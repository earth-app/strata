<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\E2E;

use Drupal;
use Drupal\strata_azure\AzureCredentials;
use Drupal\strata_azure\AzureEndpoint;
use Drupal\strata_azure\SharedKeySigner;
use Drupal\strata_s3\Credentials\Credentials;
use Drupal\strata_s3\SigV4Signer;
use DateTimeImmutable;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * What the container offers, and how to point Strata at each backend in it.
 *
 * Every endpoint, key and bucket comes from the environment the compose file sets, so the same suite
 * runs against the emulators in CI, on a laptop and on a remote box without a code change. A backend
 * that is not listed in `STRATA_E2E_PROVIDERS` is never touched.
 */
final class Lane
{
	/**
	 * Every provider the lane knows how to configure.
	 */
	public const ALL = ['local', 's3', 'r2', 'azure', 'gcs', 'b2'];

	/**
	 * The providers this run was asked to cover.
	 *
	 * @return list<string>
	 *   Provider names from `Lane::ALL`.
	 */
	public static function providers(): array
	{
		$wanted = array_filter(array_map('trim', explode(',', self::env('PROVIDERS', 'local'))));

		return array_values(array_intersect(self::ALL, $wanted));
	}

	/**
	 * The database engine this run is on.
	 *
	 * @return string
	 *   `mariadb` or `postgres`.
	 */
	public static function database(): string
	{
		return (string) (getenv('E2E_DB') ?: 'mariadb');
	}

	/**
	 * One lane setting from the environment.
	 *
	 * @param string $name
	 *   The name without its `STRATA_E2E_` prefix.
	 * @param string $default
	 *   Used when it is not set.
	 *
	 * @return string
	 *   The value.
	 */
	public static function env(string $name, string $default = ''): string
	{
		$value = getenv('STRATA_E2E_' . $name);

		return $value === false || $value === '' ? $default : $value;
	}

	/**
	 * Points `strata.settings` at one provider, under a site id of its own.
	 *
	 * @param string $provider
	 *   A name from `Lane::ALL`.
	 * @param string $site
	 *   The site id every key sits under, unique per test so no test sees another's history.
	 * @param array<string, mixed> $extra
	 *   Further settings to write, by dotted key.
	 */
	public static function configure(string $provider, string $site, array $extra = []): void
	{
		$settings = Drupal::configFactory()->getEditable('strata.settings');

		foreach (array_merge(self::settingsFor($provider), $extra) as $key => $value) {
			$settings->set($key, $value);
		}

		$settings->set('site_id', $site)->set('enabled', true)->save();

		self::provision($provider);
	}

	/**
	 * The settings that select one provider.
	 *
	 * @param string $provider
	 *   A name from `Lane::ALL`.
	 *
	 * @return array<string, mixed>
	 *   Dotted settings keys to values.
	 *
	 * @throws RuntimeException
	 *   When the provider is unknown.
	 */
	public static function settingsFor(string $provider): array
	{
		return match ($provider) {
			'local' => ['provider' => 'local', 'local_path' => '/srv/drupal/private/strata-e2e'],
			's3' => self::s3(self::env('S3_ENDPOINT'), 'us-east-1'),
			'r2' => self::s3(self::env('R2_ENDPOINT'), 'auto'),
			'azure' => [
				'provider' => 'azure',
				'azure.account' => self::env('AZURE_ACCOUNT'),
				'azure.container' => self::env('AZURE_CONTAINER'),
				'azure.account_key' => self::env('AZURE_KEY'),
				'azure.service_url' => self::env('AZURE_URL'),
			],
			'gcs' => [
				'provider' => 'gcs',
				'gcs.bucket' => self::env('GCS_BUCKET'),
				'gcs.api_url' => self::env('GCS_URL'),
				'gcs.access_token' => 'emulator',
			],
			'b2' => [
				'provider' => 'b2',
				'b2.bucket_id' => self::env('B2_BUCKET_ID'),
				'b2.bucket_name' => self::env('B2_BUCKET_NAME'),
				'b2.key_id' => self::env('B2_KEY_ID'),
				'b2.application_key' => self::env('B2_KEY'),
				'b2.api_url' => self::env('B2_URL'),
			],
			default => throw new RuntimeException(sprintf('Unknown provider "%s"', $provider)),
		};
	}

	/**
	 * What a second tier of a ladder needs to live on one backend.
	 *
	 * @param string $provider
	 *   One of `s3`, `azure`, `gcs` or `b2`.
	 *
	 * @return array{settings: array<string, mixed>, location: string}
	 *   The credential settings to add, without the provider switch, and the bucket or container the
	 *   tier points at.
	 *
	 * @throws RuntimeException
	 *   When the backend cannot be a tier here.
	 */
	public static function replica(string $provider): array
	{
		self::provision($provider);

		$settings = self::settingsFor($provider);
		unset($settings['provider']);

		return [
			'settings' => $settings,
			'location' => match ($provider) {
				's3' => self::env('S3_REPLICA'),
				'azure' => self::env('AZURE_CONTAINER'),
				'gcs' => self::env('GCS_BUCKET'),
				'b2' => self::env('B2_BUCKET_NAME'),
				default => throw new RuntimeException(
					sprintf('%s cannot be a tier here', $provider),
				),
			},
		];
	}

	/**
	 * Creates what a backend needs before Strata can write to it.
	 *
	 * Azurite starts with no container and fake-gcs-server with no bucket, and Strata deliberately
	 * never creates either, since creating storage is an operator's decision. Both answer a repeat
	 * with a conflict, which counts as present.
	 *
	 * @param string $provider
	 *   A name from `Lane::ALL`.
	 *
	 * @throws RuntimeException
	 *   When the backend refuses.
	 */
	public static function provision(string $provider): void
	{
		static $done = [];

		if (isset($done[$provider])) {
			return;
		}

		if ($provider === 'azure') {
			$account = self::env('AZURE_ACCOUNT');
			$endpoint = new AzureEndpoint(
				$account,
				self::env('AZURE_CONTAINER'),
				AzureEndpoint::PUBLIC_SUFFIX,
				self::env('AZURE_URL'),
			);
			$credentials = new AzureCredentials($account, self::env('AZURE_KEY'), '');
			$url = $endpoint->containerUrl() . '?restype=container';
			$headers = (new SharedKeySigner())->sign(
				$credentials,
				'PUT',
				$url,
				['x-ms-version' => '2021-08-06', 'Content-Length' => '0'],
				new DateTimeImmutable(),
			);
			self::expect(
				(new Client(['http_errors' => false]))->put($url, ['headers' => $headers]),
				[201, 409],
				'create the azure container',
			);
		}

		if ($provider === 's3' || $provider === 'r2') {
			$signer = new SigV4Signer('us-east-1');

			foreach ([self::env('S3_BUCKET'), self::env('S3_REPLICA')] as $bucket) {
				$url = rtrim(self::env('S3_ENDPOINT'), '/') . '/' . $bucket;
				$headers = $signer->signRequest(
					new Credentials(self::env('S3_KEY'), self::env('S3_SECRET')),
					'PUT',
					$url,
					[],
					new DateTimeImmutable(),
				);
				self::expect(
					(new Client(['http_errors' => false]))->put($url, ['headers' => $headers]),
					[200, 409],
					'create the s3 bucket ' . $bucket,
				);
			}
		}

		if ($provider === 'gcs') {
			self::expect(
				(new Client(['http_errors' => false]))->post(
					self::env('GCS_URL') . '/storage/v1/b?project=strata',
					['json' => ['name' => self::env('GCS_BUCKET')]],
				),
				[200, 409],
				'create the gcs bucket',
			);
		}

		$done[$provider] = true;
	}

	/**
	 * The settings for an S3-compatible endpoint.
	 *
	 * @param string $endpoint
	 *   The endpoint URL.
	 * @param string $region
	 *   The signing region.
	 *
	 * @return array<string, mixed>
	 *   Dotted settings keys to values.
	 */
	private static function s3(string $endpoint, string $region): array
	{
		return [
			'provider' => 's3',
			's3.endpoint' => $endpoint,
			's3.bucket' => self::env('S3_BUCKET'),
			's3.region' => $region,
			's3.access_key_id' => self::env('S3_KEY'),
			's3.secret_access_key' => self::env('S3_SECRET'),
			's3.path_style' => true,
		];
	}

	/**
	 * Fails unless a response carries one of the expected statuses.
	 *
	 * @param ResponseInterface $response
	 *   The response.
	 * @param list<int> $statuses
	 *   Statuses that count as success.
	 * @param string $what
	 *   What was being attempted.
	 *
	 * @throws RuntimeException
	 *   When the status is anything else.
	 */
	private static function expect(ResponseInterface $response, array $statuses, string $what): void
	{
		if (!in_array($response->getStatusCode(), $statuses, true)) {
			throw new RuntimeException(
				sprintf(
					'Could not %s: %d %s',
					$what,
					$response->getStatusCode(),
					substr((string) $response->getBody(), 0, 200),
				),
			);
		}
	}
}
