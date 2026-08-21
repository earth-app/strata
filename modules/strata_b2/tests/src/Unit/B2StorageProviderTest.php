<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_b2\Unit;

use Drupal\strata\Storage\ByteRange;
use Drupal\strata\Storage\HttpTransport;
use Drupal\strata\Storage\ObjectMeta;
use Drupal\strata_b2\B2Account;
use Drupal\strata_b2\B2Endpoint;
use Drupal\strata_b2\B2StorageProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Drives the whole B2 provider over canned responses with no network.
 *
 * The provider is exercised through the real Guzzle transport against a MockHandler, so the requests
 * these assertions read are the ones that would go on the wire.
 *
 * Three of these exist because B2 behaves unlike S3 and the difference is invisible until it costs
 * something: an upload URL is dead as soon as a write to it fails, a delete names a file id that
 * only a lookup knows, and the smallest part the account accepts comes from the authorization rather
 * than from a constant.
 */
#[CoversClass(B2StorageProvider::class)]
#[CoversClass(B2Endpoint::class)]
class B2StorageProviderTest extends TestCase
{
	#region Fixtures

	/**
	 * Prefix the engine puts every key under.
	 */
	private const PREFIX = '_strata/site-1/';

	/**
	 * Where the canned authorization points api calls.
	 */
	private const API = 'https://api002.backblazeb2.com';

	/**
	 * Where the canned authorization points downloads.
	 */
	private const DOWNLOAD = 'https://f002.backblazeb2.com';

	/**
	 * Where the canned authorization points uploads.
	 */
	private const UPLOAD = 'https://pod-000-1016-09.backblaze.com/b2api/v2/b2_upload_file/bucket/c001';

	/**
	 * Smallest part the canned authorization allows, which is not the documented default.
	 */
	private const MIN_PART = 1_000_000;

	/**
	 * Responses the handler hands out in order.
	 */
	private MockHandler $handler;

	/**
	 * Requests the client sent.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $history = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->handler = new MockHandler();
		$this->history = [];
	}

	/**
	 * Queues one response.
	 *
	 * @param int $status
	 *   HTTP status.
	 * @param string $body
	 *   Response body.
	 */
	private function queue(int $status, string $body = ''): void
	{
		$this->handler->append(new Response($status, [], $body));
	}

	/**
	 * Queues one response built from an array.
	 *
	 * @param array<string, mixed> $body
	 *   The response body, which is json on the wire.
	 * @param int $status
	 *   HTTP status.
	 */
	private function queueJson(array $body, int $status = 200): void
	{
		$this->queue($status, (string) json_encode($body));
	}

	/**
	 * Queues a response carrying only headers, as a download HEAD answers with.
	 *
	 * @param int $status
	 *   HTTP status.
	 * @param array<string, string> $headers
	 *   Response headers.
	 */
	private function queueHeaders(int $status, array $headers): void
	{
		$this->handler->append(new Response($status, $headers));
	}

	/**
	 * Queues a transport failure, as an unroutable endpoint produces.
	 */
	private function queueFailure(): void
	{
		$this->handler->append(
			new ConnectException('Connection refused', new Request('GET', 'https://example.test')),
		);
	}

	/**
	 * The authorization the canned endpoint answers with.
	 *
	 * @param array<string, mixed> $overrides
	 *   Fields to replace.
	 *
	 * @return array<string, mixed>
	 *   The response body.
	 */
	private function authorization(array $overrides = []): array
	{
		return array_merge(
			[
				'absoluteMinimumPartSize' => self::MIN_PART,
				'recommendedPartSize' => 100_000_000,
				'apiUrl' => self::API,
				'downloadUrl' => self::DOWNLOAD,
				'authorizationToken' => '4_0022_account',
				'allowed' => [
					'bucketId' => 'bucket-id-1',
					'bucketName' => 'strata-backups',
					'capabilities' => ['listFiles', 'readFiles', 'writeFiles', 'deleteFiles'],
					'namePrefix' => null,
				],
			],
			$overrides,
		);
	}

	/**
	 * A provider whose authorization has not happened yet.
	 *
	 * @param int|null $threshold
	 *   Large file threshold, or NULL for the endpoint maximum.
	 *
	 * @return B2StorageProvider
	 *   The provider.
	 */
	private function provider(?int $threshold = null): B2StorageProvider
	{
		$stack = HandlerStack::create($this->handler);
		$stack->push(Middleware::history($this->history));
		$transport = new HttpTransport(new Client(['handler' => $stack]));

		return new B2StorageProvider(
			new B2Account('0022deadbeef', 'K002Example', $transport),
			new B2Endpoint('bucket-id-1', 'strata-backups', self::PREFIX, $threshold),
			$transport,
		);
	}

	/**
	 * A provider that has authorized and forgotten the request it took.
	 *
	 * @param int|null $threshold
	 *   Large file threshold, or NULL for the endpoint maximum.
	 * @param array<string, mixed> $overrides
	 *   Authorization fields to replace.
	 *
	 * @return B2StorageProvider
	 *   The provider.
	 */
	private function probed(?int $threshold = null, array $overrides = []): B2StorageProvider
	{
		$provider = $this->provider($threshold);

		$this->queueJson($this->authorization($overrides));
		$provider->capabilities();
		$this->history = [];

		return $provider;
	}

	/**
	 * The requests the provider made.
	 *
	 * @return list<RequestInterface>
	 *   The requests, in order.
	 */
	private function requests(): array
	{
		return array_values(
			array_map(static function (array $entry): RequestInterface {
				$request = $entry['request'];

				return $request instanceof RequestInterface
					? $request
					: new Request('GET', 'https://nothing.test');
			}, $this->history),
		);
	}

	/**
	 * One request the provider made.
	 *
	 * @param int $index
	 *   Which one, counting from zero.
	 *
	 * @return RequestInterface
	 *   The request.
	 */
	private function request(int $index): RequestInterface
	{
		$requests = $this->requests();

		$this->assertArrayHasKey($index, $requests, 'the provider made fewer requests than that');

		return $requests[$index];
	}

	/**
	 * The decoded body of one request.
	 *
	 * @param int $index
	 *   Which request, counting from zero.
	 *
	 * @return array<mixed>
	 *   The parsed json.
	 */
	private function payload(int $index): array
	{
		$parsed = json_decode((string) $this->request($index)->getBody(), true);

		$this->assertIsArray($parsed);

		return $parsed;
	}

	/**
	 * Queues the two answers one single upload needs.
	 *
	 * @param string $body
	 *   The bytes being written, which the recorded size and hash are computed from.
	 * @param string $name
	 *   The file name, already prefixed.
	 */
	private function queueUpload(string $body, string $name): void
	{
		$this->queueJson(['uploadUrl' => self::UPLOAD, 'authorizationToken' => '4_0022_upload']);
		$this->queueJson([
			'fileId' => '4_z_file_1',
			'fileName' => $name,
			'contentLength' => strlen($body),
			'contentSha1' => sha1($body),
		]);
	}

	#endregion

	#region Identity

	#[Test]
	#[TestDox('the provider reports a stable id, a label and the limits the account gave it')]
	#[Group('strata/b2')]
	public function reportsCoherentIdentity(): void
	{
		$provider = $this->provider();
		$this->queueJson($this->authorization());

		$capabilities = $provider->capabilities();

		$this->assertSame('b2', $provider->id());
		$this->assertSame(strtolower($provider->id()), $provider->id());
		$this->assertSame('Backblaze B2', $provider->label());
		$this->assertTrue($provider->isReachable());
		$this->assertNull($provider->unreachableReason());
		$this->assertSame(
			self::MIN_PART,
			$capabilities->minPartSize,
			'the floor is what the authorization reported, not a constant',
		);
		$this->assertTrue($capabilities->multipart);
		$this->assertTrue($capabilities->checksums, 'sha1 is mandatory on every upload');
		$this->assertFalse($capabilities->batchDelete);
		$this->assertFalse($capabilities->conditionalWrite);
		$this->assertFalse($capabilities->storageClasses);
		$this->assertSame(1, $capabilities->deleteBatchSize());
	}

	#[Test]
	#[TestDox('the account is authorized once however often capabilities are asked for')]
	#[Group('strata/b2')]
	public function authorizesOnceAndCaches(): void
	{
		$provider = $this->provider();
		$this->queueJson($this->authorization());

		$first = $provider->capabilities();
		$provider->capabilities();
		$provider->isReachable();

		$this->assertSame($first, $provider->capabilities());
		$this->assertCount(1, $this->requests());
	}

	#[Test]
	#[TestDox('an account that will not authorize reports conservatively, not optimistically')]
	#[Group('strata/b2')]
	public function reportsConservativelyWhenAuthorizationFails(): void
	{
		$provider = $this->provider();
		$this->queueJson(['code' => 'unauthorized', 'message' => 'no such key'], 401);

		$capabilities = $provider->capabilities();

		$this->assertFalse($capabilities->multipart);
		$this->assertFalse($capabilities->checksums);
		$this->assertFalse($provider->isReachable());
		$this->assertStringContainsString('unauthorized', (string) $provider->unreachableReason());
	}

	#[Test]
	#[TestDox('an unroutable endpoint is reported rather than raised out of isReachable')]
	#[Group('strata/b2')]
	public function reachabilityNeverRaises(): void
	{
		$provider = $this->provider();
		$this->queueFailure();

		$this->assertFalse($provider->isReachable());
		$this->assertStringContainsString('answered 0', (string) $provider->unreachableReason());
	}

	#[Test]
	#[TestDox('a key restricted to another bucket is reported now rather than at the first write')]
	#[Group('strata/b2')]
	public function keyForAnotherBucketIsReported(): void
	{
		$provider = $this->provider();
		$this->queueJson(
			$this->authorization([
				'allowed' => [
					'bucketId' => 'someone-elses-bucket',
					'capabilities' => ['writeFiles'],
				],
			]),
		);

		$this->assertFalse($provider->isReachable());
		$this->assertStringContainsString(
			'restricted to bucket someone-elses-bucket',
			(string) $provider->unreachableReason(),
		);
		$this->assertFalse($provider->capabilities()->multipart);
	}

	#[Test]
	#[TestDox('a key that cannot write is reported now rather than at the first write')]
	#[Group('strata/b2')]
	public function readOnlyKeyIsReported(): void
	{
		$provider = $this->provider();
		$this->queueJson(
			$this->authorization([
				'allowed' => [
					'bucketId' => 'bucket-id-1',
					'capabilities' => ['listFiles', 'readFiles'],
				],
			]),
		);

		$this->assertFalse($provider->isReachable());
		$this->assertStringContainsString(
			'not allowed to write files',
			(string) $provider->unreachableReason(),
		);
	}

	#endregion

	#region Round trips

	#[Test]
	#[TestDox('a file written reads back byte for byte and keeps its content hash')]
	#[Group('strata/b2')]
	public function filesRoundTrip(): void
	{
		$payload = random_bytes(4096);
		$provider = $this->probed();

		$this->queueUpload($payload, self::PREFIX . 'frames/ab/cd/object');
		$this->queue(200, $payload);

		$result = $provider->put('frames/ab/cd/object', $payload);

		$this->assertSame('frames/ab/cd/object', $result->key);
		$this->assertSame(4096, $result->size);
		$this->assertSame(sha1($payload), $result->etag);
		$this->assertFalse($result->multipart);
		$this->assertSame($payload, $provider->get('frames/ab/cd/object'));

		$slot = $this->request(0);
		$upload = $this->request(1);

		$this->assertStringEndsWith('/b2api/v2/b2_get_upload_url', (string) $slot->getUri());
		$this->assertSame('bucket-id-1', $this->payload(0)['bucketId'] ?? null);
		$this->assertSame(self::UPLOAD, (string) $upload->getUri());
		$this->assertSame('4_0022_upload', $upload->getHeaderLine('Authorization'));
		$this->assertSame(
			'_strata/site-1/frames/ab/cd/object',
			$upload->getHeaderLine('X-Bz-File-Name'),
		);
		$this->assertSame(sha1($payload), $upload->getHeaderLine('X-Bz-Content-Sha1'));
		$this->assertSame('4096', $upload->getHeaderLine('Content-Length'));
		$this->assertSame('b2/x-auto', $upload->getHeaderLine('Content-Type'));
		$this->assertSame(
			self::DOWNLOAD . '/file/strata-backups/_strata/site-1/frames/ab/cd/object',
			(string) $this->request(2)->getUri(),
		);
	}

	#[Test]
	#[TestDox('a file name is percent-encoded segment by segment in the header that carries it')]
	#[Group('strata/b2')]
	public function fileNamesAreEncodedSegmentBySegment(): void
	{
		$provider = $this->probed();
		$this->queueUpload('payload', self::PREFIX . 'frames/a b');

		$provider->put('frames/a b', 'payload');

		$this->assertSame(
			'_strata/site-1/frames/a%20b',
			$this->request(1)->getHeaderLine('X-Bz-File-Name'),
		);
	}

	#[Test]
	#[TestDox('user metadata travels as file info headers')]
	#[Group('strata/b2')]
	public function metadataTravelsAsFileInfo(): void
	{
		$provider = $this->probed();
		$this->queueUpload('payload', self::PREFIX . 'frames/thing');

		$provider->put('frames/thing', 'payload', [
			'contentType' => 'application/octet-stream',
			'metadata' => ['snapshot' => '42'],
		]);

		$upload = $this->request(1);

		$this->assertSame('42', $upload->getHeaderLine('x-bz-info-snapshot'));
		$this->assertSame('application/octet-stream', $upload->getHeaderLine('Content-Type'));
	}

	#[Test]
	#[TestDox('a stream body reads back identically')]
	#[Group('strata/b2')]
	public function streamBodiesRoundTrip(): void
	{
		$payload = random_bytes(64 * 1024 + 7);
		$provider = $this->probed();
		$stream = fopen('php://temp', 'r+b');

		$this->assertIsResource($stream);
		fwrite($stream, $payload);
		rewind($stream);

		$this->queueUpload($payload, self::PREFIX . 'streamed');

		$result = $provider->put('streamed', $stream);
		fclose($stream);

		$this->assertSame(strlen($payload), $result->size);
		$this->assertSame($payload, (string) $this->request(1)->getBody());
	}

	#[Test]
	#[TestDox('stream() hands back a readable handle positioned at the start')]
	#[Group('strata/b2')]
	public function streamReturnsReadableHandle(): void
	{
		$provider = $this->probed();
		$this->queue(200, 'the whole payload');

		$handle = $provider->stream('streamed');

		$this->assertIsResource($handle);
		$this->assertSame('the whole payload', stream_get_contents($handle));

		fclose($handle);
	}

	#endregion

	#region Upload urls

	#[Test]
	#[TestDox('one upload url serves the next write as well')]
	#[Group('strata/b2')]
	public function uploadUrlIsHeldBetweenWrites(): void
	{
		$provider = $this->probed();

		$this->queueUpload('one', self::PREFIX . 'frames/one');
		$this->queueJson([
			'fileId' => '4_z_file_2',
			'fileName' => self::PREFIX . 'frames/two',
			'contentLength' => 3,
			'contentSha1' => sha1('two'),
		]);

		$provider->put('frames/one', 'one');
		$provider->put('frames/two', 'two');

		$this->assertCount(3, $this->requests(), 'the second write reuses the held url');
		$this->assertSame(self::UPLOAD, (string) $this->request(2)->getUri());
	}

	#[Test]
	#[TestDox('an upload url that has failed once is replaced and the write is retried')]
	#[Group('strata/b2')]
	public function failedUploadUrlIsReplacedOnce(): void
	{
		$provider = $this->probed();

		$this->queueJson(['uploadUrl' => self::UPLOAD, 'authorizationToken' => '4_0022_first']);
		$this->queueJson(['code' => 'service_unavailable', 'message' => 'busy'], 503);
		$this->queueJson([
			'uploadUrl' => self::UPLOAD . '-second',
			'authorizationToken' => '4_0022_second',
		]);
		$this->queueJson([
			'fileId' => '4_z_file_1',
			'fileName' => self::PREFIX . 'frames/thing',
			'contentLength' => 7,
			'contentSha1' => sha1('payload'),
		]);

		$result = $provider->put('frames/thing', 'payload');

		$this->assertSame(7, $result->size);
		$this->assertCount(4, $this->requests());
		$this->assertSame(self::UPLOAD . '-second', (string) $this->request(3)->getUri());
		$this->assertSame('4_0022_second', $this->request(3)->getHeaderLine('Authorization'));
	}

	#[Test]
	#[TestDox('a write that fails twice is reported rather than retried forever')]
	#[Group('strata/b2')]
	public function aWriteThatFailsTwiceIsReported(): void
	{
		$provider = $this->probed();

		$this->queueJson(['uploadUrl' => self::UPLOAD, 'authorizationToken' => '4_0022_first']);
		$this->queueJson(['code' => 'service_unavailable'], 503);
		$this->queueJson(['uploadUrl' => self::UPLOAD, 'authorizationToken' => '4_0022_second']);
		$this->queueJson(['code' => 'service_unavailable'], 503);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot write object frames/thing');

		$provider->put('frames/thing', 'payload');
	}

	#endregion

	#region Verification

	#[Test]
	#[TestDox('a write the account recorded at another size raises rather than reporting success')]
	#[Group('strata/b2')]
	public function shortWriteIsRefused(): void
	{
		$provider = $this->probed();

		$this->queueJson(['uploadUrl' => self::UPLOAD, 'authorizationToken' => '4_0022_upload']);
		$this->queueJson([
			'fileId' => '4_z_file_1',
			'fileName' => self::PREFIX . 'frames/thing',
			'contentLength' => 3,
			'contentSha1' => sha1('payload'),
		]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('written as 7 bytes and the endpoint recorded 3');

		$provider->put('frames/thing', 'payload');
	}

	#[Test]
	#[TestDox('a write the account hashed differently raises')]
	#[Group('strata/b2')]
	public function hashMismatchIsRefused(): void
	{
		$provider = $this->probed();

		$this->queueJson(['uploadUrl' => self::UPLOAD, 'authorizationToken' => '4_0022_upload']);
		$this->queueJson([
			'fileId' => '4_z_file_1',
			'fileName' => self::PREFIX . 'frames/thing',
			'contentLength' => 7,
			'contentSha1' => sha1('something else'),
		]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('hash that is not the one sent');

		$provider->put('frames/thing', 'payload');
	}

	#endregion

	#region Ranges

	#[Test]
	#[TestDox('a byte range asks for exactly the window and returns it')]
	#[Group('strata/b2')]
	public function rangeReturnsExactWindow(): void
	{
		$provider = $this->probed();
		$this->queue(206, '456789');

		$this->assertSame('456789', $provider->get('ranged', new ByteRange(4, 6)));
		$this->assertSame('bytes=4-9', $this->request(0)->getHeaderLine('Range'));
		$this->assertSame('4_0022_account', $this->request(0)->getHeaderLine('Authorization'));
	}

	#[Test]
	#[TestDox('a range answered with fewer bytes than were asked for raises')]
	#[Group('strata/b2')]
	public function shortRangeRaises(): void
	{
		$provider = $this->probed();
		$this->queue(206, '01234');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('returned 5 bytes for a 20 byte range at offset 5');

		$provider->get('short', new ByteRange(5, 20));
	}

	#endregion

	#region Absence

	#[Test]
	#[TestDox('heading an absent file returns null rather than raising')]
	#[Group('strata/b2')]
	public function headingAbsentFileReturnsNull(): void
	{
		$provider = $this->probed();

		$this->queueHeaders(404, []);
		$this->queueHeaders(404, []);

		$this->assertNull($provider->head('never/written'));
		$this->assertFalse($provider->exists('never/written'));
		$this->assertSame('HEAD', $this->request(0)->getMethod());
	}

	#[Test]
	#[TestDox('heading a present file reports its size, hash, timestamp and file info')]
	#[Group('strata/b2')]
	public function headingPresentFileReportsMetadata(): void
	{
		$provider = $this->probed();
		$this->queueHeaders(200, [
			'Content-Length' => '4096',
			'x-bz-file-id' => '4_z_file_1',
			'x-bz-content-sha1' => sha1('payload'),
			'x-bz-upload-timestamp' => '1787140800000',
			'x-bz-info-snapshot' => '42',
		]);

		$meta = $provider->head('frames/ab/cd/object');

		$this->assertInstanceOf(ObjectMeta::class, $meta);
		$this->assertSame('frames/ab/cd/object', $meta->key);
		$this->assertSame(4096, $meta->size);
		$this->assertSame(sha1('payload'), $meta->etag);
		$this->assertSame(1_787_140_800, $meta->modified, 'b2 timestamps are milliseconds');
		$this->assertNull($meta->storageClass, 'b2 has no per-object tier');
		$this->assertSame(['snapshot' => '42'], $meta->metadata);
	}

	#[Test]
	#[TestDox('a large file heads with no entity tag rather than with the word none')]
	#[Group('strata/b2')]
	public function largeFileHeadsWithoutAHash(): void
	{
		$provider = $this->probed();
		$this->queueHeaders(200, [
			'Content-Length' => '10',
			'x-bz-file-id' => '4_z_file_1',
			'x-bz-content-sha1' => 'none',
		]);

		$this->assertNull($provider->head('packs/big.pack')?->etag);
	}

	#[Test]
	#[TestDox('an endpoint that refuses a head falls back to listing the one name')]
	#[Group('strata/b2')]
	public function headFallsBackToListing(): void
	{
		$provider = $this->probed();

		$this->queueHeaders(405, []);
		$this->queueJson([
			'files' => [
				[
					'fileId' => '4_z_file_1',
					'fileName' => self::PREFIX . 'frames/thing',
					'contentLength' => 12,
					'contentSha1' => sha1('payload'),
					'uploadTimestamp' => 1_787_140_800_000,
				],
			],
			'nextFileName' => null,
		]);

		$meta = $provider->head('frames/thing');

		$this->assertNotNull($meta);
		$this->assertSame('frames/thing', $meta->key);
		$this->assertSame(12, $meta->size);
		$this->assertStringEndsWith(
			'/b2api/v2/b2_list_file_names',
			(string) $this->request(1)->getUri(),
		);
		$this->assertSame(self::PREFIX . 'frames/thing', $this->payload(1)['prefix'] ?? null);
	}

	#[Test]
	#[TestDox('reading an absent file raises and names the key')]
	#[Group('strata/b2')]
	public function readingAbsentFileRaises(): void
	{
		$provider = $this->probed();
		$this->queueJson(['code' => 'not_found', 'message' => 'File not present'], 404);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Object missing/thing is not present');

		$provider->get('missing/thing');
	}

	#[Test]
	#[TestDox('a refused read reports the code backblaze sent')]
	#[Group('strata/b2')]
	public function refusedReadReportsTheCode(): void
	{
		$provider = $this->probed();
		$this->queueJson(['code' => 'access_denied', 'message' => 'no read capability'], 403);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('answered 403 (access_denied no read capability)');

		$provider->get('frames/thing');
	}

	#endregion

	#region Deleting

	#[Test]
	#[TestDox('a delete looks the version up and then removes that version')]
	#[Group('strata/b2')]
	public function deleteRemovesTheNamedVersion(): void
	{
		$provider = $this->probed();

		$this->queueHeaders(200, [
			'Content-Length' => '7',
			'x-bz-file-id' => '4_z_file_1',
			'x-bz-content-sha1' => sha1('payload'),
		]);
		$this->queueJson(['fileId' => '4_z_file_1', 'fileName' => self::PREFIX . 'frames/thing']);

		$this->assertSame(1, $provider->delete(['frames/thing']));

		$this->assertStringEndsWith(
			'/b2api/v2/b2_delete_file_version',
			(string) $this->request(1)->getUri(),
		);
		$this->assertSame('4_z_file_1', $this->payload(1)['fileId'] ?? null);
		$this->assertSame(self::PREFIX . 'frames/thing', $this->payload(1)['fileName'] ?? null);
	}

	#[Test]
	#[
		TestDox(
			'deleting an absent key costs one lookup and no delete, so a retried prune is idempotent',
		),
	]
	#[Group('strata/b2')]
	public function deletingAbsentKeyIsIdempotent(): void
	{
		$provider = $this->probed();
		$this->queueHeaders(404, []);

		$this->assertSame(0, $provider->delete(['frames/gone']));
		$this->assertCount(1, $this->requests());
	}

	#[Test]
	#[TestDox('deleting nothing makes no request at all')]
	#[Group('strata/b2')]
	public function deletingNothingMakesNoRequest(): void
	{
		$this->assertSame(0, $this->probed()->delete([]));
		$this->assertSame([], $this->requests());
	}

	#endregion

	#region Listing

	#[Test]
	#[TestDox('a listing pages through everything using the next file name')]
	#[Group('strata/b2')]
	public function listingPagesThroughEverything(): void
	{
		$provider = $this->probed();

		$this->queueJson([
			'files' => [
				[
					'fileName' => self::PREFIX . 'packs/01.pack',
					'fileId' => '4_z_1',
					'contentLength' => 10,
					'contentSha1' => sha1('a'),
					'action' => 'upload',
				],
				[
					'fileName' => self::PREFIX . 'packs/02.pack',
					'fileId' => '4_z_2',
					'contentLength' => 20,
					'contentSha1' => sha1('b'),
					'action' => 'upload',
				],
			],
			'nextFileName' => self::PREFIX . 'packs/03.pack',
		]);
		$this->queueJson([
			'files' => [
				[
					'fileName' => self::PREFIX . 'packs/03.pack',
					'fileId' => '4_z_3',
					'contentLength' => 30,
					'action' => 'upload',
				],
			],
			'nextFileName' => null,
		]);

		$first = $provider->list('packs/', null, 2);

		$this->assertSame(['packs/01.pack', 'packs/02.pack'], $first->keys());
		$this->assertTrue($first->hasMore());
		$this->assertSame(self::PREFIX . 'packs/03.pack', $first->cursor);
		$this->assertSame(30, $first->bytes());
		$this->assertSame(sha1('a'), $first->objects[0]->etag);

		$second = $provider->list('packs/', $first->cursor, 2);

		$this->assertSame(['packs/03.pack'], $second->keys());
		$this->assertFalse($second->hasMore());

		$this->assertSame(2, $this->payload(0)['maxFileCount'] ?? null);
		$this->assertSame(self::PREFIX . 'packs/', $this->payload(0)['prefix'] ?? null);
		$this->assertArrayNotHasKey('startFileName', $this->payload(0));
		$this->assertSame(
			self::PREFIX . 'packs/03.pack',
			$this->payload(1)['startFileName'] ?? null,
		);
	}

	#[Test]
	#[TestDox('a delimited listing reports a folder as a prefix rather than as an object')]
	#[Group('strata/b2')]
	public function foldersAreReportedAsPrefixes(): void
	{
		$provider = $this->probed();
		$this->queueJson([
			'files' => [
				[
					'fileName' => self::PREFIX . 'frames/',
					'action' => 'folder',
					'contentLength' => 0,
				],
				[
					'fileName' => self::PREFIX . 'top',
					'action' => 'upload',
					'contentLength' => 1,
					'fileId' => '4_z_1',
				],
			],
			'nextFileName' => null,
		]);

		$page = $provider->list('', null, 100, '/');

		$this->assertSame(['top'], $page->keys());
		$this->assertSame(['frames/'], $page->prefixes);
		$this->assertSame('/', $this->payload(0)['delimiter'] ?? null);
	}

	#[Test]
	#[TestDox('a listing limit below one is refused before a request is made')]
	#[Group('strata/b2')]
	public function badListingLimitIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('at least one');

		$this->probed()->list('', null, 0);
	}

	#[Test]
	#[TestDox('an expired token is re-authorized once and the call is made again')]
	#[Group('strata/b2')]
	public function anExpiredTokenIsRefreshedOnce(): void
	{
		$provider = $this->probed();

		$this->queueJson(['code' => 'expired_auth_token', 'message' => 'expired'], 401);
		$this->queueJson($this->authorization(['authorizationToken' => '4_0022_renewed']));
		$this->queueJson(['files' => [], 'nextFileName' => null]);

		$this->assertCount(0, $provider->list('frames/'));

		$this->assertCount(3, $this->requests());
		$this->assertStringEndsWith(
			'/b2api/v2/b2_authorize_account',
			(string) $this->request(1)->getUri(),
		);
		$this->assertSame('4_0022_renewed', $this->request(2)->getHeaderLine('Authorization'));
	}

	#endregion

	#region Large files

	#[Test]
	#[TestDox('a body over the threshold is uploaded in parts whose hashes are finished in order')]
	#[Group('strata/b2')]
	public function largeBodyIsUploadedInParts(): void
	{
		$provider = $this->probed(1_048_576);
		$partSize = $provider->capabilities()->partSizeFor(0);
		$payload = str_repeat('s', 2 * $partSize + 11);
		$parts = [
			substr($payload, 0, $partSize),
			substr($payload, $partSize, $partSize),
			substr($payload, 2 * $partSize),
		];

		$this->queueJson(['fileId' => '4_z_large_1']);
		$this->queueJson([
			'uploadUrl' => self::UPLOAD . '/part',
			'authorizationToken' => '4_0022_part',
		]);
		$this->queueJson(['contentSha1' => sha1($parts[0])]);
		$this->queueJson(['contentSha1' => sha1($parts[1])]);
		$this->queueJson(['contentSha1' => sha1($parts[2])]);
		$this->queueJson([
			'fileId' => '4_z_large_1',
			'fileName' => self::PREFIX . 'packs/big.pack',
			'contentLength' => strlen($payload),
			'contentSha1' => 'none',
		]);

		$result = $provider->put('packs/big.pack', $payload);

		$this->assertTrue($result->multipart);
		$this->assertSame(3, $result->parts);
		$this->assertSame(strlen($payload), $result->size);
		$this->assertSame('4_z_large_1', $result->etag);

		$requests = $this->requests();

		$this->assertCount(6, $requests);
		$this->assertStringEndsWith(
			'/b2api/v2/b2_start_large_file',
			(string) $requests[0]->getUri(),
		);
		$this->assertSame(self::PREFIX . 'packs/big.pack', $this->payload(0)['fileName'] ?? null);
		$this->assertStringEndsWith(
			'/b2api/v2/b2_get_upload_part_url',
			(string) $requests[1]->getUri(),
		);

		foreach ([2, 3, 4] as $index) {
			$this->assertSame(self::UPLOAD . '/part', (string) $requests[$index]->getUri());
			$this->assertSame(
				(string) ($index - 1),
				$requests[$index]->getHeaderLine('X-Bz-Part-Number'),
			);
			$this->assertSame(
				sha1($parts[$index - 2]),
				$requests[$index]->getHeaderLine('X-Bz-Content-Sha1'),
			);
		}

		$this->assertSame(
			[sha1($parts[0]), sha1($parts[1]), sha1($parts[2])],
			$this->payload(5)['partSha1Array'] ?? null,
		);
	}

	#[Test]
	#[TestDox('one part url serves every part of the same large file')]
	#[Group('strata/b2')]
	public function onePartUrlServesEveryPart(): void
	{
		$provider = $this->probed(1_048_576);
		$payload = str_repeat('s', 2 * $provider->capabilities()->partSizeFor(0));

		$this->queueJson(['fileId' => '4_z_large_1']);
		$this->queueJson([
			'uploadUrl' => self::UPLOAD . '/part',
			'authorizationToken' => '4_0022_part',
		]);
		$this->queueJson(['contentSha1' => sha1(str_repeat('s', strlen($payload) / 2))]);
		$this->queueJson(['contentSha1' => sha1(str_repeat('s', strlen($payload) / 2))]);
		$this->queueJson([
			'fileId' => '4_z_large_1',
			'fileName' => self::PREFIX . 'packs/exact.pack',
			'contentLength' => strlen($payload),
			'contentSha1' => 'none',
		]);

		$provider->put('packs/exact.pack', $payload);

		$this->assertCount(5, $this->requests(), 'a part url is asked for once, not once per part');
	}

	#[Test]
	#[TestDox('a part that fails cancels the large file rather than leaving it open')]
	#[Group('strata/b2')]
	public function failedPartCancelsTheLargeFile(): void
	{
		$provider = $this->probed(1_048_576);
		$payload = str_repeat('s', 2 * $provider->capabilities()->partSizeFor(0) + 5);

		$this->queueJson(['fileId' => '4_z_large_1']);
		$this->queueJson([
			'uploadUrl' => self::UPLOAD . '/part',
			'authorizationToken' => '4_0022_part',
		]);
		$this->queueJson(['contentSha1' => sha1(str_repeat('s', 1_048_576))]);
		$this->queueJson(['code' => 'bad_request', 'message' => 'checksum mismatch'], 400);
		$this->queueJson(['fileId' => '4_z_large_1']);

		try {
			$provider->put('packs/big.pack', $payload);
			$this->fail('a failed part has to raise');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('upload part 2 of packs/big.pack', $e->getMessage());
		}

		$cancel = $this->request(count($this->requests()) - 1);

		$this->assertStringEndsWith('/b2api/v2/b2_cancel_large_file', (string) $cancel->getUri());
	}

	#[Test]
	#[TestDox('a large file opened without an id is refused instead of uploading parts nowhere')]
	#[Group('strata/b2')]
	public function largeFileWithoutAnIdIsRefused(): void
	{
		$provider = $this->probed(1_048_576);
		$payload = str_repeat('s', 2 * $provider->capabilities()->partSizeFor(0));

		$this->queueJson(['accountId' => 'deadbeef']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('opened a large file for packs/big.pack without naming it');

		$provider->put('packs/big.pack', $payload);
	}

	#[Test]
	#[TestDox('the same body is a single request when no threshold is configured')]
	#[Group('strata/b2')]
	public function sameBodyIsSingleUploadWithoutThreshold(): void
	{
		$provider = $this->probed();
		$payload = str_repeat('x', 4 * 1_048_576);

		$this->queueUpload($payload, self::PREFIX . 'frames/big');

		$result = $provider->put('frames/big', $payload);

		$this->assertFalse($result->multipart);
		$this->assertCount(2, $this->requests());
	}

	#[Test]
	#[TestDox('a threshold can only lower where parts begin, never raise it')]
	#[Group('strata/b2')]
	public function thresholdCannotRaiseTheCeiling(): void
	{
		$this->assertSame(
			B2StorageProvider::MAX_SINGLE_PUT,
			$this->probed(PHP_INT_MAX)->capabilities()->maxSinglePut,
		);
	}

	#endregion

	#region Refusals

	#[Test]
	#[TestDox('a conditional write is refused, because b2 has no precondition to send')]
	#[Group('strata/b2')]
	public function conditionalWriteIsRefused(): void
	{
		$provider = $this->probed();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('does not honour a conditional write');

		$provider->put('refs/head', 'value', ['ifNoneMatch' => true]);
	}

	#[Test]
	#[TestDox('a write naming a storage class is refused rather than silently dropping it')]
	#[Group('strata/b2')]
	public function storageClassIsRefused(): void
	{
		$provider = $this->probed();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('no storage class');

		$provider->put('frames/thing', 'payload', ['storageClass' => 'GLACIER']);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function unsafeKeyProvider(): array
	{
		return [
			'parent traversal' => ['../escape'],
			'interior traversal' => ['frames/../../escape'],
			'current directory' => ['frames/./thing'],
			'empty segment' => ['frames//thing'],
			'empty key' => [''],
			'only slashes' => ['///'],
			'a null byte' => ["frames/th\0ing"],
			'the reserved suffix' => ['frames/thing.tmp'],
		];
	}

	#[Test]
	#[TestDox('a key containing $_dataName is refused before it reaches the endpoint')]
	#[Group('strata/b2')]
	#[DataProvider('unsafeKeyProvider')]
	public function unsafeKeysAreRefused(string $key): void
	{
		$provider = $this->probed();

		$this->expectException(InvalidArgumentException::class);

		try {
			$provider->put($key, 'payload');
		} finally {
			$this->assertSame([], $this->requests(), 'an unsafe key must not reach the transport');
		}
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function unusableBodyProvider(): array
	{
		return [
			'an integer' => [42],
			'an array' => [['payload']],
			'nothing at all' => [null],
		];
	}

	#[Test]
	#[TestDox('a body that is $_dataName rather than a string or a stream is refused')]
	#[Group('strata/b2')]
	#[DataProvider('unusableBodyProvider')]
	public function refusesBodyThatIsNeitherStringNorStream(mixed $body): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('string or an open stream resource');

		$this->probed()->put('frames/thing', $body);
	}

	#[Test]
	#[TestDox('an endpoint named only one way is refused at construction')]
	#[Group('strata/b2')]
	public function endpointNeedsBothNames(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('bucket name');

		new B2Endpoint('bucket-id-1', '');
	}

	#endregion
}
