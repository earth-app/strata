<?php

declare(strict_types=1);

namespace Drupal\strata\File;

use JsonSerializable;
use RuntimeException;

/**
 * What changed between two versions of a file, block by block.
 *
 * The thing that decides what a new version costs. A version whose blocks are already in the store
 * costs a map and nothing else; one with thirty-three new blocks costs those blocks.
 *
 * **Two counts, and they answer different questions.** The blocks at changed POSITIONS say how much of
 * the file looks different, which is what tells a shift from an edit. The blocks that are genuinely NEW
 * say what has to be uploaded, and it is always the smaller number - a shifted file is full of blocks
 * that moved rather than changed, and a moved block is already stored.
 *
 * @see FileMap
 * @see ShiftDetector
 */
final class FileMapDiff implements JsonSerializable
{
	/**
	 * Constructs a diff.
	 *
	 * @param FileMap|null $before
	 *   The previous version, or NULL for a first capture.
	 * @param FileMap $after
	 *   The version being stored.
	 * @param list<int> $changedPositions
	 *   Block indexes whose content differs from the same index in the previous version, plus every
	 *   index past the end of it.
	 * @param list<string> $newBlocks
	 *   Block addresses the previous version did not contain anywhere, in first-seen order.
	 * @param list<string> $droppedBlocks
	 *   Block addresses the previous version contained and this one does not.
	 */
	public function __construct(
		public readonly ?FileMap $before,
		public readonly FileMap $after,
		public readonly array $changedPositions,
		public readonly array $newBlocks,
		public readonly array $droppedBlocks,
	) {}

	/**
	 * Compares two versions of a file.
	 *
	 * @param FileMap|null $before
	 *   The previous version, or NULL for a first capture, which reports every block as new.
	 * @param FileMap $after
	 *   The version being stored.
	 *
	 * @return FileMapDiff
	 *   The diff.
	 *
	 * @throws RuntimeException
	 *   When the two maps were split at different block sizes, which would make every position
	 *   comparison meaningless. Recapturing the file at the new size is the answer, and saying so
	 *   beats reporting a 100% change as though the file had been rewritten.
	 */
	public static function between(?FileMap $before, FileMap $after): self
	{
		if ($before !== null && $before->blockSize !== $after->blockSize) {
			throw new RuntimeException(
				sprintf(
					'%s was mapped at %d-byte blocks and is now %d, so the versions cannot be compared',
					$after->path,
					$before->blockSize,
					$after->blockSize,
				),
			);
		}

		$previous = $before === null ? [] : $before->blocks;
		$held = array_fill_keys($previous, true);
		$wanted = array_fill_keys($after->blocks, true);
		$changed = [];
		$new = [];

		foreach ($after->blocks as $index => $block) {
			if (($previous[$index] ?? null) !== $block) {
				$changed[] = $index;
			}
			if (!isset($held[$block]) && !isset($new[$block])) {
				$new[$block] = true;
			}
		}

		$dropped = [];

		foreach ($previous as $block) {
			if (!isset($wanted[$block])) {
				$dropped[$block] = true;
			}
		}

		return new self($before, $after, $changed, array_keys($new), array_keys($dropped));
	}

	/**
	 * Whether anything changed at all.
	 *
	 * @return bool
	 *   TRUE when the two versions are identical.
	 */
	public function isUnchanged(): bool
	{
		return $this->changedPositions === [] && $this->newBlocks === [];
	}

	/**
	 * Whether this is a first capture.
	 *
	 * @return bool
	 *   TRUE when there was no previous version.
	 */
	public function isFirstCapture(): bool
	{
		return $this->before === null;
	}

	/**
	 * Share of the file's positions whose content differs.
	 *
	 * The number a shift is detected from. Measured on a 256 MiB file: a 2 MiB in-place edit moves
	 * 0.81% of it, and a 2 MiB insertion moves 60.79%.
	 *
	 * @return float
	 *   A fraction between 0 and 1. A first capture is 1.0, since none of it was there before.
	 */
	public function changedRatio(): float
	{
		$blocks = $this->after->count();

		if ($blocks < 1) {
			return 0.0;
		}

		return count($this->changedPositions) / $blocks;
	}

	/**
	 * Bytes that have to be uploaded, at most.
	 *
	 * @return int
	 *   Bytes. An upper bound, because the file's last block is usually short.
	 */
	public function uploadCeiling(): int
	{
		return count($this->newBlocks) * $this->after->blockSize;
	}

	/**
	 * What share of the file has to be uploaded.
	 *
	 * @return float
	 *   A fraction of the file's length, which is what "a 2 MiB edit costs 0.81%" is measuring.
	 */
	public function uploadShare(): float
	{
		return $this->after->length < 1
			? 0.0
			: min(1.0, $this->uploadCeiling() / $this->after->length);
	}

	/**
	 * One line describing the change.
	 *
	 * @return string
	 *   The summary.
	 */
	public function summary(): string
	{
		if ($this->isUnchanged()) {
			return sprintf('%s is unchanged', $this->after->path);
		}

		return sprintf(
			'%s: %d of %d blocks moved (%.2f%%), %d to upload (%.2f%% of the file)',
			$this->after->path,
			count($this->changedPositions),
			$this->after->count(),
			$this->changedRatio() * 100,
			count($this->newBlocks),
			$this->uploadShare() * 100,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The diff as data. The block lists are counted rather than listed, because a diff of a large
	 *   file holds thousands of digests and a report does not need them.
	 */
	public function jsonSerialize(): array
	{
		return [
			'path' => $this->after->path,
			'first_capture' => $this->isFirstCapture(),
			'blocks' => $this->after->count(),
			'changed_positions' => count($this->changedPositions),
			'new_blocks' => count($this->newBlocks),
			'dropped_blocks' => count($this->droppedBlocks),
			'changed_ratio' => round($this->changedRatio(), 6),
			'upload_share' => round($this->uploadShare(), 6),
		];
	}
}
