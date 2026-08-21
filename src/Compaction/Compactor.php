<?php

declare(strict_types=1);

namespace Drupal\strata\Compaction;

use Drupal\strata\Cas\FrameIndexInterface;
use Drupal\strata\Cas\FrameRecord;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Cas\PackIndex;
use Drupal\strata\Delta\Reanchorer;
use Drupal\strata\Flush\Lease;
use Drupal\strata\Storage\StorageProviderInterface;
use Drupal\strata\Tier\PlacementIndexInterface;
use Drupal\strata\Tier\TierMigrator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Makes stored history cheaper without making it less restorable.
 *
 * Two jobs, deliberately separated. Recompression rewrites objects at a setting the flush path
 * cannot afford, changes no content address, and destroys nothing - it is safe to run on a schedule
 * and safe to interrupt. Pruning removes restore targets, so it runs only when asked, refuses on
 * any
 * doubt, and always produces a receipt.
 *
 * The refusal rules are the load-bearing part:
 *
 * - A prune refuses outright when the reachability walk could not read something. An incomplete
 * walk
 *   under-reports what is live, and under-reporting is the direction that deletes what a restore
 *   needs. Refusing costs storage; guessing costs the backup.
 * - A candidate is dropped only when all three reachability classes agree - no reachable commit
 *   needs it, no live frame was compressed against its dictionary, and nothing decodes against it
 * as
 *   a delta parent. Every candidate held back is named in the receipt, so the refusals are visible
 *   rather than implied.
 * - A pack is deleted only when every frame in it is collectable. One live frame keeps the whole
 *   object, because a pack is a flat concatenation and there is no way to remove part of one.
 * - A pack the index no longer points at is deleted only when every frame in it is provably filed
 *   somewhere else. A recompressed pack leaves its predecessor with no index row naming it, which
 *   is indistinguishable from a stale index unless each frame is found at its new home first.
 * - A prune whose deletion the store refuses part way returns a refusal rather than trimming the
 *   index anyway. On a store spread across buckets that is what a bucket being down looks like, and
 *   the index rows are the only thing that still names the objects left behind.
 *
 * @see Reachability
 * @see PruneReceipt
 * @see Recompressor
 */
final class Compactor
{
	/**
	 * Constructs a compactor.
	 *
	 * @param StorageProviderInterface $provider
	 *   The store to rewrite and prune.
	 * @param FrameIndexInterface $index
	 *   Repointed by a rewrite and trimmed by a prune.
	 * @param Recompressor $recompressor
	 *   Does the rewriting.
	 * @param Reachability $reachability
	 *   Decides what may be removed.
	 * @param LevelPolicy $levels
	 *   The retention ladder, which decides what is old enough to consider.
	 * @param Lease $lease
	 *   Keeps two compactions from overlapping, and keeps one from overlapping a flush.
	 * @param LoggerInterface $logger
	 *   Records what a pass did.
	 * @param Reanchorer|null $reanchorer
	 *   Breaks delta chains that have grown past the cap, or NULL to leave them alone. Runs before the
	 *   recompression, because re-anchoring rewrites the frames a recompression would otherwise have
	 *   to rewrite a second time.
	 * @param Rollup|null $rollup
	 *   Folds each level's segments into the level above, or NULL to leave the ladder alone. Runs
	 *   before the recompression so the coarse segments it writes are the ones densified.
	 * @param TierMigrator|null $migrator
	 *   Moves aged objects into the tier they belong in, or NULL on a store with one destination.
	 *   Runs after the recompression, because a pack is densified once and the dense form is the one
	 *   worth copying into a bucket nobody is going to rewrite again.
	 * @param PlacementIndexInterface|null $placement
	 *   Where each object lives, or NULL when there is only one place it could be. Used to keep the
	 *   recompression on the nearest tier: a cold pack was densified on its way out, and reading it
	 *   again every pass would spend class-B requests in the bucket the tiering exists to keep quiet.
	 */
	public function __construct(
		private readonly StorageProviderInterface $provider,
		private readonly FrameIndexInterface $index,
		private readonly Recompressor $recompressor,
		private readonly Reachability $reachability,
		private readonly LevelPolicy $levels,
		private readonly Lease $lease,
		private readonly LoggerInterface $logger,
		private readonly ?Reanchorer $reanchorer = null,
		private readonly ?Rollup $rollup = null,
		private readonly ?TierMigrator $migrator = null,
		private readonly ?PlacementIndexInterface $placement = null,
	) {}

	#region Passes

	/**
	 * Runs a compaction pass.
	 *
	 * @param int $budget
	 *   Most stored bytes to read while recompressing; zero for no limit.
	 * @param bool $prune
	 *   TRUE to follow the rewrite with a prune. FALSE is the cron default, because removing a
	 *   restore target is not something a scheduled job should do without having been asked.
	 * @param bool $apply
	 *   Passed through to the prune: FALSE produces the same receipt and deletes nothing.
	 *
	 * @return CompactionReport
	 *   What the pass did.
	 */
	public function compact(
		int $budget = 0,
		bool $prune = false,
		bool $apply = false,
	): CompactionReport {
		$started = microtime(true);

		if (!$this->lease->acquire(Lease::COMPACTION)) {
			return new CompactionReport(
				0,
				0,
				0,
				0,
				0,
				PruneReceipt::refuse('another compaction holds the lease'),
				['another compaction holds the lease'],
				microtime(true) - $started,
			);
		}

		try {
			$anchored = $this->reanchorer?->run();
			$folded = $this->rollup?->run(time());
			$dense = $this->recompressor->recompressPacks($this->nearPackKeys(), $budget);
			$migration = $this->migrator?->run($budget);

			foreach ([$anchored, $folded] as $stage) {
				if ($stage !== null && $stage['problems'] !== []) {
					$dense['problems'] = [...$dense['problems'], ...$stage['problems']];
				}
			}

			if ($migration !== null && $migration->problems !== []) {
				$dense['problems'] = [...$dense['problems'], ...$migration->problems];
			}

			// the walk runs after the rewrite, since a rewrite moves every frame it touches
			$this->reachability->refresh();

			$receipt = $prune ? $this->prune($apply) : null;

			$report = new CompactionReport(
				$dense['packs'],
				$dense['frames'],
				$dense['before'],
				$dense['after'],
				$dense['skipped'],
				$receipt,
				$dense['problems'],
				microtime(true) - $started,
			);

			$this->logger->info('Strata %summary', ['%summary' => $report->summary()]);

			return $report;
		} finally {
			$this->lease->release(Lease::COMPACTION);
		}
	}

	/**
	 * Removes what no reachable history needs, or explains why it would not.
	 *
	 * @param bool $apply
	 *   FALSE to produce the receipt without deleting anything. The numbers are the same either way,
	 *   which is what makes the dry run worth reading.
	 * @param int $limit
	 *   Most frames to consider in one pass.
	 *
	 * @return PruneReceipt
	 *   What was removed, what was held back, and why.
	 */
	public function prune(bool $apply = false, int $limit = 1000): PruneReceipt
	{
		$started = microtime(true);

		if (!$this->reachability->isComplete()) {
			return PruneReceipt::refuse(
				sprintf(
					'the reachability walk could not read %d objects, so what is live is unknown',
					count($this->reachability->unreadable()),
				),
				$this->reachability->unreadable(),
			);
		}

		$candidates = $this->index->orphans($limit);
		$collectable = [];
		$kept = [];

		foreach ($candidates as $record) {
			$reason = $this->reachability->frameReason($record->hash);

			if ($reason !== null) {
				$kept[] = sprintf('frame %s: %s', Hash::abbreviate($record->hash), $reason);

				continue;
			}

			$collectable[$record->hash] = $record;
		}

		$superseded = $this->supersededPacks($kept);

		if ($collectable === [] && $superseded === []) {
			return new PruneReceipt($apply, [], [], 0, [], $kept, null, microtime(true) - $started);
		}

		return $this->remove($collectable, $superseded, $kept, $apply, $started);
	}

	#endregion

	#region Removal

	/**
	 * Deletes the objects holding a set of collectable frames.
	 *
	 * A pack survives while it holds one frame anything needs. That means a pass can free nothing
	 * even with collectable frames in hand, which is correct and is reported rather than worked
	 * around: the alternative is rewriting the pack without the dead frames, which is a compaction
	 * job and not a deletion.
	 *
	 * @param array<string, FrameRecord> $collectable
	 *   Collectable records keyed by content address.
	 * @param array<string, int> $superseded
	 *   Object key keyed to its size, for packs the index has moved every frame out of.
	 * @param list<string> $kept
	 *   Lines already collected for the receipt.
	 * @param bool $apply
	 *   FALSE to report without deleting.
	 * @param float $started
	 *   When the pass began.
	 *
	 * @return PruneReceipt
	 *   The receipt.
	 */
	private function remove(
		array $collectable,
		array $superseded,
		array $kept,
		bool $apply,
		float $started,
	): PruneReceipt {
		$standalone = [];
		$packed = [];
		$bytes = 0;

		foreach ($collectable as $hash => $record) {
			if ($record->isPacked()) {
				$packed[(string) $record->pack][] = $hash;

				continue;
			}

			$standalone[$hash] = Hash::key($hash, ObjectStore::FRAME_PREFIX);
			$bytes += $record->storedSize;
		}

		$keys = array_values($standalone);
		$removed = array_keys($standalone);

		foreach ($packed as $pack => $hashes) {
			$holdout = $this->liveFrameIn($pack, $hashes);

			if ($holdout !== null) {
				$kept[] = sprintf(
					'pack %s: frame %s in it is still live',
					Hash::abbreviate($pack),
					Hash::abbreviate($holdout),
				);

				continue;
			}

			$keys[] = Hash::key($pack, ObjectStore::PACK_PREFIX);
			$removed = [...$removed, ...$hashes];

			foreach ($hashes as $hash) {
				$bytes += $collectable[$hash]->storedSize;
			}
		}

		foreach ($superseded as $key => $size) {
			$keys[] = $key;
			$bytes += $size;
		}

		if ($apply && $keys !== []) {
			try {
				$this->provider->delete($keys);
			} catch (Throwable $error) {
				// a store that refused half the deletion has told us it cannot finish; forgetting the
				// index rows anyway would leave objects nothing names, which is what a receipt prevents
				return PruneReceipt::refuse(
					sprintf(
						'the store would not remove what was selected: %s',
						$error->getMessage(),
					),
					$kept,
				);
			}

			$this->index->forget($removed);
		}

		return new PruneReceipt(
			$apply,
			$removed,
			$keys,
			$bytes,
			[],
			$kept,
			null,
			microtime(true) - $started,
		);
	}

	/**
	 * A frame in a pack that is not in the collectable set.
	 *
	 * @param string $pack
	 *   The pack id.
	 * @param list<string> $collectable
	 *   Frames in this pack that may go.
	 *
	 * @return string|null
	 *   The address of a frame that must stay, or NULL when the whole pack may go.
	 */
	private function liveFrameIn(string $pack, array $collectable): ?string
	{
		$key = Hash::key($pack, ObjectStore::PACK_PREFIX);
		$going = array_fill_keys($collectable, true);

		try {
			$entries = PackIndex::decode($key, $this->provider->get($key));
		} catch (Throwable) {
			// a pack that will not read cannot be shown to be safe to delete, so it stays
			return $pack;
		}

		foreach ($entries as $entry) {
			$hash = (string) $entry['hash'];

			if (!isset($going[$hash])) {
				return $hash;
			}
		}

		return null;
	}

	#endregion

	/**
	 * Packs whose every frame the index now files somewhere else.
	 *
	 * Recompression writes a denser pack and repoints the index at it, leaving the predecessor in
	 * place: a row pointing at an object that was never written is data loss, while an object no row
	 * points at is garbage, and the safe order produces garbage. This is what collects it.
	 *
	 * A pack is superseded only when every frame in its directory is found in the index under a
	 * DIFFERENT object. A frame the index does not know at all makes the pack un-sweepable, because
	 * an absent row means either that the frame was collected or that the index is stale, and the
	 * two are indistinguishable from here. Refusing costs one stale object until the next reindex.
	 *
	 * @param list<string> $kept
	 *   Collects a line for each pack that was examined and held.
	 *
	 * @return array<string, int>
	 *   Object key keyed to its size in bytes.
	 */
	private function supersededPacks(array &$kept): array
	{
		$superseded = [];

		foreach ($this->packKeys() as $key) {
			$id = basename($key);

			try {
				$object = $this->provider->get($key);
				$entries = PackIndex::decode($key, $object);
			} catch (Throwable $error) {
				$kept[] = sprintf('pack %s: %s', $key, $error->getMessage());

				continue;
			}

			if ($entries === []) {
				continue;
			}

			$moved = true;

			foreach ($entries as $entry) {
				$record = $this->index->get((string) $entry['hash']);

				if ($record === null || $record->pack === $id) {
					$moved = false;

					break;
				}
			}

			if ($moved) {
				$superseded[$key] = strlen($object);
			}
		}

		return $superseded;
	}

	/**
	 * Pack keys the recompressor should look at.
	 *
	 * Everything, on a store with one destination. On a tiered store, only the packs the placement
	 * index files in the nearest tier: a pack that has already been promoted was densified before it
	 * went, and reading it back every pass to find out that it will not shrink further is a class-B
	 * request in exactly the bucket the tiering exists to keep quiet. A pack with no placement row is
	 * included, because unknown must not read as cold.
	 *
	 * @return list<string>
	 *   Object keys.
	 */
	private function nearPackKeys(): array
	{
		$keys = $this->packKeys();

		if ($this->placement === null) {
			return $keys;
		}

		return array_values(
			array_filter($keys, function (string $key): bool {
				$nearest = $this->placement?->get($key)?->nearest();

				return $nearest === null || $nearest === 0;
			}),
		);
	}

	/**
	 * Every pack key in the store, oldest level first.
	 *
	 * @return list<string>
	 *   Object keys.
	 */
	private function packKeys(): array
	{
		$keys = [];
		$cursor = null;

		do {
			$page = $this->provider->list(ObjectStore::PACK_PREFIX . '/', $cursor, 1000);
			$keys = [...$keys, ...$page->keys()];
			$cursor = $page->cursor;
		} while ($page->hasMore());

		return $keys;
	}

	/**
	 * The retention ladder this compactor works to.
	 *
	 * @return LevelPolicy
	 *   The policy.
	 */
	public function levels(): LevelPolicy
	{
		return $this->levels;
	}
}
