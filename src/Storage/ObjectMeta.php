<?php

declare(strict_types=1);

namespace Drupal\strata\Storage;

use JsonSerializable;

/**
 * What a store knows about one object without reading it.
 *
 * @see StorageProviderInterface
 */
final class ObjectMeta implements JsonSerializable
{
	/**
	 * Constructs object metadata.
	 *
	 * @param string $key
	 *   The object key, relative to the store root.
	 * @param int $size
	 *   Size in bytes.
	 * @param string|null $etag
	 *   The endpoint's entity tag, stored exactly as it was returned. Quotes are part of the value
	 *   on several providers, and R2 rejects a CompleteMultipartUpload whose part tags were
	 *   normalised, so it is never trimmed.
	 * @param int|null $modified
	 *   Unix timestamp of the last write, or NULL when the endpoint does not report one.
	 * @param string|null $storageClass
	 *   The tier the object sits in, or NULL.
	 * @param array<string, string> $metadata
	 *   User metadata the object carries.
	 */
	public function __construct(
		public readonly string $key,
		public readonly int $size,
		public readonly ?string $etag = null,
		public readonly ?int $modified = null,
		public readonly ?string $storageClass = null,
		public readonly array $metadata = [],
	) {}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The metadata as a plain array.
	 */
	public function jsonSerialize(): array
	{
		return [
			'key' => $this->key,
			'size' => $this->size,
			'etag' => $this->etag,
			'modified' => $this->modified,
			'storageClass' => $this->storageClass,
			'metadata' => $this->metadata,
		];
	}
}
