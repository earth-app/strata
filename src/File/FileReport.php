<?php

declare(strict_types=1);

namespace Drupal\strata\File;

use JsonSerializable;

/**
 * What capturing one file did.
 *
 * Reports the uploaded bytes separately from the file's own size, because the whole claim of the file
 * realm is that those two numbers are different: a 2 MiB edit to a 256 MiB file uploads 2.1 MiB, and a
 * report that only said "captured video.mp4" would hide whether that held.
 *
 * @see FileCapture
 */
final class FileReport implements JsonSerializable
{
	/**
	 * Constructs a report.
	 *
	 * @param bool $ran
	 *   Whether anything was stored.
	 * @param string $path
	 *   The file, as it was recorded.
	 * @param int $blocks
	 *   How many blocks the version has.
	 * @param int $uploaded
	 *   How many of those blocks were new.
	 * @param int $shared
	 *   How many were already in the store, from another version or another file.
	 * @param int $bytes
	 *   Bytes uploaded.
	 * @param int $length
	 *   The file's own length.
	 * @param float $changedRatio
	 *   Share of block positions whose content differs from the previous version.
	 * @param bool $shifted
	 *   Whether the change looks like a shift rather than an edit.
	 * @param bool $firstCapture
	 *   Whether this was the file's first version.
	 * @param string $skipped
	 *   Why nothing was stored, empty when something was.
	 * @param float $seconds
	 *   How long the capture took.
	 */
	public function __construct(
		public readonly bool $ran = false,
		public readonly string $path = '',
		public readonly int $blocks = 0,
		public readonly int $uploaded = 0,
		public readonly int $shared = 0,
		public readonly int $bytes = 0,
		public readonly int $length = 0,
		public readonly float $changedRatio = 0.0,
		public readonly bool $shifted = false,
		public readonly bool $firstCapture = false,
		public readonly string $skipped = '',
		public readonly float $seconds = 0.0,
	) {}

	/**
	 * A report for a file nothing was done to.
	 *
	 * @param string $path
	 *   The file.
	 * @param string $reason
	 *   Why.
	 * @param float $started
	 *   When the attempt began, as a microtime float.
	 *
	 * @return FileReport
	 *   The report.
	 */
	public static function skipped(string $path, string $reason, float $started): self
	{
		return new self(
			false,
			$path,
			0,
			0,
			0,
			0,
			0,
			0.0,
			false,
			false,
			$reason,
			microtime(true) - $started,
		);
	}

	/**
	 * What share of the file was uploaded.
	 *
	 * The number the whole design rests on: 0.81% for a measured 2 MiB in-place edit on a 256 MiB
	 * file, against 100% for whole-object storage.
	 *
	 * @return float
	 *   A fraction between 0 and 1.
	 */
	public function uploadShare(): float
	{
		return $this->length < 1 ? 0.0 : min(1.0, $this->bytes / $this->length);
	}

	/**
	 * How many bytes storing this version saved against storing the file whole.
	 *
	 * @return int
	 *   Bytes.
	 */
	public function saved(): int
	{
		return max(0, $this->length - $this->bytes);
	}

	/**
	 * One line describing the capture.
	 *
	 * @return string
	 *   The summary.
	 */
	public function summary(): string
	{
		if (!$this->ran) {
			return sprintf('did not capture %s: %s', $this->path, $this->skipped);
		}

		return sprintf(
			'captured %s: %d of %d blocks uploaded, %s of %s (%.2f%%)%s',
			$this->path,
			$this->uploaded,
			$this->blocks,
			$this->format($this->bytes),
			$this->format($this->length),
			$this->uploadShare() * 100,
			$this->shifted ? ', and its content is shifting rather than being edited' : '',
		);
	}

	/**
	 * A byte count in the largest unit that stays readable.
	 *
	 * @param int $bytes
	 *   The count.
	 *
	 * @return string
	 *   Something such as "2.1 MiB".
	 */
	private function format(int $bytes): string
	{
		return match (true) {
			$bytes < 1024 => sprintf('%d B', $bytes),
			$bytes < 1_048_576 => sprintf('%.1f KiB', $bytes / 1024),
			$bytes < 1_073_741_824 => sprintf('%.1f MiB', $bytes / 1_048_576),
			default => sprintf('%.2f GiB', $bytes / 1_073_741_824),
		};
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The report as data.
	 */
	public function jsonSerialize(): array
	{
		return [
			'ran' => $this->ran,
			'path' => $this->path,
			'blocks' => $this->blocks,
			'uploaded' => $this->uploaded,
			'shared' => $this->shared,
			'bytes' => $this->bytes,
			'length' => $this->length,
			'upload_share' => round($this->uploadShare(), 6),
			'saved' => $this->saved(),
			'changed_ratio' => round($this->changedRatio, 6),
			'shifted' => $this->shifted,
			'first_capture' => $this->firstCapture,
			'skipped' => $this->skipped,
			'seconds' => round($this->seconds, 4),
		];
	}
}
