<?php

declare(strict_types=1);

namespace Drupal\strata_s3;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\strata\Storage\HttpTransport;
use Drupal\strata\Storage\TierProviderFactoryInterface;
use Drupal\strata\Tier\TierTarget;
use Drupal\strata_s3\Credentials\ConfiguredCredentials;
use Drupal\strata_s3\Credentials\CredentialChain;
use Drupal\strata_s3\Credentials\Credentials;
use Drupal\strata_s3\Credentials\CredentialProviderInterface;
use Drupal\strata_s3\Credentials\EnvironmentCredentials;
use Drupal\strata_s3\Credentials\InstanceProfileCredentials;
use Drupal\strata_s3\Credentials\SharedFileCredentials;
use GuzzleHttp\ClientInterface;
use RuntimeException;

/**
 * Builds the S3 provider from the module's configuration.
 *
 * Credentials are resolved in the order a site is most likely to have set them: keys typed into the
 * settings form, then the AWS environment variables, then a shared credentials file, then an
 * instance profile. A site running on EC2 with an instance role therefore needs no keys configured,
 * and a site that typed keys in is not overridden by an environment that happens to have some.
 *
 * One endpoint can serve several buckets, which is what a tiered store is here: the same account,
 * the same credentials, and a bucket per tier with its own storage class. Only those two things vary
 * between tiers, so a tier names them and everything else is read once from configuration.
 *
 * @see S3StorageProvider
 * @see CredentialChain
 * @see TierProviderFactoryInterface
 */
final class S3ProviderFactory implements TierProviderFactoryInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return 's3';
	}

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
	 * Builds a provider from the current configuration.
	 *
	 * @return S3StorageProvider
	 *   The provider.
	 *
	 * @throws RuntimeException
	 *   When no bucket is configured, since an endpoint without one addresses nothing.
	 */
	public function create(): S3StorageProvider
	{
		return $this->build('', '');
	}

	/**
	 * Builds a provider for one tier's bucket.
	 *
	 * @param TierTarget $target
	 *   The tier. Its location is the bucket name and its storage class is what objects there are
	 *   written with; an empty value for either falls back to what the module is configured with, so
	 *   the nearest tier of a single-bucket site is the same provider create() would build.
	 *
	 * @return S3StorageProvider
	 *   The provider.
	 *
	 * @throws RuntimeException
	 *   When neither the tier nor the module names a bucket.
	 */
	public function createFor(TierTarget $target): S3StorageProvider
	{
		return $this->build($target->location, $target->storageClass);
	}

	/**
	 * Builds a provider, taking the bucket and storage class from an override or from configuration.
	 *
	 * @param string $bucket
	 *   Bucket name, or an empty string for the configured one.
	 * @param string $storageClass
	 *   Storage class, or an empty string for the configured one.
	 *
	 * @return S3StorageProvider
	 *   The provider.
	 *
	 * @throws RuntimeException
	 *   When no bucket is named anywhere, since an endpoint without one addresses nothing.
	 */
	private function build(string $bucket, string $storageClass): S3StorageProvider
	{
		$settings = $this->configFactory->get('strata.settings');
		$bucket = trim($bucket) ?: trim((string) $settings->get('s3.bucket'));

		if ($bucket === '') {
			throw new RuntimeException('The S3 provider is selected but no bucket is configured');
		}

		$region = trim((string) $settings->get('s3.region')) ?: 'us-east-1';
		$threshold = (int) $settings->get('s3.multipart_threshold');
		$class = trim($storageClass) ?: trim((string) $settings->get('s3.storage_class'));

		$endpoint = new S3Endpoint(
			$bucket,
			$region,
			trim((string) $settings->get('s3.endpoint')) ?: null,
			(bool) $settings->get('s3.path_style'),
			'_strata/' . trim((string) $settings->get('site_id'), '/'),
			(bool) $settings->get('s3.send_checksums'),
			$class === '' ? null : $class,
			$threshold > 0 ? $threshold : null,
		);

		return new S3StorageProvider(
			$this->credentials(
				$settings->get('s3.access_key_id'),
				$settings->get('s3.secret_access_key'),
			),
			$endpoint,
			new HttpTransport($this->httpClient),
			new SigV4Signer($region),
		);
	}

	/**
	 * The credential chain for a configured key pair.
	 *
	 * @param mixed $keyId
	 *   The configured access key id, if any.
	 * @param mixed $secret
	 *   The configured secret access key, if any.
	 *
	 * @return CredentialProviderInterface
	 *   The chain.
	 */
	private function credentials(mixed $keyId, mixed $secret): CredentialProviderInterface
	{
		$providers = [];

		if (trim((string) $keyId) !== '' && trim((string) $secret) !== '') {
			$providers[] = new ConfiguredCredentials(
				new Credentials(trim((string) $keyId), trim((string) $secret)),
			);
		}

		$providers[] = new EnvironmentCredentials();

		$home = rtrim((string) (getenv('HOME') ?: ''), '/');

		if ($home !== '') {
			$providers[] = new SharedFileCredentials(
				$home . '/.aws/credentials',
				$home . '/.aws/config',
			);
		}

		// the metadata service answers only on ec2; elsewhere the short timeout makes it a no-op
		$providers[] = new InstanceProfileCredentials(new HttpTransport($this->httpClient, 2, 1));

		return new CredentialChain($providers);
	}
}
