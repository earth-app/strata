<?php

declare(strict_types=1);

namespace Drupal\strata\Tier;

use Drupal\strata\Verify\Verifier;

/**
 * Asks a store which of its tiers can be reached.
 *
 * A verify pass has to be able to report a tier it could not read, and the alternative to a contract
 * like this one is a class check on the provider - which the engine deliberately never does, because
 * a provider is chosen by configuration and a branch on its class makes an add-on a special case.
 *
 * A store with one destination answers with one reachable tier, so a caller holding this needs no
 * branch of its own.
 *
 * @see TieredProvider
 * @see Verifier
 */
interface TierStatusInterface
{
	/**
	 * Why each tier cannot be reached, if it cannot.
	 *
	 * Probing is what a status page and a verify pass need, so it must not throw: a tier that cannot
	 * even be built is reported as unreachable with the reason rather than taking the caller down.
	 *
	 * @return array<int, string|null>
	 *   Tier index keyed to the reason it is unreachable, or NULL when it is reachable.
	 */
	public function tierStatus(): array;

	/**
	 * Tier names keyed by index.
	 *
	 * @return array<int, string>
	 *   Names, so a finding can say "the year bucket" rather than "tier 3".
	 */
	public function tierNames(): array;
}
