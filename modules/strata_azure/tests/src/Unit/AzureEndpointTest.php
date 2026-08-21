<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_azure\Unit;

use Drupal\strata_azure\AzureEndpoint;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Proves a blob URL is built the same way for every deployment that exists.
 *
 * Three of them do exist and they do not agree: the public cloud puts the account in the host, a
 * sovereign cloud changes only the suffix, and Azurite puts the account in the path. A URL built for
 * the wrong one addresses a container that is not there, and the signature computed over it is
 * wrong as well.
 */
#[CoversClass(AzureEndpoint::class)]
class AzureEndpointTest extends TestCase
{
	#[Test]
	#[TestDox('the public cloud puts the account in the host and the container in the path')]
	#[Group('strata/azure')]
	public function publicCloudUrlsAreAccountHosted(): void
	{
		$endpoint = new AzureEndpoint('strataaccount', 'backups');

		$this->assertSame('https://strataaccount.blob.core.windows.net', $endpoint->baseUrl());
		$this->assertSame(
			'https://strataaccount.blob.core.windows.net/backups',
			$endpoint->containerUrl(),
		);
		$this->assertSame('strataaccount.blob.core.windows.net', $endpoint->host());
		$this->assertFalse($endpoint->isEmulated());
	}

	#[Test]
	#[TestDox('a sovereign cloud changes the suffix and nothing else')]
	#[Group('strata/azure')]
	public function sovereignCloudsChangeOnlyTheSuffix(): void
	{
		$endpoint = new AzureEndpoint('strataaccount', 'backups', 'core.chinacloudapi.cn');

		$this->assertSame(
			'https://strataaccount.blob.core.chinacloudapi.cn/backups',
			$endpoint->containerUrl(),
		);
	}

	#[Test]
	#[TestDox('a service url overrides the suffix, which is how azurite is addressed')]
	#[Group('strata/azure')]
	public function serviceUrlOverridesTheSuffix(): void
	{
		$endpoint = new AzureEndpoint(
			'devstoreaccount1',
			'backups',
			AzureEndpoint::PUBLIC_SUFFIX,
			'http://127.0.0.1:10000/devstoreaccount1/',
		);

		$this->assertSame(
			'http://127.0.0.1:10000/devstoreaccount1/backups',
			$endpoint->containerUrl(),
		);
		$this->assertSame('127.0.0.1', $endpoint->host());
		$this->assertTrue($endpoint->isEmulated());
	}

	#[Test]
	#[TestDox('every path segment of a blob name is percent-encoded on its own')]
	#[Group('strata/azure')]
	public function blobNameSegmentsAreEncodedSeparately(): void
	{
		$endpoint = new AzureEndpoint('strataaccount', 'backups');

		$this->assertSame(
			'https://strataaccount.blob.core.windows.net/backups/frames/a%20b/c%2Bd',
			$endpoint->blobUrl('frames/a b/c+d'),
		);
	}

	#[Test]
	#[TestDox('the key prefix is normalised to exactly one trailing slash')]
	#[Group('strata/azure')]
	public function keyPrefixIsNormalised(): void
	{
		$this->assertSame(
			'_strata/site-1/',
			(new AzureEndpoint(
				'a11yaccount',
				'backups',
				'core.windows.net',
				null,
				'/_strata/site-1',
			))->keys->prefix,
		);
		$this->assertSame('', (new AzureEndpoint('a11yaccount', 'backups'))->keys->prefix);
	}

	/**
	 * @return array<string, array{string, string, string, ?string, ?int, string}>
	 */
	public static function unusableEndpointProvider(): array
	{
		return [
			'no account' => ['', 'backups', 'core.windows.net', null, null, 'storage account name'],
			'no container' => ['acct', '', 'core.windows.net', null, null, 'container name'],
			'no suffix and no url' => ['acct', 'backups', '', null, null, 'dns suffix'],
			'a url with no host' => [
				'acct',
				'backups',
				'core.windows.net',
				'not-a-url',
				null,
				'names no host',
			],
			'a zero threshold' => [
				'acct',
				'backups',
				'core.windows.net',
				null,
				0,
				'at least one byte',
			],
		];
	}

	#[Test]
	#[TestDox('an endpoint with $_dataName is refused at construction')]
	#[Group('strata/azure')]
	#[DataProvider('unusableEndpointProvider')]
	public function unusableEndpointsAreRefused(
		string $account,
		string $container,
		string $suffix,
		?string $serviceUrl,
		?int $threshold,
		string $reason,
	): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($reason);

		new AzureEndpoint($account, $container, $suffix, $serviceUrl, '', null, $threshold);
	}
}
