<?php

declare(strict_types=1);

namespace Drupal\strata\Restore;

use Drupal\strata\Health\Finding;
use Drupal\strata\Health\HealthLedgerInterface;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Tree\CommitLog;
use Drupal\strata\Tree\SubjectIndex;
use Throwable;

/**
 * Works out what a restore can and cannot put back, before it writes anything.
 *
 * Every subject in scope is replayed and classified. That is deliberately expensive: it reads the
 * same objects the apply will read, so a plan that says restorable has proved it rather than
 * assumed it, and an operator confirming a rollback is looking at a measurement.
 *
 * The default for a degraded subject is to leave it alone. A partial field set written over a live
 * value produces a record that is neither the old one nor the new one, in a system whose whole
 * purpose is to be trusted about what the site used to look like. Filling the gaps with defaults is
 * available per scope and is spelled out subject by subject before it happens.
 *
 * Anything the preflight could not classify raises `restore.partial`, so a restore that was never
 * run still leaves evidence of why.
 *
 * @see RestorePlan
 * @see LogicalRestore
 */
final class Preflight
{
	/**
	 * Code raised when a restore would not be able to put everything back.
	 */
	public const PARTIAL = 'restore.partial';

	/**
	 * Constructs a preflight.
	 *
	 * @param Replayer $replayer
	 *   Reconstructs each subject.
	 * @param CommitLog $commits
	 *   Resolves the target and reports the replay depth.
	 * @param HealthLedgerInterface $ledger
	 *   Where a partial plan is recorded.
	 * @param SubjectIndex|null $subjects
	 *   Consulted for when each subject was last written, which is what makes a conflict detectable.
	 *   NULL plans without conflict detection, which is what a store with no local index can do.
	 */
	public function __construct(
		private readonly Replayer $replayer,
		private readonly CommitLog $commits,
		private readonly HealthLedgerInterface $ledger,
		private readonly ?SubjectIndex $subjects = null,
	) {}

	/**
	 * Plans a restore of every subject a commit covers.
	 *
	 * @param string $target
	 *   Commit id to restore to.
	 * @param bool $fillDegraded
	 *   TRUE to write partial reconstructions as well. Off unless a human asked.
	 * @param int|null $limit
	 *   Most subjects to plan, or NULL for all of them.
	 * @param bool $acceptConflicts
	 *   TRUE to write a subject somebody changed while the plan was waiting. Off unless a human asked.
	 *
	 * @return RestorePlan
	 *   The plan.
	 */
	public function plan(
		string $target,
		bool $fillDegraded = false,
		?int $limit = null,
		bool $acceptConflicts = false,
	): RestorePlan {
		$started = microtime(true);
		$problems = [];

		try {
			$subjects = $this->replayer->subjectsAt($target);
		} catch (Throwable $error) {
			return new RestorePlan(
				$target,
				0,
				[],
				[sprintf('the target commit does not read: %s', $error->getMessage())],
				$fillDegraded,
				microtime(true) - $started,
			);
		}

		$subjects = array_values(array_filter($subjects, self::isRestorableRealm(...)));

		if ($limit !== null) {
			$subjects = array_slice($subjects, 0, max(0, $limit));
		}

		return $this->planFor(
			$target,
			$subjects,
			$fillDegraded,
			$problems,
			$started,
			$acceptConflicts,
		);
	}

	/**
	 * Plans a restore of named subjects only.
	 *
	 * The surgical case: one node, one config object, one table row. A subject the target does not
	 * cover is planned anyway and comes back unrestorable, because "that node did not exist then" is
	 * an answer an operator needs rather than an error.
	 *
	 * @param string $target
	 *   Commit id to restore to.
	 * @param list<string> $subjects
	 *   Subject paths, such as "entity/node:42".
	 * @param bool $fillDegraded
	 *   TRUE to write partial reconstructions as well.
	 * @param bool $acceptConflicts
	 *   TRUE to write a subject somebody changed while the plan was waiting. Off unless a human asked.
	 *
	 * @return RestorePlan
	 *   The plan.
	 */
	public function planSubjects(
		string $target,
		array $subjects,
		bool $fillDegraded = false,
		bool $acceptConflicts = false,
	): RestorePlan {
		$started = microtime(true);
		$problems = [];
		$scoped = [];

		foreach ($subjects as $subject) {
			if (self::isRestorableRealm($subject)) {
				$scoped[] = $subject;

				continue;
			}

			$problems[] = sprintf('%s is in a realm a restore does not write back', $subject);
		}

		return $this->planFor(
			$target,
			$scoped,
			$fillDegraded,
			$problems,
			$started,
			$acceptConflicts,
		);
	}

	/**
	 * The subjects a plan covers that somebody has changed since it was built.
	 *
	 * Called at apply time rather than at plan time, because at plan time nothing can have changed
	 * yet. A plan applied straight away finds nothing here; one that waited for a second approval may
	 * find several, and those are the writes nobody reviewing the plan saw.
	 *
	 * Read from the local subject index, which records when each subject was last written, so this is
	 * one query and no object reads. A store with no local index detects nothing and says so by
	 * returning an empty set rather than by claiming there are none.
	 *
	 * @param RestorePlan $plan
	 *   The plan being applied.
	 *
	 * @return array<string, Conflict>
	 *   Subject path keyed to the conflict, empty when the plan carries no build time to compare
	 *   against.
	 */
	public function concurrentChanges(RestorePlan $plan): array
	{
		if ($this->subjects === null || $plan->plannedAt < 1) {
			return [];
		}

		$conflicts = [];
		$changed = $this->subjects->changedSince($plan->plannedAt);

		foreach (array_keys($plan->writable()) as $subject) {
			$path = (string) $subject;

			if (!array_key_exists($path, $changed)) {
				continue;
			}

			$conflicts[$path] = new Conflict(
				$path,
				$this->subjects->changedAt($path) ?? $plan->plannedAt,
				$plan->plannedAt,
			);
		}

		return $conflicts;
	}

	/**
	 * Whether a restore writes subjects in a path's realm back at all.
	 *
	 * Ephemeral state is captured so a restore can report what it invalidated, and never written
	 * back: the restored data rebuilds it, and writing a stale cache entry over a live bin costs
	 * more than the rebuild.
	 *
	 * @param string $subject
	 *   Subject path.
	 *
	 * @return bool
	 *   TRUE when the realm is restorable, and for a path whose realm is not one this release knows,
	 *   since a subject from a newer capture is not something to silently drop.
	 */
	public static function isRestorableRealm(string $subject): bool
	{
		$at = strpos($subject, '/');

		if ($at === false) {
			return true;
		}

		return Realm::tryFrom(substr($subject, 0, $at))?->isRestorable() ?? true;
	}

	/**
	 * Builds the plan for a resolved subject list.
	 *
	 * @param string $target
	 *   Commit id.
	 * @param list<string> $subjects
	 *   Subject paths.
	 * @param bool $fillDegraded
	 *   Whether partial reconstructions are written.
	 * @param list<string> $problems
	 *   Problems collected so far.
	 * @param float $started
	 *   When planning began.
	 * @param bool $acceptConflicts
	 *   Whether subjects changed since the restore point are written anyway.
	 *
	 * @return RestorePlan
	 *   The plan.
	 */
	private function planFor(
		string $target,
		array $subjects,
		bool $fillDegraded,
		array $problems,
		float $started,
		bool $acceptConflicts = false,
	): RestorePlan {
		$results = [];
		$depth = 0;

		if ($subjects !== []) {
			try {
				$results = $this->replayer->materializeAll($subjects, $target);
				$depth = $this->commits->replayPath($target)['depth'] + 1;
			} catch (Throwable $error) {
				$problems[] = sprintf('the replay did not complete: %s', $error->getMessage());
			}
		}

		$plan = new RestorePlan(
			$target,
			$depth,
			$results,
			$problems,
			$fillDegraded,
			microtime(true) - $started,
			(int) round($started * JournalOp::MICROSECONDS_PER_SECOND),
			$acceptConflicts,
		);

		$this->record($plan);

		return $plan;
	}

	/**
	 * Records a plan that would not put everything back.
	 *
	 * @param RestorePlan $plan
	 *   The plan.
	 */
	private function record(RestorePlan $plan): void
	{
		if ($plan->isComplete()) {
			return;
		}

		$counts = $plan->counts();

		$this->ledger->record(
			new Finding(
				self::PARTIAL,
				$counts[SubjectStatus::UNRESTORABLE->value] > 0 ? Finding::ERROR : Finding::WARN,
				$plan->target,
				sprintf(
					'A restore to this commit would leave %d subjects partly reconstructed and %d ' .
						'with nothing to restore from',
					$counts[SubjectStatus::DEGRADED->value],
					$counts[SubjectStatus::UNRESTORABLE->value],
				),
			),
		);
	}
}
