<?php

declare(strict_types=1);

namespace Drupal\strata\Crypto;

use Drupal\strata\Cas\FrameIndexInterface;
use Drupal\strata\Cas\FrameRecord;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Compaction\Recompressor;
use Drupal\strata\Health\HealthLedgerInterface;
use Drupal\strata\Health\Tripwire\KeyRotatedMidFlight;
use Drupal\strata\Health\TripwireRegistry;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Measures how much of the store still needs a retired key, and re-seals it a batch at a time.
 *
 * A rotation is not an event, it is a state the store is in until every frame has been re-sealed. This
 * is what reports on that state and what advances it.
 *
 * **Re-sealing is incremental and bounded, and it is safe to stop at any point.** A frame is read,
 * opened with whichever key works, sealed with the active key and written back to the same address -
 * the content address is over the DECODED bytes, so re-sealing does not move a frame and nothing that
 * references it has to be rewritten. A pass that runs out of budget leaves a consistent store where
 * some frames are new and some are old, which is the same state it started in.
 *
 * **A rotation is only finished when nothing needs a retired key.** Until then the retired keys must
 * stay configured; removing one makes every frame still sealed under it unreadable, and nothing can
 * tell that apart from corruption afterwards. `isComplete()` is the only safe signal for removing a
 * key, and the report says so.
 *
 * @see KeyRing
 * @see RotatingCipher
 */
final class KeyRotation
{
	/**
	 * Frames one pass re-seals when no budget is given.
	 *
	 * Each one is a read, an open, a seal and a write, so the budget is a request budget as much as a
	 * time one.
	 */
	public const DEFAULT_BUDGET = 500;

	/**
	 * Frames one measurement samples.
	 *
	 * A measurement is a report, not a pass, so it reads a sample rather than the whole index; the
	 * share it reports is an estimate and is labelled as one.
	 */
	public const SAMPLE = 200;

	/**
	 * Constructs a rotation.
	 *
	 * @param RotatingCipher $cipher
	 *   Opens a frame with whichever key works and reports which one that was.
	 * @param ObjectStore $store
	 *   Reads the frames.
	 * @param Recompressor $recompressor
	 *   Rewrites an object under the active key. A packed frame is re-sealed with its pack, which is
	 *   the rule compaction already follows and the reason a frame is not rewritten here.
	 * @param FrameIndexInterface $index
	 *   Enumerates the frames to examine.
	 * @param HealthLedgerInterface $ledger
	 *   Where a mid-flush rotation is recorded.
	 * @param LoggerInterface $logger
	 *   Records what a pass did.
	 */
	public function __construct(
		private readonly RotatingCipher $cipher,
		private readonly ObjectStore $store,
		private readonly Recompressor $recompressor,
		private readonly FrameIndexInterface $index,
		private readonly HealthLedgerInterface $ledger,
		private readonly LoggerInterface $logger,
	) {}

	/**
	 * How much of the store still needs a retired key.
	 *
	 * @param int $sample
	 *   Frames to examine.
	 *
	 * @return array{rotating: bool, keys: int, sampled: int, stale: int, unreadable: int,
	 *   complete: bool, fingerprints: list<string>}
	 *   Whether a rotation is in progress, how many keys are on the ring, what the sample found, and
	 *   whether it is safe to remove the retired keys.
	 */
	public function measure(int $sample = self::SAMPLE): array
	{
		$ring = $this->cipher->ring();
		$sampled = 0;
		$stale = 0;
		$unreadable = 0;

		if ($ring->isRotating()) {
			foreach ($this->frames(max(1, $sample)) as $record) {
				$sampled++;
				$opened = $this->fingerprintOf($record->hash);

				if ($opened === null) {
					$unreadable++;

					continue;
				}
				if (!$ring->isActive($opened)) {
					$stale++;
				}
			}
		}

		return [
			'rotating' => $ring->isRotating(),
			'keys' => $ring->count(),
			'sampled' => $sampled,
			'stale' => $stale,
			'unreadable' => $unreadable,
			// an unreadable frame is not evidence a key is still needed, so it does not block removal
			'complete' => !$ring->isRotating() || ($sampled > 0 && $stale === 0),
			'fingerprints' => $ring->fingerprints(),
		];
	}

	/**
	 * Re-seals frames that still open under a retired key.
	 *
	 * @param int $budget
	 *   Frames to select at most. A packed frame is re-sealed with its whole pack, so a pass can
	 *   re-seal more frames than the budget names: the budget bounds what is read and examined, and
	 *   one object is the smallest unit that can be re-sealed.
	 *
	 * @return array{examined: int, resealed: int, skipped: int, unreadable: int, problems: list<string>}
	 *   What the pass did.
	 */
	public function run(int $budget = self::DEFAULT_BUDGET): array
	{
		$ring = $this->cipher->ring();
		$examined = 0;
		$resealed = 0;
		$skipped = 0;
		$unreadable = 0;
		$problems = [];

		if (!$ring->isRotating()) {
			return [
				'examined' => 0,
				'resealed' => 0,
				'skipped' => 0,
				'unreadable' => 0,
				'problems' => [],
			];
		}

		$stale = [];

		foreach ($this->frames(max(1, $budget) * 2) as $record) {
			if (count($stale) >= $budget) {
				break;
			}

			$examined++;

			if ($this->read($record->hash, $problems) === null) {
				$unreadable++;

				continue;
			}
			if ($this->cipher->openedWithActiveKey()) {
				$skipped++;

				continue;
			}

			$stale[] = $record;
		}

		$resealed = $this->resealAll($stale, $problems);

		$this->logger->notice(
			'Strata re-sealed @resealed frames under the active key, skipped @skipped already current',
			['@resealed' => $resealed, '@skipped' => $skipped],
		);

		return [
			'examined' => $examined,
			'resealed' => $resealed,
			'skipped' => $skipped,
			'unreadable' => $unreadable,
			'problems' => $problems,
		];
	}

	/**
	 * Records a finding when one segment's frames were sealed under more than one key.
	 *
	 * @param string $segment
	 *   The segment key.
	 * @param list<string> $frames
	 *   The frame addresses the segment references.
	 *
	 * @return bool
	 *   TRUE when a finding was recorded.
	 */
	public function checkSegment(string $segment, array $frames): bool
	{
		$fingerprints = [];

		foreach ($frames as $hash) {
			$opened = $this->fingerprintOf($hash);

			if ($opened !== null) {
				$fingerprints[] = $opened;
			}
		}

		$findings = (new TripwireRegistry())
			->register(new KeyRotatedMidFlight())
			->evaluate(['segment' => $segment, 'fingerprints' => $fingerprints]);

		foreach ($findings as $finding) {
			$this->ledger->record($finding);
		}

		return $findings !== [];
	}

	/**
	 * The frames to examine, oldest first.
	 *
	 * Every frame, referenced or not. A rotation is not a reachability question: a frame nothing
	 * points at yet is still sealed under a key, and a frame the tree does point at is exactly the
	 * one that must not stop opening.
	 *
	 * @param int $limit
	 *   Most frames to return.
	 *
	 * @return list<FrameRecord>
	 *   Frame records.
	 */
	private function frames(int $limit): array
	{
		return $this->index->page($limit);
	}

	/**
	 * The fingerprint of the key that opens one frame.
	 *
	 * @param string $hash
	 *   The frame address.
	 *
	 * @return string|null
	 *   The fingerprint, or NULL when no key on the ring opened it.
	 */
	private function fingerprintOf(string $hash): ?string
	{
		$problems = [];

		return $this->read($hash, $problems) === null ? null : $this->cipher->openedWith();
	}

	/**
	 * Reads one frame, recording rather than throwing when it will not open.
	 *
	 * @param string $hash
	 *   The frame address.
	 * @param list<string> $problems
	 *   Problems collected so far, added to by reference.
	 *
	 * @return string|null
	 *   The decoded bytes, or NULL when the frame could not be read.
	 */
	private function read(string $hash, array &$problems): ?string
	{
		try {
			return $this->store->frame($hash);
		} catch (Throwable $error) {
			$problems[] = sprintf('%s did not open: %s', $hash, $error->getMessage());

			return null;
		}
	}

	/**
	 * Writes a set of frames back under the active key.
	 *
	 * A frame cannot be re-sealed by writing its bytes again: the store deduplicates on the content
	 * address, so a second write of the same bytes finds the frame already present and only takes a
	 * reference. The object itself has to be rewritten, which is what the recompressor does, and a
	 * packed frame is rewritten with its whole pack rather than pulled out of it.
	 *
	 * The address never moves, so nothing that names a frame has to be rewritten. A pack that is
	 * rewritten leaves its predecessor behind for the prune, exactly as recompaction does.
	 *
	 * @param list<FrameRecord> $records
	 *   The frames that still open under a retired key.
	 * @param list<string> $problems
	 *   Problems collected so far, added to by reference.
	 *
	 * @return int
	 *   How many frames were re-sealed. A pack counts every frame it carried forward, because all of
	 *   them are sealed under the active key afterwards.
	 */
	private function resealAll(array $records, array &$problems): int
	{
		$packs = [];
		$standalone = [];

		foreach ($records as $record) {
			if ($record->isPacked()) {
				$packs[(string) $record->pack] = true;

				continue;
			}

			$standalone[] = $record->hash;
		}

		$resealed = 0;

		foreach (array_keys($packs) as $pack) {
			try {
				$report = $this->recompressor->recompressPack(
					Hash::key($pack, ObjectStore::PACK_PREFIX),
					true,
				);
				$resealed += (int) $report['frames'];
			} catch (Throwable $error) {
				$problems[] = sprintf(
					'pack %s could not be re-sealed: %s',
					$pack,
					$error->getMessage(),
				);
			}
		}

		foreach ($standalone as $hash) {
			try {
				$this->recompressor->recompressFrame($hash, true);
				$resealed++;
			} catch (Throwable $error) {
				$problems[] = sprintf('%s could not be re-sealed: %s', $hash, $error->getMessage());
			}
		}

		return $resealed;
	}
}
