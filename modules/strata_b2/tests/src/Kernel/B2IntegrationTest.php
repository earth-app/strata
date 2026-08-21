<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_b2\Kernel;

use Drupal\Tests\strata\Kernel\StrataKernelTestBase;
use Drupal\strata\Storage\ByteRange;
use Drupal\strata\Storage\HttpTransport;
use Drupal\strata_b2\B2Account;
use Drupal\strata_b2\B2Endpoint;
use Drupal\strata_b2\B2StorageProvider;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Exercises the provider against a real B2 account.
 *
 * The unit lane drives the provider over canned responses, which proves it builds the requests it
 * means to. This lane proves Backblaze accepts them, and it is the only place the part sizes and the
 * upload host come from a real authorization rather than from a fixture.
 *
 * Skipped unless STRATA_B2_KEY_ID, STRATA_B2_KEY, STRATA_B2_BUCKET_ID and STRATA_B2_BUCKET_NAME are
 * all set, so the suite stays green without a Backblaze account. B2 has no emulator; an application
 * key scoped to one throwaway bucket is the cheapest way to run this.
 */
class B2IntegrationTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'key', 'strata', 'strata_b2'];

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

		foreach (['KEY_ID', 'KEY', 'BUCKET_ID', 'BUCKET_NAME'] as $name) {
			if ($this->setting($name) === '') {
				$this->markTestSkipped(
					'no b2 account; set STRATA_B2_KEY_ID, STRATA_B2_KEY, STRATA_B2_BUCKET_ID and STRATA_B2_BUCKET_NAME',
				);
			}
		}

		$this->prefix = '_strata/kernel-' . bin2hex(random_bytes(6));
	}

	/**
	 * {@inheritdoc}
	 */
	protected function tearDown(): void
	{
		if ($this->prefix !== '') {
			$provider = $this->b2();
			$keys = $provider->list('')->keys();

			if ($keys !== []) {
				$provider->delete($keys);
			}
		}

		parent::tearDown();
	}

	private function setting(string $name): string
	{
		return trim((string) (getenv('STRATA_B2_' . $name) ?: ''));
	}

	private function b2(?int $threshold = null): B2StorageProvider
	{
		$transport = new HttpTransport(new Client());
		$api = $this->setting('API_URL');

		return new B2StorageProvider(
			new B2Account(
				$this->setting('KEY_ID'),
				$this->setting('KEY'),
				$transport,
				$api === '' ? B2Account::DEFAULT_API : $api,
			),
			new B2Endpoint(
				$this->setting('BUCKET_ID'),
				$this->setting('BUCKET_NAME'),
				$this->prefix,
				$threshold,
			),
			$transport,
		);
	}

	#[Test]
	#[TestDox('a real account authorizes and reports the part sizes it actually allows')]
	#[Group('strata/b2-live')]
	public function accountAuthorizes(): void
	{
		$provider = $this->b2();

		$this->assertTrue($provider->isReachable(), (string) $provider->unreachableReason());
		$this->assertNull($provider->unreachableReason());

		$capabilities = $provider->capabilities();

		$this->assertTrue($capabilities->multipart);
		$this->assertTrue($capabilities->rangeRead);
		$this->assertFalse($capabilities->batchDelete);
		$this->assertFalse($capabilities->conditionalWrite);
		$this->assertGreaterThan(0, $capabilities->minPartSize);
	}

	#[Test]
	#[TestDox('one file goes through the whole cycle against a real account')]
	#[Group('strata/b2-live')]
	public function wholeCycleRunsAgainstTheAccount(): void
	{
		$provider = $this->b2();
		$payload = random_bytes(64 * 1024 + 7);

		$result = $provider->put('frames/probe', $payload);

		$this->assertSame(strlen($payload), $result->size);
		$this->assertSame(sha1($payload), $result->etag);

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
	#[TestDox('a listing pages through a real bucket with its next file name')]
	#[Group('strata/b2-live')]
	public function listingPagesThroughTheBucket(): void
	{
		$provider = $this->b2();

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
	#[TestDox('a body over the threshold is finished from parts a real account accepts')]
	#[Group('strata/b2-live')]
	public function largeBodyIsFinishedFromParts(): void
	{
		$provider = $this->b2(1_048_576);
		$payload = random_bytes($provider->capabilities()->partSizeFor(0) + 4096);

		$result = $provider->put('packs/big.pack', $payload);

		$this->assertTrue($result->multipart);
		$this->assertSame(2, $result->parts);
		$this->assertSame($payload, $provider->get('packs/big.pack'));
	}
}
