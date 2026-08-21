<?php

declare(strict_types=1);

namespace Drupal\strata\Restore\Plugin\Strata\Restore;

use Drupal\Core\Database\Connection;
use Drupal\strata\Restore\RestoreStrategyInterface;
use RuntimeException;
use Throwable;

/**
 * Builds the new contents in a shadow table and renames it into place.
 *
 * The original stays readable and complete until the instant of the swap, so a site can restore one
 * table without going into maintenance mode. That is the whole reason this exists next to the
 * truncate strategy, which is simpler and works everywhere.
 *
 * **It is only atomic where the driver can rename two tables in one statement.** MySQL's
 * `RENAME TABLE a TO a_old, b TO a` is one operation with no window in which the name is unbound.
 * PostgreSQL's `ALTER TABLE ... RENAME` is per-table, but two of them inside a transaction commit
 * together, which gives the same guarantee. SQLite can rename a table and cannot do either
 * atomically, so a reader can find the name missing between the two statements.
 *
 * **On SQLite it refuses rather than degrading.** A strategy that quietly fell back to truncating
 * would give an operator the swap's promise and the truncate's behaviour, and the one moment that
 * matters is the moment they were relying on the promise.
 *
 * @see RestoreStrategyInterface
 * @see TruncateRestoreStrategy
 */
final class ShadowSwapStrategy implements RestoreStrategyInterface
{
	/**
	 * How many rows are inserted per statement.
	 */
	public const BATCH = 500;

	/**
	 * Drivers whose rename is atomic enough for this.
	 */
	public const SUPPORTED_DRIVERS = ['mysql', 'pgsql'];

	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return 'shadow_swap';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label(): string
	{
		return 'Build and Swap';
	}

	/**
	 * {@inheritdoc}
	 */
	public function describe(): string
	{
		return 'The table stays readable and complete until the swap, so this needs no maintenance ' .
			'window. Requires MySQL or PostgreSQL.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function isSupported(Connection $database): bool
	{
		return in_array($this->driver($database), self::SUPPORTED_DRIVERS, true);
	}

	/**
	 * {@inheritdoc}
	 */
	public function unsupportedReason(Connection $database): ?string
	{
		if ($this->isSupported($database)) {
			return null;
		}

		return sprintf(
			'%s cannot rename two tables atomically, so a reader could find the table missing ' .
				'mid-swap. Use the truncate strategy in maintenance mode instead.',
			$this->driver($database),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function restore(Connection $database, string $table, array $rows, array $columns): int
	{
		$reason = $this->unsupportedReason($database);

		if ($reason !== null) {
			throw new RuntimeException($reason);
		}
		if ($columns === []) {
			throw new RuntimeException(
				sprintf('Cannot restore %s without knowing which columns to write', $table),
			);
		}

		$schema = $database->schema();

		if (!$schema->tableExists($table)) {
			throw new RuntimeException(sprintf('Table %s does not exist', $table));
		}

		$shadow = $this->shadowName($table);
		$retired = $this->retiredName($table);

		$this->drop($database, $shadow);
		$this->drop($database, $retired);

		try {
			$written = $this->fill($database, $table, $shadow, $rows, $columns);
			$this->swap($database, $table, $shadow, $retired);
		} catch (Throwable $error) {
			// the original is untouched until the swap, so cleaning up the shadow restores nothing
			$this->drop($database, $shadow);

			throw new RuntimeException(
				sprintf(
					'Restoring %s failed and the table was left as it was: %s',
					$table,
					$error->getMessage(),
				),
				0,
				$error,
			);
		}

		$this->drop($database, $retired);

		return $written;
	}

	/**
	 * The name the new contents are built under.
	 *
	 * @param string $table
	 *   The table being restored.
	 *
	 * @return string
	 *   The shadow name.
	 */
	public function shadowName(string $table): string
	{
		return substr($table, 0, 40) . '_strata_new';
	}

	/**
	 * The name the old contents are moved aside to.
	 *
	 * Kept until the swap succeeds and dropped afterwards, so a failed swap has something to put
	 * back rather than nothing.
	 *
	 * @param string $table
	 *   The table being restored.
	 *
	 * @return string
	 *   The retired name.
	 */
	public function retiredName(string $table): string
	{
		return substr($table, 0, 40) . '_strata_old';
	}

	/**
	 * Copies the table's structure and fills it with the new rows.
	 *
	 * The structure is copied by the database rather than described by Strata, so every index,
	 * default and constraint comes across without this class having to understand the schema.
	 *
	 * @param Connection $database
	 *   The connection.
	 * @param string $table
	 *   The original table.
	 * @param string $shadow
	 *   The shadow table to create.
	 * @param list<array<string, mixed>> $rows
	 *   The rows to write.
	 * @param list<string> $columns
	 *   The columns to write.
	 *
	 * @return int
	 *   How many rows were written.
	 *
	 * @throws RuntimeException
	 *   When a row does not carry every column.
	 */
	private function fill(
		Connection $database,
		string $table,
		string $shadow,
		array $rows,
		array $columns,
	): int {
		$driver = $this->driver($database);

		if ($driver === 'mysql') {
			$database->query('CREATE TABLE {' . $shadow . '} LIKE {' . $table . '}');
		} else {
			// postgres copies defaults and identity but not indexes, which a restore does not need
			// on a table it is about to swap in whole
			$database->query(
				'CREATE TABLE {' . $shadow . '} (LIKE {' . $table . '} INCLUDING ALL)',
			);
		}

		$written = 0;

		foreach (array_chunk($rows, self::BATCH) as $batch) {
			$insert = $database->insert($shadow)->fields($columns);

			foreach ($batch as $row) {
				$values = [];

				foreach ($columns as $column) {
					if (!array_key_exists($column, $row)) {
						throw new RuntimeException(
							sprintf('A stored row for %s is missing column %s', $table, $column),
						);
					}

					$values[$column] = $row[$column];
				}

				$insert->values($values);
				$written++;
			}

			$insert->execute();
		}

		return $written;
	}

	/**
	 * Moves the shadow into place.
	 *
	 * @param Connection $database
	 *   The connection.
	 * @param string $table
	 *   The original name.
	 * @param string $shadow
	 *   The shadow holding the new contents.
	 * @param string $retired
	 *   Where the old contents go.
	 */
	private function swap(
		Connection $database,
		string $table,
		string $shadow,
		string $retired,
	): void {
		if ($this->driver($database) === 'mysql') {
			// one statement, so the name is never unbound
			$database->query(
				sprintf(
					'RENAME TABLE {%s} TO {%s}, {%s} TO {%s}',
					$table,
					$retired,
					$shadow,
					$table,
				),
			);

			return;
		}

		// two statements that commit together, which gives the same guarantee to any reader
		$transaction = $database->startTransaction();

		try {
			$database->query(sprintf('ALTER TABLE {%s} RENAME TO {%s}', $table, $retired));
			$database->query(sprintf('ALTER TABLE {%s} RENAME TO {%s}', $shadow, $table));
		} catch (Throwable $error) {
			$transaction->rollBack();

			throw $error;
		}

		unset($transaction);
	}

	/**
	 * Drops a table if it is there.
	 *
	 * @param Connection $database
	 *   The connection.
	 * @param string $table
	 *   The table name.
	 */
	private function drop(Connection $database, string $table): void
	{
		if ($database->schema()->tableExists($table)) {
			$database->schema()->dropTable($table);
		}
	}

	/**
	 * The connection's driver name.
	 *
	 * @param Connection $database
	 *   The connection.
	 *
	 * @return string
	 *   The driver, lowercased.
	 */
	private function driver(Connection $database): string
	{
		return strtolower($database->driver());
	}
}
