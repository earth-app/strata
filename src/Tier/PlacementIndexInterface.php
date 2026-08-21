<?php

declare(strict_types=1);

namespace Drupal\strata\Tier;

/**
 * Remembers which tier holds which object.
 *
 * Without it a read has to guess, and guessing costs one request per tier before the right one is
 * found. With it a read of a known object costs exactly what an untiered read costs.
 *
 * Everything here is derived from the buckets and is rebuilt by TierPlacementRebuilder, which is
 * what makes it safe for an uninstall to drop the table. A miss is therefore not a failure: it
 * means the router has to probe, and it records what it finds so the next read is cheap again.
 *
 * @see Placement
 * @see TieredProvider
 * @see TierPlacementRebuilder
 */
interface PlacementIndexInterface
{
	/**
	 * Where one object is.
	 *
	 * @param string $key
	 *   The object key.
	 *
	 * @return Placement|null
	 *   The placement, or NULL when nothing has been recorded for that key. NULL means unknown, not
	 *   absent; Placement::isEmpty() is what says every tier was asked and none had it.
	 */
	public function get(string $key): ?Placement;

	/**
	 * Replaces what is known about one object.
	 *
	 * @param Placement $placement
	 *   The placement. Tiers not named by it are removed, so this is how a rebuild writes an
	 *   authoritative answer over a stale one.
	 *
	 * @return Placement
	 *   The stored placement.
	 */
	public function record(Placement $placement): Placement;

	/**
	 * Adds one tier to what is known about an object.
	 *
	 * @param string $key
	 *   The object key.
	 * @param int $tier
	 *   Tier index that holds it.
	 * @param int $bytes
	 *   The object's size, or zero to keep whatever is recorded.
	 *
	 * @return Placement
	 *   The resulting placement.
	 */
	public function place(string $key, int $tier, int $bytes = 0): Placement;

	/**
	 * Removes one tier from what is known about an object.
	 *
	 * @param string $key
	 *   The object key.
	 * @param int $tier
	 *   Tier index that no longer holds it.
	 *
	 * @return Placement
	 *   The resulting placement, empty when that was the last tier holding it.
	 */
	public function displace(string $key, int $tier): Placement;

	/**
	 * Forgets objects entirely.
	 *
	 * @param list<string> $keys
	 *   Object keys.
	 *
	 * @return int
	 *   How many rows were removed.
	 */
	public function forget(array $keys): int;

	/**
	 * Object keys one tier holds, in key order.
	 *
	 * @param int $tier
	 *   Tier index.
	 * @param int $limit
	 *   Most keys to return.
	 * @param int $offset
	 *   Keys to skip.
	 *
	 * @return list<string>
	 *   The keys.
	 */
	public function keysIn(int $tier, int $limit = 1000, int $offset = 0): array;

	/**
	 * One page of every placement, in key order.
	 *
	 * @param int $limit
	 *   Most placements to return.
	 * @param int $offset
	 *   Placements to skip.
	 *
	 * @return list<Placement>
	 *   The page, empty once the offset is past the end.
	 */
	public function page(int $limit = 1000, int $offset = 0): array;

	/**
	 * Drops every row.
	 *
	 * Used by TierPlacementRebuilder, which lists every tier and must not inherit a row for an
	 * object that has moved.
	 *
	 * @return int
	 *   How many rows were removed.
	 */
	public function clear(): int;

	/**
	 * What each tier holds.
	 *
	 * @return array<int, array{objects: int, bytes: int}>
	 *   Tier index keyed to how many objects it holds and how many bytes they occupy. A tier with
	 *   nothing recorded is absent, so a report drawn from this shows the tiers in use.
	 */
	public function byTier(): array;

	/**
	 * How many distinct objects and rows are held.
	 *
	 * @return array{objects: int, copies: int, bytes: int}
	 *   Distinct object keys, total placements across every tier, and the byte total counted once
	 *   per object rather than once per copy.
	 */
	public function statistics(): array;
}
