<?php

declare(strict_types=1);

namespace Drupal\strata\Branch;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Flush\Flusher;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Restore\LogicalRestore;
use Drupal\strata\Restore\ReplayResult;
use Drupal\strata\Restore\Replayer;
use Drupal\strata\Restore\RestorePlan;
use Drupal\strata\Segment\SegmentReader;
use Drupal\strata\Tree\Commit;
use Drupal\strata\Tree\CommitIndex;
use Drupal\strata\Tree\CommitLog;
use Drupal\strata\Tree\RefStore;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Plans and applies a merge of one config branch back into the trunk.
 *
 * **Configuration only, and the refusal names what it refused.** A configuration object is captured
 * WHOLE on every save, so its value at three commits is three complete documents and a three-way
 * merge over them is well defined. Content entities and table rows are captured as FIELD DELTAS
 * against a parent, so the same operation over them would mean replaying two divergent delta chains
 * and inventing a value wherever they disagree - which is not a merge, it is data loss with extra
 * steps. So a branch that received anything outside the configuration realm makes the plan refuse and
 * names the realm and the subjects, rather than merging the config and quietly dropping the rest.
 *
 * **The target is always the trunk.** A merge writes configuration to the live site, and the live
 * site is what `refs/heads/main` describes. Merging into some other ref would write values the trunk
 * does not have and leave the ref every restore resolves through describing a site that no longer
 * exists.
 *
 * The apply is a restricted logical restore, not a second write path. That is what gives the merge
 * the forced pre-write snapshot, the per-object failure reporting and the audit row for free, and it
 * is why there is no option here to skip the snapshot: the one moment somebody needs an undo is right
 * after a merge they did not mean to run.
 *
 * Three commits come out of an applied merge, in this order: the snapshot the restore forced, the
 * ordinary commit that seals the configuration it wrote, and a two-parent merge commit whose second
 * parent is the branch tip. That last one carries no segment; its whole job is to make the branch's
 * commits reachable from the trunk, so a prune keeps them and a later merge of the same branch can
 * see it has already been brought in.
 *
 * @see MergePlan
 * @see ThreeWayMerge
 * @see MergeBase
 * @see LogicalRestore
 */
final class Merger
{
	/**
	 * How many commits either side's walk reads before the plan gives up, when nothing says.
	 */
	public const MAX_WALK = 5000;

	/**
	 * How many subjects a refusal names before it stops listing them.
	 */
	public const NAMED_SUBJECTS = 20;

	/**
	 * Constructs a merger.
	 *
	 * @param BranchStore $branches
	 *   Resolves the branch being merged.
	 * @param RefStore $refs
	 *   Resolves the trunk's tip.
	 * @param CommitLog $commits
	 *   Reads the commits either side added.
	 * @param CommitIndex $index
	 *   Where the merge commit is mirrored, and what MergeBase walks.
	 * @param MergeBase $mergeBase
	 *   Finds the commit the two sides last agreed at.
	 * @param ThreeWayMerge $threeWay
	 *   Merges one object across the three.
	 * @param Replayer $replayer
	 *   Reconstructs each object at each of the three commits.
	 * @param SegmentReader $segments
	 *   Reads what each commit on either side actually touched.
	 * @param LogicalRestore $restore
	 *   Writes the merged configuration back, with its forced snapshot.
	 * @param Flusher $flusher
	 *   Seals the configuration the merge wrote before the merge commit names it.
	 * @param BranchIndex $branchIndex
	 *   Kept in step when a branch moves.
	 * @param AccountProxyInterface $currentUser
	 *   Attributes the merge to whoever ran it.
	 * @param LoggerInterface $logger
	 *   Records the outcome.
	 * @param int $maxWalk
	 *   How many commits either side is read for before the plan gives up. Each one costs a segment
	 *   read, so this bounds object reads rather than queries.
	 */
	public function __construct(
		private readonly BranchStore $branches,
		private readonly RefStore $refs,
		private readonly CommitLog $commits,
		private readonly CommitIndex $index,
		private readonly MergeBase $mergeBase,
		private readonly ThreeWayMerge $threeWay,
		private readonly Replayer $replayer,
		private readonly SegmentReader $segments,
		private readonly LogicalRestore $restore,
		private readonly Flusher $flusher,
		private readonly BranchIndex $branchIndex,
		private readonly AccountProxyInterface $currentUser,
		private readonly LoggerInterface $logger,
		private readonly int $maxWalk = self::MAX_WALK,
	) {}

	#region Branching

	/**
	 * Cuts a branch off a commit and indexes it.
	 *
	 * @param string $name
	 *   The branch name.
	 * @param string|null $from
	 *   Commit to fork from, or NULL for the trunk's current tip.
	 *
	 * @return Branch
	 *   The branch.
	 *
	 * @throws RuntimeException
	 *   When the name is taken, the trunk has no tip to fork from, or the commit is not present.
	 */
	public function branch(string $name, ?string $from = null): Branch
	{
		$at = $from ?? $this->refs->read();

		if ($at === null) {
			throw new RuntimeException(
				'This site has sealed no history yet, so there is no commit to branch from',
			);
		}
		if (!$this->commits->exists($at)) {
			throw new RuntimeException(
				sprintf('Commit %s is not in this store', Hash::abbreviate($at)),
			);
		}

		$branch = $this->branches->create($name, $at, $this->actor());

		$this->branchIndex->record($branch);
		$this->logger->notice('Strata branched %name from %commit', [
			'%name' => $name,
			'%commit' => Hash::abbreviate($at),
		]);

		return $branch;
	}

	/**
	 * Removes a branch and forgets its row.
	 *
	 * @param string $name
	 *   The branch name.
	 *
	 * @return bool
	 *   TRUE when a branch was removed.
	 *
	 * @throws RuntimeException
	 *   When the name is the trunk.
	 */
	public function remove(string $name): bool
	{
		$removed = $this->branches->delete($name);

		$this->branchIndex->forget($name);

		return $removed;
	}

	/**
	 * Every branch, with its row kept in step with the store.
	 *
	 * @return array<string, Branch>
	 *   Branch name keyed to the branch.
	 */
	public function list(): array
	{
		$branches = $this->branches->all();

		foreach ($branches as $branch) {
			$this->branchIndex->record($branch);
		}

		return $branches;
	}

	#endregion

	#region Planning

	/**
	 * Works out what merging a branch into the trunk would do.
	 *
	 * @param string $name
	 *   The branch name.
	 * @param MergeStrategy $strategy
	 *   What to do with a key both sides changed.
	 *
	 * @return MergePlan
	 *   The plan, which carries its own refusal when it could not be built.
	 */
	public function plan(string $name, MergeStrategy $strategy = MergeStrategy::REFUSE): MergePlan
	{
		$started = microtime(true);
		$target = Branch::refFor(Branch::TRUNK);

		try {
			$branch = $this->branches->read($name);
		} catch (Throwable $error) {
			return MergePlan::refuse($name, $target, $error->getMessage(), $strategy);
		}

		if ($branch === null) {
			return MergePlan::refuse(
				$name,
				$target,
				sprintf('there is no branch named "%s" in this store', $name),
				$strategy,
			);
		}
		if ($branch->isTrunk()) {
			return MergePlan::refuse(
				$name,
				$target,
				'the trunk is what a branch merges into, so it cannot merge into itself',
				$strategy,
			);
		}

		$ours = $this->refs->read($target);

		if ($ours === null) {
			return MergePlan::refuse(
				$name,
				$target,
				sprintf('%s points at nothing, so there is no trunk to merge into', $target),
				$strategy,
			);
		}

		try {
			return $this->build($branch, $target, $ours, $strategy, $started);
		} catch (Throwable $error) {
			return MergePlan::refuse($name, $target, $error->getMessage(), $strategy);
		}
	}

	/**
	 * Builds the plan once both tips are known.
	 *
	 * @param Branch $branch
	 *   The branch being merged.
	 * @param string $target
	 *   Ref name being merged into.
	 * @param string $ours
	 *   The trunk's tip.
	 * @param MergeStrategy $strategy
	 *   What to do with a key both sides changed.
	 * @param float $started
	 *   When planning began.
	 *
	 * @return MergePlan
	 *   The plan.
	 *
	 * @throws Throwable
	 *   When a walk cannot be completed, which the caller turns into a refusal.
	 */
	private function build(
		Branch $branch,
		string $target,
		string $ours,
		MergeStrategy $strategy,
		float $started,
	): MergePlan {
		$theirs = $branch->tip;
		$base = $this->mergeBase->find($ours, $theirs);

		if ($base === null) {
			return MergePlan::refuse(
				$branch->name,
				$target,
				sprintf(
					'%s and %s share no common commit, so there is nothing to merge against',
					$target,
					$branch->ref(),
				),
				$strategy,
			);
		}
		if ($base === $theirs) {
			// the trunk already contains every commit on the branch
			return new MergePlan(
				$branch->name,
				$target,
				$base,
				$ours,
				$theirs,
				$strategy,
				[],
				[],
				0,
				$this->now(),
				microtime(true) - $started,
			);
		}

		$behind = $this->mergeBase->ancestors($base);
		$touchedTheirs = $this->touched($theirs, $behind);
		$touchedOurs = $this->touched($ours, $behind);
		$problems = $this->refuseOtherRealms($branch, $touchedTheirs);
		$names = $this->configNames([...$touchedOurs, ...$touchedTheirs]);

		if ($names === []) {
			return new MergePlan(
				$branch->name,
				$target,
				$base,
				$ours,
				$theirs,
				$strategy,
				[],
				$problems,
				0,
				$this->now(),
				microtime(true) - $started,
			);
		}

		$subjects = array_map(
			static fn(string $config): string => Realm::CONFIG->value . '/' . $config,
			$names,
		);
		$atBase = $this->replayer->materializeAll($subjects, $base);
		$atOurs = $this->replayer->materializeAll($subjects, $ours);
		$atTheirs = $this->replayer->materializeAll($subjects, $theirs);

		$entries = [];
		$unchanged = 0;

		foreach ($names as $config) {
			$subject = Realm::CONFIG->value . '/' . $config;
			$readable = $this->readable($subject, [
				$base => $atBase[$subject] ?? null,
				$ours => $atOurs[$subject] ?? null,
				$theirs => $atTheirs[$subject] ?? null,
			]);

			if ($readable !== null) {
				$problems[] = $readable;

				continue;
			}

			$entry = $this->threeWay->merge(
				$config,
				$this->dataOf($atBase[$subject] ?? null),
				$this->dataOf($atOurs[$subject] ?? null),
				$this->dataOf($atTheirs[$subject] ?? null),
				$strategy,
			);

			if ($entry->outcome === MergeOutcome::UNCHANGED) {
				$unchanged++;

				continue;
			}

			$entries[$config] = $entry;
		}

		ksort($entries, SORT_STRING);

		return new MergePlan(
			$branch->name,
			$target,
			$base,
			$ours,
			$theirs,
			$strategy,
			$entries,
			$problems,
			$unchanged,
			$this->now(),
			microtime(true) - $started,
		);
	}

	#endregion

	#region Applying

	/**
	 * Applies a plan that has already been made.
	 *
	 * The plan is applied as it was printed rather than recomputed after the answer, which is what
	 * makes the manifest an operator approved the thing that happens.
	 *
	 * @param MergePlan $plan
	 *   The plan.
	 * @param bool $apply
	 *   FALSE to report what the plan would do and write nothing.
	 *
	 * @return MergeResult
	 *   What was written, or what would be.
	 */
	public function apply(MergePlan $plan, bool $apply = true): MergeResult
	{
		$started = microtime(true);
		$refusal = $plan->refusal();

		if ($refusal !== null) {
			$this->logger->warning('Strata refused a merge: %why', ['%why' => $refusal]);

			return MergeResult::refuse($plan, $refusal);
		}
		if (!$apply) {
			return MergeResult::dryRun($plan);
		}

		$restore = $this->restore->apply(
			$this->restorePlan($plan),
			sprintf('merge %s into %s, %s', $plan->branch, $plan->target, $plan->strategy->value),
			true,
		);

		if ($restore->wasRefused()) {
			return MergeResult::refuse($plan, (string) $restore->refused);
		}

		// seal what the restore just wrote, so the merge commit's first parent describes the merged
		// configuration rather than the state before it
		$this->flusher->flush(true, $plan->target);

		$commit = $this->seal($plan);
		$result = MergeResult::fromRestore($plan, $restore, $commit, microtime(true) - $started);

		$this->logger->notice('Strata %summary', ['%summary' => $result->summary()]);

		return $result;
	}

	/**
	 * Merges a branch in one call.
	 *
	 * @param string $name
	 *   The branch name.
	 * @param MergeStrategy $strategy
	 *   What to do with a key both sides changed.
	 * @param bool $apply
	 *   FALSE to plan and write nothing.
	 *
	 * @return MergeResult
	 *   What was written, or what would be.
	 */
	public function merge(
		string $name,
		MergeStrategy $strategy = MergeStrategy::REFUSE,
		bool $apply = true,
	): MergeResult {
		return $this->apply($this->plan($name, $strategy), $apply);
	}

	/**
	 * The restricted restore plan a merge applies.
	 *
	 * **Concurrent-change detection is switched off, by passing no plan time.** It compares each
	 * subject's last-written timestamp against the moment the plan was built, and the restore's own
	 * forced snapshot writes every pending subject between those two moments, so every object in the
	 * plan would look like somebody else's edit. The three-way comparison against the merge base is
	 * the stronger check and it has already run.
	 *
	 * @param MergePlan $plan
	 *   The plan.
	 *
	 * @return RestorePlan
	 *   A plan holding one config subject per object the merge writes.
	 */
	private function restorePlan(MergePlan $plan): RestorePlan
	{
		$subjects = [];

		foreach ($plan->writable() as $entry) {
			$subjects[$entry->subject()] = new ReplayResult(
				$entry->subject(),
				$entry->exists,
				$entry->value,
				1,
				0,
				[],
			);
		}

		return new RestorePlan((string) $plan->theirs, 0, $subjects);
	}

	/**
	 * Writes the two-parent commit that records the merge.
	 *
	 * @param MergePlan $plan
	 *   The plan that was applied.
	 *
	 * @return string|null
	 *   The merge commit id, or NULL when the trunk lost its tip mid-merge or another writer moved it.
	 */
	private function seal(MergePlan $plan): ?string
	{
		$head = $this->commits->head($plan->target);

		if ($head === null || $plan->theirs === null) {
			return null;
		}

		$commit = new Commit(
			$head->index,
			$head->id(),
			$this->now(),
			sprintf('merged %s, %d config objects', $plan->branch, count($plan->writable())),
			$this->actor(),
			0,
			0,
			0,
			$head->level,
			false,
			$head->chain,
			$head->anchoredAt,
			[
				'merge' => $plan->branch,
				'strategy' => $plan->strategy->value,
				'base' => (string) $plan->base,
			],
			$plan->theirs,
		);

		try {
			$id = $this->commits->append($commit, $plan->target);
		} catch (Throwable $error) {
			// the configuration is written and sealed either way; what is lost is the record of which
			// branch it came from, which is worth a warning and not worth undoing a good merge for
			$this->logger->warning('Strata wrote a merge but could not record it: %why', [
				'%why' => $error->getMessage(),
			]);

			return null;
		}

		$this->index->record($id, $commit);

		return $id;
	}

	#endregion

	#region Reading

	/**
	 * Every subject one side touched since the merge base.
	 *
	 * Read from the segments the commits on that side sealed, rather than by comparing the two whole
	 * subject sets. A site has thousands of subjects and a branch touches a handful, so the difference
	 * is between reading a handful of segments and materializing the whole site three times.
	 *
	 * @param string $from
	 *   Tip to walk back from.
	 * @param array<string, true> $behind
	 *   Commits at or behind the merge base, which end the walk.
	 *
	 * @return list<string>
	 *   Subject paths, such as `config/system.site`, with duplicates removed.
	 *
	 * @throws RuntimeException
	 *   When the walk passes $maxWalk, which means the branch is further from the trunk than a merge
	 *   will look.
	 */
	private function touched(string $from, array $behind): array
	{
		$queue = [$from];
		$seen = [$from => true];
		$subjects = [];
		$read = 0;

		while ($queue !== []) {
			$id = (string) array_shift($queue);

			if (isset($behind[$id])) {
				continue;
			}
			if (++$read > $this->maxWalk) {
				throw new RuntimeException(
					sprintf(
						'More than %d commits separate %s from the merge base, so nothing was compared',
						$this->maxWalk,
						Hash::abbreviate($from),
					),
				);
			}

			$commit = $this->commits->read($id);

			foreach ($this->subjectsIn($commit) as $subject) {
				$subjects[$subject] = true;
			}

			foreach ($commit->parents() as $parent) {
				if (!isset($seen[$parent])) {
					$seen[$parent] = true;
					$queue[] = $parent;
				}
			}
		}

		return array_keys($subjects);
	}

	/**
	 * The subjects one commit's segment names.
	 *
	 * @param Commit $commit
	 *   The commit.
	 *
	 * @return list<string>
	 *   Subject paths, empty when the commit sealed no segment.
	 *
	 * @throws RuntimeException
	 *   When a segment will not read. A merge over a side whose history is partly unreadable would
	 *   compare against a smaller set of objects than the branch actually touched, and would silently
	 *   leave the rest behind.
	 */
	private function subjectsIn(Commit $commit): array
	{
		$key = $commit->metadata['segment'] ?? null;

		if (!is_string($key) || $key === '') {
			return [];
		}

		try {
			$manifest = $this->segments->read($key);
		} catch (Throwable $error) {
			throw new RuntimeException(
				sprintf(
					'Segment %s does not read, so the merge cannot say what it held: %s',
					$key,
					$error->getMessage(),
				),
				0,
				$error,
			);
		}

		$subjects = [];

		foreach ($manifest->operations as $operation) {
			$subjects[$operation->realm->value . '/' . $operation->subject] = true;
		}

		return array_keys($subjects);
	}

	/**
	 * The configuration object names in a set of subject paths.
	 *
	 * @param list<string> $subjects
	 *   Subject paths from either side.
	 *
	 * @return list<string>
	 *   Object names, sorted and deduplicated.
	 */
	private function configNames(array $subjects): array
	{
		$prefix = Realm::CONFIG->value . '/';
		$names = [];

		foreach ($subjects as $subject) {
			if (str_starts_with($subject, $prefix)) {
				$names[substr($subject, strlen($prefix))] = true;
			}
		}

		$names = array_keys($names);

		sort($names, SORT_STRING);

		return $names;
	}

	/**
	 * One refusal line per realm the branch touched that a branch cannot carry.
	 *
	 * @param Branch $branch
	 *   The branch being merged.
	 * @param list<string> $subjects
	 *   Subject paths the branch touched.
	 *
	 * @return list<string>
	 *   The refusals, empty when the branch touched configuration only.
	 */
	private function refuseOtherRealms(Branch $branch, array $subjects): array
	{
		$byRealm = [];

		foreach ($subjects as $subject) {
			$at = strpos($subject, '/');

			if ($at === false) {
				continue;
			}

			$realm = substr($subject, 0, $at);

			if ($realm === Realm::CONFIG->value) {
				continue;
			}

			$byRealm[$realm][] = $subject;
		}

		$problems = [];

		foreach ($byRealm as $realm => $touched) {
			$named = array_slice($touched, 0, self::NAMED_SUBJECTS);
			$rest = count($touched) - count($named);

			$problems[] = sprintf(
				'branch %s changed %d subjects in the %s realm, which is captured as deltas and ' .
					'cannot be merged: %s%s',
				$branch->name,
				count($touched),
				$realm,
				implode(', ', $named),
				$rest > 0 ? sprintf(' and %d more', $rest) : '',
			);
		}

		return $problems;
	}

	/**
	 * Why one object cannot be merged, when a replay could only partly reconstruct it.
	 *
	 * A merge over a partial document would read every field the replay could not read as a deletion,
	 * so it would produce a config object that is neither side's and write it over a live one.
	 *
	 * @param string $subject
	 *   The subject path.
	 * @param array<string, ReplayResult|null> $results
	 *   Commit id keyed to what the replay produced there.
	 *
	 * @return string|null
	 *   The refusal, or NULL when every side read completely.
	 */
	private function readable(string $subject, array $results): ?string
	{
		foreach ($results as $commit => $result) {
			if ($result === null || $result->isComplete()) {
				continue;
			}

			return sprintf(
				'%s could only be partly reconstructed at %s, so it is not safe to merge',
				$subject,
				Hash::abbreviate((string) $commit),
			);
		}

		return null;
	}

	/**
	 * The data a replay produced, or NULL when the object was not there.
	 *
	 * @param ReplayResult|null $result
	 *   What the replay produced.
	 *
	 * @return array<string, mixed>|null
	 *   The configuration data, or NULL for an object that did not exist at that commit.
	 */
	private function dataOf(?ReplayResult $result): ?array
	{
		if ($result === null || !$result->exists || !$result->wasFound()) {
			return null;
		}

		return $result->fields;
	}

	#endregion

	/**
	 * The user a branch or a merge is attributed to.
	 *
	 * @return int|null
	 *   A Drupal user id, or NULL for an unattended run.
	 */
	private function actor(): ?int
	{
		$id = (int) $this->currentUser->id();

		return $id > 0 ? $id : null;
	}

	/**
	 * The current time in unix microseconds.
	 *
	 * @return int
	 *   Microseconds since the epoch.
	 */
	private function now(): int
	{
		return (int) round(microtime(true) * JournalOp::MICROSECONDS_PER_SECOND);
	}
}
