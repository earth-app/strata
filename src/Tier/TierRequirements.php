<?php

declare(strict_types=1);

namespace Drupal\strata\Tier;

use Drupal\strata\Cas\FrameIndexInterface;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Compaction\Reachability;
use Drupal\strata\Restore\Preflight;
use Drupal\strata\Site\SiteContext;
use Drupal\strata\Site\SiteScopedProvider;
use Drupal\strata\Tree\BaseManifest;
use Drupal\strata\Tree\BaseReader;
use Drupal\strata\Tree\CommitLog;
use Throwable;

/**
 * Works out which buckets a restore has to read before it reads any of them.
 *
 * Preflight already proves what a restore can put back, and it proves it by materialising every
 * subject - which means by the time it has an answer it has already read the cold bucket. This runs
 * first and answers a smaller question from the placement index: which tiers hold the objects that
 * replay is going to want. It reads the commits and the anchor chain, which are small, and looks the
 * frames up locally rather than fetching them.
 *
 * An object with no placement row is counted and never guessed at. While there is one, every tier is
 * reported as possibly needed.
 *
 * @see TierRequirementReport
 * @see Preflight
 */
final class TierRequirements
{
	/**
	 * How many frames one walk looks up before it stops and says it was bounded.
	 *
	 * A full anchor names every subject on the site, and the question here has at most a handful of
	 * possible answers, so reading the whole index to reach one of them is waste. The walk also stops
	 * as soon as every tier is required, because nothing further can change the answer.
	 */
	public const FRAME_LIMIT = 5000;

	/**
	 * Constructs a requirements pass.
	 *
	 * @param TieredProvider $router
	 *   The store, asked which tiers can be reached.
	 * @param PlacementIndexInterface $placement
	 *   Where each object is.
	 * @param CommitLog $commits
	 *   Resolves the replay path.
	 * @param BaseReader $bases
	 *   Resolves the anchor chain and the frames it names.
	 * @param FrameIndexInterface $index
	 *   Maps a frame address to the object holding it, and to its delta parent.
	 * @param SiteContext $site
	 *   Applies the same key scoping the provider does, so a placement lookup uses the key the bucket
	 *   actually holds.
	 */
	public function __construct(
		private readonly TieredProvider $router,
		private readonly PlacementIndexInterface $placement,
		private readonly CommitLog $commits,
		private readonly BaseReader $bases,
		private readonly FrameIndexInterface $index,
		private readonly SiteContext $site,
	) {}

	/**
	 * Which tiers a restore to one commit needs.
	 *
	 * @param string $target
	 *   Commit id to replay to.
	 * @param int $limit
	 *   Most frames to look up before the walk reports itself bounded.
	 *
	 * @return TierRequirementReport
	 *   The answer.
	 */
	public function require(string $target, int $limit = self::FRAME_LIMIT): TierRequirementReport
	{
		$started = microtime(true);
		$problems = [];
		$counts = [];
		$objects = 0;
		$unplaced = 0;
		$bounded = false;

		foreach ($this->objectKeys($target, $limit, $problems, $bounded) as $key) {
			$objects++;
			$tiers = $this->placement->get($key)?->tiers() ?? [];

			if ($tiers === []) {
				$unplaced++;

				continue;
			}

			foreach ($tiers as $tier) {
				$counts[$tier] = ($counts[$tier] ?? 0) + 1;
			}
		}

		// an object nobody can place could be in any bucket, so every bucket is named
		if ($unplaced > 0) {
			foreach ($this->router->tiers()->indexes() as $index) {
				$counts[$index] ??= 0;
			}
		}

		ksort($counts);

		return new TierRequirementReport(
			$target,
			$this->describe($counts),
			$objects,
			$unplaced,
			$bounded,
			$problems,
			microtime(true) - $started,
		);
	}

	#region The Walk

	/**
	 * Every object key a replay to a commit reads.
	 *
	 * @param string $target
	 *   Commit id.
	 * @param int $limit
	 *   Most frames to resolve.
	 * @param list<string> $problems
	 *   Collects one line per object the walk could not resolve.
	 * @param bool $bounded
	 *   Set to TRUE when the walk stopped early.
	 *
	 * @return list<string>
	 *   Object keys, deduplicated, scoped exactly as the bucket holds them.
	 */
	private function objectKeys(string $target, int $limit, array &$problems, bool &$bounded): array
	{
		$keys = [];

		try {
			$path = $this->commits->replayPath($target);
		} catch (Throwable $error) {
			$problems[] = sprintf('the replay path does not resolve: %s', $error->getMessage());

			return [];
		}

		$anchors = [];

		foreach ([$path['anchor'], ...$path['path']] as $id) {
			$keys[$this->scope(Hash::key($id, CommitLog::PREFIX))] = true;

			try {
				$commit = $this->commits->read($id);
			} catch (Throwable $error) {
				$problems[] = sprintf('commit %s: %s', Hash::abbreviate($id), $error->getMessage());

				continue;
			}

			// commits between two anchors name the same one, so this set is far smaller than the path
			$anchors[$commit->index] = true;
			$segment = $commit->metadata['segment'] ?? null;

			if (is_string($segment) && $segment !== '') {
				$keys[$this->scope($segment)] = true;
			}
		}

		$seen = 0;

		foreach (array_keys($anchors) as $anchor) {
			$this->addAnchor((string) $anchor, $keys, $limit, $seen, $problems, $bounded);

			if ($bounded) {
				break;
			}
		}

		return array_keys($keys);
	}

	/**
	 * Adds an anchor chain and the objects holding the frames it names.
	 *
	 * @param string $anchor
	 *   Anchor address.
	 * @param array<string, true> $keys
	 *   Collected keys, added to by reference.
	 * @param int $limit
	 *   Most frames to resolve.
	 * @param int $seen
	 *   Frames resolved so far, counted across every anchor rather than per anchor.
	 * @param list<string> $problems
	 *   Collects one line per object the walk could not resolve.
	 * @param bool $bounded
	 *   Set to TRUE when the walk stopped early.
	 */
	private function addAnchor(
		string $anchor,
		array &$keys,
		int $limit,
		int &$seen,
		array &$problems,
		bool &$bounded,
	): void {
		try {
			foreach ($this->bases->chain($anchor) as $link) {
				$keys[$this->scope(Hash::key($link, BaseManifest::PREFIX))] = true;
			}

			$frames = $this->bases->frames($anchor);
		} catch (Throwable $error) {
			$problems[] = sprintf('anchor %s: %s', Hash::abbreviate($anchor), $error->getMessage());

			return;
		}

		foreach ($frames as $frame) {
			if ($seen >= $limit) {
				$bounded = true;

				return;
			}

			$seen++;
			$this->addFrame($frame, $keys);
		}
	}

	/**
	 * Adds the object holding one frame, and the objects holding its delta chain.
	 *
	 * A frame the index does not know is not added at all. It would be a guess about which bucket
	 * holds an object nothing can locate, and Preflight is the pass that reports that as
	 * unrestorable.
	 *
	 * @param string $hash
	 *   Frame content address.
	 * @param array<string, true> $keys
	 *   Collected keys, added to by reference.
	 */
	private function addFrame(string $hash, array &$keys): void
	{
		$at = $hash;
		$depth = 0;

		while ($depth++ < Reachability::CHAIN_LIMIT) {
			$record = $this->index->get($at);

			if ($record === null) {
				return;
			}

			$key = $record->isPacked()
				? Hash::key((string) $record->pack, ObjectStore::PACK_PREFIX)
				: Hash::key($at, ObjectStore::FRAME_PREFIX);

			$keys[$key] = true;

			if ($record->deltaParent === null) {
				return;
			}

			$at = $record->deltaParent;
		}
	}

	#endregion

	/**
	 * Turns per-tier object counts into the report's rows.
	 *
	 * @param array<int, int> $counts
	 *   Tier index keyed to how many needed objects it holds.
	 *
	 * @return array<int, array{name: string, objects: int, reachable: bool, reason: string|null}>
	 *   The rows.
	 */
	private function describe(array $counts): array
	{
		$names = $this->router->tierNames();
		$status = $this->router->tierStatus();
		$rows = [];

		foreach ($counts as $index => $objects) {
			// `??` would be wrong here: a reachable tier reports NULL, which is a value and not a gap
			$reason = array_key_exists($index, $status)
				? $status[$index]
				: 'that tier is no longer on the ladder';

			$rows[$index] = [
				'name' => $names[$index] ?? (string) $index,
				'objects' => $objects,
				'reachable' => $reason === null,
				'reason' => $reason,
			];
		}

		return $rows;
	}

	/**
	 * A key as the bucket holds it.
	 *
	 * Frames, packs, media blocks and dictionaries are shared between the sites in a bucket and carry
	 * no site prefix; everything else does. Applying the wrong rule would look up a key nothing has
	 * a placement row for and report every object as unplaced.
	 *
	 * @param string $key
	 *   The key as the engine composes it.
	 *
	 * @return string
	 *   The key as it appears in a listing.
	 */
	private function scope(string $key): string
	{
		return SiteScopedProvider::isShared($key) ? $key : $this->site->key($key);
	}
}
