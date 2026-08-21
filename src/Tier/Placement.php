<?php

declare(strict_types=1);

namespace Drupal\strata\Tier;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Which tiers hold one object.
 *
 * A set rather than a single value, because a replica tier and its neighbour hold the same key and
 * both answers are true. One tier means the object moved; several mean it was copied.
 *
 * This is a cache over the buckets, not the record. The truth is which bucket the key is actually
 * in, and TieredProvider falls back to probing when there is no row here, so a lost table costs one
 * extra request per miss rather than a lost object. TierPlacementRebuilder puts it back by listing
 * each tier, which is what makes it safe for an uninstall to drop the table.
 *
 * @see PlacementIndexInterface
 * @see TieredProvider
 */
final class Placement implements JsonSerializable
{
	/**
	 * Tier indexes holding this object, ascending and unique.
	 *
	 * @var list<int>
	 */
	private readonly array $tiers;

	/**
	 * Constructs a placement.
	 *
	 * @param string $key
	 *   The object key, exactly as the bucket holds it.
	 * @param list<int> $tiers
	 *   Tier indexes. Sorted and deduplicated, so two callers that learned the same tiers in a
	 *   different order produce the same record.
	 * @param int $bytes
	 *   The object's size, for the per-tier totals a report shows.
	 * @param int $recorded
	 *   Unix timestamp this placement was last written.
	 *
	 * @throws InvalidArgumentException
	 *   When the key is empty, a tier index is negative, or the size is negative.
	 */
	public function __construct(
		public readonly string $key,
		array $tiers = [],
		public readonly int $bytes = 0,
		public readonly int $recorded = 0,
	) {
		if (trim($key) === '') {
			throw new InvalidArgumentException('A placement needs an object key');
		}
		if ($bytes < 0) {
			throw new InvalidArgumentException('A placed object cannot have a negative size');
		}

		$unique = [];

		foreach ($tiers as $tier) {
			if ($tier < 0) {
				throw new InvalidArgumentException('A tier index cannot be negative');
			}

			$unique[$tier] = true;
		}

		$indexes = array_keys($unique);
		sort($indexes);

		$this->tiers = $indexes;
	}

	#region Reading

	/**
	 * Every tier holding this object.
	 *
	 * @return list<int>
	 *   Tier indexes, nearest first.
	 */
	public function tiers(): array
	{
		return $this->tiers;
	}

	/**
	 * The nearest tier holding it.
	 *
	 * @return int|null
	 *   The index, or NULL when nothing holds it.
	 */
	public function nearest(): ?int
	{
		return $this->tiers[0] ?? null;
	}

	/**
	 * The furthest tier holding it.
	 *
	 * @return int|null
	 *   The index, or NULL when nothing holds it.
	 */
	public function coldest(): ?int
	{
		return $this->tiers === [] ? null : $this->tiers[count($this->tiers) - 1];
	}

	/**
	 * Whether one tier holds it.
	 *
	 * @param int $tier
	 *   Tier index.
	 *
	 * @return bool
	 *   TRUE when it does.
	 */
	public function has(int $tier): bool
	{
		return in_array($tier, $this->tiers, true);
	}

	/**
	 * Whether more than one tier holds it.
	 *
	 * @return bool
	 *   TRUE when there is a second copy.
	 */
	public function isReplicated(): bool
	{
		return count($this->tiers) > 1;
	}

	/**
	 * Whether nothing holds it.
	 *
	 * A placement with no tiers is not the same as no placement at all: it records that every tier
	 * was asked and none had the object, which is the answer a verify pass needs to report rather
	 * than a gap to fill in.
	 *
	 * @return bool
	 *   TRUE when no tier holds it.
	 */
	public function isEmpty(): bool
	{
		return $this->tiers === [];
	}

	/**
	 * How many tiers hold it.
	 *
	 * @return int
	 *   The count.
	 */
	public function copies(): int
	{
		return count($this->tiers);
	}

	#endregion

	#region Deriving

	/**
	 * The same placement with one more tier.
	 *
	 * @param int $tier
	 *   Tier index to add.
	 * @param int $at
	 *   Unix timestamp to stamp the record with, or zero to keep the existing one.
	 *
	 * @return self
	 *   A new placement.
	 *
	 * @throws InvalidArgumentException
	 *   When the tier index is negative.
	 */
	public function with(int $tier, int $at = 0): self
	{
		return new self(
			$this->key,
			[...$this->tiers, $tier],
			$this->bytes,
			$at > 0 ? $at : $this->recorded,
		);
	}

	/**
	 * The same placement with one tier removed.
	 *
	 * @param int $tier
	 *   Tier index to drop.
	 * @param int $at
	 *   Unix timestamp to stamp the record with, or zero to keep the existing one.
	 *
	 * @return self
	 *   A new placement.
	 */
	public function without(int $tier, int $at = 0): self
	{
		return new self(
			$this->key,
			array_values(array_filter($this->tiers, static fn(int $held): bool => $held !== $tier)),
			$this->bytes,
			$at > 0 ? $at : $this->recorded,
		);
	}

	/**
	 * The same placement with a different size.
	 *
	 * @param int $bytes
	 *   The object's size.
	 *
	 * @return self
	 *   A new placement.
	 *
	 * @throws InvalidArgumentException
	 *   When the size is negative.
	 */
	public function withBytes(int $bytes): self
	{
		return new self($this->key, $this->tiers, $bytes, $this->recorded);
	}

	#endregion

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The placement as a plain array.
	 */
	public function jsonSerialize(): array
	{
		return [
			'key' => $this->key,
			'tiers' => $this->tiers,
			'bytes' => $this->bytes,
			'recorded' => $this->recorded,
		];
	}
}
