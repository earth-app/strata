<?php

declare(strict_types=1);

namespace Drupal\strata_azure;

use Drupal\strata\Storage\ObjectKeys;
use InvalidArgumentException;

/**
 * Where one Azure blob container lives and how to address it.
 *
 * Three things vary between deployments and none can be inferred from the others: the account, the
 * container, and the DNS suffix the service answers on. The suffix is `core.windows.net` on the
 * public cloud, something else in a sovereign cloud, and nothing at all under Azurite, which serves
 * every account from one host and puts the account in the path instead. A full service URL therefore
 * overrides the suffix rather than being derived from it.
 *
 * The key prefix keeps a container shareable. The engine passes `_strata/<site-id>/`, so two sites
 * can back up into one container and neither sees the other's blobs in a listing.
 *
 * @see AzureStorageProvider
 */
final class AzureEndpoint
{
	/**
	 * DNS suffix the public cloud answers on.
	 */
	public const PUBLIC_SUFFIX = 'core.windows.net';

	/**
	 * Where keys sit inside the container.
	 */
	public readonly ObjectKeys $keys;

	/**
	 * Constructs an endpoint.
	 *
	 * @param string $account
	 *   Storage account name.
	 * @param string $container
	 *   Container name. Strata never creates it, so it has to exist.
	 * @param string $endpointSuffix
	 *   DNS suffix the blob service answers on, such as `core.windows.net` or `core.chinacloudapi.cn`.
	 * @param string|null $serviceUrl
	 *   Full base URL of the blob service, such as `http://127.0.0.1:10000/devstoreaccount1` for
	 *   Azurite, or NULL to build one from the account and the suffix.
	 * @param string $keyPrefix
	 *   Prefix every key sits under, normalised to end in a slash.
	 * @param string|null $accessTier
	 *   Access tier to write blobs with, or NULL for the container's default.
	 * @param int|null $blockThreshold
	 *   Bytes above which a blob is uploaded as blocks, or NULL to use blocks only when one Put Blob
	 *   cannot carry the body. Lowering it costs more requests and buys retry granularity.
	 *
	 * @throws InvalidArgumentException
	 *   When the account, the container or the suffix is empty, the service URL names no host, or
	 *   the threshold is not positive.
	 */
	public function __construct(
		public readonly string $account,
		public readonly string $container,
		public readonly string $endpointSuffix = self::PUBLIC_SUFFIX,
		public readonly ?string $serviceUrl = null,
		string $keyPrefix = '',
		public readonly ?string $accessTier = null,
		public readonly ?int $blockThreshold = null,
	) {
		if (trim($account) === '') {
			throw new InvalidArgumentException('An endpoint needs a storage account name');
		}
		if (trim($container) === '') {
			throw new InvalidArgumentException('An endpoint needs a container name');
		}
		if ($serviceUrl === null && trim($endpointSuffix) === '') {
			throw new InvalidArgumentException('An endpoint needs a dns suffix or a service url');
		}
		if ($serviceUrl !== null && parse_url($serviceUrl, PHP_URL_HOST) === null) {
			throw new InvalidArgumentException(
				sprintf('The service url "%s" names no host', $serviceUrl),
			);
		}
		if ($blockThreshold !== null && $blockThreshold < 1) {
			throw new InvalidArgumentException('A block threshold must be at least one byte');
		}

		$this->keys = new ObjectKeys($keyPrefix);
	}

	/**
	 * The base URL of the blob service.
	 *
	 * @return string
	 *   The configured service URL with any trailing slash removed, or one built from the account
	 *   and the suffix.
	 */
	public function baseUrl(): string
	{
		if ($this->serviceUrl !== null) {
			return rtrim($this->serviceUrl, '/');
		}

		return sprintf('https://%s.blob.%s', $this->account, trim($this->endpointSuffix, '/'));
	}

	/**
	 * The URL the container itself is addressed at.
	 *
	 * @return string
	 *   The container URL, with no query string.
	 */
	public function containerUrl(): string
	{
		return $this->baseUrl() . '/' . rawurlencode($this->container);
	}

	/**
	 * The URL one blob is addressed at.
	 *
	 * @param string $key
	 *   Blob name, already carrying whatever prefix applies.
	 *
	 * @return string
	 *   An absolute URL whose path segments are percent-encoded.
	 */
	public function blobUrl(string $key): string
	{
		$segments = array_map(
			static fn(string $segment): string => rawurlencode($segment),
			explode('/', trim($key, '/')),
		);

		return $this->containerUrl() . '/' . implode('/', $segments);
	}

	/**
	 * The host the service is reached at.
	 *
	 * @return string
	 *   The host, for an error message that has to say where a request went.
	 */
	public function host(): string
	{
		return (string) (parse_url($this->baseUrl(), PHP_URL_HOST) ?: $this->baseUrl());
	}

	/**
	 * Whether this endpoint is a local emulator rather than a real cloud.
	 *
	 * Azurite serves every account from one host and puts the account name in the path, which is
	 * exactly what a configured service URL describes.
	 *
	 * @return bool
	 *   TRUE when a service URL was configured instead of an account host.
	 */
	public function isEmulated(): bool
	{
		return $this->serviceUrl !== null;
	}
}
