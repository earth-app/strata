<?php

declare(strict_types=1);

namespace Drupal\strata\Restore;

use Drupal\strata\Capture\PayloadCodec;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Journal\Verb;
use Drupal\strata\Segment\SegmentManifest;
use Drupal\strata\Segment\SegmentReader;
use Drupal\strata\Tree\CommitLog;
use Drupal\strata\Tree\RefStore;
use Drupal\strata\Tree\BaseReader;
use Throwable;

/**
 * Reconstructs what a subject looked like at a point in history.
 *
 * What is stored per change is a FIELD DELTA, not a whole value - a body rewrite costs 297 bytes
 * rather than 2,211 - so a value at time T is the accumulation of every change up to T rather than
 * a single object to fetch. That is the trade the whole storage model rests on, and this is where
 * it is paid back.
 *
 * The walk runs from the nearest base anchor forward to the target, which is what bounds it: an
 * anchor is a commit a replay may start at, so history before one is never read. Until compaction
 * has produced anchors, the only anchor is the root and a replay covers the whole chain; the report
 * carries the depth either way, so a restore's cost is visible rather than assumed.
 *
 * Nothing is invented. A version that cannot be read is recorded as unreadable and the fields it
 * carried are left out, which is what makes the result DEGRADED rather than a plausible-looking
 * complete value. A caller that writes a degraded result over a live one has turned a recovery into
 * a second incident, so ReplayResult says which it is and Preflight decides.
 *
 * @see ReplayResult
 * @see Preflight
 * @see CommitLog::replayPath()
 */
final class Replayer
{
	/**
	 * Manifests already read in this pass, keyed by object key.
	 *
	 * A replay of many subjects at one commit reads each segment once rather than once per subject,
	 * which is the difference between one pass and one pass per subject over the same objects.
	 *
	 * @var array<string, SegmentManifest|false>
	 */
	private array $manifests = [];

	/**
	 * Constructs a replayer.
	 *
	 * @param ObjectStore $store
	 *   Reads payload frames.
	 * @param CommitLog $commits
	 *   Finds the anchor and the path to the target.
	 * @param RefStore $refs
	 *   Resolves a ref when a caller names one instead of a commit.
	 * @param SegmentReader $segments
	 *   Reads the operations each commit sealed.
	 * @param BaseReader $bases
	 *   Enumerates the subjects a commit covers.
	 */
	public function __construct(
		private readonly ObjectStore $store,
		private readonly CommitLog $commits,
		private readonly RefStore $refs,
		private readonly SegmentReader $segments,
		private readonly BaseReader $bases,
	) {}

	#region Subjects

	/**
	 * Reconstructs one subject at a commit.
	 *
	 * @param string $subject
	 *   Subject path, such as "entity/node:42".
	 * @param string $commit
	 *   Commit id to reconstruct at.
	 *
	 * @return ReplayResult
	 *   The subject's fields at that commit, and what could not be read.
	 */
	public function materialize(string $subject, string $commit): ReplayResult
	{
		return $this->materializeAll([$subject], $commit)[$subject] ??
			ReplayResult::absent($subject);
	}

	/**
	 * Reconstructs several subjects at a commit in one pass.
	 *
	 * @param list<string> $subjects
	 *   Subject paths.
	 * @param string $commit
	 *   Commit id to reconstruct at.
	 *
	 * @return array<string, ReplayResult>
	 *   Subject path keyed to its result.
	 */
	public function materializeAll(array $subjects, string $commit): array
	{
		if ($subjects === []) {
			return [];
		}

		$wanted = array_fill_keys($subjects, true);
		$path = $this->commits->replayPath($commit);
		$chain = [$path['anchor'], ...$path['path']];
		$depth = count($chain);

		$fields = [];
		$exists = [];
		$versions = [];
		$unreadable = [];

		foreach ($chain as $id) {
			$manifest = $this->manifestAt($id, $unreadable);

			if ($manifest === null) {
				continue;
			}

			foreach ($manifest->operations as $operation) {
				$key = $operation->realm->value . '/' . $operation->subject;

				if (!isset($wanted[$key])) {
					continue;
				}

				$this->apply(
					$id,
					$key,
					$operation,
					$manifest,
					$fields,
					$exists,
					$versions,
					$unreadable,
				);
			}
		}

		$results = [];

		foreach ($subjects as $subject) {
			$results[$subject] = new ReplayResult(
				$subject,
				$exists[$subject] ?? false,
				$fields[$subject] ?? [],
				$versions[$subject] ?? 0,
				$depth,
				$unreadable[$subject] ?? [],
			);
		}

		return $results;
	}

	/**
	 * Every subject a commit covers.
	 *
	 * The anchor a commit names is a complete index at the moment the anchor was written, which is at
	 * or before the commit. So the set is the anchor's index plus whatever the commits since have
	 * touched: a subject created after the anchor appears in no anchor yet, and one deleted after it
	 * is still listed by the anchor and has to come out.
	 *
	 * @param string $commit
	 *   Commit id.
	 *
	 * @return list<string>
	 *   Subject paths.
	 */
	public function subjectsAt(string $commit): array
	{
		$path = $this->commits->replayPath($commit);
		$anchor = $this->commits->read($path['anchor']);
		$subjects = array_fill_keys($this->bases->subjects($anchor->index), true);
		$unreadable = [];

		foreach ([$path['anchor'], ...$path['path']] as $id) {
			$manifest = $this->manifestAt($id, $unreadable);

			if ($manifest === null) {
				continue;
			}

			foreach ($manifest->operations as $operation) {
				$key = $operation->realm->value . '/' . $operation->subject;

				if ($operation->verb->removesSubject()) {
					unset($subjects[$key]);

					continue;
				}

				$subjects[$key] = true;
			}
		}

		return array_keys($subjects);
	}

	/**
	 * The commit a ref or a timestamp resolves to.
	 *
	 * @param string $ref
	 *   Ref name.
	 * @param int|null $microtime
	 *   Unix microseconds to resolve to the commit in force then, or NULL for the ref's tip.
	 *
	 * @return string|null
	 *   The commit id, or NULL when history does not reach that far back.
	 */
	public function resolve(string $ref = RefStore::MAIN, ?int $microtime = null): ?string
	{
		if ($microtime === null) {
			return $this->refs->read($ref);
		}

		return $this->commits->at($microtime, $ref)?->id();
	}

	/**
	 * Forgets the manifests read so far.
	 *
	 * Called by a long-running command between batches so memory does not grow with the walk.
	 */
	public function flushCache(): void
	{
		$this->manifests = [];
	}

	#endregion

	#region Reading

	/**
	 * The manifest one commit sealed.
	 *
	 * @param string $commit
	 *   Commit id.
	 * @param array<string, list<string>> $unreadable
	 *   Collects a line per version that could not be read. A segment that will not read loses every
	 *   subject in it, so the loss is recorded under the empty key against the commit rather than
	 *   being silently skipped.
	 *
	 * @return SegmentManifest|null
	 *   The manifest, or NULL when the commit sealed none or it would not read.
	 */
	private function manifestAt(string $commit, array &$unreadable): ?SegmentManifest
	{
		try {
			$key = $this->commits->read($commit)->metadata['segment'] ?? null;
		} catch (Throwable $error) {
			$unreadable[''][] = sprintf(
				'commit %s: %s',
				Hash::abbreviate($commit),
				$error->getMessage(),
			);

			return null;
		}

		if (!is_string($key) || $key === '') {
			return null;
		}
		if (array_key_exists($key, $this->manifests)) {
			$cached = $this->manifests[$key];

			return $cached === false ? null : $cached;
		}

		try {
			$manifest = $this->segments->read($key);
		} catch (Throwable $error) {
			$this->manifests[$key] = false;
			$unreadable[''][] = sprintf('segment %s: %s', $key, $error->getMessage());

			return null;
		}

		return $this->manifests[$key] = $manifest;
	}

	/**
	 * Folds one operation into a subject's accumulating state.
	 *
	 * A create or update merges its field delta over what is there; a delete resets the subject to
	 * absent, so a later create starts clean rather than inheriting fields from before the delete.
	 *
	 * @param string $commit
	 *   Commit the operation came from, for the unreadable line.
	 * @param string $subject
	 *   Subject path.
	 * @param JournalOp $operation
	 *   The operation.
	 * @param SegmentManifest $manifest
	 *   The manifest it came from, which holds the frame map for its payload.
	 * @param array<string, array<string, mixed>> $fields
	 *   Accumulating field sets, keyed by subject.
	 * @param array<string, bool> $exists
	 *   Whether each subject exists as of the operation.
	 * @param array<string, int> $versions
	 *   How many versions have been applied per subject.
	 * @param array<string, list<string>> $unreadable
	 *   Collects a line per version that could not be read.
	 */
	private function apply(
		string $commit,
		string $subject,
		JournalOp $operation,
		SegmentManifest $manifest,
		array &$fields,
		array &$exists,
		array &$versions,
		array &$unreadable,
	): void {
		if ($operation->verb === Verb::DELETE || $operation->verb === Verb::TRUNCATE) {
			$fields[$subject] = [];
			$exists[$subject] = false;
			$versions[$subject] = ($versions[$subject] ?? 0) + 1;

			return;
		}

		$map = $manifest->payloadFor($operation);

		if ($map === []) {
			// an operation with no payload changes nothing, and is not a loss
			$exists[$subject] = true;

			return;
		}

		try {
			$payload = $this->store->read($map);
		} catch (Throwable $error) {
			$unreadable[$subject][] = sprintf(
				'commit %s: %s',
				Hash::abbreviate($commit),
				$error->getMessage(),
			);

			return;
		}

		$decoded = PayloadCodec::decode($operation->realm, $payload);

		if ($decoded === null) {
			$unreadable[$subject][] = sprintf(
				'commit %s: the payload does not decode to a %s value',
				Hash::abbreviate($commit),
				$operation->realm->value,
			);

			return;
		}

		$fields[$subject] = [...$fields[$subject] ?? [], ...$decoded];
		$exists[$subject] = true;
		$versions[$subject] = ($versions[$subject] ?? 0) + 1;
	}

	#endregion
}
