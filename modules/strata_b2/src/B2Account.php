<?php

declare(strict_types=1);

namespace Drupal\strata_b2;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

/**
 * Exchanges an application key for an authorization.
 *
 * `b2_authorize_account` is the only call that takes the long-lived key, and everything it returns
 * is needed by everything else, so it happens once per request and is held. A token lasts 24 hours
 * and the only thing that reliably says it has stopped working is a 401, which is why re-authorizing
 * is a method rather than a timer.
 *
 * The transport is injected, so the exchange is exercised against a recorded response with no
 * network and no key.
 *
 * @see B2Authorization
 * @see B2StorageProvider
 */
final class B2Account
{
	/**
	 * API version this module speaks.
	 */
	public const API_VERSION = 'v2';

	/**
	 * Where an authorization is asked for, before the account's own api url is known.
	 */
	public const DEFAULT_API = 'https://api.backblazeb2.com';

	/**
	 * The injected transport.
	 */
	private readonly Closure $transport;

	/**
	 * The held authorization, or NULL before the first exchange.
	 */
	private ?B2Authorization $authorization = null;

	/**
	 * Constructs an account.
	 *
	 * @param string $keyId
	 *   The key id, which is the master key id or an application key id.
	 * @param string $applicationKey
	 *   The key itself.
	 * @param callable $transport
	 *   A transport with the signature
	 *   `callable(string $method, string $url, array $headers, ?string $body): array{status: int,
	 *   body: string, headers?: array<string, string>}`.
	 * @param string $apiUrl
	 *   Where to ask for an authorization.
	 *
	 * @throws InvalidArgumentException
	 *   When either half of the key is empty.
	 */
	public function __construct(
		private readonly string $keyId,
		#[SensitiveParameter] private readonly string $applicationKey,
		callable $transport,
		private readonly string $apiUrl = self::DEFAULT_API,
	) {
		if (trim($keyId) === '' || trim($applicationKey) === '') {
			throw new InvalidArgumentException(
				'A b2 account needs a key id and an application key',
			);
		}

		$this->transport = $transport(...);
	}

	/**
	 * The held authorization, exchanging for one on first use.
	 *
	 * @return B2Authorization
	 *   The authorization.
	 *
	 * @throws RuntimeException
	 *   When B2 refuses the key.
	 */
	public function authorization(): B2Authorization
	{
		return $this->authorization ??= $this->exchange();
	}

	/**
	 * Throws the held authorization away and asks for another.
	 *
	 * What a 401 means: the token expired, or the key was rotated under the site. One retry after
	 * this is the whole recovery, because a second 401 is a credential problem rather than a stale
	 * token.
	 *
	 * @return B2Authorization
	 *   The new authorization.
	 *
	 * @throws RuntimeException
	 *   When B2 refuses the key.
	 */
	public function reauthorize(): B2Authorization
	{
		$this->authorization = null;

		return $this->authorization();
	}

	/**
	 * The key id, for a message that must not print the key.
	 *
	 * @return string
	 *   A short description.
	 */
	public function describe(): string
	{
		return sprintf('the b2 key %s', $this->keyId);
	}

	/**
	 * Performs the exchange.
	 *
	 * @return B2Authorization
	 *   The authorization.
	 *
	 * @throws RuntimeException
	 *   When the transport answers with something that is not a response, when B2 refuses, or when
	 *   the answer names no api url.
	 */
	private function exchange(): B2Authorization
	{
		$response = ($this->transport)(
			'GET',
			sprintf(
				'%s/b2api/%s/b2_authorize_account',
				rtrim($this->apiUrl, '/'),
				self::API_VERSION,
			),
			[
				'Authorization' =>
					'Basic ' . base64_encode($this->keyId . ':' . $this->applicationKey),
			],
			null,
		);

		if (!is_array($response)) {
			throw new RuntimeException(
				'The transport answered b2_authorize_account with something that is not a response',
			);
		}

		$status = (int) ($response['status'] ?? 0);
		$parsed = json_decode((string) ($response['body'] ?? ''), true);
		$parsed = is_array($parsed) ? $parsed : [];

		if ($status < 200 || $status >= 300) {
			throw new RuntimeException(
				sprintf(
					'Cannot authorize %s: the endpoint answered %d%s',
					$this->describe(),
					$status,
					$this->detail($parsed),
				),
			);
		}

		try {
			return B2Authorization::fromResponse($parsed);
		} catch (InvalidArgumentException $e) {
			throw new RuntimeException(
				sprintf('b2_authorize_account answered without an api url: %s', $e->getMessage()),
				0,
				$e,
			);
		}
	}

	/**
	 * What B2 said went wrong.
	 *
	 * @param array<mixed> $parsed
	 *   The parsed response body.
	 *
	 * @return string
	 *   A parenthesised detail, or an empty string when there was none.
	 */
	private function detail(array $parsed): string
	{
		$detail = trim(
			trim((string) ($parsed['code'] ?? '')) .
				' ' .
				trim((string) ($parsed['message'] ?? '')),
		);

		return $detail === '' ? '' : ' (' . $detail . ')';
	}
}
