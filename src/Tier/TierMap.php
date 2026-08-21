<?php

declare(strict_types=1);

namespace Drupal\strata\Tier;

use Drupal\strata\Compaction\LevelPolicy;
use InvalidArgumentException;
use JsonSerializable;

/**
 * The storage ladder, nearest first.
 *
 * Shaped like LevelPolicy on purpose - an ordered list, validated to increase, folded coarsest-last
 * - because it answers the same kind of question about the same history. It is a separate list
 * rather than a `tier` key on each retention level for one reason: the retention ladder decides how
 * coarse a segment's window becomes, and a site with five rollup levels and two buckets would
 * otherwise have to invent three more buckets. The two dials stay independent and the settings form
 * prints the retention cutoffs next to the age thresholds so they can be aligned deliberately.
 *
 * Four rules are refused rather than warned about, because each one produces a store that looks
 * configured and is not:
 *
 * - The nearest tier holds everything, so its age threshold is zero.
 * - Thresholds increase. A tier that accepted younger objects than the tier below it would claim
 *   every object the tier below already holds.
 * - Names are unique. A name is what a per-tier request count is filed under, and two tiers under
 *   one name make every figure downstream wrong.
 * - Bucket addresses are unique. Two tiers pointing at one bucket turn a move into a delete and a
 *   replica into a claim about durability that is not true.
 *
 * @see Tier
 * @see LevelPolicy
 */
final class TierMap implements JsonSerializable
{
	/**
	 * Most tiers one ladder may have.
	 *
	 * An absent key costs one request per tier to establish that it is absent, so the ladder depth is
	 * a direct multiplier on the cost of every miss. Eight is far past any real deployment and is
	 * here to stop a mistyped configuration turning one head into hundreds.
	 */
	public const MAX_TIERS = 8;

	/**
	 * The tiers, nearest first.
	 *
	 * @var list<Tier>
	 */
	private readonly array $tiers;

	/**
	 * Constructs a ladder.
	 *
	 * @param list<Tier> $tiers
	 *   The tiers, nearest first. Indexes are taken from position rather than from the objects, so a
	 *   caller cannot build a ladder whose indexes disagree with its order.
	 *
	 * @throws InvalidArgumentException
	 *   When the ladder is empty, deeper than TierMap::MAX_TIERS, has a non-zero nearest threshold,
	 *   has thresholds that do not increase, or repeats a name or a bucket address.
	 */
	public function __construct(array $tiers)
	{
		if ($tiers === []) {
			throw new InvalidArgumentException('A storage ladder needs at least one tier');
		}
		if (count($tiers) > self::MAX_TIERS) {
			throw new InvalidArgumentException(
				sprintf(
					'A storage ladder may have at most %d tiers, got %d',
					self::MAX_TIERS,
					count($tiers),
				),
			);
		}

		$ordered = [];
		$names = [];
		$addresses = [];
		$previous = -1;

		foreach (array_values($tiers) as $index => $tier) {
			if ($index === 0 && $tier->fromAge !== 0) {
				throw new InvalidArgumentException(
					'The nearest tier holds everything, so its age threshold is zero',
				);
			}
			if ($index > 0 && $tier->fromAge <= $previous) {
				throw new InvalidArgumentException(
					sprintf(
						'Tier "%s" accepts objects from %d seconds, which is not older than the tier below it at %d',
						$tier->name(),
						$tier->fromAge,
						$previous,
					),
				);
			}
			if (isset($names[$tier->name()])) {
				throw new InvalidArgumentException(
					sprintf('Two tiers are named "%s"', $tier->name()),
				);
			}
			if (isset($addresses[$tier->target->address()])) {
				throw new InvalidArgumentException(
					sprintf(
						'Tiers "%s" and "%s" both write to %s, so one of them needs its own location',
						$addresses[$tier->target->address()],
						$tier->name(),
						$tier->target->isRelocated()
							? $tier->target->address()
							: $tier->target->provider,
					),
				);
			}

			$names[$tier->name()] = true;
			$addresses[$tier->target->address()] = $tier->name();
			$previous = $tier->fromAge;

			// the index comes from position, so an ordered ladder cannot hold a mislabelled tier
			$ordered[] =
				$tier->index === $index
					? $tier
					: new Tier($index, $tier->target, $tier->fromAge, $tier->retainBelow);
		}

		$this->tiers = $ordered;
	}

	/**
	 * Builds a ladder from the module's settings, or nothing when tiering is off.
	 *
	 * A single tier is not tiering. One tier is exactly what an untiered site already does, so
	 * returning NULL there keeps Engine on the single-provider path rather than wrapping it in a
	 * router that would route to one place.
	 *
	 * @param bool $enabled
	 *   The `tiers.enabled` setting.
	 * @param array<int, array<string, mixed>> $configured
	 *   The `tiers.levels` setting.
	 *
	 * @return self|null
	 *   The ladder, or NULL when tiering is off or fewer than two tiers are configured.
	 *
	 * @throws InvalidArgumentException
	 *   When the configured ladder breaks one of the four rules.
	 */
	public static function fromSettings(bool $enabled, array $configured): ?self
	{
		if (!$enabled || count($configured) < 2) {
			return null;
		}

		$tiers = [];

		foreach (array_values($configured) as $index => $row) {
			$tiers[] = Tier::fromSettings($row, $index);
		}

		return new self($tiers);
	}

	#region The Ladder

	/**
	 * How many tiers the ladder has.
	 *
	 * @return int
	 *   The count.
	 */
	public function count(): int
	{
		return count($this->tiers);
	}

	/**
	 * Every tier, nearest first.
	 *
	 * @return list<Tier>
	 *   The tiers.
	 */
	public function all(): array
	{
		return $this->tiers;
	}

	/**
	 * The tier writes land in.
	 *
	 * @return Tier
	 *   Tier zero.
	 */
	public function nearest(): Tier
	{
		return $this->tiers[0];
	}

	/**
	 * The furthest tier.
	 *
	 * @return Tier
	 *   The last tier on the ladder.
	 */
	public function coldest(): Tier
	{
		return $this->tiers[count($this->tiers) - 1];
	}

	/**
	 * One tier by index.
	 *
	 * @param int $index
	 *   Position on the ladder.
	 *
	 * @return Tier
	 *   The tier.
	 *
	 * @throws InvalidArgumentException
	 *   When the index is not on the ladder.
	 */
	public function at(int $index): Tier
	{
		if (!isset($this->tiers[$index])) {
			throw new InvalidArgumentException(
				sprintf('Tier %d is not on a ladder of %d tiers', $index, $this->count()),
			);
		}

		return $this->tiers[$index];
	}

	/**
	 * Whether an index names a tier.
	 *
	 * @param int $index
	 *   Position on the ladder.
	 *
	 * @return bool
	 *   TRUE when the tier exists.
	 */
	public function has(int $index): bool
	{
		return isset($this->tiers[$index]);
	}

	/**
	 * Every tier index, nearest first.
	 *
	 * @return list<int>
	 *   The indexes.
	 */
	public function indexes(): array
	{
		return array_keys($this->tiers);
	}

	/**
	 * Tier names keyed by index.
	 *
	 * @return array<int, string>
	 *   Names, for a report table and for the per-tier request counts.
	 */
	public function names(): array
	{
		$names = [];

		foreach ($this->tiers as $tier) {
			$names[$tier->index] = $tier->name();
		}

		return $names;
	}

	#endregion

	#region Questions

	/**
	 * The tier an object of a given age belongs in.
	 *
	 * The coldest tier whose threshold the age has reached, so an object older than every threshold
	 * lands at the end of the ladder rather than partway along it.
	 *
	 * @param int $age
	 *   Seconds since the object was last written.
	 *
	 * @return Tier
	 *   The tier, which is the nearest one for anything younger than the first threshold.
	 */
	public function forAge(int $age): Tier
	{
		$chosen = $this->nearest();

		foreach ($this->tiers as $tier) {
			if ($tier->accepts($age)) {
				$chosen = $tier;
			}
		}

		return $chosen;
	}

	/**
	 * Whether any tier moves objects rather than replicating them.
	 *
	 * A ladder where every tier retains the copy below it keeps the near tier complete, so a read
	 * never reaches a far tier and a far tier being down costs nothing but the second copy. A ladder
	 * with one destination tier splits history across buckets, and that is what makes a far tier's
	 * reachability part of whether a restore can run.
	 *
	 * @return bool
	 *   TRUE when at least one tier discards the copy below it.
	 */
	public function isSplit(): bool
	{
		foreach ($this->tiers as $tier) {
			if (!$tier->isNearest() && !$tier->retainBelow) {
				return true;
			}
		}

		return false;
	}

	#endregion

	/**
	 * {@inheritdoc}
	 *
	 * @return list<array<string, mixed>>
	 *   The ladder as plain arrays for a report table.
	 */
	public function jsonSerialize(): array
	{
		return array_map(static fn(Tier $tier): array => $tier->jsonSerialize(), $this->tiers);
	}
}
