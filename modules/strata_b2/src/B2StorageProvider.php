<?php

declare(strict_types=1);

namespace Drupal\strata_b2;

use Closure;
use Drupal\strata\Storage\BodyReader;
use Drupal\strata\Storage\ByteRange;
use Drupal\strata\Storage\Capabilities;
use Drupal\strata\Storage\ObjectMeta;
use Drupal\strata\Storage\ObjectPage;
use Drupal\strata\Storage\PutResult;
use Drupal\strata\Storage\StorageProviderInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Stores objects on Backblaze B2 over the native API.
 *
 * B2 has an S3-compatible gateway and this module does not use it, for one reason: the gateway
 * cannot authenticate an application key restricted to a bucket and a name prefix, which is the
 * whole point of an application key. The native API can.
 *
 * Four things differ from S3 in ways a reader has to know about:
 *
 * - **Every upload creates a version, and a delete removes one version.** `b2_delete_file_version`
 *   names a file id, not a name, so a delete has to find the current version first. Writing the same
 *   key twice leaves two versions and deleting once leaves the older one in place, which is what a
 *   bucket lifecycle rule is for and not something this provider papers over.
 * - **An upload URL is single-use on failure.** B2 hands out a URL bound to one node; when a write
 *   to it fails the URL is dead, so the provider drops it, asks for another and retries once.
 * - **SHA-1 is mandatory, not optional.** Every upload declares its hash and B2 refuses the write if
 *   the bytes disagree, so `checksums` is reported true and the digest is checked again on the way
 *   back.
 * - **The part size floor comes from the account.** `b2_authorize_account` reports
 *   `absoluteMinimumPartSize`, and it is what Capabilities carries rather than a constant that would
 *   be wrong on an account B2 configured differently.
 *
 * There is no batch delete and no conditional write. Both are reported false rather than emulated,
 * because emulating a conditional write with a head and a put is a race, not a guarantee.
 *
 * The HTTP transport is injected as a callable, so the contract is exercised over a recorded
 * exchange with no network and no credentials.
 *
 * @see StorageProviderInterface
 * @see B2Account
 * @see B2Endpoint
 */
final class B2StorageProvider implements StorageProviderInterface
{
	/**
	 * Largest file B2 accepts in one `b2_upload_file`, in bytes.
	 */
	public const MAX_SINGLE_PUT = 5_000_000_000;

	/**
	 * Largest part, in bytes.
	 */
	public const MAX_PART_SIZE = 5_000_000_000;

	/**
	 * Most parts one large file may have.
	 */
	public const MAX_PARTS = 10_000;

	/**
	 * Content type that asks B2 to work it out from the name.
	 */
	private const DEFAULT_TYPE = 'b2/x-auto';

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
	 * The held upload URL and its token, or NULL when one has to be asked for.
	 *
	 * @var array{url: string, token: string}|null
	 */
	private ?array $slot = null;

	/**
	 * Constructs a provider.
	 *
	 * @param B2Account $account
	 *   The key, and the authorization it exchanges for.
	 * @param B2Endpoint $endpoint
	 *   Which bucket, named both ways.
	 * @param callable $transport
	 *   A transport with the signature
	 *   `callable(string $method, string $url, array $headers, ?string $body): array{status: int,
	 *   body: string, headers?: array<string, string>}`. Response headers are optional but a head
	 *   reads everything it knows out of them, so a transport that drops them cannot head.
	 */
	public function __construct(
		private readonly B2Account $account,
		private readonly B2Endpoint $endpoint,
		callable $transport,
	) {
		$this->transport = $transport(...);
	}

	/**
	 * The endpoint this provider writes to.
	 *
	 * @return B2Endpoint
	 *   The endpoint.
	 */
	public function endpoint(): B2Endpoint
	{
		return $this->endpoint;
	}

	#region Identity

	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return 'b2';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label(): string
	{
		return 'Backblaze B2';
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
		$response = $this->download('GET', $key, $headers);

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
	 * The transport hands back a complete body, so the file is buffered into a temporary stream. A
	 * caller that must not hold the file in memory reads it in ranges instead.
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
		return $this->version($key)?->toMeta($this->endpoint->keys);
	}

	/**
	 * {@inheritdoc}
	 */
	public function exists(string $key): bool
	{
		return $this->version($key) !== null;
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

		if (($options['ifNoneMatch'] ?? false) === true) {
			throw new RuntimeException(
				sprintf('%s does not honour a conditional write', $this->account->describe()),
			);
		}

		$class = $options['storageClass'] ?? null;

		// b2 has one tier, so a write that names another cannot be honoured and is not dropped
		if (is_string($class) && trim($class) !== '') {
			throw new InvalidArgumentException(
				sprintf('B2 has no storage class to write object %s to', $key),
			);
		}

		if (is_string($body)) {
			$this->assertFits(strlen($body), $key);

			return $capabilities->requiresMultipart(strlen($body))
				? $this->putParts(
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

		// with no size to plan from, parts are the smallest uniform size the account accepts
		$partSize = $capabilities->partSizeFor($size ?? 0);
		$first = BodyReader::readExactly($body, $partSize, $key);

		if (strlen($first) < $partSize) {
			return $this->putSingle($key, $target, $first, $options, $started);
		}

		return $this->putParts(
			$key,
			$target,
			BodyReader::sliceStream($body, $partSize, $first, $key),
			$options,
			$started,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Two requests per key, because a delete names a file id and only a lookup knows it. A key that
	 * was not there is reported as not removed, which B2 can say and S3 cannot.
	 */
	public function delete(array $keys): int
	{
		$removed = 0;

		foreach (array_values($keys) as $key) {
			$version = $this->version($key);

			// an absent file is not an error, so a retried prune is idempotent
			if ($version === null) {
				continue;
			}

			$this->call('b2_delete_file_version', [
				'fileName' => $version->name,
				'fileId' => $version->id,
			]);
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

		$payload = [
			'bucketId' => $this->endpoint->bucketId,
			'maxFileCount' => $limit,
			'prefix' => $this->endpoint->keys->scope($prefix),
		];

		if ($cursor !== null && $cursor !== '') {
			$payload['startFileName'] = $cursor;
		}
		if ($delimiter !== null && $delimiter !== '') {
			$payload['delimiter'] = $delimiter;
		}

		$page = $this->call('b2_list_file_names', $payload);
		$objects = [];
		$prefixes = [];
		$files = $page['files'] ?? [];

		foreach (is_array($files) ? $files : [] as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$name = (string) ($entry['fileName'] ?? '');

			// a delimited listing reports a folder as an entry with no bytes behind it
			if ((string) ($entry['action'] ?? '') === 'folder') {
				$prefixes[] = $this->endpoint->keys->strip($name);

				continue;
			}

			// a zero-byte directory marker left by another tool is not an object
			if ($name === '' || str_ends_with($name, '/')) {
				continue;
			}

			$objects[] = B2File::fromListing($entry)->toMeta($this->endpoint->keys);
		}

		$next = trim((string) ($page['nextFileName'] ?? ''));

		return new ObjectPage($objects, $next === '' ? null : $next, $prefixes);
	}

	#endregion

	#region Single writes

	/**
	 * Writes one file in a single request.
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
	 *   When the upload fails twice, or B2 records something other than what was sent.
	 */
	private function putSingle(
		string $key,
		string $target,
		string $body,
		array $options,
		float $started,
	): PutResult {
		$sha1 = sha1($body);
		$headers = $this->infoHeaders($options);
		$headers['X-Bz-File-Name'] = $this->encodeName($target);
		$headers['Content-Type'] = $this->contentType($options);
		$headers['Content-Length'] = (string) strlen($body);
		$headers['X-Bz-Content-Sha1'] = $sha1;

		$file = $this->upload(
			fn(bool $fresh): array => $this->slot($fresh),
			$headers,
			$body,
			sprintf('write object %s', $key),
		);
		$this->verify($file, $key, strlen($body), $sha1);

		return new PutResult(
			$this->endpoint->keys->normalize($key),
			strlen($body),
			$sha1,
			false,
			1,
			microtime(true) - $started,
		);
	}

	#endregion

	#region Large files

	/**
	 * Writes one file in parts.
	 *
	 * Any failure cancels the large file, so an account does not accumulate parts nobody is paying
	 * attention to.
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
	 *   What was written. Its entity tag is the file id, because a file assembled from parts has no
	 *   whole-file hash and the id is the only thing B2 reports that identifies it.
	 *
	 * @throws RuntimeException
	 *   When the part count would exceed the ceiling, or any part fails.
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
					'Object %s is larger than one request carries and %s has no large file upload',
					$key,
					$this->account->describe(),
				),
			);
		}

		$payload = ['bucketId' => $this->endpoint->bucketId, 'fileName' => $target];
		$payload['contentType'] = $this->contentType($options);
		$info = [];

		foreach ($this->fileInfo($options) as $name => $value) {
			$info[$name] = $value;
		}
		if ($info !== []) {
			$payload['fileInfo'] = $info;
		}

		$fileId = trim((string) ($this->call('b2_start_large_file', $payload)['fileId'] ?? ''));

		if ($fileId === '') {
			throw new RuntimeException(
				sprintf('The endpoint opened a large file for %s without naming it', $key),
			);
		}

		$hashes = [];
		$size = 0;
		$slot = null;

		// one part url serves every part until a write to it fails, which is what B2 recommends
		$fetch = function (bool $fresh) use ($fileId, &$slot): array {
			if ($fresh || !is_array($slot)) {
				$answer = $this->call('b2_get_upload_part_url', ['fileId' => $fileId]);
				$slot = [
					'url' => (string) ($answer['uploadUrl'] ?? ''),
					'token' => (string) ($answer['authorizationToken'] ?? ''),
				];
			}

			return $slot;
		};

		try {
			foreach ($parts as $part) {
				$number = count($hashes) + 1;

				if ($number > $capabilities->maxParts) {
					throw new RuntimeException(
						sprintf(
							'Object %s needs more than the %d parts b2 allows',
							$key,
							$capabilities->maxParts,
						),
					);
				}

				$hashes[] = $this->uploadPart($key, $fetch, $number, $part);
				$size += strlen($part);
			}

			if ($hashes === []) {
				throw new RuntimeException(sprintf('Object %s produced no parts to upload', $key));
			}

			$file = $this->call('b2_finish_large_file', [
				'fileId' => $fileId,
				'partSha1Array' => $hashes,
			]);
		} catch (Throwable $e) {
			$this->cancel($fileId);

			throw $e;
		}

		$this->verify($file, $key, $size, null);

		return new PutResult(
			$this->endpoint->keys->normalize($key),
			$size,
			$fileId,
			true,
			count($hashes),
			microtime(true) - $started,
		);
	}

	/**
	 * Uploads one part.
	 *
	 * @param string $key
	 *   Object key as the caller named it.
	 * @param callable(bool): array{url: string, token: string} $slot
	 *   Produces an upload URL for the large file, a fresh one when asked.
	 * @param int $number
	 *   Part number, counting from one.
	 * @param string $part
	 *   The bytes.
	 *
	 * @return string
	 *   The part's SHA-1, which the finish call sends back in order.
	 *
	 * @throws RuntimeException
	 *   When the part fails twice, or B2 records a different hash than was declared.
	 */
	private function uploadPart(string $key, callable $slot, int $number, string $part): string
	{
		$sha1 = sha1($part);
		$file = $this->upload(
			$slot,
			[
				'X-Bz-Part-Number' => (string) $number,
				'Content-Length' => (string) strlen($part),
				'X-Bz-Content-Sha1' => $sha1,
			],
			$part,
			sprintf('upload part %d of %s', $number, $key),
		);

		$reported = strtolower(trim((string) ($file['contentSha1'] ?? '')));

		if ($reported !== '' && $reported !== $sha1) {
			throw new RuntimeException(
				sprintf(
					'Part %d of %s was stored with a hash that is not the one sent',
					$number,
					$key,
				),
			);
		}

		return $sha1;
	}

	/**
	 * Abandons a large file.
	 *
	 * Best effort. The upload has already failed, and raising here would replace the reason with a
	 * less useful one.
	 *
	 * @param string $fileId
	 *   The large file's id.
	 */
	private function cancel(string $fileId): void
	{
		try {
			$this->call('b2_cancel_large_file', ['fileId' => $fileId]);
		} catch (Throwable) {
			// the caller is already raising about the part that failed
		}
	}

	#endregion

	#region Uploads

	/**
	 * Sends a body to an upload URL, asking for another one and retrying once if it fails.
	 *
	 * An upload URL is bound to one node and is dead as soon as a write to it fails, so a retry to
	 * the same URL fails too. This is the only retry in the provider and it happens exactly once.
	 *
	 * @param callable(bool): array{url: string, token: string} $slot
	 *   Produces an upload URL and its token, a fresh one when it is passed TRUE.
	 * @param array<string, string> $headers
	 *   Headers to send, without the authorization.
	 * @param string $body
	 *   The bytes.
	 * @param string $what
	 *   What is being attempted, as a verb phrase for the message.
	 *
	 * @return array<mixed>
	 *   What B2 recorded.
	 *
	 * @throws RuntimeException
	 *   When the second attempt fails too.
	 */
	private function upload(callable $slot, array $headers, string $body, string $what): array
	{
		$response = ['status' => 0, 'body' => '', 'headers' => []];

		for ($attempt = 0; $attempt < 2; $attempt++) {
			// the url is single-use once it has failed, so a retry demands another one
			$target = $slot($attempt > 0);
			$headers['Authorization'] = $target['token'];
			$response = $this->send('POST', $target['url'], $headers, $body);

			if (!$this->isRetryable($response['status'])) {
				break;
			}
		}

		$this->assertSuccess($response, $what);

		return $this->json($response['body'], $what);
	}

	/**
	 * The held upload URL, asking for one when there is none.
	 *
	 * @param bool $fresh
	 *   Whether the held one has just failed and another is needed.
	 *
	 * @return array{url: string, token: string}
	 *   The URL and the token that authorizes a write to it.
	 *
	 * @throws RuntimeException
	 *   When B2 refuses to hand one out, or answers without a URL.
	 */
	private function slot(bool $fresh = false): array
	{
		if (!$fresh && $this->slot !== null) {
			return $this->slot;
		}

		$answer = $this->call('b2_get_upload_url', ['bucketId' => $this->endpoint->bucketId]);
		$url = trim((string) ($answer['uploadUrl'] ?? ''));

		if ($url === '') {
			throw new RuntimeException('b2_get_upload_url answered without a url');
		}

		return $this->slot = [
			'url' => $url,
			'token' => (string) ($answer['authorizationToken'] ?? ''),
		];
	}

	/**
	 * Whether a failed upload is worth one more attempt at a fresh URL.
	 *
	 * @param int $status
	 *   The status the upload answered with. Zero is a transport failure.
	 *
	 * @return bool
	 *   TRUE for the statuses B2 documents as meaning "get another upload url".
	 */
	private function isRetryable(int $status): bool
	{
		return $status === 0 ||
			$status === 401 ||
			$status === 408 ||
			$status === 429 ||
			$status >= 500;
	}

	#endregion

	#region Lookups

	/**
	 * The current version of one file.
	 *
	 * A HEAD on the download URL is one request and reports the file id, which a listing lookup also
	 * does but at the cost of a POST that a read-only key may not be allowed to make. An endpoint
	 * that refuses the HEAD falls back to the listing.
	 *
	 * @param string $key
	 *   Object key relative to the store root.
	 *
	 * @return B2File|null
	 *   The version, or NULL when the name has none.
	 *
	 * @throws RuntimeException
	 *   When B2 refuses the lookup for any reason other than absence.
	 */
	private function version(string $key): ?B2File
	{
		$target = $this->endpoint->keys->resolve($key);
		$response = $this->download('HEAD', $key, []);

		if ($response['status'] === 404) {
			return null;
		}
		if ($response['status'] === 405 || $response['status'] === 501) {
			return $this->versionByListing($target);
		}

		$this->assertSuccess($response, sprintf('head object %s', $key));

		return B2File::fromHeaders($response['headers'], $target);
	}

	/**
	 * The current version of one file, found by listing exactly one name.
	 *
	 * @param string $target
	 *   The prefixed key.
	 *
	 * @return B2File|null
	 *   The version, or NULL when the name has none.
	 *
	 * @throws RuntimeException
	 *   When the listing is refused.
	 */
	private function versionByListing(string $target): ?B2File
	{
		$page = $this->call('b2_list_file_names', [
			'bucketId' => $this->endpoint->bucketId,
			'startFileName' => $target,
			'prefix' => $target,
			'maxFileCount' => 1,
		]);
		$files = $page['files'] ?? [];
		$first = is_array($files) ? array_values($files)[0] ?? null : null;

		if (!is_array($first) || (string) ($first['fileName'] ?? '') !== $target) {
			return null;
		}

		return B2File::fromListing($first);
	}

	#endregion

	#region Capabilities

	/**
	 * Probes the account once.
	 *
	 * The probe is the authorization itself, which is the one call every other call needs anyway and
	 * which reports the part sizes the capability set is built from.
	 *
	 * @return Capabilities
	 *   The account's own limits when it authorized, or a conservative set when it did not. A key
	 *   that authorized but is not allowed to write, or is confined to another bucket, is reported
	 *   as unreachable now rather than as a refused upload at the first flush.
	 */
	private function probe(): Capabilities
	{
		try {
			$authorization = $this->account->authorization();

			if (!$authorization->allows('writeFiles')) {
				$this->unreachable = sprintf(
					'%s is not allowed to write files',
					$this->account->describe(),
				);

				return $this->conservative($authorization->minimumPartSize);
			}
			if (
				$authorization->isRestricted() &&
				$authorization->bucketId !== $this->endpoint->bucketId
			) {
				$this->unreachable = sprintf(
					'%s is restricted to bucket %s',
					$this->account->describe(),
					$authorization->bucketId,
				);

				return $this->conservative($authorization->minimumPartSize);
			}

			$this->unreachable = null;

			return $this->capable($authorization->minimumPartSize);
		} catch (Throwable $e) {
			$this->unreachable = $e->getMessage();
		}

		return $this->conservative(B2Authorization::FALLBACK_MINIMUM_PART);
	}

	/**
	 * What an authorized key can do.
	 *
	 * @param int $minPartSize
	 *   The floor `b2_authorize_account` reported, which differs between accounts.
	 *
	 * @return Capabilities
	 *   B2's limits. Batch delete and conditional writes are off because B2 has neither, and
	 *   presigning is off because a download authorization is a different call this provider does
	 *   not make.
	 */
	private function capable(int $minPartSize): Capabilities
	{
		return new Capabilities(
			multipart: true,
			batchDelete: false,
			conditionalWrite: false,
			rangeRead: true,
			presign: false,
			checksums: true,
			storageClasses: false,
			maxSinglePut: $this->singlePutCeiling(),
			minPartSize: $minPartSize,
			maxPartSize: self::MAX_PART_SIZE,
			maxParts: self::MAX_PARTS,
			maxBatchDelete: 1,
			uniformPartSize: false,
		);
	}

	/**
	 * What is claimed for an account that would not authorize or would not be allowed to write.
	 *
	 * @param int $minPartSize
	 *   The floor to report.
	 *
	 * @return Capabilities
	 *   The same set with nothing optional claimed.
	 */
	private function conservative(int $minPartSize): Capabilities
	{
		return $this->capable($minPartSize)->with(['multipart' => false, 'checksums' => false]);
	}

	/**
	 * Where a single upload stops and a large file begins.
	 *
	 * @return int
	 *   The configured threshold, or what one request carries. A threshold can only lower it.
	 */
	private function singlePutCeiling(): int
	{
		$threshold = $this->endpoint->largeFileThreshold;

		return $threshold === null ? self::MAX_SINGLE_PUT : min(self::MAX_SINGLE_PUT, $threshold);
	}

	#endregion

	#region Requests

	/**
	 * Makes one API call.
	 *
	 * A 401 means the token expired, so the account re-authorizes and the call is made once more.
	 * A second 401 is a credential problem and is reported.
	 *
	 * @param string $name
	 *   The call, such as `b2_get_upload_url`.
	 * @param array<string, mixed> $payload
	 *   The JSON body.
	 *
	 * @return array<mixed>
	 *   The parsed answer.
	 *
	 * @throws RuntimeException
	 *   When B2 refuses.
	 */
	private function call(string $name, array $payload): array
	{
		$body = (string) json_encode($payload);
		$authorization = $this->account->authorization();
		$response = $this->send(
			'POST',
			$authorization->endpoint($name),
			[
				'Authorization' => $authorization->token,
				'Content-Type' => 'application/json',
				'Content-Length' => (string) strlen($body),
			],
			$body,
		);

		if ($response['status'] === 401) {
			$authorization = $this->account->reauthorize();
			$this->slot = null;
			$response = $this->send(
				'POST',
				$authorization->endpoint($name),
				[
					'Authorization' => $authorization->token,
					'Content-Type' => 'application/json',
					'Content-Length' => (string) strlen($body),
				],
				$body,
			);
		}

		$this->assertSuccess($response, sprintf('call %s', $name));

		return $this->json($response['body'], sprintf('call %s', $name));
	}

	/**
	 * Reads bytes or headers from the download host.
	 *
	 * @param string $method
	 *   Either `GET` or `HEAD`.
	 * @param string $key
	 *   Object key relative to the store root.
	 * @param array<string, string> $headers
	 *   Headers to send, without the authorization.
	 *
	 * @return array{status: int, body: string, headers: array<string, string>}
	 *   The response.
	 *
	 * @throws RuntimeException
	 *   When the account cannot authorize.
	 */
	private function download(string $method, string $key, array $headers): array
	{
		$target = $this->endpoint->keys->resolve($key);
		$authorization = $this->account->authorization();
		$headers['Authorization'] = $authorization->token;
		$response = $this->send(
			$method,
			$authorization->fileUrl($this->endpoint->bucketName, $target),
			$headers,
			null,
		);

		if ($response['status'] !== 401) {
			return $response;
		}

		$authorization = $this->account->reauthorize();
		$headers['Authorization'] = $authorization->token;

		return $this->send(
			$method,
			$authorization->fileUrl($this->endpoint->bucketName, $target),
			$headers,
			null,
		);
	}

	/**
	 * Sends one request.
	 *
	 * @param string $method
	 *   HTTP method.
	 * @param string $url
	 *   Absolute URL.
	 * @param array<string, string> $headers
	 *   Headers to send.
	 * @param string|null $body
	 *   The request body, or NULL for none.
	 *
	 * @return array{status: int, body: string, headers: array<string, string>}
	 *   The response, with header names lowercased.
	 *
	 * @throws RuntimeException
	 *   When the transport answers with something that is not a response.
	 */
	private function send(string $method, string $url, array $headers, ?string $body): array
	{
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
	 * A message describing a failed response.
	 *
	 * @param array{status: int, body: string, headers: array<string, string>} $response
	 *   The response.
	 * @param string $what
	 *   What was being attempted, as a verb phrase.
	 *
	 * @return string
	 *   The status, and B2's own code and message where it sent them.
	 */
	private function errorMessage(array $response, string $what): string
	{
		$parsed = json_decode($response['body'], true);
		$parsed = is_array($parsed) ? $parsed : [];
		$detail = trim(
			trim((string) ($parsed['code'] ?? '')) .
				' ' .
				trim((string) ($parsed['message'] ?? '')),
		);

		return sprintf(
			'Cannot %s: the endpoint answered %d%s',
			$what,
			$response['status'],
			$detail === '' ? '' : ' (' . $detail . ')',
		);
	}

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

	#endregion

	#region Bodies

	/**
	 * Raises when what B2 recorded is not what was sent.
	 *
	 * @param array<mixed> $file
	 *   The file B2 answered with.
	 * @param string $key
	 *   Object key as the caller named it.
	 * @param int $size
	 *   How many bytes were sent.
	 * @param string|null $sha1
	 *   The hash that was declared, or NULL for a large file, which has no whole-file hash.
	 *
	 * @throws RuntimeException
	 *   When the recorded size or hash disagrees.
	 */
	private function verify(array $file, string $key, int $size, ?string $sha1): void
	{
		$recorded = (int) ($file['contentLength'] ?? -1);

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

		$reported = strtolower(trim((string) ($file['contentSha1'] ?? '')));

		if ($sha1 !== null && $reported !== '' && $reported !== $sha1) {
			throw new RuntimeException(
				sprintf('Object %s was stored with a hash that is not the one sent', $key),
			);
		}
	}

	/**
	 * The user metadata a write carries.
	 *
	 * @param array<string, mixed> $options
	 *   Provider options.
	 *
	 * @return array<string, string>
	 *   Metadata keyed by name, with nothing added when none was asked for.
	 */
	private function fileInfo(array $options): array
	{
		$info = [];

		foreach ((array) ($options['metadata'] ?? []) as $name => $value) {
			$clean = trim((string) $name);

			if ($clean !== '') {
				$info[$clean] = (string) $value;
			}
		}

		return $info;
	}

	/**
	 * The metadata headers a single upload carries.
	 *
	 * @param array<string, mixed> $options
	 *   Provider options.
	 *
	 * @return array<string, string>
	 *   One `X-Bz-Info-` header per metadata entry.
	 */
	private function infoHeaders(array $options): array
	{
		$headers = [];

		foreach ($this->fileInfo($options) as $name => $value) {
			$headers[B2File::INFO_PREFIX . $name] = rawurlencode($value);
		}

		return $headers;
	}

	/**
	 * The content type a write declares.
	 *
	 * @param array<string, mixed> $options
	 *   Provider options.
	 *
	 * @return string
	 *   The requested type, or the token that asks B2 to decide.
	 */
	private function contentType(array $options): string
	{
		$type = $options['contentType'] ?? null;

		return is_string($type) && trim($type) !== '' ? $type : self::DEFAULT_TYPE;
	}

	/**
	 * Percent-encodes a file name for the header that carries it.
	 *
	 * @param string $name
	 *   The prefixed key.
	 *
	 * @return string
	 *   The name with each segment encoded and the separators kept, which B2 documents as valid and
	 *   which keeps a listing readable in the console.
	 */
	private function encodeName(string $name): string
	{
		return implode(
			'/',
			array_map(
				static fn(string $segment): string => rawurlencode($segment),
				explode('/', trim($name, '/')),
			),
		);
	}

	/**
	 * Guards a file against the account's ceiling.
	 *
	 * @param int $size
	 *   Object size in bytes.
	 * @param string $key
	 *   Object key, for the message.
	 *
	 * @throws RuntimeException
	 *   When the file is larger than the account can hold at all.
	 */
	private function assertFits(int $size, string $key): void
	{
		if ($this->capabilities()->canStore($size)) {
			return;
		}

		throw new RuntimeException(
			sprintf(
				'Object %s is %d bytes and b2 holds at most %d',
				$key,
				$size,
				$this->capabilities()->maxObjectSize(),
			),
		);
	}

	#endregion
}
