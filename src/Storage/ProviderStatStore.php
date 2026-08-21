<?php

declare(strict_types=1);

namespace Drupal\strata\Storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * Keeps what each store was asked to do, accumulated per day.
 *
 * A ProviderStats is an accumulator for one request; this is the record. Without it every reading
 * the budget guard, the cost report and the telemetry pass can take describes only the process that
 * is asking, so a site that flushes in a queue worker and reports in a web request can never see
 * its own bill.
 *
 * One row per provider, day and operation. A day is a whole number of days since the epoch at
 * midnight UTC, which keeps a row addressable without a date function and makes the primary key do
 * the accumulating: a second flush on the same day adds to the row the first one wrote.
 *
 * Durations are stored in whole milliseconds. A float column would carry more precision than an
 * HTTP round trip has, and an integer sums exactly however many times it is added to.
 *
 * Nothing here is authoritative and nothing else reads it back to work: it is an observation of
 * traffic that already happened, which is why an uninstall dropping the table costs nothing but the
 * history of the bill.
 *
 * @see ProviderStats
 * @see RecordingProvider
 */
final class ProviderStatStore
{
	/**
	 * The table this store lives in.
	 */
	public const TABLE = 'strata_provider_stat';

	/**
	 * Seconds in a day.
	 */
	private const DAY = 86_400;

	/**
	 * Constructs a store.
	 *
	 * @param Connection $database
	 *   The database.
	 * @param TimeInterface $time
	 *   The clock, so a test can place a reading on a chosen day.
	 */
	public function __construct(
		private readonly Connection $database,
		private readonly TimeInterface $time,
	) {}

	#region Recording

	/**
	 * Folds one window into the rows for its day.
	 *
	 * Idempotent in shape rather than in effect: calling it twice with the same window counts that
	 * window twice, which is correct, because a window is a set of requests that were made. A caller
	 * that persists an accumulator is expected to reset it, and ProviderStats::reset() exists for
	 * exactly that.
	 *
	 * @param string $provider
	 *   The provider id the window belongs to.
	 * @param ProviderStats $stats
	 *   The window. Left unchanged.
	 * @param int|null $at
	 *   Unix timestamp the window belongs to. NULL uses the request time.
	 *
	 * @return int
	 *   How many operation rows were written or updated. Zero when the window recorded nothing.
	 */
	public function record(string $provider, ProviderStats $stats, ?int $at = null): int
	{
		$breakdown = $stats->byOperation();

		if ($provider === '' || $breakdown === []) {
			return 0;
		}

		$day = self::day($at ?? $this->time->getRequestTime());
		$written = 0;

		foreach ($breakdown as $operation => $figures) {
			$milliseconds = self::milliseconds($stats->secondsFor($operation));
			$slowest = self::milliseconds($stats->slowestOf($operation));

			$this->database
				->merge(self::TABLE)
				->keys([
					'provider' => mb_substr($provider, 0, 64),
					'day' => $day,
					'operation' => $operation,
				])
				->fields([
					'requests' => $figures['count'],
					'failures' => $figures['failures'],
					'bytes' => $figures['bytes'],
					'seconds' => $milliseconds,
					'slowest' => $slowest,
				])
				->expression('requests', 'requests + :requests', [':requests' => $figures['count']])
				->expression('failures', 'failures + :failures', [
					':failures' => $figures['failures'],
				])
				->expression('bytes', 'bytes + :bytes', [':bytes' => $figures['bytes']])
				->expression('seconds', 'seconds + :seconds', [':seconds' => $milliseconds])
				// no GREATEST(): SQLite has no such function and its MAX() takes one argument here
				->expression('slowest', 'CASE WHEN slowest > :peak THEN slowest ELSE :peak END', [
					':peak' => $slowest,
				])
				->execute();

			$written++;
		}

		return $written;
	}

	/**
	 * Drops every row for days that ended before a moment.
	 *
	 * @param int $before
	 *   Unix timestamp. The day this falls in is kept; earlier days go.
	 *
	 * @return int
	 *   How many rows were removed.
	 */
	public function prune(int $before): int
	{
		return (int) $this->database
			->delete(self::TABLE)
			->condition('day', self::day($before), '<')
			->execute();
	}

	#endregion

	#region Reading

	/**
	 * Everything recorded over a window, as one set of figures.
	 *
	 * @param int $from
	 *   Unix timestamp to read from. Zero reads from the first row.
	 * @param int $to
	 *   Unix timestamp to read to. Zero reads to the last row.
	 *
	 * @return array{requests: int, failures: int, bytes: int, seconds: float, classA: int, classB: int}
	 *   All zeroes when nothing was recorded in the window.
	 */
	public function total(int $from = 0, int $to = 0): array
	{
		$totals = [
			'requests' => 0,
			'failures' => 0,
			'bytes' => 0,
			'seconds' => 0.0,
			'classA' => 0,
			'classB' => 0,
		];

		foreach ($this->byOperation($from, $to) as $operation => $figures) {
			$totals['requests'] += $figures['requests'];
			$totals['failures'] += $figures['failures'];
			$totals['bytes'] += $figures['bytes'];
			$totals['seconds'] += $figures['seconds'];

			$class = in_array($operation, ProviderStats::CLASS_A, true) ? 'classA' : 'classB';
			$totals[$class] += $figures['requests'];
		}

		return $totals;
	}

	/**
	 * Per-operation figures over a window, summed across every provider.
	 *
	 * Operations with no rows are left out, so a table rendered from this shows the verbs the store
	 * was actually asked for. Order follows ProviderStats::OPERATIONS.
	 *
	 * @param int $from
	 *   Unix timestamp to read from. Zero reads from the first row.
	 * @param int $to
	 *   Unix timestamp to read to. Zero reads to the last row.
	 *
	 * @return array<string, array{requests: int, failures: int, bytes: int, seconds: float, slowest: float}>
	 *   Keyed by operation.
	 */
	public function byOperation(int $from = 0, int $to = 0): array
	{
		$rows = $this->aggregate('operation', $from, $to);
		$ordered = [];

		foreach (ProviderStats::OPERATIONS as $operation) {
			if (isset($rows[$operation])) {
				$ordered[$operation] = $rows[$operation];
			}
		}

		// a verb no longer in OPERATIONS can still be on disk from an older release
		foreach ($rows as $operation => $figures) {
			$ordered[$operation] ??= $figures;
		}

		return $ordered;
	}

	/**
	 * Per-provider figures over a window, summed across every operation.
	 *
	 * @param int $from
	 *   Unix timestamp to read from. Zero reads from the first row.
	 * @param int $to
	 *   Unix timestamp to read to. Zero reads to the last row.
	 *
	 * @return array<string, array{requests: int, failures: int, bytes: int, seconds: float, slowest: float}>
	 *   Keyed by provider id, busiest first.
	 */
	public function byProvider(int $from = 0, int $to = 0): array
	{
		$rows = $this->aggregate('provider', $from, $to);

		uasort($rows, static fn(array $a, array $b): int => $b['requests'] <=> $a['requests']);

		return $rows;
	}

	/**
	 * Daily totals over a window, oldest first.
	 *
	 * A day with no traffic has no row, so a graph drawn from this joins the points it has rather
	 * than reading a gap as a zero.
	 *
	 * @param int $from
	 *   Unix timestamp to read from. Zero reads from the first row.
	 * @param int $to
	 *   Unix timestamp to read to. Zero reads to the last row.
	 *
	 * @return list<array{day: int, requests: int, failures: int, bytes: int, seconds: float, slowest: float}>
	 *   One entry per day that has traffic.
	 */
	public function daily(int $from = 0, int $to = 0): array
	{
		$rows = $this->aggregate('day', $from, $to);
		$days = [];

		foreach ($rows as $day => $figures) {
			$days[] = ['day' => (int) $day] + $figures;
		}

		usort($days, static fn(array $a, array $b): int => $a['day'] <=> $b['day']);

		return $days;
	}

	/**
	 * Every provider that has a row.
	 *
	 * @return list<string>
	 *   Provider ids, alphabetically.
	 */
	public function providers(): array
	{
		$ids = $this->database
			->select(self::TABLE, 's')
			->distinct()
			->fields('s', ['provider'])
			->orderBy('provider')
			->execute()
			?->fetchCol();

		if (!is_array($ids)) {
			return [];
		}

		return array_values(array_map(static fn(mixed $id): string => (string) $id, $ids));
	}

	/**
	 * How many rows are held.
	 *
	 * @return int
	 *   The row count.
	 */
	public function count(): int
	{
		return (int) $this->database
			->select(self::TABLE, 's')
			->countQuery()
			->execute()
			?->fetchField();
	}

	#endregion

	/**
	 * Midnight UTC of the day a moment falls in.
	 *
	 * @param int $timestamp
	 *   A Unix timestamp. A negative one is treated as the epoch.
	 *
	 * @return int
	 *   Unix timestamp of that midnight.
	 */
	public static function day(int $timestamp): int
	{
		return $timestamp <= 0 ? 0 : intdiv($timestamp, self::DAY) * self::DAY;
	}

	/**
	 * Sums the figure columns grouped by one column.
	 *
	 * @param string $column
	 *   One of `provider`, `day` or `operation`.
	 * @param int $from
	 *   Unix timestamp to read from. Zero reads from the first row.
	 * @param int $to
	 *   Unix timestamp to read to. Zero reads to the last row.
	 *
	 * @return array<string, array{requests: int, failures: int, bytes: int, seconds: float, slowest: float}>
	 *   Keyed by the grouping column's value.
	 */
	private function aggregate(string $column, int $from, int $to): array
	{
		$select = $this->database->select(self::TABLE, 's');
		$select->fields('s', [$column]);
		$select->addExpression('SUM([s].[requests])', 'requests');
		$select->addExpression('SUM([s].[failures])', 'failures');
		$select->addExpression('SUM([s].[bytes])', 'bytes');
		$select->addExpression('SUM([s].[seconds])', 'seconds');
		$select->addExpression('MAX([s].[slowest])', 'slowest');
		$select->groupBy('s.' . $column);

		if ($from > 0) {
			$select->condition('day', self::day($from), '>=');
		}
		if ($to > 0) {
			$select->condition('day', self::day($to), '<=');
		}

		$rows = $select->execute()?->fetchAll(FetchAs::Associative) ?? [];
		$grouped = [];

		foreach ($rows as $row) {
			$grouped[(string) $row[$column]] = [
				'requests' => (int) $row['requests'],
				'failures' => (int) $row['failures'],
				'bytes' => (int) $row['bytes'],
				'seconds' => (float) $row['seconds'] / 1000,
				'slowest' => (float) $row['slowest'] / 1000,
			];
		}

		return $grouped;
	}

	/**
	 * Whole milliseconds, rounded to nearest and never negative.
	 *
	 * @param float $seconds
	 *   A duration.
	 *
	 * @return int
	 *   Milliseconds.
	 */
	private static function milliseconds(float $seconds): int
	{
		return max(0, (int) round($seconds * 1000));
	}
}
