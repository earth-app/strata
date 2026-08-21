<?php

declare(strict_types=1);

namespace Drupal\strata\Capture;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Health\HealthLedgerInterface;
use Drupal\strata\Health\Tripwire\WatermarkDrift;
use Drupal\strata\Health\TripwireRegistry;
use Drupal\strata\Journal\JournalInterface;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Proves capture is complete, and closes the gap when it is not.
 *
 * Every other part of the capture path records a change it was told about. This one goes looking
 * for changes nobody told anyone about: a module writing straight to its own table, a query that
 * ran before the statement tap was enabled, a migration executed outside Drupal entirely. From
 * inside the capture path all three are invisible, and a backup that cannot detect its own gaps is
 * a backup nobody should trust.
 *
 * The method is a bounded fingerprint per table, compared against the last one stored. A table
 * whose fingerprint moved while the journal and the commit log hold no operation for it has
 * drifted, which raises `watermark.drift` and, when asked, captures the table's current rows so the
 * gap closes.
 *
 * **What this covers and what it does not.** Row count, highest key and highest changed-timestamp
 * are exact. The sampled digest is a sample: a change confined to rows outside the sample is not
 * seen. So a clean pass means "no drift detected over what was measured", which is why the finding
 * is a warning that names the table rather than a silent repair, and why the sample size is a
 * setting. A table with no single-column primary key gets count and digest only.
 *
 * @see Watermark
 * @see WatermarkDrift
 */
final class Reconciler
{
	/**
	 * The table watermarks are stored in.
	 */
	public const TABLE = 'strata_watermark';

	/**
	 * How many rows the sampled digest covers by default.
	 *
	 * A bound on the cost of a pass rather than a statistical choice. At 200 rows over a few hundred
	 * tables a pass reads tens of thousands of rows, which is a fraction of one page render.
	 */
	public const DEFAULT_SAMPLE = 200;

	/**
	 * Column names treated as a changed timestamp, in order of preference.
	 */
	public const CHANGED_COLUMNS = ['changed', 'updated', 'timestamp', 'created'];

	/**
	 * Most rows to capture from one drifted table in one pass.
	 *
	 * Closing a gap must not become the thing that blows the cron window. A table with more drifted
	 * rows than this keeps its finding open and is picked up again next pass.
	 */
	public const CAPTURE_LIMIT = 500;

	/**
	 * How much of the journal is scanned when asking whether a table was captured.
	 *
	 * A pass runs after a flush, so the journal holds at most one window. This is a bound in case it
	 * does not: a backlog deeper than this makes the answer "not captured", which errs toward
	 * reporting drift that turns out to be accounted for rather than missing drift that is real.
	 */
	public const JOURNAL_SCAN = 10000;

	/**
	 * Constructs a reconciler.
	 *
	 * @param Connection $database
	 *   The connection to inspect.
	 * @param JournalInterface $journal
	 *   Where captured rows are appended.
	 * @param CaptureScope $scope
	 *   Decides which tables are in scope.
	 * @param TripwireRegistry $tripwires
	 *   Runs the drift check over each observation.
	 * @param HealthLedgerInterface $ledger
	 *   Where drift is recorded.
	 * @param LoggerInterface $logger
	 *   Records what a pass did.
	 * @param int $sampleSize
	 *   How many rows the digest covers.
	 */
	public function __construct(
		private readonly Connection $database,
		private readonly JournalInterface $journal,
		private readonly CaptureScope $scope,
		private readonly TripwireRegistry $tripwires,
		private readonly HealthLedgerInterface $ledger,
		private readonly LoggerInterface $logger,
		private readonly int $sampleSize = self::DEFAULT_SAMPLE,
	) {}

	#region Passes

	/**
	 * Compares every captured table against its stored watermark.
	 *
	 * @param list<string> $tables
	 *   Tables to examine, or an empty list for every captured table.
	 * @param int $limit
	 *   Most tables to examine in this pass.
	 * @param bool $capture
	 *   TRUE to capture the rows of a drifted table, closing the gap. FALSE reports the drift and
	 *   leaves it, which is what a dry run and a status page want.
	 *
	 * @return ReconcileReport
	 *   What the pass found.
	 */
	public function reconcile(
		array $tables = [],
		int $limit = 100,
		bool $capture = true,
	): ReconcileReport {
		$started = microtime(true);
		$candidates = $tables === [] ? $this->tables() : array_values($tables);
		$complete = count($candidates) <= $limit;
		$candidates = array_slice($candidates, 0, max(0, $limit));

		$examined = 0;
		$changed = 0;
		$drifted = 0;
		$captured = 0;
		$drift = [];
		$problems = [];

		foreach ($candidates as $table) {
			try {
				$current = $this->observe($table);
			} catch (Throwable $error) {
				$problems[] = sprintf('%s: %s', $table, $error->getMessage());

				continue;
			}

			$examined++;
			$stored = $this->stored($table);
			$this->store($current);

			if ($stored === null || !$current->differsFrom($stored)) {
				continue;
			}

			$changed++;

			if ($this->wasCaptured($table, $stored->observed)) {
				continue;
			}

			$drifted++;
			$drift[$table] = $current->describe($stored);
			$this->raise($table, $drift[$table]);

			if ($capture) {
				$captured += $this->captureRows($table, $stored, $problems);
			}
		}

		$report = new ReconcileReport(
			$examined,
			$changed,
			$drifted,
			$captured,
			$drift,
			$problems,
			microtime(true) - $started,
			$complete,
		);

		$this->logger->info('Strata %summary', ['%summary' => $report->summary()]);

		return $report;
	}

	/**
	 * Tables the reconciler watches.
	 *
	 * Every table the connection holds that capture covers. Strata's own tables are excluded by the
	 * scope, and so is anything the site has told it to leave alone.
	 *
	 * @return list<string>
	 *   Table names, in a stable order so a bounded pass covers them evenly over time.
	 */
	public function tables(): array
	{
		$tables = array_values($this->database->schema()->findTables('%'));
		sort($tables);

		return array_values(
			array_filter($tables, fn(string $table): bool => $this->scope->coversTable($table)),
		);
	}

	#endregion

	#region Measuring

	/**
	 * Takes a fresh reading of one table.
	 *
	 * @param string $table
	 *   The table name.
	 *
	 * @return Watermark
	 *   The reading.
	 *
	 * @throws DatabaseExceptionWrapper
	 *   When the table cannot be read, which the caller reports rather than swallows.
	 */
	public function observe(string $table): Watermark
	{
		$columns = $this->columns($table);
		$order = $this->orderColumn($columns);
		$changed = $this->changedColumn($columns);

		$count = (int) $this->database->select($table, 't')->countQuery()->execute()?->fetchField();

		$highestChanged = $changed === null ? null : $this->highest($table, $changed);

		return new Watermark(
			$table,
			$count,
			$order === null ? null : $this->highest($table, $order),
			$highestChanged === null ? null : (int) $highestChanged,
			$this->digest($table, $order, $columns),
			time(),
			min($count, $this->sampleSize),
		);
	}

	/**
	 * The stored watermark for a table.
	 *
	 * @param string $table
	 *   The table name.
	 *
	 * @return Watermark|null
	 *   The stored reading, or NULL when the table has never been read.
	 */
	public function stored(string $table): ?Watermark
	{
		$row = $this->database
			->select(self::TABLE, 'w')
			->fields('w')
			->condition('table_name', $table)
			->execute()
			?->fetchAssoc();

		return $row === false || $row === null ? null : Watermark::fromRow($row);
	}

	/**
	 * Records a reading, replacing any earlier one.
	 *
	 * @param Watermark $watermark
	 *   The reading.
	 */
	public function store(Watermark $watermark): void
	{
		$this->database
			->merge(self::TABLE)
			->key('table_name', $watermark->table)
			->fields([
				'row_count' => $watermark->rowCount,
				'max_key' => $watermark->maxKey,
				'max_changed' => $watermark->maxChanged,
				'digest' => $watermark->digest === '' ? null : $watermark->digest,
				'checked' => $watermark->observed,
			])
			->execute();
	}

	/**
	 * Forgets every stored reading.
	 *
	 * Used after a restore, which moves every table at once and would otherwise report the whole
	 * schema as drifted on the next pass.
	 *
	 * @return int
	 *   How many readings were removed.
	 */
	public function forget(): int
	{
		return (int) $this->database->delete(self::TABLE)->execute();
	}

	/**
	 * The highest value in a column.
	 *
	 * @param string $table
	 *   The table name.
	 * @param string $column
	 *   The column.
	 *
	 * @return string|null
	 *   The value as a string, or NULL when the table is empty.
	 */
	private function highest(string $table, string $column): ?string
	{
		$query = $this->database->select($table, 't');
		$query->addExpression('MAX([t].[' . $column . '])', 'highest');

		$value = $query->execute()?->fetchField();

		return $value === false || $value === null ? null : (string) $value;
	}

	/**
	 * A digest over a bounded sample of rows.
	 *
	 * Ordered by the primary key when there is one, so two readings sample the same rows and a
	 * difference means the contents moved rather than the order did. Without a key the order is
	 * whatever the database gives, so the digest is only meaningful for a table small enough that the
	 * sample covers all of it.
	 *
	 * @param string $table
	 *   The table name.
	 * @param string|null $key
	 *   The primary key column, or NULL.
	 * @param list<string> $columns
	 *   The table's columns.
	 *
	 * @return string
	 *   The digest, or an empty string when no sample could be taken.
	 */
	private function digest(string $table, ?string $key, array $columns): string
	{
		if ($columns === []) {
			return '';
		}

		$query = $this->database
			->select($table, 't')
			->fields('t', $columns)
			->range(0, $this->sampleSize);

		if ($key !== null) {
			$query->orderBy('t.' . $key);
		}

		$rows = $query->execute()?->fetchAll(FetchAs::Associative) ?? [];

		return $rows === [] ? '' : Hash::of((string) json_encode($rows));
	}

	/**
	 * A table's columns, in a stable order.
	 *
	 * @param string $table
	 *   The table name.
	 *
	 * @return list<string>
	 *   Column names, sorted so the digest does not change with the schema's reported order.
	 */
	private function columns(string $table): array
	{
		// Schema::findPrimaryKeyColumns() is protected in core, so a bounded select is what
		// discovers the shape; an empty table reports no columns and gets no digest
		$row = $this->database
			->select($table, 't')
			->fields('t')
			->range(0, 1)
			->execute()
			?->fetchAssoc();

		$names = is_array($row) ? array_map('strval', array_keys($row)) : [];
		sort($names);

		return $names;
	}

	/**
	 * The column a sample is ordered by, so two readings cover the same rows.
	 *
	 * A heuristic, and only ever used for ordering: any column that sorts deterministically makes two
	 * readings comparable, so being wrong about which one is the key costs nothing here. Drupal names
	 * its keys predictably - `id`, or `nid`, `uid`, `fid`, `tid` - which is what the preference order
	 * follows.
	 *
	 * @param list<string> $columns
	 *   The table's columns.
	 *
	 * @return string|null
	 *   The column, or NULL when the table has none.
	 */
	private function orderColumn(array $columns): ?string
	{
		if ($columns === []) {
			return null;
		}
		if (in_array('id', $columns, true)) {
			return 'id';
		}

		$candidates = array_values(
			array_filter($columns, static fn(string $column): bool => str_ends_with($column, 'id')),
		);

		if ($candidates !== []) {
			usort(
				$candidates,
				static fn(string $left, string $right): int => strlen($left) <=> strlen($right) ?:
				strcmp($left, $right),
			);

			return $candidates[0];
		}

		return in_array('name', $columns, true) ? 'name' : $columns[0];
	}

	/**
	 * A single column whose values are unique, when one exists.
	 *
	 * Used only when capturing the rows of a table that has already drifted, because a row needs an
	 * identity before it can be a restorable subject and a guess would give two different rows the
	 * same subject. Verified rather than assumed: one aggregate query per candidate, on a table the
	 * pass has already decided is worth the work.
	 *
	 * @param string $table
	 *   The table name.
	 * @param list<string> $columns
	 *   The table's columns.
	 *
	 * @return string|null
	 *   The column, or NULL when no single column identifies a row.
	 */
	private function identityColumn(string $table, array $columns): ?string
	{
		$order = $this->orderColumn($columns);
		$candidates = $order === null ? $columns : [$order, ...array_diff($columns, [$order])];

		foreach ($candidates as $column) {
			$query = $this->database->select($table, 't');
			$query->addExpression('COUNT(*)', 'rows');
			$query->addExpression('COUNT(DISTINCT [t].[' . $column . '])', 'distinct_values');

			$row = $query->execute()?->fetchAssoc();

			if (!is_array($row)) {
				continue;
			}
			if ((int) $row['rows'] === (int) $row['distinct_values'] && (int) $row['rows'] > 0) {
				return (string) $column;
			}
		}

		return null;
	}

	/**
	 * The column that records when a row last changed, when there is one.
	 *
	 * @param list<string> $columns
	 *   The table's columns.
	 *
	 * @return string|null
	 *   The column, or NULL.
	 */
	private function changedColumn(array $columns): ?string
	{
		foreach (self::CHANGED_COLUMNS as $candidate) {
			if (in_array($candidate, $columns, true)) {
				return $candidate;
			}
		}

		return null;
	}

	#endregion

	#region Closing The Gap

	/**
	 * Whether an operation was captured for a table since a given time.
	 *
	 * Read from the journal, which holds what has been captured and not yet sealed. A window already
	 * sealed into a commit is not consulted: the reconciler runs on cron after a flush, so anything
	 * sealed was seen, and re-reading the commit log per table would cost a request per table.
	 *
	 * @param string $table
	 *   The table name.
	 * @param int $since
	 *   Unix timestamp of the previous reading.
	 *
	 * @return bool
	 *   TRUE when something was captured for this table.
	 */
	private function wasCaptured(string $table, int $since): bool
	{
		$microtime = $since * JournalOp::MICROSECONDS_PER_SECOND;

		foreach ($this->journal->read(self::JOURNAL_SCAN) as $entry) {
			$operation = $entry['operation'];

			if ($operation->microtime < $microtime) {
				continue;
			}
			if ($operation->realm !== Realm::TABLE && $operation->realm !== Realm::SCHEMA) {
				continue;
			}
			if (
				$operation->subject === $table ||
				str_starts_with($operation->subject, $table . ':')
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Captures the rows of a drifted table, so the gap closes.
	 *
	 * Only rows the reading can identify are captured: the table needs a single-column primary key,
	 * because a row with no identity has no subject to be restored into. A table without one keeps its
	 * finding open, which is the honest outcome - the drift is real and this cannot fix it.
	 *
	 * @param string $table
	 *   The table name.
	 * @param Watermark $stored
	 *   The previous reading, used to capture only what moved past it where possible.
	 * @param list<string> $problems
	 *   Collects a line when the capture itself fails.
	 *
	 * @return int
	 *   How many rows were captured.
	 */
	private function captureRows(string $table, Watermark $stored, array &$problems): int
	{
		try {
			$columns = $this->columns($table);
			$key = $this->identityColumn($table, $columns);

			if ($key === null) {
				$problems[] = sprintf(
					'%s drifted but no single column identifies its rows, so they have no subject',
					$table,
				);

				return 0;
			}

			$query = $this->database
				->select($table, 't')
				->fields('t', $columns)
				->orderBy('t.' . $key, 'DESC')
				->range(0, self::CAPTURE_LIMIT);

			$changed = $this->changedColumn($columns);

			// a timestamped table needs only what moved; without one, the newest rows are the guess
			if ($changed !== null && $stored->maxChanged !== null) {
				$query->condition('t.' . $changed, $stored->maxChanged, '>=');
			}

			$rows = $query->execute()?->fetchAll(FetchAs::Associative) ?? [];
			$captured = 0;

			foreach ($rows as $row) {
				$captured += $this->appendRow($table, $key, $row) ? 1 : 0;
			}

			return $captured;
		} catch (Throwable $error) {
			$problems[] = sprintf('%s: %s', $table, $error->getMessage());

			return 0;
		}
	}

	/**
	 * Appends one row as a captured operation.
	 *
	 * @param string $table
	 *   The table name.
	 * @param string $key
	 *   The primary key column.
	 * @param array<string, mixed> $row
	 *   The row.
	 *
	 * @return bool
	 *   TRUE when the row was appended.
	 */
	private function appendRow(string $table, string $key, array $row): bool
	{
		if (!array_key_exists($key, $row)) {
			return false;
		}

		$payload = (string) json_encode($row);
		$subject = sprintf('%s:%s=%s', $table, $key, (string) $row[$key]);

		try {
			$this->journal->append(
				new JournalOp(
					0,
					(int) round(microtime(true) * JournalOp::MICROSECONDS_PER_SECOND),
					Realm::TABLE,
					$subject,
					Verb::UPDATE,
					null,
					null,
					Hash::of($payload),
					null,
					strlen($payload),
					sprintf('Reconciled %s', $subject),
					array_map('strval', array_keys($row)),
				),
				$payload,
			);

			return true;
		} catch (Throwable $error) {
			$this->logger->error('Strata could not capture %subject: %message', [
				'%subject' => $subject,
				'%message' => $error->getMessage(),
			]);

			return false;
		}
	}

	/**
	 * Records drift.
	 *
	 * @param string $table
	 *   The table that drifted.
	 * @param string $description
	 *   What moved.
	 */
	private function raise(string $table, string $description): void
	{
		$observation = ['table' => $table, 'drifted' => true, 'drift' => $description];

		foreach ($this->tripwires->evaluate($observation) as $finding) {
			$this->ledger->record($finding);
		}
	}

	#endregion
}
