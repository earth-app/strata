<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_azure\Kernel;

use Drupal\Tests\strata\Kernel\StrataKernelTestBase;
use Drupal\strata\Storage\ByteRange;
use Drupal\strata\Storage\HttpTransport;
use Drupal\strata_azure\AzureCredentials;
use Drupal\strata_azure\AzureEndpoint;
use Drupal\strata_azure\AzureStorageProvider;
use Drupal\strata_azure\SharedKeySigner;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Exercises the provider against a real blob endpoint.
 *
 * The unit lane drives the provider over canned responses, which proves it builds the requests it
 * means to. This lane proves a real service accepts them, and it is the only place the SharedKey
 * signature is checked by something other than the code that produced it.
 *
 * Skipped unless STRATA_AZURE_ENDPOINT and STRATA_AZURE_KEY name a reachable service, so the suite
 * stays green without one. Azurite serves as one:
 * `docker run -p 10000:10000 mcr.microsoft.com/azure-storage/azurite azurite-blob --blobHost 0.0.0.0`.
 */
class AzureIntegrationTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'key', 'strata', 'strata_azure'];

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

		if ($this->setting('ENDPOINT') === '' || $this->setting('KEY') === '') {
			$this->markTestSkipped(
				'no azure endpoint; set STRATA_AZURE_ENDPOINT and STRATA_AZURE_KEY to run this',
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
			$provider = $this->azure();
			$keys = $provider->list('')->keys();

			if ($keys !== []) {
				$provider->delete($keys);
			}
		}

		parent::tearDown();
	}

	private function setting(string $name, string $fallback = ''): string
	{
		$value = trim((string) (getenv('STRATA_AZURE_' . $name) ?: ''));

		return $value === '' ? $fallback : $value;
	}

	private function azure(): AzureStorageProvider
	{
		$account = $this->setting('ACCOUNT', 'devstoreaccount1');
		$endpoint = new AzureEndpoint(
			$account,
			$this->setting('CONTAINER', 'strata-backups'),
			AzureEndpoint::PUBLIC_SUFFIX,
			$this->setting('ENDPOINT'),
			$this->prefix,
		);

		return new AzureStorageProvider(
			new AzureCredentials($account, $this->setting('KEY'), $this->setting('SAS')),
			$endpoint,
			new HttpTransport(new Client()),
			new SharedKeySigner(),
		);
	}

	#[Test]
	#[TestDox('a real container answers a capability probe and reports itself reachable')]
	#[Group('strata/azure-live')]
	public function endpointIsReachable(): void
	{
		$provider = $this->azure();

		$this->assertTrue($provider->isReachable(), (string) $provider->unreachableReason());
		$this->assertNull($provider->unreachableReason());

		$capabilities = $provider->capabilities();

		$this->assertTrue($capabilities->multipart);
		$this->assertTrue($capabilities->rangeRead);
		$this->assertFalse($capabilities->batchDelete);
	}

	#[Test]
	#[TestDox('one blob goes through the whole cycle against a real service')]
	#[Group('strata/azure-live')]
	public function wholeCycleRunsAgainstTheService(): void
	{
		$provider = $this->azure();
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
	#[TestDox('a listing pages through a real container with its marker')]
	#[Group('strata/azure-live')]
	public function listingPagesThroughTheContainer(): void
	{
		$provider = $this->azure();

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
	#[TestDox('a blob over the threshold is staged as blocks a real service commits')]
	#[Group('strata/azure-live')]
	public function largeBlobIsCommittedFromBlocks(): void
	{
		$account = $this->setting('ACCOUNT', 'devstoreaccount1');
		$endpoint = new AzureEndpoint(
			$account,
			$this->setting('CONTAINER', 'strata-backups'),
			AzureEndpoint::PUBLIC_SUFFIX,
			$this->setting('ENDPOINT'),
			$this->prefix,
			null,
			1_048_576,
		);
		$provider = new AzureStorageProvider(
			new AzureCredentials($account, $this->setting('KEY'), $this->setting('SAS')),
			$endpoint,
			new HttpTransport(new Client()),
			new SharedKeySigner(),
		);
		$payload = random_bytes($provider->capabilities()->partSizeFor(0) + 4096);

		$result = $provider->put('packs/big.pack', $payload);

		$this->assertTrue($result->multipart);
		$this->assertSame(2, $result->parts);
		$this->assertSame($payload, $provider->get('packs/big.pack'));
	}
}
