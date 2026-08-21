<?php

declare(strict_types=1);

namespace Drupal\strata_gcs\Credentials;

use RuntimeException;

/**
 * Where the bearer token on a Cloud Storage request comes from.
 *
 * Two sources, and both are first-class. A **service account** signs its own JWT and exchanges it
 * for an access token, which is what a site running anywhere gets. A **pre-issued token** is handed
 * in by the platform, which is what workload identity on GKE does and what a test does.
 *
 * @see ServiceAccountCredentials
 * @see StaticAccessToken
 */
interface AccessTokenProviderInterface
{
	/**
	 * A token to put in the Authorization header.
	 *
	 * Cached until shortly before it expires, so a request that follows another does not pay for a
	 * second exchange.
	 *
	 * @return string
	 *   The access token.
	 *
	 * @throws RuntimeException
	 *   When no token can be obtained.
	 */
	public function token(): string;

	/**
	 * Where the token comes from, for a message that must not print the token.
	 *
	 * @return string
	 *   A short description.
	 */
	public function describe(): string;
}
