<?php

declare(strict_types=1);

namespace Drupal\strata\Capture;

use JsonSerializable;

/**
 * A cheap, bounded fingerprint of one table.
 *
 * The reconciler compares two of these to answer "did this table change since we last looked", for
 * tables nothing instrumented. It has to be cheap enough to run over every captured table on every
 * cron, which rules out reading the table, and complete enough that an in-place update does not
 * slip past, which rules out a row count on its own.
 *
 * Four measurements, each catching what the others miss:
 *
 * - **row count** catches inserts and deletes, and misses an update, and misses an insert paired
 * with a delete.
 * - **highest key** catches an insert paired with the deletion of an OLDER row, where the count
 * returns to where it started but the maximum has moved up.
 * - **highest changed timestamp** catches an update, on the tables that carry such a column.
 * - **sampled digest** catches an update on the tables that do not, over a bounded number of rows.
 *
 * Two blind spots, stated rather than implied. A row inserted and then deleted again between two
 * readings leaves nothing behind: the count returns to its old value and `MAX()` drops back with
 * it, since a deleted row's key cannot be recovered from the column. And the digest is a sample, so
 * a change confined to rows outside it is not seen. Both are why drift raises a finding for a human
 * to read rather than driving a silent repair, and why the sample size is a setting.
 *
 * @see Reconciler
 */
final class Watermark implements JsonSerializable
{
	/**
	 * Constructs a watermark.
	 *
	 * @param string $table
	 *   The table it describes.
	 * @param int $rowCount
	 *   Rows at the time of the reading.
	 * @param string|null $maxKey
	 *   Highest primary key seen, or NULL when the table has no single-column key.
	 * @param int|null $maxChanged
	 *   Highest value of a changed-timestamp column, or NULL when the table has none.
	 * @param string $digest
	 *   Digest over a bounded sample of rows, or an empty string when none was taken.
	 * @param int $observed
	 *   Unix timestamp of the reading.
	 * @param int $sampled
	 *   How many rows the digest covered.
	 */
	public function __construct(
		public readonly string $table,
		public readonly int $rowCount = 0,
		public readonly ?string $maxKey = null,
		public readonly ?int $maxChanged = null,
		public readonly string $digest = '',
		public readonly int $observed = 0,
		public readonly int $sampled = 0,
	) {}

	/**
	 * Whether this reading differs from an earlier one.
	 *
	 * @param Watermark $earlier
	 *   The stored reading.
	 *
	 * @return bool
	 *   TRUE when any measurement moved.
	 */
	public function differsFrom(Watermark $earlier): bool
	{
		return $this->changesFrom($earlier) !== [];
	}

	/**
	 * Which measurements moved, and how.
	 *
	 * Named rather than counted, because "the row count is unchanged but the digest moved" and "four
	 * hundred rows appeared" call for different responses and a boolean cannot tell them apart.
	 *
	 * @param Watermark $earlier
	 *   The stored reading.
	 *
	 * @return array<string, string>
	 *   Measurement name keyed to a description of the change.
	 */
	public function changesFrom(Watermark $earlier): array
	{
		$changes = [];

		if ($this->rowCount !== $earlier->rowCount) {
			$changes['rows'] = sprintf('%d to %d', $earlier->rowCount, $this->rowCount);
		}
		if ($this->maxKey !== $earlier->maxKey) {
			$changes['key'] = sprintf(
				'%s to %s',
				$earlier->maxKey ?? 'none',
				$this->maxKey ?? 'none',
			);
		}
		if ($this->maxChanged !== $earlier->maxChanged) {
			$changes['changed'] = sprintf(
				'%s to %s',
				$earlier->maxChanged === null ? 'none' : (string) $earlier->maxChanged,
				$this->maxChanged === null ? 'none' : (string) $this->maxChanged,
			);
		}

		// an empty digest means none was taken, which is not evidence either way
		if ($this->digest !== '' && $earlier->digest !== '' && $this->digest !== $earlier->digest) {
			$changes['contents'] = sprintf('%d sampled rows differ', $this->sampled);
		}

		return $changes;
	}

	/**
	 * A one-line description of what moved.
	 *
	 * @param Watermark $earlier
	 *   The stored reading.
	 *
	 * @return string
	 *   Something such as "rows 40 to 41, key 40 to 41".
	 */
	public function describe(Watermark $earlier): string
	{
		$parts = [];

		foreach ($this->changesFrom($earlier) as $measurement => $change) {
			$parts[] = sprintf('%s %s', $measurement, $change);
		}

		return $parts === [] ? 'unchanged' : implode(', ', $parts);
	}

	/**
	 * Whether this reading measured anything at all.
	 *
	 * A table with no rows, no key, no timestamp column and no sample is indistinguishable from a
	 * table the reader could not inspect, so the two are not treated as equal.
	 *
	 * @return bool
	 *   TRUE when at least one measurement was taken.
	 */
	public function isMeasured(): bool
	{
		return $this->observed > 0;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The watermark as a plain array.
	 */
	public function jsonSerialize(): array
	{
		return [
			'table' => $this->table,
			'rowCount' => $this->rowCount,
			'maxKey' => $this->maxKey,
			'maxChanged' => $this->maxChanged,
			'digest' => $this->digest,
			'observed' => $this->observed,
			'sampled' => $this->sampled,
		];
	}

	/**
	 * Rebuilds a watermark from a stored row.
	 *
	 * @param array<string, mixed> $row
	 *   A row from `strata_watermark`.
	 *
	 * @return self
	 *   The watermark.
	 */
	public static function fromRow(array $row): self
	{
		return new self(
			(string) ($row['table_name'] ?? ''),
			(int) ($row['row_count'] ?? 0),
			($row['max_key'] ?? null) === null ? null : (string) $row['max_key'],
			($row['max_changed'] ?? null) === null ? null : (int) $row['max_changed'],
			(string) ($row['digest'] ?? ''),
			(int) ($row['checked'] ?? 0),
		);
	}
}
