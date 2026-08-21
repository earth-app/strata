<?php

declare(strict_types=1);

namespace Drupal\strata\Compaction;

use Drupal\strata\Cas\FrameIndexInterface;
use Drupal\strata\Cas\FrameRecord;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Tree\CommitLog;
use Drupal\strata\Tree\RefStore;
use Drupal\strata\Tree\BaseReader;
use Throwable;

/**
 * Decides whether an object can be removed without breaking something that still works.
 *
 * A reference count answers "does anything point at this" and that is not the same question. Three
 * separate things make an object un-collectable, and a prune that checks only the first has, in
 * every generation of this kind of system, eventually deleted something a restore needed.
 *
 * **Commits and bases.** A frame under a tree that a reachable commit points at is live, however
 * old the commit is and however many times the subject has changed since. Reachability is computed
 * by walking from the refs rather than read from a counter, because a counter is a cache of a walk
 * and the walk is the thing that is actually true.
 *
 * **Dictionaries.** A frame compressed against a trained dictionary cannot be decompressed without
 * it. The dictionary is not an optimisation after the fact; it is part of the frame. A dictionary
 * any live frame names survives even when the dictionary itself is old, unused by new writes, and
 * looks like an obvious candidate.
 *
 * **Delta-chain parents.** This is the class that delta coding introduced and the one most easily
 * missed, because the parent frame is often referenced by nothing at all - its own commit may be
 * long pruned. It is still needed: the frame that deltas against it decodes to garbage without it,
 * and so does everything after that one. A chain is only collectable whole.
 *
 * Every method here answers with the reason, not just a boolean, so a receipt can say what held an
 * object alive rather than reporting a refusal with no explanation.
 *
 * @see PruneReceipt
 * @see Compactor
 */
final class Reachability
{
	/**
	 * How many dependents to look at before treating a frame as widely depended on.
	 *
	 * A frame with more children than this is protected without enumerating them. The exact count
	 * changes nothing about the decision, and a chain that wide is a re-anchoring job rather than a
	 * prune candidate.
	 */
	public const DEPENDENT_LIMIT = 64;

	/**
	 * How far a delta chain is followed before the index is treated as corrupt.
	 *
	 * Content addressing makes a genuine cycle impossible, so a chain this long means an index row
	 * names a parent it should not. Far above the 32-link policy cap, so a legitimate chain never
	 * reaches it.
	 */
	public const CHAIN_LIMIT = 1024;

	/**
	 * Frames reachable from a ref, keyed by address; NULL until the walk has run.
	 *
	 * @var array<string, true>|null
	 */
	private ?array $liveFrames = null;

	/**
	 * Dictionaries live frames name, keyed by id; NULL until the walk has run.
	 *
	 * @var array<string, true>|null
	 */
	private ?array $liveDictionaries = null;

	/**
	 * Commits reachable from a ref, keyed by id; NULL until the walk has run.
	 *
	 * @var array<string, true>|null
	 */
	private ?array $liveCommits = null;

	/**
	 * Base anchors reachable from a ref, keyed by address; NULL until the walk has run.
	 *
	 * @var array<string, true>|null
	 */
	private ?array $liveTrees = null;

	/**
	 * Objects the walk could not read, so the answer is incomplete.
	 *
	 * @var list<string>
	 */
	private array $unreadable = [];

	/**
	 * Constructs a reachability set.
	 *
	 * @param FrameIndexInterface $index
	 *   Consulted for each frame's dictionary and delta parent.
	 * @param CommitLog $commitLog
	 *   Walks history from each ref.
	 * @param RefStore $refs
	 *   Lists the refs history is reachable from.
	 * @param BaseReader $bases
	 *   Reads the anchor chain so the frames its entries name can be collected.
	 */
	public function __construct(
		private readonly FrameIndexInterface $index,
		private readonly CommitLog $commitLog,
		private readonly RefStore $refs,
		private readonly BaseReader $bases,
	) {}

	#region The Walk

	/**
	 * Walks every ref and records what is reachable.
	 *
	 * Runs once and is cached, because a prune asks about thousands of candidates against one
	 * snapshot of history. Call Reachability::refresh() after anything that moves a ref.
	 */
	public function walk(): void
	{
		if ($this->liveFrames !== null) {
			return;
		}

		$this->liveFrames = [];
		$this->liveDictionaries = [];
		$this->liveCommits = [];
		$this->liveTrees = [];
		$this->unreadable = [];

		foreach ($this->refs->all() as $tip) {
			$this->walkFrom($tip);
		}
	}

	/**
	 * Discards the cached walk.
	 *
	 * A prune that has just moved a ref is looking at different history than the one it started
	 * with, and answering from the old snapshot is how a live object gets collected.
	 */
	public function refresh(): void
	{
		$this->liveFrames = null;
		$this->liveDictionaries = null;
		$this->liveCommits = null;
		$this->liveTrees = null;
		$this->unreadable = [];
	}

	/**
	 * Whether the walk read everything it needed to.
	 *
	 * A prune must refuse outright when this is FALSE. An incomplete walk under-reports what is
	 * live, and under-reporting is exactly the direction that deletes something needed.
	 *
	 * @return bool
	 *   TRUE when every object the walk reached for was readable.
	 */
	public function isComplete(): bool
	{
		$this->walk();

		return $this->unreadable === [];
	}

	/**
	 * Objects the walk could not read.
	 *
	 * @return list<string>
	 *   One line per unreadable object, naming it and the reason.
	 */
	public function unreadable(): array
	{
		$this->walk();

		return $this->unreadable;
	}

	/**
	 * Walks one chain of history from a tip.
	 *
	 * @param string $tip
	 *   Commit id to start at.
	 */
	private function walkFrom(string $tip): void
	{
		$id = $tip;

		while (true) {
			if (isset($this->liveCommits[$id])) {
				return;
			}

			try {
				$commit = $this->commitLog->read($id);
			} catch (Throwable $error) {
				$this->unreadable[] = sprintf(
					'commit %s: %s',
					Hash::abbreviate($id),
					$error->getMessage(),
				);

				return;
			}

			$this->liveCommits[$id] = true;
			$this->walkAnchor($commit->index);

			if ($commit->parent === null) {
				return;
			}

			$id = $commit->parent;
		}
	}

	/**
	 * Walks an anchor chain, marking every anchor and the frames its entries name live.
	 *
	 * The whole chain rather than the resolved index: an entry a later anchor replaced is still what
	 * the anchor that named it restores from, and every anchor is a restore target.
	 *
	 * @param string $address
	 *   Anchor address.
	 */
	private function walkAnchor(string $address): void
	{
		$at = $address;

		while ($at !== null && !isset($this->liveTrees[$at])) {
			$this->liveTrees[$at] = true;

			try {
				$manifest = $this->bases->read($at);
			} catch (Throwable $error) {
				$this->unreadable[] = sprintf(
					'anchor %s: %s',
					Hash::abbreviate($at),
					$error->getMessage(),
				);

				return;
			}

			foreach ($manifest->frames() as $frame) {
				$this->markFrame($frame);
			}

			$at = $manifest->full ? null : $manifest->parent;
		}
	}

	/**
	 * Marks a frame live, along with its dictionary and its whole delta chain.
	 *
	 * The chain is followed here rather than checked later, because a parent's own commit may be
	 * gone and nothing else would ever reach it.
	 *
	 * @param string $hash
	 *   Frame content address.
	 */
	private function markFrame(string $hash): void
	{
		$current = $hash;
		$depth = 0;

		while (true) {
			if (isset($this->liveFrames[$current])) {
				return;
			}

			$this->liveFrames[$current] = true;
			$record = $this->index->get($current);

			if ($record === null) {
				return;
			}
			if ($record->dictionary !== null) {
				$this->liveDictionaries[$record->dictionary] = true;
			}
			if ($record->deltaParent === null) {
				return;
			}

			// a cycle cannot happen through content addressing, but a bad index row could fake one
			if (++$depth > self::CHAIN_LIMIT) {
				$this->unreadable[] = sprintf(
					'delta chain from %s does not terminate within %d links',
					Hash::abbreviate($hash),
					self::CHAIN_LIMIT,
				);

				return;
			}

			$current = $record->deltaParent;
		}
	}

	#endregion

	#region Questions

	/**
	 * Whether a frame must survive a prune, and why.
	 *
	 * @param string $hash
	 *   Frame content address.
	 *
	 * @return string|null
	 *   The reason it is live, or NULL when nothing needs it.
	 */
	public function frameReason(string $hash): ?string
	{
		$this->walk();

		if (isset($this->liveFrames[$hash])) {
			return 'a reachable commit needs it';
		}

		$record = $this->index->get($hash);

		if ($record === null) {
			return null;
		}
		if ($record->references > 0) {
			return sprintf('%d references still point at it', $record->references);
		}

		return $this->dependentReason($hash);
	}

	/**
	 * Whether anything live decodes against this frame.
	 *
	 * The upward direction of the chain: the frame itself may be unreferenced and unreachable while
	 * a frame that IS live sits one link downstream of it.
	 *
	 * @param string $hash
	 *   Frame content address.
	 *
	 * @return string|null
	 *   The reason it is live, or NULL when nothing decodes against it.
	 */
	private function dependentReason(string $hash): ?string
	{
		$dependents = $this->index->dependents($hash, self::DEPENDENT_LIMIT);

		if ($dependents === []) {
			return null;
		}
		if (count($dependents) >= self::DEPENDENT_LIMIT) {
			return sprintf('at least %d delta frames decode against it', self::DEPENDENT_LIMIT);
		}

		foreach ($dependents as $dependent) {
			if (isset($this->liveFrames[$dependent->hash]) || $dependent->references > 0) {
				return sprintf(
					'delta frame %s decodes against it',
					Hash::abbreviate($dependent->hash),
				);
			}
		}

		return null;
	}

	/**
	 * Whether a frame can be removed.
	 *
	 * @param string $hash
	 *   Frame content address.
	 *
	 * @return bool
	 *   TRUE when nothing reachable needs it.
	 */
	public function isFrameCollectable(string $hash): bool
	{
		return $this->frameReason($hash) === null;
	}

	/**
	 * Whether a dictionary must survive a prune, and why.
	 *
	 * Asked against both the walk and the index, because a frame outside reachable history can
	 * still be readable and still need its dictionary.
	 *
	 * @param string $id
	 *   Dictionary id.
	 *
	 * @return string|null
	 *   The reason it is live, or NULL when nothing needs it.
	 */
	public function dictionaryReason(string $id): ?string
	{
		$this->walk();

		if (isset($this->liveDictionaries[$id])) {
			return 'a live frame was compressed against it';
		}

		$counts = $this->index->dictionaries();

		if (isset($counts[$id])) {
			return sprintf('%d referenced frames were compressed against it', $counts[$id]);
		}

		return null;
	}

	/**
	 * Whether a commit must survive a prune, and why.
	 *
	 * @param string $id
	 *   Commit id.
	 *
	 * @return string|null
	 *   The reason it is live, or NULL when no ref reaches it.
	 */
	public function commitReason(string $id): ?string
	{
		$this->walk();

		return isset($this->liveCommits[$id]) ? 'a ref reaches it' : null;
	}

	/**
	 * Whether a tree node must survive a prune, and why.
	 *
	 * @param string $address
	 *   Node address.
	 *
	 * @return string|null
	 *   The reason it is live, or NULL when no reachable commit points at it.
	 */
	public function treeReason(string $address): ?string
	{
		$this->walk();

		return isset($this->liveTrees[$address]) ? 'a reachable commit points at it' : null;
	}

	#endregion

	#region Totals

	/**
	 * What the walk reached.
	 *
	 * @return array{commits: int, trees: int, frames: int, dictionaries: int, unreadable: int}
	 *   Counts, for a receipt and for the storage explorer.
	 */
	public function statistics(): array
	{
		$this->walk();

		return [
			'commits' => count($this->liveCommits ?? []),
			'trees' => count($this->liveTrees ?? []),
			'frames' => count($this->liveFrames ?? []),
			'dictionaries' => count($this->liveDictionaries ?? []),
			'unreadable' => count($this->unreadable),
		];
	}

	/**
	 * Frames the index holds that no reachable history needs.
	 *
	 * Every candidate has been through all three classes, so a caller can act on the list without
	 * re-checking. The list is bounded, because a prune runs inside a cron window.
	 *
	 * @param int $limit
	 *   Most candidates to return.
	 *
	 * @return list<FrameRecord>
	 *   Collectable records.
	 */
	public function collectableFrames(int $limit = 1000): array
	{
		$this->walk();
		$collectable = [];

		foreach ($this->index->orphans($limit * 2) as $record) {
			if (count($collectable) >= $limit) {
				break;
			}
			if ($this->frameReason($record->hash) === null) {
				$collectable[] = $record;
			}
		}

		return $collectable;
	}

	#endregion
}
