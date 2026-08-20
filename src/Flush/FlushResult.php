<?php

declare(strict_types=1);

namespace Drupal\strata\Flush;

use JsonSerializable;

/**
 * What one flush did.
 *
 * Returned rather than logged so a caller decides what to report: cron logs it, Drush prints it,
 * and the settings form shows the last one.
 *
 * @see Flusher
 */
final class FlushResult implements JsonSerializable
{
	/**
	 * Constructs a result.
	 *
	 * @param bool $ran
	 *   Whether a segment was written. FALSE when nothing was pending or no bound had been reached.
	 * @param string|null $reason
	 *   Which flush bound fired, or NULL when none did.
	 * @param string|null $segment
	 *   Object key of the segment written, or NULL when none was.
	 * @param string|null $commit
	 *   Id of the commit appended, or NULL when none was.
	 * @param int $captured
	 *   Operations read out of the journal.
	 * @param int $stored
	 *   Operations that survived collapsing.
	 * @param int $rawBytes
	 *   Decoded payload bytes the operations described.
	 * @param int $trimmed
	 *   Journal rows removed once the segment was sealed.
	 * @param float $seconds
	 *   Wall-clock time the flush took.
	 * @param string|null $skipped
	 *   Why the flush did not run, or NULL when it did.
	 */
	public function __construct(
		public readonly bool $ran,
		public readonly ?string $reason = null,
		public readonly ?string $segment = null,
		public readonly ?string $commit = null,
		public readonly int $captured = 0,
		public readonly int $stored = 0,
		public readonly int $rawBytes = 0,
		public readonly int $trimmed = 0,
		public readonly float $seconds = 0.0,
		public readonly ?string $skipped = null,
	) {}

	/**
	 * A result for a flush that did not run.
	 *
	 * @param string $skipped
	 *   Why it did not run.
	 *
	 * @return self
	 *   The result.
	 */
	public static function skipped(string $skipped): self
	{
		return new self(false, null, null, null, 0, 0, 0, 0, 0.0, $skipped);
	}

	/**
	 * How many operations collapsing removed.
	 *
	 * @return int
	 *   Operations dropped, never below zero.
	 */
	public function collapsed(): int
	{
		return max(0, $this->captured - $this->stored);
	}

	/**
	 * A one-line summary for a log or a status page.
	 *
	 * @return string
	 *   Something such as "sealed 42 operations into 1 segment in 0.31s (age)".
	 */
	public function summary(): string
	{
		if (!$this->ran) {
			return sprintf('no flush: %s', $this->skipped ?? 'nothing pending');
		}

		return sprintf(
			'sealed %d of %d operations in %.2fs (%s)',
			$this->stored,
			$this->captured,
			$this->seconds,
			$this->reason ?? 'forced',
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The result as a plain array.
	 */
	public function jsonSerialize(): array
	{
		return [
			'ran' => $this->ran,
			'reason' => $this->reason,
			'segment' => $this->segment,
			'commit' => $this->commit,
			'captured' => $this->captured,
			'stored' => $this->stored,
			'collapsed' => $this->collapsed(),
			'rawBytes' => $this->rawBytes,
			'trimmed' => $this->trimmed,
			'seconds' => round($this->seconds, 4),
			'skipped' => $this->skipped,
		];
	}
}
