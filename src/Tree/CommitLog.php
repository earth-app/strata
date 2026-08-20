<?php

declare(strict_types=1);

namespace Drupal\strata\Tree;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Storage\StorageProviderInterface;
use Generator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reads and appends the history a restore walks.
 *
 * Commits are immutable objects addressed by their own content; refs are the mutable names that
 * say where a line of history ends. Appending a commit writes the object and then advances the
 * ref, in that order, so a crash between the two leaves an unreferenced commit rather than a ref
 * pointing at nothing. An unreferenced commit is collected by a later prune; a dangling ref would
 * make the whole history unreadable.
 *
 * @see Commit
 * @see RefStore
 * @see TreeBuilder
 */
final class CommitLog
{
	/**
	 * Key prefix commits are written under.
	 */
	public const PREFIX = 'commits';

	/**
	 * Commits read during this request, keyed by id.
	 *
	 * A walk revisits the same commit whenever two callers ask about overlapping ranges, and a
	 * commit is immutable, so caching it for the request is always safe.
	 *
	 * @var array<string, Commit>
	 */
	private array $cache = [];

	/**
	 * Constructs a log.
	 *
	 * @param StorageProviderInterface $provider
	 *   Where commits are written.
	 * @param RefStore $refs
	 *   The ref store holding the tips.
	 */
	public function __construct(
		private readonly StorageProviderInterface $provider,
		private readonly RefStore $refs,
	) {}

	#region Reading

	/**
	 * The commit a ref points at.
	 *
	 * @param string $ref
	 *   Ref name.
	 *
	 * @return Commit|null
	 *   The commit, or NULL when the ref does not exist yet.
	 *
	 * @throws RuntimeException
	 *   When the ref points at a commit that is absent or corrupt.
	 */
	public function head(string $ref = RefStore::MAIN): ?Commit
	{
		$id = $this->refs->read($ref);

		return $id === null ? null : $this->read($id);
	}

	/**
	 * Reads one commit.
	 *
	 * @param string $id
	 *   The commit id.
	 *
	 * @return Commit
	 *   The commit.
	 *
	 * @throws InvalidArgumentException
	 *   When the id is not a valid digest.
	 * @throws RuntimeException
	 *   When the commit is absent, or its content does not hash to its id.
	 */
	public function read(string $id): Commit
	{
		if (!Hash::isValid($id)) {
			throw new InvalidArgumentException('A commit id must be a valid digest');
		}
		if (isset($this->cache[$id])) {
			return $this->cache[$id];
		}

		$bytes = $this->provider->get(Hash::key($id, self::PREFIX));

		if (!Hash::equals(Hash::of($bytes), $id)) {
			throw new RuntimeException(
				sprintf('Commit %s does not match its id', Hash::abbreviate($id)),
			);
		}

		/** @var array<string, mixed> $decoded */
		$decoded = json_decode($bytes, true) ?? [];

		return $this->cache[$id] = Commit::fromArray($decoded);
	}

	/**
	 * Whether a commit is present.
	 *
	 * @param string $id
	 *   The commit id.
	 *
	 * @return bool
	 *   TRUE when the object exists.
	 */
	public function exists(string $id): bool
	{
		return isset($this->cache[$id]) || $this->provider->exists(Hash::key($id, self::PREFIX));
	}

	/**
	 * Walks history backwards from a commit.
	 *
	 * @param string $from
	 *   Commit id to start at.
	 * @param int|null $limit
	 *   Stop after this many commits, or NULL to walk to the root.
	 *
	 * @return Generator<string, Commit>
	 *   Commit id keyed to the commit, newest first.
	 *
	 * @throws RuntimeException
	 *   When a commit in the chain is absent or corrupt.
	 */
	public function walk(string $from, ?int $limit = null): Generator
	{
		$id = $from;
		$seen = 0;

		while ($id !== null && ($limit === null || $seen < $limit)) {
			$commit = $this->read($id);

			yield $id => $commit;

			$seen++;
			$id = $commit->parent;
		}
	}

	/**
	 * The nearest anchor at or before a commit, and the commits between.
	 *
	 * A replay starts from an anchor and applies what follows, so this is the shape of a restore's
	 * work. The returned depth is what the UI shows as the cost of a restore point.
	 *
	 * @param string $target
	 *   Commit id to reach.
	 *
	 * @return array{anchor: string, path: list<string>, depth: int}
	 *   The anchor's id, the commits from just after the anchor up to and including the target in
	 *   oldest-first order, and how many commits must be replayed.
	 *
	 * @throws RuntimeException
	 *   When no anchor is reachable, which means the root of history is missing.
	 */
	public function replayPath(string $target): array
	{
		$path = [];
		$id = $target;

		while (true) {
			$commit = $this->read($id);

			if ($commit->isAnchor()) {
				return [
					'anchor' => $id,
					'path' => array_reverse($path),
					'depth' => count($path),
				];
			}

			$path[] = $id;
			$parent = $commit->parent;

			if ($parent === null) {
				throw new RuntimeException(
					sprintf(
						'Commit %s has no parent and is not an anchor, so history has no root',
						Hash::abbreviate($id),
					),
				);
			}

			$id = $parent;
		}
	}

	/**
	 * The commit in force at a moment in time.
	 *
	 * Walks back from a ref until it finds the newest commit at or before the given time, which is
	 * what "restore to 14:32:06" resolves to.
	 *
	 * @param int $microtime
	 *   Unix microseconds.
	 * @param string $ref
	 *   Ref name to walk from.
	 *
	 * @return Commit|null
	 *   The commit, or NULL when history does not reach that far back.
	 *
	 * @throws RuntimeException
	 *   When a commit in the chain is absent or corrupt.
	 */
	public function at(int $microtime, string $ref = RefStore::MAIN): ?Commit
	{
		$id = $this->refs->read($ref);

		if ($id === null) {
			return null;
		}

		foreach ($this->walk($id) as $commit) {
			if ($commit->microtime <= $microtime) {
				return $commit;
			}
		}

		return null;
	}

	#endregion

	#region Appending

	/**
	 * Writes a commit and advances a ref to it.
	 *
	 * The commit's parent must be whatever the ref currently points at, so a caller that built on a
	 * stale head is told rather than silently discarding the commits in between.
	 *
	 * @param Commit $commit
	 *   The commit to append.
	 * @param string $ref
	 *   Ref name to advance.
	 *
	 * @return string
	 *   The commit id.
	 *
	 * @throws RuntimeException
	 *   When the commit's parent is not the ref's current tip, or the write fails.
	 */
	public function append(Commit $commit, string $ref = RefStore::MAIN): string
	{
		$tip = $this->refs->read($ref);

		if ($commit->parent !== $tip) {
			throw new RuntimeException(
				sprintf(
					'Commit builds on %s but %s points at %s',
					$commit->parent === null ? 'no parent' : Hash::abbreviate($commit->parent),
					$ref,
					$tip === null ? 'nothing' : Hash::abbreviate($tip),
				),
			);
		}

		$id = $this->write($commit);

		if (!$this->refs->compareAndSet($tip, $id, $ref)) {
			throw new RuntimeException(
				sprintf('Another writer advanced %s while this commit was being written', $ref),
			);
		}

		return $id;
	}

	/**
	 * Writes a commit object without touching any ref.
	 *
	 * Used by compaction, which writes a rolled-up commit before deciding which ref should point at
	 * it, and by a restore, which writes its pre-rollback snapshot before moving anything.
	 *
	 * @param Commit $commit
	 *   The commit.
	 *
	 * @return string
	 *   The commit id.
	 *
	 * @throws RuntimeException
	 *   When the write fails.
	 */
	public function write(Commit $commit): string
	{
		$bytes = (string) json_encode($commit);
		$id = Hash::of($bytes);
		$key = Hash::key($id, self::PREFIX);

		// a commit is addressed by its content, so an existing object is already this commit
		if (!$this->provider->exists($key)) {
			$this->provider->put($key, $bytes);
		}

		$this->cache[$id] = $commit;

		return $id;
	}

	#endregion

	/**
	 * Forgets cached commits.
	 *
	 * Called by a long-running command between batches so the cache does not grow with the walk.
	 */
	public function flushCache(): void
	{
		$this->cache = [];
	}
}
