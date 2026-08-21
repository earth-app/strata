<?php

declare(strict_types=1);

namespace Drupal\strata\Restore;

use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\strata\Capture\PayloadCodec;
use Drupal\strata\Flush\Flusher;
use Drupal\strata\Journal\Realm;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Replaces whole tables with what was captured for them.
 *
 * The operation for the realms a logical restore refuses: raw table rows, and anything whose
 * correctness depends on constraints, sequences and triggers rather than on an entity API.
 *
 * **The honest constraint, stated first because everything else follows from it.** Strata stores
 * CHURN, not snapshots - that is the whole reason a year of history fits in 10 GB. So the rows it can
 * put back into a table are the rows it captured for that table, and nothing else. A table whose
 * changes were all captured restores exactly; a table that existed before capture started, or whose
 * drift exceeded the reconciler's per-pass bound, restores to fewer rows than it has now.
 *
 * That difference is measured before anything is written, and a restore that would leave a table with
 * materially fewer rows than it holds REFUSES unless an operator says otherwise. Silently truncating
 * a table to the subset that happened to be captured is the single worst thing this code could do,
 * and it is exactly what a naive implementation does.
 *
 * A pre-restore snapshot is forced, as it is for a logical restore, and maintenance mode is the
 * caller's decision: the truncate strategy needs it and the shadow-swap strategy does not, which is
 * why the strategy describes its own cost.
 *
 * @see RestoreStrategyInterface
 * @see PhysicalPlan
 * @see LogicalRestore
 */
final class PhysicalRestore
{
	/**
	 * How far below the live row count a restore may land before it refuses.
	 *
	 * A table restoring to 95% of its rows is plausibly a table with a few uncaptured rows; one
	 * restoring to 5% is a table about to be emptied by a backup that never saw it.
	 */
	public const COVERAGE_FLOOR = 0.9;

	/**
	 * Strategies available, keyed by id.
	 *
	 * @var array<string, RestoreStrategyInterface>
	 */
	private array $strategies = [];

	/**
	 * Constructs a physical restore.
	 *
	 * @param Connection $database
	 *   The connection tables are replaced on.
	 * @param Replayer $replayer
	 *   Reconstructs the captured rows.
	 * @param Flusher $flusher
	 *   Takes the snapshot that makes the restore undoable.
	 * @param RestoreAudit $audit
	 *   Records what was done.
	 * @param StateInterface $state
	 *   Used to put the site into maintenance mode when a strategy needs it.
	 * @param AccountProxyInterface $currentUser
	 *   Attributes the restore to whoever ran it.
	 * @param LoggerInterface $logger
	 *   Records the outcome.
	 */
	public function __construct(
		private readonly Connection $database,
		private readonly Replayer $replayer,
		private readonly Flusher $flusher,
		private readonly RestoreAudit $audit,
		private readonly StateInterface $state,
		private readonly AccountProxyInterface $currentUser,
		private readonly LoggerInterface $logger,
	) {}

	/**
	 * Registers a strategy.
	 *
	 * Collected from the container, so a contributed strategy is a tagged service.
	 *
	 * @param RestoreStrategyInterface $strategy
	 *   The strategy.
	 */
	public function addStrategy(RestoreStrategyInterface $strategy): void
	{
		$this->strategies[$strategy->id()] = $strategy;
	}

	/**
	 * Strategies that can run on this connection.
	 *
	 * @return array<string, RestoreStrategyInterface>
	 *   Id keyed to the strategy.
	 */
	public function available(): array
	{
		return array_filter(
			$this->strategies,
			fn(RestoreStrategyInterface $strategy): bool => $strategy->isSupported($this->database),
		);
	}

	/**
	 * Why each unavailable strategy cannot run.
	 *
	 * @return array<string, string>
	 *   Id keyed to the reason.
	 */
	public function unavailable(): array
	{
		$reasons = [];

		foreach ($this->strategies as $id => $strategy) {
			$reason = $strategy->unsupportedReason($this->database);

			if ($reason !== null) {
				$reasons[$id] = $reason;
			}
		}

		return $reasons;
	}

	#region Planning

	/**
	 * Works out what restoring a table would do, before anything is written.
	 *
	 * @param string $table
	 *   The table.
	 * @param string $target
	 *   Commit id to restore to.
	 *
	 * @return PhysicalPlan
	 *   What the restore would leave behind, and whether that is safe.
	 */
	public function plan(string $table, string $target): PhysicalPlan
	{
		$started = microtime(true);

		if (!$this->database->schema()->tableExists($table)) {
			return PhysicalPlan::refuse(
				$table,
				$target,
				sprintf('table %s does not exist', $table),
				microtime(true) - $started,
			);
		}

		try {
			$live = (int) $this->database
				->select($table, 't')
				->countQuery()
				->execute()
				?->fetchField();
			$rows = $this->rowsAt($table, $target);
		} catch (Throwable $error) {
			return PhysicalPlan::refuse(
				$table,
				$target,
				$error->getMessage(),
				microtime(true) - $started,
			);
		}

		return new PhysicalPlan(
			$table,
			$target,
			$live,
			count($rows['rows']),
			$rows['columns'],
			$rows['unreadable'],
			null,
			microtime(true) - $started,
		);
	}

	/**
	 * The rows captured for a table at a commit.
	 *
	 * Every row subject the target's tree covers for this table is replayed. A subject the replay
	 * could not complete is counted rather than half-written: a table restored from a mix of
	 * complete and partial rows is not the table as it was at any moment.
	 *
	 * @param string $table
	 *   The table.
	 * @param string $target
	 *   Commit id.
	 *
	 * @return array{rows: list<array<string, mixed>>, columns: list<string>, unreadable: int}
	 *   The rows, the union of their columns, and how many could not be read.
	 */
	private function rowsAt(string $table, string $target): array
	{
		$subjects = [];
		$prefix = Realm::TABLE->value . '/' . $table . ':';

		foreach ($this->replayer->subjectsAt($target) as $subject) {
			if (str_starts_with($subject, $prefix)) {
				$subjects[] = $subject;
			}
		}

		if ($subjects === []) {
			return ['rows' => [], 'columns' => [], 'unreadable' => 0];
		}

		$rows = [];
		$columns = [];
		$unreadable = 0;

		foreach ($this->replayer->materializeAll($subjects, $target) as $result) {
			if (!$result->exists) {
				continue;
			}
			if (!$result->isComplete() || $result->fields === []) {
				$unreadable++;

				continue;
			}

			$row = $result->fields;
			unset($row[PayloadCodec::VALUE]);

			if ($row === []) {
				$unreadable++;

				continue;
			}

			$rows[] = $row;

			foreach (array_keys($row) as $column) {
				$columns[(string) $column] = true;
			}
		}

		$names = array_keys($columns);
		sort($names);

		return ['rows' => $rows, 'columns' => $names, 'unreadable' => $unreadable];
	}

	#endregion

	#region Applying

	/**
	 * Restores one table.
	 *
	 * @param string $table
	 *   The table.
	 * @param string $target
	 *   Commit id to restore to.
	 * @param string $strategy
	 *   Which strategy to use.
	 * @param bool $apply
	 *   FALSE to plan and record without writing.
	 * @param bool $acceptRowLoss
	 *   TRUE to proceed when the restore would leave the table with materially fewer rows than it
	 *   holds. Off by default, because that is the case where a backup empties a table it never saw.
	 *
	 * @return RestoreResult
	 *   What happened.
	 */
	public function restore(
		string $table,
		string $target,
		string $strategy = 'truncate',
		bool $apply = false,
		bool $acceptRowLoss = false,
	): RestoreResult {
		$started = microtime(true);
		$actor = $this->actor();
		$plan = $this->plan($table, $target);
		$scope = Realm::TABLE->value . '/' . $table;

		$refusal = $this->refusalFor($plan, $strategy, $acceptRowLoss);

		if ($refusal !== null) {
			$this->audit->refuse(
				$plan->asRestorePlan(),
				$scope,
				$refusal,
				RestoreAudit::PHYSICAL,
				$actor,
			);

			return RestoreResult::refuse($target, $refusal);
		}

		$rows = $this->rowsAt($table, $target);

		if (!$apply) {
			return new RestoreResult(
				$target,
				null,
				[$scope],
				[],
				[],
				null,
				microtime(true) - $started,
			);
		}

		$snapshot = $this->snapshot();

		if ($snapshot === null) {
			$reason = 'the pre-restore snapshot could not be taken, so this would be undoable';
			$this->audit->refuse(
				$plan->asRestorePlan(),
				$scope,
				$reason,
				RestoreAudit::PHYSICAL,
				$actor,
			);

			return RestoreResult::refuse($target, $reason);
		}

		$row = $this->audit->open(
			$plan->asRestorePlan(),
			$scope,
			RestoreAudit::PHYSICAL,
			$actor,
			$snapshot,
		);

		$maintenance = $this->enterMaintenance($strategy);
		$restored = [];
		$failed = [];

		try {
			$this->strategies[$strategy]->restore(
				$this->database,
				$table,
				$rows['rows'],
				$rows['columns'],
			);
			$restored[] = $scope;
		} catch (Throwable $error) {
			$failed[$scope] = $error->getMessage();
		} finally {
			$this->leaveMaintenance($maintenance);
		}

		$outcome = new RestoreResult(
			$target,
			$snapshot,
			$restored,
			[],
			$failed,
			null,
			microtime(true) - $started,
		);

		$this->audit->close($row, $outcome);
		$this->logger->notice('Strata %summary', ['%summary' => $outcome->summary()]);

		return $outcome;
	}

	/**
	 * Why a restore would not run.
	 *
	 * @param PhysicalPlan $plan
	 *   What the restore would do.
	 * @param string $strategy
	 *   The strategy asked for.
	 * @param bool $acceptRowLoss
	 *   Whether the caller accepted losing rows.
	 *
	 * @return string|null
	 *   The reason, or NULL when it may proceed.
	 */
	private function refusalFor(PhysicalPlan $plan, string $strategy, bool $acceptRowLoss): ?string
	{
		if ($plan->refused !== null) {
			return $plan->refused;
		}
		if (!isset($this->strategies[$strategy])) {
			return sprintf(
				'"%s" is not a registered restore strategy; available: %s',
				$strategy,
				implode(', ', array_keys($this->strategies)) ?: 'none',
			);
		}

		$reason = $this->strategies[$strategy]->unsupportedReason($this->database);

		if ($reason !== null) {
			return $reason;
		}
		if ($plan->unreadable > 0) {
			return sprintf(
				'%d captured rows for %s could not be read, so the table would be restored from an ' .
					'incomplete set',
				$plan->unreadable,
				$plan->table,
			);
		}
		if (!$acceptRowLoss && $plan->wouldLoseRows()) {
			return $plan->rowLossWarning();
		}

		return null;
	}

	#endregion

	/**
	 * Puts the site into maintenance mode when the strategy needs it.
	 *
	 * @param string $strategy
	 *   The strategy id.
	 *
	 * @return bool
	 *   TRUE when this call turned maintenance mode on, so only it turns it back off. A site already
	 *   in maintenance mode when the restore started stays in it afterwards.
	 */
	private function enterMaintenance(string $strategy): bool
	{
		if ($strategy !== 'truncate' || (bool) $this->state->get('system.maintenance_mode')) {
			return false;
		}

		$this->state->set('system.maintenance_mode', true);

		return true;
	}

	/**
	 * Takes the site back out of maintenance mode.
	 *
	 * @param bool $entered
	 *   Whether this restore turned it on.
	 */
	private function leaveMaintenance(bool $entered): void
	{
		if ($entered) {
			$this->state->set('system.maintenance_mode', false);
		}
	}

	/**
	 * Seals the site's current state so the restore can be undone.
	 *
	 * @return string|null
	 *   The snapshot commit id, or NULL when nothing could be sealed.
	 */
	private function snapshot(): ?string
	{
		$result = $this->flusher->flush(true);

		return $result->commit ?? $this->replayer->resolve();
	}

	/**
	 * The user the restore is attributed to.
	 *
	 * @return int|null
	 *   A Drupal user id, or NULL for an unattended run.
	 */
	private function actor(): ?int
	{
		$id = (int) $this->currentUser->id();

		return $id > 0 ? $id : null;
	}
}
