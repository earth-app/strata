<?php

declare(strict_types=1);

namespace Drupal\strata\File;

use InvalidArgumentException;

/**
 * Tells a file whose content shifted from one that was edited in place.
 *
 * Fixed blocks cannot follow an insertion. Insert two megabytes at the front of a file and every block
 * after the insertion moves, so the file looks entirely rewritten even though almost all of its content
 * is unchanged. Measured on a 256 MiB file: a 2 MiB in-place edit moves 0.81% of the blocks and a 2 MiB
 * insertion moves 60.79%, at every block size from 64 KiB to 4 MiB.
 *
 * That gap is what makes the two cases separable. Above the threshold the file is shifting rather than
 * being edited, and fixed blocks are the wrong tool for it - so the finding is raised and an
 * administrator is offered the two things that would work: whole-object storage, or the content-defined
 * chunker at its measured 4.29 MB/s.
 *
 * **Nothing is changed automatically.** A shift is not a fault and the right answer depends on the file
 * type: an appended log wants the chunker, a re-encoded video wants whole-object storage, and a
 * database dump might want neither. Choosing costs storage or CPU, and that is an operator's call.
 *
 * @see FileMapDiff
 * @see Drupal\strata\Cas\Chunker\FastCdcChunker
 */
final class ShiftDetector
{
	/**
	 * Share of moved blocks above which the content is taken to have shifted.
	 *
	 * 30%, chosen to sit in the wide gap between the measured in-place case at 0.81% and the measured
	 * insertion case at 60.79%. A file type whose real distribution straddles this is exactly what
	 * calibration is for.
	 */
	public const DEFAULT_THRESHOLD = 0.3;

	/**
	 * Fewest blocks a file must have before a ratio means anything.
	 *
	 * On a four-block file one changed block is 25% and says nothing about shifting.
	 */
	public const MIN_BLOCKS = 16;

	/**
	 * Constructs a detector.
	 *
	 * @param float $threshold
	 *   Share of moved blocks that counts as a shift.
	 * @param int $minimumBlocks
	 *   Fewest blocks before the ratio is judged at all.
	 *
	 * @throws InvalidArgumentException
	 *   When the threshold is not a fraction above zero.
	 */
	public function __construct(
		private readonly float $threshold = self::DEFAULT_THRESHOLD,
		private readonly int $minimumBlocks = self::MIN_BLOCKS,
	) {
		if ($threshold <= 0.0 || $threshold > 1.0) {
			throw new InvalidArgumentException('A shift threshold is a fraction above zero');
		}
		if ($minimumBlocks < 1) {
			throw new InvalidArgumentException('A shift needs at least one block to be judged on');
		}
	}

	/**
	 * The configured threshold.
	 *
	 * @return float
	 *   A fraction.
	 */
	public function threshold(): float
	{
		return $this->threshold;
	}

	/**
	 * Whether a change looks like a shift rather than an edit.
	 *
	 * @param FileMapDiff $diff
	 *   The change.
	 *
	 * @return bool
	 *   TRUE when the moved-block share is past the threshold on a file large enough to judge.
	 */
	public function hasShifted(FileMapDiff $diff): bool
	{
		if ($diff->isFirstCapture() || $diff->after->count() < $this->minimumBlocks) {
			return false;
		}

		return $diff->changedRatio() > $this->threshold;
	}

	/**
	 * The observation a tripwire reads.
	 *
	 * @param FileMapDiff $diff
	 *   The change.
	 *
	 * @return array<string, mixed>
	 *   The observation.
	 */
	public function observe(FileMapDiff $diff): array
	{
		return [
			'file' => $diff->after->path,
			'file_shifted' => $this->hasShifted($diff),
			'changed_ratio' => $diff->changedRatio(),
			'shift_threshold' => $this->threshold,
			'changed_blocks' => count($diff->changedPositions),
			'total_blocks' => $diff->after->count(),
			'upload_share' => $diff->uploadShare(),
		];
	}

	/**
	 * What an operator could do about a shifting file.
	 *
	 * @param FileMapDiff $diff
	 *   The change.
	 *
	 * @return list<string>
	 *   The options, each naming what it costs, empty when nothing shifted.
	 */
	public function options(FileMapDiff $diff): array
	{
		if (!$this->hasShifted($diff)) {
			return [];
		}

		return [
			sprintf(
				'store each version whole: %d bytes per version, no block sharing at all',
				$diff->after->length,
			),
			sprintf(
				'split it with the content-defined chunker: follows the shift, and runs at %.1f MB/s against %.0f MB/s for fixed blocks',
				4.29,
				BlockSplitter::MEASURED_THROUGHPUT / 1_000_000,
			),
			'leave it as it is: correct, and it stores most of the file on every change',
		];
	}

	/**
	 * A detector from the module's settings.
	 *
	 * @param float|null $threshold
	 *   The configured threshold, or NULL for the default.
	 *
	 * @return ShiftDetector
	 *   The detector.
	 */
	public static function fromSettings(?float $threshold): self
	{
		return new self(
			$threshold === null || $threshold <= 0.0 || $threshold > 1.0
				? self::DEFAULT_THRESHOLD
				: $threshold,
		);
	}
}
