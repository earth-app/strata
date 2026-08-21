<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_s3\Unit;

use Drupal\strata\Storage\ByteRange;
use Drupal\strata\Storage\ObjectMeta;
use Drupal\strata_s3\Credentials\EnvironmentCredentials;
use Drupal\strata_s3\S3Endpoint;
use Drupal\strata_s3\S3StorageProvider;
use Drupal\strata_s3\SigV4Signer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimpleXMLElement;

#[CoversClass(S3StorageProvider::class)]
class S3StorageProviderTest extends TestCase
{
	#region Fixtures

	/**
	 * Prefix the engine puts every key under.
	 */
	private const PREFIX = '_strata/site-1/';

	/**
	 * Fragment of the probe url, used to tell a probe from a real request.
	 */
	private const PROBE = 'max-keys=0';

	/**
	 * Requests the transport recorded, probe requests included.
	 *
	 * @var list<array{method: string, url: string, headers: array<string, mixed>, body: ?string}>
	 */
	private array $sent = [];

	/**
	 * Responses the transport hands out in order.
	 *
	 * @var list<array{status: int, body: string, headers: array<string, string>}>
	 */
	private array $canned = [];

	/**
	 * Status the capability probe answers with.
	 */
	private int $probeStatus = 200;

	/**
	 * Whether the transport fails outright, as it does when the endpoint is unroutable.
	 */
	private bool $offline = false;

	/**
	 * Files written during a test, removed afterwards.
	 *
	 * @var list<string>
	 */
	private array $files = [];

	protected function tearDown(): void
	{
		foreach ($this->files as $path) {
			@unlink($path);
		}

		$this->files = [];
		$this->sent = [];
		$this->canned = [];
		$this->probeStatus = 200;
		$this->offline = false;

		parent::tearDown();
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
		$this->canned[] = ['status' => $status, 'body' => $body, 'headers' => $headers];
	}

	/**
	 * A transport that records what it was asked and replays what was queued.
	 *
	 * @return callable
	 *   The transport.
	 */
	private function transport(): callable
	{
		return function (string $method, string $url, array $headers, ?string $body): array {
			$this->sent[] = [
				'method' => $method,
				'url' => $url,
				'headers' => $headers,
				'body' => $body,
			];

			if ($this->offline) {
				throw new RuntimeException('Connection refused');
			}
			if (str_contains($url, self::PROBE)) {
				return [
					'status' => $this->probeStatus,
					'body' => '<ListBucketResult><KeyCount>0</KeyCount></ListBucketResult>',
					'headers' => [],
				];
			}

			$next = array_shift($this->canned);

			return $next ?? [
				'status' => 500,
				'body' => '<Error><Code>NoResponseQueued</Code></Error>',
				'headers' => [],
			];
		};
	}

	/**
	 * A provider over the recording transport.
	 *
	 * @param S3Endpoint|null $endpoint
	 *   The endpoint, or NULL for an AWS bucket with the engine's key prefix.
	 * @param array<string, string>|null $environment
	 *   Environment the credentials come from, or NULL for a working key pair.
	 *
	 * @return S3StorageProvider
	 *   The provider.
	 */
	private function provider(
		?S3Endpoint $endpoint = null,
		?array $environment = null,
	): S3StorageProvider {
		$endpoint ??= new S3Endpoint('strata-backups', 'us-east-1', null, false, self::PREFIX);

		return new S3StorageProvider(
			new EnvironmentCredentials(
				$environment ?? [
					'AWS_ACCESS_KEY_ID' => 'AKIDEXAMPLE',
					'AWS_SECRET_ACCESS_KEY' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
				],
			),
			$endpoint,
			$this->transport(),
			new SigV4Signer($endpoint->region),
		);
	}

	/**
	 * Requests the provider made, with capability probes left out.
	 *
	 * @return list<array{method: string, url: string, headers: array<string, mixed>, body: ?string}>
	 *   The requests, in order.
	 */
	private function requests(): array
	{
		return array_values(
			array_filter(
				$this->sent,
				static fn(array $request): bool => !str_contains($request['url'], self::PROBE),
			),
		);
	}

	/**
	 * A stream that cannot report its own size, as a pipe or a socket cannot.
	 *
	 * The zlib wrapper answers fstat() with FALSE, which is the branch a body of unknown length
	 * takes.
	 *
	 * @param string $payload
	 *   The bytes the stream yields.
	 *
	 * @return resource
	 *   An open readable stream.
	 */
	private function unsizableStream(string $payload)
	{
		$path = sys_get_temp_dir() . '/strata-body-' . bin2hex(random_bytes(8)) . '.gz';
		file_put_contents($path, (string) gzencode($payload, 1));
		$this->files[] = $path;

		$handle = fopen('compress.zlib://' . $path, 'rb');

		$this->assertIsResource($handle);
		$this->assertFalse(@fstat($handle), 'the fixture stream has to be unsizable');

		return $handle;
	}

	/**
	 * Parses a request body the provider sent.
	 *
	 * @param string|null $body
	 *   The body.
	 *
	 * @return SimpleXMLElement
	 *   The parsed document.
	 */
	private function parse(?string $body): SimpleXMLElement
	{
		$document = simplexml_load_string((string) $body, SimpleXMLElement::class, LIBXML_NONET);

		$this->assertInstanceOf(SimpleXMLElement::class, $document);

		return $document;
	}

	/**
	 * A ListObjectsV2 response.
	 *
	 * @param list<string> $keys
	 *   Keys the page holds, already prefixed.
	 * @param string|null $next
	 *   Continuation token for the next page, or NULL when this is the last one.
	 * @param list<string> $prefixes
	 *   Common prefixes, already prefixed.
	 *
	 * @return string
	 *   The response body.
	 */
	private function listing(array $keys, ?string $next = null, array $prefixes = []): string
	{
		$xml = '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult>';
		$xml .= sprintf('<IsTruncated>%s</IsTruncated>', $next === null ? 'false' : 'true');

		if ($next !== null) {
			$xml .= sprintf('<NextContinuationToken>%s</NextContinuationToken>', $next);
		}

		foreach ($keys as $index => $key) {
			$xml .= sprintf(
				'<Contents><Key>%s</Key><Size>%d</Size><ETag>"tag-%d"</ETag>' .
					'<LastModified>2026-08-19T12:00:00.000Z</LastModified>' .
					'<StorageClass>STANDARD</StorageClass></Contents>',
				$key,
				($index + 1) * 10,
				$index + 1,
			);
		}

		foreach ($prefixes as $prefix) {
			$xml .= sprintf('<CommonPrefixes><Prefix>%s</Prefix></CommonPrefixes>', $prefix);
		}

		return $xml . '</ListBucketResult>';
	}

	#endregion

	#region Identity

	#[Test]
	#[TestDox('the provider reports a stable id, a label and a coherent capability set')]
	#[Group('strata/s3')]
	public function reportsCoherentIdentity(): void
	{
		$provider = $this->provider();
		$capabilities = $provider->capabilities();

		$this->assertSame('s3', $provider->id());
		$this->assertSame(strtolower($provider->id()), $provider->id());
		$this->assertSame('S3-Compatible Storage', $provider->label());
		$this->assertGreaterThan(0, $capabilities->maxObjectSize());
		$this->assertGreaterThanOrEqual(1, $capabilities->deleteBatchSize());
		$this->assertTrue($provider->isReachable());
		$this->assertNull($provider->unreachableReason());
		$this->assertSame('strata-backups', $provider->endpoint()->bucket);
	}

	#[Test]
	#[TestDox('an r2 endpoint is labelled as r2')]
	#[Group('strata/s3')]
	public function labelsR2ByName(): void
	{
		$provider = $this->provider(
			new S3Endpoint('b', 'auto', 'https://account.r2.cloudflarestorage.com'),
		);

		$this->assertSame('Cloudflare R2', $provider->label());
	}

	#[Test]
	#[TestDox('a signer scoped to another region is refused at construction')]
	#[Group('strata/s3')]
	public function refusesSignerFromAnotherRegion(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('signs for region eu-west-2');

		new S3StorageProvider(
			new EnvironmentCredentials([]),
			new S3Endpoint('strata-backups', 'us-east-1'),
			$this->transport(),
			new SigV4Signer('eu-west-2'),
		);
	}

	#endregion

	#region Capabilities

	#[Test]
	#[TestDox('the endpoint is probed once however often capabilities are asked for')]
	#[Group('strata/s3')]
	public function probesOnceAndCaches(): void
	{
		$provider = $this->provider();

		$first = $provider->capabilities();
		$provider->capabilities();
		$provider->isReachable();
		$provider->unreachableReason();

		$this->assertSame($first, $provider->capabilities());
		$this->assertCount(1, $this->sent);
		$this->assertStringContainsString('list-type=2', $this->sent[0]['url']);
	}

	#[Test]
	#[TestDox('an endpoint that refuses the probe reports conservatively, not optimistically')]
	#[Group('strata/s3')]
	public function reportsConservativelyWhenTheProbeFails(): void
	{
		$this->probeStatus = 403;
		$provider = $this->provider();
		$capabilities = $provider->capabilities();

		$this->assertFalse($capabilities->multipart);
		$this->assertFalse($capabilities->batchDelete);
		$this->assertFalse($capabilities->conditionalWrite);
		$this->assertFalse($capabilities->checksums);
		$this->assertFalse($capabilities->presign);
		$this->assertFalse($provider->isReachable());
		$this->assertStringContainsString('answered 403', (string) $provider->unreachableReason());
	}

	#[Test]
	#[TestDox('an unroutable endpoint is reported rather than raised out of isReachable')]
	#[Group('strata/s3')]
	public function reachabilityNeverRaises(): void
	{
		$this->offline = true;
		$provider = $this->provider();

		$this->assertFalse($provider->isReachable());
		$this->assertSame('Connection refused', $provider->unreachableReason());
		$this->assertFalse($provider->capabilities()->multipart);
	}

	#[Test]
	#[TestDox('r2 is detected from its host, so parts are uniform and checksums are not sent')]
	#[Group('strata/s3')]
	public function detectsR2Capabilities(): void
	{
		$r2 = $this->provider(
			new S3Endpoint(
				'strata-backups',
				'auto',
				'https://account.r2.cloudflarestorage.com',
				false,
				self::PREFIX,
				true,
			),
		)->capabilities();
		$aws = $this->provider(
			new S3Endpoint('strata-backups', 'us-east-1', null, false, self::PREFIX, true),
		)->capabilities();

		$this->assertTrue($r2->uniformPartSize);
		$this->assertFalse($r2->checksums, 'r2 rejects the x-amz-checksum family');
		$this->assertFalse($aws->uniformPartSize);
		$this->assertTrue($aws->checksums);
	}

	#endregion

	#region Round trips

	#[Test]
	#[TestDox('an object written reads back byte for byte and keeps its entity tag')]
	#[Group('strata/s3')]
	public function objectsRoundTrip(): void
	{
		$payload = random_bytes(4096);
		$provider = $this->provider();

		$this->queue(200, '', ['ETag' => '"written-tag"']);
		$this->queue(200, $payload);

		$result = $provider->put('frames/ab/cd/object', $payload);

		$this->assertSame('frames/ab/cd/object', $result->key);
		$this->assertSame(4096, $result->size);
		$this->assertSame('"written-tag"', $result->etag);
		$this->assertFalse($result->multipart);
		$this->assertSame(1, $result->parts);
		$this->assertSame($payload, $provider->get('frames/ab/cd/object'));

		$put = $this->requests()[0];

		$this->assertSame('PUT', $put['method']);
		$this->assertSame('4096', $put['headers']['Content-Length']);
		$this->assertSame(hash('sha256', $payload), $put['headers']['x-amz-content-sha256']);
		$this->assertStringStartsWith(
			'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/',
			(string) $put['headers']['Authorization'],
		);
	}

	#[Test]
	#[TestDox('the key prefix is added on the way out and stripped on the way back')]
	#[Group('strata/s3')]
	public function prefixesKeysSymmetrically(): void
	{
		$provider = $this->provider();

		$this->queue(200, '', ['ETag' => '"tag"']);
		$this->queue(200, $this->listing([self::PREFIX . 'frames/ab/cd/object']));

		$result = $provider->put('frames/ab/cd/object', 'payload');
		$page = $provider->list('frames/');

		$this->assertSame('frames/ab/cd/object', $result->key);
		$this->assertSame(['frames/ab/cd/object'], $page->keys());
		$this->assertStringEndsWith(
			'/_strata/site-1/frames/ab/cd/object',
			$this->requests()[0]['url'],
		);
		$this->assertStringContainsString(
			'prefix=_strata%2Fsite-1%2Fframes%2F',
			$this->requests()[1]['url'],
		);
	}

	#[Test]
	#[TestDox('a leading slash is stripped rather than treated as an absolute key')]
	#[Group('strata/s3')]
	public function leadingSlashIsStripped(): void
	{
		$provider = $this->provider();
		$this->queue(200, '', ['ETag' => '"tag"']);

		$this->assertSame('frames/thing', $provider->put('/frames/thing', 'payload')->key);
		$this->assertStringEndsWith('/_strata/site-1/frames/thing', $this->requests()[0]['url']);
	}

	#[Test]
	#[TestDox('a stream body reads back identically')]
	#[Group('strata/s3')]
	public function streamBodiesRoundTrip(): void
	{
		$payload = random_bytes(64 * 1024 + 7);
		$provider = $this->provider();
		$stream = fopen('php://temp', 'r+b');

		fwrite($stream, $payload);
		rewind($stream);

		$this->queue(200, '', ['ETag' => '"tag"']);

		$result = $provider->put('streamed', $stream);
		fclose($stream);

		$this->assertSame(strlen($payload), $result->size);
		$this->assertSame($payload, $this->requests()[0]['body']);
	}

	#[Test]
	#[TestDox('stream() hands back a readable handle positioned at the start')]
	#[Group('strata/s3')]
	public function streamReturnsReadableHandle(): void
	{
		$provider = $this->provider();
		$this->queue(200, 'the whole payload');

		$handle = $provider->stream('streamed');

		$this->assertIsResource($handle);
		$this->assertSame('the whole payload', stream_get_contents($handle));

		fclose($handle);
	}

	#[Test]
	#[TestDox('content type, storage class and user metadata reach the request')]
	#[Group('strata/s3')]
	public function objectOptionsReachTheRequest(): void
	{
		$provider = $this->provider();
		$this->queue(200, '', ['ETag' => '"tag"']);

		$provider->put('packs/one.pack', 'payload', [
			'contentType' => 'application/octet-stream',
			'storageClass' => 'STANDARD_IA',
			'metadata' => ['Strata-Snapshot' => '42'],
		]);

		$headers = $this->requests()[0]['headers'];

		$this->assertSame('application/octet-stream', $headers['Content-Type']);
		$this->assertSame('STANDARD_IA', $headers['x-amz-storage-class']);
		$this->assertSame('42', $headers['x-amz-meta-strata-snapshot']);
	}

	#endregion

	#region Ranges

	#[Test]
	#[TestDox('a byte range asks for exactly the window and returns it')]
	#[Group('strata/s3')]
	public function rangeReturnsExactWindow(): void
	{
		$provider = $this->provider();
		$this->queue(206, '456789');

		$this->assertSame('456789', $provider->get('ranged', new ByteRange(4, 6)));
		$this->assertSame('bytes=4-9', $this->requests()[0]['headers']['Range']);
	}

	#[Test]
	#[TestDox('a range answered with fewer bytes than were asked for raises')]
	#[Group('strata/s3')]
	public function shortRangeRaises(): void
	{
		$provider = $this->provider();
		$this->queue(206, '01234');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('returned 5 bytes for a 20 byte range at offset 5');

		$provider->get('short', new ByteRange(5, 20));
	}

	#endregion

	#region Absence

	#[Test]
	#[TestDox('heading an absent object returns null rather than raising')]
	#[Group('strata/s3')]
	public function headingAbsentObjectReturnsNull(): void
	{
		$provider = $this->provider();

		$this->queue(404);
		$this->queue(404);

		$this->assertNull($provider->head('never/written'));
		$this->assertFalse($provider->exists('never/written'));
	}

	#[Test]
	#[TestDox('heading a present object reports its size, tag, tier and metadata')]
	#[Group('strata/s3')]
	public function headingPresentObjectReportsMetadata(): void
	{
		$provider = $this->provider();
		$this->queue(200, '', [
			'Content-Length' => '4096',
			'ETag' => '"a-tag"',
			'Last-Modified' => 'Wed, 19 Aug 2026 12:00:00 GMT',
			'x-amz-storage-class' => 'STANDARD_IA',
			'x-amz-meta-snapshot' => '42',
		]);

		$meta = $provider->head('frames/ab/cd/object');

		$this->assertInstanceOf(ObjectMeta::class, $meta);
		$this->assertSame('frames/ab/cd/object', $meta->key);
		$this->assertSame(4096, $meta->size);
		$this->assertSame('"a-tag"', $meta->etag);
		$this->assertSame(strtotime('Wed, 19 Aug 2026 12:00:00 GMT'), $meta->modified);
		$this->assertSame('STANDARD_IA', $meta->storageClass);
		$this->assertSame(['snapshot' => '42'], $meta->metadata);
	}

	#[Test]
	#[TestDox('reading an absent object raises and names the key')]
	#[Group('strata/s3')]
	public function readingAbsentObjectRaises(): void
	{
		$provider = $this->provider();
		$this->queue(404, '<Error><Code>NoSuchKey</Code></Error>');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Object missing/thing is not present');

		$provider->get('missing/thing');
	}

	#[Test]
	#[TestDox('a refused read reports the code the endpoint sent')]
	#[Group('strata/s3')]
	public function refusedReadReportsTheEndpointCode(): void
	{
		$provider = $this->provider();
		$this->queue(
			403,
			'<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>',
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('answered 403 (AccessDenied Access Denied)');

		$provider->get('frames/thing');
	}

	#endregion

	#region Conditional writes

	#[Test]
	#[TestDox('a conditional write sends the precondition and reports a conflict as one')]
	#[Group('strata/s3')]
	public function conditionalWriteReportsConflict(): void
	{
		$provider = $this->provider();
		$this->queue(412, '<Error><Code>PreconditionFailed</Code></Error>');

		try {
			$provider->put('refs/head', 'second', ['ifNoneMatch' => true]);
			$this->fail('a conditional write over an existing key has to raise');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('conditional on its absence', $e->getMessage());
		}

		$this->assertSame('*', $this->requests()[0]['headers']['If-None-Match']);
	}

	#[Test]
	#[TestDox('a conditional write is refused on an endpoint that does not honour one')]
	#[Group('strata/s3')]
	public function conditionalWriteRefusedWhereUnsupported(): void
	{
		$this->probeStatus = 500;
		$provider = $this->provider();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('does not honour a conditional write');

		$provider->put('refs/head', 'value', ['ifNoneMatch' => true]);
	}

	#endregion

	#region Multipart

	#[Test]
	#[TestDox('a body of unknown length is split into uniform parts whose tags travel verbatim')]
	#[Group('strata/s3')]
	public function multipartSendsUniformPartsAndVerbatimTags(): void
	{
		$payload = str_repeat('strata', 2_000_000);
		$provider = $this->provider();
		$partSize = $provider->capabilities()->partSizeFor(0);

		$this->queue(
			200,
			'<InitiateMultipartUploadResult><UploadId>UPLOAD-1</UploadId>' .
				'</InitiateMultipartUploadResult>',
		);
		$this->queue(200, '', ['ETag' => '"part-one"']);
		$this->queue(200, '', ['ETag' => '"part-two"']);
		$this->queue(200, '', ['ETag' => '"part-three"']);
		$this->queue(
			200,
			'<CompleteMultipartUploadResult><ETag>"final-tag-3"</ETag>' .
				'</CompleteMultipartUploadResult>',
		);

		$stream = $this->unsizableStream($payload);
		$result = $provider->put('packs/big.pack', $stream);
		fclose($stream);

		$this->assertTrue($result->multipart);
		$this->assertSame(3, $result->parts);
		$this->assertSame(strlen($payload), $result->size);
		$this->assertSame('"final-tag-3"', $result->etag);

		$requests = $this->requests();

		$this->assertCount(5, $requests);
		$this->assertStringEndsWith('?uploads', $requests[0]['url']);

		// every part but the last is exactly the same size, which r2 requires and aws tolerates
		$this->assertSame($partSize, strlen((string) $requests[1]['body']));
		$this->assertSame($partSize, strlen((string) $requests[2]['body']));
		$this->assertSame(strlen($payload) - 2 * $partSize, strlen((string) $requests[3]['body']));

		foreach ([1, 2, 3] as $part) {
			$this->assertStringContainsString(
				sprintf('?partNumber=%d&uploadId=UPLOAD-1', $part),
				$requests[$part]['url'],
			);
		}

		$complete = $this->parse($requests[4]['body']);
		$tags = [];

		foreach ($complete->Part as $part) {
			$tags[(int) (string) $part->PartNumber] = (string) $part->ETag;
		}

		$this->assertSame(
			[1 => '"part-one"', 2 => '"part-two"', 3 => '"part-three"'],
			$tags,
			'a part tag that lost its quotes is rejected by r2',
		);
	}

	#[Test]
	#[
		TestDox(
			'a multipart upload declares its checksum algorithm and hashes each part when enabled',
		),
	]
	#[Group('strata/s3')]
	public function multipartCarriesChecksumsWhenEnabled(): void
	{
		$provider = $this->provider(
			new S3Endpoint('strata-backups', 'us-east-1', null, false, self::PREFIX, true),
		);
		$partSize = $provider->capabilities()->partSizeFor(0);
		$payload = str_repeat('strata', 1_000_000);

		$this->queue(
			200,
			'<InitiateMultipartUploadResult><UploadId>UPLOAD-5</UploadId>' .
				'</InitiateMultipartUploadResult>',
		);
		$this->queue(200, '', ['ETag' => '"part-one"']);
		$this->queue(200, '', ['ETag' => '"part-two"']);
		$this->queue(
			200,
			'<CompleteMultipartUploadResult><ETag>"final"</ETag></CompleteMultipartUploadResult>',
		);

		$stream = $this->unsizableStream($payload);
		$provider->put('packs/big.pack', $stream);
		fclose($stream);

		$requests = $this->requests();

		$this->assertSame('SHA256', $requests[0]['headers']['x-amz-checksum-algorithm']);
		$this->assertSame(
			base64_encode(hash('sha256', substr($payload, 0, $partSize), true)),
			$requests[1]['headers']['x-amz-checksum-sha256'],
		);
	}

	#[Test]
	#[TestDox('a body ending exactly on a part boundary sends no empty final part')]
	#[Group('strata/s3')]
	public function bodyEndingOnPartBoundarySendsNoEmptyPart(): void
	{
		$provider = $this->provider();
		$payload = str_repeat('s', $provider->capabilities()->partSizeFor(0) * 2);

		$this->queue(
			200,
			'<InitiateMultipartUploadResult><UploadId>UPLOAD-6</UploadId>' .
				'</InitiateMultipartUploadResult>',
		);
		$this->queue(200, '', ['ETag' => '"part-one"']);
		$this->queue(200, '', ['ETag' => '"part-two"']);
		$this->queue(
			200,
			'<CompleteMultipartUploadResult><ETag>"final"</ETag></CompleteMultipartUploadResult>',
		);

		$stream = $this->unsizableStream($payload);
		$result = $provider->put('packs/exact.pack', $stream);
		fclose($stream);

		$this->assertSame(2, $result->parts);
		$this->assertCount(4, $this->requests());
	}

	#[Test]
	#[TestDox('a part that fails aborts the upload rather than leaving it open')]
	#[Group('strata/s3')]
	public function partFailureAbortsTheUpload(): void
	{
		$payload = str_repeat('strata', 1_000_000);
		$provider = $this->provider();

		$this->queue(
			200,
			'<InitiateMultipartUploadResult><UploadId>UPLOAD-2</UploadId>' .
				'</InitiateMultipartUploadResult>',
		);
		$this->queue(200, '', ['ETag' => '"part-one"']);
		$this->queue(500, '<Error><Code>InternalError</Code></Error>');
		$this->queue(204);

		$stream = $this->unsizableStream($payload);

		try {
			$provider->put('packs/big.pack', $stream);
			$this->fail('a failed part has to raise');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('upload part 2 of packs/big.pack', $e->getMessage());
		} finally {
			fclose($stream);
		}

		$requests = $this->requests();
		$abort = $requests[count($requests) - 1];

		$this->assertSame('DELETE', $abort['method']);
		$this->assertStringEndsWith('?uploadId=UPLOAD-2', $abort['url']);
	}

	#[Test]
	#[
		TestDox(
			'a configured threshold splits a string body that would otherwise fit in one request',
		),
	]
	#[Group('strata/s3')]
	public function thresholdSplitsStringBody(): void
	{
		// without a threshold a 12 MiB string is a single put, since one request carries 5 GiB
		$endpoint = new S3Endpoint(
			'strata-backups',
			'us-east-1',
			null,
			false,
			self::PREFIX,
			false,
			null,
			1_048_576,
		);
		$provider = $this->provider($endpoint);
		$body = str_repeat('x', 12 * 1_048_576);

		// s3 will not take a part below 5 MiB, so a 12 MiB body is 5 + 5 + 2 however low the
		// threshold is set
		$partSize = $provider->capabilities()->partSizeFor(strlen($body));
		$this->assertSame(5 * 1_048_576, $partSize);

		$this->queue(
			200,
			'<InitiateMultipartUploadResult><UploadId>UPLOAD-T</UploadId>' .
				'</InitiateMultipartUploadResult>',
		);
		for ($part = 1; $part <= 3; $part++) {
			$this->queue(200, '', ['ETag' => sprintf('"part-%d"', $part)]);
		}
		$this->queue(
			200,
			'<CompleteMultipartUploadResult><ETag>"final-tag-3"</ETag>' .
				'</CompleteMultipartUploadResult>',
		);

		$result = $provider->put('frames/big', $body);

		$this->assertTrue($result->multipart, 'the threshold forced a multipart upload');
		$this->assertSame(3, $result->parts);
		$this->assertSame(strlen($body), $result->size);
		$this->assertSame('"final-tag-3"', $result->etag);

		$parts = array_values(
			array_filter(
				$this->requests(),
				static fn(array $r): bool => str_contains($r['url'], 'partNumber='),
			),
		);

		$this->assertCount(3, $parts);
		$this->assertSame($partSize, strlen((string) $parts[0]['body']));
		$this->assertSame($partSize, strlen((string) $parts[1]['body']));
		$this->assertSame(2 * 1_048_576, strlen((string) $parts[2]['body']));
	}

	#[Test]
	#[TestDox('the same string body is one request when no threshold is configured')]
	#[Group('strata/s3')]
	public function sameBodyIsSingleRequestWithoutThreshold(): void
	{
		$provider = $this->provider();
		$this->queue(200, '', ['ETag' => '"single"']);

		$result = $provider->put('frames/big', str_repeat('x', 12 * 1_048_576));

		$this->assertFalse($result->multipart);
		$this->assertSame(1, $result->parts);
		$this->assertCount(1, $this->requests());
	}

	#[Test]
	#[TestDox('a threshold can only lower where multipart begins, never raise it')]
	#[Group('strata/s3')]
	public function thresholdCannotRaiseTheCeiling(): void
	{
		$endpoint = new S3Endpoint(
			'strata-backups',
			'us-east-1',
			null,
			false,
			self::PREFIX,
			false,
			null,
			PHP_INT_MAX,
		);

		$this->assertSame(
			5_368_709_120,
			$this->provider($endpoint)->capabilities()->maxSinglePut,
			'a threshold above what one request carries is clamped to it',
		);
	}

	#[Test]
	#[TestDox('no threshold leaves multipart to begin only where a request cannot carry the body')]
	#[Group('strata/s3')]
	public function noThresholdKeepsTheDefaultCeiling(): void
	{
		$this->assertSame(5_368_709_120, $this->provider()->capabilities()->maxSinglePut);
	}

	#[Test]
	#[TestDox('an endpoint with no multipart refuses a body it cannot take in one request')]
	#[Group('strata/s3')]
	public function refusesLargeBodyWithoutMultipart(): void
	{
		$this->probeStatus = 500;
		$provider = $this->provider();
		$stream = $this->unsizableStream(str_repeat('strata', 1_000_000));

		try {
			$this->expectException(RuntimeException::class);
			$this->expectExceptionMessage('it has no multipart upload');

			$provider->put('packs/big.pack', $stream);
		} finally {
			fclose($stream);
		}
	}

	#[Test]
	#[TestDox('a body of unknown length under one part is written in a single request')]
	#[Group('strata/s3')]
	public function shortUnsizableBodyTakesTheSinglePutPath(): void
	{
		$payload = str_repeat('strata', 1000);
		$provider = $this->provider();
		$this->queue(200, '', ['ETag' => '"tag"']);

		$stream = $this->unsizableStream($payload);
		$result = $provider->put('packs/small.pack', $stream);
		fclose($stream);

		$this->assertFalse($result->multipart);
		$this->assertSame(strlen($payload), $result->size);
		$this->assertCount(1, $this->requests());
	}

	#endregion

	#region Deleting

	#[Test]
	#[TestDox('a batch delete is chunked at the size the endpoint allows')]
	#[Group('strata/s3')]
	public function batchDeleteIsChunked(): void
	{
		$provider = $this->provider();
		$keys = [];

		for ($i = 0; $i < 2500; $i++) {
			$keys[] = sprintf('frames/%04d', $i);
		}

		$this->queue(200, '<DeleteResult></DeleteResult>');
		$this->queue(200, '<DeleteResult></DeleteResult>');
		$this->queue(200, '<DeleteResult></DeleteResult>');

		$this->assertSame(2500, $provider->delete($keys));

		$requests = $this->requests();

		$this->assertCount(3, $requests);
		$this->assertCount(1000, $this->parse($requests[0]['body'])->Object);
		$this->assertCount(1000, $this->parse($requests[1]['body'])->Object);
		$this->assertCount(500, $this->parse($requests[2]['body'])->Object);

		foreach ($requests as $request) {
			$this->assertSame('POST', $request['method']);
			$this->assertStringEndsWith('?delete', $request['url']);
			$this->assertStringContainsString(
				'<Key>_strata/site-1/frames/',
				(string) $request['body'],
			);
		}
	}

	#[Test]
	#[TestDox('an absent key in a batch delete is not an error, so a retried prune is idempotent')]
	#[Group('strata/s3')]
	public function absentKeyInBatchDeleteIsNotAnError(): void
	{
		$provider = $this->provider();
		$this->queue(
			200,
			'<DeleteResult><Error><Key>_strata/site-1/gone</Key><Code>NoSuchKey</Code>' .
				'</Error></DeleteResult>',
		);

		$this->assertSame(2, $provider->delete(['present', 'gone', 'also-present']));
	}

	#[Test]
	#[TestDox('a quiet batch delete answered with no body counts every key it accepted')]
	#[Group('strata/s3')]
	public function quietBatchDeleteWithNoBodyCountsEveryKey(): void
	{
		$provider = $this->provider();
		$this->queue(204);

		$this->assertSame(2, $provider->delete(['frames/one', 'frames/two']));
	}

	#[Test]
	#[TestDox('a key that fails for any other reason is reported by name')]
	#[Group('strata/s3')]
	public function otherDeleteErrorsAreReported(): void
	{
		$provider = $this->provider();
		$this->queue(
			200,
			'<DeleteResult><Error><Key>_strata/site-1/locked</Key><Code>AccessDenied</Code>' .
				'</Error></DeleteResult>',
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot delete object _strata/site-1/locked: AccessDenied');

		$provider->delete(['locked']);
	}

	#[Test]
	#[TestDox('deleting nothing makes no request at all')]
	#[Group('strata/s3')]
	public function deletingNothingMakesNoRequest(): void
	{
		$this->assertSame(0, $this->provider()->delete([]));
		$this->assertSame([], $this->requests());
	}

	#[Test]
	#[TestDox('an endpoint with no batch delete falls back to one request per key')]
	#[Group('strata/s3')]
	public function fallsBackToSingleDeletes(): void
	{
		$this->probeStatus = 500;
		$provider = $this->provider();

		$this->queue(204);
		$this->queue(404);

		$this->assertSame(1, $provider->delete(['present', 'gone']));

		$requests = $this->requests();

		$this->assertCount(2, $requests);
		$this->assertSame('DELETE', $requests[0]['method']);
		$this->assertStringEndsWith('/_strata/site-1/present', $requests[0]['url']);
	}

	#endregion

	#region Listing

	#[Test]
	#[TestDox('a listing pages through everything using the continuation token')]
	#[Group('strata/s3')]
	public function listingPagesThroughEverything(): void
	{
		$provider = $this->provider();

		$this->queue(
			200,
			$this->listing(
				[self::PREFIX . 'packs/01.pack', self::PREFIX . 'packs/02.pack'],
				'TOKEN-PAGE-2',
			),
		);
		$this->queue(200, $this->listing([self::PREFIX . 'packs/03.pack']));

		$first = $provider->list('packs/', null, 2);

		$this->assertSame(['packs/01.pack', 'packs/02.pack'], $first->keys());
		$this->assertTrue($first->hasMore());
		$this->assertSame('TOKEN-PAGE-2', $first->cursor);
		$this->assertSame(30, $first->bytes());

		$second = $provider->list('packs/', $first->cursor, 2);

		$this->assertSame(['packs/03.pack'], $second->keys());
		$this->assertFalse($second->hasMore());

		$requests = $this->requests();

		$this->assertStringContainsString('max-keys=2', $requests[0]['url']);
		$this->assertStringNotContainsString('continuation-token', $requests[0]['url']);
		$this->assertStringContainsString('continuation-token=TOKEN-PAGE-2', $requests[1]['url']);
	}

	#[Test]
	#[TestDox('a delimiter groups keys into common prefixes the engine recognises')]
	#[Group('strata/s3')]
	public function delimiterGroupsKeys(): void
	{
		$provider = $this->provider();
		$this->queue(200, $this->listing([self::PREFIX . 'top'], null, [self::PREFIX . 'frames/']));

		$page = $provider->list('', null, 100, '/');

		$this->assertSame(['top'], $page->keys());
		$this->assertSame(['frames/'], $page->prefixes);
		$this->assertStringContainsString('delimiter=%2F', $this->requests()[0]['url']);
	}

	#[Test]
	#[TestDox('a directory marker another tool left behind is not reported as an object')]
	#[Group('strata/s3')]
	public function directoryMarkersAreNotObjects(): void
	{
		$provider = $this->provider();
		$this->queue(
			200,
			$this->listing([self::PREFIX . 'frames/', self::PREFIX . 'frames/thing']),
		);

		$this->assertSame(['frames/thing'], $provider->list('')->keys());
	}

	#[Test]
	#[TestDox('a listing limit below one is refused before a request is made')]
	#[Group('strata/s3')]
	public function badListingLimitIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('at least one');

		$this->provider()->list('', null, 0);
	}

	#[Test]
	#[TestDox('an external entity in a listing expands to nothing instead of to a local file')]
	#[Group('strata/s3')]
	public function externalEntitiesInListingsAreNotResolved(): void
	{
		$secret = sys_get_temp_dir() . '/strata-secret-' . bin2hex(random_bytes(8));
		file_put_contents($secret, 'THE-SECRET-CONTENTS');
		$this->files[] = $secret;

		$provider = $this->provider();
		$this->queue(
			200,
			sprintf(
				'<?xml version="1.0"?><!DOCTYPE ListBucketResult [<!ENTITY leak SYSTEM "file://%s">]>' .
					'<ListBucketResult><IsTruncated>false</IsTruncated><Contents>' .
					'<Key>safe-key-&leak;</Key><Size>1</Size></Contents></ListBucketResult>',
				$secret,
			),
		);

		$keys = $provider->list('')->keys();

		$this->assertSame(['safe-key-'], $keys, 'the entity reference expanded to nothing');
		$this->assertStringNotContainsString('THE-SECRET-CONTENTS', implode('', $keys));
	}

	#endregion

	#region Checksums

	#[Test]
	#[TestDox('a checksum header is sent only where the endpoint accepts one')]
	#[Group('strata/s3')]
	public function checksumsAreSentOnlyWhenEnabled(): void
	{
		$payload = 'payload';
		$enabled = $this->provider(
			new S3Endpoint('strata-backups', 'us-east-1', null, false, self::PREFIX, true),
		);
		$disabled = $this->provider(
			new S3Endpoint('strata-backups', 'us-east-1', null, false, self::PREFIX, false),
		);

		$this->queue(200, '', ['ETag' => '"tag"']);
		$enabled->put('frames/thing', $payload);

		$with = $this->requests()[0]['headers'];
		$this->sent = [];

		$this->queue(200, '', ['ETag' => '"tag"']);
		$disabled->put('frames/thing', $payload);

		$without = $this->requests()[0]['headers'];

		$this->assertSame(
			base64_encode(hash('sha256', $payload, true)),
			$with['x-amz-checksum-sha256'],
		);
		$this->assertArrayNotHasKey('x-amz-checksum-sha256', $without);

		// the payload hash goes out on every endpoint, checksums or not
		$this->assertSame(hash('sha256', $payload), $with['x-amz-content-sha256']);
		$this->assertSame(hash('sha256', $payload), $without['x-amz-content-sha256']);
	}

	#[Test]
	#[TestDox('a batch delete carries a content digest only where checksums are enabled')]
	#[Group('strata/s3')]
	public function batchDeleteCarriesContentDigestWhenEnabled(): void
	{
		$enabled = $this->provider(
			new S3Endpoint('strata-backups', 'us-east-1', null, false, self::PREFIX, true),
		);

		$this->queue(200, '<DeleteResult></DeleteResult>');
		$enabled->delete(['frames/thing']);

		$request = $this->requests()[0];

		$this->assertSame(
			base64_encode(md5((string) $request['body'], true)),
			$request['headers']['Content-MD5'],
		);
	}

	#endregion

	#region Presigning

	#[Test]
	#[TestDox('a presigned url carries the signature in its query string')]
	#[Group('strata/s3')]
	public function presignedUrlCarriesTheSignature(): void
	{
		$url = $this->provider()->presign('frames/ab/cd/object', 900);

		$this->assertStringStartsWith(
			'https://strata-backups.s3.us-east-1.amazonaws.com/_strata/site-1/frames/ab/cd/object?',
			$url,
		);
		$this->assertStringContainsString('X-Amz-Expires=900', $url);
		$this->assertStringContainsString('X-Amz-SignedHeaders=host', $url);
		$this->assertStringContainsString('X-Amz-Signature=', $url);
	}

	#[Test]
	#[TestDox('presigning is refused on an endpoint that could not be probed')]
	#[Group('strata/s3')]
	public function presigningRefusedWhereUnsupported(): void
	{
		$this->probeStatus = 500;

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('does not support presigned urls');

		$this->provider()->presign('frames/thing');
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
		];
	}

	#[Test]
	#[TestDox('a key containing $_dataName is refused before it reaches the endpoint')]
	#[Group('strata/s3')]
	#[DataProvider('unsafeKeyProvider')]
	public function unsafeKeysAreRefused(string $key): void
	{
		$this->expectException(InvalidArgumentException::class);

		try {
			$this->provider()->put($key, 'payload');
		} finally {
			$this->assertSame([], $this->sent, 'an unsafe key must not reach the transport');
		}
	}

	#[Test]
	#[TestDox('writing to the reserved in-progress suffix is refused')]
	#[Group('strata/s3')]
	public function reservedSuffixCannotBeWritten(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('reserved suffix');

		$this->provider()->put('frames/thing.tmp', 'payload');
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
	#[Group('strata/s3')]
	#[DataProvider('unusableBodyProvider')]
	public function refusesBodyThatIsNeitherStringNorStream(mixed $body): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('string or an open stream resource');

		$this->provider()->put('frames/thing', $body);
	}

	#endregion

	#region Failures

	#[Test]
	#[TestDox('an upload opened without an id is refused instead of leaving parts nowhere')]
	#[Group('strata/s3')]
	public function uploadWithoutIdIsRefused(): void
	{
		$provider = $this->provider();
		$this->queue(200, '<InitiateMultipartUploadResult></InitiateMultipartUploadResult>');

		$stream = $this->unsizableStream(str_repeat('strata', 1_000_000));

		try {
			$this->expectException(RuntimeException::class);
			$this->expectExceptionMessage('opened an upload of packs/big.pack without naming it');

			$provider->put('packs/big.pack', $stream);
		} finally {
			fclose($stream);
		}
	}

	#[Test]
	#[TestDox('a part answered without an entity tag is refused rather than completed blind')]
	#[Group('strata/s3')]
	public function partWithoutEntityTagIsRefused(): void
	{
		$provider = $this->provider();

		$this->queue(
			200,
			'<InitiateMultipartUploadResult><UploadId>UPLOAD-3</UploadId>' .
				'</InitiateMultipartUploadResult>',
		);
		$this->queue(200);

		$stream = $this->unsizableStream(str_repeat('strata', 1_000_000));

		try {
			$this->expectException(RuntimeException::class);
			$this->expectExceptionMessage('Part 1 of packs/big.pack came back with no entity tag');

			$provider->put('packs/big.pack', $stream);
		} finally {
			fclose($stream);
		}
	}

	#[Test]
	#[TestDox('an error document answered with a 200 on completion is still an error')]
	#[Group('strata/s3')]
	public function errorDocumentOnCompletionIsRefused(): void
	{
		$provider = $this->provider();

		$this->queue(
			200,
			'<InitiateMultipartUploadResult><UploadId>UPLOAD-4</UploadId>' .
				'</InitiateMultipartUploadResult>',
		);
		$this->queue(200, '', ['ETag' => '"part-one"']);
		$this->queue(200, '', ['ETag' => '"part-two"']);
		$this->queue(200, '<Error><Code>InternalError</Code></Error>');

		$stream = $this->unsizableStream(str_repeat('strata', 1_000_000));

		try {
			$provider->put('packs/big.pack', $stream);
			$this->fail('an error document has to raise even behind a 200');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString(
				'Cannot complete the upload of packs/big.pack: InternalError',
				$e->getMessage(),
			);
		} finally {
			fclose($stream);
		}

		$requests = $this->requests();

		$this->assertSame('DELETE', $requests[count($requests) - 1]['method']);
	}

	#[Test]
	#[TestDox('heading an object the endpoint refuses raises rather than reading as absent')]
	#[Group('strata/s3')]
	public function refusedHeadRaises(): void
	{
		$provider = $this->provider();
		$this->queue(403, '<Error><Code>AccessDenied</Code></Error>');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot head object frames/thing');

		$provider->head('frames/thing');
	}

	#[Test]
	#[TestDox('a refused single delete is reported by key')]
	#[Group('strata/s3')]
	public function refusedSingleDeleteIsReported(): void
	{
		$this->probeStatus = 500;
		$provider = $this->provider();
		$this->queue(403, '<Error><Code>AccessDenied</Code></Error>');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot delete object frames/thing');

		$provider->delete(['frames/thing']);
	}

	#[Test]
	#[TestDox('a listing body that is not xml is reported rather than parsed into nothing')]
	#[Group('strata/s3')]
	public function nonXmlListingIsReported(): void
	{
		$provider = $this->provider();
		$this->queue(200, 'this is not xml at all');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot list objects: the endpoint answered with a body');

		$provider->list('');
	}

	#[Test]
	#[TestDox('an error body that cannot be parsed leaves the status as the whole report')]
	#[Group('strata/s3')]
	public function unparseableErrorBodyLeavesTheStatus(): void
	{
		$provider = $this->provider();
		$this->queue(500, '<Error<<truncated');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot read object frames/thing: the endpoint answered 500');

		$provider->get('frames/thing');
	}

	#[Test]
	#[TestDox('a transport answering with something that is not a response is reported')]
	#[Group('strata/s3')]
	public function transportAnsweringWithNonsenseIsReported(): void
	{
		$provider = new S3StorageProvider(
			new EnvironmentCredentials([
				'AWS_ACCESS_KEY_ID' => 'AKIDEXAMPLE',
				'AWS_SECRET_ACCESS_KEY' => 'secret',
			]),
			new S3Endpoint('strata-backups', 'us-east-1'),
			static fn(): string => 'not a response',
			new SigV4Signer('us-east-1'),
		);

		$this->assertFalse($provider->isReachable());

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('something that is not a response');

		$provider->get('frames/thing');
	}

	#[Test]
	#[TestDox('the tier configured on the endpoint applies when a write names none')]
	#[Group('strata/s3')]
	public function endpointStorageClassAppliesByDefault(): void
	{
		$provider = $this->provider(
			new S3Endpoint(
				'strata-backups',
				'us-east-1',
				null,
				false,
				self::PREFIX,
				false,
				'GLACIER_IR',
			),
		);
		$this->queue(200, '', ['ETag' => '"tag"']);

		$provider->put('frames/thing', 'payload');

		$this->assertSame('GLACIER_IR', $this->requests()[0]['headers']['x-amz-storage-class']);
	}

	#endregion

	#region Credentials

	#[Test]
	#[TestDox('a missing credential source is reported by naming what was tried')]
	#[Group('strata/s3')]
	public function missingCredentialsNameTheSource(): void
	{
		$provider = $this->provider(null, []);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No credentials are available from Environment');

		$provider->put('frames/thing', 'payload');
	}

	#endregion
}
