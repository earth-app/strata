<?php

declare(strict_types=1);

namespace Drupal\strata\Storage;

use JsonSerializable;

/**
 * What a store returns after accepting an object.
 *
 * @see StorageProviderInterface
 */
final class PutResult implements JsonSerializable
{
	/**
	 * Constructs a result.
	 *
	 * @param string $key
	 *   The key the object was written to.
	 * @param int $size
	 *   Bytes written.
	 * @param string|null $etag
	 *   The endpoint's entity tag, stored exactly as returned.
	 * @param bool $multipart
	 *   Whether the upload was split into parts.
	 * @param int $parts
	 *   How many parts were sent; 1 for a single put.
	 * @param float $seconds
	 *   Wall-clock time the upload took, for the provider statistics table.
	 */
	public function __construct(
		public readonly string $key,
		public readonly int $size,
		public readonly ?string $etag = null,
		public readonly bool $multipart = false,
		public readonly int $parts = 1,
		public readonly float $seconds = 0.0,
	) {}

	/**
	 * Upload throughput.
	 *
	 * @return float
	 *   Megabytes per second, or 0.0 when the duration was not recorded.
	 */
	public function megabytesPerSecond(): float
	{
		if ($this->seconds <= 0.0) {
			return 0.0;
		}

		return $this->size / 1048576 / $this->seconds;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The result as a plain array.
	 */
	public function jsonSerialize(): array
	{
		return [
			'key' => $this->key,
			'size' => $this->size,
			'etag' => $this->etag,
			'multipart' => $this->multipart,
			'parts' => $this->parts,
			'seconds' => round($this->seconds, 4),
		];
	}
}
