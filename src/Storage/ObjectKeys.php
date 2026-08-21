<?php

declare(strict_types=1);

namespace Drupal\strata\Storage;

use InvalidArgumentException;

/**
 * The key namespace one store writes inside.
 *
 * Every key the engine names is relative to the store root, and the provider puts it under
 * `_strata/<site-id>/` so a bucket or a container can hold two sites and neither sees the other in a
 * listing. The prefix goes on the way out and comes off the way back, in one place, because a
 * provider that adds it in put() and forgets it in list() reports keys nothing can read.
 *
 * A key arrives from a manifest and a manifest can be tampered with, so `..`, `.` and empty segments
 * are refused by name rather than normalised away.
 *
 * @see StorageProviderInterface
 */
final class ObjectKeys
{
	/**
	 * Suffix that marks a write in progress rather than an object.
	 *
	 * Reserved because a local staging tier writes `<key>.tmp` while a body is in flight, and a
	 * manifest naming that key would collide with a partial write.
	 */
	public const RESERVED_SUFFIX = '.tmp';

	/**
	 * The prefix with no leading slash and exactly one trailing slash, or an empty string.
	 */
	public readonly string $prefix;

	/**
	 * Constructs a key namespace.
	 *
	 * @param string $prefix
	 *   Prefix every key sits under, normalised to end in a slash. An empty string writes at the
	 *   root of the store.
	 */
	public function __construct(string $prefix = '')
	{
		$trimmed = trim($prefix, '/');
		$this->prefix = $trimmed === '' ? '' : $trimmed . '/';
	}

	/**
	 * Turns a caller's key into the key the endpoint sees.
	 *
	 * @param string $key
	 *   Object key relative to the store root.
	 *
	 * @return string
	 *   The key with ObjectKeys::$prefix in front of it.
	 *
	 * @throws InvalidArgumentException
	 *   When the key is empty, contains a traversal or empty segment or a null byte, or carries the
	 *   suffix reserved for a write in progress.
	 */
	public function resolve(string $key): string
	{
		$key = $this->normalize($key);

		if ($key === '') {
			throw new InvalidArgumentException('An object key cannot be empty');
		}
		if (str_contains($key, "\0")) {
			throw new InvalidArgumentException('An object key cannot contain a null byte');
		}

		foreach (explode('/', $key) as $segment) {
			if ($segment === '..' || $segment === '.' || $segment === '') {
				throw new InvalidArgumentException(
					sprintf('Object key "%s" contains a traversal or empty segment', $key),
				);
			}
		}
		if (str_ends_with($key, self::RESERVED_SUFFIX)) {
			throw new InvalidArgumentException(
				sprintf(
					'Object key "%s" ends in the reserved suffix "%s", which marks a write in progress',
					$key,
					self::RESERVED_SUFFIX,
				),
			);
		}

		return $this->prefix . $key;
	}

	/**
	 * A listing prefix, which unlike a key may be empty and names no object.
	 *
	 * @param string $prefix
	 *   Key prefix relative to the store root.
	 *
	 * @return string
	 *   The prefix with ObjectKeys::$prefix in front of it.
	 */
	public function scope(string $prefix): string
	{
		return $this->prefix . ltrim($prefix, '/');
	}

	/**
	 * Strips leading and trailing slashes from a key.
	 *
	 * @param string $key
	 *   The key.
	 *
	 * @return string
	 *   The key with no leading or trailing slash.
	 */
	public function normalize(string $key): string
	{
		return trim($key, '/');
	}

	/**
	 * Removes the store prefix from a key the endpoint reported.
	 *
	 * @param string $key
	 *   A key or common prefix as the endpoint named it.
	 *
	 * @return string
	 *   The key the engine knows it by.
	 */
	public function strip(string $key): string
	{
		return $this->prefix !== '' && str_starts_with($key, $this->prefix)
			? substr($key, strlen($this->prefix))
			: $key;
	}
}
