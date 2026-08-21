<?php

declare(strict_types=1);

namespace Drupal\strata_s3;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\strata_s3\Credentials\Credentials;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Signs requests with AWS Signature Version 4.
 *
 * Hand-rolled rather than delegated to `aws-sdk-php`. The SDK installs 22 MiB, and both it and the
 * JavaScript SDK began attaching CRC32 checksum headers to every request in early 2025, which
 * Cloudflare R2, MinIO, Backblaze B2 and Dell ECS reject. Deciding exactly which headers leave the
 * process is what this class is for, so it emits the canonical request, the string to sign, the
 * derived signing key and the Authorization header, and nothing else.
 *
 * Region and service are fixed per signer. The request time is passed in, so a signature is a pure
 * function of its inputs and a test compares against a published vector instead of racing a clock.
 *
 * The canonical URI is rebuilt by decoding each path segment and re-encoding it, which is
 * idempotent for an already-encoded path and correct for a raw one. Path segments are not
 * normalised: `.` and `..` are legal characters in an S3 key, and collapsing them would sign a
 * different object than the one requested.
 *
 * @see Credentials
 * @see S3StorageProvider
 */
final class SigV4Signer
{
	/**
	 * The only algorithm this signer emits.
	 */
	public const ALGORITHM = 'AWS4-HMAC-SHA256';

	/**
	 * Final step of the signing key derivation.
	 */
	public const TERMINATOR = 'aws4_request';

	/**
	 * Payload hash placeholder for a body the endpoint should not checksum.
	 */
	public const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

	/**
	 * SHA-256 of the empty string, which every bodyless request signs.
	 */
	public const EMPTY_PAYLOAD = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	/**
	 * Header carrying the request timestamp.
	 */
	public const DATE_HEADER = 'x-amz-date';

	/**
	 * Header carrying the payload hash.
	 */
	public const CONTENT_SHA256_HEADER = 'x-amz-content-sha256';

	/**
	 * Header carrying a session token.
	 */
	public const TOKEN_HEADER = 'x-amz-security-token';

	/**
	 * Longest presigned URL lifetime the algorithm allows, in seconds.
	 */
	public const MAX_EXPIRES = 604800;

	/**
	 * Timestamp format used in the string to sign and in `x-amz-date`.
	 */
	private const TIMESTAMP = 'Ymd\THis\Z';

	/**
	 * Date format used in the credential scope.
	 */
	private const SCOPE_DATE = 'Ymd';

	/**
	 * Constructs a signer.
	 *
	 * @param string $region
	 *   Region the endpoint is addressed in, such as `us-east-1`. Cloudflare R2 uses `auto`.
	 * @param string $service
	 *   Service name in the credential scope. `s3` for every object store; the AWS test vectors use
	 *   the literal `service`.
	 *
	 * @throws InvalidArgumentException
	 *   When either is empty, which would produce a scope the endpoint cannot match.
	 */
	public function __construct(
		private readonly string $region,
		private readonly string $service = 's3',
	) {
		if (trim($region) === '') {
			throw new InvalidArgumentException('A signer needs a region');
		}
		if (trim($service) === '') {
			throw new InvalidArgumentException('A signer needs a service name');
		}
	}

	/**
	 * The region this signer scopes signatures to.
	 *
	 * @return string
	 *   The region.
	 */
	public function region(): string
	{
		return $this->region;
	}

	/**
	 * The service this signer scopes signatures to.
	 *
	 * @return string
	 *   The service name.
	 */
	public function service(): string
	{
		return $this->service;
	}

	#region Signing

	/**
	 * Signs a request and returns the headers to send.
	 *
	 * Adds `x-amz-date`, adds `x-amz-security-token` when the credentials carry one, and adds
	 * `Authorization`. Every other header is returned exactly as it was passed, because a header the
	 * signature covers has to reach the endpoint byte for byte.
	 *
	 * @param Credentials $credentials
	 *   The credentials to sign with.
	 * @param string $method
	 *   HTTP method.
	 * @param string $url
	 *   Absolute request URL, including any query string.
	 * @param array<string, string|list<string>> $headers
	 *   Headers to sign. `host` is derived from the URL when absent.
	 * @param DateTimeImmutable $now
	 *   The request time.
	 * @param string $payloadHash
	 *   Lowercase hex SHA-256 of the body, SigV4Signer::EMPTY_PAYLOAD for a bodyless request, or
	 *   SigV4Signer::UNSIGNED_PAYLOAD to leave the body out of the signature.
	 *
	 * @return array<string, string|list<string>>
	 *   The headers to send.
	 *
	 * @throws InvalidArgumentException
	 *   When the URL has no host, or `x-amz-content-sha256` is present and disagrees with
	 *   $payloadHash. A disagreement would sign one body and send another.
	 */
	public function signRequest(
		Credentials $credentials,
		string $method,
		string $url,
		array $headers,
		DateTimeImmutable $now,
		string $payloadHash = self::EMPTY_PAYLOAD,
	): array {
		$stated = $this->headerValue($headers, self::CONTENT_SHA256_HEADER);

		if ($stated !== null && $stated !== $payloadHash) {
			throw new InvalidArgumentException(
				sprintf(
					'%s is "%s" but the payload hash being signed is "%s"',
					self::CONTENT_SHA256_HEADER,
					$stated,
					$payloadHash,
				),
			);
		}

		if ($this->headerValue($headers, self::DATE_HEADER) === null) {
			$headers[self::DATE_HEADER] = $this->timestamp($now);
		}
		$carriesToken = $this->headerValue($headers, self::TOKEN_HEADER) !== null;

		if ($credentials->hasSessionToken() && !$carriesToken) {
			$headers[self::TOKEN_HEADER] = (string) $credentials->sessionToken;
		}

		$signed = $this->signedHeaders($headers, $url);
		$canonical = $this->canonicalRequest($method, $url, $headers, $payloadHash);
		$signature = $this->signature(
			$credentials->secretAccessKey,
			$this->stringToSign($canonical, $now),
			$now,
		);

		$headers['Authorization'] = sprintf(
			'%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			self::ALGORITHM,
			$credentials->accessKeyId,
			$this->credentialScope($now),
			$signed,
			$signature,
		);

		return $headers;
	}

	/**
	 * Builds a presigned URL.
	 *
	 * The signature moves into the query string, so a browser or a curl invocation can fetch the
	 * object with no Authorization header. Only `host` is signed by default, which is the minimum the
	 * algorithm allows and the most portable across endpoints.
	 *
	 * @param Credentials $credentials
	 *   The credentials to sign with.
	 * @param string $method
	 *   HTTP method the URL is valid for.
	 * @param string $url
	 *   Absolute URL, including any query string that must survive into the signed form.
	 * @param int $expires
	 *   Lifetime in seconds, from 1 to SigV4Signer::MAX_EXPIRES.
	 * @param DateTimeImmutable $now
	 *   The signing time, which the lifetime counts from.
	 * @param array<string, string|list<string>> $headers
	 *   Extra headers to sign. Every one of them has to be sent with the eventual request.
	 * @param string $payloadHash
	 *   Payload hash, normally SigV4Signer::UNSIGNED_PAYLOAD because the fetcher's body is unknown
	 *   at signing time.
	 *
	 * @return string
	 *   The signed URL, with its query string in canonical order and `X-Amz-Signature` last.
	 *
	 * @throws InvalidArgumentException
	 *   When the lifetime is out of range or the URL has no host.
	 */
	public function presign(
		Credentials $credentials,
		string $method,
		string $url,
		int $expires,
		DateTimeImmutable $now,
		array $headers = [],
		string $payloadHash = self::UNSIGNED_PAYLOAD,
	): string {
		if ($expires < 1 || $expires > self::MAX_EXPIRES) {
			throw new InvalidArgumentException(
				sprintf(
					'A presigned url lasts 1 to %d seconds, not %d',
					self::MAX_EXPIRES,
					$expires,
				),
			);
		}

		$signed = $this->signedHeaders($headers, $url);
		$query = $this->queryPairs($this->part($url, 'query'));

		$query['X-Amz-Algorithm'] = self::ALGORITHM;
		$query['X-Amz-Credential'] = $credentials->accessKeyId . '/' . $this->credentialScope($now);
		$query['X-Amz-Date'] = $this->timestamp($now);
		$query['X-Amz-Expires'] = (string) $expires;
		$query['X-Amz-SignedHeaders'] = $signed;

		if ($credentials->hasSessionToken()) {
			$query['X-Amz-Security-Token'] = (string) $credentials->sessionToken;
		}

		$canonicalQuery = $this->canonicalPairs($query);
		$canonical = implode("\n", [
			strtoupper($method),
			$this->canonicalUri($this->part($url, 'path')),
			$canonicalQuery,
			$this->canonicalHeaders($this->normalized($headers, $url)),
			$signed,
			$payloadHash,
		]);

		$signature = $this->signature(
			$credentials->secretAccessKey,
			$this->stringToSign($canonical, $now),
			$now,
		);

		return $this->base($url) . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
	}

	#endregion

	#region Stages

	/**
	 * The canonical request for one set of inputs.
	 *
	 * @param string $method
	 *   HTTP method.
	 * @param string $url
	 *   Absolute request URL, including any query string.
	 * @param array<string, string|list<string>> $headers
	 *   Headers to sign. `host` is derived from the URL when absent.
	 * @param string $payloadHash
	 *   The hashed payload line.
	 *
	 * @return string
	 *   The canonical request, newline separated, with no trailing newline.
	 *
	 * @throws InvalidArgumentException
	 *   When the URL has no host.
	 */
	public function canonicalRequest(
		string $method,
		string $url,
		array $headers,
		string $payloadHash = self::EMPTY_PAYLOAD,
	): string {
		return implode("\n", [
			strtoupper($method),
			$this->canonicalUri($this->part($url, 'path')),
			$this->canonicalQuery($this->part($url, 'query')),
			$this->canonicalHeaders($this->normalized($headers, $url)),
			$this->signedHeaders($headers, $url),
			$payloadHash,
		]);
	}

	/**
	 * The semicolon-separated list of headers a signature covers.
	 *
	 * @param array<string, string|list<string>> $headers
	 *   Headers to sign.
	 * @param string $url
	 *   Absolute request URL, used to derive `host` when it is absent.
	 *
	 * @return string
	 *   Lowercased header names in ascending order, such as `host;x-amz-date`.
	 *
	 * @throws InvalidArgumentException
	 *   When the URL has no host.
	 */
	public function signedHeaders(array $headers, string $url): string
	{
		return implode(';', array_keys($this->normalized($headers, $url)));
	}

	/**
	 * The string a signature is computed over.
	 *
	 * @param string $canonicalRequest
	 *   A canonical request from SigV4Signer::canonicalRequest().
	 * @param DateTimeImmutable $now
	 *   The request time.
	 *
	 * @return string
	 *   Four newline-separated lines: the algorithm, the timestamp, the credential scope and the
	 *   hex SHA-256 of the canonical request.
	 */
	public function stringToSign(string $canonicalRequest, DateTimeImmutable $now): string
	{
		return implode("\n", [
			self::ALGORITHM,
			$this->timestamp($now),
			$this->credentialScope($now),
			hash('sha256', $canonicalRequest),
		]);
	}

	/**
	 * The scope a signature is valid within.
	 *
	 * @param DateTimeImmutable $now
	 *   The request time.
	 *
	 * @return string
	 *   A scope such as `20150830/us-east-1/s3/aws4_request`.
	 */
	public function credentialScope(DateTimeImmutable $now): string
	{
		return implode('/', [
			$this->utc($now)->format(self::SCOPE_DATE),
			$this->region,
			$this->service,
			self::TERMINATOR,
		]);
	}

	/**
	 * The signing key for one day, region and service.
	 *
	 * Four chained HMACs starting from the secret prefixed with `AWS4`, so the key that touches the
	 * request is scoped and the long-lived secret is not.
	 *
	 * @param string $secret
	 *   The secret access key.
	 * @param DateTimeImmutable $now
	 *   The request time, which decides the date in the scope.
	 *
	 * @return string
	 *   The key as lowercase hex, matching the form AWS publishes its worked example in.
	 */
	public function signingKey(#[SensitiveParameter] string $secret, DateTimeImmutable $now): string
	{
		return bin2hex($this->deriveKey($secret, $this->utc($now)->format(self::SCOPE_DATE)));
	}

	/**
	 * The signature for one string to sign.
	 *
	 * @param string $secret
	 *   The secret access key.
	 * @param string $stringToSign
	 *   A string to sign from SigV4Signer::stringToSign().
	 * @param DateTimeImmutable $now
	 *   The request time.
	 *
	 * @return string
	 *   The signature as lowercase hex.
	 */
	public function signature(
		#[SensitiveParameter] string $secret,
		string $stringToSign,
		DateTimeImmutable $now,
	): string {
		$key = $this->deriveKey($secret, $this->utc($now)->format(self::SCOPE_DATE));

		return hash_hmac('sha256', $stringToSign, $key);
	}

	/**
	 * The request timestamp in the form the algorithm uses.
	 *
	 * @param DateTimeImmutable $now
	 *   The request time.
	 *
	 * @return string
	 *   A timestamp such as `20150830T123600Z`.
	 */
	public function timestamp(DateTimeImmutable $now): string
	{
		return $this->utc($now)->format(self::TIMESTAMP);
	}

	#endregion

	#region Canonicalisation

	/**
	 * The canonical form of a request path.
	 *
	 * @param string $path
	 *   The path portion of the URL, encoded or raw.
	 *
	 * @return string
	 *   Each segment percent-encoded per RFC 3986 with the separators kept, never empty.
	 */
	private function canonicalUri(string $path): string
	{
		if ($path === '' || $path === '/') {
			return '/';
		}
		if (!str_starts_with($path, '/')) {
			$path = '/' . $path;
		}

		$segments = array_map(
			static fn(string $segment): string => rawurlencode(rawurldecode($segment)),
			explode('/', $path),
		);

		return implode('/', $segments);
	}

	/**
	 * The canonical form of a query string.
	 *
	 * @param string $query
	 *   The query portion of the URL, with no leading `?`.
	 *
	 * @return string
	 *   Encoded pairs sorted by key then value, or an empty string when there is no query.
	 */
	private function canonicalQuery(string $query): string
	{
		return $this->canonicalPairs($this->queryPairs($query));
	}

	/**
	 * Splits a query string into decoded pairs.
	 *
	 * @param string $query
	 *   The query portion of the URL, with no leading `?`.
	 *
	 * @return array<string, string>
	 *   Decoded values keyed by decoded name. A repeated name keeps its last value, which is what
	 *   every S3 operation this module issues means by one.
	 */
	private function queryPairs(string $query): array
	{
		$pairs = [];

		foreach (explode('&', $query) as $pair) {
			if ($pair === '') {
				continue;
			}

			$at = strpos($pair, '=');
			$name = $at === false ? $pair : substr($pair, 0, $at);
			$value = $at === false ? '' : substr($pair, $at + 1);

			$pairs[rawurldecode($name)] = rawurldecode($value);
		}

		return $pairs;
	}

	/**
	 * Encodes and orders query pairs.
	 *
	 * @param array<string, string> $pairs
	 *   Decoded values keyed by decoded name.
	 *
	 * @return string
	 *   Encoded pairs joined with `&`, sorted by encoded name then encoded value.
	 */
	private function canonicalPairs(array $pairs): string
	{
		$encoded = [];

		foreach ($pairs as $name => $value) {
			$encoded[] = [rawurlencode((string) $name), rawurlencode($value)];
		}

		usort($encoded, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

		return implode(
			'&',
			array_map(static fn(array $pair): string => $pair[0] . '=' . $pair[1], $encoded),
		);
	}

	/**
	 * Reduces headers to the canonical set a signature covers.
	 *
	 * @param array<string, string|list<string>> $headers
	 *   Headers to sign.
	 * @param string $url
	 *   Absolute request URL, used to derive `host` when it is absent.
	 *
	 * @return array<string, list<string>>
	 *   Trimmed values keyed by lowercased name, in ascending name order. A name given twice keeps
	 *   both values, in the order they were supplied.
	 *
	 * @throws InvalidArgumentException
	 *   When the URL has no host.
	 */
	private function normalized(array $headers, string $url): array
	{
		$normalized = [];

		foreach ($headers as $name => $value) {
			$key = strtolower(trim((string) $name));

			if ($key === '') {
				continue;
			}

			foreach (is_array($value) ? $value : [$value] as $one) {
				$normalized[$key][] = $this->collapse((string) $one);
			}
		}

		if (!isset($normalized['host'])) {
			$normalized['host'] = [$this->hostFor($url)];
		}

		ksort($normalized, SORT_STRING);

		return $normalized;
	}

	/**
	 * Renders normalised headers as canonical header lines.
	 *
	 * @param array<string, list<string>> $headers
	 *   Normalised headers from SigV4Signer::normalized().
	 *
	 * @return string
	 *   One `name:value` line per header, each ending in a newline.
	 */
	private function canonicalHeaders(array $headers): string
	{
		$lines = '';

		foreach ($headers as $name => $values) {
			$lines .= $name . ':' . implode(',', $values) . "\n";
		}

		return $lines;
	}

	/**
	 * Trims a header value and collapses runs of whitespace inside it.
	 *
	 * @param string $value
	 *   The value as supplied.
	 *
	 * @return string
	 *   The value with no leading or trailing whitespace and no internal run longer than one space.
	 */
	private function collapse(string $value): string
	{
		return (string) preg_replace('/\s+/', ' ', trim($value));
	}

	#endregion

	#region Urls

	/**
	 * The `host` header value for a URL.
	 *
	 * @param string $url
	 *   Absolute request URL.
	 *
	 * @return string
	 *   The host, with the port appended when it is not the default for the scheme.
	 *
	 * @throws InvalidArgumentException
	 *   When the URL has no host.
	 */
	private function hostFor(string $url): string
	{
		$parts = parse_url($url);
		$host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';

		if ($host === '') {
			throw new InvalidArgumentException(
				sprintf('Cannot sign "%s" because it names no host', $url),
			);
		}

		$scheme = strtolower((string) (is_array($parts) ? $parts['scheme'] ?? 'https' : 'https'));
		$port = is_array($parts) ? $parts['port'] ?? null : null;
		$default = $scheme === 'http' ? 80 : 443;

		return $port === null || (int) $port === $default ? $host : $host . ':' . (int) $port;
	}

	/**
	 * One component of a URL.
	 *
	 * @param string $url
	 *   Absolute request URL.
	 * @param string $component
	 *   Either `path` or `query`.
	 *
	 * @return string
	 *   The component, or an empty string when the URL does not carry it.
	 */
	private function part(string $url, string $component): string
	{
		$parts = parse_url($url);

		return is_array($parts) ? (string) ($parts[$component] ?? '') : '';
	}

	/**
	 * A URL with its query string and fragment removed.
	 *
	 * @param string $url
	 *   Absolute request URL.
	 *
	 * @return string
	 *   Scheme, host, port and path.
	 */
	private function base(string $url): string
	{
		$at = strcspn($url, '?#');

		return substr($url, 0, $at);
	}

	/**
	 * One header's value, whatever case it was given in.
	 *
	 * @param array<string, string|list<string>> $headers
	 *   Headers to search.
	 * @param string $name
	 *   Lowercased header name.
	 *
	 * @return string|null
	 *   The first value, or NULL when the header is absent.
	 */
	private function headerValue(array $headers, string $name): ?string
	{
		foreach ($headers as $key => $value) {
			if (strtolower(trim((string) $key)) !== $name) {
				continue;
			}

			$first = is_array($value) ? $value[0] ?? '' : $value;

			return (string) $first;
		}

		return null;
	}

	#endregion

	#region Keys

	/**
	 * Derives the raw signing key.
	 *
	 * @param string $secret
	 *   The secret access key.
	 * @param string $date
	 *   The scope date as `Ymd`.
	 *
	 * @return string
	 *   32 raw bytes.
	 */
	private function deriveKey(#[SensitiveParameter] string $secret, string $date): string
	{
		$key = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
		$key = hash_hmac('sha256', $this->region, $key, true);
		$key = hash_hmac('sha256', $this->service, $key, true);

		return hash_hmac('sha256', self::TERMINATOR, $key, true);
	}

	/**
	 * The request time in UTC.
	 *
	 * @param DateTimeImmutable $now
	 *   The request time in any zone.
	 *
	 * @return DateTimeImmutable
	 *   The same instant in UTC, which is the only zone the algorithm accepts.
	 */
	private function utc(DateTimeImmutable $now): DateTimeImmutable
	{
		return $now->setTimezone(new DateTimeZone('UTC'));
	}

	#endregion
}
