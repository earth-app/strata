<?php

declare(strict_types=1);

namespace Drupal\strata\Tier;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * The placement index a site runs on.
 *
 * One row per object per tier, keyed on both, so adding a replica is an insert rather than a
 * read-modify-write over a packed column and two concurrent migrations cannot lose one another's
 * copy.
 *
 * Every row is derived from the buckets and is rebuilt by TierPlacementRebuilder, so an uninstall
 * dropping the table costs the next read one probe per tier and nothing else.
 *
 * @see PlacementIndexInterface
 * @see MemoryPlacementIndex
 */
final class DatabasePlacementIndex implements PlacementIndexInterface
{
	/**
	 * The table rows live in.
	 */
	public const TABLE = 'strata_placement';

	/**
	 * Constructs an index.
	 *
	 * @param Connection $database
	 *   Where rows are kept.
	 */
	public function __construct(private readonly Connection $database) {}

	/**
	 * {@inheritdoc}
	 */
	public function get(string $key): ?Placement
	{
		$rows = $this->database
			->select(self::TABLE, 'p')
			->fields('p', ['tier', 'bytes', 'recorded'])
			->condition('object_key', $key)
			->orderBy('tier')
			->execute()
			?->fetchAll(FetchAs::Associative);

		if ($rows === null || $rows === []) {
			return null;
		}

		$tiers = [];
		$bytes = 0;
		$recorded = 0;

		foreach ($rows as $row) {
			$tiers[] = (int) $row['tier'];
			$bytes = max($bytes, (int) $row['bytes']);
			$recorded = max($recorded, (int) $row['recorded']);
		}

		return new Placement($key, $tiers, $bytes, $recorded);
	}

	/**
	 * {@inheritdoc}
	 */
	public function record(Placement $placement): Placement
	{
		$tiers = $placement->tiers();

		$delete = $this->database->delete(self::TABLE)->condition('object_key', $placement->key);

		if ($tiers !== []) {
			$delete->condition('tier', $tiers, 'NOT IN');
		}

		$delete->execute();

		foreach ($tiers as $tier) {
			$this->write($placement->key, $tier, $placement->bytes, $placement->recorded);
		}

		return $placement;
	}

	/**
	 * {@inheritdoc}
	 */
	public function place(string $key, int $tier, int $bytes = 0): Placement
	{
		$existing = $this->get($key);
		$size = $bytes > 0 ? $bytes : $existing->bytes ?? 0;

		$this->write($key, $tier, $size, time());

		if ($size > 0 && $existing !== null && $existing->bytes !== $size) {
			// every copy is the same object, so one size describes all of them
			$this->database
				->update(self::TABLE)
				->fields(['bytes' => $size])
				->condition('object_key', $key)
				->execute();
		}

		return $this->get($key) ?? new Placement($key, [$tier], $size, time());
	}

	/**
	 * {@inheritdoc}
	 */
	public function displace(string $key, int $tier): Placement
	{
		$this->database
			->delete(self::TABLE)
			->condition('object_key', $key)
			->condition('tier', $tier)
			->execute();

		return $this->get($key) ?? new Placement($key);
	}

	/**
	 * {@inheritdoc}
	 */
	public function forget(array $keys): int
	{
		if ($keys === []) {
			return 0;
		}

		return (int) $this->database
			->delete(self::TABLE)
			->condition('object_key', $keys, 'IN')
			->execute();
	}

	/**
	 * {@inheritdoc}
	 */
	public function keysIn(int $tier, int $limit = 1000, int $offset = 0): array
	{
		$keys = $this->database
			->select(self::TABLE, 'p')
			->fields('p', ['object_key'])
			->condition('tier', $tier)
			->orderBy('object_key')
			->range(max(0, $offset), max(0, $limit))
			->execute()
			?->fetchCol();

		if (!is_array($keys)) {
			return [];
		}

		return array_values(array_map(static fn(mixed $key): string => (string) $key, $keys));
	}

	/**
	 * {@inheritdoc}
	 */
	public function page(int $limit = 1000, int $offset = 0): array
	{
		$select = $this->database->select(self::TABLE, 'p');
		$select->fields('p', ['object_key']);
		$select->groupBy('p.object_key');
		$select->orderBy('object_key');
		$select->range(max(0, $offset), max(0, $limit));

		$keys = $select->execute()?->fetchCol();
		$placements = [];

		foreach (is_array($keys) ? $keys : [] as $key) {
			$placement = $this->get((string) $key);

			if ($placement !== null) {
				$placements[] = $placement;
			}
		}

		return $placements;
	}

	/**
	 * {@inheritdoc}
	 */
	public function clear(): int
	{
		return (int) $this->database->delete(self::TABLE)->execute();
	}

	/**
	 * {@inheritdoc}
	 */
	public function byTier(): array
	{
		$select = $this->database->select(self::TABLE, 'p');
		$select->fields('p', ['tier']);
		$select->addExpression('COUNT(*)', 'objects');
		$select->addExpression('COALESCE(SUM([p].[bytes]), 0)', 'bytes');
		$select->groupBy('p.tier');
		$select->orderBy('tier');

		$rows = $select->execute()?->fetchAll(FetchAs::Associative) ?? [];
		$tiers = [];

		foreach ($rows as $row) {
			$tiers[(int) $row['tier']] = [
				'objects' => (int) $row['objects'],
				'bytes' => (int) $row['bytes'],
			];
		}

		return $tiers;
	}

	/**
	 * {@inheritdoc}
	 */
	public function statistics(): array
	{
		$inner = $this->database->select(self::TABLE, 'p');
		$inner->fields('p', ['object_key']);
		$inner->addExpression('MAX([p].[bytes])', 'bytes');
		$inner->groupBy('p.object_key');

		$rows = $inner->execute()?->fetchAll(FetchAs::Associative) ?? [];
		$bytes = 0;

		foreach ($rows as $row) {
			$bytes += (int) $row['bytes'];
		}

		$copies = (int) $this->database
			->select(self::TABLE, 'p')
			->countQuery()
			->execute()
			?->fetchField();

		return ['objects' => count($rows), 'copies' => $copies, 'bytes' => $bytes];
	}

	/**
	 * Writes one row.
	 *
	 * @param string $key
	 *   The object key.
	 * @param int $tier
	 *   Tier index.
	 * @param int $bytes
	 *   The object's size.
	 * @param int $recorded
	 *   Unix timestamp.
	 */
	private function write(string $key, int $tier, int $bytes, int $recorded): void
	{
		$this->database
			->merge(self::TABLE)
			->keys(['object_key' => $key, 'tier' => $tier])
			->fields(['bytes' => $bytes, 'recorded' => $recorded > 0 ? $recorded : time()])
			->execute();
	}
}
