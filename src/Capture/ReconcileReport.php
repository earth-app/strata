<?php

declare(strict_types=1);

namespace Drupal\strata\Capture;

use JsonSerializable;

/**
 * What one reconciliation pass found.
 *
 * The number to read is `drifted`. Zero means every table that changed since the last pass had an
 * operation recorded for it, which is the only evidence that capture is complete. Anything above
 * zero names the tables the history has a gap for.
 *
 * @see Reconciler
 */
final class ReconcileReport implements JsonSerializable
{
	/**
	 * Constructs a report.
	 *
	 * @param int $examined
	 *   Tables whose watermark was read.
	 * @param int $changed
	 *   Tables whose watermark moved.
	 * @param int $drifted
	 *   Tables that moved with no captured operation to account for it.
	 * @param int $captured
	 *   Rows the pass captured to close a gap.
	 * @param array<string, string> $drift
	 *   Table name keyed to what moved, for the tables that drifted.
	 * @param list<string> $problems
	 *   One line per table that could not be read.
	 * @param float $seconds
	 *   How long the pass took.
	 * @param bool $complete
	 *   FALSE when the pass stopped at a limit rather than covering every captured table.
	 */
	public function __construct(
		public readonly int $examined = 0,
		public readonly int $changed = 0,
		public readonly int $drifted = 0,
		public readonly int $captured = 0,
		public readonly array $drift = [],
		public readonly array $problems = [],
		public readonly float $seconds = 0.0,
		public readonly bool $complete = true,
	) {}

	/**
	 * Whether capture accounted for every change the pass saw.
	 *
	 * @return bool
	 *   TRUE when nothing drifted and every table was readable.
	 */
	public function isClean(): bool
	{
		return $this->drifted === 0 && $this->problems === [];
	}

	/**
	 * A one-line summary for a log entry or a command's output.
	 *
	 * @return string
	 *   The summary.
	 */
	public function summary(): string
	{
		$scope = $this->complete
			? sprintf('%d tables', $this->examined)
			: sprintf('%d of the captured tables', $this->examined);

		if ($this->isClean()) {
			return sprintf(
				'reconciled %s in %.2fs: %d changed, all accounted for',
				$scope,
				$this->seconds,
				$this->changed,
			);
		}

		$summary = sprintf(
			'reconciled %s in %.2fs: %d changed, %d drifted',
			$scope,
			$this->seconds,
			$this->changed,
			$this->drifted,
		);

		if ($this->captured > 0) {
			$summary .= sprintf(', %d rows captured to close the gap', $this->captured);
		}
		if ($this->problems !== []) {
			$summary .= sprintf(', %d unreadable', count($this->problems));
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
			'examined' => $this->examined,
			'changed' => $this->changed,
			'drifted' => $this->drifted,
			'captured' => $this->captured,
			'drift' => $this->drift,
			'problems' => $this->problems,
			'seconds' => round($this->seconds, 4),
			'complete' => $this->complete,
			'clean' => $this->isClean(),
		];
	}
}
