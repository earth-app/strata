<?php

declare(strict_types=1);

namespace Drupal\strata_gcs;

use Drupal\strata\Storage\ObjectKeys;
use InvalidArgumentException;

/**
 * Where one Cloud Storage bucket lives and how to address it.
 *
 * The JSON API splits an object across three URLs and they are not interchangeable: metadata and
 * listings come from `/storage/v1`, bytes come from the same path with `?alt=media`, and writes go
 * to a different host path, `/upload/storage/v1`. A write sent to the metadata path is not an upload
 * at all, so each of the three has its own method here rather than one URL with a parameter.
 *
 * An object name goes in a path segment and is percent-encoded whole, slashes included. A bucket is
 * flat, so `frames/ab/cd` is one name containing slashes rather than a path, and leaving those
 * slashes unencoded addresses a resource that does not exist.
 *
 * The key prefix keeps a bucket shareable. The engine passes `_strata/<site-id>/`, so two sites can
 * back up into one bucket and neither sees the other's objects in a listing.
 *
 * @see GcsStorageProvider
 */
final class GcsEndpoint
{
	/**
	 * The public API host.
	 */
	public const PUBLIC_API = 'https://storage.googleapis.com';

	/**
	 * Smallest unit a resumable chunk may be a multiple of, in bytes.
	 */
	public const CHUNK_ALIGNMENT = 262_144;

	/**
	 * Where keys sit inside the bucket.
	 */
	public readonly ObjectKeys $keys;

	/**
	 * Constructs an endpoint.
	 *
	 * @param string $bucket
	 *   Bucket name. Strata never creates it, so it has to exist.
	 * @param string $keyPrefix
	 *   Prefix every key sits under, normalised to end in a slash.
	 * @param string|null $apiUrl
	 *   Base URL of the API, or NULL for the public one. A fake server in a test and a private
	 *   service endpoint are the two reasons to set it.
	 * @param string|null $storageClass
	 *   Storage class to write objects with, or NULL for the bucket's default.
	 * @param int|null $resumableThreshold
	 *   Bytes above which an object is uploaded in a resumable session, or NULL to use one only
	 *   when a single request cannot carry the body.
	 *
	 * @throws InvalidArgumentException
	 *   When the bucket is empty, the API URL names no host, or the threshold is not positive.
	 */
	public function __construct(
		public readonly string $bucket,
		string $keyPrefix = '',
		public readonly ?string $apiUrl = null,
		public readonly ?string $storageClass = null,
		public readonly ?int $resumableThreshold = null,
	) {
		if (trim($bucket) === '') {
			throw new InvalidArgumentException('An endpoint needs a bucket name');
		}
		if ($apiUrl !== null && parse_url($apiUrl, PHP_URL_HOST) === null) {
			throw new InvalidArgumentException(sprintf('The api url "%s" names no host', $apiUrl));
		}
		if ($resumableThreshold !== null && $resumableThreshold < 1) {
			throw new InvalidArgumentException('A resumable threshold must be at least one byte');
		}

		$this->keys = new ObjectKeys($keyPrefix);
	}

	/**
	 * The base URL of the API.
	 *
	 * @return string
	 *   The configured URL with any trailing slash removed, or the public one.
	 */
	public function baseUrl(): string
	{
		return rtrim($this->apiUrl ?? self::PUBLIC_API, '/');
	}

	/**
	 * The URL one object's metadata is addressed at.
	 *
	 * @param string $key
	 *   Object name, already carrying whatever prefix applies.
	 *
	 * @return string
	 *   The absolute URL, with the name percent-encoded whole.
	 */
	public function objectUrl(string $key): string
	{
		return $this->collectionUrl() . '/' . rawurlencode(trim($key, '/'));
	}

	/**
	 * The URL one object's bytes are read from.
	 *
	 * @param string $key
	 *   Object name, already carrying whatever prefix applies.
	 *
	 * @return string
	 *   The absolute URL, already carrying the media parameter.
	 */
	public function mediaUrl(string $key): string
	{
		return $this->objectUrl($key) . '?alt=media';
	}

	/**
	 * The URL objects are listed at.
	 *
	 * @return string
	 *   The absolute URL, with no query string.
	 */
	public function collectionUrl(): string
	{
		return sprintf('%s/storage/v1/b/%s/o', $this->baseUrl(), rawurlencode($this->bucket));
	}

	/**
	 * The URL objects are written to.
	 *
	 * @return string
	 *   The absolute URL, with no query string.
	 */
	public function uploadUrl(): string
	{
		return sprintf(
			'%s/upload/storage/v1/b/%s/o',
			$this->baseUrl(),
			rawurlencode($this->bucket),
		);
	}

	/**
	 * The host the API is reached at.
	 *
	 * @return string
	 *   The host, for an error message that has to say where a request went.
	 */
	public function host(): string
	{
		return (string) (parse_url($this->baseUrl(), PHP_URL_HOST) ?: $this->baseUrl());
	}
}
