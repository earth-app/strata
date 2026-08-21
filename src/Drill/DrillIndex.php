<?php

declare(strict_types=1);

namespace Drupal\strata\Drill;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * Where drill results are kept, so a pass or a failure is a trend rather than a moment.
 *
 * One drill answering "yes" is weak evidence; the same drill answering "yes" every night for a month
 * and then "no" is what an operator can act on. This is a local table because it is a record of what
 * this site measured about its own store, and unlike everything under `commits/` it cannot be
 * rebuilt from the bucket. An uninstall exports it alongside the restore audit.
 *
 * Only the counts and the verdict are kept, never the subject lists. A drill of every subject on a
 * large site would put a hundred thousand paths in one row, and the paths are only useful while the
 * failure is being investigated - which is what the report the drill just returned is for.
 *
 * @see DrillReport
 * @see DrillRunner
 */
final class DrillIndex
{
	/**
	 * The table this index lives in.
	 */
	public const TABLE = 'strata_drill';

	/**
	 * Constructs an index.
	 *
	 * @param Connection $database
	 *   The database.
	 */
	public function __construct(private readonly Connection $database) {}

	/**
	 * Records one drill.
	 *
	 * @param DrillReport $report
	 *   What it found.
	 *
	 * @return int
	 *   The row id.
	 */
	public function record(DrillReport $report): int
	{
		return (int) $this->database
			->insert(self::TABLE)
			->fields([
				'target' => $report->target,
				'mode' => $report->mode,
				'verdict' => $report->verdict(),
				'matched' => count($report->matched),
				'differed' => count($report->differed),
				'skipped' => count($report->skipped),
				'unreadable' => count($report->unreadable),
				'sampled' => $report->sampled,
				'population' => $report->population,
				'seconds' => (int) round($report->seconds * 1000),
				'created' => $report->ranAt,
				'detail' => mb_substr($report->summary(), 0, 500),
			])
			->execute();
	}

	/**
	 * The most recent drills, newest first.
	 *
	 * @param int $limit
	 *   Most rows to return.
	 *
	 * @return list<array<string, mixed>>
	 *   The rows.
	 */
	public function recent(int $limit = 30): array
	{
		return $this->database
			->select(self::TABLE, 'd')
			->fields('d')
			->orderBy('created', 'DESC')
			->orderBy('id', 'DESC')
			->range(0, max(1, $limit))
			->execute()
			?->fetchAll(FetchAs::Associative) ?? [];
	}

	/**
	 * The newest drill.
	 *
	 * @return array<string, mixed>|null
	 *   The row, or NULL when no drill has run.
	 */
	public function newest(): ?array
	{
		$rows = $this->recent(1);

		return $rows === [] ? null : $rows[0];
	}

	/**
	 * When the last drill ran.
	 *
	 * @return int
	 *   Unix seconds, or 0 when none has.
	 */
	public function lastRunAt(): int
	{
		$newest = $this->newest();

		return $newest === null ? 0 : (int) ($newest['created'] ?? 0);
	}

	/**
	 * How many of the last drills passed.
	 *
	 * @param int $limit
	 *   How many drills to look back over.
	 *
	 * @return array{passed: int, failed: int, inconclusive: int, refused: int}
	 *   Counts by verdict.
	 */
	public function verdicts(int $limit = 30): array
	{
		$counts = ['passed' => 0, 'failed' => 0, 'inconclusive' => 0, 'refused' => 0];

		foreach ($this->recent($limit) as $row) {
			$verdict = (string) ($row['verdict'] ?? '');
			$key = match ($verdict) {
				'pass' => 'passed',
				'fail' => 'failed',
				'refused' => 'refused',
				default => 'inconclusive',
			};

			$counts[$key]++;
		}

		return $counts;
	}

	/**
	 * Removes drills older than a moment.
	 *
	 * @param int $olderThan
	 *   Unix seconds.
	 *
	 * @return int
	 *   How many rows were removed.
	 */
	public function purge(int $olderThan): int
	{
		return (int) $this->database
			->delete(self::TABLE)
			->condition('created', $olderThan, '<')
			->execute();
	}

	/**
	 * Drops every row.
	 *
	 * @return int
	 *   How many rows were removed.
	 */
	public function clear(): int
	{
		return (int) $this->database->delete(self::TABLE)->execute();
	}
}
