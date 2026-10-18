<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\E2E;

use Drupal\strata\Storage\StorageProviderInterface;

/**
 * Damages a store the way a real failure does, through the provider and never the local index.
 *
 * A failing endpoint, a lifecycle rule with the wrong prefix and a disk that flips a bit all act on
 * the objects. None of them can reach Strata's own tables, and the question every tripwire test asks
 * is what Strata can work out from the objects it can still read, so this never touches them.
 * Selection is by sorted key, which makes a failing run repeatable.
 */
final class Fault
{
	/**
	 * Every key under a prefix, in order.
	 *
	 * @param StorageProviderInterface $provider
	 *   The store.
	 * @param string $prefix
	 *   The key prefix.
	 *
	 * @return list<string>
	 *   The keys.
	 */
	public static function keys(StorageProviderInterface $provider, string $prefix): array
	{
		$keys = [];
		$cursor = null;

		do {
			$page = $provider->list($prefix, $cursor, 1000);
			$keys = array_merge($keys, $page->keys());
			$cursor = $page->cursor;
		} while ($cursor !== null);

		sort($keys);

		return $keys;
	}

	/**
	 * Removes objects.
	 *
	 * @param StorageProviderInterface $provider
	 *   The store.
	 * @param list<string> $keys
	 *   What to remove.
	 */
	public static function delete(StorageProviderInterface $provider, array $keys): void
	{
		foreach ($keys as $key) {
			$provider->delete([$key]);
		}
	}

	/**
	 * Flips one byte, which is what a bit error does and leaves the length alone.
	 *
	 * @param StorageProviderInterface $provider
	 *   The store.
	 * @param string $key
	 *   The object.
	 * @param int $offset
	 *   Where to flip, or a negative number to count from the end.
	 */
	public static function flip(
		StorageProviderInterface $provider,
		string $key,
		int $offset = -1,
	): void {
		$body = $provider->get($key);
		$at = $offset < 0 ? strlen($body) + $offset : $offset;
		$body[$at] = chr(ord($body[$at]) ^ 0xff);

		$provider->put($key, $body);
	}

	/**
	 * Cuts an object short, as an interrupted upload leaves it.
	 *
	 * @param StorageProviderInterface $provider
	 *   The store.
	 * @param string $key
	 *   The object.
	 * @param float $keep
	 *   The fraction of the bytes to keep.
	 */
	public static function truncate(
		StorageProviderInterface $provider,
		string $key,
		float $keep = 0.5,
	): void {
		$body = $provider->get($key);

		$provider->put($key, substr($body, 0, max(1, (int) floor(strlen($body) * $keep))));
	}

	/**
	 * Replaces an object with bytes of the same length that are not the object.
	 *
	 * @param StorageProviderInterface $provider
	 *   The store.
	 * @param string $key
	 *   The object.
	 */
	public static function overwrite(StorageProviderInterface $provider, string $key): void
	{
		$provider->put($key, str_repeat('X', max(1, strlen($provider->get($key)))));
	}

	/**
	 * Removes everything under a prefix, which is what an emptied bucket looks like.
	 *
	 * @param StorageProviderInterface $provider
	 *   The store.
	 * @param string $prefix
	 *   The key prefix.
	 *
	 * @return int
	 *   How many objects went.
	 */
	public static function wipe(StorageProviderInterface $provider, string $prefix = ''): int
	{
		$keys = self::keys($provider, $prefix);

		self::delete($provider, $keys);

		return count($keys);
	}
}
