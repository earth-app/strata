<?php

declare(strict_types=1);

namespace Drupal\strata_b2;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\strata\Storage\HttpTransport;
use Drupal\strata\Storage\StorageProviderFactoryInterface;
use GuzzleHttp\ClientInterface;
use RuntimeException;

/**
 * Builds the B2 provider from the module's configuration.
 *
 * B2 has no environment credential chain and no instance identity, so the key is configured or the
 * provider refuses to be built. What it does have is application keys scoped to one bucket and one
 * name prefix, which is the credential to give a Drupal site.
 *
 * @see B2StorageProvider
 * @see StorageProviderFactoryInterface
 */
final class B2ProviderFactory implements StorageProviderFactoryInterface
{
	/**
	 * Constructs a factory.
	 *
	 * @param ConfigFactoryInterface $configFactory
	 *   Where the endpoint settings are read from.
	 * @param ClientInterface $httpClient
	 *   The HTTP client requests are carried over.
	 */
	public function __construct(
		private readonly ConfigFactoryInterface $configFactory,
		private readonly ClientInterface $httpClient,
	) {}

	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return 'b2';
	}

	/**
	 * Builds a provider from the current configuration.
	 *
	 * @return B2StorageProvider
	 *   The provider.
	 *
	 * @throws RuntimeException
	 *   When the bucket is not named both ways, or the key is missing a half.
	 */
	public function create(): B2StorageProvider
	{
		$settings = $this->configFactory->get('strata.settings');
		$bucketId = trim((string) $settings->get('b2.bucket_id'));
		$bucketName = trim((string) $settings->get('b2.bucket_name'));

		if ($bucketId === '' || $bucketName === '') {
			throw new RuntimeException(
				'The B2 provider is selected but the bucket is not named by both id and name',
			);
		}

		$keyId = trim((string) $settings->get('b2.key_id'));
		$key = trim((string) $settings->get('b2.application_key'));

		if ($keyId === '' || $key === '') {
			throw new RuntimeException(
				'The B2 provider is selected but no key id and application key are configured',
			);
		}

		$api = trim((string) $settings->get('b2.api_url'));
		$threshold = (int) $settings->get('b2.large_file_threshold');

		$endpoint = new B2Endpoint(
			$bucketId,
			$bucketName,
			'_strata/' . trim((string) $settings->get('site_id'), '/'),
			$threshold > 0 ? $threshold : null,
		);

		return new B2StorageProvider(
			new B2Account(
				$keyId,
				$key,
				new HttpTransport($this->httpClient),
				$api === '' ? B2Account::DEFAULT_API : $api,
			),
			$endpoint,
			new HttpTransport($this->httpClient),
		);
	}
}
