<?php

declare(strict_types=1);

namespace Drupal\strata\Storage;

use RuntimeException;

/**
 * Builds a storage provider a submodule contributes.
 *
 * A provider is registered as a factory rather than as an instance because building one reads
 * configuration and can fail: a missing bucket, credentials that do not resolve, an endpoint URL
 * with no host. Deferring that until the provider is actually selected keeps a misconfigured
 * optional provider from taking the container down at compile time.
 *
 * @see StorageProviderManager
 */
interface StorageProviderFactoryInterface
{
	/**
	 * The id the provider registers under, as it appears in configuration.
	 *
	 * @return string
	 *   A short lowercase token such as "s3" or "sftp".
	 */
	public function id(): string;

	/**
	 * Builds the provider.
	 *
	 * @return StorageProviderInterface
	 *   The provider.
	 *
	 * @throws RuntimeException
	 *   When the configuration does not describe a usable endpoint.
	 */
	public function create(): StorageProviderInterface;
}
