<?php

declare(strict_types=1);

namespace Drupal\strata\Restore;

use JsonSerializable;

/**
 * What replacing one table's contents would actually leave behind.
 *
 * The number this exists for is the comparison between the rows the table holds now and the rows
 * Strata captured for it. Those two are equal on a table whose whole life was captured and unequal on
 * a table that predates capture, and the difference is how many rows a restore would silently delete.
 *
 * A confirm form that printed "restore 4,120 rows" without printing "the table currently holds
 * 51,880" would be describing the same operation in a way that hides its cost. So both numbers are
 * here, and the plan carries the warning rather than leaving the caller to work it out.
 *
 * @see PhysicalRestore
 */
final class PhysicalPlan implements JsonSerializable
{
	/**
	 * Constructs a plan.
	 *
	 * @param string $table
	 *   The table.
	 * @param string $target
	 *   Commit id the restore targets.
	 * @param int $liveRows
	 *   Rows the table holds now.
	 * @param int $capturedRows
	 *   Rows Strata captured for it at the target.
	 * @param list<string> $columns
	 *   The union of columns those rows carry.
	 * @param int $unreadable
	 *   Captured rows that could not be replayed completely.
	 * @param string|null $refused
	 *   Why the plan cannot proceed at all, or NULL.
	 * @param float $seconds
	 *   How long planning took.
	 */
	public function __construct(
		public readonly string $table,
		public readonly string $target,
		public readonly int $liveRows = 0,
		public readonly int $capturedRows = 0,
		public readonly array $columns = [],
		public readonly int $unreadable = 0,
		public readonly ?string $refused = null,
		public readonly float $seconds = 0.0,
	) {}

	/**
	 * A plan that cannot proceed.
	 *
	 * @param string $table
	 *   The table.
	 * @param string $target
	 *   Commit id.
	 * @param string $reason
	 *   Why.
	 * @param float $seconds
	 *   How long deciding took.
	 *
	 * @return self
	 *   The plan.
	 */
	public static function refuse(
		string $table,
		string $target,
		string $reason,
		float $seconds = 0.0,
	): self {
		return new self($table, $target, 0, 0, [], 0, $reason, $seconds);
	}

	/**
	 * How much of the live table the capture covers.
	 *
	 * @return float
	 *   Captured rows divided by live rows, or 1.0 when the table is empty - restoring an empty
	 *   table to empty loses nothing.
	 */
	public function coverage(): float
	{
		return $this->liveRows === 0 ? 1.0 : $this->capturedRows / $this->liveRows;
	}

	/**
	 * How many rows the restore would remove.
	 *
	 * @return int
	 *   The difference, never below zero. A capture holding MORE rows than the table does is a
	 *   restore that adds rows back, which is what a restore is for.
	 */
	public function rowsLost(): int
	{
		return max(0, $this->liveRows - $this->capturedRows);
	}

	/**
	 * Whether the restore would leave the table materially smaller.
	 *
	 * @return bool
	 *   TRUE when coverage falls below the floor.
	 */
	public function wouldLoseRows(): bool
	{
		return $this->coverage() < PhysicalRestore::COVERAGE_FLOOR;
	}

	/**
	 * The warning a confirm form prints, and the reason a restore refuses without an override.
	 *
	 * @return string
	 *   One sentence naming both numbers.
	 */
	public function rowLossWarning(): string
	{
		return sprintf(
			'%s holds %d rows and only %d were captured, so restoring would remove %d rows ' .
				'(%.1f%% coverage). Strata stores changes rather than snapshots, so rows that ' .
				'predate capture were never stored.',
			$this->table,
			$this->liveRows,
			$this->capturedRows,
			$this->rowsLost(),
			$this->coverage() * 100,
		);
	}

	/**
	 * Whether this plan would write anything.
	 *
	 * @return bool
	 *   TRUE when it can proceed and has rows or an empty target to write.
	 */
	public function isActionable(): bool
	{
		return $this->refused === null && $this->unreadable === 0;
	}

	/**
	 * A one-line summary for a log entry or a command's output.
	 *
	 * @return string
	 *   The summary.
	 */
	public function summary(): string
	{
		if ($this->refused !== null) {
			return sprintf('cannot restore %s: %s', $this->table, $this->refused);
		}

		$summary = sprintf(
			'%s: %d rows captured against %d live (%.1f%% coverage)',
			$this->table,
			$this->capturedRows,
			$this->liveRows,
			$this->coverage() * 100,
		);

		if ($this->unreadable > 0) {
			$summary .= sprintf(', %d rows unreadable', $this->unreadable);
		}
		if ($this->wouldLoseRows()) {
			$summary .= sprintf(', %d rows would be removed', $this->rowsLost());
		}

		return $summary;
	}

	/**
	 * The same plan in the shape the audit log records.
	 *
	 * The audit table is shared with logical restores, so a physical plan presents itself as one
	 * subject - the table - with the coverage carried in the detail.
	 *
	 * @return RestorePlan
	 *   The plan.
	 */
	public function asRestorePlan(): RestorePlan
	{
		$subject = 'table/' . $this->table;
		$status = $this->isActionable() ? SubjectStatus::RESTORABLE : SubjectStatus::UNRESTORABLE;

		return new RestorePlan(
			$this->target,
			1,
			[
				$subject => new ReplayResult(
					$subject,
					true,
					array_fill_keys($this->columns, null),
					$status === SubjectStatus::RESTORABLE ? 1 : 0,
					1,
					$this->unreadable > 0 ? [$this->summary()] : [],
				),
			],
			$this->refused === null ? [] : [$this->refused],
			false,
			$this->seconds,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The plan as a plain array.
	 */
	public function jsonSerialize(): array
	{
		return [
			'table' => $this->table,
			'target' => $this->target,
			'liveRows' => $this->liveRows,
			'capturedRows' => $this->capturedRows,
			'columns' => $this->columns,
			'unreadable' => $this->unreadable,
			'coverage' => round($this->coverage(), 4),
			'rowsLost' => $this->rowsLost(),
			'refused' => $this->refused,
			'seconds' => round($this->seconds, 4),
		];
	}
}
