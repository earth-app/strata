<?php

declare(strict_types=1);

namespace Drupal\strata\Storage\Plugin\Strata\Storage;

use Drupal\strata\Storage\ByteRange;
use Drupal\strata\Storage\Capabilities;
use Drupal\strata\Storage\ObjectMeta;
use Drupal\strata\Storage\ObjectPage;
use Drupal\strata\Storage\PutResult;
use Drupal\strata\Storage\StorageProviderInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Stores objects on the local filesystem.
 *
 * Three real uses: the kernel test lane, which needs a store with no network; a staging tier a site
 * can flush to before a slower upload; and a target for an export a human is going to copy.
 *
 * Writes are atomic. Every object lands in a temporary file in the same directory and is renamed
 * into place, so a crash mid-write leaves either the previous object or none, never a half one. A
 * reader that saw a truncated object would treat it as a corrupt frame and quarantine a commit that
 * is actually intact.
 *
 * @see StorageProviderInterface
 */
final class LocalStorage implements StorageProviderInterface
{
	/**
	 * Directory mode for created directories.
	 */
	private const DIRECTORY_MODE = 0o755;

	/**
	 * Bytes copied per iteration when streaming a body in.
	 */
	private const COPY_CHUNK = 1_048_576;

	/**
	 * Suffix the atomic-write mechanism reserves for a write in progress.
	 *
	 * A file carrying it is a partial write, so it is not an object: it is hidden from listings,
	 * reports absent from head and exists, and cannot be written to directly.
	 */
	private const RESERVED_SUFFIX = '.tmp';

	/**
	 * Absolute path to the store root, with no trailing slash.
	 */
	private readonly string $root;

	/**
	 * Constructs a store.
	 *
	 * @param string $root
	 *   Directory the store lives in. Created on first write if absent.
	 *
	 * @throws InvalidArgumentException
	 *   When the path is empty.
	 */
	public function __construct(string $root)
	{
		if (trim($root) === '') {
			throw new InvalidArgumentException('A local store needs a root directory');
		}

		$this->root = rtrim($root, '/');
	}

	/**
	 * The store root.
	 *
	 * @return string
	 *   Absolute path with no trailing slash.
	 */
	public function root(): string
	{
		return $this->root;
	}

	#region Identity

	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return 'local';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label(): string
	{
		return 'Local Filesystem';
	}

	/**
	 * {@inheritdoc}
	 */
	public function capabilities(): Capabilities
	{
		return Capabilities::local();
	}

	/**
	 * {@inheritdoc}
	 */
	public function isReachable(): bool
	{
		return $this->unreachableReason() === null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function unreachableReason(): ?string
	{
		if (is_dir($this->root)) {
			return is_writable($this->root) ? null : sprintf('%s is not writable', $this->root);
		}

		$parent = dirname($this->root);

		if (!is_dir($parent)) {
			return sprintf('%s does not exist and neither does its parent', $this->root);
		}

		return is_writable($parent)
			? null
			: sprintf('%s does not exist and %s is not writable', $this->root, $parent);
	}

	#endregion

	#region Reading

	/**
	 * {@inheritdoc}
	 */
	public function get(string $key, ?ByteRange $range = null): string
	{
		$path = $this->resolve($key);

		if (!is_file($path)) {
			throw new RuntimeException(sprintf('Object %s is not present', $key));
		}

		if ($range === null) {
			$bytes = @file_get_contents($path);

			if ($bytes === false) {
				throw new RuntimeException(sprintf('Cannot read object %s', $key));
			}

			return $bytes;
		}

		$handle = @fopen($path, 'rb');
		if ($handle === false) {
			throw new RuntimeException(sprintf('Cannot open object %s', $key));
		}

		try {
			if (fseek($handle, $range->offset) !== 0) {
				throw new RuntimeException(
					sprintf('Cannot seek to byte %d of object %s', $range->offset, $key),
				);
			}

			$bytes = '';
			while (strlen($bytes) < $range->length) {
				$block = fread($handle, $range->length - strlen($bytes));

				if ($block === false) {
					throw new RuntimeException(sprintf('Failed reading object %s', $key));
				}
				if ($block === '') {
					break;
				}

				$bytes .= $block;
			}

			// a short range read means the object is smaller than the manifest says it is
			if (strlen($bytes) !== $range->length) {
				throw new RuntimeException(
					sprintf(
						'Object %s returned %d bytes for a %d byte range at offset %d',
						$key,
						strlen($bytes),
						$range->length,
						$range->offset,
					),
				);
			}

			return $bytes;
		} finally {
			fclose($handle);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function stream(string $key)
	{
		$path = $this->resolve($key);

		if (!is_file($path)) {
			throw new RuntimeException(sprintf('Object %s is not present', $key));
		}

		$handle = @fopen($path, 'rb');

		if ($handle === false) {
			throw new RuntimeException(sprintf('Cannot open object %s', $key));
		}

		return $handle;
	}

	/**
	 * {@inheritdoc}
	 */
	public function head(string $key): ?ObjectMeta
	{
		if ($this->isReserved($key)) {
			return null;
		}

		$path = $this->resolve($key);

		if (!is_file($path)) {
			return null;
		}

		$size = filesize($path);
		$modified = filemtime($path);

		if ($size === false) {
			throw new RuntimeException(sprintf('Cannot stat object %s', $key));
		}

		return new ObjectMeta(
			$this->normalize($key),
			$size,
			$this->etag($path, $size),
			$modified === false ? null : $modified,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function exists(string $key): bool
	{
		return !$this->isReserved($key) && is_file($this->resolve($key));
	}

	#endregion

	#region Writing

	/**
	 * {@inheritdoc}
	 */
	public function put(string $key, mixed $body, array $options = []): PutResult
	{
		$path = $this->resolve($key);
		$started = microtime(true);

		// the filesystem has nowhere to keep user metadata, and dropping it would produce an object
		// whose reader cannot tell it apart from a corrupt one
		foreach (['metadata', 'contentType', 'storageClass'] as $unsupported) {
			if (($options[$unsupported] ?? null) !== null) {
				throw new InvalidArgumentException(
					sprintf(
						'A local store cannot record "%s"; store what a reader needs in the object itself',
						$unsupported,
					),
				);
			}
		}

		if (($options['ifNoneMatch'] ?? false) === true && is_file($path)) {
			throw new RuntimeException(
				sprintf(
					'Object %s already exists and the write was conditional on its absence',
					$key,
				),
			);
		}

		$this->makeDirectory(dirname($path));

		$temporary = $path . '.strata-' . bin2hex(random_bytes(8)) . '.tmp';
		$size = 0;

		try {
			if (is_string($body)) {
				$written = @file_put_contents($temporary, $body);
				if ($written === false) {
					throw new RuntimeException(sprintf('Cannot write object %s', $key));
				}
				$size = $written;
			} elseif (is_resource($body)) {
				$size = $this->copyStream($body, $temporary, $key);
			} else {
				throw new InvalidArgumentException(
					'A body must be a string or an open stream resource',
				);
			}

			// rename is atomic within a filesystem, so a reader sees the old object or the new one
			if (!@rename($temporary, $path)) {
				throw new RuntimeException(sprintf('Cannot move object %s into place', $key));
			}
		} catch (RuntimeException | InvalidArgumentException $e) {
			@unlink($temporary);

			throw $e;
		}

		return new PutResult(
			$this->normalize($key),
			$size,
			$this->etag($path, $size),
			false,
			1,
			microtime(true) - $started,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete(array $keys): int
	{
		$deleted = 0;

		foreach ($keys as $key) {
			$path = $this->resolve($key);

			// an absent key is not an error, so a retried prune is idempotent
			if (!is_file($path)) {
				continue;
			}
			if (!@unlink($path)) {
				throw new RuntimeException(sprintf('Cannot delete object %s', $key));
			}

			$deleted++;
			$this->pruneEmptyDirectories(dirname($path));
		}

		return $deleted;
	}

	#endregion

	#region Listing

	/**
	 * {@inheritdoc}
	 */
	public function list(
		string $prefix = '',
		?string $cursor = null,
		int $limit = 1000,
		?string $delimiter = null,
	): ObjectPage {
		if ($limit < 1) {
			throw new InvalidArgumentException('A listing limit must be at least one');
		}

		$prefix = $this->normalize($prefix);
		$keys = $this->walk($this->root, '');
		sort($keys, SORT_STRING);

		$objects = [];
		$prefixes = [];
		$next = null;

		foreach ($keys as $key) {
			if ($prefix !== '' && !str_starts_with($key, $prefix)) {
				continue;
			}
			// the cursor is the last key of the previous page, so the listing is stable under
			// concurrent writes in a way an offset would not be
			if ($cursor !== null && strcmp($key, $cursor) <= 0) {
				continue;
			}

			if ($delimiter !== null && $delimiter !== '') {
				$remainder = substr($key, strlen($prefix));
				$at = strpos($remainder, $delimiter);

				if ($at !== false) {
					$group = $prefix . substr($remainder, 0, $at + strlen($delimiter));
					if (!in_array($group, $prefixes, true)) {
						$prefixes[] = $group;
					}

					continue;
				}
			}

			if (count($objects) >= $limit) {
				$next = $objects[count($objects) - 1]->key;

				break;
			}

			$meta = $this->head($key);
			if ($meta !== null) {
				$objects[] = $meta;
			}
		}

		return new ObjectPage($objects, $next, $prefixes);
	}

	#endregion

	#region Paths

	/**
	 * Turns a key into an absolute path, refusing anything that escapes the root.
	 *
	 * A key arrives from a manifest, and a manifest can be tampered with, so `..` and absolute
	 * paths are rejected by name rather than normalised away.
	 *
	 * @param string $key
	 *   Object key relative to the store root.
	 *
	 * @return string
	 *   Absolute path inside the root.
	 *
	 * @throws InvalidArgumentException
	 *   When the key is empty, absolute, or contains a traversal segment or a null byte.
	 */
	private function resolve(string $key): string
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
		if ($this->isReserved($key)) {
			throw new InvalidArgumentException(
				sprintf(
					'Object key "%s" ends in the reserved suffix "%s", which marks a write in progress',
					$key,
					self::RESERVED_SUFFIX,
				),
			);
		}

		return $this->root . '/' . $key;
	}

	/**
	 * Whether a key names a write in progress rather than an object.
	 *
	 * @param string $key
	 *   The key or a single path segment.
	 *
	 * @return bool
	 *   TRUE when it carries the reserved suffix.
	 */
	private function isReserved(string $key): bool
	{
		return str_ends_with($key, self::RESERVED_SUFFIX);
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
	private function normalize(string $key): string
	{
		return trim($key, '/');
	}

	/**
	 * Creates a directory and its parents.
	 *
	 * @param string $path
	 *   The directory.
	 *
	 * @throws RuntimeException
	 *   When it cannot be created.
	 */
	private function makeDirectory(string $path): void
	{
		if (is_dir($path)) {
			return;
		}
		if (!@mkdir($path, self::DIRECTORY_MODE, true) && !is_dir($path)) {
			throw new RuntimeException(sprintf('Cannot create directory %s', $path));
		}
	}

	/**
	 * Removes directories left empty by a delete, up to the root.
	 *
	 * A sharded store creates 65,536 directories; without this a bucket that has been fully pruned
	 * still carries all of them.
	 *
	 * @param string $path
	 *   The directory to start from.
	 */
	private function pruneEmptyDirectories(string $path): void
	{
		while ($path !== $this->root && str_starts_with($path, $this->root) && is_dir($path)) {
			if ((scandir($path) ?: []) !== ['.', '..']) {
				return;
			}
			if (!@rmdir($path)) {
				return;
			}

			$path = dirname($path);
		}
	}

	/**
	 * Collects every object key under a directory.
	 *
	 * @param string $directory
	 *   Absolute path to walk.
	 * @param string $prefix
	 *   Key prefix accumulated so far.
	 *
	 * @return list<string>
	 *   Object keys relative to the store root.
	 */
	private function walk(string $directory, string $prefix): array
	{
		if (!is_dir($directory)) {
			return [];
		}

		$keys = [];

		foreach (scandir($directory) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$path = $directory . '/' . $entry;
			$key = $prefix === '' ? $entry : $prefix . '/' . $entry;

			if (is_dir($path)) {
				$keys = [...$keys, ...$this->walk($path, $key)];

				continue;
			}
			if ($this->isReserved($entry)) {
				continue;
			}

			$keys[] = $key;
		}

		return $keys;
	}

	/**
	 * Copies a stream into a file.
	 *
	 * @param resource $source
	 *   An open readable stream.
	 * @param string $destination
	 *   Path to write.
	 * @param string $key
	 *   The object key, for error messages.
	 *
	 * @return int
	 *   Bytes written.
	 *
	 * @throws RuntimeException
	 *   When either side fails.
	 */
	private function copyStream($source, string $destination, string $key): int
	{
		$target = @fopen($destination, 'wb');

		if ($target === false) {
			throw new RuntimeException(sprintf('Cannot write object %s', $key));
		}

		try {
			$size = 0;

			while (!feof($source)) {
				$block = fread($source, self::COPY_CHUNK);

				if ($block === false) {
					throw new RuntimeException(sprintf('Failed reading the body for %s', $key));
				}
				if ($block === '') {
					break;
				}
				if (fwrite($target, $block) === false) {
					throw new RuntimeException(sprintf('Failed writing object %s', $key));
				}

				$size += strlen($block);
			}

			return $size;
		} finally {
			fclose($target);
		}
	}

	/**
	 * An entity tag for a stored object.
	 *
	 * Derived from size and modification time rather than content, so heading an object stays a
	 * stat rather than a read. Consumers treat an etag as opaque and compare it only to another
	 * etag from the same store.
	 *
	 * @param string $path
	 *   Absolute path to the object.
	 * @param int $size
	 *   Size in bytes.
	 *
	 * @return string
	 *   The tag.
	 */
	private function etag(string $path, int $size): string
	{
		return sprintf('"%x-%x"', $size, filemtime($path) ?: 0);
	}

	#endregion
}
