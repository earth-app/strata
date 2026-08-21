<?php

declare(strict_types=1);

namespace Drupal\strata\Timeline;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\strata\Restore\RestoreAudit;
use Drupal\strata\Tree\CommitIndex;

/**
 * Turns the commit index into the buckets a timeline draws.
 *
 * The aggregation is done in SQL rather than in PHP. A year of history on a busy site is hundreds of
 * thousands of commits, and loading them to add up seven columns would cost more memory than the
 * page has - so the resolution is turned into an integer division inside the query and the database
 * returns one row per bar.
 *
 * **Empty buckets are filled in.** A query returns only the buckets that have commits, and a chart
 * drawn from those alone shows a continuous line across a period when nothing happened, which reads
 * as steady activity rather than as silence. So every bucket the window defines is present, and the
 * quiet ones are explicitly empty.
 *
 * Restores are counted alongside commits because they belong on the same axis: an operator looking at
 * a spike wants to know whether a rollback caused it, and putting the two on separate charts makes
 * that a comparison of x-axes by eye.
 *
 * @see TimelineWindow
 * @see TimelineBucket
 */
final class TimelineQuery
{
	/**
	 * Constructs a query.
	 *
	 * @param Connection $database
	 *   The database the indexes live in.
	 * @param CommitIndex $commits
	 *   Reads individual commits for a drilldown.
	 */
	public function __construct(
		private readonly Connection $database,
		private readonly CommitIndex $commits,
	) {}

	/**
	 * The buckets a window covers, oldest first.
	 *
	 * @param TimelineWindow $window
	 *   The window.
	 *
	 * @return list<TimelineBucket>
	 *   One bucket per boundary the window defines.
	 */
	public function buckets(TimelineWindow $window): array
	{
		$aggregated = $this->aggregate($window);
		$restores = $this->restores($window);
		$ids = $this->identifiers($window);
		$width = $window->resolution * TimelineWindow::MICROS_PER_SECOND;
		$buckets = [];

		foreach ($window->boundaries() as $start) {
			$row = $aggregated[$start] ?? null;

			$buckets[] = new TimelineBucket(
				$start,
				$start + $width - 1,
				(int) ($row['commits'] ?? 0),
				(int) ($row['operations'] ?? 0),
				(int) ($row['raw_bytes'] ?? 0),
				(int) ($row['stored_bytes'] ?? 0),
				(int) ($row['anchors'] ?? 0),
				$ids[$start] ?? [],
				$restores[$start] ?? 0,
			);
		}

		return $buckets;
	}

	/**
	 * The span the whole history covers.
	 *
	 * What a timeline opens on when the caller has no window in mind.
	 *
	 * @param int $fallbackNow
	 *   The unix second to use when there is no history.
	 *
	 * @return TimelineWindow
	 *   A window over every commit, or over the last hour when there are none.
	 */
	public function fullHistory(int $fallbackNow): TimelineWindow
	{
		$query = $this->database->select(CommitIndex::TABLE, 'c');
		$query->addExpression('MIN([c].[microtime])', 'oldest');
		$query->addExpression('MAX([c].[microtime])', 'newest');

		$row = $query->execute()?->fetch(FetchAs::Associative) ?: [];
		$oldest = (int) ($row['oldest'] ?? 0);
		$newest = (int) ($row['newest'] ?? 0);

		if ($oldest === 0 || $newest === 0) {
			return TimelineWindow::lastSeconds(3_600, $fallbackNow);
		}

		return TimelineWindow::spanning($oldest, $newest);
	}

	/**
	 * The commits inside one bucket, newest first.
	 *
	 * @param TimelineBucket $bucket
	 *   The bucket.
	 * @param int $limit
	 *   Most rows to return.
	 *
	 * @return list<array<string, mixed>>
	 *   Commit index rows.
	 */
	public function commitsIn(TimelineBucket $bucket, int $limit = 100): array
	{
		return $this->commits->between($bucket->startMicrotime, $bucket->endMicrotime, $limit);
	}

	/**
	 * Totals across a whole window, without bucketing.
	 *
	 * @param TimelineWindow $window
	 *   The window.
	 *
	 * @return array{commits: int, operations: int, raw_bytes: int, stored_bytes: int, anchors: int}
	 *   The totals.
	 */
	public function totals(TimelineWindow $window): array
	{
		$query = $this->database->select(CommitIndex::TABLE, 'c');
		$query->condition('c.microtime', $window->fromMicrotime, '>=');
		$query->condition('c.microtime', $window->toMicrotime, '<=');
		$query->addExpression('COUNT(*)', 'commits');
		$query->addExpression('COALESCE(SUM([c].[operations]), 0)', 'operations');
		$query->addExpression('COALESCE(SUM([c].[raw_bytes]), 0)', 'raw_bytes');
		$query->addExpression('COALESCE(SUM([c].[stored_bytes]), 0)', 'stored_bytes');
		$query->addExpression('COALESCE(SUM([c].[is_base]), 0)', 'anchors');

		$row = $query->execute()?->fetch(FetchAs::Associative) ?: [];

		return [
			'commits' => (int) ($row['commits'] ?? 0),
			'operations' => (int) ($row['operations'] ?? 0),
			'raw_bytes' => (int) ($row['raw_bytes'] ?? 0),
			'stored_bytes' => (int) ($row['stored_bytes'] ?? 0),
			'anchors' => (int) ($row['anchors'] ?? 0),
		];
	}

	/**
	 * Raw bytes every indexed commit says it captured.
	 *
	 * Over the whole history rather than a window, because it is the numerator of a store-wide
	 * deduplication ratio and the denominator is a whole-store figure too.
	 *
	 * @return int
	 *   Bytes.
	 */
	public function capturedRawBytes(): int
	{
		$query = $this->database->select(CommitIndex::TABLE, 'c');
		$query->addExpression('COALESCE(SUM([c].[raw_bytes]), 0)', 'raw_bytes');

		$row = $query->execute()?->fetch(FetchAs::Associative) ?: [];

		return (int) ($row['raw_bytes'] ?? 0);
	}

	#region Aggregation

	/**
	 * Commit totals per bucket.
	 *
	 * @param TimelineWindow $window
	 *   The window.
	 *
	 * @return array<int, array<string, mixed>>
	 *   Bucket start keyed to its totals.
	 */
	private function aggregate(TimelineWindow $window): array
	{
		$width = $window->resolution * TimelineWindow::MICROS_PER_SECOND;
		$query = $this->database->select(CommitIndex::TABLE, 'c');
		$query->condition('c.microtime', $window->fromMicrotime, '>=');
		$query->condition('c.microtime', $window->toMicrotime, '<=');
		// modulo rather than division: `/` is integer division on sqlite and postgres and decimal on
		// mysql, and `%` means the same thing on all three
		$query->addExpression('([c].[microtime] - ([c].[microtime] % :strata_width))', 'bucket', [
			':strata_width' => $width,
		]);
		$query->addExpression('COUNT(*)', 'commits');
		$query->addExpression('COALESCE(SUM([c].[operations]), 0)', 'operations');
		$query->addExpression('COALESCE(SUM([c].[raw_bytes]), 0)', 'raw_bytes');
		$query->addExpression('COALESCE(SUM([c].[stored_bytes]), 0)', 'stored_bytes');
		$query->addExpression('COALESCE(SUM([c].[is_base]), 0)', 'anchors');
		$query->groupBy('bucket');

		$rows = $query->execute()?->fetchAll(FetchAs::Associative) ?? [];
		$buckets = [];

		foreach ($rows as $row) {
			$buckets[(int) $row['bucket']] = $row;
		}

		return $buckets;
	}

	/**
	 * How many restores ran in each bucket.
	 *
	 * @param TimelineWindow $window
	 *   The window.
	 *
	 * @return array<int, int>
	 *   Bucket start keyed to a count.
	 */
	private function restores(TimelineWindow $window): array
	{
		$from = intdiv($window->fromMicrotime, TimelineWindow::MICROS_PER_SECOND);
		$to = intdiv($window->toMicrotime, TimelineWindow::MICROS_PER_SECOND);
		$rows =
			$this->database
				->select(RestoreAudit::TABLE, 'r')
				->fields('r', ['started'])
				->condition('started', $from, '>=')
				->condition('started', $to, '<=')
				->execute()
				?->fetchCol() ?? [];

		$counts = [];

		foreach ($rows as $started) {
			$bucket = $window->bucketFor((int) $started * TimelineWindow::MICROS_PER_SECOND);
			$counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
		}

		return $counts;
	}

	/**
	 * A bounded list of commit ids per bucket.
	 *
	 * Read as one ordered pass rather than one query per bucket: a window can hold hundreds of
	 * buckets, and a query each would be hundreds of round trips for a chart.
	 *
	 * @param TimelineWindow $window
	 *   The window.
	 *
	 * @return array<int, list<string>>
	 *   Bucket start keyed to commit ids, newest first.
	 */
	private function identifiers(TimelineWindow $window): array
	{
		$rows =
			$this->database
				->select(CommitIndex::TABLE, 'c')
				->fields('c', ['id', 'microtime'])
				->condition('microtime', $window->fromMicrotime, '>=')
				->condition('microtime', $window->toMicrotime, '<=')
				->orderBy('microtime', 'DESC')
				->range(0, TimelineWindow::MAX_BUCKETS * TimelineBucket::MAX_IDS)
				->execute()
				?->fetchAll(FetchAs::Associative) ?? [];

		$ids = [];

		foreach ($rows as $row) {
			$bucket = $window->bucketFor((int) $row['microtime']);

			if (count($ids[$bucket] ?? []) >= TimelineBucket::MAX_IDS) {
				continue;
			}

			$ids[$bucket][] = (string) $row['id'];
		}

		return $ids;
	}

	#endregion
}
