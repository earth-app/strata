<?php

declare(strict_types=1);

namespace Drupal\strata\Branch;

use Closure;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Tree\CommitIndex;
use RuntimeException;

/**
 * Finds the commit two lines of history last agreed at.
 *
 * A three-way merge is only meaningful against a common ancestor: without one there is no way to
 * tell a value somebody changed from a value that was always there, and every difference would read
 * as a conflict. So this runs first, and a merge with no base refuses rather than falling back to
 * comparing two tips against each other.
 *
 * Parents are walked through CommitIndex rather than through CommitLog, so the walk is queries
 * against a local table instead of one ranged GET per commit. That is what makes it affordable to
 * walk thousands of commits to answer a question an operator asked interactively.
 *
 * **The walk is bounded twice and says which bound it hit.** A ceiling stops it after a fixed number
 * of commits, because a branch cut a year ago against a busy trunk is a walk nobody wants to wait
 * for and "this is further back than I will look" is a usable answer. A seen set stops it on a chain
 * that revisits a commit, which cannot happen in a content-addressed history and therefore means the
 * index is corrupt - that one is refused outright rather than reported as a distance, because
 * carrying on would produce a base that is not one.
 *
 * @see ThreeWayMerge
 * @see Merger
 */
final class MergeBase
{
	/**
	 * How many commits a walk reads before it gives up.
	 *
	 * Counted per side, so the worst case is twice this many rows. Chosen to be far longer than any
	 * branch an operator would merge by hand and far shorter than a walk that would time out a
	 * request.
	 */
	public const DEFAULT_CEILING = 10000;

	/**
	 * Constructs a merge base finder.
	 *
	 * The lookup is a callable rather than the index itself, and on a real site it is
	 * `CommitIndex::parentsOf(...)`. What this needs is one question answered - what does this commit
	 * build on - and taking the answer rather than the table is what lets the walk be driven over a
	 * synthetic chain with no database behind it, which is the only way a diamond and a corrupt chain
	 * can be tested at all.
	 *
	 * @param Closure(string): (list<string>|null) $parentsOf
	 *   Answers what one commit builds on, first parent first, or NULL when the commit is not indexed.
	 * @param int $ceiling
	 *   How many commits either walk may read before refusing.
	 */
	public function __construct(
		private readonly Closure $parentsOf,
		private readonly int $ceiling = self::DEFAULT_CEILING,
	) {}

	/**
	 * The nearest commit both sides descend from.
	 *
	 * Ancestors of the left side are collected first, then the right side is walked breadth first and
	 * the first commit already seen is the answer. Breadth first is what makes it the NEAREST common
	 * ancestor rather than merely a common one: on a diamond, the fork point is reached before the
	 * commits above it.
	 *
	 * @param string $left
	 *   One commit, conventionally the target tip.
	 * @param string $right
	 *   The other, conventionally the branch tip.
	 *
	 * @return string|null
	 *   The base commit, or NULL when the two share no ancestor at all, which is what two histories
	 *   with different roots look like.
	 *
	 * @throws RuntimeException
	 *   When either walk passes the ceiling, or meets the same commit twice.
	 */
	public function find(string $left, string $right): ?string
	{
		if (!Hash::isValid($left) || !Hash::isValid($right)) {
			throw new RuntimeException('A merge base needs two valid commit ids');
		}
		if ($left === $right) {
			return $left;
		}

		$ancestors = $this->ancestors($left);

		if (isset($ancestors[$right])) {
			return $right;
		}

		foreach ($this->walk($right) as $id) {
			if (isset($ancestors[$id])) {
				return $id;
			}
		}

		return null;
	}

	/**
	 * Whether one commit is reachable from another.
	 *
	 * Answers the question a merge asks before it does any work: a branch whose tip the target already
	 * contains has nothing to bring in, and one that contains the target tip is a fast-forward rather
	 * than a merge.
	 *
	 * @param string $ancestor
	 *   The commit that might be behind.
	 * @param string $descendant
	 *   The commit to walk back from.
	 *
	 * @return bool
	 *   TRUE when walking back from the descendant reaches the ancestor.
	 *
	 * @throws RuntimeException
	 *   When the walk passes the ceiling, or meets the same commit twice.
	 */
	public function contains(string $ancestor, string $descendant): bool
	{
		if ($ancestor === $descendant) {
			return true;
		}

		foreach ($this->walk($descendant) as $id) {
			if ($id === $ancestor) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every commit reachable from one, as a lookup set.
	 *
	 * @param string $from
	 *   Commit to walk back from.
	 *
	 * @return array<string, true>
	 *   Commit id keyed to TRUE, the starting commit included.
	 *
	 * @throws RuntimeException
	 *   When the walk passes the ceiling, or meets the same commit twice.
	 */
	public function ancestors(string $from): array
	{
		$seen = [];

		foreach ($this->walk($from) as $id) {
			$seen[$id] = true;
		}

		return $seen;
	}

	/**
	 * Walks back from a commit, nearest first, refusing a chain that will not terminate.
	 *
	 * A commit the index does not hold ends that line of the walk rather than raising. History is
	 * pruned from the far end, so a branch cut before a prune legitimately walks off the end of what
	 * survives, and refusing there would make an ordinary store unmergeable.
	 *
	 * @param string $from
	 *   Commit to start at.
	 *
	 * @return list<string>
	 *   Commit ids in breadth-first order, the starting commit first.
	 *
	 * @throws RuntimeException
	 *   When the walk passes the ceiling, or the commits it read describe a cycle.
	 */
	private function walk(string $from): array
	{
		$queue = [$from];
		$seen = [$from => true];
		$order = [];
		$parents = [];

		while ($queue !== []) {
			$id = (string) array_shift($queue);
			$order[] = $id;

			if (count($order) > $this->ceiling) {
				throw new RuntimeException(
					sprintf(
						'The history behind %s is longer than %d commits, so no merge base was looked for',
						Hash::abbreviate($from),
						$this->ceiling,
					),
				);
			}

			$parents[$id] = ($this->parentsOf)($id) ?? [];

			foreach ($parents[$id] as $parent) {
				if (isset($seen[$parent])) {
					continue;
				}

				$seen[$parent] = true;
				$queue[] = $parent;
			}
		}

		$this->assertAcyclic($from, $parents);

		return $order;
	}

	/**
	 * Refuses a set of commits whose parent links form a loop.
	 *
	 * **Meeting a commit twice is not the test, and using it as one would refuse ordinary histories.**
	 * A merge whose branch was cut off the target's current tip reaches that tip down both sides, and
	 * every diamond reaches its fork point twice; both are correct histories. What cannot happen is a
	 * commit being its own ancestor, since an address is derived from the bytes that name the parent.
	 *
	 * So the peel is the test: repeatedly remove the commits whose parents are all outside the set or
	 * already removed. An acyclic set empties; whatever is left when nothing can be removed is exactly
	 * the commits inside a loop.
	 *
	 * @param string $from
	 *   Commit the walk started at, for the message.
	 * @param array<string, list<string>> $parents
	 *   Commit id keyed to the parents the index gave it.
	 *
	 * @throws RuntimeException
	 *   When any commit in the set is its own ancestor.
	 */
	private function assertAcyclic(string $from, array $parents): void
	{
		$degree = [];
		$children = [];
		$ready = [];

		foreach ($parents as $id => $links) {
			$inside = array_values(
				array_filter($links, static fn(string $p): bool => isset($parents[$p])),
			);
			$degree[$id] = count($inside);

			foreach ($inside as $parent) {
				$children[$parent][] = (string) $id;
			}

			if ($inside === []) {
				$ready[] = (string) $id;
			}
		}

		$peeled = 0;

		while ($ready !== []) {
			$id = (string) array_pop($ready);
			$peeled++;

			foreach ($children[$id] ?? [] as $child) {
				if (--$degree[$child] === 0) {
					$ready[] = $child;
				}
			}
		}

		if ($peeled === count($parents)) {
			return;
		}

		throw new RuntimeException(
			sprintf(
				'The history behind %s has %d commits that are their own ancestors, so the commit index is corrupt',
				Hash::abbreviate($from),
				count($parents) - $peeled,
			),
		);
	}
}
