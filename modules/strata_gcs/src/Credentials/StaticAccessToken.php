<?php

declare(strict_types=1);

namespace Drupal\strata_gcs\Credentials;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * A token somebody else issued.
 *
 * What workload identity produces: the platform mints a token, the site uses it, and no private key
 * ever reaches the configuration. It also makes the provider drivable in a test with no key pair.
 *
 * The token is not refreshed, because nothing here knows how it was minted. A site using this is
 * responsible for handing in a live one.
 *
 * @see AccessTokenProviderInterface
 */
final class StaticAccessToken implements AccessTokenProviderInterface
{
	/**
	 * Constructs a token source.
	 *
	 * @param string $token
	 *   The access token.
	 *
	 * @throws InvalidArgumentException
	 *   When the token is empty, which would authenticate nothing.
	 */
	public function __construct(#[SensitiveParameter] private readonly string $token)
	{
		if (trim($token) === '') {
			throw new InvalidArgumentException('An access token cannot be empty');
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function token(): string
	{
		return $this->token;
	}

	/**
	 * {@inheritdoc}
	 */
	public function describe(): string
	{
		return 'a pre-issued access token';
	}
}
