<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_gcs\Unit;

use Drupal\strata\Storage\ByteRange;
use Drupal\strata\Storage\HttpTransport;
use Drupal\strata\Storage\ObjectMeta;
use Drupal\strata_gcs\Credentials\StaticAccessToken;
use Drupal\strata_gcs\GcsEndpoint;
use Drupal\strata_gcs\GcsStorageProvider;
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
 * Drives the whole Cloud Storage provider over canned responses with no network.
 *
 * The provider is exercised through the real Guzzle transport against a MockHandler, so the requests
 * these assertions read are the ones that would go on the wire.
 *
 * Two of these are the reason the lane exists. A resumable session is finalised by a short chunk or
 * by an empty request naming the total, and getting that wrong leaves an upload that answers 308
 * forever; and a write is only accepted when the size and digest Google recorded are the ones that
 * were sent, which is what catches a chunk that never arrived.
 */
#[CoversClass(GcsStorageProvider::class)]
#[CoversClass(GcsEndpoint::class)]
class GcsStorageProviderTest extends TestCase
{
	#region Fixtures

	/**
	 * Prefix the engine puts every key under.
	 */
	private const PREFIX = '_strata/site-1/';

	/**
	 * Where a resumable session is opened at.
	 */
	private const SESSION = 'https://storage.googleapis.com/upload/session/abc123';

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
	 * @param array<string, string> $headers
	 *   Response headers.
	 */
	private function queue(int $status, string $body = '', array $headers = []): void
	{
		$this->handler->append(new Response($status, $headers, $body));
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
	 * A provider whose capability probe has not run yet.
	 *
	 * @param GcsEndpoint|null $endpoint
	 *   The endpoint, or NULL for the default bucket.
	 *
	 * @return GcsStorageProvider
	 *   The provider.
	 */
	private function provider(?GcsEndpoint $endpoint = null): GcsStorageProvider
	{
		$stack = HandlerStack::create($this->handler);
		$stack->push(Middleware::history($this->history));

		return new GcsStorageProvider(
			new StaticAccessToken('ya29.test-token'),
			$endpoint ?? $this->endpoint(),
			new HttpTransport(new Client(['handler' => $stack])),
		);
	}

	/**
	 * A provider whose capability probe has already run and been forgotten.
	 *
	 * @param GcsEndpoint|null $endpoint
	 *   The endpoint, or NULL for the default bucket.
	 *
	 * @return GcsStorageProvider
	 *   The provider, with the probe's request cleared out of the history.
	 */
	private function probed(?GcsEndpoint $endpoint = null): GcsStorageProvider
	{
		$provider = $this->provider($endpoint);

		$this->queue(200, '{"kind":"storage#objects","items":[]}');
		$provider->capabilities();
		$this->history = [];

		return $provider;
	}

	/**
	 * The default endpoint.
	 *
	 * @param string|null $class
	 *   Storage class, or NULL for the bucket default.
	 * @param int|null $threshold
	 *   Resumable threshold, or NULL for the endpoint maximum.
	 *
	 * @return GcsEndpoint
	 *   The endpoint.
	 */
	private function endpoint(?string $class = null, ?int $threshold = null): GcsEndpoint
	{
		return new GcsEndpoint('strata-backups', self::PREFIX, null, $class, $threshold);
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
	 * An object resource, as the endpoint answers a write or a head with.
	 *
	 * @param string $name
	 *   The object name, already prefixed.
	 * @param string $body
	 *   The bytes it holds, which the size and digest are computed from.
	 * @param array<string, mixed> $extra
	 *   Fields to add or override.
	 *
	 * @return string
	 *   The response body.
	 */
	private function object(string $name, string $body, array $extra = []): string
	{
		return (string) json_encode(
			array_merge(
				[
					'kind' => 'storage#object',
					'name' => $name,
					'bucket' => 'strata-backups',
					'size' => (string) strlen($body),
					'md5Hash' => base64_encode(md5($body, true)),
					'etag' => 'CPjJ8fLm',
					'updated' => '2026-08-19T12:00:00.000Z',
					'storageClass' => 'STANDARD',
				],
				$extra,
			),
		);
	}

	#endregion

	#region Identity

	#[Test]
	#[TestDox('the provider reports a stable id, a label and a coherent capability set')]
	#[Group('strata/gcs')]
	public function reportsCoherentIdentity(): void
	{
		$provider = $this->provider();
		$this->queue(200, '{"kind":"storage#objects","items":[]}');

		$capabilities = $provider->capabilities();

		$this->assertSame('gcs', $provider->id());
		$this->assertSame(strtolower($provider->id()), $provider->id());
		$this->assertSame('Google Cloud Storage', $provider->label());
		$this->assertTrue($provider->isReachable());
		$this->assertNull($provider->unreachableReason());
		$this->assertFalse($capabilities->batchDelete, 'the batch endpoint is deprecated');
		$this->assertTrue($capabilities->conditionalWrite);
		$this->assertSame(1, $capabilities->deleteBatchSize());
		$this->assertSame(
			0,
			$capabilities->partSizeFor(0) % GcsEndpoint::CHUNK_ALIGNMENT,
			'every chunk size has to be a multiple of 256 KiB',
		);
		$this->assertSame('strata-backups', $provider->endpoint()->bucket);
	}

	#[Test]
	#[TestDox('the endpoint is probed once however often capabilities are asked for')]
	#[Group('strata/gcs')]
	public function probesOnceAndCaches(): void
	{
		$provider = $this->provider();
		$this->queue(200, '{"kind":"storage#objects","items":[]}');

		$first = $provider->capabilities();
		$provider->capabilities();
		$provider->isReachable();

		$this->assertSame($first, $provider->capabilities());
		$this->assertCount(1, $this->requests());
		$this->assertStringContainsString('maxResults=1', (string) $this->request(0)->getUri());
		$this->assertSame(
			'Bearer ya29.test-token',
			$this->request(0)->getHeaderLine('Authorization'),
		);
	}

	#[Test]
	#[TestDox('an endpoint that refuses the probe reports conservatively, not optimistically')]
	#[Group('strata/gcs')]
	public function reportsConservativelyWhenTheProbeFails(): void
	{
		$provider = $this->provider();
		$this->queue(403, '{"error":{"code":403,"message":"Permission denied."}}');

		$capabilities = $provider->capabilities();

		$this->assertFalse($capabilities->multipart);
		$this->assertFalse($capabilities->conditionalWrite);
		$this->assertFalse($capabilities->checksums);
		$this->assertFalse($capabilities->storageClasses);
		$this->assertFalse($provider->isReachable());
		$this->assertStringContainsString(
			'Permission denied.',
			(string) $provider->unreachableReason(),
		);
	}

	#[Test]
	#[TestDox('an unroutable endpoint is reported rather than raised out of isReachable')]
	#[Group('strata/gcs')]
	public function reachabilityNeverRaises(): void
	{
		$provider = $this->provider();
		$this->queueFailure();

		$this->assertFalse($provider->isReachable());
		$this->assertStringContainsString('answered 0', (string) $provider->unreachableReason());
	}

	#endregion

	#region Round trips

	#[Test]
	#[TestDox('an object written reads back byte for byte and keeps its entity tag')]
	#[Group('strata/gcs')]
	public function objectsRoundTrip(): void
	{
		$payload = random_bytes(4096);
		$provider = $this->probed();

		$this->queue(200, $this->object(self::PREFIX . 'frames/ab/cd/object', $payload));
		$this->queue(200, $payload);

		$result = $provider->put('frames/ab/cd/object', $payload);

		$this->assertSame('frames/ab/cd/object', $result->key);
		$this->assertSame(4096, $result->size);
		$this->assertSame('CPjJ8fLm', $result->etag);
		$this->assertFalse($result->multipart);
		$this->assertSame(1, $result->parts);
		$this->assertSame($payload, $provider->get('frames/ab/cd/object'));

		$put = $this->request(0);

		$this->assertSame('POST', $put->getMethod());
		$this->assertStringContainsString('uploadType=media', (string) $put->getUri());
		$this->assertStringContainsString(
			'name=_strata%2Fsite-1%2Fframes%2Fab%2Fcd%2Fobject',
			(string) $put->getUri(),
		);
		$this->assertSame('application/octet-stream', $put->getHeaderLine('Content-Type'));
		$this->assertStringContainsString('alt=media', (string) $this->request(1)->getUri());
	}

	#[Test]
	#[TestDox('an object name is percent-encoded whole, slashes included')]
	#[Group('strata/gcs')]
	public function objectNamesAreEncodedWhole(): void
	{
		$provider = $this->probed();
		$this->queue(200, $this->object(self::PREFIX . 'frames/thing', 'payload'));

		$provider->head('frames/thing');

		$this->assertStringEndsWith(
			'/storage/v1/b/strata-backups/o/_strata%2Fsite-1%2Fframes%2Fthing',
			(string) $this->request(0)->getUri(),
		);
	}

	#[Test]
	#[TestDox('a stream body reads back identically')]
	#[Group('strata/gcs')]
	public function streamBodiesRoundTrip(): void
	{
		$payload = random_bytes(64 * 1024 + 7);
		$provider = $this->probed();
		$stream = fopen('php://temp', 'r+b');

		$this->assertIsResource($stream);
		fwrite($stream, $payload);
		rewind($stream);

		$this->queue(200, $this->object(self::PREFIX . 'streamed', $payload));

		$result = $provider->put('streamed', $stream);
		fclose($stream);

		$this->assertSame(strlen($payload), $result->size);
		$this->assertSame($payload, (string) $this->request(0)->getBody());
	}

	#[Test]
	#[TestDox('stream() hands back a readable handle positioned at the start')]
	#[Group('strata/gcs')]
	public function streamReturnsReadableHandle(): void
	{
		$provider = $this->probed();
		$this->queue(200, 'the whole payload');

		$handle = $provider->stream('streamed');

		$this->assertIsResource($handle);
		$this->assertSame('the whole payload', stream_get_contents($handle));

		fclose($handle);
	}

	#[Test]
	#[TestDox('a write naming metadata is sent as multipart with a json part')]
	#[Group('strata/gcs')]
	public function metadataForcesAMultipartUpload(): void
	{
		$provider = $this->probed();
		$this->queue(200, $this->object(self::PREFIX . 'packs/one.pack', 'payload'));

		$provider->put('packs/one.pack', 'payload', [
			'contentType' => 'application/octet-stream',
			'storageClass' => 'NEARLINE',
			'metadata' => ['snapshot' => '42'],
		]);

		$request = $this->request(0);
		$body = (string) $request->getBody();

		$this->assertStringContainsString('uploadType=multipart', (string) $request->getUri());
		$this->assertStringStartsWith(
			'multipart/related; boundary=',
			$request->getHeaderLine('Content-Type'),
		);
		$this->assertStringContainsString('"name":"_strata\/site-1\/packs\/one.pack"', $body);
		$this->assertStringContainsString('"storageClass":"NEARLINE"', $body);
		$this->assertStringContainsString('"metadata":{"snapshot":"42"}', $body);
		$this->assertStringContainsString("\r\n\r\npayload\r\n--", $body);
	}

	#[Test]
	#[TestDox('the storage class configured on the endpoint applies when a write names none')]
	#[Group('strata/gcs')]
	public function endpointStorageClassAppliesByDefault(): void
	{
		$provider = $this->probed($this->endpoint('COLDLINE'));
		$this->queue(200, $this->object(self::PREFIX . 'frames/thing', 'payload'));

		$provider->put('frames/thing', 'payload');

		$this->assertStringContainsString(
			'"storageClass":"COLDLINE"',
			(string) $this->request(0)->getBody(),
		);
	}

	#endregion

	#region Verification

	#[Test]
	#[TestDox('a write the endpoint recorded at another size raises rather than reporting success')]
	#[Group('strata/gcs')]
	public function shortWriteIsRefused(): void
	{
		$provider = $this->probed();
		$this->queue(200, $this->object(self::PREFIX . 'frames/thing', 'payload', ['size' => '3']));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('written as 7 bytes and the endpoint recorded 3');

		$provider->put('frames/thing', 'payload');
	}

	#[Test]
	#[TestDox('a write the endpoint digested differently raises')]
	#[Group('strata/gcs')]
	public function checksumMismatchIsRefused(): void
	{
		$provider = $this->probed();
		$this->queue(
			200,
			$this->object(self::PREFIX . 'frames/thing', 'payload', [
				'md5Hash' => base64_encode(md5('something else', true)),
			]),
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('digest that is not the one sent');

		$provider->put('frames/thing', 'payload');
	}

	#endregion

	#region Ranges

	#[Test]
	#[TestDox('a byte range asks for exactly the window and returns it')]
	#[Group('strata/gcs')]
	public function rangeReturnsExactWindow(): void
	{
		$provider = $this->probed();
		$this->queue(206, '456789');

		$this->assertSame('456789', $provider->get('ranged', new ByteRange(4, 6)));
		$this->assertSame('bytes=4-9', $this->request(0)->getHeaderLine('Range'));
	}

	#[Test]
	#[TestDox('a range answered with fewer bytes than were asked for raises')]
	#[Group('strata/gcs')]
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
	#[TestDox('heading an absent object returns null rather than raising')]
	#[Group('strata/gcs')]
	public function headingAbsentObjectReturnsNull(): void
	{
		$provider = $this->probed();

		$this->queue(404, '{"error":{"code":404,"message":"No such object."}}');
		$this->queue(404, '{"error":{"code":404,"message":"No such object."}}');

		$this->assertNull($provider->head('never/written'));
		$this->assertFalse($provider->exists('never/written'));
	}

	#[Test]
	#[TestDox('heading a present object reports its size, tag, class and metadata')]
	#[Group('strata/gcs')]
	public function headingPresentObjectReportsMetadata(): void
	{
		$provider = $this->probed();
		$this->queue(
			200,
			$this->object(self::PREFIX . 'frames/ab/cd/object', str_repeat('x', 4096), [
				'metadata' => ['snapshot' => '42'],
			]),
		);

		$meta = $provider->head('frames/ab/cd/object');

		$this->assertInstanceOf(ObjectMeta::class, $meta);
		$this->assertSame('frames/ab/cd/object', $meta->key);
		$this->assertSame(4096, $meta->size);
		$this->assertSame('CPjJ8fLm', $meta->etag);
		$this->assertSame(strtotime('2026-08-19T12:00:00.000Z'), $meta->modified);
		$this->assertSame('STANDARD', $meta->storageClass);
		$this->assertSame(['snapshot' => '42'], $meta->metadata);
	}

	#[Test]
	#[TestDox('reading an absent object raises and names the key')]
	#[Group('strata/gcs')]
	public function readingAbsentObjectRaises(): void
	{
		$provider = $this->probed();
		$this->queue(404, '{"error":{"code":404,"message":"No such object."}}');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Object missing/thing is not present');

		$provider->get('missing/thing');
	}

	#[Test]
	#[TestDox('a refused read reports the message the endpoint sent')]
	#[Group('strata/gcs')]
	public function refusedReadReportsTheEndpointMessage(): void
	{
		$provider = $this->probed();
		$this->queue(403, '{"error":{"code":403,"message":"strata@x does not have access."}}');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('answered 403 (strata@x does not have access.)');

		$provider->get('frames/thing');
	}

	#[Test]
	#[TestDox('a body that is not json is reported rather than parsed into nothing')]
	#[Group('strata/gcs')]
	public function nonJsonBodyIsReported(): void
	{
		$provider = $this->probed();
		$this->queue(200, '<html>a proxy answered instead</html>');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot list objects: the endpoint answered with a body');

		$provider->list('');
	}

	#endregion

	#region Conditional writes

	#[Test]
	#[TestDox('a conditional write sends ifGenerationMatch and reports a conflict as one')]
	#[Group('strata/gcs')]
	public function conditionalWriteReportsConflict(): void
	{
		$provider = $this->probed();
		$this->queue(412, '{"error":{"code":412,"message":"Precondition Failed"}}');

		try {
			$provider->put('refs/head', 'second', ['ifNoneMatch' => true]);
			$this->fail('a conditional write over an existing key has to raise');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('conditional on its absence', $e->getMessage());
		}

		$this->assertStringContainsString(
			'ifGenerationMatch=0',
			(string) $this->request(0)->getUri(),
		);
	}

	#[Test]
	#[TestDox('a conditional write is refused on an endpoint that could not be probed')]
	#[Group('strata/gcs')]
	public function conditionalWriteRefusedWhereUnsupported(): void
	{
		$provider = $this->provider();
		$this->queue(500, '{}');
		$provider->capabilities();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('does not honour a conditional write');

		$provider->put('refs/head', 'value', ['ifNoneMatch' => true]);
	}

	#endregion

	#region Resumable

	#[Test]
	#[TestDox('a body over the threshold is uploaded in aligned chunks and finalised by the last')]
	#[Group('strata/gcs')]
	public function largeBodyIsUploadedInChunks(): void
	{
		$provider = $this->probed($this->endpoint(null, 1_048_576));
		$chunk = $provider->capabilities()->partSizeFor(0);
		$payload = str_repeat('s', 2 * $chunk + 11);

		$this->queue(200, '', ['Location' => self::SESSION]);
		$this->queue(308);
		$this->queue(308);
		$this->queue(200, $this->object(self::PREFIX . 'packs/big.pack', $payload));

		$result = $provider->put('packs/big.pack', $payload);

		$this->assertTrue($result->multipart);
		$this->assertSame(3, $result->parts);
		$this->assertSame(strlen($payload), $result->size);

		$requests = $this->requests();

		$this->assertCount(4, $requests);
		$this->assertStringContainsString('uploadType=resumable', (string) $requests[0]->getUri());
		$this->assertSame(
			'application/octet-stream',
			$requests[0]->getHeaderLine('X-Upload-Content-Type'),
		);

		foreach ([1, 2, 3] as $index) {
			$this->assertSame('PUT', $requests[$index]->getMethod());
			$this->assertSame(self::SESSION, (string) $requests[$index]->getUri());
		}

		$this->assertSame(
			sprintf('bytes 0-%d/*', $chunk - 1),
			$requests[1]->getHeaderLine('Content-Range'),
		);
		$this->assertSame(
			sprintf('bytes %d-%d/*', $chunk, 2 * $chunk - 1),
			$requests[2]->getHeaderLine('Content-Range'),
		);
		$this->assertSame(
			sprintf('bytes %d-%d/%d', 2 * $chunk, strlen($payload) - 1, strlen($payload)),
			$requests[3]->getHeaderLine('Content-Range'),
			'only the last chunk knows the total',
		);
	}

	#[Test]
	#[TestDox('a body ending exactly on a chunk boundary is finalised by an empty request')]
	#[Group('strata/gcs')]
	public function bodyEndingOnChunkBoundaryIsFinalisedSeparately(): void
	{
		$provider = $this->probed($this->endpoint(null, 1_048_576));
		$chunk = $provider->capabilities()->partSizeFor(0);
		$payload = str_repeat('s', 2 * $chunk);

		$this->queue(200, '', ['Location' => self::SESSION]);
		$this->queue(308);
		$this->queue(308);
		$this->queue(200, $this->object(self::PREFIX . 'packs/exact.pack', $payload));

		$result = $provider->put('packs/exact.pack', $payload);

		$this->assertSame(2, $result->parts);
		$this->assertSame(strlen($payload), $result->size);

		$final = $this->request(3);

		$this->assertSame('', (string) $final->getBody());
		$this->assertSame(
			sprintf('bytes */%d', strlen($payload)),
			$final->getHeaderLine('Content-Range'),
		);
	}

	#[Test]
	#[TestDox('a session that will not open is refused rather than uploading into nowhere')]
	#[Group('strata/gcs')]
	public function sessionWithoutALocationIsRefused(): void
	{
		$provider = $this->probed($this->endpoint(null, 1_048_576));
		$payload = str_repeat('s', 2 * $provider->capabilities()->partSizeFor(0));

		$this->queue(200, '{}');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('opened a session for packs/big.pack without naming it');

		$provider->put('packs/big.pack', $payload);
	}

	#[Test]
	#[TestDox('a chunk that fails cancels the session rather than leaving it open')]
	#[Group('strata/gcs')]
	public function failedChunkCancelsTheSession(): void
	{
		$provider = $this->probed($this->endpoint(null, 1_048_576));
		$payload = str_repeat('s', 2 * $provider->capabilities()->partSizeFor(0) + 5);

		$this->queue(200, '', ['Location' => self::SESSION]);
		$this->queue(308);
		$this->queue(503, '{"error":{"code":503,"message":"Backend Error"}}');
		$this->queue(499);

		try {
			$provider->put('packs/big.pack', $payload);
			$this->fail('a failed chunk has to raise');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('upload the chunk at offset', $e->getMessage());
		}

		$cancel = $this->request(3);

		$this->assertSame('DELETE', $cancel->getMethod());
		$this->assertSame(self::SESSION, (string) $cancel->getUri());
	}

	#[Test]
	#[TestDox('the same body is a single request when no threshold is configured')]
	#[Group('strata/gcs')]
	public function sameBodyIsSingleRequestWithoutThreshold(): void
	{
		$provider = $this->probed();
		$payload = str_repeat('x', 12 * 1_048_576);

		$this->queue(200, $this->object(self::PREFIX . 'frames/big', $payload));

		$result = $provider->put('frames/big', $payload);

		$this->assertFalse($result->multipart);
		$this->assertCount(1, $this->requests());
	}

	#[Test]
	#[TestDox('a threshold can only lower where a session begins, never raise it')]
	#[Group('strata/gcs')]
	public function thresholdCannotRaiseTheCeiling(): void
	{
		$provider = $this->probed($this->endpoint(null, PHP_INT_MAX));

		$this->assertSame(
			GcsStorageProvider::MAX_SINGLE_PUT,
			$provider->capabilities()->maxSinglePut,
		);
	}

	#endregion

	#region Deleting

	#[Test]
	#[TestDox('a delete removes one object per request and counts only what went away')]
	#[Group('strata/gcs')]
	public function deleteCountsWhatWentAway(): void
	{
		$provider = $this->probed();

		$this->queue(204);
		$this->queue(404, '{"error":{"code":404,"message":"No such object."}}');
		$this->queue(204);

		$this->assertSame(2, $provider->delete(['present', 'gone', 'also-present']));

		$requests = $this->requests();

		$this->assertCount(3, $requests);
		$this->assertSame('DELETE', $requests[0]->getMethod());
		$this->assertStringEndsWith('_strata%2Fsite-1%2Fpresent', (string) $requests[0]->getUri());
	}

	#[Test]
	#[TestDox('deleting nothing makes no request at all')]
	#[Group('strata/gcs')]
	public function deletingNothingMakesNoRequest(): void
	{
		$this->assertSame(0, $this->probed()->delete([]));
		$this->assertSame([], $this->requests());
	}

	#[Test]
	#[TestDox('a refused delete is reported by key')]
	#[Group('strata/gcs')]
	public function refusedDeleteIsReported(): void
	{
		$provider = $this->probed();
		$this->queue(403, '{"error":{"code":403,"message":"Permission denied."}}');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot delete object frames/thing');

		$provider->delete(['frames/thing']);
	}

	#endregion

	#region Listing

	#[Test]
	#[TestDox('a listing pages through everything using the page token')]
	#[Group('strata/gcs')]
	public function listingPagesThroughEverything(): void
	{
		$provider = $this->probed();

		$this->queue(
			200,
			(string) json_encode([
				'kind' => 'storage#objects',
				'items' => [
					['name' => self::PREFIX . 'packs/01.pack', 'size' => '10', 'etag' => 'a'],
					['name' => self::PREFIX . 'packs/02.pack', 'size' => '20', 'etag' => 'b'],
				],
				'nextPageToken' => 'TOKEN-PAGE-2',
			]),
		);
		$this->queue(
			200,
			(string) json_encode([
				'kind' => 'storage#objects',
				'items' => [['name' => self::PREFIX . 'packs/03.pack', 'size' => '30']],
			]),
		);

		$first = $provider->list('packs/', null, 2);

		$this->assertSame(['packs/01.pack', 'packs/02.pack'], $first->keys());
		$this->assertTrue($first->hasMore());
		$this->assertSame('TOKEN-PAGE-2', $first->cursor);
		$this->assertSame(30, $first->bytes());

		$second = $provider->list('packs/', $first->cursor, 2);

		$this->assertSame(['packs/03.pack'], $second->keys());
		$this->assertFalse($second->hasMore());

		$this->assertStringContainsString('maxResults=2', (string) $this->request(0)->getUri());
		$this->assertStringNotContainsString('pageToken', (string) $this->request(0)->getUri());
		$this->assertStringContainsString(
			'pageToken=TOKEN-PAGE-2',
			(string) $this->request(1)->getUri(),
		);
	}

	#[Test]
	#[TestDox('a delimiter groups objects into prefixes the engine recognises')]
	#[Group('strata/gcs')]
	public function delimiterGroupsObjects(): void
	{
		$provider = $this->probed();
		$this->queue(
			200,
			(string) json_encode([
				'kind' => 'storage#objects',
				'items' => [['name' => self::PREFIX . 'top', 'size' => '1']],
				'prefixes' => [self::PREFIX . 'frames/'],
			]),
		);

		$page = $provider->list('', null, 100, '/');

		$this->assertSame(['top'], $page->keys());
		$this->assertSame(['frames/'], $page->prefixes);
		$this->assertStringContainsString('delimiter=%2F', (string) $this->request(0)->getUri());
	}

	#[Test]
	#[TestDox('a directory marker another tool left behind is not reported as an object')]
	#[Group('strata/gcs')]
	public function directoryMarkersAreNotObjects(): void
	{
		$provider = $this->probed();
		$this->queue(
			200,
			(string) json_encode([
				'items' => [
					['name' => self::PREFIX . 'frames/', 'size' => '0'],
					['name' => self::PREFIX . 'frames/thing', 'size' => '5'],
				],
			]),
		);

		$this->assertSame(['frames/thing'], $provider->list('')->keys());
	}

	#[Test]
	#[TestDox('a listing limit below one is refused before a request is made')]
	#[Group('strata/gcs')]
	public function badListingLimitIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('at least one');

		$this->probed()->list('', null, 0);
	}

	#endregion

	#region Key safety

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
	#[Group('strata/gcs')]
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
	#[Group('strata/gcs')]
	#[DataProvider('unusableBodyProvider')]
	public function refusesBodyThatIsNeitherStringNorStream(mixed $body): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('string or an open stream resource');

		$this->probed()->put('frames/thing', $body);
	}

	#endregion
}
