<?php

declare(strict_types=1);

namespace Drupal\strata\Restore;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * The record of who restored what, when, and what it did.
 *
 * One of the two tables nothing in the bucket can reproduce, and the reason an uninstall exports
 * before dropping. The bucket records what the site looked like; this records what someone did
 * to it.
 *
 * A row is written BEFORE the restore runs, with the outcome pending and the plan already in it. A
 * restore that crashes half way therefore leaves a row saying it started and never finished, which
 * is the state an operator needs to see. Writing the row afterwards would mean the only restores on
 * record are the ones that worked.
 *
 * @see LogicalRestore
 * @see RestorePlan
 */
final class RestoreAudit
{
	/**
	 * The table restores are recorded in.
	 */
	public const TABLE = 'strata_restore_log';

	/**
	 * Recorded while a restore is in flight.
	 */
	public const PENDING = 'pending';

	/**
	 * Recorded when a restore finished and wrote what it planned to.
	 */
	public const SUCCEEDED = 'succeeded';

	/**
	 * Recorded when a restore started and did not finish.
	 */
	public const FAILED = 'failed';

	/**
	 * Recorded when a restore declined to start.
	 */
	public const REFUSED = 'refused';

	/**
	 * A restore that writes entities and config in place, with the site up.
	 */
	public const LOGICAL = 'logical';

	/**
	 * A restore that replaces whole tables, with the site in maintenance mode.
	 */
	public const PHYSICAL = 'physical';

	/**
	 * Constructs an audit.
	 *
	 * @param Connection $database
	 *   The database.
	 */
	public function __construct(private readonly Connection $database) {}

	/**
	 * Opens a row for a restore that is about to run.
	 *
	 * @param RestorePlan $plan
	 *   What the restore intends to do.
	 * @param string $scope
	 *   What it was scoped to, as the operator expressed it.
	 * @param string $mode
	 *   Either RestoreAudit::LOGICAL or RestoreAudit::PHYSICAL.
	 * @param int|null $actor
	 *   Drupal user id running it, or NULL for an unattended run.
	 * @param string|null $snapshot
	 *   Commit taken before the restore so it can be undone, or NULL when none was taken.
	 *
	 * @return int
	 *   The row id, to close the entry with.
	 */
	public function open(
		RestorePlan $plan,
		string $scope,
		string $mode = self::LOGICAL,
		?int $actor = null,
		?string $snapshot = null,
	): int {
		return (int) $this->database
			->insert(self::TABLE)
			->fields([
				'started' => time(),
				'finished' => null,
				'actor' => $actor,
				'target' => $plan->target,
				'pre_snapshot' => $snapshot,
				'scope' => mb_substr($scope, 0, 255),
				'mode' => $mode,
				'outcome' => self::PENDING,
				'restored' => 0,
				'skipped' => count($plan->skipped()),
				'detail' => (string) json_encode($plan),
			])
			->execute();
	}

	/**
	 * Closes a row with what the restore actually did.
	 *
	 * @param int $id
	 *   The row id from RestoreAudit::open().
	 * @param RestoreResult $result
	 *   What happened.
	 */
	public function close(int $id, RestoreResult $result): void
	{
		$this->database
			->update(self::TABLE)
			->fields([
				'finished' => time(),
				'outcome' => $result->outcome(),
				'restored' => count($result->restored),
				'skipped' => count($result->skipped),
				'detail' => (string) json_encode($result),
			])
			->condition('id', $id)
			->execute();
	}

	/**
	 * Records a restore that declined to start.
	 *
	 * @param RestorePlan $plan
	 *   What it would have done.
	 * @param string $scope
	 *   What it was scoped to.
	 * @param string $reason
	 *   Why it refused.
	 * @param string $mode
	 *   Either RestoreAudit::LOGICAL or RestoreAudit::PHYSICAL.
	 * @param int|null $actor
	 *   Drupal user id, or NULL.
	 *
	 * @return int
	 *   The row id.
	 */
	public function refuse(
		RestorePlan $plan,
		string $scope,
		string $reason,
		string $mode = self::LOGICAL,
		?int $actor = null,
	): int {
		$now = time();

		return (int) $this->database
			->insert(self::TABLE)
			->fields([
				'started' => $now,
				'finished' => $now,
				'actor' => $actor,
				'target' => $plan->target,
				'pre_snapshot' => null,
				'scope' => mb_substr($scope, 0, 255),
				'mode' => $mode,
				'outcome' => self::REFUSED,
				'restored' => 0,
				'skipped' => count($plan->subjects),
				'detail' => (string) json_encode(['refused' => $reason, 'plan' => $plan]),
			])
			->execute();
	}

	/**
	 * Restores in reverse order, newest first.
	 *
	 * @param int $limit
	 *   Most rows to return.
	 *
	 * @return list<array<string, mixed>>
	 *   The rows.
	 */
	public function recent(int $limit = 50): array
	{
		return $this->database
			->select(self::TABLE, 'r')
			->fields('r')
			->orderBy('started', 'DESC')
			->orderBy('id', 'DESC')
			->range(0, max(0, $limit))
			->execute()
			?->fetchAll(FetchAs::Associative) ?? [];
	}

	/**
	 * One row.
	 *
	 * @param int $id
	 *   The row id.
	 *
	 * @return array<string, mixed>|null
	 *   The row, or NULL when it is not there.
	 */
	public function get(int $id): ?array
	{
		$row = $this->database
			->select(self::TABLE, 'r')
			->fields('r')
			->condition('id', $id)
			->execute()
			?->fetchAssoc();

		return $row === false || $row === null ? null : $row;
	}

	/**
	 * Restores that started and never finished.
	 *
	 * What a status page shows as needing attention: a pending row that is not this request's is a
	 * restore that died, and the site may be half rolled back.
	 *
	 * @return list<array<string, mixed>>
	 *   The rows, oldest first.
	 */
	public function unfinished(): array
	{
		return $this->database
			->select(self::TABLE, 'r')
			->fields('r')
			->condition('outcome', self::PENDING)
			->orderBy('started')
			->execute()
			?->fetchAll(FetchAs::Associative) ?? [];
	}
}
