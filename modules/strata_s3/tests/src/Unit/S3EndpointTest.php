<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_s3\Unit;

use Drupal\strata_s3\S3Endpoint;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(S3Endpoint::class)]
class S3EndpointTest extends TestCase
{
	#region Addressing

	/**
	 * @return array<string, array{S3Endpoint, string, string}>
	 */
	public static function addressProvider(): array
	{
		return [
			'aws virtual-host style' => [
				new S3Endpoint('strata-backups', 'eu-west-2'),
				'strata-backups.s3.eu-west-2.amazonaws.com',
				'https://strata-backups.s3.eu-west-2.amazonaws.com/frames/ab/cd/object',
			],
			'aws path style' => [
				new S3Endpoint('strata-backups', 'eu-west-2', null, true),
				's3.eu-west-2.amazonaws.com',
				'https://s3.eu-west-2.amazonaws.com/strata-backups/frames/ab/cd/object',
			],
			'r2 virtual-host style' => [
				new S3Endpoint(
					'strata-backups',
					S3Endpoint::R2_REGION,
					'https://account.r2.cloudflarestorage.com',
				),
				'strata-backups.account.r2.cloudflarestorage.com',
				'https://strata-backups.account.r2.cloudflarestorage.com/frames/ab/cd/object',
			],
			'minio on a non-default port' => [
				new S3Endpoint('strata-backups', 'us-east-1', 'http://minio.internal:9000', true),
				'minio.internal:9000',
				'http://minio.internal:9000/strata-backups/frames/ab/cd/object',
			],
			'an endpoint on the default https port' => [
				new S3Endpoint('strata-backups', 'us-east-1', 'https://ceph.internal:443', true),
				'ceph.internal',
				'https://ceph.internal/strata-backups/frames/ab/cd/object',
			],
		];
	}

	#[Test]
	#[TestDox('$_dataName resolves to the host and object url it should')]
	#[Group('strata/s3')]
	#[DataProvider('addressProvider')]
	public function addressesBucketsCorrectly(S3Endpoint $endpoint, string $host, string $url): void
	{
		$this->assertSame($host, $endpoint->hostFor($endpoint->bucket));
		$this->assertSame($url, $endpoint->urlFor('frames/ab/cd/object'));
	}

	#[Test]
	#[TestDox('an empty key addresses the bucket, which is what a listing is issued against')]
	#[Group('strata/s3')]
	public function emptyKeyAddressesTheBucket(): void
	{
		$virtual = new S3Endpoint('strata-backups', 'us-east-1');
		$path = new S3Endpoint('strata-backups', 'us-east-1', 'https://minio.internal', true);

		$this->assertSame(
			'https://strata-backups.s3.us-east-1.amazonaws.com/',
			$virtual->urlFor(''),
		);
		$this->assertSame('https://minio.internal/strata-backups/', $path->urlFor(''));
	}

	#[Test]
	#[TestDox('a key holding a space or a plus is encoded per segment with the slashes kept')]
	#[Group('strata/s3')]
	public function encodesKeysPerSegment(): void
	{
		$endpoint = new S3Endpoint('strata-backups', 'us-east-1');

		$this->assertSame(
			'https://strata-backups.s3.us-east-1.amazonaws.com/a%20b/c%2Bd/e~f',
			$endpoint->urlFor('a b/c+d/e~f'),
		);
	}

	#endregion

	#region Prefix

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function prefixProvider(): array
	{
		return [
			'nothing' => ['', ''],
			'a bare segment' => ['_strata', '_strata/'],
			'a trailing slash already there' => ['_strata/site-1/', '_strata/site-1/'],
			'a leading slash' => ['/_strata/site-1', '_strata/site-1/'],
			'slashes on both ends' => ['/_strata/site-1/', '_strata/site-1/'],
			'only slashes' => ['///', ''],
		];
	}

	#[Test]
	#[TestDox('a key prefix given as $_dataName normalises to one trailing slash')]
	#[Group('strata/s3')]
	#[DataProvider('prefixProvider')]
	public function normalisesTheKeyPrefix(string $given, string $expected): void
	{
		$endpoint = new S3Endpoint('strata-backups', 'us-east-1', null, false, $given);

		$this->assertSame($expected, $endpoint->keyPrefix);
	}

	#endregion

	#region Detection

	#[Test]
	#[TestDox('r2 is recognised from its endpoint host and nothing else is')]
	#[Group('strata/s3')]
	public function detectsR2FromTheHost(): void
	{
		$r2 = new S3Endpoint('b', 'auto', 'https://account.R2.CloudflareStorage.com');
		$aws = new S3Endpoint('b', 'us-east-1');
		$minio = new S3Endpoint('b', 'us-east-1', 'https://minio.internal:9000', true);

		$this->assertTrue($r2->isR2());
		$this->assertFalse($aws->isR2());
		$this->assertFalse($minio->isR2());
	}

	#[Test]
	#[TestDox('the scheme comes from the endpoint url and defaults to https')]
	#[Group('strata/s3')]
	public function readsTheSchemeFromTheEndpoint(): void
	{
		$this->assertSame('https', (new S3Endpoint('b', 'us-east-1'))->scheme());
		$this->assertSame(
			'http',
			(new S3Endpoint('b', 'us-east-1', 'http://minio.internal'))->scheme(),
		);
		$this->assertSame(
			'https',
			(new S3Endpoint('b', 'us-east-1', 'https://minio.internal'))->scheme(),
		);
	}

	#endregion

	#region Refusals

	/**
	 * @return array<string, array{string, string, string|null, string}>
	 */
	public static function badEndpointProvider(): array
	{
		return [
			'no bucket' => ['  ', 'us-east-1', null, 'needs a bucket name'],
			'no region' => ['strata-backups', ' ', null, 'needs a region'],
			'an endpoint url naming no host' => [
				'strata-backups',
				'us-east-1',
				'not-a-url',
				'names no host',
			],
		];
	}

	#[Test]
	#[TestDox('an endpoint with $_dataName is refused at construction')]
	#[Group('strata/s3')]
	#[DataProvider('badEndpointProvider')]
	public function refusesIncoherentEndpoints(
		string $bucket,
		string $region,
		?string $url,
		string $message,
	): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		new S3Endpoint($bucket, $region, $url);
	}

	#endregion
}
