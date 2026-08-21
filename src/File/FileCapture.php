<?php

declare(strict_types=1);

namespace Drupal\strata\File;

use Drupal\strata\Capture\CaptureScope;
use Drupal\strata\Health\HealthLedgerInterface;
use Drupal\strata\Health\TripwireRegistry;
use Drupal\strata\Journal\JournalInterface;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Captures one file as blocks, and records which version it is.
 *
 * The realm with a different economy from every other one. Files are around 98% of the stored bytes on
 * a site that captures them and get almost none of the compression benefit, so they have their own
 * ladder, their own budget line, their own storage class, and a different default: a block is stored
 * once by content and shared by every file and version that holds it.
 *
 * **The journal carries the map, not the bytes.** An operation in the file realm is a few hundred bytes
 * naming the map that reassembles the version; the blocks went straight to the store. That keeps a
 * 256 MiB file out of the flush window entirely, which is what stops one large upload from sealing a
 * segment nothing else needed.
 *
 * **A change is priced before it is paid.** The previous version's map is compared with the new one, and
 * a file whose blocks have shifted rather than changed raises `file.shift_detected` instead of quietly
 * storing 60% of itself on every save.
 *
 * @see MediaStore
 * @see ShiftDetector
 * @see FileMapDiff
 */
final class FileCapture
{
	/**
	 * State key prefix holding the map key of each file's last captured version.
	 */
	public const STATE_PREFIX = 'strata.file.';

	/**
	 * Constructs a capture.
	 *
	 * @param MediaStore $store
	 *   Where blocks and maps go.
	 * @param BlockSplitter $splitter
	 *   Splits a file into blocks.
	 * @param ShiftDetector $shifts
	 *   Decides whether a change is a shift.
	 * @param JournalInterface $journal
	 *   Where the operation naming the version is appended.
	 * @param CaptureScope $scope
	 *   Decides whether the file realm is captured at all.
	 * @param StateInterface $state
	 *   Remembers which version was captured last.
	 * @param TripwireRegistry $tripwires
	 *   Runs the shift check.
	 * @param HealthLedgerInterface $ledger
	 *   Where a shift is recorded.
	 * @param LoggerInterface $logger
	 *   Records what a capture did.
	 */
	public function __construct(
		private readonly MediaStore $store,
		private readonly BlockSplitter $splitter,
		private readonly ShiftDetector $shifts,
		private readonly JournalInterface $journal,
		private readonly CaptureScope $scope,
		private readonly StateInterface $state,
		private readonly TripwireRegistry $tripwires,
		private readonly HealthLedgerInterface $ledger,
		private readonly LoggerInterface $logger,
	) {}

	/**
	 * Captures one file if it has changed.
	 *
	 * @param string $path
	 *   Path to the file on disk.
	 * @param string|null $storedAs
	 *   The path to record, when it differs from where the file sits on disk.
	 * @param int|null $actor
	 *   Drupal user id responsible, or NULL for unattended work.
	 *
	 * @return FileReport
	 *   What the capture did.
	 */
	public function capture(string $path, ?string $storedAs = null, ?int $actor = null): FileReport
	{
		$started = microtime(true);
		$subject = $storedAs ?? $path;

		if (!$this->scope->covers(Realm::FILE)) {
			return FileReport::skipped($subject, 'the file realm is not captured', $started);
		}
		if (!is_file($path) || !is_readable($path)) {
			return FileReport::skipped($subject, 'the file is not readable', $started);
		}

		try {
			$previous = $this->previousMap($subject);
			$candidate = $this->splitter->map($path);
			$diff = FileMapDiff::between($previous, $candidate);
		} catch (Throwable $error) {
			$this->logger->error('Strata could not read %file: %message', [
				'%file' => $subject,
				'%message' => $error->getMessage(),
			]);

			return FileReport::skipped($subject, $error->getMessage(), $started);
		}

		if ($diff->isUnchanged()) {
			return FileReport::skipped($subject, 'the file is unchanged', $started);
		}

		$this->raise($diff);

		try {
			$stored = $this->store->store($path, $subject);
			$this->append($stored['map'], $stored['key'], $diff, $actor);
			$this->state->set(self::STATE_PREFIX . md5($subject), $stored['key']);
		} catch (Throwable $error) {
			$this->logger->error('Strata could not store %file: %message', [
				'%file' => $subject,
				'%message' => $error->getMessage(),
			]);

			return FileReport::skipped($subject, $error->getMessage(), $started);
		}

		$report = new FileReport(
			true,
			$subject,
			$stored['map']->count(),
			$stored['uploaded'],
			$stored['skipped'],
			$stored['bytes'],
			$stored['map']->length,
			$diff->changedRatio(),
			$this->shifts->hasShifted($diff),
			$diff->isFirstCapture(),
			'',
			microtime(true) - $started,
		);

		$this->logger->info('Strata %summary', ['%summary' => $report->summary()]);

		return $report;
	}

	/**
	 * What capturing a file would cost, without storing anything.
	 *
	 * @param string $path
	 *   Path to the file.
	 * @param string|null $storedAs
	 *   The path to compare against.
	 *
	 * @return array{blocks: int, new: int, bytes: int, ratio: float, shifted: bool, seconds: float}
	 *   The measurement, including how long the split itself would take on this host.
	 */
	public function measure(string $path, ?string $storedAs = null): array
	{
		$subject = $storedAs ?? $path;
		$candidate = $this->splitter->map($path);
		$diff = FileMapDiff::between($this->previousMap($subject), $candidate);

		return [
			'blocks' => $candidate->count(),
			'new' => count($diff->newBlocks),
			'bytes' => $diff->uploadCeiling(),
			'ratio' => $diff->changedRatio(),
			'shifted' => $this->shifts->hasShifted($diff),
			'seconds' => BlockSplitter::secondsFor($candidate->length),
		];
	}

	/**
	 * The map of a file's last captured version.
	 *
	 * @param string $subject
	 *   The path as it was recorded.
	 *
	 * @return FileMap|null
	 *   The map, or NULL when the file has not been captured before or its map cannot be read. An
	 *   unreadable previous version is treated as a first capture, which stores the whole file: more
	 *   expensive and never wrong.
	 */
	public function previousMap(string $subject): ?FileMap
	{
		$key = (string) $this->state->get(self::STATE_PREFIX . md5($subject), '');

		if ($key === '') {
			return null;
		}

		try {
			return $this->store->map($key);
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * Appends the operation naming a stored version.
	 *
	 * @param FileMap $map
	 *   The version.
	 * @param string $key
	 *   The map's object key.
	 * @param FileMapDiff $diff
	 *   What changed, which the label reports.
	 * @param int|null $actor
	 *   Drupal user id, or NULL.
	 */
	private function append(FileMap $map, string $key, FileMapDiff $diff, ?int $actor): void
	{
		$payload = (string) json_encode(['map' => $key, 'address' => $map->address()]);

		$this->journal->append(
			new JournalOp(
				0,
				(int) round(microtime(true) * JournalOp::MICROSECONDS_PER_SECOND),
				Realm::FILE,
				$map->path,
				$diff->isFirstCapture() ? Verb::CREATE : Verb::UPDATE,
				$actor,
				null,
				null,
				null,
				strlen($payload),
				$diff->summary(),
				[],
			),
			$payload,
		);
	}

	/**
	 * Records a shift finding when the change looks like one.
	 *
	 * @param FileMapDiff $diff
	 *   What changed.
	 */
	private function raise(FileMapDiff $diff): void
	{
		foreach ($this->tripwires->evaluate($this->shifts->observe($diff)) as $finding) {
			$this->ledger->record($finding);
		}
	}
}
