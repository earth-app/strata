<?php

declare(strict_types=1);

namespace Drupal\strata\Site;

use Drupal\strata\Storage\ByteRange;
use Drupal\strata\Storage\Capabilities;
use Drupal\strata\Storage\ObjectMeta;
use Drupal\strata\Storage\ObjectPage;
use Drupal\strata\Storage\PutResult;
use Drupal\strata\Storage\StorageProviderInterface;

/**
 * Confines one site's reads and writes to its own namespace.
 *
 * Multi-site isolation is enforced here rather than in every caller. The commit log, the segment
 * writer, the tree builder and the ref store all compose their own keys, and asking each of them to
 * remember a site prefix would mean one that forgot became a site reading another's history.
 *
 * Wrapping the provider instead means there is exactly one place the prefix is applied and exactly
 * one place it is stripped, and a listing cannot return a key belonging to another site because the
 * listing itself is prefixed.
 *
 * Frames are NOT confined, and that is the point of sharing a bucket. A frame is addressed by the
 * digest of its content, so two sites storing the same config object store one frame; confining
 * frames per site would turn the saving into duplication. Only the things that describe a site -
 * commits, refs, trees, segments - are namespaced.
 *
 * @see SiteContext
 */
final class SiteScopedProvider implements StorageProviderInterface
{
	/**
	 * Key prefixes shared across every site in a bucket.
	 *
	 * Content, addressed by its own digest. Two sites holding the same bytes hold them once.
	 */
	public const SHARED_PREFIXES = ['frames/', 'packs/', 'media/', 'dicts/'];

	/**
	 * Constructs a scoped provider.
	 *
	 * @param StorageProviderInterface $inner
	 *   The provider being wrapped.
	 * @param SiteContext $site
	 *   Which site's namespace to use.
	 */
	public function __construct(
		private readonly StorageProviderInterface $inner,
		private readonly SiteContext $site,
	) {}

	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return $this->inner->id();
	}

	/**
	 * {@inheritdoc}
	 */
	public function label(): string
	{
		return $this->inner->label();
	}

	/**
	 * {@inheritdoc}
	 */
	public function capabilities(): Capabilities
	{
		return $this->inner->capabilities();
	}

	/**
	 * {@inheritdoc}
	 */
	public function isReachable(): bool
	{
		return $this->inner->isReachable();
	}

	/**
	 * {@inheritdoc}
	 */
	public function unreachableReason(): ?string
	{
		return $this->inner->unreachableReason();
	}

	/**
	 * {@inheritdoc}
	 */
	public function put(string $key, mixed $body, array $options = []): PutResult
	{
		return $this->inner->put($this->scope($key), $body, $options);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get(string $key, ?ByteRange $range = null): string
	{
		return $this->inner->get($this->scope($key), $range);
	}

	/**
	 * {@inheritdoc}
	 */
	public function stream(string $key)
	{
		return $this->inner->stream($this->scope($key));
	}

	/**
	 * {@inheritdoc}
	 */
	public function head(string $key): ?ObjectMeta
	{
		$meta = $this->inner->head($this->scope($key));

		// the caller asked about its own key and must not learn the scoped one
		return $meta === null
			? null
			: new ObjectMeta(
				$key,
				$meta->size,
				$meta->etag,
				$meta->modified,
				$meta->storageClass,
				$meta->metadata,
			);
	}

	/**
	 * {@inheritdoc}
	 */
	public function exists(string $key): bool
	{
		return $this->inner->exists($this->scope($key));
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete(array $keys): int
	{
		return $this->inner->delete(
			array_map(fn(string $key): string => $this->scope($key), $keys),
		);
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
		$page = $this->inner->list($this->scope($prefix), $cursor, $limit, $delimiter);
		$objects = [];

		foreach ($page->objects as $object) {
			$unscoped = $this->unscope($object->key);

			// a key that does not unscope belongs to another site and is not this site's business
			if ($unscoped === null) {
				continue;
			}

			$objects[] = new ObjectMeta(
				$unscoped,
				$object->size,
				$object->etag,
				$object->modified,
				$object->storageClass,
				$object->metadata,
			);
		}

		$prefixes = [];

		foreach ($page->prefixes as $common) {
			$unscoped = $this->unscope($common);

			if ($unscoped !== null) {
				$prefixes[] = $unscoped;
			}
		}

		return new ObjectPage($objects, $page->cursor, $prefixes);
	}

	/**
	 * The provider this wraps.
	 *
	 * @return StorageProviderInterface
	 *   The inner provider, for a caller that genuinely needs the whole bucket - a bucket-wide usage
	 *   report, or a listing of which sites are present.
	 */
	public function unscoped(): StorageProviderInterface
	{
		return $this->inner;
	}

	/**
	 * Whether a key is shared across sites rather than namespaced.
	 *
	 * @param string $key
	 *   The key.
	 *
	 * @return bool
	 *   TRUE when the key addresses content by its own digest.
	 */
	public static function isShared(string $key): bool
	{
		foreach (self::SHARED_PREFIXES as $prefix) {
			if (str_starts_with($key, $prefix)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A key inside this site's namespace, unless it is shared.
	 *
	 * @param string $key
	 *   The key as the caller composed it.
	 *
	 * @return string
	 *   The key to use against the inner provider.
	 */
	private function scope(string $key): string
	{
		return self::isShared($key) ? $key : $this->site->key($key);
	}

	/**
	 * A key as the caller would have composed it.
	 *
	 * @param string $key
	 *   The key from the inner provider.
	 *
	 * @return string|null
	 *   The unscoped key, or NULL when it belongs to a different site.
	 */
	private function unscope(string $key): ?string
	{
		return self::isShared($key) ? $key : $this->site->strip($key);
	}
}
