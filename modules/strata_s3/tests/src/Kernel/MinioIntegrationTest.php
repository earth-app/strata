<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_s3\Kernel;

use Drupal\Tests\strata\Kernel\StrataKernelTestBase;
use Drupal\strata\Cas\Framer;
use Drupal\strata\Cas\MemoryFrameIndex;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Cas\Packer;
use Drupal\strata\Codec\CodecRegistry;
use Drupal\strata\Storage\ByteRange;
use Drupal\strata_s3\Credentials\ConfiguredCredentials;
use Drupal\strata_s3\Credentials\Credentials;
use Drupal\strata\Storage\HttpTransport;
use Drupal\strata_s3\S3Endpoint;
use Drupal\strata_s3\S3StorageProvider;
use Drupal\strata_s3\SigV4Signer;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Exercises the provider against a real S3 endpoint.
 *
 * The unit lane drives the provider over canned responses, which proves it builds the requests it
 * means to. This lane proves a real endpoint accepts them. The two find different things: batch
 * delete passed every unit test and failed here, because DeleteObjects requires an integrity header
 * that the canned transport never asked for.
 *
 * Skipped unless a MinIO endpoint is reachable, so the suite stays green without docker. Start one
 * with `docker compose -f docker/compose.yml up -d --wait`.
 */
class MinioIntegrationTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'key', 'strata', 'strata_s3'];

	/**
	 * Endpoint the compose file publishes.
	 */
	private const ENDPOINT = 'http://127.0.0.1:9000';

	/**
	 * Bucket the compose file creates.
	 */
	private const BUCKET = 'strata-backups';

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

		if (!$this->reachable()) {
			$this->markTestSkipped(
				'no MinIO at ' .
					self::ENDPOINT .
					'; start one with docker compose -f docker/compose.yml up -d --wait',
			);
		}

		$this->prefix = '_strata/kernel-' . bin2hex(random_bytes(6));
	}

	/**
	 * {@inheritdoc}
	 */
	protected function tearDown(): void
	{
		if ($this->prefix !== '' && $this->reachable()) {
			$provider = $this->s3();
			$keys = $provider->list('')->keys();

			if ($keys !== []) {
				$provider->delete($keys);
			}
		}

		parent::tearDown();
	}

	private function reachable(): bool
	{
		$socket = @fsockopen('127.0.0.1', 9000, $code, $message, 0.5);

		if ($socket === false) {
			return false;
		}

		fclose($socket);

		return true;
	}

	private function s3(): S3StorageProvider
	{
		$endpoint = new S3Endpoint(self::BUCKET, 'us-east-1', self::ENDPOINT, true, $this->prefix);

		return new S3StorageProvider(
			new ConfiguredCredentials(new Credentials('strata', 'stratatest')),
			$endpoint,
			new HttpTransport(new Client()),
			new SigV4Signer('us-east-1'),
		);
	}

	#[Test]
	#[TestDox('a real endpoint answers a capability probe and reports itself reachable')]
	#[Group('strata/s3-live')]
	public function endpointIsReachable(): void
	{
		$provider = $this->s3();

		$this->assertTrue($provider->isReachable());
		$this->assertNull($provider->unreachableReason());

		$capabilities = $provider->capabilities();

		$this->assertTrue($capabilities->multipart);
		$this->assertTrue($capabilities->batchDelete);
		$this->assertTrue($capabilities->rangeRead);
	}

	#[Test]
	#[TestDox('an object round-trips through a real endpoint byte for byte')]
	#[Group('strata/s3-live')]
	public function objectRoundTrips(): void
	{
		$provider = $this->s3();
		$payload = random_bytes(64 * 1024 + 7);

		$result = $provider->put('frames/probe', $payload);

		$this->assertSame(strlen($payload), $result->size);
		$this->assertNotNull($result->etag);
		$this->assertSame($payload, $provider->get('frames/probe'));
		$this->assertTrue($provider->exists('frames/probe'));
		$this->assertSame(strlen($payload), $provider->head('frames/probe')?->size);
	}

	#[Test]
	#[TestDox('a ranged read returns exactly the requested window from a real endpoint')]
	#[Group('strata/s3-live')]
	public function rangedReadReturnsWindow(): void
	{
		$provider = $this->s3();
		$provider->put('frames/ranged', '0123456789abcdef');

		$this->assertSame('0123', $provider->get('frames/ranged', new ByteRange(0, 4)));
		$this->assertSame('456789', $provider->get('frames/ranged', new ByteRange(4, 6)));
	}

	#[Test]
	#[TestDox('a batch delete is accepted, which needs the integrity header a put does not')]
	#[Group('strata/s3-live')]
	public function batchDeleteIsAccepted(): void
	{
		$provider = $this->s3();

		for ($i = 0; $i < 5; $i++) {
			$provider->put("packs/$i.pack", "payload $i");
		}

		$this->assertCount(5, $provider->list('packs/'));

		$deleted = $provider->delete($provider->list('packs/')->keys());

		$this->assertSame(5, $deleted);
		$this->assertCount(0, $provider->list('packs/'));
	}

	#[Test]
	#[TestDox('deleting an absent key is accepted, so a retried prune is idempotent')]
	#[Group('strata/s3-live')]
	public function deletingAbsentKeyIsAccepted(): void
	{
		// s3 reports success for a key that was never there and cannot say it was absent
		$this->assertSame(1, $this->s3()->delete(['packs/never-written.pack']));
	}

	#[Test]
	#[TestDox('a listing pages through a real endpoint with its continuation token')]
	#[Group('strata/s3-live')]
	public function listingPagesThroughEndpoint(): void
	{
		$provider = $this->s3();

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
	#[TestDox('the whole object store runs against a real endpoint')]
	#[Group('strata/s3-live')]
	public function objectStoreRunsAgainstEndpoint(): void
	{
		$store = new ObjectStore(
			$this->s3(),
			new MemoryFrameIndex(),
			CodecRegistry::withShippedCodecs(),
			$this->cipher(),
			new Framer(16384),
			new Packer(65536),
			19,
		);

		$value = '';
		for ($i = 0; $i < 40; $i++) {
			$value .=
				(string) json_encode([
					'nid' => $i,
					'title' => "Node $i",
					'body' => str_repeat('lorem ipsum dolor ', 30),
				]) . "\n";
		}

		$map = $store->write($value);
		$store->commit();

		$this->assertNotEmpty($map);
		$this->assertSame($value, $store->read($map));
		$this->assertGreaterThan(0, count($this->s3()->list('')));
	}
}
