<?php

declare(strict_types=1);

namespace Drupal\strata_azure;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Signs blob requests with the `SharedKey` scheme.
 *
 * Hand-rolled rather than delegated to the Azure SDK, for the same reason S3 is: the SDK is large,
 * it brings its own HTTP stack, and deciding exactly which headers leave the process is the whole
 * point. This class emits the string to sign, the signature and the `Authorization` header, and
 * nothing else.
 *
 * Three details decide whether a signature verifies, and each of them is a silent 403 when it is
 * wrong:
 *
 * - **The eleven fixed header lines are positional.** An absent header is an empty line, never a
 *   skipped one, and `Content-Length` is an empty line when it is zero, which the 2015-02-21 version
 *   of the protocol changed and every older worked example still shows as `0`.
 * - **The canonicalized resource repeats the account name under an emulator.** It is
 *   `/{account}{path}`, and an emulator's path already begins with the account, so the account
 *   appears twice. That falls out of the algorithm rather than being a special case for it.
 * - **Query parameters are lowercased, decoded and sorted**, and a repeated name has its values
 *   sorted and joined with a comma.
 *
 * The request time is passed in, so a signature is a pure function of its inputs and a test compares
 * against the published worked example instead of racing a clock.
 *
 * @see AzureCredentials
 * @see AzureStorageProvider
 */
final class SharedKeySigner
{
	/**
	 * The only scheme this signer emits.
	 */
	public const SCHEME = 'SharedKey';

	/**
	 * Header carrying the request timestamp.
	 */
	public const DATE_HEADER = 'x-ms-date';

	/**
	 * Header carrying the protocol version.
	 */
	public const VERSION_HEADER = 'x-ms-version';

	/**
	 * Prefix on the headers a signature canonicalizes.
	 */
	public const CANONICAL_PREFIX = 'x-ms-';

	/**
	 * Timestamp format the service accepts, which is RFC 1123 in GMT.
	 */
	public const TIMESTAMP = 'D, d M Y H:i:s \G\M\T';

	/**
	 * The eleven standard headers a signature covers, in the order it covers them.
	 */
	private const FIXED_HEADERS = [
		'content-encoding',
		'content-language',
		'content-length',
		'content-md5',
		'content-type',
		'date',
		'if-modified-since',
		'if-match',
		'if-none-match',
		'if-unmodified-since',
		'range',
	];

	/**
	 * Signs a request and returns the headers to send.
	 *
	 * Adds `x-ms-date` when it is absent and adds `Authorization`. Every other header is returned
	 * exactly as it was passed, because a header the signature covers has to reach the service byte
	 * for byte.
	 *
	 * @param AzureCredentials $credentials
	 *   The credentials to sign with.
	 * @param string $method
	 *   HTTP method.
	 * @param string $url
	 *   Absolute request URL, including any query string.
	 * @param array<string, string> $headers
	 *   Headers to sign.
	 * @param DateTimeImmutable $now
	 *   The request time.
	 *
	 * @return array<string, string>
	 *   The headers to send.
	 *
	 * @throws InvalidArgumentException
	 *   When the credentials carry no account key, which means the caller should have appended a SAS
	 *   token instead of asking for a signature.
	 */
	public function sign(
		AzureCredentials $credentials,
		string $method,
		string $url,
		array $headers,
		DateTimeImmutable $now,
	): array {
		if (!$credentials->hasSharedKey()) {
			throw new InvalidArgumentException(
				'A shared key signature needs an account key, and these credentials carry none',
			);
		}

		if ($this->headerValue($headers, self::DATE_HEADER) === null) {
			$headers[self::DATE_HEADER] = $this->timestamp($now);
		}

		$headers['Authorization'] = sprintf(
			'%s %s:%s',
			self::SCHEME,
			$credentials->accountName,
			$this->signature(
				$credentials->signingKey(),
				$this->stringToSign($credentials->accountName, $method, $url, $headers),
			),
		);

		return $headers;
	}

	/**
	 * The string a signature is computed over.
	 *
	 * @param string $account
	 *   Storage account name.
	 * @param string $method
	 *   HTTP method.
	 * @param string $url
	 *   Absolute request URL, including any query string.
	 * @param array<string, string> $headers
	 *   Headers to sign.
	 *
	 * @return string
	 *   The verb, the eleven fixed header lines, the canonicalized headers and the canonicalized
	 *   resource, newline separated.
	 */
	public function stringToSign(
		string $account,
		string $method,
		string $url,
		array $headers,
	): string {
		$lines = [strtoupper($method)];

		foreach (self::FIXED_HEADERS as $name) {
			$value = (string) ($this->headerValue($headers, $name) ?? '');

			// a zero content length signs as an empty line, which the 2015-02-21 version changed
			$lines[] = $name === 'content-length' && $value === '0' ? '' : $value;
		}

		return implode("\n", $lines) .
			"\n" .
			$this->canonicalizedHeaders($headers) .
			$this->canonicalizedResource($account, $url);
	}

	/**
	 * The `x-ms-` headers a signature covers, as canonical lines.
	 *
	 * @param array<string, string> $headers
	 *   Headers to sign.
	 *
	 * @return string
	 *   One `name:value` line per header in ascending name order, each ending in a newline, or an
	 *   empty string when there are none.
	 */
	public function canonicalizedHeaders(array $headers): string
	{
		$canonical = [];

		foreach ($headers as $name => $value) {
			$key = strtolower(trim((string) $name));

			if (!str_starts_with($key, self::CANONICAL_PREFIX)) {
				continue;
			}

			$collapsed = $this->collapse((string) $value);
			$canonical[$key] = isset($canonical[$key])
				? $canonical[$key] . ',' . $collapsed
				: $collapsed;
		}

		ksort($canonical, SORT_STRING);

		$lines = '';

		foreach ($canonical as $name => $value) {
			$lines .= $name . ':' . $value . "\n";
		}

		return $lines;
	}

	/**
	 * The resource a signature is scoped to.
	 *
	 * @param string $account
	 *   Storage account name.
	 * @param string $url
	 *   Absolute request URL, including any query string.
	 *
	 * @return string
	 *   `/account/path`, then one `\nname:value` line per query parameter in ascending name order.
	 */
	public function canonicalizedResource(string $account, string $url): string
	{
		$parts = parse_url($url);
		$path = is_array($parts) ? (string) ($parts['path'] ?? '/') : '/';
		$query = is_array($parts) ? (string) ($parts['query'] ?? '') : '';
		$resource = '/' . trim($account, '/') . ($path === '' ? '/' : $path);
		$pairs = [];

		foreach (explode('&', $query) as $pair) {
			if ($pair === '') {
				continue;
			}

			$at = strpos($pair, '=');
			$name = strtolower(rawurldecode($at === false ? $pair : substr($pair, 0, $at)));
			$pairs[$name][] = rawurldecode($at === false ? '' : substr($pair, $at + 1));
		}

		ksort($pairs, SORT_STRING);

		foreach ($pairs as $name => $values) {
			sort($values, SORT_STRING);
			$resource .= "\n" . $name . ':' . implode(',', $values);
		}

		return $resource;
	}

	/**
	 * The signature for one string to sign.
	 *
	 * @param string $key
	 *   The decoded account key, from AzureCredentials::signingKey().
	 * @param string $stringToSign
	 *   A string to sign from SharedKeySigner::stringToSign().
	 *
	 * @return string
	 *   The signature, base64 as the header carries it.
	 */
	public function signature(#[SensitiveParameter] string $key, string $stringToSign): string
	{
		return base64_encode(hash_hmac('sha256', $stringToSign, $key, true));
	}

	/**
	 * The request timestamp in the form the service accepts.
	 *
	 * @param DateTimeImmutable $now
	 *   The request time in any zone.
	 *
	 * @return string
	 *   A timestamp such as `Fri, 26 Jun 2015 23:39:12 GMT`.
	 */
	public function timestamp(DateTimeImmutable $now): string
	{
		return $now->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP);
	}

	/**
	 * One header's value, whatever case it was given in.
	 *
	 * @param array<string, string> $headers
	 *   Headers to search.
	 * @param string $name
	 *   Lowercased header name.
	 *
	 * @return string|null
	 *   The value, or NULL when the header is absent.
	 */
	private function headerValue(array $headers, string $name): ?string
	{
		foreach ($headers as $key => $value) {
			if (strtolower(trim((string) $key)) === $name) {
				return (string) $value;
			}
		}

		return null;
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
}
