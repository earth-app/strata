<?php

declare(strict_types=1);

namespace Drupal\strata_gcs;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\strata\Storage\HttpTransport;
use Drupal\strata\Storage\StorageProviderFactoryInterface;
use Drupal\strata_gcs\Credentials\AccessTokenProviderInterface;
use Drupal\strata_gcs\Credentials\ServiceAccountCredentials;
use Drupal\strata_gcs\Credentials\StaticAccessToken;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Builds the Cloud Storage provider from the module's configuration.
 *
 * A service account key is tried first, then a pre-issued token, then the `GOOGLE_ACCESS_TOKEN`
 * environment variable, which is what a workload-identity sidecar writes into the environment. A
 * site that pasted a key is therefore not overridden by an environment that happens to carry one.
 *
 * @see GcsStorageProvider
 * @see StorageProviderFactoryInterface
 */
final class GcsProviderFactory implements StorageProviderFactoryInterface
{
	/**
	 * Environment variable a pre-issued token is read from.
	 */
	public const TOKEN_VARIABLE = 'GOOGLE_ACCESS_TOKEN';

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
		return 'gcs';
	}

	/**
	 * Builds a provider from the current configuration.
	 *
	 * @return GcsStorageProvider
	 *   The provider.
	 *
	 * @throws RuntimeException
	 *   When no bucket is configured, or nothing can produce a token.
	 */
	public function create(): GcsStorageProvider
	{
		$settings = $this->configFactory->get('strata.settings');
		$bucket = trim((string) $settings->get('gcs.bucket'));

		if ($bucket === '') {
			throw new RuntimeException('The GCS provider is selected but no bucket is configured');
		}

		$api = trim((string) $settings->get('gcs.api_url'));
		$class = trim((string) $settings->get('gcs.storage_class'));
		$threshold = (int) $settings->get('gcs.resumable_threshold');

		$endpoint = new GcsEndpoint(
			$bucket,
			'_strata/' . trim((string) $settings->get('site_id'), '/'),
			$api === '' ? null : $api,
			$class === '' ? null : $class,
			$threshold > 0 ? $threshold : null,
		);

		return new GcsStorageProvider(
			$this->credentials(
				(string) $settings->get('gcs.service_account'),
				(string) $settings->get('gcs.access_token'),
			),
			$endpoint,
			new HttpTransport($this->httpClient),
		);
	}

	/**
	 * Where the bearer token comes from.
	 *
	 * @param string $serviceAccount
	 *   The configured service account key, if any.
	 * @param string $token
	 *   The configured access token, if any.
	 *
	 * @return AccessTokenProviderInterface
	 *   The token source.
	 *
	 * @throws RuntimeException
	 *   When nothing is configured, or the service account key does not parse. A malformed key is
	 *   reported here rather than as an authentication failure at the first flush.
	 */
	private function credentials(
		string $serviceAccount,
		string $token,
	): AccessTokenProviderInterface {
		if (trim($serviceAccount) !== '') {
			try {
				return ServiceAccountCredentials::fromJson(
					$serviceAccount,
					new HttpTransport($this->httpClient),
				);
			} catch (InvalidArgumentException $e) {
				throw new RuntimeException(
					sprintf('The configured service account key is unusable: %s', $e->getMessage()),
					0,
					$e,
				);
			}
		}

		$fallback =
			trim($token) !== '' ? $token : trim((string) (getenv(self::TOKEN_VARIABLE) ?: ''));

		if ($fallback === '') {
			throw new RuntimeException(
				'The GCS provider is selected but no service account key or access token is set',
			);
		}

		return new StaticAccessToken($fallback);
	}
}
