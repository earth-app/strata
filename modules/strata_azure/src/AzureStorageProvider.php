<?php

declare(strict_types=1);

namespace Drupal\strata_azure;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Drupal\strata\Storage\BodyReader;
use Drupal\strata\Storage\ByteRange;
use Drupal\strata\Storage\Capabilities;
use Drupal\strata\Storage\ObjectMeta;
use Drupal\strata\Storage\ObjectPage;
use Drupal\strata\Storage\PutResult;
use Drupal\strata\Storage\StorageProviderInterface;
use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Stores objects on Azure Blob Storage over the Blob REST API.
 *
 * Native rather than S3-compatible, because Azure has no S3 surface at all. Everything the engine
 * branches on comes from Capabilities, so nothing upstream of this class knows which cloud it is
 * writing to.
 *
 * Three things differ from S3 in ways a reader has to know about:
 *
 * - **There is no batch delete.** The Blob REST surface deletes one blob per request, and the Batch
 *   API is a separate multipart protocol with its own sub-request signing. Capabilities therefore
 *   reports `batchDelete: false` and a prune costs one request per key rather than pretending
 *   otherwise.
 * - **A large blob is blocks, not parts, and there is nothing to abort.** Blocks are staged under
 *   ids and become a blob only when Put Block List names them. A failed upload leaves uncommitted
 *   blocks that Azure discards on its own after seven days, so a failure needs no cleanup request.
 * - **Block ids must all be the same length**, and the service rejects a Put Block List whose ids
 *   disagree. They are base64 of a fixed-width sequence for exactly that reason.
 *
 * The HTTP transport is injected as a callable, so the contract is exercised over a recorded
 * exchange with no network and no credentials.
 *
 * @see StorageProviderInterface
 * @see AzureEndpoint
 * @see SharedKeySigner
 */
final class AzureStorageProvider implements StorageProviderInterface
{
	/**
	 * Protocol version every request declares.
	 */
	public const API_VERSION = '2021-12-02';

	/**
	 * Largest blob accepted in one Put Blob, in bytes.
	 *
	 * The protocol takes 5000 MiB from version 2019-12-12, and 256 MiB before it. The lower figure
	 * is what every version and every emulator accepts, and the only cost of using it is that a
	 * larger blob is staged as blocks.
	 */
	public const MAX_SINGLE_PUT = 268_435_456;

	/**
	 * Smallest block this provider stages, in bytes.
	 *
	 * Azure imposes no minimum. This one keeps the block count for a large blob sane.
	 */
	public const MIN_BLOCK_SIZE = 4_194_304;

	/**
	 * Largest block, in bytes.
	 */
	public const MAX_BLOCK_SIZE = 104_857_600;

	/**
	 * Most blocks one blob may be committed from.
	 */
	public const MAX_BLOCKS = 50_000;

	/**
	 * Prefix on the headers that carry user metadata.
	 */
	private const METADATA_PREFIX = 'x-ms-meta-';

	/**
	 * Header naming the kind of blob a write creates.
	 */
	private const BLOB_TYPE_HEADER = 'x-ms-blob-type';

	/**
	 * Header carrying a byte range on a read.
	 */
	private const RANGE_HEADER = 'x-ms-range';

	/**
	 * Header carrying the access tier a blob is written to.
	 */
	private const TIER_HEADER = 'x-ms-access-tier';

	/**
	 * Header the service reports its own error code in.
	 */
	private const ERROR_HEADER = 'x-ms-error-code';

	/**
	 * The injected transport.
	 */
	private readonly Closure $transport;

	/**
	 * The probed capability set, or NULL before the first probe.
	 */
	private ?Capabilities $capabilities = null;

	/**
	 * Why the endpoint could not be reached, or NULL when it answered.
	 */
	private ?string $unreachable = null;

	/**
	 * Constructs a provider.
	 *
	 * @param AzureCredentials $credentials
	 *   The account key or the SAS token.
	 * @param AzureEndpoint $endpoint
	 *   Which container, addressed how.
	 * @param callable $transport
	 *   A transport with the signature
	 *   `callable(string $method, string $url, array $headers, ?string $body): array{status: int,
	 *   body: string, headers?: array<string, string>}`. Response headers are optional but entity
	 *   tags and blob metadata come from them, so a transport that drops them loses both.
	 * @param SharedKeySigner $signer
	 *   The signer, used only when the credentials carry an account key.
	 *
	 * @throws InvalidArgumentException
	 *   When the credentials name a different account than the endpoint is addressed at. That
	 *   combination produces a signature the service rejects with no indication of why.
	 */
	public function __construct(
		private readonly AzureCredentials $credentials,
		private readonly AzureEndpoint $endpoint,
		callable $transport,
		private readonly SharedKeySigner $signer,
	) {
		if ($credentials->hasSharedKey() && $credentials->accountName !== $endpoint->account) {
			throw new InvalidArgumentException(
				sprintf(
					'The credentials are for account %s and the endpoint is addressed at %s',
					$credentials->accountName,
					$endpoint->account,
				),
			);
		}

		$this->transport = $transport(...);
	}

	/**
	 * The endpoint this provider writes to.
	 *
	 * @return AzureEndpoint
	 *   The endpoint.
	 */
	public function endpoint(): AzureEndpoint
	{
		return $this->endpoint;
	}

	#region Identity

	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return 'azure';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label(): string
	{
		return 'Azure Blob Storage';
	}

	/**
	 * {@inheritdoc}
	 */
	public function capabilities(): Capabilities
	{
		return $this->capabilities ??= $this->probe();
	}

	/**
	 * {@inheritdoc}
	 */
	public function isReachable(): bool
	{
		return $this->unreachableReason() === null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function unreachableReason(): ?string
	{
		try {
			$this->capabilities();
		} catch (Throwable $e) {
			// hook_requirements() calls this, so a broken endpoint reports rather than fatals
			return $e->getMessage();
		}

		return $this->unreachable;
	}

	#endregion

	#region Reading

	/**
	 * {@inheritdoc}
	 */
	public function get(string $key, ?ByteRange $range = null): string
	{
		$headers = $range === null ? [] : [self::RANGE_HEADER => $range->toHeader()];
		$response = $this->request('GET', $this->blobUrl($key), $headers, null);

		if ($response['status'] === 404) {
			throw new RuntimeException(sprintf('Object %s is not present', $key));
		}

		$this->assertSuccess($response, sprintf('read object %s', $key));

		$bytes = $response['body'];

		// a short range read means the object is smaller than the manifest says it is
		if ($range !== null && strlen($bytes) !== $range->length) {
			throw new RuntimeException(
				sprintf(
					'Object %s returned %d bytes for a %d byte range at offset %d',
					$key,
					strlen($bytes),
					$range->length,
					$range->offset,
				),
			);
		}

		return $bytes;
	}

	/**
	 * {@inheritdoc}
	 *
	 * The transport hands back a complete body, so the blob is buffered into a temporary stream. A
	 * caller that must not hold the blob in memory reads it in ranges instead.
	 */
	public function stream(string $key)
	{
		$bytes = $this->get($key);
		$handle = fopen('php://temp', 'r+b');

		if ($handle === false) {
			throw new RuntimeException(sprintf('Cannot open a stream for object %s', $key));
		}

		fwrite($handle, $bytes);
		rewind($handle);

		return $handle;
	}

	/**
	 * {@inheritdoc}
	 */
	public function head(string $key): ?ObjectMeta
	{
		$response = $this->request('HEAD', $this->blobUrl($key), [], null);

		// absence is what every caller of this method is asking about, so it is not an error
		if ($response['status'] === 404) {
			return null;
		}

		$this->assertSuccess($response, sprintf('head object %s', $key));

		$headers = $response['headers'];
		$metadata = [];

		foreach ($headers as $name => $value) {
			if (str_starts_with($name, self::METADATA_PREFIX)) {
				$metadata[substr($name, strlen(self::METADATA_PREFIX))] = $value;
			}
		}

		return new ObjectMeta(
			$this->endpoint->keys->normalize($key),
			(int) ($headers['content-length'] ?? 0),
			$headers['etag'] ?? null,
			$this->timestamp($headers['last-modified'] ?? ''),
			$headers[self::TIER_HEADER] ?? null,
			$metadata,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function exists(string $key): bool
	{
		return $this->head($key) !== null;
	}

	#endregion

	#region Writing

	/**
	 * {@inheritdoc}
	 */
	public function put(string $key, mixed $body, array $options = []): PutResult
	{
		$started = microtime(true);
		$target = $this->endpoint->keys->resolve($key);
		$capabilities = $this->capabilities();

		if (($options['ifNoneMatch'] ?? false) === true && !$capabilities->conditionalWrite) {
			throw new RuntimeException(
				sprintf('%s does not honour a conditional write', $this->endpoint->host()),
			);
		}

		if (is_string($body)) {
			$this->assertFits(strlen($body), $key);

			return $capabilities->requiresMultipart(strlen($body))
				? $this->putBlocks(
					$key,
					$target,
					BodyReader::sliceString($body, $capabilities->partSizeFor(strlen($body))),
					$options,
					$started,
				)
				: $this->putSingle($key, $target, $body, $options, $started);
		}

		if (!is_resource($body)) {
			throw new InvalidArgumentException(
				'A body must be a string or an open stream resource',
			);
		}

		$size = BodyReader::sizeOf($body);

		if ($size !== null) {
			$this->assertFits($size, $key);

			if (!$capabilities->requiresMultipart($size)) {
				return $this->putSingle(
					$key,
					$target,
					BodyReader::readAll($body, $key),
					$options,
					$started,
				);
			}
		}

		// with no size to plan from, blocks are the smallest uniform size the endpoint accepts
		$blockSize = $capabilities->partSizeFor($size ?? 0);
		$first = BodyReader::readExactly($body, $blockSize, $key);

		if (strlen($first) < $blockSize) {
			return $this->putSingle($key, $target, $first, $options, $started);
		}

		return $this->putBlocks(
			$key,
			$target,
			BodyReader::sliceStream($body, $blockSize, $first, $key),
			$options,
			$started,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * One request per key, because the Blob REST surface has no batch delete and the Batch API is a
	 * separate multipart protocol. A key that was not there is reported as not removed, which Azure
	 * can say and S3 cannot.
	 */
	public function delete(array $keys): int
	{
		$removed = 0;

		foreach (array_values($keys) as $key) {
			$removed += $this->deleteOne($key);
		}

		return $removed;
	}

	#endregion

	#region Listing

	/**
	 * {@inheritdoc}
	 */
	public function list(
		string $prefix = '',
		?string $cursor = null,
		int $limit = 1000,
		?string $delimiter = null,
	): ObjectPage {
		if ($limit < 1) {
			throw new InvalidArgumentException('A listing limit must be at least one');
		}

		$query = [
			'restype' => 'container',
			'comp' => 'list',
			'maxresults' => (string) $limit,
			'prefix' => $this->endpoint->keys->scope($prefix),
		];

		if ($cursor !== null && $cursor !== '') {
			$query['marker'] = $cursor;
		}
		if ($delimiter !== null && $delimiter !== '') {
			$query['delimiter'] = $delimiter;
		}

		$response = $this->request(
			'GET',
			$this->endpoint->containerUrl() . '?' . $this->queryString($query),
			[],
			null,
		);
		$this->assertSuccess($response, 'list objects');

		$document = $this->parseXml($response['body'], 'list objects');
		$objects = [];
		$prefixes = [];

		foreach ($document->Blobs->Blob as $blob) {
			$name = (string) $blob->Name;

			// a zero-byte directory marker left by another tool is not an object
			if (str_ends_with($name, '/')) {
				continue;
			}

			$properties = $blob->Properties;

			$objects[] = new ObjectMeta(
				$this->endpoint->keys->strip($name),
				(int) (string) $properties->{'Content-Length'},
				$this->etagOf($properties),
				$this->timestamp((string) $properties->{'Last-Modified'}),
				isset($properties->AccessTier) ? (string) $properties->AccessTier : null,
			);
		}

		foreach ($document->Blobs->BlobPrefix as $common) {
			$prefixes[] = $this->endpoint->keys->strip((string) $common->Name);
		}

		$next = trim((string) $document->NextMarker);

		return new ObjectPage($objects, $next === '' ? null : $next, $prefixes);
	}

	#endregion

	#region Single writes

	/**
	 * Writes one blob in a single Put Blob.
	 *
	 * @param string $key
	 *   Object key as the caller named it.
	 * @param string $target
	 *   The prefixed key.
	 * @param string $body
	 *   The bytes.
	 * @param array<string, mixed> $options
	 *   Provider options.
	 * @param float $started
	 *   When the write began, from microtime().
	 *
	 * @return PutResult
	 *   What was written.
	 *
	 * @throws RuntimeException
	 *   When the service refuses the write, including when it reports the content digest it computed
	 *   as disagreeing with the one that was sent.
	 */
	private function putSingle(
		string $key,
		string $target,
		string $body,
		array $options,
		float $started,
	): PutResult {
		$headers = $this->objectHeaders($options);
		$headers[self::BLOB_TYPE_HEADER] = 'BlockBlob';
		$headers['Content-Length'] = (string) strlen($body);
		$headers['Content-MD5'] = base64_encode(md5($body, true));

		if (($options['ifNoneMatch'] ?? false) === true) {
			$headers['If-None-Match'] = '*';
		}

		$response = $this->request('PUT', $this->endpoint->blobUrl($target), $headers, $body);

		$this->assertNoConflict($response, $key, $options);
		$this->assertSuccess($response, sprintf('write object %s', $key));

		return new PutResult(
			$this->endpoint->keys->normalize($key),
			strlen($body),
			$response['headers']['etag'] ?? null,
			false,
			1,
			microtime(true) - $started,
		);
	}

	#endregion

	#region Blocks

	/**
	 * Writes one blob by staging blocks and committing a block list.
	 *
	 * A failure leaves the staged blocks uncommitted, which is not a blob and which the service
	 * discards after seven days, so there is nothing to abort.
	 *
	 * @param string $key
	 *   Object key as the caller named it.
	 * @param string $target
	 *   The prefixed key.
	 * @param iterable<int, string> $blocks
	 *   The blocks, in order.
	 * @param array<string, mixed> $options
	 *   Provider options.
	 * @param float $started
	 *   When the write began, from microtime().
	 *
	 * @return PutResult
	 *   What was written.
	 *
	 * @throws RuntimeException
	 *   When the endpoint stages no blocks, the block count would exceed the ceiling, or any block
	 *   or the commit fails.
	 */
	private function putBlocks(
		string $key,
		string $target,
		iterable $blocks,
		array $options,
		float $started,
	): PutResult {
		$capabilities = $this->capabilities();

		if (!$capabilities->multipart) {
			throw new RuntimeException(
				sprintf(
					'Object %s is larger than %s takes in one request and blocks are unavailable',
					$key,
					$this->endpoint->host(),
				),
			);
		}

		$ids = [];
		$size = 0;

		foreach ($blocks as $block) {
			$number = count($ids) + 1;

			if ($number > $capabilities->maxParts) {
				throw new RuntimeException(
					sprintf(
						'Object %s needs more than the %d blocks %s allows',
						$key,
						$capabilities->maxParts,
						$this->endpoint->host(),
					),
				);
			}

			$ids[] = $this->stageBlock($key, $target, $number, $block);
			$size += strlen($block);
		}

		if ($ids === []) {
			throw new RuntimeException(sprintf('Object %s produced no blocks to upload', $key));
		}

		return new PutResult(
			$this->endpoint->keys->normalize($key),
			$size,
			$this->commitBlocks($key, $target, $ids, $options),
			true,
			count($ids),
			microtime(true) - $started,
		);
	}

	/**
	 * Stages one block.
	 *
	 * @param string $key
	 *   Object key as the caller named it.
	 * @param string $target
	 *   The prefixed key.
	 * @param int $number
	 *   Block number, counting from one.
	 * @param string $block
	 *   The bytes.
	 *
	 * @return string
	 *   The block id, base64 as the service takes it.
	 *
	 * @throws RuntimeException
	 *   When the block is refused.
	 */
	private function stageBlock(string $key, string $target, int $number, string $block): string
	{
		$id = $this->blockId($number);
		$response = $this->request(
			'PUT',
			sprintf(
				'%s?comp=block&blockid=%s',
				$this->endpoint->blobUrl($target),
				rawurlencode($id),
			),
			[
				'Content-Length' => (string) strlen($block),
				'Content-MD5' => base64_encode(md5($block, true)),
			],
			$block,
		);
		$this->assertSuccess($response, sprintf('stage block %d of %s', $number, $key));

		return $id;
	}

	/**
	 * Commits staged blocks into a blob.
	 *
	 * @param string $key
	 *   Object key as the caller named it.
	 * @param string $target
	 *   The prefixed key.
	 * @param list<string> $ids
	 *   Block ids, in the order the blob is assembled in.
	 * @param array<string, mixed> $options
	 *   Provider options.
	 *
	 * @return string|null
	 *   The blob's entity tag, or NULL when the service did not report one.
	 *
	 * @throws RuntimeException
	 *   When the commit is refused.
	 */
	private function commitBlocks(string $key, string $target, array $ids, array $options): ?string
	{
		$xml = '<?xml version="1.0" encoding="utf-8"?><BlockList>';

		foreach ($ids as $id) {
			$xml .= sprintf('<Latest>%s</Latest>', $this->escape($id));
		}

		$xml .= '</BlockList>';

		$headers = $this->objectHeaders($options);
		$headers['Content-Type'] = 'application/xml';
		$headers['Content-Length'] = (string) strlen($xml);

		if (($options['ifNoneMatch'] ?? false) === true) {
			$headers['If-None-Match'] = '*';
		}

		$response = $this->request(
			'PUT',
			$this->endpoint->blobUrl($target) . '?comp=blocklist',
			$headers,
			$xml,
		);

		$this->assertNoConflict($response, $key, $options);
		$this->assertSuccess($response, sprintf('commit the blocks of %s', $key));

		return $response['headers']['etag'] ?? null;
	}

	/**
	 * The id one block is staged under.
	 *
	 * Every id a Put Block List names has to decode to the same number of bytes, and the service
	 * rejects the whole commit when they disagree. A fixed-width sequence guarantees it.
	 *
	 * @param int $number
	 *   Block number, counting from one.
	 *
	 * @return string
	 *   The id, base64 encoded.
	 */
	private function blockId(int $number): string
	{
		return base64_encode(sprintf('strata-%016d', $number));
	}

	#endregion

	#region Deleting

	/**
	 * Deletes one blob.
	 *
	 * @param string $key
	 *   Object key relative to the store root.
	 *
	 * @return int
	 *   1 when a blob was removed, 0 when it was already absent.
	 *
	 * @throws RuntimeException
	 *   When the service refuses.
	 */
	private function deleteOne(string $key): int
	{
		$response = $this->request('DELETE', $this->blobUrl($key), [], null);

		// an absent blob is not an error, so a retried prune is idempotent
		if ($response['status'] === 404) {
			return 0;
		}

		$this->assertSuccess($response, sprintf('delete object %s', $key));

		return 1;
	}

	#endregion

	#region Capabilities

	/**
	 * Probes the endpoint once.
	 *
	 * The probe is a listing capped at one blob, which proves the container answers and that the
	 * credentials work without transferring anything.
	 *
	 * @return Capabilities
	 *   The assumed set when the probe succeeded, or that set with everything optional switched off
	 *   when it did not.
	 */
	private function probe(): Capabilities
	{
		$assumed = $this->assumed();

		try {
			$response = $this->request(
				'GET',
				$this->endpoint->containerUrl() . '?restype=container&comp=list&maxresults=1',
				[],
				null,
			);

			if ($response['status'] >= 200 && $response['status'] < 300) {
				$this->unreachable = null;

				return $assumed;
			}

			$this->unreachable = $this->errorMessage($response, 'list the container');
		} catch (Throwable $e) {
			$this->unreachable = $e->getMessage();
		}

		// nothing is known about an endpoint that would not answer, so nothing optional is claimed
		return $assumed->with([
			'multipart' => false,
			'conditionalWrite' => false,
			'checksums' => false,
			'storageClasses' => false,
		]);
	}

	/**
	 * The capability set the blob service is assumed to have before it answers.
	 *
	 * @return Capabilities
	 *   Azure's documented limits. Batch delete and presigning are off because this provider
	 *   implements neither: the Batch API is a separate multipart protocol, and a service SAS is
	 *   minted by a different signing scheme than the one that authenticates a request.
	 */
	private function assumed(): Capabilities
	{
		$capabilities = new Capabilities(
			multipart: true,
			batchDelete: false,
			conditionalWrite: true,
			rangeRead: true,
			presign: false,
			checksums: true,
			storageClasses: true,
			maxSinglePut: self::MAX_SINGLE_PUT,
			minPartSize: self::MIN_BLOCK_SIZE,
			maxPartSize: self::MAX_BLOCK_SIZE,
			maxParts: self::MAX_BLOCKS,
			maxBatchDelete: 1,
			uniformPartSize: false,
		);

		// a configured threshold lowers where blocks begin; it never raises it above one request
		if ($this->endpoint->blockThreshold !== null) {
			return $capabilities->with([
				'maxSinglePut' => min(self::MAX_SINGLE_PUT, $this->endpoint->blockThreshold),
			]);
		}

		return $capabilities;
	}

	#endregion

	#region Requests

	/**
	 * Authenticates and sends one request.
	 *
	 * @param string $method
	 *   HTTP method.
	 * @param string $url
	 *   Absolute URL, including any query string.
	 * @param array<string, string> $headers
	 *   Headers to send, before authentication.
	 * @param string|null $body
	 *   The request body, or NULL for none.
	 *
	 * @return array{status: int, body: string, headers: array<string, string>}
	 *   The response, with header names lowercased.
	 *
	 * @throws RuntimeException
	 *   When the transport answers with something that is not a response.
	 */
	private function request(string $method, string $url, array $headers, ?string $body): array
	{
		$now = $this->now();
		$headers[SharedKeySigner::VERSION_HEADER] = self::API_VERSION;
		$headers[SharedKeySigner::DATE_HEADER] = $this->signer->timestamp($now);

		if ($this->credentials->hasSharedKey()) {
			$headers = $this->signer->sign($this->credentials, $method, $url, $headers, $now);
		} else {
			$url .= (str_contains($url, '?') ? '&' : '?') . $this->credentials->sasQuery;
		}

		$response = ($this->transport)($method, $url, $headers, $body);

		if (!is_array($response)) {
			throw new RuntimeException(
				sprintf(
					'The transport answered %s %s with something that is not a response',
					$method,
					$url,
				),
			);
		}

		$normalized = [];

		foreach ((array) ($response['headers'] ?? []) as $name => $value) {
			$normalized[strtolower(trim((string) $name))] = is_array($value)
				? (string) ($value[0] ?? '')
				: (string) $value;
		}

		return [
			'status' => (int) ($response['status'] ?? 0),
			'body' => (string) ($response['body'] ?? ''),
			'headers' => $normalized,
		];
	}

	/**
	 * Raises unless a response was a success.
	 *
	 * @param array{status: int, body: string, headers: array<string, string>} $response
	 *   The response.
	 * @param string $what
	 *   What was being attempted, as a verb phrase for the message.
	 *
	 * @throws RuntimeException
	 *   When the status is outside the 2xx range.
	 */
	private function assertSuccess(array $response, string $what): void
	{
		if ($response['status'] >= 200 && $response['status'] < 300) {
			return;
		}

		throw new RuntimeException($this->errorMessage($response, $what));
	}

	/**
	 * Raises when a conditional write lost the race.
	 *
	 * A failed `If-None-Match: *` comes back as either 409 or 412 depending on the operation, so both
	 * are read as the same thing rather than one of them reaching the caller as a bare status.
	 *
	 * @param array{status: int, body: string, headers: array<string, string>} $response
	 *   The response.
	 * @param string $key
	 *   Object key as the caller named it.
	 * @param array<string, mixed> $options
	 *   Provider options.
	 *
	 * @throws RuntimeException
	 *   When the write was conditional on absence and the blob was there.
	 */
	private function assertNoConflict(array $response, string $key, array $options): void
	{
		if (($options['ifNoneMatch'] ?? false) !== true) {
			return;
		}
		if ($response['status'] !== 409 && $response['status'] !== 412) {
			return;
		}

		throw new RuntimeException(
			sprintf('Object %s already exists and the write was conditional on its absence', $key),
		);
	}

	/**
	 * A message describing a failed response.
	 *
	 * @param array{status: int, body: string, headers: array<string, string>} $response
	 *   The response.
	 * @param string $what
	 *   What was being attempted, as a verb phrase.
	 *
	 * @return string
	 *   The status, and the service's own error code where it sent one. Azure reports the code in a
	 *   header as well as in the body, and a HEAD has no body at all.
	 */
	private function errorMessage(array $response, string $what): string
	{
		$detail = trim((string) ($response['headers'][self::ERROR_HEADER] ?? ''));

		if ($detail === '' && str_contains($response['body'], '<Error')) {
			try {
				$document = $this->parseXml($response['body'], $what);
				$detail = trim(
					trim((string) $document->Code) . ' ' . trim((string) $document->Message),
				);
			} catch (Throwable) {
				// an unparseable error body leaves the status as the only thing worth reporting
			}
		}

		return sprintf(
			'Cannot %s: the endpoint answered %d%s',
			$what,
			$response['status'],
			$detail === '' ? '' : ' (' . $detail . ')',
		);
	}

	/**
	 * The current time in UTC.
	 *
	 * @return DateTimeImmutable
	 *   Now, in the only zone a signature accepts.
	 */
	private function now(): DateTimeImmutable
	{
		return new DateTimeImmutable('now', new DateTimeZone('UTC'));
	}

	#endregion

	#region Bodies

	/**
	 * Headers that describe the blob being written.
	 *
	 * @param array<string, mixed> $options
	 *   Provider options.
	 *
	 * @return array<string, string>
	 *   Content type, access tier and user metadata, where each was asked for.
	 *
	 * @throws InvalidArgumentException
	 *   When a metadata name is not one azure can store. Azure requires a C# identifier, and a
	 *   provider that renamed the key to fit would write an object whose reader cannot find it.
	 */
	private function objectHeaders(array $options): array
	{
		$headers = [];
		$contentType = $options['contentType'] ?? null;

		if (is_string($contentType) && trim($contentType) !== '') {
			$headers['x-ms-blob-content-type'] = $contentType;
		}

		$tier = $options['storageClass'] ?? $this->endpoint->accessTier;

		if (is_string($tier) && trim($tier) !== '') {
			$headers[self::TIER_HEADER] = $tier;
		}

		$metadata = $options['metadata'] ?? [];

		foreach (is_array($metadata) ? $metadata : [] as $name => $value) {
			$clean = strtolower(trim((string) $name));

			if ($clean === '') {
				continue;
			}

			// azure requires a metadata name to be a c# identifier, and renaming one silently would
			// store it where its reader will not look
			if (preg_match('/^[a-z_][a-z0-9_]*$/', $clean) !== 1) {
				throw new InvalidArgumentException(
					sprintf(
						'Metadata name "%s" is not a letter, digit and underscore identifier, which is all azure stores',
						(string) $name,
					),
				);
			}

			$headers[self::METADATA_PREFIX . $clean] = (string) $value;
		}

		return $headers;
	}

	/**
	 * Guards a blob against the endpoint's ceiling.
	 *
	 * @param int $size
	 *   Object size in bytes.
	 * @param string $key
	 *   Object key, for the message.
	 *
	 * @throws RuntimeException
	 *   When the blob is larger than the endpoint can hold at all.
	 */
	private function assertFits(int $size, string $key): void
	{
		if ($this->capabilities()->canStore($size)) {
			return;
		}

		throw new RuntimeException(
			sprintf(
				'Object %s is %d bytes and %s holds at most %d',
				$key,
				$size,
				$this->endpoint->host(),
				$this->capabilities()->maxObjectSize(),
			),
		);
	}

	#endregion

	#region Urls

	/**
	 * The URL one caller-named key is addressed at.
	 *
	 * @param string $key
	 *   Object key relative to the store root.
	 *
	 * @return string
	 *   The absolute blob URL.
	 *
	 * @throws InvalidArgumentException
	 *   When the key is unsafe.
	 */
	private function blobUrl(string $key): string
	{
		return $this->endpoint->blobUrl($this->endpoint->keys->resolve($key));
	}

	/**
	 * Renders query parameters in ascending name order.
	 *
	 * @param array<string, string> $parameters
	 *   Decoded values keyed by decoded name.
	 *
	 * @return string
	 *   The query string, with no leading `?`.
	 */
	private function queryString(array $parameters): string
	{
		ksort($parameters, SORT_STRING);

		$pairs = [];

		foreach ($parameters as $name => $value) {
			$pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
		}

		return implode('&', $pairs);
	}

	#endregion

	#region Xml

	/**
	 * Parses a response body as XML with entity substitution off.
	 *
	 * A listing is attacker-influenced: a blob name is whatever was written, and a compromised or
	 * hostile endpoint answers with whatever it likes. LIBXML_NOENT is therefore not passed. Its
	 * name reads as a refusal and it is the opposite: it turns entity substitution on, and libxml
	 * 2.9.13 then resolves a `<!ENTITY leak SYSTEM "file:///...">` in a listing and puts the file's
	 * contents in the blob name, LIBXML_NONET or not.
	 *
	 * @param string $body
	 *   The response body.
	 * @param string $what
	 *   What was being attempted, for the message.
	 *
	 * @return SimpleXMLElement
	 *   The parsed document.
	 *
	 * @throws RuntimeException
	 *   When the body is not XML.
	 */
	private function parseXml(string $body, string $what): SimpleXMLElement
	{
		$previous = libxml_use_internal_errors(true);
		$document = simplexml_load_string(
			$body,
			SimpleXMLElement::class,
			LIBXML_NONET | LIBXML_NOCDATA,
		);

		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if (!($document instanceof SimpleXMLElement)) {
			throw new RuntimeException(
				sprintf('Cannot %s: the endpoint answered with a body that is not xml', $what),
			);
		}

		return $document;
	}

	/**
	 * The entity tag a listing reported for one blob.
	 *
	 * The element is `Etag` on every protocol version this provider declares and `ETag` on older
	 * ones, and a listing that lost the tag would make a verify read every object to compare.
	 *
	 * @param SimpleXMLElement $properties
	 *   The blob's `Properties` element.
	 *
	 * @return string|null
	 *   The tag exactly as it was reported, or NULL when there was none.
	 */
	private function etagOf(SimpleXMLElement $properties): ?string
	{
		if (isset($properties->Etag)) {
			return (string) $properties->Etag;
		}

		return isset($properties->ETag) ? (string) $properties->ETag : null;
	}

	/**
	 * Escapes a value for an XML text node.
	 *
	 * @param string $value
	 *   The value.
	 *
	 * @return string
	 *   The value with the three characters a text node cannot hold replaced.
	 */
	private function escape(string $value): string
	{
		return htmlspecialchars($value, ENT_NOQUOTES | ENT_XML1, 'UTF-8');
	}

	/**
	 * Reads an HTTP or ISO timestamp.
	 *
	 * @param string $value
	 *   A `Last-Modified` header or a `Last-Modified` element.
	 *
	 * @return int|null
	 *   The Unix timestamp, or NULL when the endpoint reported none this method can read.
	 */
	private function timestamp(string $value): ?int
	{
		if (trim($value) === '') {
			return null;
		}

		$parsed = strtotime($value);

		return $parsed === false ? null : $parsed;
	}

	#endregion
}
