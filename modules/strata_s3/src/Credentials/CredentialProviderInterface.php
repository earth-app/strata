<?php

declare(strict_types=1);

namespace Drupal\strata_s3\Credentials;

/**
 * One place credentials can come from.
 *
 * A site running on EC2 or ECS has credentials in the instance metadata service, a developer has
 * them in `~/.aws/credentials`, a container has them in the environment, and a Drupal site has them
 * in the key module. Each of those is a provider, and CredentialChain tries them in order.
 *
 * A provider that has nothing returns NULL rather than raising. Absence is the normal answer for
 * every source except the one that wins, so it cannot be exceptional.
 *
 * @see CredentialChain
 * @see Credentials
 */
interface CredentialProviderInterface
{
	/**
	 * The credentials this source holds.
	 *
	 * @return Credentials|null
	 *   The credentials, or NULL when this source has none to offer.
	 */
	public function resolve(): ?Credentials;

	/**
	 * Where the credentials came from.
	 *
	 * Shown on the settings form and in the requirements report, so an operator can tell an
	 * environment variable from an instance role without reading code.
	 *
	 * @return string
	 *   A short human-readable name for the source, naming the profile or path where one applies.
	 */
	public function describe(): string;
}
