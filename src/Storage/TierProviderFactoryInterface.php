<?php

declare(strict_types=1);

namespace Drupal\strata\Storage;

use Drupal\strata\Tier\TierTarget;
use RuntimeException;

/**
 * A provider factory that can build the same endpoint pointed at a second location.
 *
 * Tiered storage needs several buckets from one set of credentials. Rather than asking a site to
 * configure a whole endpoint per tier - the same URL, region and keys typed four times - a factory
 * that implements this takes the one thing that differs and builds a provider for it.
 *
 * Separate from StorageProviderFactoryInterface rather than added to it, because a provider with
 * exactly one possible location is a legitimate implementation and should not be forced to declare
 * a method it cannot honour. A factory that does not implement this can still serve the nearest
 * tier; it just cannot serve a second one, and Engine says so rather than writing two tiers into
 * one bucket.
 *
 * @see StorageProviderFactoryInterface
 * @see TierTarget
 */
interface TierProviderFactoryInterface extends StorageProviderFactoryInterface
{
	/**
	 * Builds a provider for one tier's location.
	 *
	 * @param TierTarget $target
	 *   The tier's bucket and storage class. A target whose location is empty is built exactly as
	 *   StorageProviderFactoryInterface::create() would build it, so the nearest tier of a
	 *   single-bucket site is the same object either way.
	 *
	 * @return StorageProviderInterface
	 *   The provider.
	 *
	 * @throws RuntimeException
	 *   When the endpoint is not configured well enough to address that location.
	 */
	public function createFor(TierTarget $target): StorageProviderInterface;
}
