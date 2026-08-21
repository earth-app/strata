<?php

declare(strict_types=1);

namespace Drupal\strata_s3\Credentials;

use Closure;
use Throwable;

/**
 * Reads credentials from the EC2 instance metadata service.
 *
 * IMDSv2 only, which is three requests: PUT a token request, GET the role name attached to the
 * instance, GET that role's credentials. IMDSv1 is a single unauthenticated GET and is what made
 * metadata reachable through a server-side request forgery, so it is not attempted even as a
 * fallback.
 *
 * Every request carries a short timeout. The metadata address is link-local and unroutable, so off
 * EC2 the call fails rather than hanging, and a site that is not on EC2 pays a few milliseconds
 * once per chain resolution.
 *
 * The transport is injected, so a test drives the three-step exchange without a network.
 *
 * @see CredentialChain
 */
final class InstanceProfileCredentials implements CredentialProviderInterface
{
	/**
	 * Link-local address the metadata service answers on.
	 */
	public const ENDPOINT = 'http://169.254.169.254';

	/**
	 * Path the session token is requested from.
	 */
	public const TOKEN_PATH = '/latest/api/token';

	/**
	 * Path the attached role name is read from.
	 */
	public const ROLE_PATH = '/latest/meta-data/iam/security-credentials/';

	/**
	 * Header carrying the requested token lifetime.
	 */
	public const TTL_HEADER = 'X-aws-ec2-metadata-token-ttl-seconds';

	/**
	 * Header carrying the token on subsequent requests.
	 */
	public const TOKEN_HEADER = 'X-aws-ec2-metadata-token';

	/**
	 * Token lifetime requested, in seconds.
	 */
	public const TOKEN_TTL = 21600;

	/**
	 * The injected transport.
	 */
	private readonly Closure $transport;

	/**
	 * Constructs a provider.
	 *
	 * @param callable $transport
	 *   A transport with the signature
	 *   `callable(string $method, string $url, array $headers, ?string $body): array{status: int,
	 *   body: string}`. It is called at most three times per resolution and may throw, which reads
	 *   as this source having nothing.
	 * @param string $endpoint
	 *   Base URL of the metadata service, with no trailing slash.
	 */
	public function __construct(
		callable $transport,
		private readonly string $endpoint = self::ENDPOINT,
	) {
		$this->transport = $transport(...);
	}

	/**
	 * {@inheritdoc}
	 */
	public function resolve(): ?Credentials
	{
		try {
			$token = $this->token();

			if ($token === null) {
				return null;
			}

			$role = $this->role($token);

			if ($role === null) {
				return null;
			}

			return $this->credentials($token, $role);
		} catch (Throwable) {
			// off EC2 the address is unroutable; a failure here means this source has nothing
			return null;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function describe(): string
	{
		return sprintf('Instance metadata service (%s)', $this->endpoint);
	}

	#region Steps

	/**
	 * Requests a session token.
	 *
	 * @return string|null
	 *   The token, or NULL when the service did not issue one.
	 */
	private function token(): ?string
	{
		$response = $this->call('PUT', $this->endpoint . self::TOKEN_PATH, [
			self::TTL_HEADER => (string) self::TOKEN_TTL,
		]);

		return $response === null || trim($response) === '' ? null : trim($response);
	}

	/**
	 * Reads the role name attached to the instance.
	 *
	 * @param string $token
	 *   A session token.
	 *
	 * @return string|null
	 *   The role name, or NULL when no role is attached.
	 */
	private function role(string $token): ?string
	{
		$response = $this->call('GET', $this->endpoint . self::ROLE_PATH, [
			self::TOKEN_HEADER => $token,
		]);

		if ($response === null) {
			return null;
		}

		// the body lists one role per line; an instance carries at most one
		$name = trim(explode("\n", $response)[0]);

		return $name === '' ? null : $name;
	}

	/**
	 * Reads one role's credentials.
	 *
	 * @param string $token
	 *   A session token.
	 * @param string $role
	 *   The role name.
	 *
	 * @return Credentials|null
	 *   The credentials, or NULL when the document was absent or incomplete.
	 */
	private function credentials(string $token, string $role): ?Credentials
	{
		$response = $this->call('GET', $this->endpoint . self::ROLE_PATH . rawurlencode($role), [
			self::TOKEN_HEADER => $token,
		]);

		if ($response === null) {
			return null;
		}

		$document = json_decode($response, true);

		if (!is_array($document)) {
			return null;
		}

		$keyId = trim((string) ($document['AccessKeyId'] ?? ''));
		$secret = trim((string) ($document['SecretAccessKey'] ?? ''));

		if ($keyId === '' || $secret === '') {
			return null;
		}

		$sessionToken = trim((string) ($document['Token'] ?? ''));
		$expires = strtotime((string) ($document['Expiration'] ?? ''));

		return new Credentials(
			$keyId,
			$secret,
			$sessionToken === '' ? null : $sessionToken,
			$expires === false ? null : $expires,
		);
	}

	/**
	 * Makes one metadata request.
	 *
	 * @param string $method
	 *   HTTP method.
	 * @param string $url
	 *   Absolute URL.
	 * @param array<string, string> $headers
	 *   Request headers.
	 *
	 * @return string|null
	 *   The response body, or NULL when the status was not 200.
	 */
	private function call(string $method, string $url, array $headers): ?string
	{
		$response = ($this->transport)($method, $url, $headers, null);
		$status = (int) ($response['status'] ?? 0);

		return $status === 200 ? (string) ($response['body'] ?? '') : null;
	}

	#endregion
}
