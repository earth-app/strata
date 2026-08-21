<?php

declare(strict_types=1);

namespace Drupal\strata\Verify;

use Drupal\strata\Cas\FrameIndexInterface;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Crypto\AuthenticationFailure;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\HealthLedgerInterface;
use Drupal\strata\Health\TripwireRegistry;
use Drupal\strata\Segment\SegmentReader;
use Drupal\strata\Storage\StorageProviderInterface;
use Drupal\strata\Tier\TierStatusInterface;
use Drupal\strata\Tree\Commit;
use Drupal\strata\Tree\CommitIndex;
use Drupal\strata\Tree\CommitLog;
use Drupal\strata\Tree\RefStore;
use Drupal\strata\Tree\BaseReader;
use Throwable;

/**
 * Reads the backup back and reports what does not come out.
 *
 * The claim a backup system makes is that its history can be restored, and the only evidence for
 * that is having read it. This walks a ref back through history and fetches every object each
 * commit depends on: the parent commit, the base anchors its index resolves through, the frames each
 * anchor entry names, the segment the flush wrote. In its deep mode it also decodes every frame and
 * checks the bytes hash back to the address they are filed under, which catches silent corruption.
 *
 * Nothing here repairs anything. Findings go to the ledger, where the repair ladder decides what
 * runs automatically and what waits for a human. That separation is why this is safe to run from
 * cron on a schedule.
 *
 * Two things keep the cost sane on a large site. Frames and anchors are verified once no matter how
 * many commits name them, and the commits between two anchors name the same one, so a pass over a
 * day of history reads six anchors rather than one per flush. And a bounded pass walks a fixed number
 * of commits back from the head and says so in the report, rather than reporting a clean result for a
 * run that stopped early.
 *
 * @see VerifyReport
 * @see TripwireRegistry
 * @see Reindexer
 */
final class Verifier
{
	/**
	 * Frame addresses already verified in this pass.
	 *
	 * @var array<string, true>
	 */
	private array $seenFrames = [];

	/**
	 * Base anchor addresses already walked in this pass.
	 *
	 * @var array<string, true>
	 */
	private array $seenTrees = [];

	/**
	 * Findings raised so far, in the order they were found.
	 *
	 * @var list<Finding>
	 */
	private array $findings = [];

	/**
	 * Running counters for the report.
	 *
	 * @var array{commits: int, trees: int, frames: int, segments: int, bytes: int}
	 */
	private array $counts = [
		'commits' => 0,
		'trees' => 0,
		'frames' => 0,
		'segments' => 0,
		'bytes' => 0,
	];

	/**
	 * Constructs a verifier.
	 *
	 * @param StorageProviderInterface $provider
	 *   Where objects live; asked whether each one is present.
	 * @param FrameIndexInterface $index
	 *   Consulted for what the site believes about each frame.
	 * @param ObjectStore $store
	 *   Fetches and decodes frames in a deep pass.
	 * @param CommitLog $commits
	 *   Walks history.
	 * @param RefStore $refs
	 *   Resolves the ref to start from.
	 * @param BaseReader $bases
	 *   Reads the base anchors history resolves against.
	 * @param SegmentReader $segments
	 *   Reads segment manifests.
	 * @param TripwireRegistry $tripwires
	 *   The checks each observation is run through.
	 * @param HealthLedgerInterface $ledger
	 *   Where findings are recorded.
	 * @param int $maxDeltaDepth
	 *   The configured chain-depth cap, so a chain past it is reported.
	 * @param CommitIndex|null $commitIndex
	 *   The local index, consulted only to tell a store whose objects are gone from one that never
	 *   had any. NULL leaves that question unasked, which is what a unit lane with no database does.
	 * @param TierStatusInterface|null $tiers
	 *   The store's tiers, asked at the start of every pass which of them can be reached. NULL is a
	 *   store with one destination, where the provider's own reachability is the whole answer.
	 */
	public function __construct(
		private readonly StorageProviderInterface $provider,
		private readonly FrameIndexInterface $index,
		private readonly ObjectStore $store,
		private readonly CommitLog $commits,
		private readonly RefStore $refs,
		private readonly BaseReader $bases,
		private readonly SegmentReader $segments,
		private readonly TripwireRegistry $tripwires,
		private readonly HealthLedgerInterface $ledger,
		private readonly int $maxDeltaDepth = 32,
		private readonly ?CommitIndex $commitIndex = null,
		private readonly ?TierStatusInterface $tiers = null,
	) {}

	#region Passes

	/**
	 * Verifies history reachable from a ref.
	 *
	 * @param string $ref
	 *   Ref to walk back from.
	 * @param int|null $limit
	 *   Stop after this many commits, or NULL to walk to the root of history.
	 * @param bool $deep
	 *   TRUE to fetch and decode every frame; FALSE to check only that each object is present and
	 *   indexed, which costs one HEAD per object instead of a GET and a decode.
	 *
	 * @return VerifyReport
	 *   What the pass found.
	 */
	public function verify(
		string $ref = RefStore::MAIN,
		?int $limit = null,
		bool $deep = true,
	): VerifyReport {
		$head = $this->refs->read($ref);

		if ($head === null) {
			return $this->reportLostRef($ref);
		}

		return $this->verifyFrom($head, $limit, $deep);
	}

	/**
	 * Verifies history reachable from one commit.
	 *
	 * @param string $head
	 *   Commit id to start at.
	 * @param int|null $limit
	 *   Stop after this many commits, or NULL to walk to the root.
	 * @param bool $deep
	 *   TRUE to decode every frame.
	 *
	 * @return VerifyReport
	 *   What the pass found.
	 */
	public function verifyFrom(string $head, ?int $limit = null, bool $deep = true): VerifyReport
	{
		$started = microtime(true);
		$this->reset();
		$this->sweepTiers();

		$id = $head;
		$anchorSeen = false;
		$complete = false;
		$bounded = false;

		while (true) {
			if ($limit !== null && $this->counts['commits'] >= $limit) {
				$bounded = true;

				break;
			}

			try {
				$commit = $this->commits->read($id);
			} catch (Throwable $error) {
				// a commit that will not read is the end of what can be walked, not a skippable row
				$this->raise(
					new Finding(
						'commit.parent_missing',
						Finding::CRITICAL,
						$id,
						sprintf(
							'Commit %s did not read: %s',
							Hash::abbreviate($id),
							$error->getMessage(),
						),
					),
				);

				break;
			}

			$this->counts['commits']++;
			$anchorSeen = $anchorSeen || $commit->isAnchor();

			$this->verifyCommit($id, $commit, $deep);

			if ($commit->parent === null) {
				$complete = true;

				break;
			}

			$id = $commit->parent;
		}

		// a walk cut short by the limit has not looked for the anchor, so it cannot report one missing
		if (!$anchorSeen && !$bounded) {
			$this->sweep([
				'commit' => $head,
				'anchor_reachable' => false,
				'walked' => $this->counts['commits'],
			]);
		}

		return new VerifyReport(
			$this->counts['commits'],
			$this->counts['trees'],
			$this->counts['frames'],
			$this->counts['segments'],
			$this->counts['bytes'],
			$this->findings,
			microtime(true) - $started,
			$complete,
		);
	}

	#endregion

	#region Objects

	/**
	 * Verifies everything one commit depends on.
	 *
	 * @param string $id
	 *   The commit id.
	 * @param Commit $commit
	 *   The commit.
	 * @param bool $deep
	 *   TRUE to decode every frame.
	 */
	private function verifyCommit(string $id, Commit $commit, bool $deep): void
	{
		// both parents, so a merge commit naming a branch tip that has gone is a finding rather than a
		// dangling link nothing looks at
		foreach ($commit->parents() as $parent) {
			$this->sweep([
				'commit' => $id,
				'commit_parent' => $parent,
				'commit_parent_present' => $this->commits->exists($parent),
			]);
		}

		$this->verifyAnchor($id, $commit->index, $deep);

		$segment = $commit->metadata['segment'] ?? null;

		if (is_string($segment) && $segment !== '') {
			$this->verifySegment($segment, $deep);
		}
	}

	/**
	 * Walks an anchor chain and verifies the frames its entries name.
	 *
	 * Every link is verified, not only the resolved index: a frame named by an entry a later anchor
	 * replaced is still what that older anchor restores from, and an anchor is a restore target.
	 *
	 * @param string $commit
	 *   The commit that points at this anchor, for the finding's context.
	 * @param string $address
	 *   Anchor address.
	 * @param bool $deep
	 *   TRUE to decode every frame.
	 */
	private function verifyAnchor(string $commit, string $address, bool $deep): void
	{
		$at = $address;

		while ($at !== null && !isset($this->seenTrees[$at])) {
			$this->seenTrees[$at] = true;

			try {
				$manifest = $this->bases->read($at);
			} catch (Throwable) {
				$this->sweep(['commit' => $commit, 'anchor' => $at, 'anchor_present' => false]);

				return;
			}

			$this->counts['trees']++;

			foreach ($manifest->frames() as $frame) {
				$this->verifyFrame($frame, $deep);
			}

			$at = $manifest->full ? null : $manifest->parent;
		}
	}

	/**
	 * Verifies one segment manifest and the payload frames it names.
	 *
	 * @param string $key
	 *   The segment's object key.
	 * @param bool $deep
	 *   TRUE to decode every frame.
	 */
	private function verifySegment(string $key, bool $deep): void
	{
		try {
			$manifest = $this->segments->read($key);
		} catch (Throwable $error) {
			$this->sweep([
				'segment' => $key,
				'segment_readable' => false,
				'segment_error' => $error->getMessage(),
			]);

			return;
		}

		$this->counts['segments']++;

		foreach ($manifest->frames() as $frame) {
			$this->verifyFrame($frame, $deep);
		}
	}

	/**
	 * Verifies one frame.
	 *
	 * The order is deliberate: what the index knows, then whether the object is there, then whether
	 * the things it needs to decode are there, and only then the decode itself. Each step's failure
	 * makes the next step meaningless, so reporting all of them for one broken frame would turn a
	 * single fault into a wall of findings.
	 *
	 * @param string $hash
	 *   Frame content address.
	 * @param bool $deep
	 *   TRUE to fetch and decode.
	 */
	private function verifyFrame(string $hash, bool $deep): void
	{
		if (isset($this->seenFrames[$hash])) {
			return;
		}

		$this->seenFrames[$hash] = true;
		$record = $this->index->get($hash);

		if ($record === null) {
			$this->sweep(['frame' => $hash, 'frame_indexed' => false]);

			return;
		}

		$this->counts['frames']++;

		$key = $record->isPacked()
			? Hash::key((string) $record->pack, ObjectStore::PACK_PREFIX)
			: Hash::key($hash, ObjectStore::FRAME_PREFIX);

		$meta = $this->provider->head($key);

		if ($meta === null) {
			$this->sweep(['frame' => $hash, 'frame_present' => false, 'key' => $key]);

			return;
		}

		if (
			$this->sweep([
				'frame' => $hash,
				'pack' => $record->pack ?? '',
				'pack_size' => $record->isPacked() ? $meta->size : 0,
				'frame_offset' => $record->offset,
				'frame_length' => $record->storedSize,
				'dictionary' => $record->dictionary ?? '',
				'dictionary_present' =>
					$record->dictionary === null ||
					$record->dictionary === $this->store->dictionaryId(),
				'delta_parent' => $record->deltaParent ?? '',
				'delta_parent_present' =>
					$record->deltaParent === null ? true : $this->index->has($record->deltaParent),
				'delta_depth' => $record->deltaDepth,
				'max_depth' => $this->maxDeltaDepth,
			])
		) {
			return;
		}

		if (!$deep) {
			return;
		}

		$this->decode($hash, $record->rawSize);
	}

	/**
	 * Fetches and decodes one frame, and checks the bytes against the address.
	 *
	 * @param string $hash
	 *   Frame content address.
	 * @param int $rawSize
	 *   The decoded size the index records, so a clean-looking short read is counted correctly.
	 */
	private function decode(string $hash, int $rawSize): void
	{
		try {
			$plain = $this->store->frame($hash);
		} catch (AuthenticationFailure) {
			$this->sweep(['frame' => $hash, 'aead_failed' => true]);

			return;
		} catch (Throwable $error) {
			$this->sweep(['frame' => $hash, 'decode_error' => $error->getMessage()]);

			return;
		}

		if ($plain === null) {
			$this->sweep(['frame' => $hash, 'frame_indexed' => false]);

			return;
		}

		$this->counts['bytes'] += strlen($plain);

		$this->sweep(['frame' => $hash, 'decoded_hash' => Hash::of($plain)]);

		if (strlen($plain) !== $rawSize) {
			$this->sweep([
				'frame' => $hash,
				'decode_error' => sprintf(
					'decoded %d bytes against an indexed %d',
					strlen($plain),
					$rawSize,
				),
			]);
		}
	}

	#endregion

	/**
	 * Reports every tier of the store that cannot be reached.
	 *
	 * Run before the walk rather than derived from it. A tier that is down produces a read failure
	 * per object, and the finding an operator needs is that one bucket is unreachable, not a
	 * thousand findings about the objects in it. A store with one destination raises nothing here,
	 * since its own reachability is reported by hook_requirements().
	 */
	private function sweepTiers(): void
	{
		if ($this->tiers === null) {
			return;
		}

		$names = $this->tiers->tierNames();

		foreach ($this->tiers->tierStatus() as $index => $reason) {
			if ($reason === null) {
				continue;
			}

			$this->sweep([
				'tier' => $names[$index] ?? (string) $index,
				'tier_reachable' => false,
				'tier_reason' => $reason,
			]);
		}
	}

	/**
	 * Runs one observation through every tripwire and records what fired.
	 *
	 * @param array<string, mixed> $observation
	 *   What the caller had in hand.
	 *
	 * @return bool
	 *   TRUE when anything fired, so a caller can stop rather than pile findings on one fault.
	 */
	private function sweep(array $observation): bool
	{
		$found = false;

		foreach ($this->tripwires->evaluate($observation) as $finding) {
			$this->raise($finding);
			$found = true;
		}

		return $found;
	}

	/**
	 * Records one finding.
	 *
	 * @param Finding $finding
	 *   The finding.
	 */
	private function raise(Finding $finding): void
	{
		$this->findings[] = $finding;
		$this->ledger->record($finding);
	}

	/**
	 * What to report when a ref resolves to nothing.
	 *
	 * A ref that was never written and a ref whose object was deleted look the same from the bucket,
	 * and the difference is the whole answer: one is a site that has not flushed yet, the other is a
	 * site whose history is gone. The local index decides which, because it is derived from the
	 * bucket and cannot hold rows for commits that were never sealed.
	 *
	 * @param string $ref
	 *   The ref that did not resolve.
	 *
	 * @return VerifyReport
	 *   An empty clean pass, or one carrying `ref.missing`.
	 */
	private function reportLostRef(string $ref): VerifyReport
	{
		$indexed = $this->commitIndex?->count() ?? 0;

		$this->reset();
		$this->sweepTiers();

		if ($indexed < 1) {
			// a site that has never flushed is not a site whose history has gone
			return new VerifyReport(findings: $this->findings, complete: $this->findings === []);
		}

		foreach (
			$this->tripwires->evaluate([
				'ref' => $ref,
				'ref_resolved' => false,
				'indexed_commits' => $indexed,
			])
			as $finding
		) {
			$this->raise($finding);
		}

		return new VerifyReport(findings: $this->findings, complete: false);
	}

	/**
	 * Clears the state a previous pass left behind.
	 */
	private function reset(): void
	{
		$this->seenFrames = [];
		$this->seenTrees = [];
		$this->findings = [];
		$this->counts = [
			'commits' => 0,
			'trees' => 0,
			'frames' => 0,
			'segments' => 0,
			'bytes' => 0,
		];
	}
}
