<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\E2E;

use Drupal;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * Says what the site holds, as one digest per thing, so a rollback can be compared byte for byte.
 *
 * A restore is precise when the thing it restored hashes exactly as it did at the commit and the
 * things it was not asked about hash exactly as they did before it ran. Every method here is a
 * canonical projection of live data, never of what Strata stored, so the oracle cannot share a bug
 * with the code under test.
 */
final class Oracle
{
	/**
	 * Tables whose contents change on their own and say nothing about a restore.
	 *
	 * @var list<string>
	 */
	public const VOLATILE = [
		'cache_',
		'cachetags',
		'sessions',
		'flood',
		'semaphore',
		'watchdog',
		'queue',
		'key_value_expire',
		'batch',
		'history',
		'sequences',
		'strata_',
	];

	/**
	 * A node's content.
	 *
	 * @param int|string $id
	 *   The node id.
	 *
	 * @return string
	 *   A digest, or `absent` when there is no such node.
	 */
	public static function node(int|string $id): string
	{
		$node = Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($id);

		if ($node === null) {
			return 'absent';
		}

		return self::digest([
			'title' => $node->label(),
			'body' => $node->get('body')->getValue(),
			'status' => (int) $node->isPublished(),
			'promote' => (int) $node->isPromoted(),
			'sticky' => (int) $node->isSticky(),
			'owner' => (int) $node->getOwnerId(),
		]);
	}

	/**
	 * A user's content.
	 *
	 * @param int|string $id
	 *   The user id.
	 *
	 * @return string
	 *   A digest, or `absent`.
	 */
	public static function user(int|string $id): string
	{
		$user = Drupal::entityTypeManager()->getStorage('user')->loadUnchanged($id);

		if ($user === null) {
			return 'absent';
		}

		return self::digest([
			'name' => $user->getAccountName(),
			'mail' => $user->getEmail(),
			'status' => (int) $user->isActive(),
			'roles' => $user->getRoles(true),
		]);
	}

	/**
	 * One configuration object.
	 *
	 * @param string $name
	 *   The config name.
	 *
	 * @return string
	 *   A digest, or `absent` when the object does not exist.
	 */
	public static function config(string $name): string
	{
		$config = Drupal::configFactory()->get($name);

		return $config->isNew() ? 'absent' : self::digest($config->getRawData());
	}

	/**
	 * One state value.
	 *
	 * @param string $key
	 *   The state key.
	 *
	 * @return string
	 *   A digest, or `absent`.
	 */
	public static function state(string $key): string
	{
		Drupal::state()->resetCache();
		$value = Drupal::state()->get($key);

		return $value === null ? 'absent' : self::digest($value);
	}

	/**
	 * One key-value entry.
	 *
	 * @param string $collection
	 *   The collection.
	 * @param string $key
	 *   The key.
	 *
	 * @return string
	 *   A digest, or `absent`.
	 */
	public static function keyValue(string $collection, string $key): string
	{
		$store = Drupal::keyValue($collection);

		return $store->has($key) ? self::digest($store->get($key)) : 'absent';
	}

	/**
	 * The rows of one table, ignoring the order the database returns them in.
	 *
	 * @param string $table
	 *   The table name.
	 * @param array<string, mixed> $conditions
	 *   Column to value, to look at part of the table.
	 *
	 * @return string
	 *   A digest of the rows.
	 */
	public static function table(string $table, array $conditions = []): string
	{
		$query = Drupal::database()->select($table, 't')->fields('t');

		foreach ($conditions as $column => $value) {
			$query->condition($column, $value);
		}

		$rows = array_map(
			static fn(array $row): string => (string) json_encode(
				array_map(
					static fn(mixed $cell): ?string => $cell === null ? null : (string) $cell,
					$row,
				),
			),
			$query->execute()?->fetchAll(FetchAs::Associative) ?? [],
		);

		sort($rows);

		return hash('sha256', implode("\n", $rows));
	}

	/**
	 * The digest of every table whose name starts with one of the prefixes.
	 *
	 * @param list<string> $prefixes
	 *   Table name prefixes, such as `node`.
	 *
	 * @return array<string, string>
	 *   Table name to the digest of its rows.
	 */
	public static function tables(array $prefixes): array
	{
		$names = array_values(Drupal::database()->schema()->findTables('%'));
		sort($names);
		$digests = [];

		foreach ($names as $name) {
			foreach (self::VOLATILE as $skip) {
				if (str_starts_with($name, $skip)) {
					continue 2;
				}
			}

			foreach ($prefixes as $prefix) {
				if (str_starts_with($name, $prefix)) {
					$digests[$name] = self::table($name);

					continue 2;
				}
			}
		}

		return $digests;
	}

	/**
	 * A canonical digest of any value.
	 *
	 * @param mixed $value
	 *   Scalars, arrays and nulls.
	 *
	 * @return string
	 *   SHA-256 over JSON with sorted keys.
	 */
	public static function digest(mixed $value): string
	{
		return hash('sha256', (string) json_encode(self::sorted($value)));
	}

	/**
	 * Sorts keys at every depth, so two equal values encode to the same bytes.
	 *
	 * @param mixed $value
	 *   The value.
	 *
	 * @return mixed
	 *   The same value with keys ordered.
	 */
	private static function sorted(mixed $value): mixed
	{
		if (!is_array($value)) {
			return $value;
		}

		$value = array_map(self::sorted(...), $value);

		if (!array_is_list($value)) {
			ksort($value);
		}

		return $value;
	}
}
