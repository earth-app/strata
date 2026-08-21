<?php

declare(strict_types=1);

namespace Drupal\strata\Storage\Plugin\Strata\Storage;

use Drupal\strata\Storage\ByteRange;
use Drupal\strata\Storage\Capabilities;
use Drupal\strata\Storage\ObjectMeta;
use Drupal\strata\Storage\ObjectPage;
use Drupal\strata\Storage\PutResult;
use Drupal\strata\Storage\StorageProviderInterface;
use RuntimeException;

/**
 * Accepts objects and stores nothing, recording what a real store would have been asked to hold.
 *
 * This is what a dry run writes to. An administrator deciding whether to enable a realm can flush
 * against this provider for a day and read the object count, the byte total and the largest object
 * off it, without paying for the storage or the requests.
 *
 * Reads fail. A store that returned an empty string for a frame it never held would let a restore
 * appear to succeed against nothing, so NullStorage::get() raises with the key that was asked for.
 *
 * @see StorageProviderInterface
 */
final class NullStorage implements StorageProviderInterface
{
	/**
	 * Size of every object that was written, keyed by object key.
	 *
	 * @var array<string, int>
	 */
	private array $sizes = [];

	/**
	 * Total bytes accepted.
	 */
	private int $bytes = 0;

	/**
	 * Objects accepted, counting a rewrite of the same key twice.
	 */
	private int $writes = 0;

	/**
	 * Objects deleted.
	 */
	private int $deletes = 0;

	#region Identity

	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return 'null';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label(): string
	{
		return 'Dry Run (Discards Everything)';
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
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function unreachableReason(): ?string
	{
		return null;
	}

	#endregion

	#region Accounting

	/**
	 * What a real store would now be holding.
	 *
	 * @return array{objects: int, bytes: int, writes: int, deletes: int, largest: int}
	 *   Distinct keys currently held, their total size, how many writes and deletes were seen, and
	 *   the largest single object.
	 */
	public function statistics(): array
	{
		return [
			'objects' => count($this->sizes),
			'bytes' => $this->bytes,
			'writes' => $this->writes,
			'deletes' => $this->deletes,
			'largest' => $this->sizes === [] ? 0 : max($this->sizes),
		];
	}

	/**
	 * Forgets everything recorded so far.
	 */
	public function reset(): void
	{
		$this->sizes = [];
		$this->bytes = 0;
		$this->writes = 0;
		$this->deletes = 0;
	}

	#endregion

	#region Operations

	/**
	 * {@inheritdoc}
	 */
	public function put(string $key, mixed $body, array $options = []): PutResult
	{
		$key = trim($key, '/');
		$size = is_string($body) ? strlen($body) : $this->drain($body);

		$this->bytes += $size - ($this->sizes[$key] ?? 0);
		$this->sizes[$key] = $size;
		$this->writes++;

		return new PutResult($key, $size, sprintf('"null-%d"', $size));
	}

	/**
	 * {@inheritdoc}
	 */
	public function get(string $key, ?ByteRange $range = null): string
	{
		throw new RuntimeException(
			sprintf('Object %s cannot be read: this is a dry-run store and holds no content', $key),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function stream(string $key)
	{
		throw new RuntimeException(
			sprintf(
				'Object %s cannot be opened: this is a dry-run store and holds no content',
				$key,
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function head(string $key): ?ObjectMeta
	{
		$key = trim($key, '/');

		if (!array_key_exists($key, $this->sizes)) {
			return null;
		}

		return new ObjectMeta($key, $this->sizes[$key], sprintf('"null-%d"', $this->sizes[$key]));
	}

	/**
	 * {@inheritdoc}
	 */
	public function exists(string $key): bool
	{
		return array_key_exists(trim($key, '/'), $this->sizes);
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete(array $keys): int
	{
		$deleted = 0;

		foreach ($keys as $key) {
			$key = trim($key, '/');

			if (!array_key_exists($key, $this->sizes)) {
				continue;
			}

			$this->bytes -= $this->sizes[$key];
			unset($this->sizes[$key]);
			$deleted++;
			$this->deletes++;
		}

		return $deleted;
	}

	/**
	 * {@inheritdoc}
	 */
	public function list(
		string $prefix = '',
		?string $cursor = null,
		int $limit = 1000,
		?string $delimiter = null,
	): ObjectPage {
		$prefix = trim($prefix, '/');
		$keys = array_keys($this->sizes);
		sort($keys, SORT_STRING);

		$objects = [];
		$next = null;

		foreach ($keys as $key) {
			if ($prefix !== '' && !str_starts_with($key, $prefix)) {
				continue;
			}
			if ($cursor !== null && strcmp($key, $cursor) <= 0) {
				continue;
			}
			if (count($objects) >= $limit) {
				$next = $objects[count($objects) - 1]->key;

				break;
			}

			$objects[] = new ObjectMeta($key, $this->sizes[$key]);
		}

		return new ObjectPage($objects, $next);
	}

	#endregion

	/**
	 * Reads a stream to its end and reports how long it was.
	 *
	 * The bytes are discarded, but the stream is still consumed so a caller that hands over a
	 * temporary file sees the same behaviour it would from a real store.
	 *
	 * @param resource $stream
	 *   An open readable stream.
	 *
	 * @return int
	 *   Bytes read.
	 */
	private function drain($stream): int
	{
		$size = 0;

		while (!feof($stream)) {
			$block = fread($stream, 1_048_576);

			if ($block === false || $block === '') {
				break;
			}

			$size += strlen($block);
		}

		return $size;
	}
}
