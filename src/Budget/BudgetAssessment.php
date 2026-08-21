<?php

declare(strict_types=1);

namespace Drupal\strata\Budget;

use JsonSerializable;

/**
 * One budget reading: what a month at the observed rate comes to, and what Strata does about it.
 *
 * Readonly, because a reading describes a moment. A later window produces a new assessment rather
 * than editing this one, so a figure surfaced in a report cannot drift from the rung that was
 * derived from it.
 *
 * @see BudgetGuard
 * @see EscalationLadder
 */
final class BudgetAssessment implements JsonSerializable
{
	/**
	 * The bytes-per-month axis.
	 */
	public const AXIS_BYTES = 'bytes';

	/**
	 * The dollars-per-month axis.
	 */
	public const AXIS_DOLLARS = 'dollars';

	/**
	 * Constructs a reading.
	 *
	 * @param string $rung
	 *   A rung name from EscalationLadder::RUNGS.
	 * @param float $usedFraction
	 *   Projected usage divided by the ceiling that binds. 0.0 when no ceiling is set, or when the
	 *   window was too short to project from.
	 * @param int $projectedBytes
	 *   Bytes the store would be sent over a month at the observed rate.
	 * @param float $projectedDollars
	 *   What that month would cost, storage and requests together.
	 * @param int $bytesCeiling
	 *   The bytes-per-month ceiling in force; 0 means no ceiling on that axis.
	 * @param float $dollarsCeiling
	 *   The dollars-per-month ceiling in force; 0.0 means no ceiling on that axis.
	 * @param string|null $reason
	 *   AXIS_BYTES or AXIS_DOLLARS, naming the axis that produced $usedFraction. NULL when neither
	 *   ceiling is set, or when nothing could be projected.
	 */
	public function __construct(
		public readonly string $rung,
		public readonly float $usedFraction,
		public readonly int $projectedBytes,
		public readonly float $projectedDollars,
		public readonly int $bytesCeiling = 0,
		public readonly float $dollarsCeiling = 0.0,
		public readonly ?string $reason = null,
	) {}

	/**
	 * Whether the projection has passed the ceiling.
	 *
	 * The warn rung is under this line: it reports a month heading for the ceiling, not one that
	 * has crossed it.
	 *
	 * @return bool
	 *   TRUE at or above the full ceiling.
	 */
	public function isOverBudget(): bool
	{
		return $this->usedFraction >= EscalationLadder::REDUCE_AT;
	}

	/**
	 * Whether one realm is still being captured at this rung.
	 *
	 * @param string $realm
	 *   A realm value, as Realm stores it.
	 *
	 * @return bool
	 *   TRUE when capture should continue for that realm.
	 */
	public function allowsRealm(string $realm): bool
	{
		return !EscalationLadder::pausesRealm($this->rung, $realm);
	}

	/**
	 * Whether anything at all is still being captured.
	 *
	 * @return bool
	 *   FALSE only at the stop rung, where the journal refuses appends.
	 */
	public function allowsCapture(): bool
	{
		return !EscalationLadder::stopsEverything($this->rung);
	}

	/**
	 * The reading as one line for a log entry or a status report.
	 *
	 * @return string
	 *   A sentence naming the rung, the projection and the ceiling it was measured against.
	 */
	public function summary(): string
	{
		if ($this->reason === null) {
			return sprintf(
				'%s: projecting %s and $%.2f per month, against no ceiling',
				$this->rung,
				self::readableBytes($this->projectedBytes),
				$this->projectedDollars,
			);
		}

		$against =
			$this->reason === self::AXIS_BYTES
				? sprintf(
					'%s of %s',
					self::readableBytes($this->projectedBytes),
					self::readableBytes($this->bytesCeiling),
				)
				: sprintf('$%.2f of $%.2f', $this->projectedDollars, $this->dollarsCeiling);

		return sprintf(
			'%s: %s per month, %.1f%% of the %s ceiling',
			$this->rung,
			$against,
			$this->usedFraction * 100,
			$this->reason,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The reading as a plain array for a render array or a JSON response.
	 */
	public function jsonSerialize(): array
	{
		return [
			'rung' => $this->rung,
			'reason' => $this->reason,
			'usedFraction' => round($this->usedFraction, 4),
			'projectedBytes' => $this->projectedBytes,
			'projectedDollars' => round($this->projectedDollars, 4),
			'bytesCeiling' => $this->bytesCeiling,
			'dollarsCeiling' => round($this->dollarsCeiling, 4),
			'overBudget' => $this->isOverBudget(),
			'allowsCapture' => $this->allowsCapture(),
			'summary' => $this->summary(),
		];
	}

	/**
	 * Formats a byte count for an operator-facing line.
	 *
	 * Steps by 1024, matching the gigabyte storage is priced by in BudgetGuard. Never parse the
	 * result; it is for a table cell or a log line.
	 *
	 * @param int $bytes
	 *   The count.
	 *
	 * @return string
	 *   A string such as "1.5 GB", or "0 B" for nothing.
	 */
	private static function readableBytes(int $bytes): string
	{
		$units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
		$size = (float) $bytes;
		$unit = 0;

		while ($size >= 1024.0 && $unit < count($units) - 1) {
			$size /= 1024.0;
			$unit++;
		}

		return $unit === 0
			? sprintf('%d %s', $bytes, $units[0])
			: sprintf('%.1f %s', $size, $units[$unit]);
	}
}
