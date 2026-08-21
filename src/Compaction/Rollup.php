<?php

declare(strict_types=1);

namespace Drupal\strata\Compaction;

use Drupal\strata\Cas\FrameIndexInterface;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Segment\Collapser;
use Drupal\strata\Segment\SegmentManifest;
use Drupal\strata\Segment\SegmentReader;
use Drupal\strata\Segment\SegmentWriter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Folds the segments of one retention level into the coarser level above it.
 *
 * The retention ladder keeps fifteen-second windows for an hour, minutes for a day, hours for a week
 * and days for three months. Rolling up is what moves history between those: two hundred and forty
 * fifteen-second segments become one hour-long segment, and a subject written in several of them
 * survives once.
 *
 * **What this actually saves is smaller than it looks, and the number is worth stating.** Collapse is
 * writes per distinct subject inside the window, and on a site with hundreds of thousands of subjects
 * that is 1.00 at every level below a day: a subject is almost never written twice in the same minute.
 * It becomes meaningful only at monthly rollups. So the saving at fine levels is the object count -
 * two hundred and forty segment manifests become one - rather than the operations inside them, and
 * the real byte saving at those levels comes from recompression instead.
 *
 * **Nothing is deleted here.** A rollup writes the coarse segment and leaves the fine ones in place;
 * removing them is a prune, which needs a reachability walk and a receipt. Writing first and removing
 * separately means a rollup that dies halfway has duplicated history rather than lost it.
 *
 * @see LevelPolicy
 * @see Collapser
 * @see Compactor
 */
final class Rollup
{
	/**
	 * Fine segments read into one coarse window by default.
	 *
	 * A bound on memory: a rollup holds every operation of the window it is folding, and a day of
	 * fifteen-second segments is 5,760 of them.
	 */
	public const DEFAULT_BUDGET = 1000;

	/**
	 * Constructs a rollup pass.
	 *
	 * @param SegmentReader $reader
	 *   Reads the fine segments.
	 * @param SegmentWriter $writer
	 *   Writes the coarse one.
	 * @param LevelPolicy $levels
	 *   The ladder, which decides which windows are due and what they roll into.
	 * @param FrameIndexInterface $index
	 *   Referenced for each frame the coarse segment carries forward, so a prune of the fine segments
	 *   cannot collect a frame the coarse one still names.
	 * @param LoggerInterface $logger
	 *   Records what a pass did.
	 */
	public function __construct(
		private readonly SegmentReader $reader,
		private readonly SegmentWriter $writer,
		private readonly LevelPolicy $levels,
		private readonly FrameIndexInterface $index,
		private readonly LoggerInterface $logger,
	) {}

	/**
	 * Rolls up every window that is due, coarsest level last.
	 *
	 * @param int $now
	 *   Unix seconds to judge retention against.
	 * @param int $budget
	 *   Most fine segments to read in one pass, or zero for the default.
	 *
	 * @return array{windows: int, segments: int, operations: int, collapsed: int, written: list<string>, problems: list<string>}
	 *   How many windows were folded, how many fine segments they covered, how many operations came
	 *   out, how many were collapsed away, the coarse segment keys written, and anything that failed.
	 */
	public function run(int $now, int $budget = 0): array
	{
		$budget = $budget > 0 ? $budget : self::DEFAULT_BUDGET;
		$read = 0;
		$windows = 0;
		$operations = 0;
		$collapsed = 0;
		$written = [];
		$problems = [];

		for ($level = 0; $level < $this->levels->depth(); $level++) {
			if ($this->levels->promotes($level) === null) {
				continue;
			}

			foreach ($this->dueWindows($level, $now) as $start => $keys) {
				if ($read >= $budget) {
					$this->logger->info(
						'Strata stopped rolling up at the budget of %budget segments; the next run continues',
						['%budget' => $budget],
					);

					break 2;
				}

				$read += count($keys);

				try {
					$result = $this->fold($level, (int) $start, $keys);
				} catch (Throwable $error) {
					$problems[] = sprintf(
						'level %d window %d: %s',
						$level,
						$start,
						$error->getMessage(),
					);

					continue;
				}

				if ($result === null) {
					continue;
				}

				$windows++;
				$operations += $result['operations'];
				$collapsed += $result['collapsed'];
				$written[] = $result['key'];
			}
		}

		$this->logger->info(
			'Strata rolled up %windows windows from %segments segments into %written coarse segments',
			['%windows' => $windows, '%segments' => $read, '%written' => count($written)],
		);

		return [
			'windows' => $windows,
			'segments' => $read,
			'operations' => $operations,
			'collapsed' => $collapsed,
			'written' => $written,
			'problems' => $problems,
		];
	}

	/**
	 * The windows at one level whose retention has elapsed, oldest first.
	 *
	 * @param int $level
	 *   Level number.
	 * @param int $now
	 *   Unix seconds.
	 *
	 * @return array<int, list<string>>
	 *   Coarse window start keyed to the fine segment keys inside it.
	 */
	public function dueWindows(int $level, int $now): array
	{
		$coarse = $this->levels->promotes($level);

		if ($coarse === null) {
			return [];
		}

		$grouped = [];

		foreach ($this->reader->keys($level) as $key) {
			$second = SegmentReader::secondOf($key);

			if ($second === null || !$this->levels->isDueForRollup($second, $level, $now)) {
				continue;
			}

			$grouped[$this->levels->windowStart($second, $coarse)][] = $key;
		}

		ksort($grouped);

		return $grouped;
	}

	/**
	 * Folds the segments of one window into a single coarser one.
	 *
	 * @param int $level
	 *   The level being rolled up.
	 * @param int $start
	 *   The coarse window's start second, which the new segment is filed under.
	 * @param list<string> $keys
	 *   The fine segment keys.
	 *
	 * @return array{key: string, operations: int, collapsed: int}|null
	 *   What was written, or NULL when the window held nothing to write.
	 */
	public function fold(int $level, int $start, array $keys): ?array
	{
		$coarse = $this->levels->promotes($level);

		if ($coarse === null || $keys === []) {
			return null;
		}

		$operations = [];
		$payloads = [];
		$rawBytes = 0;

		foreach ($keys as $key) {
			$manifest = $this->reader->read($key);

			foreach ($manifest->operations as $operation) {
				$operations[] = $operation;
				$payloads[$operation->key()] = $manifest->payloadFor($operation);
			}

			$rawBytes += $manifest->rawBytes;
		}

		if ($operations === []) {
			return null;
		}

		$survivors = Collapser::collapse($operations);
		$carried = [];

		foreach ($survivors as $operation) {
			$frames = $payloads[$operation->key()] ?? [];

			if ($frames === []) {
				continue;
			}

			$carried[$operation->key()] = $frames;

			// the coarse segment is a second thing naming these frames, so they gain a reference
			foreach ($frames as $frame) {
				$this->index->reference($frame);
			}
		}

		$manifest = new SegmentManifest(
			$coarse,
			$this->boundary($survivors, true),
			$this->boundary($survivors, false),
			$survivors[0]->microtime,
			$survivors[count($survivors) - 1]->microtime,
			$survivors,
			$carried,
			count($operations),
			$rawBytes,
		);

		return [
			'key' => $this->writer->write($manifest, $start),
			'operations' => count($survivors),
			'collapsed' => count($operations) - count($survivors),
		];
	}

	/**
	 * The lowest or highest sequence in a set of operations.
	 *
	 * A rolled-up window spans several segments, so its sequence range is the range of what survived
	 * rather than the range of any one segment it came from.
	 *
	 * @param list<JournalOp> $operations
	 *   The operations.
	 * @param bool $lowest
	 *   TRUE for the first sequence, FALSE for the last.
	 *
	 * @return int
	 *   The sequence.
	 */
	private function boundary(array $operations, bool $lowest): int
	{
		$sequences = array_map(static fn(JournalOp $op): int => $op->sequence, $operations);

		return $lowest ? min($sequences) : max($sequences);
	}
}
