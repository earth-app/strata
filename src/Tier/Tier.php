<?php

declare(strict_types=1);

namespace Drupal\strata\Tier;

use InvalidArgumentException;
use JsonSerializable;

/**
 * One rung of the storage ladder: a bucket, an age band, and whether the copy below it survives.
 *
 * Tier zero is where every write lands and where every ref lives. A tier above it holds history
 * that has aged past its own threshold, and the only question that then matters is whether the
 * nearer copy is kept:
 *
 * - `retainBelow` **true** makes the tier a replica. The nearer copy stays, so the near tier remains
 *   complete and the far tier is a second copy of the same object under the same key. This is the
 *   layered-safety configuration, and it is the one to use when several sites share a bucket.
 * - `retainBelow` **false** makes the tier a destination. The object moves, so history ends up split
 *   across buckets and a read of that part of history reaches the far tier. This is the cheap
 *   configuration: the object is stored once.
 *
 * Neither changes an object's address. A tier is where a key lives, never part of the key, so the
 * same frame in two tiers is one object rather than a copy with a new name.
 *
 * @see TierMap
 * @see TierTarget
 */
final class Tier implements JsonSerializable
{
	/**
	 * Constructs a tier.
	 *
	 * @param int $index
	 *   Position on the ladder, zero being the nearest.
	 * @param TierTarget $target
	 *   Where this tier writes.
	 * @param int $fromAge
	 *   Seconds an object must have gone untouched before it belongs here. Zero for the nearest
	 *   tier, which holds everything by definition.
	 * @param bool $retainBelow
	 *   TRUE to keep the copy in the tier below, making this a replica rather than a destination.
	 *
	 * @throws InvalidArgumentException
	 *   When the index or the age is negative, or the nearest tier is given a non-zero age.
	 */
	public function __construct(
		public readonly int $index,
		public readonly TierTarget $target,
		public readonly int $fromAge = 0,
		public readonly bool $retainBelow = false,
	) {
		if ($index < 0) {
			throw new InvalidArgumentException('A tier index cannot be negative');
		}
		if ($fromAge < 0) {
			throw new InvalidArgumentException(
				sprintf('Tier "%s" has a negative age threshold', $target->name),
			);
		}
		if ($index === 0 && $fromAge !== 0) {
			throw new InvalidArgumentException(
				'The nearest tier holds everything, so its age threshold is zero',
			);
		}
	}

	/**
	 * Whether this is the tier writes land in.
	 *
	 * @return bool
	 *   TRUE for tier zero.
	 */
	public function isNearest(): bool
	{
		return $this->index === 0;
	}

	/**
	 * This tier's name.
	 *
	 * @return string
	 *   The target's name, which is also what per-tier request counts are filed under.
	 */
	public function name(): string
	{
		return $this->target->name;
	}

	/**
	 * Whether an object of a given age has aged into this tier.
	 *
	 * Says nothing about whether a colder tier also claims it; TierMap::forAge() answers that by
	 * taking the coldest tier that says yes.
	 *
	 * @param int $age
	 *   Seconds since the object was last written.
	 *
	 * @return bool
	 *   TRUE when the age has reached this tier's threshold.
	 */
	public function accepts(int $age): bool
	{
		return $age >= $this->fromAge;
	}

	/**
	 * Builds a tier from one settings row.
	 *
	 * @param array<string, mixed> $row
	 *   One entry of the `tiers.levels` setting.
	 * @param int $index
	 *   Position on the ladder.
	 *
	 * @return self
	 *   The tier.
	 *
	 * @throws InvalidArgumentException
	 *   When the row names no provider, or gives the nearest tier an age threshold.
	 */
	public static function fromSettings(array $row, int $index): self
	{
		return new self(
			$index,
			TierTarget::fromSettings($row, $index),
			$index === 0 ? 0 : max(0, (int) ($row['from_age'] ?? 0)),
			(bool) ($row['retain_below'] ?? false),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The tier as a plain array for a report table.
	 */
	public function jsonSerialize(): array
	{
		return [
			'index' => $this->index,
			'name' => $this->target->name,
			'provider' => $this->target->provider,
			'location' => $this->target->location,
			'storageClass' => $this->target->storageClass,
			'fromAge' => $this->fromAge,
			'retainBelow' => $this->retainBelow,
		];
	}
}
