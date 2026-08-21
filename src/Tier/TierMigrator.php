<?php

declare(strict_types=1);

namespace Drupal\strata\Tier;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\strata\Cas\FrameIndexInterface;
use Drupal\strata\Cas\FrameRecord;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Cas\PackIndex;
use Drupal\strata\Compaction\Reachability;
use Drupal\strata\Delta\Reanchorer;
use Drupal\strata\Site\SiteContext;
use Drupal\strata\Site\SiteScopedProvider;
use Drupal\strata\Storage\ObjectMeta;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moves history into the tier its age says it belongs in.
 *
 * The only thing that ever writes to a far tier, and it runs on a schedule rather than on the flush
 * path. A far tier therefore takes one class-A request per object that has ever aged into it, which
 * is what makes it cheap enough to be worth having.
 *
 * **Age is the object's own last-modified time in the tier it currently occupies**, read out of the
 * listing this pass has to do anyway. Nothing else about an object is a reliable clock: a frame is
 * shared between commits a year apart, so no single commit dates it, and the reference count says
 * nothing about when. A recompressed pack is a genuinely new object with a new address, so its clock
 * starting again is correct rather than a reset - and recompression leaves a pack alone once it stops
 * getting materially smaller, so a pack does not have its age refreshed forever.
 *
 * **A copy is verified before the nearer one is dropped.** The bytes are read back out of the tier
 * they were just written to and hashed against what was sent. A far tier that accepted a write and
 * holds something else is the one failure a layer of safety must not paper over, so a mismatch drops
 * the placement for that tier, records the problem, and leaves the nearer copy exactly where it was.
 *
 * **A delta parent is never left colder than its child.** A frame whose child still lives in a
 * nearer tier is copied outward but keeps its nearer copy, because a read of the hot child would
 * otherwise have to reach into the cold bucket to decode - and would fail outright while that bucket
 * is down. The report says so per object. Once the child ages out too, a later pass drops the
 * parent's nearer copy. A dependent whose placement is not known yet counts as nearer, so the first
 * pass after a rebuild holds back rather than guessing.
 *
 * **Refs never move.** They are pinned to the nearest tier by TierPolicy, for the reasons stated
 * there.
 *
 * One caveat worth stating rather than hiding. Frames and packs are shared by every site in a
 * bucket, because a frame is addressed by its content and content has no owner. Each site keeps its
 * own placement table, so one site demoting a shared frame moves it out from under another site's
 * hot history; that site's next read finds it in the far tier and records where it went, so nothing
 * breaks, but the read is slower and costs more. A bucket shared between sites should configure
 * every tier to retain the copy below it, which never removes a nearer copy at all.
 *
 * @see TierPolicy
 * @see TieredProvider
 * @see Reanchorer
 */
final class TierMigrator
{
	/**
	 * How many objects to ask for per listing page.
	 */
	public const PAGE = 500;

	/**
	 * Constructs a migrator.
	 *
	 * @param TieredProvider $router
	 *   The store, asked for tier-explicit reads, writes and deletes.
	 * @param TierPolicy $policy
	 *   Decides what belongs where.
	 * @param FrameIndexInterface $index
	 *   Consulted for what deltas against a frame, which is what stops a parent going cold ahead of
	 *   its child.
	 * @param SiteContext $site
	 *   Names the prefix this site's own objects sit under, so a pass over a shared bucket does not
	 *   move another site's commits.
	 * @param TimeInterface $time
	 *   The clock ages are measured against.
	 * @param LoggerInterface $logger
	 *   Records what a pass did.
	 * @param bool $verify
	 *   TRUE to read every copy back and hash it. Off costs one class-B request per object less and
	 *   gives up the only evidence that the far copy is the object it claims to be.
	 */
	public function __construct(
		private readonly TieredProvider $router,
		private readonly TierPolicy $policy,
		private readonly FrameIndexInterface $index,
		private readonly SiteContext $site,
		private readonly TimeInterface $time,
		private readonly LoggerInterface $logger,
		private readonly bool $verify = true,
	) {}

	#region The Pass

	/**
	 * Runs one migration pass.
	 *
	 * @param int $budget
	 *   Most bytes to copy; zero for no limit. A pass that reaches the budget stops where it is and
	 *   says how far it got, because every object it handled is complete on its own.
	 *
	 * @return TierMigrationReport
	 *   What was moved, what was left, and why.
	 */
	public function run(int $budget = 0): TierMigrationReport
	{
		$started = microtime(true);
		$now = $this->time->getRequestTime();

		$examined = 0;
		$copied = 0;
		$moved = 0;
		$bytes = 0;
		$intoTier = [];
		$refused = [];
		$problems = [];

		foreach ($this->sourceTiers() as $from) {
			foreach ($this->roots() as $root) {
				foreach ($this->objects($from, $root, $problems) as $object) {
					if (TierPolicy::isPinned($object->key)) {
						continue;
					}

					$target = $this->promotion($object, $from, $now, $refused);

					if ($target === null) {
						continue;
					}

					$examined++;
					$outcome = $this->promote($from, $target, $object, $refused, $problems);

					if ($outcome === null) {
						continue;
					}

					if ($outcome['copied']) {
						$copied++;
						$bytes += $outcome['bytes'];
						$intoTier[$target] = ($intoTier[$target] ?? 0) + 1;
					}
					if ($outcome['moved']) {
						$moved++;
					}

					if ($budget > 0 && $bytes >= $budget) {
						break 3;
					}
				}
			}
		}

		ksort($intoTier);

		$report = new TierMigrationReport(
			$examined,
			$copied,
			$moved,
			$bytes,
			$intoTier,
			$refused,
			$problems,
			microtime(true) - $started,
		);

		$this->logger->info('Strata %summary', ['%summary' => $report->summary()]);

		return $report;
	}

	#endregion

	#region Candidates

	/**
	 * The tiers a pass can promote out of.
	 *
	 * Every tier except the last, since nothing is further than the last one.
	 *
	 * @return list<int>
	 *   Tier indexes, nearest first.
	 */
	private function sourceTiers(): array
	{
		$tiers = $this->policy->tiers();

		return array_slice($tiers->indexes(), 0, max(0, $tiers->count() - 1));
	}

	/**
	 * The key prefixes a pass walks.
	 *
	 * This site's own namespace plus the prefixes every site in the bucket shares. Listing the whole
	 * bucket instead would move another site's commits on this site's schedule.
	 *
	 * @return list<string>
	 *   Prefixes.
	 */
	private function roots(): array
	{
		return [$this->site->prefix(), ...SiteScopedProvider::SHARED_PREFIXES];
	}

	/**
	 * Every object under one prefix in one tier.
	 *
	 * @param int $tier
	 *   Tier index.
	 * @param string $prefix
	 *   Key prefix.
	 * @param list<string> $problems
	 *   Collects a line when the listing itself fails.
	 *
	 * @return list<ObjectMeta>
	 *   The objects.
	 */
	private function objects(int $tier, string $prefix, array &$problems): array
	{
		$objects = [];
		$cursor = null;

		do {
			try {
				$page = $this->router->listIn($tier, $prefix, $cursor, self::PAGE);
			} catch (Throwable $error) {
				$problems[] = sprintf(
					'tier "%s" could not be listed under %s: %s',
					$this->name($tier),
					$prefix,
					$error->getMessage(),
				);

				return $objects;
			}

			$objects = [...$objects, ...$page->objects];
			$cursor = $page->cursor;
		} while ($page->hasMore());

		return $objects;
	}

	/**
	 * The tier one object should be promoted into, if any.
	 *
	 * @param ObjectMeta $object
	 *   The object as the listing described it.
	 * @param int $from
	 *   Tier it is in now.
	 * @param int $now
	 *   Unix seconds.
	 * @param list<string> $refused
	 *   Collects a line when the object cannot be aged.
	 *
	 * @return int|null
	 *   The target tier index, or NULL when the object stays where it is.
	 */
	private function promotion(ObjectMeta $object, int $from, int $now, array &$refused): ?int
	{
		if ($object->modified === null) {
			$refused[] = sprintf(
				'%s: the store reports no modification time, so its age is unknown',
				$object->key,
			);

			return null;
		}

		return $this->policy->promotionFor($object->key, $from, max(0, $now - $object->modified));
	}

	#endregion

	#region Promotion

	/**
	 * Copies one object outward and decides whether the nearer copy goes.
	 *
	 * @param int $from
	 *   Tier it is in now.
	 * @param int $target
	 *   Tier it belongs in.
	 * @param ObjectMeta $object
	 *   The object.
	 * @param list<string> $refused
	 *   Collects a line when the nearer copy is kept deliberately.
	 * @param list<string> $problems
	 *   Collects a line when a copy could not be made or verified.
	 *
	 * @return array{copied: bool, moved: bool, bytes: int}|null
	 *   What happened, or NULL when there was nothing left to do.
	 */
	private function promote(
		int $from,
		int $target,
		ObjectMeta $object,
		array &$refused,
		array &$problems,
	): ?array {
		$placement = $this->router->placement()->get($object->key);
		$needsCopy = $placement === null || !$placement->has($target);
		$mayDemote = !$this->policy->retainsBelow($target);

		if (!$needsCopy && !$mayDemote) {
			return null;
		}

		try {
			$body = $this->router->getFrom($from, $object->key);
		} catch (Throwable $error) {
			$problems[] = sprintf('%s: %s', $object->key, $error->getMessage());

			return null;
		}

		$copied = false;

		if ($needsCopy) {
			if (!$this->copy($target, $object->key, $body, $problems)) {
				return null;
			}

			$copied = true;
		}

		if (!$mayDemote) {
			return ['copied' => $copied, 'moved' => false, 'bytes' => strlen($body)];
		}

		$holdout = $this->nearerDependent($object->key, $body, $target);

		if ($holdout !== null) {
			$refused[] = sprintf(
				'%s: kept in tier "%s" because %s',
				$object->key,
				$this->name($from),
				$holdout,
			);

			return ['copied' => $copied, 'moved' => false, 'bytes' => strlen($body)];
		}

		try {
			$this->router->deleteFrom($from, $object->key);
		} catch (Throwable $error) {
			$problems[] = sprintf(
				'%s: copied into tier "%s" but the nearer copy could not be removed: %s',
				$object->key,
				$this->name($target),
				$error->getMessage(),
			);

			return ['copied' => $copied, 'moved' => false, 'bytes' => strlen($body)];
		}

		return ['copied' => $copied, 'moved' => true, 'bytes' => strlen($body)];
	}

	/**
	 * Writes one object into a tier and proves it arrived intact.
	 *
	 * @param int $target
	 *   Tier index.
	 * @param string $key
	 *   Object key.
	 * @param string $body
	 *   The bytes.
	 * @param list<string> $problems
	 *   Collects a line when the write or the check fails.
	 *
	 * @return bool
	 *   TRUE when the copy is present and verified.
	 */
	private function copy(int $target, string $key, string $body, array &$problems): bool
	{
		try {
			$result = $this->router->putIn($target, $key, $body);
		} catch (Throwable $error) {
			$problems[] = sprintf(
				'%s: could not be copied into tier "%s": %s',
				$key,
				$this->name($target),
				$error->getMessage(),
			);

			return false;
		}

		if ($result->size !== strlen($body)) {
			$this->reject(
				$target,
				$key,
				sprintf(
					'%d bytes were sent and the endpoint stored %d',
					strlen($body),
					$result->size,
				),
				$problems,
			);

			return false;
		}
		if (!$this->verify) {
			return true;
		}

		try {
			$readback = $this->router->getFrom($target, $key);
		} catch (Throwable $error) {
			$this->reject($target, $key, $error->getMessage(), $problems);

			return false;
		}

		if (!Hash::equals(Hash::of($readback), Hash::of($body))) {
			$this->reject($target, $key, 'the copy does not hash to what was sent', $problems);

			return false;
		}

		return true;
	}

	/**
	 * Un-records a copy that cannot be trusted.
	 *
	 * The object itself is left where it landed rather than deleted: a far tier that answered a write
	 * with something else is not a tier a delete should be aimed at on the strength of the same
	 * exchange, and the placement row is what would have made anything read it.
	 *
	 * @param int $target
	 *   Tier index.
	 * @param string $key
	 *   Object key.
	 * @param string $reason
	 *   What went wrong.
	 * @param list<string> $problems
	 *   Collects the line.
	 */
	private function reject(int $target, string $key, string $reason, array &$problems): void
	{
		$this->router->placement()->displace($key, $target);

		$problems[] = sprintf(
			'%s: the copy in tier "%s" was not accepted, %s',
			$key,
			$this->name($target),
			$reason,
		);
	}

	#endregion

	#region Delta Chains

	/**
	 * Whether anything that decodes against this object still lives nearer than the target tier.
	 *
	 * @param string $key
	 *   Object key.
	 * @param string $body
	 *   The object's bytes, so a pack's own directory answers which frames it holds.
	 * @param int $target
	 *   Tier the object is being promoted into.
	 *
	 * @return string|null
	 *   Why the nearer copy has to stay, or NULL when nothing needs it there.
	 */
	private function nearerDependent(string $key, string $body, int $target): ?string
	{
		foreach ($this->framesIn($key, $body) as $hash) {
			foreach ($this->index->dependents($hash, Reachability::DEPENDENT_LIMIT) as $dependent) {
				$at = $this->router->placement()->get($this->objectKeyFor($dependent))?->nearest();

				if ($at === null) {
					return sprintf(
						'delta frame %s decodes against %s and where it lives is not known yet',
						Hash::abbreviate($dependent->hash),
						Hash::abbreviate($hash),
					);
				}
				if ($at < $target) {
					return sprintf(
						'delta frame %s decodes against %s and is still in tier "%s"',
						Hash::abbreviate($dependent->hash),
						Hash::abbreviate($hash),
						$this->name($at),
					);
				}
			}
		}

		return null;
	}

	/**
	 * The frame addresses one object holds.
	 *
	 * Only frames carry a delta parent, so a commit, an anchor, a segment, a dictionary or a media
	 * block answers with nothing and skips the whole check.
	 *
	 * @param string $key
	 *   Object key.
	 * @param string $body
	 *   The object's bytes.
	 *
	 * @return list<string>
	 *   Frame content addresses.
	 */
	private function framesIn(string $key, string $body): array
	{
		if (self::isUnder($key, ObjectStore::FRAME_PREFIX)) {
			$hash = basename($key);

			return Hash::isValid($hash) ? [$hash] : [];
		}
		if (!self::isUnder($key, ObjectStore::PACK_PREFIX)) {
			return [];
		}

		try {
			$entries = PackIndex::decode($key, $body);
		} catch (Throwable) {
			// a pack whose directory will not read cannot be shown safe to demote, so it is not
			return [];
		}

		$hashes = [];

		foreach ($entries as $entry) {
			$hashes[] = (string) $entry['hash'];
		}

		return $hashes;
	}

	/**
	 * The object key one frame record lives at.
	 *
	 * @param FrameRecord $record
	 *   The record.
	 *
	 * @return string
	 *   The key of the pack holding it, or of its own object.
	 */
	private function objectKeyFor(FrameRecord $record): string
	{
		return $record->isPacked()
			? Hash::key((string) $record->pack, ObjectStore::PACK_PREFIX)
			: Hash::key($record->hash, ObjectStore::FRAME_PREFIX);
	}

	#endregion

	/**
	 * One tier's name.
	 *
	 * @param int $index
	 *   Tier index.
	 *
	 * @return string
	 *   The name, or the index when the ladder no longer has that tier.
	 */
	private function name(int $index): string
	{
		return $this->policy->tiers()->has($index)
			? $this->policy->tiers()->at($index)->name()
			: (string) $index;
	}

	/**
	 * Whether a key sits under a prefix, scoped or not.
	 *
	 * @param string $key
	 *   Object key.
	 * @param string $prefix
	 *   Key prefix without slashes.
	 *
	 * @return bool
	 *   TRUE when it does.
	 */
	private static function isUnder(string $key, string $prefix): bool
	{
		return str_starts_with($key, $prefix . '/') || str_contains($key, '/' . $prefix . '/');
	}
}
