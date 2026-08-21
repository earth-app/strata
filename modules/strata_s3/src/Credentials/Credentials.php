<?php

declare(strict_types=1);

namespace Drupal\strata_s3\Credentials;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * One set of credentials for an S3-compatible endpoint.
 *
 * Long-lived keys carry no expiry. A session obtained from STS or from the instance metadata
 * service carries one, and a signature made after it passes is rejected by the endpoint, so the
 * expiry travels with the keys rather than beside them.
 *
 * The secret is marked sensitive so a stack trace, a var_dump of an exception or a logged backtrace
 * shows the parameter as redacted instead of printing the key.
 *
 * @see CredentialProviderInterface
 */
final class Credentials
{
	/**
	 * Seconds before the stated expiry at which a session is treated as spent.
	 *
	 * A request signed in the last moments of a session can still arrive after it has lapsed, and
	 * the endpoint judges the arrival rather than the signing.
	 */
	public const EXPIRY_MARGIN = 60;

	/**
	 * Constructs a credential set.
	 *
	 * @param string $accessKeyId
	 *   The access key id, which appears in the credential scope of every signature.
	 * @param string $secretAccessKey
	 *   The secret, used only to derive a signing key and never transmitted.
	 * @param string|null $sessionToken
	 *   A session token to send as `x-amz-security-token`, or NULL for a long-lived key pair.
	 * @param int|null $expiresAt
	 *   Unix timestamp the credentials lapse at, or NULL when they do not expire.
	 *
	 * @throws InvalidArgumentException
	 *   When the key id or the secret is empty. An empty half of a pair is a misconfiguration that
	 *   otherwise surfaces as an opaque 403 from the endpoint.
	 */
	public function __construct(
		public readonly string $accessKeyId,
		#[SensitiveParameter] public readonly string $secretAccessKey,
		public readonly ?string $sessionToken = null,
		public readonly ?int $expiresAt = null,
	) {
		if (trim($accessKeyId) === '') {
			throw new InvalidArgumentException('Credentials need an access key id');
		}
		if ($secretAccessKey === '') {
			throw new InvalidArgumentException('Credentials need a secret access key');
		}
	}

	/**
	 * Whether these credentials are spent.
	 *
	 * @param int $now
	 *   The current Unix timestamp, passed in so a caller can decide what "now" means.
	 *
	 * @return bool
	 *   TRUE when the credentials carry an expiry that has passed, or is within
	 *   Credentials::EXPIRY_MARGIN seconds of passing.
	 */
	public function isExpired(int $now): bool
	{
		return $this->expiresAt !== null && $this->expiresAt - self::EXPIRY_MARGIN <= $now;
	}

	/**
	 * Whether a session token accompanies the key pair.
	 *
	 * @return bool
	 *   TRUE when `x-amz-security-token` has to be sent.
	 */
	public function hasSessionToken(): bool
	{
		return $this->sessionToken !== null && $this->sessionToken !== '';
	}
}
