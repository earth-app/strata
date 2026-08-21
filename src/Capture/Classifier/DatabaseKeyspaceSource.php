<?php

declare(strict_types=1);

namespace Drupal\strata\Capture\Classifier;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Generator;
use Throwable;
use Traversable;

/**
 * Reads the ephemeral keyspace out of the database.
 *
 * The source every site has. Drupal's default cache, key-value and expirable backends are tables, so
 * a site with no Redis still has a describable keyspace and still gets a classification pass. When
 * `strata_redis` is installed its own source is added alongside this one rather than replacing it,
 * because a site commonly keeps some bins in the database and moves only the hot ones.
 *
 * Sizes come from the stored value's length, which is a lower bound on what the entry costs: it
 * excludes the key, the expiry and the tags. That is the right direction to be wrong in for a number
 * used to rank what needs a decision.
 *
 * @see KeyspaceSourceInterface
 */
final class DatabaseKeyspaceSource implements KeyspaceSourceInterface
{
	/**
	 * Table prefixes that hold ephemeral entries, with the column naming each entry.
	 *
	 * @var array<string, array{key: string, value: string}>
	 */
	public const SHAPES = [
		'cache_' => ['key' => 'cid', 'value' => 'data'],
		'key_value_expire' => ['key' => 'name', 'value' => 'value'],
		'key_value' => ['key' => 'name', 'value' => 'value'],
		'queue' => ['key' => 'name', 'value' => 'data'],
		'semaphore' => ['key' => 'name', 'value' => 'value'],
		'flood' => ['key' => 'event', 'value' => 'identifier'],
		'sessions' => ['key' => 'sid', 'value' => 'session'],
	];

	/**
	 * Constructs a source.
	 *
	 * @param Connection $database
	 *   The connection to read.
	 */
	public function __construct(private readonly Connection $database) {}

	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return 'database';
	}

	/**
	 * {@inheritdoc}
	 */
	public function isAvailable(): bool
	{
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function keys(int $limit): Traversable
	{
		$remaining = max(0, $limit);

		foreach ($this->tables() as $table => $shape) {
			if ($remaining < 1) {
				return;
			}

			yield from $this->fromTable($table, $shape, $remaining, $remaining);
		}
	}

	/**
	 * Tables on this connection that hold ephemeral entries.
	 *
	 * @return array<string, array{key: string, value: string}>
	 *   Table name keyed to the shape it uses.
	 */
	private function tables(): array
	{
		$found = [];

		foreach ($this->database->schema()->findTables('%') as $table) {
			$name = (string) $table;

			// this module's own tables describe the backup rather than the site
			if (str_starts_with($name, 'strata_')) {
				continue;
			}

			foreach (self::SHAPES as $prefix => $shape) {
				if (str_starts_with($name, $prefix)) {
					$found[$name] = $shape;

					break;
				}
			}
		}

		return $found;
	}

	/**
	 * Reads keys out of one table.
	 *
	 * @param string $table
	 *   The table name.
	 * @param array{key: string, value: string} $shape
	 *   Which columns hold the key and the value.
	 * @param int $limit
	 *   Most rows to read.
	 * @param int $remaining
	 *   Decremented as rows are yielded, so the caller's overall bound is respected.
	 *
	 * @return Generator<string, int>
	 *   Key name keyed to its size in bytes.
	 */
	private function fromTable(string $table, array $shape, int $limit, int &$remaining): Generator
	{
		try {
			$rows =
				$this->database
					->select($table, 't')
					->fields('t', [$shape['key'], $shape['value']])
					->range(0, $limit)
					->execute()
					?->fetchAll(FetchAs::Associative) ?? [];
		} catch (Throwable) {
			// a table whose shape does not match its prefix is skipped rather than failing the pass
			return;
		}

		foreach ($rows as $row) {
			$key = (string) ($row[$shape['key']] ?? '');

			if ($key === '') {
				continue;
			}

			// the table name is the namespace: a cid alone says nothing about which bin holds it
			yield $table . ':' . $key => strlen((string) ($row[$shape['value']] ?? ''));

			$remaining--;

			if ($remaining < 1) {
				return;
			}
		}
	}
}
