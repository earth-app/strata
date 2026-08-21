<?php

declare(strict_types=1);

namespace Drupal\strata_azure;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\strata\Storage\HttpTransport;
use Drupal\strata\Storage\StorageProviderFactoryInterface;
use GuzzleHttp\ClientInterface;
use RuntimeException;

/**
 * Builds the Azure provider from the module's configuration.
 *
 * Nothing is resolved from the environment. Azure's own credential chain is managed identity, which
 * needs an IMDS token exchange and a bearer scheme this provider does not implement, so the account
 * key or the SAS token is configured or the provider refuses to be built.
 *
 * @see AzureStorageProvider
 * @see StorageProviderFactoryInterface
 */
final class AzureProviderFactory implements StorageProviderFactoryInterface
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
		return 'azure';
	}

	/**
	 * Builds a provider from the current configuration.
	 *
	 * @return AzureStorageProvider
	 *   The provider.
	 *
	 * @throws RuntimeException
	 *   When no account or container is configured, or neither credential is.
	 */
	public function create(): AzureStorageProvider
	{
		$settings = $this->configFactory->get('strata.settings');
		$account = trim((string) $settings->get('azure.account'));
		$container = trim((string) $settings->get('azure.container'));

		if ($account === '' || $container === '') {
			throw new RuntimeException(
				'The Azure provider is selected but no account and container are configured',
			);
		}

		$key = trim((string) $settings->get('azure.account_key'));
		$sas = trim((string) $settings->get('azure.sas_token'));

		if ($key === '' && $sas === '') {
			throw new RuntimeException(
				'The Azure provider is selected but neither an account key nor a sas token is set',
			);
		}

		$tier = trim((string) $settings->get('azure.access_tier'));
		$threshold = (int) $settings->get('azure.block_threshold');
		$suffix = trim((string) $settings->get('azure.endpoint_suffix'));
		$service = trim((string) $settings->get('azure.service_url'));

		$endpoint = new AzureEndpoint(
			$account,
			$container,
			$suffix === '' ? AzureEndpoint::PUBLIC_SUFFIX : $suffix,
			$service === '' ? null : $service,
			'_strata/' . trim((string) $settings->get('site_id'), '/'),
			$tier === '' ? null : $tier,
			$threshold > 0 ? $threshold : null,
		);

		return new AzureStorageProvider(
			new AzureCredentials($account, $key, $sas),
			$endpoint,
			new HttpTransport($this->httpClient),
			new SharedKeySigner(),
		);
	}
}
