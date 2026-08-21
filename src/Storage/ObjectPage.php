<?php

declare(strict_types=1);

namespace Drupal\strata\Storage;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * One page of a listing.
 *
 * Listings are paged rather than returned whole because a prune or a verify walks the entire store,
 * and a bucket holding a year of history has millions of keys in it.
 *
 * @implements IteratorAggregate<int, ObjectMeta>
 *
 * @see StorageProviderInterface
 */
final class ObjectPage implements Countable, IteratorAggregate
{
	/**
	 * Constructs a page.
	 *
	 * @param list<ObjectMeta> $objects
	 *   The objects on this page, in the order the endpoint returned them.
	 * @param string|null $cursor
	 *   Token for the next page, or NULL when this is the last one.
	 * @param list<string> $prefixes
	 *   Common prefixes, when the listing was delimited.
	 */
	public function __construct(
		public readonly array $objects = [],
		public readonly ?string $cursor = null,
		public readonly array $prefixes = [],
	) {}

	/**
	 * Whether another page follows.
	 *
	 * @return bool
	 *   TRUE when StorageProviderInterface::list() should be called again with this cursor.
	 */
	public function hasMore(): bool
	{
		return $this->cursor !== null;
	}

	/**
	 * Total bytes described by this page.
	 *
	 * @return int
	 *   The sum of every object's size.
	 */
	public function bytes(): int
	{
		$total = 0;

		foreach ($this->objects as $object) {
			$total += $object->size;
		}

		return $total;
	}

	/**
	 * Just the keys.
	 *
	 * @return list<string>
	 *   Object keys, in page order.
	 */
	public function keys(): array
	{
		return array_map(static fn(ObjectMeta $o): string => $o->key, $this->objects);
	}

	/**
	 * {@inheritdoc}
	 */
	public function count(): int
	{
		return count($this->objects);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return Traversable<int, ObjectMeta>
	 *   The objects on this page.
	 */
	public function getIterator(): Traversable
	{
		yield from $this->objects;
	}
}
