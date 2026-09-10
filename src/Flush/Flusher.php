<?php

declare(strict_types=1);

namespace Drupal\strata\Flush;

use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Event\Notifier;
use Drupal\strata\Journal\FlushPolicy;
use Drupal\strata\Journal\JournalInterface;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Segment\SegmentBuilder;
use Drupal\strata\Segment\SegmentWriter;
use Drupal\strata\Tree\Commit;
use Drupal\strata\Tree\CommitIndex;
use Drupal\strata\Tree\CommitLog;
use Drupal\strata\Tree\RefStore;
use Drupal\strata\Tree\BasePolicy;
use Drupal\strata\Tree\BaseReader;
use Drupal\strata\Tree\BaseWriter;
use Drupal\strata\Tree\SubjectIndex;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seals a window of captured operations into a segment and a commit.
 *
 * The step that turns capture into a backup. Everything before it is local and cheap; this is where
 * bytes leave the site.
 *
 * **Four objects, whatever the site's size.** A pack of frames, a segment manifest, a commit and the
 * ref that names it. The index of every subject is not one of them: writing one per flush was
 * measured at 3,989,289 bytes to record a 201-byte change across 50,000 subjects, because its cost
 * scaled with how many subjects the site had rather than with how many changed. So the index belongs
 * to a base anchor on its own interval, the commits in between inherit its address, and what changed
 * in the meantime accumulates in a local table that costs no requests at all.
 *
 * The order is fixed by what a crash between two steps would leave behind. Payloads are stored
 * first, because a frame nothing references is collectable garbage while a manifest naming a frame
 * that was never written is a corrupt segment. The segment is written before the anchor, the anchor
 * before the commit, and the commit before the ref moves, so at every point the objects already
 * written are either referenced or collectable, and never dangling.
 *
 * The journal is trimmed last, after the ref has moved. A flush that dies before the trim leaves
 * the window in place and repeats itself on the next run, producing an identical segment - every
 * object is content-addressed, so a repeat costs a few lookups and writes nothing new. A flush that
 * trimmed first and then died would lose the window outright.
 *
 * @see FlushPolicy
 * @see SegmentBuilder
 * @see CommitLog
 * @see BasePolicy
 */
final class Flusher
{
	/**
	 * Constructs a flusher.
	 *
	 * @param JournalInterface $journal
	 *   The journal to drain.
	 * @param FlushPolicy $policy
	 *   Decides whether a window is due.
	 * @param ObjectStore $store
	 *   Stores payloads.
	 * @param SegmentWriter $segments
	 *   Writes the segment manifest.
	 * @param BaseWriter $bases
	 *   Writes the base anchor when one is due.
	 * @param BaseReader $reader
	 *   Resolves the anchor chain when a full anchor is due.
	 * @param BasePolicy $basePolicy
	 *   Decides when an anchor is due and when it has to list every subject.
	 * @param SubjectIndex $subjects
	 *   Records where every subject was last stored, which is both what the next anchor names and what
	 *   the next rewrite of a subject is delta coded against.
	 * @param CommitLog $commits
	 *   Appends the commit and advances the ref.
	 * @param Lease $lease
	 *   Keeps two flushes from overlapping.
	 * @param LoggerInterface $logger
	 *   Records what a flush did, and what it could not do.
	 * @param CommitIndex|null $index
	 *   Mirrors the commit into the local index, or NULL to write only to the store. The index is a
	 *   cache over what the bucket already holds, so a flush that cannot reach it has still
	 *   produced a complete backup and says so.
	 * @param Notifier|null $notifier
	 *   Announces a sealed window, or NULL to announce nothing. Optional because a flush is correct
	 *   without one and this class is constructed directly by several tests.
	 * @param string $site
	 *   The site identifier a subscriber needs to tell two sites in one bucket apart.
	 */
	public function __construct(
		private readonly JournalInterface $journal,
		private readonly FlushPolicy $policy,
		private readonly ObjectStore $store,
		private readonly SegmentWriter $segments,
		private readonly BaseWriter $bases,
		private readonly BaseReader $reader,
		private readonly BasePolicy $basePolicy,
		private readonly SubjectIndex $subjects,
		private readonly CommitLog $commits,
		private readonly Lease $lease,
		private readonly LoggerInterface $logger,
		private readonly ?CommitIndex $index = null,
		private readonly ?Notifier $notifier = null,
		private readonly string $site = '',
	) {}

	/**
	 * Seals a window if one is due.
	 *
	 * @param bool $force
	 *   TRUE to seal whatever is pending regardless of the policy. Used by a shutdown, an export and
	 *   the `strata:flush` command.
	 * @param string $ref
	 *   Ref to advance.
	 *
	 * @return FlushResult
	 *   What happened.
	 */
	public function flush(bool $force = false, string $ref = RefStore::MAIN): FlushResult
	{
		$pending = $this->journal->pending();

		if ($pending < 1) {
			return FlushResult::skipped('nothing pending');
		}

		$reason = $this->policy->reason(
			$pending,
			$this->journal->pendingBytes(),
			$this->journal->oldest(),
			$this->now(),
		);

		if ($reason === null && !$force) {
			return FlushResult::skipped('no flush bound reached');
		}

		if (!$this->lease->acquire(Lease::FLUSH)) {
			return FlushResult::skipped('another flush holds the lease');
		}

		try {
			return $this->seal($reason ?? 'forced', $ref);
		} catch (Throwable $e) {
			// the window is untouched, so the next run repeats this flush rather than losing it
			$this->logger->error('Strata could not seal a window: %message', [
				'%message' => $e->getMessage(),
			]);

			return FlushResult::skipped(sprintf('failed: %s', $e->getMessage()));
		} finally {
			$this->lease->release(Lease::FLUSH);
		}
	}

	/**
	 * Whether a window is due, without sealing one.
	 *
	 * Read by the status page and by `strata:status`, so it does not take the lease.
	 *
	 * @return bool
	 *   TRUE when a flush would run.
	 */
	public function isDue(): bool
	{
		return $this->policy->shouldFlush(
			$this->journal->pending(),
			$this->journal->pendingBytes(),
			$this->journal->oldest(),
			$this->now(),
		);
	}

	/**
	 * How far behind the store is, in seconds.
	 *
	 * The recovery-point lag: how much of the site's recent history exists only on this server. The
	 * number the timeline shows beside the head.
	 *
	 * @return float
	 *   Seconds since the oldest unsealed operation, or 0.0 when nothing is pending.
	 */
	public function lag(): float
	{
		$oldest = $this->journal->oldest();

		if ($oldest === null) {
			return 0.0;
		}

		return max(0.0, ($this->now() - $oldest) / JournalOp::MICROSECONDS_PER_SECOND);
	}

	/**
	 * Does the sealing, with the lease already held.
	 *
	 * @param string $reason
	 *   Which bound fired.
	 * @param string $ref
	 *   Ref to advance.
	 *
	 * @return FlushResult
	 *   What happened.
	 */
	private function seal(string $reason, string $ref): FlushResult
	{
		$started = microtime(true);
		$window = $this->journal->read($this->policy->maxOps() ?: 5000);

		if ($window === []) {
			return FlushResult::skipped('nothing pending');
		}

		// frame bytes this window adds, which the commit cannot carry: a commit is addressed by its
		// own json, so a size written into it would change the address it is stored under
		$writtenBefore = $this->store->written();

		// the index holds where each subject was last stored, which is what a delta codes against
		$builder = new SegmentBuilder(
			$this->store,
			0,
			fn(string $subject): array => $this->subjects->frames($subject),
		);

		foreach ($window as $entry) {
			$builder->add($entry['operation'], $entry['payload']);
		}

		$manifest = $builder->build();

		if ($manifest === null) {
			return FlushResult::skipped('nothing pending');
		}

		$segmentKey = $this->segments->write($manifest);

		$head = $this->commits->head($ref);
		$touched = [];

		foreach ($manifest->operations as $operation) {
			$frames = $manifest->payloadFor($operation);
			$path = $operation->realm->value . '/' . $operation->subject;

			// an operation with no payload removes the subject from the index
			$touched[$path] =
				$frames === [] ? null : ['frames' => $frames, 'size' => $operation->payloadLength];
		}

		$this->subjects->record($touched, $manifest->lastMicrotime);
		$anchor = $this->anchor($head, $manifest->lastMicrotime);

		$commit = new Commit(
			$anchor['index'],
			$head?->id(),
			$manifest->lastMicrotime,
			$manifest->summary(),
			$this->actorOf($manifest->operations),
			$manifest->count(),
			$manifest->rawBytes,
			0,
			0,
			$anchor['wrote'],
			$anchor['chain'],
			$anchor['at'],
			array_filter([
				'segment' => $segmentKey,
				'reason' => $reason,
				'anchor' => $anchor['why'],
			]),
		);

		$commitId = $this->commits->append($commit, $ref);

		$this->index?->record($commitId, $commit, $this->store->written() - $writtenBefore);

		// last, and only once the ref has moved: a repeat is cheap, a lost window is not
		$trimmed = $this->journal->trim($manifest->lastSequence);

		$result = new FlushResult(
			true,
			$reason,
			$segmentKey,
			$commitId,
			count($window),
			$manifest->count(),
			$manifest->rawBytes,
			$trimmed,
			microtime(true) - $started,
		);

		$this->logger->info('Strata %summary', ['%summary' => $result->summary()]);

		// after the ref has moved and the journal is trimmed, so a subscriber that raises cannot
		// undo a sealed window; Notifier catches its own dispatch anyway
		$this->notifier?->commitSealed($result, $this->site);

		return $result;
	}

	/**
	 * Writes the base anchor if one is due, or names the one already in force.
	 *
	 * Which subjects the anchor names is decided by when they last changed rather than by emptying a
	 * queue. The rows stay, because they are what the next rewrite of each subject is delta coded
	 * against, and a failed anchor therefore costs nothing: the next attempt selects the same set.
	 *
	 * @param Commit|null $head
	 *   The commit this one follows, or NULL for the root of history, which always writes one.
	 * @param int $microtime
	 *   Unix microseconds the window was sealed at.
	 *
	 * @return array{index: string, wrote: bool, chain: int, at: int, why: string}
	 *   The anchor address to record, whether this flush wrote it, its chain length, the time it was
	 *   written and why, which is an empty string when nothing was written.
	 */
	private function anchor(?Commit $head, int $microtime): array
	{
		$why = $this->basePolicy->reason($head?->anchoredAt ?: null, $microtime, $head->chain ?? 0);

		if ($why === null && $head !== null) {
			return [
				'index' => $head->index,
				'wrote' => false,
				'chain' => $head->chain,
				'at' => $head->anchoredAt,
				'why' => '',
			];
		}

		$changed = $this->subjects->changedSince($head === null ? 0 : $head->anchoredAt);
		$full = $head === null || $this->basePolicy->needsFull($head->chain);

		if ($full) {
			$index = $this->resolveFull($head, $changed);
			$address = $this->bases->full($index, $microtime);
			$chain = 1;
		} else {
			$address = $this->bases->delta($changed, $head->index, $microtime);
			$chain = $head->chain + 1;
		}

		return [
			'index' => $address,
			'wrote' => true,
			'chain' => $chain,
			'at' => $microtime,
			'why' => (string) $why,
		];
	}

	/**
	 * The complete index a full anchor lists.
	 *
	 * The previous anchor resolved, with this window's changes applied over it. Reading the previous
	 * chain is what a full anchor costs and why it is written rarely.
	 *
	 * @param Commit|null $head
	 *   The commit this one follows, or NULL when there is no history to carry forward.
	 * @param array<string, array{frames: list<string>, size: int}|null> $changed
	 *   What has changed since the last anchor.
	 *
	 * @return array<string, array{frames: list<string>, size: int}>
	 *   Every subject the site holds.
	 */
	private function resolveFull(?Commit $head, array $changed): array
	{
		$index = $head === null ? [] : $this->reader->resolve($head->index);

		foreach ($changed as $subject => $entry) {
			if ($entry === null) {
				unset($index[(string) $subject]);

				continue;
			}

			$index[(string) $subject] = $entry;
		}

		return $index;
	}

	/**
	 * The user a commit is attributed to.
	 *
	 * A window covering one person's work is attributed to them; one covering several, or covering
	 * unattended work, is attributed to nobody rather than to whoever happened to be last.
	 *
	 * @param list<JournalOp> $operations
	 *   The operations the commit covers.
	 *
	 * @return int|null
	 *   A Drupal user id, or NULL.
	 */
	private function actorOf(array $operations): ?int
	{
		$actors = [];

		foreach ($operations as $operation) {
			if ($operation->actor !== null) {
				$actors[$operation->actor] = true;
			}
		}

		return count($actors) === 1 ? (int) array_key_first($actors) : null;
	}

	/**
	 * The current time in unix microseconds.
	 *
	 * @return int
	 *   Microseconds since the epoch.
	 */
	private function now(): int
	{
		return (int) round(microtime(true) * JournalOp::MICROSECONDS_PER_SECOND);
	}
}
