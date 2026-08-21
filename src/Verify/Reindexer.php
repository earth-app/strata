<?php

declare(strict_types=1);

namespace Drupal\strata\Verify;

use Drupal\strata\Cas\FrameEnvelope;
use Drupal\strata\Cas\FrameIndexInterface;
use Drupal\strata\Cas\FrameRecord;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Cas\PackIndex;
use Drupal\strata\Segment\SegmentReader;
use Drupal\strata\Storage\StorageProviderInterface;
use Drupal\strata\Tier\TierPlacementRebuilder;
use Drupal\strata\Tree\CommitIndex;
use Drupal\strata\Tree\CommitLog;
use Drupal\strata\Tree\RefStore;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rebuilds the local index from the bucket.
 *
 * The local tables are a cache over objects that already exist. Uninstalling the module drops them
 * and leaves the bucket untouched, a restore into a fresh site starts with no tables at all, and a
 * corrupt row is faster to rebuild than to repair. All three are the same operation.
 *
 * It works because every object says what it is. A commit is a JSON document addressed by its own
 * content. A standalone frame carries a plaintext header naming its codec, cipher, dictionary and
 * decoded length. A pack carries a directory of the same fields for every frame inside it. Nothing
 * here has to be guessed or inferred from a database row, which is the property that makes the
 * bucket the record and the tables derived state.
 *
 * Reference counts are recomputed rather than restored, by reading the segments every reachable
 * commit names. A count derived from what actually points at a frame is correct by construction; a
 * count carried over from a backup of the index would be exactly as stale as the index it came
 * from.
 *
 * @see Verifier
 * @see PackIndex
 * @see FrameEnvelope
 */
final class Reindexer
{
	/**
	 * How many keys to ask for per listing page.
	 */
	private const PAGE = 1000;

	/**
	 * Constructs a reindexer.
	 *
	 * @param StorageProviderInterface $provider
	 *   The bucket to walk.
	 * @param FrameIndexInterface $index
	 *   The frame index to rebuild.
	 * @param CommitIndex $commitIndex
	 *   The commit index to rebuild.
	 * @param CommitLog $commits
	 *   Reads commit objects.
	 * @param RefStore $refs
	 *   Lists the refs whose history is reachable.
	 * @param SegmentReader $segments
	 *   Reads segment manifests so payload frames can be counted.
	 * @param LoggerInterface $logger
	 *   Records progress, since a full rebuild on a large bucket is not quick.
	 * @param TierPlacementRebuilder|null $placement
	 *   Rebuilds which bucket holds which object, or NULL on a store with one destination, where
	 *   there is no such question to answer.
	 */
	public function __construct(
		private readonly StorageProviderInterface $provider,
		private readonly FrameIndexInterface $index,
		private readonly CommitIndex $commitIndex,
		private readonly CommitLog $commits,
		private readonly RefStore $refs,
		private readonly SegmentReader $segments,
		private readonly LoggerInterface $logger,
		private readonly ?TierPlacementRebuilder $placement = null,
	) {}

	/**
	 * Rebuilds both indexes from what is in the bucket.
	 *
	 * @param bool $fresh
	 *   TRUE to drop both tables first. A rebuild that keeps existing rows cannot notice an object
	 *   that has gone away, so this is the default for a repair and FALSE only when topping up an
	 *   index that is known to be merely incomplete.
	 *
	 * @return ReindexReport
	 *   What was rebuilt, and what could not be.
	 */
	public function reindex(bool $fresh = true): ReindexReport
	{
		$started = microtime(true);

		if ($fresh) {
			$this->index->clear();
			$this->commitIndex->clear();
		}

		$problems = [];

		// placement first, so the passes below read every object from the bucket that actually has it
		$placed = $this->placement?->rebuild($fresh, $problems)['objects'] ?? 0;
		$frames = $this->indexObjects($problems);
		$commits = $this->indexCommits($problems);
		$attribution = $this->attributeReferences($problems);

		$report = new ReindexReport(
			$commits,
			$frames['packs'],
			$frames['frames'],
			$attribution['references'],
			$attribution['segments'],
			count($problems),
			$problems,
			microtime(true) - $started,
			$placed,
		);

		$this->logger->info('Strata %summary', ['%summary' => $report->summary()]);

		return $report;
	}

	#region Frames

	/**
	 * Rebuilds the frame index from the pack and frame objects.
	 *
	 * Every frame starts at zero references. Attribution is a separate pass over the segments,
	 * because a frame's object says nothing about who points at it.
	 *
	 * @param list<string> $problems
	 *   Collects one line per unusable object.
	 *
	 * @return array{packs: int, frames: int}
	 *   How many packs were read and how many frame records were written.
	 */
	private function indexObjects(array &$problems): array
	{
		$packs = 0;
		$frames = 0;

		foreach ($this->keys(ObjectStore::PACK_PREFIX . '/') as $key) {
			try {
				$object = $this->provider->get($key);
				$entries = PackIndex::decode($key, $object);
			} catch (Throwable $error) {
				$problems[] = sprintf('%s: %s', $key, $error->getMessage());

				continue;
			}

			$packs++;
			$id = basename($key);

			foreach ($entries as $entry) {
				try {
					$this->index->record(
						new FrameRecord(
							(string) $entry['hash'],
							(int) $entry['raw'],
							(int) $entry['length'],
							(string) $entry['codec'],
							(string) $entry['cipher'],
							$entry['dictionary'] === null ? null : (string) $entry['dictionary'],
							$id,
							(int) $entry['offset'],
							0,
							0,
							$entry['parent'] === null ? null : (string) $entry['parent'],
							(int) $entry['depth'],
						),
					);
					$frames++;
				} catch (Throwable $error) {
					$problems[] = sprintf('%s entry: %s', $key, $error->getMessage());
				}
			}
		}

		foreach ($this->keys(ObjectStore::FRAME_PREFIX . '/') as $key) {
			$hash = basename($key);

			try {
				$envelope = FrameEnvelope::parse($key, $this->provider->get($key));

				$this->index->record(
					new FrameRecord(
						$hash,
						$envelope['raw'],
						strlen($envelope['body']),
						$envelope['codec'],
						$envelope['cipher'],
						$envelope['dictionary'],
						null,
						0,
						0,
						0,
						$envelope['parent'],
						$envelope['depth'],
					),
				);
				$frames++;
			} catch (Throwable $error) {
				$problems[] = sprintf('%s: %s', $key, $error->getMessage());
			}
		}

		return ['packs' => $packs, 'frames' => $frames];
	}

	#endregion

	#region Commits

	/**
	 * Rebuilds the commit index from the commit objects.
	 *
	 * Every commit object is indexed, not only the ones a ref reaches. A commit orphaned by a
	 * restore that rewound a ref is still a restore target and still the parent of a chain someone
	 * may want back, so dropping it here would quietly delete history the bucket still holds.
	 *
	 * @param list<string> $problems
	 *   Collects one line per unusable object.
	 *
	 * @return int
	 *   How many commit rows were written.
	 */
	private function indexCommits(array &$problems): int
	{
		$written = 0;

		foreach ($this->keys(CommitLog::PREFIX . '/') as $key) {
			$id = basename($key);

			if (!Hash::isValid($id)) {
				$problems[] = sprintf('%s: the key is not a commit address', $key);

				continue;
			}

			try {
				$commit = $this->commits->read($id);
			} catch (Throwable $error) {
				$problems[] = sprintf('%s: %s', $key, $error->getMessage());

				continue;
			}

			$this->commitIndex->record($id, $commit);
			$written++;
		}

		return $written;
	}

	#endregion

	#region References

	/**
	 * Counts what points at each frame, by reading every segment a ref can reach.
	 *
	 * Segment payloads are the only thing counted, and matching what a flush counts is the whole
	 * point: a flush adds one reference per frame per operation payload as it writes, and nothing
	 * else. Base anchors name the same frames, but an anchor carries an entry for every subject that
	 * changed in its whole interval, so counting anchor entries would attribute a second reference to
	 * every frame the segments already counted. Whether a surviving anchor still needs a frame is a
	 * reachability question, answered by walking at prune time rather than by a counter.
	 *
	 * Walking from the refs rather than from the commit table means a commit no ref reaches
	 * contributes no references. It stays indexed and restorable; it just does not hold frames alive
	 * against a prune.
	 *
	 * @param list<string> $problems
	 *   Collects one line per unusable object.
	 *
	 * @return array{references: int, segments: int}
	 *   How many references were attributed, and how many segments were read to find them.
	 */
	private function attributeReferences(array &$problems): array
	{
		$references = 0;
		$seen = [];

		foreach ($this->refs->all() as $id) {
			$current = $id;

			while ($current !== null) {
				try {
					$commit = $this->commits->read($current);
				} catch (Throwable $error) {
					$problems[] = sprintf(
						'commit %s: %s',
						Hash::abbreviate($current),
						$error->getMessage(),
					);

					break;
				}

				$segment = $commit->metadata['segment'] ?? null;

				if (is_string($segment) && $segment !== '' && !isset($seen[$segment])) {
					$seen[$segment] = true;
					$references += $this->attributeSegment($segment, $problems);
				}

				$current = $commit->parent;
			}
		}

		return ['references' => $references, 'segments' => count($seen)];
	}

	/**
	 * Adds one reference per payload frame a segment names.
	 *
	 * @param string $key
	 *   The segment's object key.
	 * @param list<string> $problems
	 *   Collects one line per unusable object.
	 *
	 * @return int
	 *   How many references were added.
	 */
	private function attributeSegment(string $key, array &$problems): int
	{
		try {
			$manifest = $this->segments->read($key);
		} catch (Throwable $error) {
			$problems[] = sprintf('%s: %s', $key, $error->getMessage());

			return 0;
		}

		$added = 0;

		foreach ($manifest->frames() as $frame) {
			$this->index->reference($frame);
			$added++;
		}

		return $added;
	}

	#endregion

	/**
	 * Every key under a prefix, a page at a time.
	 *
	 * @param string $prefix
	 *   The prefix to list.
	 *
	 * @return list<string>
	 *   Object keys.
	 */
	private function keys(string $prefix): array
	{
		$keys = [];
		$cursor = null;

		do {
			$page = $this->provider->list($prefix, $cursor, self::PAGE);
			$keys = [...$keys, ...$page->keys()];
			$cursor = $page->cursor;
		} while ($page->hasMore());

		return $keys;
	}
}
