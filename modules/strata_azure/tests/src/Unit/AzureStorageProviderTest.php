<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_azure\Unit;

use Drupal\strata\Storage\ByteRange;
use Drupal\strata\Storage\HttpTransport;
use Drupal\strata\Storage\ObjectMeta;
use Drupal\strata_azure\AzureCredentials;
use Drupal\strata_azure\AzureEndpoint;
use Drupal\strata_azure\AzureStorageProvider;
use Drupal\strata_azure\SharedKeySigner;
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
use SimpleXMLElement;

/**
 * Drives the whole Azure provider over canned responses with no network.
 *
 * The provider is exercised through the real Guzzle transport against a MockHandler rather than
 * against a hand-written closure, so the requests these assertions read are the ones that would go
 * on the wire, headers and all.
 *
 * What it proves is the contract StorageProviderInterface binds every provider to: a key is
 * prefixed on the way out and stripped on the way back, a short read raises rather than returning
 * what arrived, an absent blob heads as NULL rather than as an error, and a capability that was
 * never probed is reported off rather than assumed on.
 */
#[CoversClass(AzureStorageProvider::class)]
#[CoversClass(AzureEndpoint::class)]
class AzureStorageProviderTest extends TestCase
{
	#region Fixtures

	/**
	 * Prefix the engine puts every key under.
	 */
	private const PREFIX = '_strata/site-1/';

	/**
	 * Account every fixture is addressed at.
	 */
	private const ACCOUNT = 'strataaccount';

	/**
	 * An account key that is valid base64, which is all a signature needs of one.
	 */
	private const KEY = 'c3RyYXRhLXNoYXJlZC1rZXktZm9yLXRlc3Rpbmc=';

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
	 * @param AzureEndpoint|null $endpoint
	 *   The endpoint, or NULL for the default container.
	 * @param AzureCredentials|null $credentials
	 *   The credentials, or NULL for an account key.
	 *
	 * @return AzureStorageProvider
	 *   The provider.
	 */
	private function provider(
		?AzureEndpoint $endpoint = null,
		?AzureCredentials $credentials = null,
	): AzureStorageProvider {
		$stack = HandlerStack::create($this->handler);
		$stack->push(Middleware::history($this->history));

		return new AzureStorageProvider(
			$credentials ?? new AzureCredentials(self::ACCOUNT, self::KEY),
			$endpoint ?? $this->endpoint(),
			new HttpTransport(new Client(['handler' => $stack])),
			new SharedKeySigner(),
		);
	}

	/**
	 * A provider whose capability probe has already run and been forgotten.
	 *
	 * @param AzureEndpoint|null $endpoint
	 *   The endpoint, or NULL for the default container.
	 * @param AzureCredentials|null $credentials
	 *   The credentials, or NULL for an account key.
	 *
	 * @return AzureStorageProvider
	 *   The provider, with the probe's request cleared out of the history.
	 */
	private function probed(
		?AzureEndpoint $endpoint = null,
		?AzureCredentials $credentials = null,
	): AzureStorageProvider {
		$provider = $this->provider($endpoint, $credentials);

		$this->queue(200, '<EnumerationResults><Blobs /></EnumerationResults>');
		$provider->capabilities();
		$this->history = [];

		return $provider;
	}

	/**
	 * The default endpoint.
	 *
	 * @param string|null $tier
	 *   Access tier, or NULL for the container default.
	 * @param int|null $threshold
	 *   Block threshold, or NULL for the endpoint maximum.
	 *
	 * @return AzureEndpoint
	 *   The endpoint.
	 */
	private function endpoint(?string $tier = null, ?int $threshold = null): AzureEndpoint
	{
		return new AzureEndpoint(
			self::ACCOUNT,
			'backups',
			AzureEndpoint::PUBLIC_SUFFIX,
			null,
			self::PREFIX,
			$tier,
			$threshold,
		);
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
	 * Parses a request or response body.
	 *
	 * @param string $body
	 *   The body.
	 *
	 * @return SimpleXMLElement
	 *   The parsed document.
	 */
	private function parse(string $body): SimpleXMLElement
	{
		$document = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET);

		$this->assertInstanceOf(SimpleXMLElement::class, $document);

		return $document;
	}

	/**
	 * A List Blobs response.
	 *
	 * @param list<string> $names
	 *   Blob names, already prefixed.
	 * @param string|null $marker
	 *   Marker for the next page, or NULL when this is the last one.
	 * @param list<string> $prefixes
	 *   Blob prefixes, already prefixed.
	 *
	 * @return string
	 *   The response body.
	 */
	private function listing(array $names, ?string $marker = null, array $prefixes = []): string
	{
		$xml = '<?xml version="1.0" encoding="utf-8"?><EnumerationResults><Blobs>';

		foreach ($names as $index => $name) {
			$xml .= sprintf(
				'<Blob><Name>%s</Name><Properties>' .
					'<Last-Modified>Wed, 19 Aug 2026 12:00:00 GMT</Last-Modified>' .
					'<Etag>"tag-%d"</Etag><Content-Length>%d</Content-Length>' .
					'<AccessTier>Hot</AccessTier></Properties></Blob>',
				$name,
				$index + 1,
				($index + 1) * 10,
			);
		}

		foreach ($prefixes as $prefix) {
			$xml .= sprintf('<BlobPrefix><Name>%s</Name></BlobPrefix>', $prefix);
		}

		return $xml .
			'</Blobs><NextMarker>' .
			($marker ?? '') .
			'</NextMarker></EnumerationResults>';
	}

	#endregion

	#region Identity

	#[Test]
	#[TestDox('the provider reports a stable id, a label and a coherent capability set')]
	#[Group('strata/azure')]
	public function reportsCoherentIdentity(): void
	{
		$provider = $this->provider();

		$this->queue(200, '<EnumerationResults><Blobs /></EnumerationResults>');

		$capabilities = $provider->capabilities();

		$this->assertSame('azure', $provider->id());
		$this->assertSame(strtolower($provider->id()), $provider->id());
		$this->assertSame('Azure Blob Storage', $provider->label());
		$this->assertTrue($provider->isReachable());
		$this->assertNull($provider->unreachableReason());
		$this->assertGreaterThan(0, $capabilities->maxObjectSize());
		$this->assertSame(1, $capabilities->deleteBatchSize());
		$this->assertFalse($capabilities->batchDelete, 'the blob rest surface has no batch delete');
		$this->assertTrue($capabilities->conditionalWrite);
		$this->assertSame('backups', $provider->endpoint()->container);
	}

	#[Test]
	#[TestDox('the endpoint is probed once however often capabilities are asked for')]
	#[Group('strata/azure')]
	public function probesOnceAndCaches(): void
	{
		$provider = $this->provider();
		$this->queue(200, '<EnumerationResults><Blobs /></EnumerationResults>');

		$first = $provider->capabilities();
		$provider->capabilities();
		$provider->isReachable();
		$provider->unreachableReason();

		$this->assertSame($first, $provider->capabilities());
		$this->assertCount(1, $this->requests());
		$this->assertStringContainsString(
			'restype=container',
			(string) $this->request(0)->getUri(),
		);
	}

	#[Test]
	#[TestDox('an endpoint that refuses the probe reports conservatively, not optimistically')]
	#[Group('strata/azure')]
	public function reportsConservativelyWhenTheProbeFails(): void
	{
		$provider = $this->provider();
		$this->queue(403, '', ['x-ms-error-code' => 'AuthenticationFailed']);

		$capabilities = $provider->capabilities();

		$this->assertFalse($capabilities->multipart);
		$this->assertFalse($capabilities->conditionalWrite);
		$this->assertFalse($capabilities->checksums);
		$this->assertFalse($capabilities->storageClasses);
		$this->assertFalse($provider->isReachable());
		$this->assertStringContainsString(
			'AuthenticationFailed',
			(string) $provider->unreachableReason(),
		);
	}

	#[Test]
	#[TestDox('an unroutable endpoint is reported rather than raised out of isReachable')]
	#[Group('strata/azure')]
	public function reachabilityNeverRaises(): void
	{
		$provider = $this->provider();
		$this->queueFailure();

		$this->assertFalse($provider->isReachable());
		$this->assertStringContainsString('answered 0', (string) $provider->unreachableReason());
		$this->assertFalse($provider->capabilities()->multipart);
	}

	#[Test]
	#[TestDox('credentials for another account are refused at construction')]
	#[Group('strata/azure')]
	public function refusesCredentialsForAnotherAccount(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('credentials are for account someoneelse');

		$this->provider(null, new AzureCredentials('someoneelse', self::KEY));
	}

	#endregion

	#region Round trips

	#[Test]
	#[TestDox('a blob written reads back byte for byte and keeps its entity tag')]
	#[Group('strata/azure')]
	public function blobsRoundTrip(): void
	{
		$payload = random_bytes(4096);
		$provider = $this->probed();

		$this->queue(201, '', ['ETag' => '"written-tag"']);
		$this->queue(200, $payload);

		$result = $provider->put('frames/ab/cd/object', $payload);

		$this->assertSame('frames/ab/cd/object', $result->key);
		$this->assertSame(4096, $result->size);
		$this->assertSame('"written-tag"', $result->etag);
		$this->assertFalse($result->multipart);
		$this->assertSame(1, $result->parts);
		$this->assertSame($payload, $provider->get('frames/ab/cd/object'));

		$put = $this->request(0);

		$this->assertSame('PUT', $put->getMethod());
		$this->assertSame('BlockBlob', $put->getHeaderLine('x-ms-blob-type'));
		$this->assertSame('4096', $put->getHeaderLine('Content-Length'));
		$this->assertSame(base64_encode(md5($payload, true)), $put->getHeaderLine('Content-MD5'));
		$this->assertStringStartsWith(
			'SharedKey ' . self::ACCOUNT . ':',
			$put->getHeaderLine('Authorization'),
		);
	}

	#[Test]
	#[TestDox('the key prefix is added on the way out and stripped on the way back')]
	#[Group('strata/azure')]
	public function prefixesKeysSymmetrically(): void
	{
		$provider = $this->probed();

		$this->queue(201, '', ['ETag' => '"tag"']);
		$this->queue(200, $this->listing([self::PREFIX . 'frames/ab/cd/object']));

		$result = $provider->put('frames/ab/cd/object', 'payload');
		$page = $provider->list('frames/');

		$this->assertSame('frames/ab/cd/object', $result->key);
		$this->assertSame(['frames/ab/cd/object'], $page->keys());
		$this->assertStringEndsWith(
			'/backups/_strata/site-1/frames/ab/cd/object',
			$this->request(0)->getUri()->getPath(),
		);
		$this->assertStringContainsString(
			'prefix=_strata%2Fsite-1%2Fframes%2F',
			(string) $this->request(1)->getUri(),
		);
	}

	#[Test]
	#[TestDox('a leading slash is stripped rather than treated as an absolute key')]
	#[Group('strata/azure')]
	public function leadingSlashIsStripped(): void
	{
		$provider = $this->probed();
		$this->queue(201, '', ['ETag' => '"tag"']);

		$this->assertSame('frames/thing', $provider->put('/frames/thing', 'payload')->key);
		$this->assertStringEndsWith(
			'/backups/_strata/site-1/frames/thing',
			$this->request(0)->getUri()->getPath(),
		);
	}

	#[Test]
	#[TestDox('a stream body reads back identically')]
	#[Group('strata/azure')]
	public function streamBodiesRoundTrip(): void
	{
		$payload = random_bytes(64 * 1024 + 7);
		$provider = $this->probed();
		$stream = fopen('php://temp', 'r+b');

		$this->assertIsResource($stream);
		fwrite($stream, $payload);
		rewind($stream);

		$this->queue(201, '', ['ETag' => '"tag"']);

		$result = $provider->put('streamed', $stream);
		fclose($stream);

		$this->assertSame(strlen($payload), $result->size);
		$this->assertSame($payload, (string) $this->request(0)->getBody());
	}

	#[Test]
	#[TestDox('stream() hands back a readable handle positioned at the start')]
	#[Group('strata/azure')]
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
	#[TestDox('content type, access tier and user metadata reach the request')]
	#[Group('strata/azure')]
	public function objectOptionsReachTheRequest(): void
	{
		$provider = $this->probed();
		$this->queue(201, '', ['ETag' => '"tag"']);

		$provider->put('packs/one.pack', 'payload', [
			'contentType' => 'application/octet-stream',
			'storageClass' => 'Cool',
			'metadata' => ['Strata_Snapshot' => '42'],
		]);

		$put = $this->request(0);

		$this->assertSame(
			'application/octet-stream',
			$put->getHeaderLine('x-ms-blob-content-type'),
		);
		$this->assertSame('Cool', $put->getHeaderLine('x-ms-access-tier'));
		$this->assertSame('42', $put->getHeaderLine('x-ms-meta-strata_snapshot'));
	}

	#[Test]
	#[TestDox('a metadata name azure cannot store is refused rather than renamed to fit')]
	#[Group('strata/azure')]
	public function unstorableMetadataNameIsRefused(): void
	{
		$provider = $this->probed();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Metadata name "strata-snapshot" is not a letter');

		try {
			$provider->put('packs/one.pack', 'payload', [
				'metadata' => ['strata-snapshot' => '42'],
			]);
		} finally {
			$this->assertSame([], $this->requests(), 'nothing is written with the wrong name');
		}
	}

	#[Test]
	#[TestDox('the tier configured on the endpoint applies when a write names none')]
	#[Group('strata/azure')]
	public function endpointTierAppliesByDefault(): void
	{
		$provider = $this->probed($this->endpoint('Cold'));
		$this->queue(201, '', ['ETag' => '"tag"']);

		$provider->put('frames/thing', 'payload');

		$this->assertSame('Cold', $this->request(0)->getHeaderLine('x-ms-access-tier'));
	}

	#endregion

	#region Ranges

	#[Test]
	#[TestDox('a byte range asks for exactly the window with the x-ms header and returns it')]
	#[Group('strata/azure')]
	public function rangeReturnsExactWindow(): void
	{
		$provider = $this->probed();
		$this->queue(206, '456789');

		$this->assertSame('456789', $provider->get('ranged', new ByteRange(4, 6)));
		$this->assertSame('bytes=4-9', $this->request(0)->getHeaderLine('x-ms-range'));
		$this->assertSame('', $this->request(0)->getHeaderLine('Range'));
	}

	#[Test]
	#[TestDox('a range answered with fewer bytes than were asked for raises')]
	#[Group('strata/azure')]
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
	#[TestDox('heading an absent blob returns null rather than raising')]
	#[Group('strata/azure')]
	public function headingAbsentBlobReturnsNull(): void
	{
		$provider = $this->probed();

		$this->queue(404, '', ['x-ms-error-code' => 'BlobNotFound']);
		$this->queue(404, '', ['x-ms-error-code' => 'BlobNotFound']);

		$this->assertNull($provider->head('never/written'));
		$this->assertFalse($provider->exists('never/written'));
	}

	#[Test]
	#[TestDox('heading a present blob reports its size, tag, tier and metadata')]
	#[Group('strata/azure')]
	public function headingPresentBlobReportsMetadata(): void
	{
		$provider = $this->probed();
		$this->queue(200, '', [
			'Content-Length' => '4096',
			'ETag' => '"a-tag"',
			'Last-Modified' => 'Wed, 19 Aug 2026 12:00:00 GMT',
			'x-ms-access-tier' => 'Cool',
			'x-ms-meta-snapshot' => '42',
		]);

		$meta = $provider->head('frames/ab/cd/object');

		$this->assertInstanceOf(ObjectMeta::class, $meta);
		$this->assertSame('frames/ab/cd/object', $meta->key);
		$this->assertSame(4096, $meta->size);
		$this->assertSame('"a-tag"', $meta->etag);
		$this->assertSame(strtotime('Wed, 19 Aug 2026 12:00:00 GMT'), $meta->modified);
		$this->assertSame('Cool', $meta->storageClass);
		$this->assertSame(['snapshot' => '42'], $meta->metadata);
		$this->assertSame('HEAD', $this->request(0)->getMethod());
	}

	#[Test]
	#[TestDox('reading an absent blob raises and names the key')]
	#[Group('strata/azure')]
	public function readingAbsentBlobRaises(): void
	{
		$provider = $this->probed();
		$this->queue(404, '', ['x-ms-error-code' => 'BlobNotFound']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Object missing/thing is not present');

		$provider->get('missing/thing');
	}

	#[Test]
	#[TestDox('a refused read reports the code the service sent in its header')]
	#[Group('strata/azure')]
	public function refusedReadReportsTheServiceCode(): void
	{
		$provider = $this->probed();
		$this->queue(403, '', ['x-ms-error-code' => 'AuthorizationFailure']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('answered 403 (AuthorizationFailure)');

		$provider->get('frames/thing');
	}

	#[Test]
	#[TestDox('a refused read with no header reports the code out of the body')]
	#[Group('strata/azure')]
	public function refusedReadReportsTheBodyCode(): void
	{
		$provider = $this->probed();
		$this->queue(409, '<?xml version="1.0"?><Error><Code>ContainerBeingDeleted</Code></Error>');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('ContainerBeingDeleted');

		$provider->get('frames/thing');
	}

	#endregion

	#region Conditional writes

	#[Test]
	#[TestDox('a conditional write sends the precondition and reports a conflict as one')]
	#[Group('strata/azure')]
	public function conditionalWriteReportsConflict(): void
	{
		$provider = $this->probed();
		$this->queue(409, '', ['x-ms-error-code' => 'BlobAlreadyExists']);

		try {
			$provider->put('refs/head', 'second', ['ifNoneMatch' => true]);
			$this->fail('a conditional write over an existing key has to raise');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('conditional on its absence', $e->getMessage());
		}

		$this->assertSame('*', $this->request(0)->getHeaderLine('If-None-Match'));
	}

	#[Test]
	#[TestDox('a conditional write is refused on an endpoint that could not be probed')]
	#[Group('strata/azure')]
	public function conditionalWriteRefusedWhereUnsupported(): void
	{
		$provider = $this->provider();
		$this->queue(500);
		$provider->capabilities();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('does not honour a conditional write');

		$provider->put('refs/head', 'value', ['ifNoneMatch' => true]);
	}

	#endregion

	#region Blocks

	#[Test]
	#[TestDox('a body over the threshold is staged as blocks whose ids are all the same length')]
	#[Group('strata/azure')]
	public function largeBodyIsStagedAsBlocks(): void
	{
		$provider = $this->probed($this->endpoint(null, 1_048_576));
		$blockSize = $provider->capabilities()->partSizeFor(0);
		$payload = str_repeat('s', 2 * $blockSize + 11);

		$this->queue(201);
		$this->queue(201);
		$this->queue(201);
		$this->queue(201, '', ['ETag' => '"committed"']);

		$result = $provider->put('packs/big.pack', $payload);

		$this->assertTrue($result->multipart);
		$this->assertSame(3, $result->parts);
		$this->assertSame(strlen($payload), $result->size);
		$this->assertSame('"committed"', $result->etag);

		$requests = $this->requests();

		$this->assertCount(4, $requests);
		$this->assertSame($blockSize, $requests[0]->getBody()->getSize());
		$this->assertSame($blockSize, $requests[1]->getBody()->getSize());
		$this->assertSame(11, $requests[2]->getBody()->getSize());

		$ids = [];

		foreach ([0, 1, 2] as $index) {
			parse_str($requests[$index]->getUri()->getQuery(), $query);

			$this->assertSame('block', $query['comp'] ?? null);
			$this->assertIsString($query['blockid'] ?? null);
			$ids[] = (string) $query['blockid'];
		}

		$this->assertCount(1, array_unique(array_map('strlen', $ids)), 'ids must be one length');
		$this->assertSame($ids, array_unique($ids), 'every block id has to be distinct');

		$commit = $this->parse((string) $requests[3]->getBody());
		$committed = [];

		foreach ($commit->Latest as $latest) {
			$committed[] = (string) $latest;
		}

		$this->assertSame(
			$ids,
			$committed,
			'the commit names the blocks in the order they were sent',
		);
		$this->assertStringContainsString('comp=blocklist', (string) $requests[3]->getUri());
	}

	#[Test]
	#[TestDox('a body ending exactly on a block boundary stages no empty final block')]
	#[Group('strata/azure')]
	public function bodyEndingOnBlockBoundaryStagesNoEmptyBlock(): void
	{
		$provider = $this->probed($this->endpoint(null, 1_048_576));
		$payload = str_repeat('s', 2 * $provider->capabilities()->partSizeFor(0));

		$this->queue(201);
		$this->queue(201);
		$this->queue(201, '', ['ETag' => '"committed"']);

		$result = $provider->put('packs/exact.pack', $payload);

		$this->assertSame(2, $result->parts);
		$this->assertCount(3, $this->requests());
	}

	#[Test]
	#[TestDox('a block that fails raises before the block list is ever committed')]
	#[Group('strata/azure')]
	public function failedBlockIsNotCommitted(): void
	{
		$provider = $this->probed($this->endpoint(null, 1_048_576));
		$payload = str_repeat('s', 2 * $provider->capabilities()->partSizeFor(0) + 5);

		$this->queue(201);
		$this->queue(500, '<?xml version="1.0"?><Error><Code>InternalError</Code></Error>');

		try {
			$provider->put('packs/big.pack', $payload);
			$this->fail('a failed block has to raise');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('stage block 2 of packs/big.pack', $e->getMessage());
		}

		$this->assertCount(2, $this->requests(), 'nothing is committed after a block fails');
	}

	#[Test]
	#[TestDox('the same body is a single request when no threshold is configured')]
	#[Group('strata/azure')]
	public function sameBodyIsSingleRequestWithoutThreshold(): void
	{
		$provider = $this->probed();
		$this->queue(201, '', ['ETag' => '"single"']);

		$result = $provider->put('frames/big', str_repeat('x', 12 * 1_048_576));

		$this->assertFalse($result->multipart);
		$this->assertSame(1, $result->parts);
		$this->assertCount(1, $this->requests());
	}

	#[Test]
	#[TestDox('a threshold can only lower where blocks begin, never raise it')]
	#[Group('strata/azure')]
	public function thresholdCannotRaiseTheCeiling(): void
	{
		$provider = $this->probed($this->endpoint(null, PHP_INT_MAX));

		$this->assertSame(
			AzureStorageProvider::MAX_SINGLE_PUT,
			$provider->capabilities()->maxSinglePut,
		);
	}

	#endregion

	#region Deleting

	#[Test]
	#[TestDox('a delete removes one blob per request and counts only what went away')]
	#[Group('strata/azure')]
	public function deleteCountsWhatWentAway(): void
	{
		$provider = $this->probed();

		$this->queue(202);
		$this->queue(404, '', ['x-ms-error-code' => 'BlobNotFound']);
		$this->queue(202);

		$this->assertSame(2, $provider->delete(['present', 'gone', 'also-present']));

		$requests = $this->requests();

		$this->assertCount(3, $requests);
		$this->assertSame('DELETE', $requests[0]->getMethod());
		$this->assertStringEndsWith('/_strata/site-1/present', $requests[0]->getUri()->getPath());
	}

	#[Test]
	#[TestDox('deleting nothing makes no request at all')]
	#[Group('strata/azure')]
	public function deletingNothingMakesNoRequest(): void
	{
		$this->assertSame(0, $this->probed()->delete([]));
		$this->assertSame([], $this->requests());
	}

	#[Test]
	#[TestDox('a refused delete is reported by key')]
	#[Group('strata/azure')]
	public function refusedDeleteIsReported(): void
	{
		$provider = $this->probed();
		$this->queue(403, '', ['x-ms-error-code' => 'AuthorizationFailure']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot delete object frames/thing');

		$provider->delete(['frames/thing']);
	}

	#endregion

	#region Listing

	#[Test]
	#[TestDox('a listing pages through everything using the marker')]
	#[Group('strata/azure')]
	public function listingPagesThroughEverything(): void
	{
		$provider = $this->probed();

		$this->queue(
			200,
			$this->listing(
				[self::PREFIX . 'packs/01.pack', self::PREFIX . 'packs/02.pack'],
				'MARKER-PAGE-2',
			),
		);
		$this->queue(200, $this->listing([self::PREFIX . 'packs/03.pack']));

		$first = $provider->list('packs/', null, 2);

		$this->assertSame(['packs/01.pack', 'packs/02.pack'], $first->keys());
		$this->assertTrue($first->hasMore());
		$this->assertSame('MARKER-PAGE-2', $first->cursor);
		$this->assertSame(30, $first->bytes());
		$this->assertSame('"tag-1"', $first->objects[0]->etag);
		$this->assertSame('Hot', $first->objects[0]->storageClass);

		$second = $provider->list('packs/', $first->cursor, 2);

		$this->assertSame(['packs/03.pack'], $second->keys());
		$this->assertFalse($second->hasMore());

		$this->assertStringContainsString('maxresults=2', (string) $this->request(0)->getUri());
		$this->assertStringNotContainsString('marker=', (string) $this->request(0)->getUri());
		$this->assertStringContainsString(
			'marker=MARKER-PAGE-2',
			(string) $this->request(1)->getUri(),
		);
	}

	#[Test]
	#[TestDox('a delimiter groups blobs into prefixes the engine recognises')]
	#[Group('strata/azure')]
	public function delimiterGroupsBlobs(): void
	{
		$provider = $this->probed();
		$this->queue(200, $this->listing([self::PREFIX . 'top'], null, [self::PREFIX . 'frames/']));

		$page = $provider->list('', null, 100, '/');

		$this->assertSame(['top'], $page->keys());
		$this->assertSame(['frames/'], $page->prefixes);
		$this->assertStringContainsString('delimiter=%2F', (string) $this->request(0)->getUri());
	}

	#[Test]
	#[TestDox('a directory marker another tool left behind is not reported as a blob')]
	#[Group('strata/azure')]
	public function directoryMarkersAreNotObjects(): void
	{
		$provider = $this->probed();
		$this->queue(
			200,
			$this->listing([self::PREFIX . 'frames/', self::PREFIX . 'frames/thing']),
		);

		$this->assertSame(['frames/thing'], $provider->list('')->keys());
	}

	#[Test]
	#[TestDox('a listing limit below one is refused before a request is made')]
	#[Group('strata/azure')]
	public function badListingLimitIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('at least one');

		$this->probed()->list('', null, 0);
	}

	#[Test]
	#[TestDox('a listing body that is not xml is reported rather than parsed into nothing')]
	#[Group('strata/azure')]
	public function nonXmlListingIsReported(): void
	{
		$provider = $this->probed();
		$this->queue(200, 'this is not xml at all');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot list objects: the endpoint answered with a body');

		$provider->list('');
	}

	#[Test]
	#[TestDox('an external entity in a listing expands to nothing instead of to a local file')]
	#[Group('strata/azure')]
	public function externalEntitiesInListingsAreNotResolved(): void
	{
		$secret = sys_get_temp_dir() . '/strata-secret-' . bin2hex(random_bytes(8));
		file_put_contents($secret, 'THE-SECRET-CONTENTS');

		$provider = $this->probed();
		$this->queue(
			200,
			sprintf(
				'<?xml version="1.0"?><!DOCTYPE EnumerationResults [<!ENTITY leak SYSTEM "file://%s">]>' .
					'<EnumerationResults><Blobs><Blob><Name>safe-&leak;</Name>' .
					'<Properties><Content-Length>1</Content-Length></Properties></Blob></Blobs>' .
					'</EnumerationResults>',
				$secret,
			),
		);

		$keys = $provider->list('')->keys();
		@unlink($secret);

		$this->assertSame(['safe-'], $keys, 'the entity reference expanded to nothing');
		$this->assertStringNotContainsString('THE-SECRET-CONTENTS', implode('', $keys));
	}

	#endregion

	#region Sas

	#[Test]
	#[TestDox('a sas token is appended as a query string instead of an authorization header')]
	#[Group('strata/azure')]
	public function sasTokenTravelsInTheQueryString(): void
	{
		$provider = $this->probed(
			null,
			new AzureCredentials(self::ACCOUNT, '', '?sv=2021-12-02&sig=abc%2Fdef'),
		);
		$this->queue(201, '', ['ETag' => '"tag"']);

		$provider->put('frames/thing', 'payload');

		$request = $this->request(0);

		$this->assertSame('', $request->getHeaderLine('Authorization'));
		$this->assertStringContainsString(
			'sv=2021-12-02&sig=abc%2Fdef',
			(string) $request->getUri(),
		);
		$this->assertSame('2021-12-02', $request->getHeaderLine('x-ms-version'));
	}

	#[Test]
	#[TestDox('a sas token joins an existing query string rather than replacing it')]
	#[Group('strata/azure')]
	public function sasTokenJoinsAnExistingQuery(): void
	{
		$provider = $this->probed(null, new AzureCredentials(self::ACCOUNT, '', 'sig=abc'));
		$this->queue(200, $this->listing([]));

		$provider->list('frames/');

		$uri = (string) $this->request(0)->getUri();

		$this->assertStringContainsString('comp=list', $uri);
		$this->assertStringContainsString('&sig=abc', $uri);
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
	#[Group('strata/azure')]
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
	#[Group('strata/azure')]
	#[DataProvider('unusableBodyProvider')]
	public function refusesBodyThatIsNeitherStringNorStream(mixed $body): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('string or an open stream resource');

		$this->probed()->put('frames/thing', $body);
	}

	#endregion
}
