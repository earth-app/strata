<?php

declare(strict_types=1);

namespace Drupal\strata_s3;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Drupal\strata\Storage\ByteRange;
use Drupal\strata\Storage\Capabilities;
use Drupal\strata\Storage\ObjectKeys;
use Drupal\strata\Storage\ObjectMeta;
use Drupal\strata\Storage\ObjectPage;
use Drupal\strata\Storage\PutResult;
use Drupal\strata\Storage\StorageProviderInterface;
use Drupal\strata_s3\Credentials\CredentialProviderInterface;
use Drupal\strata_s3\Credentials\Credentials;
use Generator;
use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Stores objects on AWS S3, on any S3-compatible endpoint, and on Cloudflare R2.
 *
 * One class covers all three because the differences are settings rather than dialects: the URL and
 * addressing style come from S3Endpoint, and everything the engine branches on comes from
 * Capabilities. An endpoint that will not answer a listing reports a capability set with multipart,
 * batch delete, conditional writes and checksums off, so an unknown endpoint costs more requests
 * instead of a failed flush.
 *
 * Every key sits under S3Endpoint::$keyPrefix, which the engine sets to `_strata/<site-id>/`. The
 * prefix is added on the way out and stripped on the way back, so a bucket can hold two sites and a
 * listing returns the keys the caller asked about.
 *
 * Multipart parts are uniform except the last, which R2 requires and AWS tolerates, and each part's
 * entity tag is kept exactly as the endpoint returned it. R2 rejects a CompleteMultipartUpload
 * whose part tags have had their quotes trimmed.
 *
 * The HTTP transport is injected as a callable, so the contract is exercised over a recorded
 * exchange with no network and no credentials.
 *
 * @see StorageProviderInterface
 * @see S3Endpoint
 * @see SigV4Signer
 */
final class S3StorageProvider implements StorageProviderInterface
{
	/**
	 * Largest object AWS accepts in one PutObject, in bytes.
	 */
	public const MAX_SINGLE_PUT = 5_368_709_120;

	/**
	 * Smallest multipart part except the last, in bytes.
	 */
	public const MIN_PART_SIZE = 5_242_880;

	/**
	 * Largest multipart part, in bytes.
	 */
	public const MAX_PART_SIZE = 5_368_709_120;

	/**
	 * Most parts one multipart upload may have.
	 */
	public const MAX_PARTS = 10_000;

	/**
	 * Most keys one batch delete may name.
	 */
	public const MAX_BATCH_DELETE = 1_000;

	/**
	 * Bytes read per iteration when a body arrives as a stream.
	 */
	private const COPY_CHUNK = 1_048_576;

	/**
	 * Prefix on the response headers that carry user metadata.
	 */
	private const METADATA_PREFIX = 'x-amz-meta-';

	/**
	 * The injected transport.
	 */
	private readonly Closure $transport;

	/**
	 * The key namespace this provider writes inside.
	 */
	private readonly ObjectKeys $keys;

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
	 * @param CredentialProviderInterface $credentials
	 *   Where credentials come from. Resolved per request, so a rotated session is picked up without
	 *   rebuilding the provider.
	 * @param S3Endpoint $endpoint
	 *   Which bucket, addressed how.
	 * @param callable $transport
	 *   A transport with the signature
	 *   `callable(string $method, string $url, array $headers, ?string $body): array{status: int,
	 *   body: string, headers?: array<string, string>}`. Response headers are optional but entity
	 *   tags and object metadata come from them, so a transport that drops them loses both.
	 * @param SigV4Signer $signer
	 *   The signer, whose region has to match the endpoint's.
	 *
	 * @throws InvalidArgumentException
	 *   When the signer signs for a different region than the endpoint is addressed in. That
	 *   combination produces a signature the endpoint rejects with no indication of why.
	 */
	public function __construct(
		private readonly CredentialProviderInterface $credentials,
		private readonly S3Endpoint $endpoint,
		callable $transport,
		private readonly SigV4Signer $signer,
	) {
		if ($signer->region() !== $endpoint->region) {
			throw new InvalidArgumentException(
				sprintf(
					'The signer signs for region %s and the endpoint is addressed in %s',
					$signer->region(),
					$endpoint->region,
				),
			);
		}

		$this->transport = $transport(...);
		$this->keys = new ObjectKeys($endpoint->keyPrefix);
	}

	/**
	 * The endpoint this provider writes to.
	 *
	 * @return S3Endpoint
	 *   The endpoint.
	 */
	public function endpoint(): S3Endpoint
	{
		return $this->endpoint;
	}

	#region Identity

	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return 's3';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label(): string
	{
		return $this->endpoint->isR2() ? 'Cloudflare R2' : 'S3-Compatible Storage';
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
			$this->endpoint->urlFor($this->keys->resolve($key)),
			$headers,
			null,
			SigV4Signer::EMPTY_PAYLOAD,
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
			'HEAD',
			$this->endpoint->urlFor($this->keys->resolve($key)),
			[],
			null,
			SigV4Signer::EMPTY_PAYLOAD,
		);

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
			$this->keys->normalize($key),
			(int) ($headers['content-length'] ?? 0),
			$headers['etag'] ?? null,
			$this->timestamp($headers['last-modified'] ?? ''),
			$headers['x-amz-storage-class'] ?? null,
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
		$target = $this->keys->resolve($key);
		$capabilities = $this->capabilities();
		$conditional = ($options['ifNoneMatch'] ?? false) === true;

		if ($conditional && !$capabilities->conditionalWrite) {
			throw new RuntimeException(
				sprintf('%s does not honour a conditional write', $this->endpoint->hostFor('')),
			);
		}

		if (is_string($body)) {
			$this->assertFits(strlen($body), $key);

			return $capabilities->requiresMultipart(strlen($body))
				? $this->putParts(
					$key,
					$target,
					$this->sliceString($body, $capabilities->partSizeFor(strlen($body))),
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

		$size = $this->streamSize($body);

		if ($size !== null) {
			$this->assertFits($size, $key);

			if (!$capabilities->requiresMultipart($size)) {
				return $this->putSingle(
					$key,
					$target,
					$this->readAll($body, $key),
					$options,
					$started,
				);
			}
		}

		// with no size to plan from, parts are the smallest uniform size the endpoint accepts
		$partSize = $capabilities->partSizeFor($size ?? 0);
		$first = $this->readExactly($body, $partSize, $key);

		if (strlen($first) < $partSize) {
			return $this->putSingle($key, $target, $first, $options, $started);
		}

		return $this->putParts(
			$key,
			$target,
			$this->sliceStream($body, $partSize, $first, $key),
			$options,
			$started,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * S3 answers a delete the same way whether or not the key was there, so the count is how many
	 * keys the endpoint accepted rather than how many objects went away.
	 */
	public function delete(array $keys): int
	{
		$targets = array_map(
			fn(string $key): string => $this->keys->resolve($key),
			array_values($keys),
		);

		if ($targets === []) {
			return 0;
		}

		$capabilities = $this->capabilities();
		$accepted = 0;

		foreach (array_chunk($targets, max(1, $capabilities->deleteBatchSize())) as $chunk) {
			$accepted += $capabilities->batchDelete
				? $this->deleteBatch($chunk)
				: $this->deleteOne($chunk[0]);
		}

		return $accepted;
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
			'list-type' => '2',
			'max-keys' => (string) $limit,
			'prefix' => $this->keys->scope($prefix),
		];

		if ($cursor !== null && $cursor !== '') {
			$query['continuation-token'] = $cursor;
		}
		if ($delimiter !== null && $delimiter !== '') {
			$query['delimiter'] = $delimiter;
		}

		$response = $this->request(
			'GET',
			$this->endpoint->urlFor('') . '?' . $this->queryString($query),
			[],
			null,
			SigV4Signer::EMPTY_PAYLOAD,
		);
		$this->assertSuccess($response, 'list objects');

		$document = $this->parseXml($response['body'], 'list objects');
		$objects = [];
		$prefixes = [];

		foreach ($document->Contents as $entry) {
			$key = (string) $entry->Key;

			// a zero-byte directory marker left by another tool is not an object
			if (str_ends_with($key, '/')) {
				continue;
			}

			$objects[] = new ObjectMeta(
				$this->keys->strip($key),
				(int) (string) $entry->Size,
				isset($entry->ETag) ? (string) $entry->ETag : null,
				$this->timestamp((string) $entry->LastModified),
				isset($entry->StorageClass) ? (string) $entry->StorageClass : null,
			);
		}

		foreach ($document->CommonPrefixes as $common) {
			$prefixes[] = $this->keys->strip((string) $common->Prefix);
		}

		$truncated = strtolower(trim((string) $document->IsTruncated)) === 'true';
		$next = $truncated ? trim((string) $document->NextContinuationToken) : '';

		return new ObjectPage($objects, $next === '' ? null : $next, $prefixes);
	}

	#endregion

	#region Presigning

	/**
	 * A time-limited URL for one object.
	 *
	 * @param string $key
	 *   Object key relative to the store root.
	 * @param int $expires
	 *   Lifetime in seconds, up to SigV4Signer::MAX_EXPIRES.
	 * @param string $method
	 *   HTTP method the URL is valid for.
	 *
	 * @return string
	 *   The signed URL.
	 *
	 * @throws RuntimeException
	 *   When the endpoint does not support presigning or no credentials are available.
	 */
	public function presign(string $key, int $expires = 3600, string $method = 'GET'): string
	{
		if (!$this->capabilities()->presign) {
			throw new RuntimeException(
				sprintf('%s does not support presigned urls', $this->endpoint->hostFor('')),
			);
		}

		return $this->signer->presign(
			$this->resolveCredentials(),
			$method,
			$this->endpoint->urlFor($this->keys->resolve($key)),
			$expires,
			$this->now(),
		);
	}

	#endregion

	#region Single writes

	/**
	 * Writes one object in a single request.
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
	 *   When the endpoint refuses the write.
	 */
	private function putSingle(
		string $key,
		string $target,
		string $body,
		array $options,
		float $started,
	): PutResult {
		$headers = $this->objectHeaders($options);
		$headers['Content-Length'] = (string) strlen($body);

		if ($this->capabilities()->checksums) {
			$headers['x-amz-checksum-sha256'] = base64_encode(hash('sha256', $body, true));
		}
		if (($options['ifNoneMatch'] ?? false) === true) {
			$headers['If-None-Match'] = '*';
		}

		$response = $this->request(
			'PUT',
			$this->endpoint->urlFor($target),
			$headers,
			$body,
			hash('sha256', $body),
		);

		if ($response['status'] === 412 && ($options['ifNoneMatch'] ?? false) === true) {
			throw new RuntimeException(
				sprintf(
					'Object %s already exists and the write was conditional on its absence',
					$key,
				),
			);
		}

		$this->assertSuccess($response, sprintf('write object %s', $key));

		return new PutResult(
			$this->keys->normalize($key),
			strlen($body),
			$response['headers']['etag'] ?? null,
			false,
			1,
			microtime(true) - $started,
		);
	}

	#endregion

	#region Multipart

	/**
	 * Writes one object as a multipart upload.
	 *
	 * Parts are uniform except the last. Any failure aborts the upload, so a bucket does not
	 * accumulate storage nobody is paying attention to.
	 *
	 * @param string $key
	 *   Object key as the caller named it.
	 * @param string $target
	 *   The prefixed key.
	 * @param iterable<int, string> $parts
	 *   The parts, in order.
	 * @param array<string, mixed> $options
	 *   Provider options.
	 * @param float $started
	 *   When the write began, from microtime().
	 *
	 * @return PutResult
	 *   What was written.
	 *
	 * @throws RuntimeException
	 *   When the endpoint has no multipart, a conditional write was asked for, the part count would
	 *   exceed the endpoint's ceiling, or any part fails.
	 */
	private function putParts(
		string $key,
		string $target,
		iterable $parts,
		array $options,
		float $started,
	): PutResult {
		$capabilities = $this->capabilities();

		if (!$capabilities->multipart) {
			throw new RuntimeException(
				sprintf(
					'Object %s is larger than %s takes in one request and it has no multipart upload',
					$key,
					$this->endpoint->hostFor(''),
				),
			);
		}
		if (($options['ifNoneMatch'] ?? false) === true) {
			throw new RuntimeException(
				sprintf('Object %s is too large for a conditional write', $key),
			);
		}

		$uploadId = $this->createUpload($key, $target, $options);
		$tags = [];
		$size = 0;

		try {
			foreach ($parts as $part) {
				$number = count($tags) + 1;

				if ($number > $capabilities->maxParts) {
					throw new RuntimeException(
						sprintf(
							'Object %s needs more than the %d parts %s allows',
							$key,
							$capabilities->maxParts,
							$this->endpoint->hostFor(''),
						),
					);
				}

				$tags[$number] = $this->uploadPart($key, $target, $uploadId, $number, $part);
				$size += strlen($part);
			}

			if ($tags === []) {
				throw new RuntimeException(sprintf('Object %s produced no parts to upload', $key));
			}

			$etag = $this->completeUpload($key, $target, $uploadId, $tags);
		} catch (Throwable $e) {
			$this->abortUpload($target, $uploadId);

			throw $e;
		}

		return new PutResult(
			$this->keys->normalize($key),
			$size,
			$etag,
			true,
			count($tags),
			microtime(true) - $started,
		);
	}

	/**
	 * Opens a multipart upload.
	 *
	 * @param string $key
	 *   Object key as the caller named it.
	 * @param string $target
	 *   The prefixed key.
	 * @param array<string, mixed> $options
	 *   Provider options.
	 *
	 * @return string
	 *   The upload id.
	 *
	 * @throws RuntimeException
	 *   When the endpoint refuses, or answers without an upload id.
	 */
	private function createUpload(string $key, string $target, array $options): string
	{
		$headers = $this->objectHeaders($options);

		if ($this->capabilities()->checksums) {
			$headers['x-amz-checksum-algorithm'] = 'SHA256';
		}

		$response = $this->request(
			'POST',
			$this->endpoint->urlFor($target) . '?uploads',
			$headers,
			'',
			SigV4Signer::EMPTY_PAYLOAD,
		);
		$this->assertSuccess($response, sprintf('begin a multipart upload of %s', $key));

		$uploadId = trim((string) $this->parseXml($response['body'], 'begin an upload')->UploadId);

		if ($uploadId === '') {
			throw new RuntimeException(
				sprintf('The endpoint opened an upload of %s without naming it', $key),
			);
		}

		return $uploadId;
	}

	/**
	 * Uploads one part.
	 *
	 * @param string $key
	 *   Object key as the caller named it.
	 * @param string $target
	 *   The prefixed key.
	 * @param string $uploadId
	 *   The upload id.
	 * @param int $number
	 *   Part number, counting from one.
	 * @param string $part
	 *   The bytes.
	 *
	 * @return string
	 *   The part's entity tag, exactly as the endpoint returned it. Quotes are part of the value and
	 *   R2 rejects a completion whose tags were normalised.
	 *
	 * @throws RuntimeException
	 *   When the part is refused or comes back with no entity tag.
	 */
	private function uploadPart(
		string $key,
		string $target,
		string $uploadId,
		int $number,
		string $part,
	): string {
		$headers = ['Content-Length' => (string) strlen($part)];

		if ($this->capabilities()->checksums) {
			$headers['x-amz-checksum-sha256'] = base64_encode(hash('sha256', $part, true));
		}

		$response = $this->request(
			'PUT',
			sprintf(
				'%s?partNumber=%d&uploadId=%s',
				$this->endpoint->urlFor($target),
				$number,
				rawurlencode($uploadId),
			),
			$headers,
			$part,
			hash('sha256', $part),
		);
		$this->assertSuccess($response, sprintf('upload part %d of %s', $number, $key));

		$etag = $response['headers']['etag'] ?? '';

		if ($etag === '') {
			throw new RuntimeException(
				sprintf('Part %d of %s came back with no entity tag', $number, $key),
			);
		}

		return $etag;
	}

	/**
	 * Closes a multipart upload.
	 *
	 * @param string $key
	 *   Object key as the caller named it.
	 * @param string $target
	 *   The prefixed key.
	 * @param string $uploadId
	 *   The upload id.
	 * @param array<int, string> $tags
	 *   Entity tags keyed by part number.
	 *
	 * @return string|null
	 *   The completed object's entity tag, or NULL when the endpoint did not report one.
	 *
	 * @throws RuntimeException
	 *   When the completion is refused. S3 can answer 200 with an error document, so the body is
	 *   inspected as well as the status.
	 */
	private function completeUpload(
		string $key,
		string $target,
		string $uploadId,
		array $tags,
	): ?string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUpload>';

		foreach ($tags as $number => $etag) {
			$xml .= sprintf(
				'<Part><PartNumber>%d</PartNumber><ETag>%s</ETag></Part>',
				$number,
				$this->escape($etag),
			);
		}

		$xml .= '</CompleteMultipartUpload>';

		$response = $this->request(
			'POST',
			sprintf('%s?uploadId=%s', $this->endpoint->urlFor($target), rawurlencode($uploadId)),
			['Content-Type' => 'application/xml', 'Content-Length' => (string) strlen($xml)],
			$xml,
			hash('sha256', $xml),
		);
		$this->assertSuccess($response, sprintf('complete the upload of %s', $key));

		$document = $this->parseXml($response['body'], 'complete an upload');

		if (isset($document->Code)) {
			throw new RuntimeException(
				sprintf(
					'Cannot complete the upload of %s: %s',
					$key,
					trim((string) $document->Code),
				),
			);
		}

		return isset($document->ETag) ? (string) $document->ETag : null;
	}

	/**
	 * Abandons a multipart upload.
	 *
	 * Best effort. The upload has already failed, and raising here would replace the reason with a
	 * less useful one.
	 *
	 * @param string $target
	 *   The prefixed key.
	 * @param string $uploadId
	 *   The upload id.
	 */
	private function abortUpload(string $target, string $uploadId): void
	{
		try {
			$this->request(
				'DELETE',
				sprintf(
					'%s?uploadId=%s',
					$this->endpoint->urlFor($target),
					rawurlencode($uploadId),
				),
				[],
				null,
				SigV4Signer::EMPTY_PAYLOAD,
			);
		} catch (Throwable) {
			// the caller is already raising about the part that failed
		}
	}

	#endregion

	#region Deleting

	/**
	 * Deletes one chunk of keys in a single request.
	 *
	 * @param list<string> $chunk
	 *   Prefixed keys, at most Capabilities::deleteBatchSize() of them.
	 *
	 * @return int
	 *   How many keys the endpoint accepted.
	 *
	 * @throws RuntimeException
	 *   When the request is refused, or a key fails for a reason other than being absent.
	 */
	private function deleteBatch(array $chunk): int
	{
		$xml = '<?xml version="1.0" encoding="UTF-8"?><Delete><Quiet>true</Quiet>';

		foreach ($chunk as $target) {
			$xml .= sprintf('<Object><Key>%s</Key></Object>', $this->escape($target));
		}

		$xml .= '</Delete>';

		// DeleteObjects requires an integrity header unconditionally, unlike PutObject where the
		// checksum family is optional and several S3-compatible endpoints reject it. AWS, MinIO and
		// R2 all answer 400 MissingContentMD5 without it, so it is not gated on the endpoint setting
		$headers = [
			'Content-Type' => 'application/xml',
			'Content-Length' => (string) strlen($xml),
			'Content-MD5' => base64_encode(md5($xml, true)),
		];

		$response = $this->request(
			'POST',
			$this->endpoint->urlFor('') . '?delete',
			$headers,
			$xml,
			hash('sha256', $xml),
		);
		$this->assertSuccess($response, 'delete objects');

		if (trim($response['body']) === '') {
			return count($chunk);
		}

		$absent = 0;

		foreach ($this->parseXml($response['body'], 'delete objects')->Error as $error) {
			$code = trim((string) $error->Code);

			// an absent key is not an error, so a retried prune is idempotent
			if ($code === 'NoSuchKey') {
				$absent++;

				continue;
			}

			throw new RuntimeException(
				sprintf('Cannot delete object %s: %s', trim((string) $error->Key), $code),
			);
		}

		return count($chunk) - $absent;
	}

	/**
	 * Deletes one key on an endpoint with no batch delete.
	 *
	 * @param string $target
	 *   The prefixed key.
	 *
	 * @return int
	 *   1 when the endpoint accepted it, 0 when the key was absent.
	 *
	 * @throws RuntimeException
	 *   When the endpoint refuses.
	 */
	private function deleteOne(string $target): int
	{
		$response = $this->request(
			'DELETE',
			$this->endpoint->urlFor($target),
			[],
			null,
			SigV4Signer::EMPTY_PAYLOAD,
		);

		if ($response['status'] === 404) {
			return 0;
		}

		$this->assertSuccess($response, sprintf('delete object %s', $this->keys->strip($target)));

		return 1;
	}

	#endregion

	#region Capabilities

	/**
	 * Probes the endpoint once.
	 *
	 * The probe is a listing capped at zero keys, which proves the endpoint answers ListObjectsV2
	 * and that the credentials work without transferring anything.
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
				$this->endpoint->urlFor('') . '?list-type=2&max-keys=0',
				[],
				null,
				SigV4Signer::EMPTY_PAYLOAD,
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
			'batchDelete' => false,
			'conditionalWrite' => false,
			'checksums' => false,
			'presign' => false,
		]);
	}

	/**
	 * The capability set an S3-compatible endpoint is assumed to have before it answers.
	 *
	 * @return Capabilities
	 *   AWS limits, with the checksum family gated on the endpoint setting, and with R2's two
	 *   documented differences applied from the host name rather than probed.
	 */
	private function assumed(): Capabilities
	{
		$capabilities = new Capabilities(
			multipart: true,
			batchDelete: true,
			conditionalWrite: true,
			rangeRead: true,
			presign: true,
			checksums: $this->endpoint->sendChecksums,
			storageClasses: true,
			maxSinglePut: self::MAX_SINGLE_PUT,
			minPartSize: self::MIN_PART_SIZE,
			maxPartSize: self::MAX_PART_SIZE,
			maxParts: self::MAX_PARTS,
			maxBatchDelete: self::MAX_BATCH_DELETE,
			uniformPartSize: false,
		);

		// a configured threshold lowers where multipart begins; it never raises it above what one
		// request can carry
		if ($this->endpoint->multipartThreshold !== null) {
			$capabilities = $capabilities->with([
				'maxSinglePut' => min(self::MAX_SINGLE_PUT, $this->endpoint->multipartThreshold),
			]);
		}

		if (!$this->endpoint->isR2()) {
			return $capabilities;
		}

		return $capabilities->with(['uniformPartSize' => true, 'checksums' => false]);
	}

	#endregion

	#region Requests

	/**
	 * Signs and sends one request.
	 *
	 * @param string $method
	 *   HTTP method.
	 * @param string $url
	 *   Absolute URL, including any query string.
	 * @param array<string, string> $headers
	 *   Headers to send, before signing.
	 * @param string|null $body
	 *   The request body, or NULL for none.
	 * @param string $payloadHash
	 *   Hex SHA-256 of the body, which is signed and sent as `x-amz-content-sha256`.
	 *
	 * @return array{status: int, body: string, headers: array<string, string>}
	 *   The response, with header names lowercased.
	 *
	 * @throws RuntimeException
	 *   When no credentials are available or the transport answers with something that is not a
	 *   response.
	 */
	private function request(
		string $method,
		string $url,
		array $headers,
		?string $body,
		string $payloadHash,
	): array {
		// every s3 request carries the payload hash, signed or not, on every endpoint
		$headers[SigV4Signer::CONTENT_SHA256_HEADER] = $payloadHash;

		$signed = $this->signer->signRequest(
			$this->resolveCredentials(),
			$method,
			$url,
			$headers,
			$this->now(),
			$payloadHash,
		);

		$response = ($this->transport)($method, $url, $signed, $body);

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
	 * A message describing a failed response.
	 *
	 * @param array{status: int, body: string, headers: array<string, string>} $response
	 *   The response.
	 * @param string $what
	 *   What was being attempted, as a verb phrase.
	 *
	 * @return string
	 *   The status, and the endpoint's own error code and message where it sent one.
	 */
	private function errorMessage(array $response, string $what): string
	{
		$detail = '';

		if (str_contains($response['body'], '<Error')) {
			try {
				$document = $this->parseXml($response['body'], $what);
				$code = trim((string) $document->Code);
				$message = trim((string) $document->Message);
				$detail = trim($code . ' ' . $message);
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
	 * The credentials to sign with.
	 *
	 * @return Credentials
	 *   The resolved credentials.
	 *
	 * @throws RuntimeException
	 *   When no source in the chain has any, which is a configuration gap rather than a failure and
	 *   is reported by naming the sources that were tried.
	 */
	private function resolveCredentials(): Credentials
	{
		$credentials = $this->credentials->resolve();

		if ($credentials === null) {
			throw new RuntimeException(
				sprintf('No credentials are available from %s', $this->credentials->describe()),
			);
		}

		return $credentials;
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
	 * Headers that describe the object being written.
	 *
	 * @param array<string, mixed> $options
	 *   Provider options.
	 *
	 * @return array<string, string>
	 *   Content type, storage class and user metadata, where each was asked for.
	 */
	private function objectHeaders(array $options): array
	{
		$headers = [];
		$contentType = $options['contentType'] ?? null;

		if (is_string($contentType) && trim($contentType) !== '') {
			$headers['Content-Type'] = $contentType;
		}

		$storageClass = $options['storageClass'] ?? $this->endpoint->storageClass;

		if (is_string($storageClass) && trim($storageClass) !== '') {
			$headers['x-amz-storage-class'] = $storageClass;
		}

		$metadata = $options['metadata'] ?? [];

		foreach (is_array($metadata) ? $metadata : [] as $name => $value) {
			$clean = strtolower(trim((string) $name));

			if ($clean !== '') {
				$headers[self::METADATA_PREFIX . $clean] = (string) $value;
			}
		}

		return $headers;
	}

	/**
	 * Cuts a string into uniform parts.
	 *
	 * @param string $body
	 *   The bytes.
	 * @param int $partSize
	 *   Bytes per part.
	 *
	 * @return Generator<int, string, mixed, void>
	 *   Every part the same size except the last.
	 */
	private function sliceString(string $body, int $partSize): Generator
	{
		foreach (str_split($body, $partSize) as $part) {
			yield $part;
		}
	}

	/**
	 * Cuts a stream into uniform parts.
	 *
	 * @param resource $stream
	 *   An open readable stream, positioned after $first.
	 * @param int $partSize
	 *   Bytes per part.
	 * @param string $first
	 *   The part already read in order to decide that multipart was needed.
	 * @param string $key
	 *   Object key, for error messages.
	 *
	 * @return Generator<int, string, mixed, void>
	 *   Every part the same size except the last.
	 *
	 * @throws RuntimeException
	 *   When the stream fails mid-read.
	 */
	private function sliceStream($stream, int $partSize, string $first, string $key): Generator
	{
		yield $first;

		while (strlen($first) === $partSize) {
			$first = $this->readExactly($stream, $partSize, $key);

			if ($first === '') {
				return;
			}

			yield $first;
		}
	}

	/**
	 * Reads a stream to the end.
	 *
	 * @param resource $stream
	 *   An open readable stream.
	 * @param string $key
	 *   Object key, for error messages.
	 *
	 * @return string
	 *   Everything from the current position to EOF.
	 *
	 * @throws RuntimeException
	 *   When a read fails.
	 */
	private function readAll($stream, string $key): string
	{
		$buffer = '';

		while (!feof($stream)) {
			$block = fread($stream, self::COPY_CHUNK);

			if ($block === false) {
				throw new RuntimeException(sprintf('Failed reading the body for %s', $key));
			}
			if ($block === '') {
				break;
			}

			$buffer .= $block;
		}

		return $buffer;
	}

	/**
	 * Reads a fixed number of bytes, or fewer at the end of the stream.
	 *
	 * @param resource $stream
	 *   An open readable stream.
	 * @param int $want
	 *   Bytes to read.
	 * @param string $key
	 *   Object key, for error messages.
	 *
	 * @return string
	 *   Exactly $want bytes, or fewer when the stream ended.
	 *
	 * @throws RuntimeException
	 *   When a read fails.
	 */
	private function readExactly($stream, int $want, string $key): string
	{
		$buffer = '';

		while (strlen($buffer) < $want) {
			$block = fread($stream, $want - strlen($buffer));

			if ($block === false) {
				throw new RuntimeException(sprintf('Failed reading the body for %s', $key));
			}
			if ($block === '') {
				break;
			}

			$buffer .= $block;
		}

		return $buffer;
	}

	/**
	 * How many bytes a stream has left, where it can say.
	 *
	 * @param resource $stream
	 *   An open stream.
	 *
	 * @return int|null
	 *   Bytes from the current position to the end, or NULL for a pipe or any other stream that
	 *   cannot report a size.
	 */
	private function streamSize($stream): ?int
	{
		$stat = @fstat($stream);
		$position = ftell($stream);

		if (!is_array($stat) || !isset($stat['size']) || $position === false) {
			return null;
		}

		$size = (int) $stat['size'];

		return $size <= 0 ? null : max(0, $size - $position);
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
				$this->endpoint->hostFor(''),
				$this->capabilities()->maxObjectSize(),
			),
		);
	}

	#endregion

	#region Query

	/**
	 * Renders query parameters in canonical order.
	 *
	 * The same encoding the signature is computed over, so the URL that goes on the wire is the one
	 * that was signed.
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
	 * A listing is attacker-influenced: an object key is whatever was written, and a compromised or
	 * hostile endpoint answers with whatever it likes. LIBXML_NOENT is therefore not passed. Its
	 * name reads as a refusal and it is the opposite: it turns entity substitution on, and libxml
	 * 2.9.13 then resolves a `<!ENTITY leak SYSTEM "file:///...">` in a bucket listing and puts the
	 * file's contents in the object key, LIBXML_NONET or not. Without it the reference expands to
	 * nothing. LIBXML_NONET still refuses every network fetch.
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
	 * Escapes a value for an XML text node.
	 *
	 * @param string $value
	 *   The value.
	 *
	 * @return string
	 *   The value with the three characters a text node cannot hold replaced. Quotes are left
	 *   alone, so a part's entity tag reaches the endpoint in the form every SDK sends it in.
	 */
	private function escape(string $value): string
	{
		return htmlspecialchars($value, ENT_NOQUOTES | ENT_XML1, 'UTF-8');
	}

	/**
	 * Reads an HTTP or ISO timestamp.
	 *
	 * @param string $value
	 *   A `Last-Modified` header or a `LastModified` element.
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
