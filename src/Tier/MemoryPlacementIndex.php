<?php

declare(strict_types=1);

namespace Drupal\strata\Tier;

/**
 * A placement index that lives for one process.
 *
 * What the unit lane routes against, and what a one-shot command can use when it has no database.
 * The database index is the one a site runs on.
 *
 * @see PlacementIndexInterface
 * @see DatabasePlacementIndex
 */
final class MemoryPlacementIndex implements PlacementIndexInterface
{
	/**
	 * Placements, keyed by object key.
	 *
	 * @var array<string, Placement>
	 */
	private array $placements = [];

	/**
	 * {@inheritdoc}
	 */
	public function get(string $key): ?Placement
	{
		return $this->placements[$key] ?? null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function record(Placement $placement): Placement
	{
		return $this->placements[$placement->key] = $placement;
	}

	/**
	 * {@inheritdoc}
	 */
	public function place(string $key, int $tier, int $bytes = 0): Placement
	{
		$existing = $this->placements[$key] ?? new Placement($key, [], $bytes, time());
		$placed = $existing->with($tier, time());

		return $this->placements[$key] = $bytes > 0 ? $placed->withBytes($bytes) : $placed;
	}

	/**
	 * {@inheritdoc}
	 */
	public function displace(string $key, int $tier): Placement
	{
		$existing = $this->placements[$key] ?? null;

		if ($existing === null) {
			return new Placement($key);
		}

		return $this->placements[$key] = $existing->without($tier, time());
	}

	/**
	 * {@inheritdoc}
	 */
	public function forget(array $keys): int
	{
		$removed = 0;

		foreach ($keys as $key) {
			if (isset($this->placements[$key])) {
				unset($this->placements[$key]);
				$removed++;
			}
		}

		return $removed;
	}

	/**
	 * {@inheritdoc}
	 */
	public function keysIn(int $tier, int $limit = 1000, int $offset = 0): array
	{
		$keys = [];

		foreach ($this->sorted() as $placement) {
			if ($placement->has($tier)) {
				$keys[] = $placement->key;
			}
		}

		return array_values(array_slice($keys, max(0, $offset), max(0, $limit)));
	}

	/**
	 * {@inheritdoc}
	 */
	public function page(int $limit = 1000, int $offset = 0): array
	{
		return array_values(array_slice($this->sorted(), max(0, $offset), max(0, $limit)));
	}

	/**
	 * {@inheritdoc}
	 */
	public function clear(): int
	{
		$held = count($this->placements);
		$this->placements = [];

		return $held;
	}

	/**
	 * {@inheritdoc}
	 */
	public function byTier(): array
	{
		$tiers = [];

		foreach ($this->placements as $placement) {
			foreach ($placement->tiers() as $tier) {
				$tiers[$tier] ??= ['objects' => 0, 'bytes' => 0];
				$tiers[$tier]['objects']++;
				$tiers[$tier]['bytes'] += $placement->bytes;
			}
		}

		ksort($tiers);

		return $tiers;
	}

	/**
	 * {@inheritdoc}
	 */
	public function statistics(): array
	{
		$copies = 0;
		$bytes = 0;

		foreach ($this->placements as $placement) {
			$copies += $placement->copies();
			$bytes += $placement->bytes;
		}

		return ['objects' => count($this->placements), 'copies' => $copies, 'bytes' => $bytes];
	}

	/**
	 * Every placement in key order.
	 *
	 * @return list<Placement>
	 *   The placements.
	 */
	private function sorted(): array
	{
		$placements = $this->placements;
		ksort($placements);

		return array_values($placements);
	}
}
