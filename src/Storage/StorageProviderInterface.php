<?php

declare(strict_types=1);

namespace Drupal\strata\Storage;

use RuntimeException;

/**
 * Where Strata puts its objects.
 *
 * Implemented for S3 and every S3-compatible endpoint, for Cloudflare R2, for SFTP and FTP, and
 * for the local filesystem. An add-on provider implements this and registers with
 * StorageProviderManager; nothing in the engine branches on a provider class.
 *
 * Every key is relative to the store root, which the provider prefixes with `_strata/<site-id>/`
 * so a bucket can hold other things. Keys never begin with a slash.
 *
 * Two rules bind every implementation:
 *
 * - **Never return partial content.** A short read, a truncated body or an unverified checksum
 *   raises. Downstream code cannot distinguish a truncated frame from a real one.
 * - **Report capabilities honestly through StorageProviderInterface::capabilities().** S3
 *   compatibility varies by endpoint, and the engine branches on the answer rather than on a
 *   provider name.
 *
 * @see Capabilities
 * @see StorageProviderManager
 */
interface StorageProviderInterface
{
	/**
	 * The plugin id this provider registers under.
	 *
	 * @return string
	 *   A short lowercase token such as "s3", "r2", "sftp" or "local".
	 */
	public function id(): string;

	/**
	 * Human-readable name for the settings form.
	 *
	 * @return string
	 *   The label.
	 */
	public function label(): string;

	/**
	 * What this endpoint can do.
	 *
	 * Probed once per endpoint and cached; a probe that cannot run returns conservative defaults
	 * rather than optimistic ones, so an unknown endpoint degrades to more requests instead of to
	 * failed ones.
	 *
	 * @return Capabilities
	 *   The capability set.
	 */
	public function capabilities(): Capabilities;

	/**
	 * Whether the endpoint is reachable and the credentials work.
	 *
	 * Called by hook_requirements() and the settings form, so it must be cheap and must not throw.
	 *
	 * @return bool
	 *   TRUE when a request would succeed right now.
	 */
	public function isReachable(): bool;

	/**
	 * Why the endpoint is unreachable.
	 *
	 * @return string|null
	 *   A short human-readable reason, or NULL when it is reachable.
	 */
	public function unreachableReason(): ?string;

	/**
	 * Writes an object.
	 *
	 * Splits into parts automatically when the body exceeds what the endpoint takes in one request.
	 *
	 * @param string $key
	 *   Object key relative to the store root.
	 * @param string|resource $body
	 *   The bytes, or an open readable stream. A stream is read once and not rewound, so a caller
	 *   that needs it again must seek itself.
	 * @param array<string, mixed> $options
	 *   Provider options. `ifNoneMatch` (write only when the key is absent) is honoured wherever
	 *   Capabilities::$conditionalWrite is true. `metadata`, `contentType` and `storageClass` are
	 *   honoured only by providers whose endpoint carries them; a provider that cannot store an
	 *   option **refuses the write** rather than dropping it, because an object written without the
	 *   metadata its reader expects is indistinguishable from a corrupt one. Nothing in the engine
	 *   depends on user metadata: a stored object carries whatever a reader needs in its own bytes.
	 *
	 * @return PutResult
	 *   What was written.
	 *
	 * @throws RuntimeException
	 *   When the write fails, the body is larger than the endpoint can store, or a conditional
	 *   write was requested on an endpoint that does not honour one.
	 */
	public function put(string $key, mixed $body, array $options = []): PutResult;

	/**
	 * Reads an object.
	 *
	 * @param string $key
	 *   Object key relative to the store root.
	 * @param ByteRange|null $range
	 *   A byte range, or NULL for the whole object.
	 *
	 * @return string
	 *   The bytes.
	 *
	 * @throws RuntimeException
	 *   When the object is absent, the read fails, or fewer bytes arrive than were asked for.
	 */
	public function get(string $key, ?ByteRange $range = null): string;

	/**
	 * Opens an object as a stream.
	 *
	 * For objects too large to hold in memory. A caller that wants bytes should use
	 * StorageProviderInterface::get() instead.
	 *
	 * @param string $key
	 *   Object key relative to the store root.
	 *
	 * @return resource
	 *   An open readable stream the caller must close.
	 *
	 * @throws RuntimeException
	 *   When the object is absent or cannot be opened.
	 */
	public function stream(string $key);

	/**
	 * Metadata for one object without reading it.
	 *
	 * @param string $key
	 *   Object key relative to the store root.
	 *
	 * @return ObjectMeta|null
	 *   The metadata, or NULL when the object is absent. Absence is not an error, because every
	 *   caller of this method is asking precisely in order to find out.
	 */
	public function head(string $key): ?ObjectMeta;

	/**
	 * Whether an object exists.
	 *
	 * @param string $key
	 *   Object key relative to the store root.
	 *
	 * @return bool
	 *   TRUE when it is present.
	 */
	public function exists(string $key): bool;

	/**
	 * Deletes objects.
	 *
	 * Batches when the endpoint supports it. Deleting an absent key is not an error, so a retried
	 * prune is idempotent.
	 *
	 * @param list<string> $keys
	 *   Object keys relative to the store root.
	 *
	 * @return int
	 *   How many keys the endpoint accepted. A store that can tell an absent key from a removed one
	 *   counts only what it removed; S3 reports success for an absent key and cannot distinguish, so
	 *   it counts everything it was given. Do not read this as "how many existed" - a prune receipt
	 *   counts from the frame index, which knows.
	 *
	 * @throws RuntimeException
	 *   When the endpoint refuses the request.
	 */
	public function delete(array $keys): int;

	/**
	 * Lists one page of objects under a prefix.
	 *
	 * @param string $prefix
	 *   Key prefix relative to the store root; an empty string lists everything.
	 * @param string|null $cursor
	 *   Continuation token from a previous page, or NULL to start.
	 * @param int $limit
	 *   Most objects to return in this page.
	 * @param string|null $delimiter
	 *   Grouping delimiter, or NULL for a flat listing.
	 *
	 * @return ObjectPage
	 *   The page.
	 *
	 * @throws RuntimeException
	 *   When the listing fails.
	 */
	public function list(
		string $prefix = '',
		?string $cursor = null,
		int $limit = 1000,
		?string $delimiter = null,
	): ObjectPage;
}
