<?php

declare(strict_types=1);

namespace Drupal\strata\Storage;

use JsonSerializable;

/**
 * What one storage endpoint can actually do.
 *
 * S3 compatibility is a spectrum. AWS, Cloudflare R2, MinIO, Backblaze B2, Wasabi, Ceph RGW and
 * Garage all speak the same verbs and disagree on the details: whether a batch delete exists,
 * whether conditional writes are honoured, which checksum headers are accepted, whether an object
 * can be listed by version. Assuming a capability that is absent produces a failure at flush time
 * on someone else's infrastructure; assuming its absence gives up throughput on every provider
 * that has it.
 *
 * A provider therefore probes its endpoint once and reports the answers here. Every consumer
 * branches on this object rather than on a provider class name, so an add-on provider is not a
 * special case in the engine.
 *
 * @see StorageProviderInterface
 */
final class Capabilities implements JsonSerializable
{
	/**
	 * Constructs a capability set.
	 *
	 * @param bool $multipart
	 *   Whether uploads can be split into parts. Required for objects above $maxSinglePut.
	 * @param bool $batchDelete
	 *   Whether many keys can be deleted in one request. Pruning is enormously cheaper with it.
	 * @param bool $conditionalWrite
	 *   Whether If-Match and If-None-Match are honoured on PutObject. Used to make a ref update
	 *   safe against a concurrent writer.
	 * @param bool $rangeRead
	 *   Whether a byte range can be requested. Without it a single frame cannot be read out of a
	 *   pack and the whole pack must come down.
	 * @param bool $presign
	 *   Whether a time-limited URL can be generated.
	 * @param bool $checksums
	 *   Whether the endpoint accepts the x-amz-checksum-* family. Several S3-compatible services
	 *   reject them outright, so they are sent only where they are known to work.
	 * @param bool $storageClasses
	 *   Whether an infrequent-access tier can be selected per object.
	 * @param int $maxSinglePut
	 *   Largest object accepted in one PutObject, in bytes.
	 * @param int $minPartSize
	 *   Smallest multipart part except the last, in bytes.
	 * @param int $maxPartSize
	 *   Largest multipart part, in bytes.
	 * @param int $maxParts
	 *   Most parts one multipart upload may have.
	 * @param int $maxBatchDelete
	 *   Most keys one batch delete may name.
	 * @param bool $uniformPartSize
	 *   Whether every part except the last must be exactly the same size. R2 requires this; AWS
	 *   does not.
	 */
	public function __construct(
		public readonly bool $multipart = false,
		public readonly bool $batchDelete = false,
		public readonly bool $conditionalWrite = false,
		public readonly bool $rangeRead = true,
		public readonly bool $presign = false,
		public readonly bool $checksums = false,
		public readonly bool $storageClasses = false,
		public readonly int $maxSinglePut = 5_368_709_120,
		public readonly int $minPartSize = 5_242_880,
		public readonly int $maxPartSize = 5_368_709_120,
		public readonly int $maxParts = 10_000,
		public readonly int $maxBatchDelete = 1_000,
		public readonly bool $uniformPartSize = false,
	) {}

	/**
	 * A capability set for a store with no remote semantics at all.
	 *
	 * What LocalStorage and NullStorage report: everything is a single put, nothing is batched,
	 * and there is no size ceiling worth naming.
	 *
	 * @return self
	 *   The local capability set.
	 */
	public static function local(): self
	{
		return new self(
			multipart: false,
			batchDelete: true,
			conditionalWrite: true,
			rangeRead: true,
			presign: false,
			checksums: false,
			storageClasses: false,
			maxSinglePut: PHP_INT_MAX,
			maxBatchDelete: PHP_INT_MAX,
		);
	}

	/**
	 * The largest object this endpoint can store at all.
	 *
	 * The multipart product is saturated rather than left to overflow: the part limits come from a
	 * probe reading whatever the endpoint said, and `maxPartSize * maxParts` past PHP_INT_MAX becomes
	 * a float, which this return type would reject with a bare TypeError.
	 *
	 * @return int
	 *   Bytes, counting multipart when it is available; PHP_INT_MAX when the product is larger than
	 *   an int can hold, which no object can reach anyway.
	 */
	public function maxObjectSize(): int
	{
		if (!$this->multipart) {
			return $this->maxSinglePut;
		}

		// tested by division rather than by multiplying into a float, which loses precision past 2^53
		if ($this->maxParts > 0 && $this->maxPartSize > intdiv(PHP_INT_MAX, $this->maxParts)) {
			return PHP_INT_MAX;
		}

		return $this->maxPartSize * $this->maxParts;
	}

	/**
	 * Whether an object of a given size can be stored here.
	 *
	 * @param int $bytes
	 *   Object size.
	 *
	 * @return bool
	 *   TRUE when it fits.
	 */
	public function canStore(int $bytes): bool
	{
		return $bytes <= $this->maxObjectSize();
	}

	/**
	 * Whether an object of a given size needs to be uploaded in parts.
	 *
	 * @param int $bytes
	 *   Object size.
	 *
	 * @return bool
	 *   TRUE when a single put will not take it.
	 */
	public function requiresMultipart(int $bytes): bool
	{
		return $bytes > $this->maxSinglePut;
	}

	/**
	 * The part size to use for an object.
	 *
	 * Chooses the smallest part size that keeps the part count within $maxParts, rounded up to a
	 * whole mebibyte so the sizes stay uniform for endpoints that require it.
	 *
	 * @param int $bytes
	 *   Object size.
	 *
	 * @return int
	 *   Part size in bytes, never below $minPartSize or above $maxPartSize.
	 */
	public function partSizeFor(int $bytes): int
	{
		$needed = (int) ceil($bytes / max(1, $this->maxParts));
		$mib = 1_048_576;
		$rounded = (int) (ceil(max($needed, $this->minPartSize) / $mib) * $mib);

		return min($this->maxPartSize, max($this->minPartSize, $rounded));
	}

	/**
	 * How many keys to send per batch delete.
	 *
	 * @return int
	 *   The batch size, or 1 when the endpoint has no batch delete.
	 */
	public function deleteBatchSize(): int
	{
		return $this->batchDelete ? max(1, $this->maxBatchDelete) : 1;
	}

	/**
	 * The same capabilities with individual flags overridden.
	 *
	 * Probing refines an assumed set rather than replacing it, and the object is readonly.
	 *
	 * @param array<string, bool|int> $overrides
	 *   Constructor parameter names keyed to their new values. Unknown names are ignored, so a
	 *   probe written against a newer version does not fatal on an older one.
	 *
	 * @return self
	 *   A new capability set.
	 */
	public function with(array $overrides): self
	{
		$value = fn(string $name): mixed => $overrides[$name] ?? $this->{$name};

		return new self(
			(bool) $value('multipart'),
			(bool) $value('batchDelete'),
			(bool) $value('conditionalWrite'),
			(bool) $value('rangeRead'),
			(bool) $value('presign'),
			(bool) $value('checksums'),
			(bool) $value('storageClasses'),
			(int) $value('maxSinglePut'),
			(int) $value('minPartSize'),
			(int) $value('maxPartSize'),
			(int) $value('maxParts'),
			(int) $value('maxBatchDelete'),
			(bool) $value('uniformPartSize'),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, bool|int>
	 *   The capability set as a plain array for the settings form.
	 */
	public function jsonSerialize(): array
	{
		return [
			'multipart' => $this->multipart,
			'batchDelete' => $this->batchDelete,
			'conditionalWrite' => $this->conditionalWrite,
			'rangeRead' => $this->rangeRead,
			'presign' => $this->presign,
			'checksums' => $this->checksums,
			'storageClasses' => $this->storageClasses,
			'maxSinglePut' => $this->maxSinglePut,
			'minPartSize' => $this->minPartSize,
			'maxPartSize' => $this->maxPartSize,
			'maxParts' => $this->maxParts,
			'maxBatchDelete' => $this->maxBatchDelete,
			'uniformPartSize' => $this->uniformPartSize,
		];
	}
}
