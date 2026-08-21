<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_gcs\Kernel;

use Drupal\Tests\strata\Kernel\StrataKernelTestBase;
use Drupal\strata\Storage\ByteRange;
use Drupal\strata\Storage\HttpTransport;
use Drupal\strata_gcs\Credentials\AccessTokenProviderInterface;
use Drupal\strata_gcs\Credentials\ServiceAccountCredentials;
use Drupal\strata_gcs\Credentials\StaticAccessToken;
use Drupal\strata_gcs\GcsEndpoint;
use Drupal\strata_gcs\GcsStorageProvider;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Exercises the provider against a real Cloud Storage bucket.
 *
 * The unit lane drives the provider over canned responses, which proves it builds the requests it
 * means to. This lane proves Google accepts them, and it is the only place the service account
 * assertion is verified by the party that issued the key.
 *
 * Skipped unless STRATA_GCS_BUCKET names a bucket and one of STRATA_GCS_KEY_FILE or
 * STRATA_GCS_TOKEN can authenticate to it, so the suite stays green without a Google project.
 */
class GcsIntegrationTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'key', 'strata', 'strata_gcs'];

	/**
	 * Key prefix this test confines itself to, so a run cannot disturb another.
	 */
	private string $prefix = '';

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		if ($this->setting('BUCKET') === '') {
			$this->markTestSkipped('no gcs bucket; set STRATA_GCS_BUCKET to run this');
		}
		if ($this->setting('TOKEN') === '' && $this->setting('KEY_FILE') === '') {
			$this->markTestSkipped(
				'no gcs credential; set STRATA_GCS_KEY_FILE or STRATA_GCS_TOKEN to run this',
			);
		}

		$this->prefix = '_strata/kernel-' . bin2hex(random_bytes(6));
	}

	/**
	 * {@inheritdoc}
	 */
	protected function tearDown(): void
	{
		if ($this->prefix !== '') {
			$provider = $this->gcs();
			$keys = $provider->list('')->keys();

			if ($keys !== []) {
				$provider->delete($keys);
			}
		}

		parent::tearDown();
	}

	private function setting(string $name, string $fallback = ''): string
	{
		$value = trim((string) (getenv('STRATA_GCS_' . $name) ?: ''));

		return $value === '' ? $fallback : $value;
	}

	private function credentials(): AccessTokenProviderInterface
	{
		$file = $this->setting('KEY_FILE');

		if ($file === '') {
			return new StaticAccessToken($this->setting('TOKEN'));
		}

		return ServiceAccountCredentials::fromJson(
			(string) file_get_contents($file),
			new HttpTransport(new Client()),
		);
	}

	private function gcs(?int $threshold = null): GcsStorageProvider
	{
		return new GcsStorageProvider(
			$this->credentials(),
			new GcsEndpoint(
				$this->setting('BUCKET'),
				$this->prefix,
				$this->setting('API_URL') ?: null,
				null,
				$threshold,
			),
			new HttpTransport(new Client()),
		);
	}

	#[Test]
	#[TestDox('a real bucket answers a capability probe and reports itself reachable')]
	#[Group('strata/gcs-live')]
	public function endpointIsReachable(): void
	{
		$provider = $this->gcs();

		$this->assertTrue($provider->isReachable(), (string) $provider->unreachableReason());
		$this->assertNull($provider->unreachableReason());

		$capabilities = $provider->capabilities();

		$this->assertTrue($capabilities->multipart);
		$this->assertTrue($capabilities->rangeRead);
		$this->assertFalse($capabilities->batchDelete);
	}

	#[Test]
	#[TestDox('one object goes through the whole cycle against a real bucket')]
	#[Group('strata/gcs-live')]
	public function wholeCycleRunsAgainstTheBucket(): void
	{
		$provider = $this->gcs();
		$payload = random_bytes(64 * 1024 + 7);

		$result = $provider->put('frames/probe', $payload);

		$this->assertSame(strlen($payload), $result->size);
		$this->assertNotNull($result->etag);

		$meta = $provider->head('frames/probe');

		$this->assertNotNull($meta);
		$this->assertSame(strlen($payload), $meta->size);
		$this->assertTrue($provider->exists('frames/probe'));

		$this->assertSame($payload, $provider->get('frames/probe'));
		$this->assertSame(
			substr($payload, 16, 32),
			$provider->get('frames/probe', new ByteRange(16, 32)),
		);

		$this->assertSame(['frames/probe'], $provider->list('frames/')->keys());

		$this->assertSame(1, $provider->delete(['frames/probe']));
		$this->assertNull($provider->head('frames/probe'));
		$this->assertSame(0, $provider->delete(['frames/probe']));
	}

	#[Test]
	#[TestDox('a listing pages through a real bucket with its page token')]
	#[Group('strata/gcs-live')]
	public function listingPagesThroughTheBucket(): void
	{
		$provider = $this->gcs();

		for ($i = 0; $i < 12; $i++) {
			$provider->put(sprintf('frames/%02d', $i), 'x');
		}

		$seen = [];
		$cursor = null;
		$pages = 0;

		do {
			$page = $provider->list('frames/', $cursor, 5);
			$seen = [...$seen, ...$page->keys()];
			$cursor = $page->cursor;
			$pages++;
			$this->assertLessThan(10, $pages, 'the listing did not terminate');
		} while ($page->hasMore());

		$this->assertCount(12, $seen);
		$this->assertSame($seen, array_unique($seen));
		$this->assertGreaterThan(1, $pages);
	}

	#[Test]
	#[TestDox('a body over the threshold goes up in a resumable session a real bucket finalises')]
	#[Group('strata/gcs-live')]
	public function largeBodyUsesAResumableSession(): void
	{
		$provider = $this->gcs(1_048_576);
		$payload = random_bytes($provider->capabilities()->partSizeFor(0) + 4096);

		$result = $provider->put('packs/big.pack', $payload);

		$this->assertTrue($result->multipart);
		$this->assertSame(2, $result->parts);
		$this->assertSame($payload, $provider->get('packs/big.pack'));
	}
}
