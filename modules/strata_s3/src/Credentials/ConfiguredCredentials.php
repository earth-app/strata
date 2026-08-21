<?php

declare(strict_types=1);

namespace Drupal\strata_s3\Credentials;

/**
 * Credentials typed into the settings form.
 *
 * First in the chain, so a site that entered a key pair is never overridden by an environment that
 * happens to carry different ones.
 *
 * @see CredentialChain
 */
final class ConfiguredCredentials implements CredentialProviderInterface
{
	/**
	 * Constructs a provider.
	 *
	 * @param Credentials $credentials
	 *   The configured pair.
	 */
	public function __construct(private readonly Credentials $credentials) {}

	/**
	 * {@inheritdoc}
	 *
	 * Narrowed to never return NULL: a configured pair exists by construction, so this provider
	 * either is in the chain or is not, and never resolves to nothing.
	 */
	public function resolve(): Credentials
	{
		return $this->credentials;
	}

	/**
	 * {@inheritdoc}
	 */
	public function describe(): string
	{
		return 'configured in strata.settings';
	}
}
