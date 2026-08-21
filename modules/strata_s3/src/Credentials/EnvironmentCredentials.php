<?php

declare(strict_types=1);

namespace Drupal\strata_s3\Credentials;

/**
 * Reads credentials from the process environment.
 *
 * The variables every AWS tool agrees on: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and the
 * optional `AWS_SESSION_TOKEN`. This is how a container, a CI job and a `docker run -e` invocation
 * hand credentials to a site, so it sits first in the default chain.
 *
 * The environment is taken as an array rather than read through getenv() at each call, so a test
 * drives it without mutating the process it runs in.
 *
 * @see CredentialChain
 */
final class EnvironmentCredentials implements CredentialProviderInterface
{
	/**
	 * Variable holding the access key id.
	 */
	public const KEY_ID = 'AWS_ACCESS_KEY_ID';

	/**
	 * Variable holding the secret access key.
	 */
	public const SECRET = 'AWS_SECRET_ACCESS_KEY';

	/**
	 * Variable holding an optional session token.
	 */
	public const TOKEN = 'AWS_SESSION_TOKEN';

	/**
	 * The environment to read.
	 *
	 * @var array<string, string>
	 */
	private readonly array $environment;

	/**
	 * Constructs a provider.
	 *
	 * @param array<string, string>|null $environment
	 *   The environment to read, or NULL to read the process environment through getenv().
	 */
	public function __construct(?array $environment = null)
	{
		$this->environment = $environment ?? getenv();
	}

	/**
	 * {@inheritdoc}
	 */
	public function resolve(): ?Credentials
	{
		$keyId = trim($this->environment[self::KEY_ID] ?? '');
		$secret = trim($this->environment[self::SECRET] ?? '');

		// half a pair is a misconfiguration, not a partial answer, so the source reports nothing
		if ($keyId === '' || $secret === '') {
			return null;
		}

		$token = trim($this->environment[self::TOKEN] ?? '');

		return new Credentials($keyId, $secret, $token === '' ? null : $token);
	}

	/**
	 * {@inheritdoc}
	 */
	public function describe(): string
	{
		return sprintf('Environment (%s)', self::KEY_ID);
	}
}
