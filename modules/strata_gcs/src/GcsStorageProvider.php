<?php

declare(strict_types=1);

namespace Drupal\strata_gcs;

use Closure;
use Drupal\strata\Storage\BodyReader;
use Drupal\strata\Storage\ByteRange;
use Drupal\strata\Storage\Capabilities;
use Drupal\strata\Storage\ObjectMeta;
use Drupal\strata\Storage\ObjectPage;
use Drupal\strata\Storage\PutResult;
use Drupal\strata\Storage\StorageProviderInterface;
use Drupal\strata_gcs\Credentials\AccessTokenProviderInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Stores objects on Google Cloud Storage over the JSON API.
 *
 * Native rather than through the XML interop layer, because interop needs HMAC keys and a great
 * many organisations forbid minting them. A service account or a workload identity token is what a
 * Google project already has.
 *
 * Four things differ from S3 in ways a reader has to know about:
 *
 * - **There is no batch delete.** The `/batch/storage/v1` endpoint is deprecated and Google's own
 *   clients now send one request per object. Capabilities reports `batchDelete: false` rather than
 *   pretending, so a prune costs one request per key.
 * - **A large object is a resumable session, not parts.** One session URI takes the whole body in
 *   chunks, every chunk except the last has to be a multiple of 256 KiB, and the session is
 *   finalised either by a short chunk or by an empty request naming the total.
 * - **A write is verified against what the endpoint recorded.** The response carries the object's
 *   size and its MD5, both computed by Google, and a write whose digest disagrees raises rather
 *   than being reported as a success.
 * - **Metadata cannot ride on a plain media upload.** A write that names user metadata or a storage
 *   class is sent as `multipart/related` with a JSON part, because dropping the metadata would
 *   produce an object indistinguishable from a corrupt one.
 *
 * The HTTP transport is injected as a callable, so the contract is exercised over a recorded
 * exchange with no network and no credentials.
 *
 * @see StorageProviderInterface
 * @see GcsEndpoint
 * @see AccessTokenProviderInterface
 */
final class GcsStorageProvider implements StorageProviderInterface
{
	/**
	 * Largest object this provider writes in one request, in bytes.
	 *
	 * The API takes more, but a single request holds its whole body in memory on the way out. A
	 * larger object goes through a resumable session, and a site that wants the switch earlier
	 * lowers it on the settings form.
	 */
	public const MAX_SINGLE_PUT = 268_435_456;

	/**
	 * Smallest resumable chunk except the last, in bytes.
	 */
	public const MIN_CHUNK_SIZE = 8_388_608;

	/**
	 * Largest resumable chunk, in bytes.
	 */
	public const MAX_CHUNK_SIZE = 2_147_483_648;

	/**
	 * Most chunks one session is cut into, which with the largest chunk is Google's 5 TiB ceiling.
	 */
	public const MAX_CHUNKS = 2_560;

	/**
	 * Content type an object is written with when the caller names none.
	 */
	private const DEFAULT_TYPE = 'application/octet-stream';

	/**
	 * Status a resumable session answers a chunk that is not the last one with.
	 */
	private const RESUME_INCOMPLETE = 308;

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
	 * @param AccessTokenProviderInterface $credentials
	 *   Where the bearer token comes from. Resolved per request, so a refreshed token is picked up
	 *   without rebuilding the provider.
	 * @param GcsEndpoint $endpoint
	 *   Which bucket, addressed how.
	 * @param callable $transport
	 *   A transport with the signature
	 *   `callable(string $method, string $url, array $headers, ?string $body): array{status: int,
	 *   body: string, headers?: array<string, string>}`. Response headers are optional but a
	 *   resumable session URI arrives in one, so a transport that drops them cannot upload a large
	 *   object.
	 */
	public function __construct(
		private readonly AccessTokenProviderInterface $credentials,
		private readonly GcsEndpoint $endpoint,
		callable $transport,
	) {
		$this->transport = $transport(...);
	}

	/**
	 * The endpoint this provider writes to.
	 *
	 * @return GcsEndpoint
	 *   The endpoint.
	 */
	public function endpoint(): GcsEndpoint
	{
		return $this->endpoint;
	}

	#region Identity

	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return 'gcs';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label(): string
	{
		return 'Google Cloud Storage';
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
		$headers = $range === null ? [] : ['Range' => $range->toHeader()];
		$response = $this->request(
			'GET',
			$this->endpoint->mediaUrl($this->endpoint->keys->resolve($key)),
			$headers,
			null,
		);

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
	 * The transport hands back a complete body, so the object is buffered into a temporary stream.
	 * A caller that must not hold the object in memory reads it in ranges instead.
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
		$response = $this->request(
			'GET',
			$this->endpoint->objectUrl($this->endpoint->keys->resolve($key)),
			[],
			null,
		);

		// absence is what every caller of this method is asking about, so it is not an error
		if ($response['status'] === 404) {
			return null;
		}

		$this->assertSuccess($response, sprintf('head object %s', $key));

		return $this->metaFrom($this->json($response['body'], sprintf('head object %s', $key)));
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

			if (!$capabilities->requiresMultipart(strlen($body))) {
				return $this->putSingle($key, $target, $body, $options, $started);
			}

			$chunkSize = $capabilities->partSizeFor(strlen($body));

			return $this->putResumable(
				$key,
				$target,
				BodyReader::sliceString($body, $chunkSize),
				$chunkSize,
				$options,
				$started,
			);
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

		// with no size to plan from, chunks are the smallest uniform size the endpoint accepts
		$chunkSize = $capabilities->partSizeFor($size ?? 0);
		$first = BodyReader::readExactly($body, $chunkSize, $key);

		if (strlen($first) < $chunkSize) {
			return $this->putSingle($key, $target, $first, $options, $started);
		}

		return $this->putResumable(
			$key,
			$target,
			BodyReader::sliceStream($body, $chunkSize, $first, $key),
			$chunkSize,
			$options,
			$started,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * One request per key. Google's batch endpoint is deprecated and its own clients no longer use
	 * it, so an absent key is reported as not removed rather than counted.
	 */
	public function delete(array $keys): int
	{
		$removed = 0;

		foreach (array_values($keys) as $key) {
			$response = $this->request(
				'DELETE',
				$this->endpoint->objectUrl($this->endpoint->keys->resolve($key)),
				[],
				null,
			);

			// an absent object is not an error, so a retried prune is idempotent
			if ($response['status'] === 404) {
				continue;
			}

			$this->assertSuccess($response, sprintf('delete object %s', $key));
			$removed++;
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
			'maxResults' => (string) $limit,
			'prefix' => $this->endpoint->keys->scope($prefix),
		];

		if ($cursor !== null && $cursor !== '') {
			$query['pageToken'] = $cursor;
		}
		if ($delimiter !== null && $delimiter !== '') {
			$query['delimiter'] = $delimiter;
		}

		$response = $this->request(
			'GET',
			$this->endpoint->collectionUrl() . '?' . $this->queryString($query),
			[],
			null,
		);
		$this->assertSuccess($response, 'list objects');

		$page = $this->json($response['body'], 'list objects');
		$objects = [];
		$prefixes = [];

		foreach ($this->listOf($page, 'items') as $item) {
			if (!is_array($item)) {
				continue;
			}

			$name = (string) ($item['name'] ?? '');

			// a zero-byte directory marker left by another tool is not an object
			if ($name === '' || str_ends_with($name, '/')) {
				continue;
			}

			$objects[] = $this->metaFrom($item);
		}

		foreach ($this->listOf($page, 'prefixes') as $common) {
			$prefixes[] = $this->endpoint->keys->strip((string) $common);
		}

		$next = trim((string) ($page['nextPageToken'] ?? ''));

		return new ObjectPage($objects, $next === '' ? null : $next, $prefixes);
	}

	#endregion

	#region Single writes

	/**
	 * Writes one object in a single request.
	 *
	 * A body with nothing to say about itself is a `media` upload. One that carries user metadata or
	 * a storage class is `multipart/related`, because a media upload has nowhere to put either and
	 * dropping them would write an object its reader cannot recognise.
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
	 *   When the endpoint refuses the write, the key was already there and the write was
	 *   conditional, or what the endpoint recorded disagrees with what was sent.
	 */
	private function putSingle(
		string $key,
		string $target,
		string $body,
		array $options,
		float $started,
	): PutResult {
		$resource = $this->resourceFor($target, $options);
		$type = $this->contentType($options);
		$query = ['uploadType' => count($resource) > 1 ? 'multipart' : 'media'];

		if (($options['ifNoneMatch'] ?? false) === true) {
			$query['ifGenerationMatch'] = '0';
		}
		if ($query['uploadType'] === 'media') {
			$query['name'] = $target;
			$headers = ['Content-Type' => $type];
			$payload = $body;
		} else {
			$boundary = 'strata-' . bin2hex(random_bytes(16));
			$headers = ['Content-Type' => 'multipart/related; boundary=' . $boundary];
			$payload = $this->relatedBody($boundary, $resource, $type, $body);
		}

		$headers['Content-Length'] = (string) strlen($payload);

		$response = $this->request(
			'POST',
			$this->endpoint->uploadUrl() . '?' . $this->queryString($query),
			$headers,
			$payload,
		);

		$this->assertNoConflict($response, $key, $options);
		$this->assertSuccess($response, sprintf('write object %s', $key));

		$object = $this->json($response['body'], sprintf('write object %s', $key));
		$this->verify($object, $key, strlen($body), base64_encode(md5($body, true)));

		return new PutResult(
			$this->endpoint->keys->normalize($key),
			strlen($body),
			isset($object['etag']) ? (string) $object['etag'] : null,
			false,
			1,
			microtime(true) - $started,
		);
	}

	/**
	 * A `multipart/related` body carrying an object resource and its bytes.
	 *
	 * @param string $boundary
	 *   The part boundary, which must not occur in the bytes.
	 * @param array<string, mixed> $resource
	 *   The object resource.
	 * @param string $type
	 *   Content type of the bytes.
	 * @param string $body
	 *   The bytes.
	 *
	 * @return string
	 *   The request body.
	 */
	private function relatedBody(
		string $boundary,
		array $resource,
		string $type,
		string $body,
	): string {
		return sprintf(
			"--%s\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n%s\r\n" .
				"--%s\r\nContent-Type: %s\r\n\r\n%s\r\n--%s--",
			$boundary,
			(string) json_encode($resource),
			$boundary,
			$type,
			$body,
			$boundary,
		);
	}

	#endregion

	#region Resumable

	/**
	 * Writes one object through a resumable session.
	 *
	 * @param string $key
	 *   Object key as the caller named it.
	 * @param string $target
	 *   The prefixed key.
	 * @param iterable<int, string> $chunks
	 *   The chunks, in order.
	 * @param int $chunkSize
	 *   Bytes per chunk, which every chunk but the last is expected to be.
	 * @param array<string, mixed> $options
	 *   Provider options.
	 * @param float $started
	 *   When the write began, from microtime().
	 *
	 * @return PutResult
	 *   What was written.
	 *
	 * @throws RuntimeException
	 *   When the endpoint has no resumable upload, a chunk is misaligned, or any chunk fails.
	 */
	private function putResumable(
		string $key,
		string $target,
		iterable $chunks,
		int $chunkSize,
		array $options,
		float $started,
	): PutResult {
		$capabilities = $this->capabilities();

		if (!$capabilities->multipart) {
			throw new RuntimeException(
				sprintf(
					'Object %s is larger than %s takes in one request and it has no resumable upload',
					$key,
					$this->endpoint->host(),
				),
			);
		}

		$session = $this->startSession($key, $target, $options);
		$digest = hash_init('md5');
		$offset = 0;
		$parts = 0;
		$object = null;

		try {
			foreach ($chunks as $chunk) {
				$length = strlen($chunk);
				$last = $length < $chunkSize;
				$parts++;

				if ($parts > $capabilities->maxParts) {
					throw new RuntimeException(
						sprintf(
							'Object %s needs more than the %d chunks %s allows',
							$key,
							$capabilities->maxParts,
							$this->endpoint->host(),
						),
					);
				}

				$this->assertAligned($length, $last, $key);
				hash_update($digest, $chunk);

				$object = $this->uploadChunk($session, $key, $chunk, $offset, $last);
				$offset += $length;

				if ($object !== null) {
					break;
				}
			}

			if ($parts === 0) {
				throw new RuntimeException(sprintf('Object %s produced no chunks to upload', $key));
			}

			$object ??= $this->finishSession($session, $key, $offset);
		} catch (Throwable $e) {
			$this->cancelSession($session);

			throw $e;
		}

		$this->verify($object, $key, $offset, base64_encode(hash_final($digest, true)));

		return new PutResult(
			$this->endpoint->keys->normalize($key),
			$offset,
			isset($object['etag']) ? (string) $object['etag'] : null,
			true,
			$parts,
			microtime(true) - $started,
		);
	}

	/**
	 * Opens a resumable session.
	 *
	 * @param string $key
	 *   Object key as the caller named it.
	 * @param string $target
	 *   The prefixed key.
	 * @param array<string, mixed> $options
	 *   Provider options.
	 *
	 * @return string
	 *   The session URI, which is where every chunk goes.
	 *
	 * @throws RuntimeException
	 *   When the endpoint refuses, or answers without a session URI.
	 */
	private function startSession(string $key, string $target, array $options): string
	{
		$query = ['uploadType' => 'resumable'];

		if (($options['ifNoneMatch'] ?? false) === true) {
			$query['ifGenerationMatch'] = '0';
		}

		$resource = (string) json_encode($this->resourceFor($target, $options));
		$response = $this->request(
			'POST',
			$this->endpoint->uploadUrl() . '?' . $this->queryString($query),
			[
				'Content-Type' => 'application/json; charset=UTF-8',
				'Content-Length' => (string) strlen($resource),
				'X-Upload-Content-Type' => $this->contentType($options),
			],
			$resource,
		);

		$this->assertNoConflict($response, $key, $options);
		$this->assertSuccess($response, sprintf('begin a resumable upload of %s', $key));

		$session = trim($response['headers']['location'] ?? '');

		if ($session === '') {
			throw new RuntimeException(
				sprintf('The endpoint opened a session for %s without naming it', $key),
			);
		}

		return $session;
	}

	/**
	 * Sends one chunk into a session.
	 *
	 * @param string $session
	 *   The session URI.
	 * @param string $key
	 *   Object key as the caller named it.
	 * @param string $chunk
	 *   The bytes.
	 * @param int $offset
	 *   Where the chunk starts in the object.
	 * @param bool $last
	 *   Whether this chunk ends the object, which is the only point at which the total is known.
	 *
	 * @return array<mixed>|null
	 *   The finished object resource, or NULL when the session wants more.
	 *
	 * @throws RuntimeException
	 *   When the chunk is refused.
	 */
	private function uploadChunk(
		string $session,
		string $key,
		string $chunk,
		int $offset,
		bool $last,
	): ?array {
		$length = strlen($chunk);
		$range = $last
			? sprintf('bytes %d-%d/%d', $offset, $offset + $length - 1, $offset + $length)
			: sprintf('bytes %d-%d/*', $offset, $offset + $length - 1);

		$response = $this->request(
			'PUT',
			$session,
			['Content-Length' => (string) $length, 'Content-Range' => $range],
			$chunk,
		);

		if ($response['status'] === self::RESUME_INCOMPLETE) {
			return null;
		}

		$this->assertSuccess(
			$response,
			sprintf('upload the chunk at offset %d of %s', $offset, $key),
		);

		return $this->json($response['body'], sprintf('upload a chunk of %s', $key));
	}

	/**
	 * Finalises a session whose body ended exactly on a chunk boundary.
	 *
	 * @param string $session
	 *   The session URI.
	 * @param string $key
	 *   Object key as the caller named it.
	 * @param int $total
	 *   How many bytes were sent.
	 *
	 * @return array<mixed>
	 *   The finished object resource.
	 *
	 * @throws RuntimeException
	 *   When the endpoint refuses to finalise.
	 */
	private function finishSession(string $session, string $key, int $total): array
	{
		$response = $this->request(
			'PUT',
			$session,
			['Content-Length' => '0', 'Content-Range' => sprintf('bytes */%d', $total)],
			'',
		);
		$this->assertSuccess($response, sprintf('finish the upload of %s', $key));

		return $this->json($response['body'], sprintf('finish the upload of %s', $key));
	}

	/**
	 * Abandons a session.
	 *
	 * Best effort. The upload has already failed, and raising here would replace the reason with a
	 * less useful one.
	 *
	 * @param string $session
	 *   The session URI.
	 */
	private function cancelSession(string $session): void
	{
		try {
			$this->request('DELETE', $session, ['Content-Length' => '0'], null);
		} catch (Throwable) {
			// the caller is already raising about the chunk that failed
		}
	}

	/**
	 * Guards a chunk against the alignment a session requires.
	 *
	 * @param int $length
	 *   The chunk's size.
	 * @param bool $last
	 *   Whether this chunk ends the object, which is the only one allowed to be any size.
	 * @param string $key
	 *   Object key, for the message.
	 *
	 * @throws RuntimeException
	 *   When a chunk that is not the last is not a multiple of 256 KiB. The endpoint answers such a
	 *   chunk with a 400 naming nothing useful, so it is caught here instead.
	 */
	private function assertAligned(int $length, bool $last, string $key): void
	{
		if ($last || $length % GcsEndpoint::CHUNK_ALIGNMENT === 0) {
			return;
		}

		throw new RuntimeException(
			sprintf(
				'Chunk of %d bytes for object %s is not a multiple of %d',
				$length,
				$key,
				GcsEndpoint::CHUNK_ALIGNMENT,
			),
		);
	}

	#endregion

	#region Capabilities

	/**
	 * Probes the endpoint once.
	 *
	 * The probe is a listing capped at one object, which proves the bucket answers and that the
	 * token works without transferring anything.
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
				$this->endpoint->collectionUrl() . '?maxResults=1',
				[],
				null,
			);

			if ($response['status'] >= 200 && $response['status'] < 300) {
				$this->unreachable = null;

				return $assumed;
			}

			$this->unreachable = $this->errorMessage($response, 'list the bucket');
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
	 * The capability set the JSON API is assumed to have before it answers.
	 *
	 * @return Capabilities
	 *   Google's documented limits. Batch delete is off because the endpoint is deprecated, and
	 *   presigning is off because a V4 signed URL is minted from the private key rather than from
	 *   the bearer token this provider authenticates with.
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
			minPartSize: self::MIN_CHUNK_SIZE,
			maxPartSize: self::MAX_CHUNK_SIZE,
			maxParts: self::MAX_CHUNKS,
			maxBatchDelete: 1,
			uniformPartSize: false,
		);

		// a configured threshold lowers where a session begins; it never raises it above one request
		if ($this->endpoint->resumableThreshold !== null) {
			return $capabilities->with([
				'maxSinglePut' => min(self::MAX_SINGLE_PUT, $this->endpoint->resumableThreshold),
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
	 *   When no token is available or the transport answers with something that is not a response.
	 */
	private function request(string $method, string $url, array $headers, ?string $body): array
	{
		$headers['Authorization'] = 'Bearer ' . $this->credentials->token();

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
	 * @param array{status: int, body: string, headers: array<string, string>} $response
	 *   The response.
	 * @param string $key
	 *   Object key as the caller named it.
	 * @param array<string, mixed> $options
	 *   Provider options.
	 *
	 * @throws RuntimeException
	 *   When the write was conditional on absence and the object was there.
	 */
	private function assertNoConflict(array $response, string $key, array $options): void
	{
		if (($options['ifNoneMatch'] ?? false) !== true || $response['status'] !== 412) {
			return;
		}

		throw new RuntimeException(
			sprintf('Object %s already exists and the write was conditional on its absence', $key),
		);
	}

	/**
	 * Raises when what the endpoint recorded is not what was sent.
	 *
	 * The one check that catches a truncated upload. A resumable session that lost a chunk still
	 * answers 200, and the size it reports is the only thing that says so. The digest is compared
	 * where the endpoint sent one; a composite object carries no whole-object MD5, so its absence is
	 * not read as a mismatch.
	 *
	 * @param array<mixed> $object
	 *   The object resource the endpoint answered with.
	 * @param string $key
	 *   Object key as the caller named it.
	 * @param int $size
	 *   How many bytes were sent.
	 * @param string $digest
	 *   Base64 MD5 of what was sent.
	 *
	 * @throws RuntimeException
	 *   When the recorded size or digest disagrees.
	 */
	private function verify(array $object, string $key, int $size, string $digest): void
	{
		$recorded = (int) ($object['size'] ?? -1);

		if ($recorded !== $size) {
			throw new RuntimeException(
				sprintf(
					'Object %s was written as %d bytes and the endpoint recorded %d',
					$key,
					$size,
					$recorded,
				),
			);
		}

		$reported = trim((string) ($object['md5Hash'] ?? ''));

		if ($reported !== '' && $reported !== $digest) {
			throw new RuntimeException(
				sprintf('Object %s was stored with a digest that is not the one sent', $key),
			);
		}
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
	 *   The status, and the endpoint's own message where it sent one.
	 */
	private function errorMessage(array $response, string $what): string
	{
		$parsed = json_decode($response['body'], true);
		$error = is_array($parsed) ? $parsed['error'] ?? null : null;
		$detail = '';

		if (is_array($error)) {
			$detail = trim((string) ($error['message'] ?? ''));
		} elseif (is_string($error)) {
			$detail = trim($error);
		}

		return sprintf(
			'Cannot %s: the endpoint answered %d%s',
			$what,
			$response['status'],
			$detail === '' ? '' : ' (' . $detail . ')',
		);
	}

	#endregion

	#region Resources

	/**
	 * The object resource a write declares.
	 *
	 * @param string $target
	 *   The prefixed key.
	 * @param array<string, mixed> $options
	 *   Provider options.
	 *
	 * @return array<string, mixed>
	 *   The name, and the storage class and user metadata where either was asked for.
	 */
	private function resourceFor(string $target, array $options): array
	{
		$resource = ['name' => $target];
		$class = $options['storageClass'] ?? $this->endpoint->storageClass;

		if (is_string($class) && trim($class) !== '') {
			$resource['storageClass'] = $class;
		}

		$metadata = [];

		foreach ((array) ($options['metadata'] ?? []) as $name => $value) {
			$clean = trim((string) $name);

			if ($clean !== '') {
				$metadata[$clean] = (string) $value;
			}
		}

		if ($metadata !== []) {
			$resource['metadata'] = $metadata;
		}

		return $resource;
	}

	/**
	 * The content type a write declares.
	 *
	 * @param array<string, mixed> $options
	 *   Provider options.
	 *
	 * @return string
	 *   The requested type, or the default for opaque bytes.
	 */
	private function contentType(array $options): string
	{
		$type = $options['contentType'] ?? null;

		return is_string($type) && trim($type) !== '' ? $type : self::DEFAULT_TYPE;
	}

	/**
	 * Turns one object resource into metadata.
	 *
	 * @param array<mixed> $object
	 *   The object resource.
	 *
	 * @return ObjectMeta
	 *   The metadata, with the store prefix removed from the key.
	 */
	private function metaFrom(array $object): ObjectMeta
	{
		$metadata = [];

		foreach ((array) ($object['metadata'] ?? []) as $name => $value) {
			$metadata[(string) $name] = (string) $value;
		}

		return new ObjectMeta(
			$this->endpoint->keys->strip((string) ($object['name'] ?? '')),
			(int) ($object['size'] ?? 0),
			isset($object['etag']) ? (string) $object['etag'] : null,
			$this->timestamp((string) ($object['updated'] ?? '')),
			isset($object['storageClass']) ? (string) $object['storageClass'] : null,
			$metadata,
		);
	}

	/**
	 * Guards an object against the endpoint's ceiling.
	 *
	 * @param int $size
	 *   Object size in bytes.
	 * @param string $key
	 *   Object key, for the message.
	 *
	 * @throws RuntimeException
	 *   When the object is larger than the endpoint can hold at all.
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

	#region Json

	/**
	 * Parses a response body as a JSON object.
	 *
	 * @param string $body
	 *   The response body.
	 * @param string $what
	 *   What was being attempted, for the message.
	 *
	 * @return array<mixed>
	 *   The parsed object.
	 *
	 * @throws RuntimeException
	 *   When the body is not a JSON object.
	 */
	private function json(string $body, string $what): array
	{
		$parsed = json_decode($body, true);

		if (!is_array($parsed)) {
			throw new RuntimeException(
				sprintf('Cannot %s: the endpoint answered with a body that is not json', $what),
			);
		}

		return $parsed;
	}

	/**
	 * One list-valued member of a parsed response.
	 *
	 * @param array<mixed> $parsed
	 *   The parsed response.
	 * @param string $name
	 *   The member name.
	 *
	 * @return array<int, mixed>
	 *   The member, or an empty list when it is absent or not a list.
	 */
	private function listOf(array $parsed, string $name): array
	{
		$value = $parsed[$name] ?? null;

		return is_array($value) ? array_values($value) : [];
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

	/**
	 * Reads an RFC 3339 timestamp.
	 *
	 * @param string $value
	 *   An `updated` field.
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
