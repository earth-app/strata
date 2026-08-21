<?php

declare(strict_types=1);

namespace Drupal\strata_gcs\Credentials;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

/**
 * Exchanges a self-signed JWT for a Cloud Storage access token.
 *
 * The whole of Google's service-account flow, which is smaller than it looks: build a JWT whose
 * audience is the token endpoint, sign it RS256 with the account's private key, and POST it under
 * the `jwt-bearer` grant. Nothing else in `google/auth` is needed for a server-to-server call, and
 * that package brings its own HTTP stack, its own cache and 40-odd files.
 *
 * The token is cached until shortly before it expires. Google issues one-hour tokens and the clock
 * on a web host is not the clock in Mountain View, so the cache is retired a minute early rather
 * than exactly on the boundary.
 *
 * The clock and the transport are injected, so a test drives the exchange with no network and
 * verifies the assertion against a key pair it generated itself.
 *
 * @see AccessTokenProviderInterface
 */
final class ServiceAccountCredentials implements AccessTokenProviderInterface
{
	/**
	 * Where an assertion is exchanged.
	 */
	public const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

	/**
	 * The grant a self-signed assertion is presented under.
	 */
	public const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

	/**
	 * The scope a storage token is asked for.
	 */
	public const SCOPE = 'https://www.googleapis.com/auth/devstorage.read_write';

	/**
	 * How long an assertion is valid for, in seconds. Google refuses more than an hour.
	 */
	public const LIFETIME = 3600;

	/**
	 * Seconds before expiry at which a cached token is thrown away.
	 */
	public const SKEW = 60;

	/**
	 * The injected transport.
	 */
	private readonly Closure $transport;

	/**
	 * The injected clock.
	 */
	private readonly Closure $clock;

	/**
	 * The cached token, or NULL before the first exchange.
	 */
	private ?string $token = null;

	/**
	 * When the cached token stops being used, as a Unix timestamp.
	 */
	private int $expires = 0;

	/**
	 * Constructs a credential source.
	 *
	 * @param string $clientEmail
	 *   The service account's address, which the assertion is issued by and for.
	 * @param string $privateKey
	 *   The account's private key, PEM encoded exactly as the downloaded json carries it.
	 * @param callable $transport
	 *   A transport with the signature
	 *   `callable(string $method, string $url, array $headers, ?string $body): array{status: int,
	 *   body: string, headers?: array<string, string>}`.
	 * @param string $tokenUri
	 *   Where the assertion is exchanged.
	 * @param string $scope
	 *   The scope to ask for.
	 * @param callable|null $clock
	 *   Returns the current Unix timestamp, or NULL for the system clock.
	 *
	 * @throws InvalidArgumentException
	 *   When the address or the key is empty.
	 */
	public function __construct(
		private readonly string $clientEmail,
		#[SensitiveParameter] private readonly string $privateKey,
		callable $transport,
		private readonly string $tokenUri = self::DEFAULT_TOKEN_URI,
		private readonly string $scope = self::SCOPE,
		?callable $clock = null,
	) {
		if (trim($clientEmail) === '') {
			throw new InvalidArgumentException('A service account needs a client email');
		}
		if (trim($privateKey) === '') {
			throw new InvalidArgumentException('A service account needs a private key');
		}

		$this->transport = $transport(...);
		$this->clock = $clock === null ? static fn(): int => time() : $clock(...);
	}

	/**
	 * Reads a downloaded service account key file.
	 *
	 * @param string $json
	 *   The contents of the json Google hands out.
	 * @param callable $transport
	 *   The transport the exchange is carried over.
	 * @param string $scope
	 *   The scope to ask for.
	 * @param callable|null $clock
	 *   Returns the current Unix timestamp, or NULL for the system clock.
	 *
	 * @return self
	 *   The credentials.
	 *
	 * @throws InvalidArgumentException
	 *   When the json does not parse or does not carry both fields a service account needs.
	 */
	public static function fromJson(
		#[SensitiveParameter] string $json,
		callable $transport,
		string $scope = self::SCOPE,
		?callable $clock = null,
	): self {
		$parsed = json_decode(trim($json), true);

		if (!is_array($parsed)) {
			throw new InvalidArgumentException('A service account key has to be a json object');
		}

		$email = trim((string) ($parsed['client_email'] ?? ''));
		$key = trim((string) ($parsed['private_key'] ?? ''));

		if ($email === '' || $key === '') {
			throw new InvalidArgumentException(
				'A service account key needs both client_email and private_key',
			);
		}

		$uri = trim((string) ($parsed['token_uri'] ?? ''));

		return new self(
			$email,
			$key,
			$transport,
			$uri === '' ? self::DEFAULT_TOKEN_URI : $uri,
			$scope,
			$clock,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function token(): string
	{
		$now = (int) ($this->clock)();

		if ($this->token !== null && $now < $this->expires) {
			return $this->token;
		}

		$response = ($this->transport)(
			'POST',
			$this->tokenUri,
			['Content-Type' => 'application/x-www-form-urlencoded'],
			http_build_query([
				'grant_type' => self::GRANT_TYPE,
				'assertion' => $this->assertion($now),
			]),
		);

		if (!is_array($response)) {
			throw new RuntimeException(
				'The transport answered the token exchange with something that is not a response',
			);
		}

		$status = (int) ($response['status'] ?? 0);
		$parsed = json_decode((string) ($response['body'] ?? ''), true);
		$parsed = is_array($parsed) ? $parsed : [];

		if ($status < 200 || $status >= 300) {
			throw new RuntimeException(
				sprintf(
					'Cannot exchange the service account assertion: the endpoint answered %d%s',
					$status,
					$this->detail($parsed),
				),
			);
		}

		$token = trim((string) ($parsed['access_token'] ?? ''));

		if ($token === '') {
			throw new RuntimeException('The token endpoint answered without an access token');
		}

		$this->token = $token;
		$this->expires =
			$now + max(0, (int) ($parsed['expires_in'] ?? self::LIFETIME) - self::SKEW);

		return $token;
	}

	/**
	 * {@inheritdoc}
	 */
	public function describe(): string
	{
		return sprintf('the service account %s', $this->clientEmail);
	}

	/**
	 * Builds and signs one assertion.
	 *
	 * @param int $issuedAt
	 *   When the assertion is issued, as a Unix timestamp.
	 *
	 * @return string
	 *   The JWT, as three base64url segments joined by dots.
	 *
	 * @throws RuntimeException
	 *   When this host has no openssl, or the key will not sign.
	 */
	public function assertion(int $issuedAt): string
	{
		if (!function_exists('openssl_pkey_get_private')) {
			throw new RuntimeException(
				'A service account assertion needs the openssl extension, which this host has not',
			);
		}

		// resolved first, because openssl_sign() warns rather than returning on a key it cannot read
		$key = openssl_pkey_get_private($this->privateKey);

		if ($key === false) {
			throw new RuntimeException(
				'The service account private key would not sign an assertion: it is not a key',
			);
		}

		$header = $this->segment(['alg' => 'RS256', 'typ' => 'JWT']);
		$claims = $this->segment([
			'iss' => $this->clientEmail,
			'sub' => $this->clientEmail,
			'scope' => $this->scope,
			'aud' => $this->tokenUri,
			'iat' => $issuedAt,
			'exp' => $issuedAt + self::LIFETIME,
		]);
		$signing = $header . '.' . $claims;
		$signature = '';

		if (!openssl_sign($signing, $signature, $key, OPENSSL_ALGO_SHA256)) {
			throw new RuntimeException(
				'The service account private key would not sign an assertion',
			);
		}

		return $signing . '.' . $this->encode($signature);
	}

	/**
	 * One JWT segment.
	 *
	 * @param array<string, mixed> $claims
	 *   The segment's contents.
	 *
	 * @return string
	 *   The json, base64url encoded.
	 */
	private function segment(array $claims): string
	{
		return $this->encode((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
	}

	/**
	 * Base64url, which a JWT uses and plain base64 is not.
	 *
	 * @param string $value
	 *   The bytes.
	 *
	 * @return string
	 *   The value with the two substituted characters and no padding.
	 */
	private function encode(string $value): string
	{
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}

	/**
	 * What the token endpoint said went wrong.
	 *
	 * @param array<mixed> $parsed
	 *   The parsed response body.
	 *
	 * @return string
	 *   A parenthesised detail, or an empty string when there was none.
	 */
	private function detail(array $parsed): string
	{
		$error = trim((string) ($parsed['error'] ?? ''));
		$description = trim((string) ($parsed['error_description'] ?? ''));
		$detail = trim($error . ' ' . $description);

		return $detail === '' ? '' : ' (' . $detail . ')';
	}
}
