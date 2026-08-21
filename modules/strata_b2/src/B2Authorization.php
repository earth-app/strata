<?php

declare(strict_types=1);

namespace Drupal\strata_b2;

use InvalidArgumentException;

/**
 * What `b2_authorize_account` answered.
 *
 * Unusually for an object store, B2 will not let a client guess anything: the API host, the download
 * host, the smallest part it accepts and the size it would rather have are all told to the client at
 * authorization time and all of them differ between accounts. Hard-coding any of them works until a
 * bucket lands in a different cluster.
 *
 * The response also says what the key is allowed to do. An application key scoped to one bucket and
 * one name prefix is the reason to use the native API at all, since the S3 gateway cannot use one.
 *
 * @see B2Account
 * @see B2StorageProvider
 */
final class B2Authorization
{
	/**
	 * Smallest part B2 has ever accepted, used when the response does not say.
	 */
	public const FALLBACK_MINIMUM_PART = 5_000_000;

	/**
	 * Constructs an authorization.
	 *
	 * @param string $apiUrl
	 *   Base URL every API call goes to.
	 * @param string $token
	 *   The account authorization token, which every call but an upload carries.
	 * @param string $downloadUrl
	 *   Base URL object bytes are read from.
	 * @param int $recommendedPartSize
	 *   Part size B2 would rather receive.
	 * @param int $minimumPartSize
	 *   Smallest part except the last, which B2 refuses to go below.
	 * @param list<string> $capabilities
	 *   What the key is allowed to do.
	 * @param string $bucketId
	 *   The one bucket the key is restricted to, or an empty string when it is not restricted.
	 * @param string $namePrefix
	 *   The name prefix the key is restricted to, or an empty string.
	 *
	 * @throws InvalidArgumentException
	 *   When either URL is empty, which would make every subsequent call address nothing.
	 */
	public function __construct(
		public readonly string $apiUrl,
		public readonly string $token,
		public readonly string $downloadUrl,
		public readonly int $recommendedPartSize = self::FALLBACK_MINIMUM_PART,
		public readonly int $minimumPartSize = self::FALLBACK_MINIMUM_PART,
		public readonly array $capabilities = [],
		public readonly string $bucketId = '',
		public readonly string $namePrefix = '',
	) {
		if (trim($apiUrl) === '' || trim($downloadUrl) === '') {
			throw new InvalidArgumentException(
				'An authorization needs both an api url and a download url',
			);
		}
	}

	/**
	 * Reads an authorization out of the response body.
	 *
	 * Version 2 of the API answers flat and version 3 nests the same fields under
	 * `apiInfo.storageApi`, so both shapes are read and neither is guessed at from a version number.
	 *
	 * @param array<mixed> $parsed
	 *   The parsed response.
	 *
	 * @return self
	 *   The authorization.
	 *
	 * @throws InvalidArgumentException
	 *   When the response names no api url or no download url.
	 */
	public static function fromResponse(array $parsed): self
	{
		$info = $parsed['apiInfo'] ?? null;
		$storage = is_array($info) ? $info['storageApi'] ?? null : null;
		$storage = is_array($storage) ? $storage : [];
		$allowed = $parsed['allowed'] ?? null;
		$allowed = is_array($allowed) ? $allowed : $storage;

		$pick = static fn(string $name): mixed => $parsed[$name] ?? ($storage[$name] ?? null);
		$capabilities = $allowed['capabilities'] ?? [];

		return new self(
			rtrim((string) $pick('apiUrl'), '/'),
			(string) ($parsed['authorizationToken'] ?? ''),
			rtrim((string) $pick('downloadUrl'), '/'),
			max(1, (int) ($pick('recommendedPartSize') ?? self::FALLBACK_MINIMUM_PART)),
			max(1, (int) ($pick('absoluteMinimumPartSize') ?? self::FALLBACK_MINIMUM_PART)),
			array_values(
				array_map(
					static fn(mixed $one): string => (string) $one,
					is_array($capabilities) ? $capabilities : [],
				),
			),
			(string) ($allowed['bucketId'] ?? ''),
			(string) ($allowed['namePrefix'] ?? ''),
		);
	}

	/**
	 * The URL one API call is made at.
	 *
	 * @param string $name
	 *   The call, such as `b2_get_upload_url`.
	 * @param string $version
	 *   The API version segment.
	 *
	 * @return string
	 *   The absolute URL.
	 */
	public function endpoint(string $name, string $version = B2Account::API_VERSION): string
	{
		return sprintf('%s/b2api/%s/%s', $this->apiUrl, $version, $name);
	}

	/**
	 * The URL one object's bytes are read from.
	 *
	 * @param string $bucket
	 *   Bucket name, which the download path uses rather than the bucket id.
	 * @param string $key
	 *   Object name, already carrying whatever prefix applies.
	 *
	 * @return string
	 *   The absolute URL, with each path segment percent-encoded.
	 */
	public function fileUrl(string $bucket, string $key): string
	{
		$segments = array_map(
			static fn(string $segment): string => rawurlencode($segment),
			explode('/', trim($key, '/')),
		);

		return sprintf(
			'%s/file/%s/%s',
			$this->downloadUrl,
			rawurlencode($bucket),
			implode('/', $segments),
		);
	}

	/**
	 * Whether the key is allowed to do something.
	 *
	 * @param string $capability
	 *   A capability name such as `writeFiles`.
	 *
	 * @return bool
	 *   TRUE when the key carries it, or when the response listed none at all, since a response
	 *   that says nothing about capabilities is not evidence that one is missing.
	 */
	public function allows(string $capability): bool
	{
		return $this->capabilities === [] || in_array($capability, $this->capabilities, true);
	}

	/**
	 * Whether the key is confined to one bucket.
	 *
	 * @return bool
	 *   TRUE when the key names a bucket it may not leave.
	 */
	public function isRestricted(): bool
	{
		return $this->bucketId !== '';
	}
}
