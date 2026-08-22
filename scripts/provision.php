<?php

/**
 * @file
 * Creates whatever the configured provider needs before anything is written to it.
 *
 * Run through `./startup.sh --provider=<id>`.
 *
 * A bucket and a container are management operations, not storage ones, so no provider implements
 * them: `StorageProviderInterface` puts and gets objects inside a namespace somebody else made. The
 * emulators start empty, so this makes that namespace once, using the module's own endpoint and
 * signer rather than a vendor CLI. That is worth something on its own - a request this script signs
 * and Azurite accepts is the same signature the provider will send.
 */

declare(strict_types=1);

use Drupal\strata_azure\AzureCredentials;
use Drupal\strata_azure\AzureEndpoint;
use Drupal\strata_azure\AzureStorageProvider;
use Drupal\strata_azure\SharedKeySigner;
use Drupal\strata\Storage\HttpTransport;
use GuzzleHttp\Client;

require_once __DIR__ . '/lib.php';

$settings = Drupal::config('strata.settings');
$provider = (string) $settings->get('provider');

strata_head(sprintf('provisioning the %s namespace', $provider));

$transport = new HttpTransport(new Client());

/**
 * Reports one provisioning attempt.
 *
 * @param string $what
 *   What was being made.
 * @param int $status
 *   The status the endpoint answered with.
 */
function strata_provisioned(string $what, int $status): void
{
	// 409 is the endpoint saying it already exists, which is the same outcome as making it
	$outcome = match (true) {
		$status >= 200 && $status < 300 => 'created',
		$status === 409 => 'already there',
		default => sprintf('REFUSED with %d', $status),
	};

	strata_row($what, $outcome);
}

match ($provider) {
	'azure' => (static function () use ($settings, $transport): void {
		$endpoint = new AzureEndpoint(
			(string) $settings->get('azure.account'),
			(string) $settings->get('azure.container'),
			(string) ($settings->get('azure.endpoint_suffix') ?: 'core.windows.net'),
			((string) $settings->get('azure.service_url')) ?: null,
		);
		$credentials = new AzureCredentials(
			$endpoint->account,
			(string) $settings->get('azure.account_key'),
		);
		$url = $endpoint->containerUrl() . '?restype=container';
		$headers = (new SharedKeySigner())->sign(
			$credentials,
			'PUT',
			$url,
			['Content-Length' => '0', 'x-ms-version' => AzureStorageProvider::API_VERSION],
			new DateTimeImmutable('now'),
		);
		$response = $transport('PUT', $url, $headers, '');

		strata_provisioned(
			sprintf('container %s', $endpoint->container),
			(int) $response['status'],
		);
	})(),
	'gcs' => (static function () use ($settings, $transport): void {
		$api = rtrim((string) $settings->get('gcs.api_url'), '/');
		$bucket = (string) $settings->get('gcs.bucket');

		if ($api === '') {
			strata_row('bucket', 'skipped: a real gcs bucket is made in the console, not here');

			return;
		}

		$response = $transport(
			'POST',
			$api . '/storage/v1/b',
			['Content-Type' => 'application/json'],
			(string) json_encode(['name' => $bucket]),
		);

		strata_provisioned(sprintf('bucket %s', $bucket), (int) $response['status']);
	})(),
	default => strata_row($provider, 'nothing to provision'),
};

strata_head('what the provider says now');

$store = strata_engine()->provider();
$reason = $store->unreachableReason();

strata_row('provider', $store->id());
strata_row('reachable', $reason === null ? 'yes' : 'no: ' . $reason);

if ($reason === null) {
	$capabilities = $store->capabilities();

	strata_row('multipart', $capabilities->multipart ? 'yes' : 'no');
	strata_row('batch delete', $capabilities->batchDelete ? 'yes' : 'no');
	strata_row('conditional write', $capabilities->conditionalWrite ? 'yes' : 'no');
	strata_row('range read', $capabilities->rangeRead ? 'yes' : 'no');
	strata_row('checksums', $capabilities->checksums ? 'yes' : 'no');
	strata_row('storage classes', $capabilities->storageClasses ? 'yes' : 'no');
	strata_row('largest single put', strata_bytes($capabilities->maxSinglePut));
	strata_row('smallest part', strata_bytes($capabilities->minPartSize));
}
