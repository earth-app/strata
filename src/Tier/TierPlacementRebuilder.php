<?php

declare(strict_types=1);

namespace Drupal\strata\Tier;

use Drupal\strata\Verify\Reindexer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rebuilds the placement index by listing every tier.
 *
 * The placement table is a cache over which bucket holds which key, so it has to be reproducible
 * from the buckets alone - an uninstall drops it, a restore into a fresh site starts without it, and
 * a stale row is faster to rebuild than to repair. All three are this operation, and it is the reason
 * the tier is never part of an object's name: the answer is recoverable by looking, and a name that
 * encoded it would have to be rewritten instead.
 *
 * Every tier is listed independently, so an object present in two of them ends up recorded in both
 * rather than in whichever answered first.
 *
 * A tier that cannot be listed is reported and its rows are left alone rather than cleared. Clearing
 * them would turn an unreachable bucket into an empty one, and the whole point of the placement
 * record is to be able to say where something is even while the bucket holding it is down.
 *
 * @see PlacementIndexInterface
 * @see Reindexer
 */
final class TierPlacementRebuilder
{
	/**
	 * How many keys to ask for per listing page.
	 */
	public const PAGE = 1000;

	/**
	 * Constructs a rebuilder.
	 *
	 * @param TieredProvider $router
	 *   The store, listed one tier at a time.
	 * @param PlacementIndexInterface $placement
	 *   The index being rebuilt.
	 * @param LoggerInterface $logger
	 *   Records progress, since a full rebuild over several buckets is not quick.
	 */
	public function __construct(
		private readonly TieredProvider $router,
		private readonly PlacementIndexInterface $placement,
		private readonly LoggerInterface $logger,
	) {}

	/**
	 * Lists every tier and records what each one holds.
	 *
	 * @param bool $fresh
	 *   TRUE to drop the table first, which is the only way a row for an object that has moved goes
	 *   away. FALSE tops up an index that is known to be merely incomplete. A fresh rebuild is
	 *   downgraded to a top-up when any tier could not be listed, because clearing rows for a bucket
	 *   nobody could read would report it as empty.
	 * @param list<string> $problems
	 *   Collects one line per tier that could not be listed.
	 *
	 * @return array{objects: int, tiers: int}
	 *   How many placements were recorded and how many tiers were read.
	 */
	public function rebuild(bool $fresh = true, array &$problems = []): array
	{
		$started = microtime(true);
		$pages = [];
		$read = 0;

		foreach ($this->router->tiers()->all() as $tier) {
			try {
				$pages[$tier->index] = $this->keysIn($tier->index);
				$read++;
			} catch (Throwable $error) {
				$problems[] = sprintf(
					'tier "%s" could not be listed, so what it holds is unchanged: %s',
					$tier->name(),
					$error->getMessage(),
				);
			}
		}

		if ($fresh && $read === $this->router->tiers()->count()) {
			$this->placement->clear();
		}

		$recorded = 0;

		foreach ($pages as $index => $sizes) {
			foreach ($sizes as $key => $bytes) {
				$this->placement->place((string) $key, $index, $bytes);
				$recorded++;
			}
		}

		$this->logger->info('Strata %summary', [
			'%summary' => sprintf(
				'rebuilt %d placements across %d of %d tiers in %.2fs',
				$recorded,
				$read,
				$this->router->tiers()->count(),
				microtime(true) - $started,
			),
		]);

		return ['objects' => $recorded, 'tiers' => $read];
	}

	/**
	 * Every key one tier holds, with its size.
	 *
	 * The whole tier rather than one site's prefix: a placement row describes where an object is, and
	 * an object shared between sites is in one place whoever asks.
	 *
	 * @param int $index
	 *   Tier index.
	 *
	 * @return array<string, int>
	 *   Object key keyed to its size.
	 */
	private function keysIn(int $index): array
	{
		$keys = [];
		$cursor = null;

		do {
			$page = $this->router->listIn($index, '', $cursor, self::PAGE);

			foreach ($page->objects as $object) {
				$keys[$object->key] = $object->size;
			}

			$cursor = $page->cursor;
		} while ($page->hasMore());

		return $keys;
	}
}
