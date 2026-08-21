<?php

declare(strict_types=1);

namespace Drupal\strata\Tier;

use Drupal\strata\Tree\RefStore;

/**
 * Decides which tier an object is written to, read from, and moved into.
 *
 * All of it is pure: a key, an age and a placement in, tier indexes out. That is deliberate, because
 * these are the decisions that decide whether history is readable, and a decision that needs a
 * bucket to answer cannot be tested against the cases that matter.
 *
 * Three rules do the work.
 *
 * **Every write lands in the nearest tier and nowhere else.** A far tier is written to exactly once
 * per object, by TierMigrator, and never on the flush path. That is what makes a far tier cheap:
 * its class-A request count is the number of objects that have ever aged into it, not the number of
 * times the site was written to.
 *
 * **Refs are pinned to the nearest tier.** A ref is the only mutable object in the store and the
 * only place two writers can collide, so it lives in exactly one bucket. A second ref in a far tier
 * would be a second opinion about where history ends, with no rule for resolving a disagreement that
 * is not a guess. It also keeps the cost of reading a ref at one request whatever the ladder depth,
 * which matters because every flush reads one.
 *
 * **A read tries the tiers a placement names, then the rest nearest-first.** A known object costs
 * what an untiered read costs. An unknown one costs one request per tier and records what it found,
 * so it is only ever unknown once.
 *
 * @see TierMap
 * @see TieredProvider
 * @see TierMigrator
 */
final class TierPolicy
{
	/**
	 * Key prefixes that never leave the nearest tier.
	 *
	 * Matched as a whole path segment, so it catches both the bare prefix and the site-scoped form a
	 * shared bucket produces.
	 */
	public const PINNED_PREFIXES = [RefStore::PREFIX];

	/**
	 * Constructs a policy.
	 *
	 * @param TierMap $tiers
	 *   The ladder being applied.
	 */
	public function __construct(private readonly TierMap $tiers) {}

	/**
	 * The ladder this policy applies.
	 *
	 * @return TierMap
	 *   The ladder.
	 */
	public function tiers(): TierMap
	{
		return $this->tiers;
	}

	/**
	 * Where a write goes.
	 *
	 * One tier rather than a set, and that is the point rather than a simplification: a body may be
	 * an open stream, the contract says a stream is read once and not rewound, and a write fanned out
	 * to two tiers would hand the second one an exhausted stream. A second copy is made later, from
	 * the bytes in the bucket, by TierMigrator.
	 *
	 * @return int
	 *   The nearest tier's index.
	 */
	public function tierForWrite(): int
	{
		return $this->tiers->nearest()->index;
	}

	/**
	 * The order tiers are tried in when reading.
	 *
	 * @param string $key
	 *   The object key.
	 * @param Placement|null $placement
	 *   What is known about where it is, or NULL when nothing is.
	 *
	 * @return list<int>
	 *   Tier indexes to try, in order. A pinned key resolves to the nearest tier alone, so a ref
	 *   that is genuinely absent costs one request rather than one per tier.
	 */
	public function readOrder(string $key, ?Placement $placement): array
	{
		if (self::isPinned($key)) {
			return [$this->tiers->nearest()->index];
		}

		$order = [];

		foreach ($placement?->tiers() ?? [] as $tier) {
			if ($this->tiers->has($tier)) {
				$order[] = $tier;
			}
		}

		foreach ($this->tiers->indexes() as $index) {
			if (!in_array($index, $order, true)) {
				$order[] = $index;
			}
		}

		return $order;
	}

	/**
	 * The tier an object belongs in, given how long it has gone untouched.
	 *
	 * @param string $key
	 *   The object key.
	 * @param int $age
	 *   Seconds since the object was last written.
	 *
	 * @return int
	 *   The tier index. A pinned key is always the nearest tier however old it is.
	 */
	public function targetFor(string $key, int $age): int
	{
		if (self::isPinned($key)) {
			return $this->tiers->nearest()->index;
		}

		return $this->tiers->forAge($age)->index;
	}

	/**
	 * Whether an object should move out of the tier it is in.
	 *
	 * @param string $key
	 *   The object key.
	 * @param int $from
	 *   Tier index it is in now.
	 * @param int $age
	 *   Seconds since it was last written.
	 *
	 * @return int|null
	 *   The tier index to promote it into, or NULL when it is already where it belongs. Only ever
	 *   one step, so an object crossing two thresholds at once is moved by two passes and every
	 *   intermediate state is one this code can describe.
	 */
	public function promotionFor(string $key, int $from, int $age): ?int
	{
		$target = $this->targetFor($key, $age);

		if ($target <= $from || !$this->tiers->has($from + 1)) {
			return null;
		}

		return $from + 1;
	}

	/**
	 * Whether the copy below survives a promotion into a tier.
	 *
	 * @param int $tier
	 *   Tier index being promoted into.
	 *
	 * @return bool
	 *   TRUE when the object is copied and the nearer copy stays; FALSE when it moves.
	 */
	public function retainsBelow(int $tier): bool
	{
		return $this->tiers->has($tier) && $this->tiers->at($tier)->retainBelow;
	}

	/**
	 * Whether a key never leaves the nearest tier.
	 *
	 * @param string $key
	 *   The object key, scoped or not.
	 *
	 * @return bool
	 *   TRUE when the key sits under a pinned prefix.
	 */
	public static function isPinned(string $key): bool
	{
		foreach (self::PINNED_PREFIXES as $prefix) {
			if (str_starts_with($key, $prefix . '/') || str_contains($key, '/' . $prefix . '/')) {
				return true;
			}
		}

		return false;
	}
}
