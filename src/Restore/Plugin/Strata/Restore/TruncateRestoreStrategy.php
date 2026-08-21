<?php

declare(strict_types=1);

namespace Drupal\strata\Restore\Plugin\Strata\Restore;

use Drupal\Core\Database\Connection;
use Drupal\strata\Restore\RestoreStrategyInterface;
use RuntimeException;
use Throwable;

/**
 * Empties a table and refills it, inside one transaction.
 *
 * The strategy that works everywhere. Every driver Drupal supports can truncate and insert, and a
 * transaction makes the whole replacement atomic from a reader's point of view - on SQLite, MySQL with
 * InnoDB and PostgreSQL alike.
 *
 * **What it costs.** The table is empty to any reader inside the transaction's window, so this is a
 * maintenance-mode operation on a live site. That is stated rather than worked around, because the
 * alternative is a strategy that silently serves an empty table to real traffic.
 *
 * **`TRUNCATE` is deliberately not used.** On MySQL it is DDL and commits implicitly, which would
 * break the transaction wrapping the refill and leave a truncated table if the insert then failed.
 * A `DELETE` with no condition is transactional on every driver, which matters more here than the
 * speed difference on a table Strata is restoring row by row anyway.
 *
 * @see RestoreStrategyInterface
 * @see ShadowSwapStrategy
 */
final class TruncateRestoreStrategy implements RestoreStrategyInterface
{
	/**
	 * How many rows are inserted per statement.
	 *
	 * A multi-row insert is far faster than one per row, and an unbounded one exceeds placeholder
	 * limits: MySQL allows 65,535 placeholders per statement, so a twenty-column table caps out
	 * around 3,200 rows.
	 */
	public const BATCH = 500;

	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return 'truncate';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label(): string
	{
		return 'Empty and Refill';
	}

	/**
	 * {@inheritdoc}
	 */
	public function describe(): string
	{
		return 'Works on every driver. The table is empty to readers until the restore commits, so ' .
			'this needs maintenance mode on a live site.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function isSupported(Connection $database): bool
	{
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function unsupportedReason(Connection $database): ?string
	{
		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function restore(Connection $database, string $table, array $rows, array $columns): int
	{
		if ($columns === []) {
			throw new RuntimeException(
				sprintf('Cannot restore %s without knowing which columns to write', $table),
			);
		}
		if (!$database->schema()->tableExists($table)) {
			throw new RuntimeException(sprintf('Table %s does not exist', $table));
		}

		$transaction = $database->startTransaction();

		try {
			$database->delete($table)->execute();
			$written = $this->insert($database, $table, $rows, $columns);
		} catch (Throwable $error) {
			$transaction->rollBack();

			throw new RuntimeException(
				sprintf(
					'Restoring %s failed and was rolled back: %s',
					$table,
					$error->getMessage(),
				),
				0,
				$error,
			);
		}

		unset($transaction);

		return $written;
	}

	/**
	 * Inserts the rows in batches.
	 *
	 * @param Connection $database
	 *   The connection.
	 * @param string $table
	 *   The table.
	 * @param list<array<string, mixed>> $rows
	 *   The rows.
	 * @param list<string> $columns
	 *   The columns to write.
	 *
	 * @return int
	 *   How many rows were inserted.
	 *
	 * @throws RuntimeException
	 *   When a row does not carry every column.
	 */
	private function insert(Connection $database, string $table, array $rows, array $columns): int
	{
		$written = 0;

		foreach (array_chunk($rows, self::BATCH) as $batch) {
			$insert = $database->insert($table)->fields($columns);

			foreach ($batch as $row) {
				$values = [];

				foreach ($columns as $column) {
					if (!array_key_exists($column, $row)) {
						throw new RuntimeException(
							sprintf(
								'A stored row for %s is missing column %s, so it is not written ' .
									'rather than written with a guess',
								$table,
								$column,
							),
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
}
