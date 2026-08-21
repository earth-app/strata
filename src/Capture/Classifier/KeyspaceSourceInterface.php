<?php

declare(strict_types=1);

namespace Drupal\strata\Capture\Classifier;

use RuntimeException;
use Traversable;

/**
 * Somewhere ephemeral keys can be enumerated from.
 *
 * What holds the keyspace differs per site - Redis when `strata_redis` is installed, the cache and
 * key-value tables otherwise, something else entirely on a site with a custom backend - so discovery
 * asks sources rather than knowing about backends. A submodule contributes one by tagging a service.
 *
 * Two rules bind every implementation:
 *
 * - **It must be bounded.** A source over a Redis holding ten million keys has to stop at the limit
 *   it is given rather than reading all of them into a discovery pass. Sampling is fine and is
 *   expected; the pattern totals are used to rank what needs a decision, not to bill anyone.
 * - **It must not block on a backend that is down.** A source whose backend is unreachable raises,
 *   and discovery reports that source as unread and carries on with the others.
 *
 * @see KeyspaceDiscovery
 */
interface KeyspaceSourceInterface
{
	/**
	 * A short identifier, used to name this source in a problem report.
	 *
	 * @return string
	 *   For example "redis" or "database".
	 */
	public function id(): string;

	/**
	 * Whether this source can be read right now.
	 *
	 * @return bool
	 *   FALSE when the backend it reads is absent or unreachable, so discovery can skip it without
	 *   treating that as a fault.
	 */
	public function isAvailable(): bool;

	/**
	 * The keys this source holds, with their sizes.
	 *
	 * @param int $limit
	 *   Most keys to return.
	 *
	 * @return Traversable<string, int>
	 *   Key name keyed to its size in bytes. A source that cannot measure a size yields zero rather
	 *   than guessing, and the byte totals are then understood to be a lower bound.
	 *
	 * @throws RuntimeException
	 *   When the backend cannot be read at all.
	 */
	public function keys(int $limit): Traversable;
}
