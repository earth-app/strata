<?php

declare(strict_types=1);

namespace Drupal\strata_s3;

use InvalidArgumentException;

/**
 * Where one S3-compatible bucket lives and how to address it.
 *
 * Four things vary between endpoints and none of them can be inferred from the others: the URL, the
 * addressing style, whether the checksum header family is accepted, and whether a storage class
 * means anything. AWS takes a virtual-host address; MinIO and Ceph are usually deployed path-style;
 * R2 takes either but rejects `x-amz-checksum-*`. Each is a setting rather than a guess.
 *
 * The key prefix keeps a bucket shareable. The engine passes `_strata/<site-id>/`, so two sites can
 * back up into one bucket and neither sees the other's objects in a listing.
 *
 * @see S3StorageProvider
 */
final class S3Endpoint
{
	/**
	 * Host fragment that identifies a Cloudflare R2 endpoint.
	 */
	public const R2_HOST = 'r2.cloudflarestorage.com';

	/**
	 * Region R2 signs every request in, whatever jurisdiction the bucket sits in.
	 */
	public const R2_REGION = 'auto';

	/**
	 * Key prefix with no leading slash and exactly one trailing slash, or an empty string.
	 */
	public readonly string $keyPrefix;

	/**
	 * Constructs an endpoint.
	 *
	 * @param string $bucket
	 *   Bucket name.
	 * @param string $region
	 *   Region to sign in. R2 uses S3Endpoint::R2_REGION.
	 * @param string|null $endpointUrl
	 *   Base URL of a non-AWS endpoint, such as `https://<account>.r2.cloudflarestorage.com`, or
	 *   NULL to address AWS directly.
	 * @param bool $pathStyle
	 *   Whether to put the bucket in the path rather than in the host. Required by MinIO and by any
	 *   endpoint whose TLS certificate does not cover a bucket subdomain.
	 * @param string $keyPrefix
	 *   Prefix every key sits under, normalised to end in a slash.
	 * @param bool $sendChecksums
	 *   Whether to send the `x-amz-checksum-*` family. Several S3-compatible services reject them
	 *   outright, so the default is off and AWS turns it on.
	 * @param string|null $storageClass
	 *   Storage class to write objects with, or NULL for the endpoint's default.
	 * @param int|null $multipartThreshold
	 *   Bytes above which an object is uploaded in parts, or NULL to split only when a single
	 *   request cannot carry it. Lowering it costs more requests and buys retry granularity: a
	 *   failed part is retried alone rather than restarting the whole object. Every AWS SDK exposes
	 *   the same dial. It can only lower the threshold, never raise it past what one request takes.
	 *
	 * @throws InvalidArgumentException
	 *   When the bucket or the region is empty, or the endpoint URL names no host.
	 */
	public function __construct(
		public readonly string $bucket,
		public readonly string $region = 'us-east-1',
		public readonly ?string $endpointUrl = null,
		public readonly bool $pathStyle = false,
		string $keyPrefix = '',
		public readonly bool $sendChecksums = false,
		public readonly ?string $storageClass = null,
		public readonly ?int $multipartThreshold = null,
	) {
		if (trim($bucket) === '') {
			throw new InvalidArgumentException('An endpoint needs a bucket name');
		}
		if (trim($region) === '') {
			throw new InvalidArgumentException('An endpoint needs a region');
		}
		if ($endpointUrl !== null && parse_url($endpointUrl, PHP_URL_HOST) === null) {
			throw new InvalidArgumentException(
				sprintf('The endpoint url "%s" names no host', $endpointUrl),
			);
		}
		if ($multipartThreshold !== null && $multipartThreshold < 1) {
			throw new InvalidArgumentException('A multipart threshold must be at least one byte');
		}

		$trimmed = trim($keyPrefix, '/');
		$this->keyPrefix = $trimmed === '' ? '' : $trimmed . '/';
	}

	/**
	 * Whether this endpoint is Cloudflare R2.
	 *
	 * R2 differs from AWS in two ways the engine has to know about: every multipart part except the
	 * last must be exactly the same size, and the checksum header family is rejected. Both are
	 * decided from the host rather than probed, because a probe for either costs a failed upload.
	 *
	 * @return bool
	 *   TRUE when the endpoint URL points at R2.
	 */
	public function isR2(): bool
	{
		return $this->endpointUrl !== null &&
			str_contains(strtolower($this->endpointUrl), self::R2_HOST);
	}

	/**
	 * The host a bucket is addressed at.
	 *
	 * @param string $bucket
	 *   Bucket name.
	 *
	 * @return string
	 *   The host, with its port when the endpoint URL carries a non-default one, and with the bucket
	 *   prepended when the endpoint is addressed virtual-host style.
	 */
	public function hostFor(string $bucket): string
	{
		$host =
			$this->endpointUrl === null
				? sprintf('s3.%s.amazonaws.com', $this->region)
				: $this->authority($this->endpointUrl);

		return $this->pathStyle || trim($bucket) === '' ? $host : $bucket . '.' . $host;
	}

	/**
	 * The absolute URL one key is addressed at.
	 *
	 * @param string $key
	 *   Object key, already carrying whatever prefix applies. An empty key addresses the bucket
	 *   itself, which is what a listing and a batch delete are issued against.
	 *
	 * @return string
	 *   An absolute URL whose path segments are percent-encoded.
	 */
	public function urlFor(string $key): string
	{
		$path = $this->pathStyle ? '/' . rawurlencode($this->bucket) : '';
		$key = trim($key, '/');

		if ($key !== '') {
			$segments = array_map(
				static fn(string $segment): string => rawurlencode($segment),
				explode('/', $key),
			);
			$path .= '/' . implode('/', $segments);
		} else {
			$path .= '/';
		}

		return $this->scheme() . '://' . $this->hostFor($this->bucket) . $path;
	}

	/**
	 * The scheme the endpoint is reached over.
	 *
	 * @return string
	 *   Either `http` or `https`, defaulting to `https` when the endpoint URL does not say.
	 */
	public function scheme(): string
	{
		if ($this->endpointUrl === null) {
			return 'https';
		}

		$scheme = strtolower((string) (parse_url($this->endpointUrl, PHP_URL_SCHEME) ?: 'https'));

		return $scheme === 'http' ? 'http' : 'https';
	}

	/**
	 * The host and port of a URL.
	 *
	 * @param string $url
	 *   A URL naming a host.
	 *
	 * @return string
	 *   The host, with `:port` appended when the port is not the default for the scheme.
	 */
	private function authority(string $url): string
	{
		$parts = parse_url($url);
		$host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
		$port = is_array($parts) ? $parts['port'] ?? null : null;
		$default = $this->scheme() === 'http' ? 80 : 443;

		return $port === null || (int) $port === $default ? $host : $host . ':' . (int) $port;
	}
}
