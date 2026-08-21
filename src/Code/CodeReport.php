<?php

declare(strict_types=1);

namespace Drupal\strata\Code;

use JsonSerializable;

/**
 * What one code capture pass did.
 *
 * The two numbers worth reading are the redaction count and the drift count. The first says secrets
 * were handled rather than that they were; the second says a dependency was patched by hand, which is
 * the one thing that breaks the assumption `vendor/` is safe to reference rather than store.
 *
 * @see CodeCapture
 */
final class CodeReport implements JsonSerializable
{
	/**
	 * Constructs a report.
	 *
	 * @param bool $ran
	 *   FALSE when the pass found nothing to do.
	 * @param int $files
	 *   Code files captured.
	 * @param int $bytes
	 *   Bytes those files came to, before compression.
	 * @param int $redacted
	 *   Secret assignments replaced before storage.
	 * @param int $lockfiles
	 *   Lockfiles captured as the reference for `vendor/`.
	 * @param int $drifted
	 *   Vendor files captured because they had been patched in place.
	 * @param string $vendor
	 *   What the vendor comparison concluded.
	 * @param list<string> $problems
	 *   One line per file that could not be captured.
	 * @param float $seconds
	 *   How long the pass took.
	 * @param string $skipped
	 *   Why nothing was done, when nothing was.
	 */
	public function __construct(
		public readonly bool $ran = false,
		public readonly int $files = 0,
		public readonly int $bytes = 0,
		public readonly int $redacted = 0,
		public readonly int $lockfiles = 0,
		public readonly int $drifted = 0,
		public readonly string $vendor = '',
		public readonly array $problems = [],
		public readonly float $seconds = 0.0,
		public readonly string $skipped = '',
	) {}

	/**
	 * A report for a pass that had nothing to do.
	 *
	 * @param string $reason
	 *   Why.
	 * @param float $seconds
	 *   How long deciding took.
	 *
	 * @return self
	 *   The report.
	 */
	public static function skipped(string $reason, float $seconds = 0.0): self
	{
		return new self(false, 0, 0, 0, 0, 0, '', [], $seconds, $reason);
	}

	/**
	 * Whether every file the pass reached for was captured.
	 *
	 * @return bool
	 *   TRUE when nothing failed.
	 */
	public function isClean(): bool
	{
		return $this->problems === [];
	}

	/**
	 * Whether a dependency was found patched in place.
	 *
	 * @return bool
	 *   TRUE when vendor files had to be captured as bytes.
	 */
	public function foundDrift(): bool
	{
		return $this->drifted > 0;
	}

	/**
	 * A one-line summary for a log entry or a command's output.
	 *
	 * @return string
	 *   The summary.
	 */
	public function summary(): string
	{
		if (!$this->ran) {
			return sprintf('code capture skipped: %s', $this->skipped);
		}

		$summary = sprintf(
			'captured %d code files (%s) and %d lockfiles in %.2fs',
			$this->files,
			self::humanBytes($this->bytes),
			$this->lockfiles,
			$this->seconds,
		);

		if ($this->redacted > 0) {
			$summary .= sprintf(', %d secrets redacted', $this->redacted);
		}
		if ($this->foundDrift()) {
			$summary .= sprintf(', %d patched vendor files captured', $this->drifted);
		}
		if (!$this->isClean()) {
			$summary .= sprintf(', %d files unreadable', count($this->problems));
		}

		return $summary;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The report as a plain array.
	 */
	public function jsonSerialize(): array
	{
		return [
			'ran' => $this->ran,
			'files' => $this->files,
			'bytes' => $this->bytes,
			'redacted' => $this->redacted,
			'lockfiles' => $this->lockfiles,
			'drifted' => $this->drifted,
			'vendor' => $this->vendor,
			'problems' => $this->problems,
			'seconds' => round($this->seconds, 4),
			'skipped' => $this->skipped,
			'clean' => $this->isClean(),
		];
	}

	/**
	 * Renders a byte count at a readable scale.
	 *
	 * @param int $bytes
	 *   The count.
	 *
	 * @return string
	 *   For example "762.3 KiB".
	 */
	private static function humanBytes(int $bytes): string
	{
		$units = ['B', 'KiB', 'MiB', 'GiB'];
		$value = (float) $bytes;
		$unit = 0;

		while ($value >= 1024.0 && $unit < count($units) - 1) {
			$value /= 1024.0;
			$unit++;
		}

		return sprintf($unit === 0 ? '%d %s' : '%.1f %s', $value, $units[$unit]);
	}
}
