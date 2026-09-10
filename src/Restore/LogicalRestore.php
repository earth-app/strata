<?php

declare(strict_types=1);

namespace Drupal\strata\Restore;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\strata\Capture\EntityDelta;
use Drupal\strata\Capture\PayloadCodec;
use Drupal\strata\Event\Notifier;
use Drupal\strata\Flush\Flusher;
use Drupal\strata\Journal\Realm;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Puts individual subjects back, with the site up.
 *
 * The surgical half of rollback: one node, one config object, a set of them, without a maintenance
 * window and without touching anything the operator did not name. A whole-site rewind is a physical
 * restore and a different operation with different risks.
 *
 * Three rules make it safe to run on a live site.
 *
 * **A snapshot is forced first.** Before anything is written, the current state of every subject
 * about to change is flushed and committed, so the rollback itself has a rollback. A restore that
 * cannot take that snapshot refuses; there is no configuration to turn it off, because the one
 * moment an operator needs an undo is right after a restore they did not mean to run.
 *
 * **A degraded subject is skipped, not filled.** Writing a partial reconstruction over a live value
 * produces a record that is neither the old one nor the new one. The plan lists them and the
 * operator can opt in per scope.
 *
 * **A subject that fails to write does not abort the rest.** Each write is independent, so a
 * validation constraint on one node does not leave the other four hundred unrestored. The result
 * names every failure and the outcome is FAILED even when most of it worked.
 *
 * Entities are saved through the entity API rather than written to their tables, so hooks, field
 * constraints and cache invalidation all run - and Strata's own capture hooks run too, which is
 * exactly right: a restore is a change to the site and belongs in the history like any other.
 *
 * @see Preflight
 * @see RestorePlan
 * @see RestoreAudit
 */
final class LogicalRestore
{
	/**
	 * Fields never written back to a user.
	 *
	 * Rolling a user back to an old access timestamp would make an active account look dormant, which
	 * is corruption rather than recovery, and it would erase the security trail of who signed in when.
	 * The same reasoning excluded them from capture in the first place.
	 */
	public const EXCLUDED_USER_FIELDS = EntityDelta::ACCESS_FIELDS;

	/**
	 * Constructs a logical restore.
	 *
	 * @param Preflight $preflight
	 *   Plans the restore.
	 * @param Replayer $replayer
	 *   Reconstructs each subject.
	 * @param Flusher $flusher
	 *   Takes the snapshot that makes the restore undoable.
	 * @param RestoreAudit $audit
	 *   Records what was done.
	 * @param EntityTypeManagerInterface $entityTypeManager
	 *   Loads and saves the entities being restored.
	 * @param ConfigFactoryInterface $configFactory
	 *   Writes config objects back.
	 * @param StateInterface $state
	 *   Writes state values back.
	 * @param KeyValueFactoryInterface $keyValue
	 *   Writes key-value entries back.
	 * @param AccountProxyInterface $currentUser
	 *   Attributes the restore to whoever ran it.
	 * @param LoggerInterface $logger
	 *   Records the outcome.
	 * @param Notifier|null $notifier
	 *   Announces the outcome, or NULL to announce nothing.
	 */
	public function __construct(
		private readonly Preflight $preflight,
		private readonly Replayer $replayer,
		private readonly Flusher $flusher,
		private readonly RestoreAudit $audit,
		private readonly EntityTypeManagerInterface $entityTypeManager,
		private readonly ConfigFactoryInterface $configFactory,
		private readonly StateInterface $state,
		private readonly KeyValueFactoryInterface $keyValue,
		private readonly AccountProxyInterface $currentUser,
		private readonly LoggerInterface $logger,
		private readonly ?Notifier $notifier = null,
	) {}

	#region Applying

	/**
	 * Restores named subjects to their state at a commit.
	 *
	 * @param string $target
	 *   Commit id to restore to.
	 * @param list<string> $subjects
	 *   Subject paths, such as "entity/node:42".
	 * @param bool $apply
	 *   FALSE to plan and record without writing. The plan is identical either way.
	 * @param bool $fillDegraded
	 *   TRUE to write partial reconstructions as well.
	 *
	 * @return RestoreResult
	 *   What was written, skipped, and what failed.
	 */
	public function restore(
		string $target,
		array $subjects,
		bool $apply = false,
		bool $fillDegraded = false,
	): RestoreResult {
		return $this->run(
			$this->preflight->planSubjects($target, $subjects, $fillDegraded),
			implode(',', $subjects),
			$apply,
		);
	}

	/**
	 * Restores every subject a commit covers.
	 *
	 * @param string $target
	 *   Commit id to restore to.
	 * @param bool $apply
	 *   FALSE to plan and record without writing.
	 * @param bool $fillDegraded
	 *   TRUE to write partial reconstructions as well.
	 * @param int|null $limit
	 *   Most subjects to touch, or NULL for all of them.
	 *
	 * @return RestoreResult
	 *   What was written, skipped, and what failed.
	 */
	public function restoreAll(
		string $target,
		bool $apply = false,
		bool $fillDegraded = false,
		?int $limit = null,
	): RestoreResult {
		return $this->run($this->preflight->plan($target, $fillDegraded, $limit), 'all', $apply);
	}

	/**
	 * Applies a plan that has already been made.
	 *
	 * The path a confirm form takes: the operator was shown a plan, and this applies THAT plan rather
	 * than recomputing one that may have drifted since.
	 *
	 * @param RestorePlan $plan
	 *   The plan to apply.
	 * @param string $scope
	 *   What the restore was scoped to, as the operator expressed it.
	 * @param bool $apply
	 *   FALSE to record without writing.
	 *
	 * @return RestoreResult
	 *   What was written, skipped, and what failed.
	 */
	public function apply(RestorePlan $plan, string $scope, bool $apply = true): RestoreResult
	{
		return $this->run($plan, $scope, $apply);
	}

	/**
	 * Does the work for a plan.
	 *
	 * @param RestorePlan $plan
	 *   The plan.
	 * @param string $scope
	 *   What the restore was scoped to.
	 * @param bool $apply
	 *   FALSE to record without writing.
	 *
	 * @return RestoreResult
	 *   The result.
	 */
	private function run(RestorePlan $plan, string $scope, bool $apply): RestoreResult
	{
		$result = $this->attempt($plan, $scope, $apply);

		// announced here rather than in the three public entry points above, because all three come
		// through this method and one of them being missed is how nothing announced anything at all.
		// refusals included: a restore that refused is what an operator most needs told
		$this->notifier?->restoreFinished($result, RestoreAudit::LOGICAL, $scope, $this->actor());

		return $result;
	}

	/**
	 * The restore itself, before anything is announced.
	 *
	 * @param RestorePlan $plan
	 *   The plan.
	 * @param string $scope
	 *   What the restore was scoped to.
	 * @param bool $apply
	 *   FALSE to record without writing.
	 *
	 * @return RestoreResult
	 *   The result.
	 */
	private function attempt(RestorePlan $plan, string $scope, bool $apply): RestoreResult
	{
		$started = microtime(true);
		$actor = $this->actor();

		if ($plan->problems !== []) {
			$reason = implode('; ', $plan->problems);
			$this->audit->refuse($plan, $scope, $reason, RestoreAudit::LOGICAL, $actor);

			return RestoreResult::refuse($plan->target, $reason);
		}
		if ($plan->isEmpty()) {
			$reason = 'nothing in the plan can be written back';
			$this->audit->refuse($plan, $scope, $reason, RestoreAudit::LOGICAL, $actor);

			return RestoreResult::refuse($plan->target, $reason);
		}

		$snapshot = null;

		if ($apply) {
			$snapshot = $this->snapshot();

			if ($snapshot === null) {
				$reason = 'the pre-restore snapshot could not be taken, so this would be undoable';
				$this->audit->refuse($plan, $scope, $reason, RestoreAudit::LOGICAL, $actor);

				return RestoreResult::refuse($plan->target, $reason);
			}
		}

		$row = $this->audit->open($plan, $scope, RestoreAudit::LOGICAL, $actor, $snapshot);
		$restored = [];
		$failed = [];
		$conflicts = $plan->acceptConflicts ? [] : $this->preflight->concurrentChanges($plan);
		$skipped = $plan->skipped();

		foreach ($conflicts as $subject => $conflict) {
			$skipped[$subject] = $conflict->describe();
		}

		foreach ($plan->writable() as $subject => $result) {
			// somebody wrote this after the plan was reviewed, so the plan no longer describes it
			if (isset($conflicts[$subject])) {
				continue;
			}
			if (!$apply) {
				$restored[] = $subject;

				continue;
			}

			$error = $this->write($subject, $result);

			if ($error === null) {
				$restored[] = $subject;

				continue;
			}

			$failed[$subject] = $error;
		}

		$outcome = new RestoreResult(
			$plan->target,
			$snapshot,
			$restored,
			$skipped,
			$failed,
			null,
			microtime(true) - $started,
		);

		$this->audit->close($row, $outcome);
		$this->logger->notice('Strata %summary', ['%summary' => $outcome->summary()]);

		return $outcome;
	}

	#endregion

	#region Writing

	/**
	 * Writes one subject back.
	 *
	 * @param string $subject
	 *   Subject path.
	 * @param ReplayResult $result
	 *   What the replay reconstructed.
	 *
	 * @return string|null
	 *   NULL on success, or why it did not work.
	 */
	private function write(string $subject, ReplayResult $result): ?string
	{
		$at = strpos($subject, '/');

		if ($at === false) {
			return 'the subject path names no realm';
		}

		$realm = Realm::tryFrom(substr($subject, 0, $at));
		$name = substr($subject, $at + 1);

		if ($realm === null) {
			return sprintf(
				'"%s" is a realm this release does not know, so it is not written',
				substr($subject, 0, $at),
			);
		}

		return match ($realm) {
			Realm::ENTITY => $this->writeEntity($name, $result),
			Realm::CONFIG => $this->writeConfig($name, $result),
			Realm::STATE => $this->writeState($name, $result),
			Realm::KEY_VALUE => $this->writeKeyValue($name, $result),
			// a row operation carries the row, so it can be written; a whole-table marker cannot
			Realm::TABLE => $this->writeRow($name, $result),
			Realm::SCHEMA => sprintf(
				'%s is a schema change; replaying DDL against a live schema is a physical restore',
				$name,
			),
			Realm::FILE, Realm::CODE => sprintf(
				'restoring the %s realm needs its own module, which is not installed',
				$realm->value,
			),
			Realm::EPHEMERAL => 'ephemeral state is rebuilt by the data around it, never restored',
		};
	}

	/**
	 * Writes one config object back.
	 *
	 * Set as whole data rather than key by key, because that is how it was captured and because
	 * setting keys individually would leave any key added since the target commit in place - a
	 * restored config object that is a merge of two points in time is not either of them.
	 *
	 * @param string $name
	 *   The config object name.
	 * @param ReplayResult $result
	 *   What the replay reconstructed.
	 *
	 * @return string|null
	 *   NULL on success, or why it did not work.
	 */
	private function writeConfig(string $name, ReplayResult $result): ?string
	{
		try {
			$config = $this->configFactory->getEditable($name);

			if (!$result->exists) {
				// the object did not exist at the target, so restoring it means removing it; unlike
				// content, a config object is reproducible from its own history and carries no
				// user-authored data that a delete would lose
				$config->delete();

				return null;
			}

			$config->setData($result->fields)->save();

			return null;
		} catch (Throwable $error) {
			return $error->getMessage();
		}
	}

	/**
	 * Writes one state value back.
	 *
	 * @param string $key
	 *   The state key.
	 * @param ReplayResult $result
	 *   What the replay reconstructed.
	 *
	 * @return string|null
	 *   NULL on success, or why it did not work.
	 */
	private function writeState(string $key, ReplayResult $result): ?string
	{
		try {
			if (!$result->exists) {
				$this->state->delete($key);

				return null;
			}

			$this->state->set($key, $this->stored($result));

			return null;
		} catch (Throwable $error) {
			return $error->getMessage();
		}
	}

	/**
	 * Writes one key-value entry back.
	 *
	 * @param string $subject
	 *   The subject name, shaped "collection:key".
	 * @param ReplayResult $result
	 *   What the replay reconstructed.
	 *
	 * @return string|null
	 *   NULL on success, or why it did not work.
	 */
	private function writeKeyValue(string $subject, ReplayResult $result): ?string
	{
		$at = strpos($subject, ':');

		if ($at === false) {
			return 'the subject names no key-value collection';
		}

		$collection = substr($subject, 0, $at);
		$key = substr($subject, $at + 1);

		try {
			$store = $this->keyValue->get($collection);

			if (!$result->exists) {
				$store->delete($key);

				return null;
			}

			$store->set($key, $this->stored($result));

			return null;
		} catch (Throwable $error) {
			return $error->getMessage();
		}
	}

	/**
	 * Declines to write a table subject.
	 *
	 * Two shapes arrive here. A whole-table marker from the statement tap says a table changed and
	 * carries no rows, because a statement names the rows it touched only in its WHERE clause. A row
	 * captured by the reconciler does carry its contents, but writing it means an INSERT or UPDATE
	 * whose correctness depends on constraints, sequences and triggers this has no view of.
	 *
	 * Both are refused with the reason, and both are what a physical restore exists for.
	 *
	 * @param string $name
	 *   The subject name.
	 * @param ReplayResult $result
	 *   What the replay reconstructed.
	 *
	 * @return string
	 *   Why it was not written.
	 */
	private function writeRow(string $name, ReplayResult $result): string
	{
		if (!str_contains($name, ':')) {
			return sprintf(
				'%s records that the table changed, not which rows, so there is nothing to write',
				$name,
			);
		}

		return sprintf(
			'%s is a raw table row (%d fields); writing one is a physical restore',
			$name,
			count($result->fields),
		);
	}

	/**
	 * The value a state or key-value replay reconstructed.
	 *
	 * These stores hold arbitrary PHP values, so capture serializes them rather than encoding them as
	 * JSON. A value that round-tripped through JSON would come back as an array and be written back as
	 * the wrong type.
	 *
	 * @param ReplayResult $result
	 *   What the replay reconstructed.
	 *
	 * @return mixed
	 *   The value.
	 */
	private function stored(ReplayResult $result): mixed
	{
		return $result->fields[PayloadCodec::VALUE] ?? null;
	}

	/**
	 * Writes one entity's fields back.
	 *
	 * A subject the replay says did not exist at the target is NOT deleted here. Removing content
	 * because it postdates a restore point is a destructive interpretation of a rollback, and it is
	 * the one thing an operator would least expect from restoring a single node.
	 *
	 * @param string $name
	 *   The subject name, shaped "node:42".
	 * @param ReplayResult $result
	 *   What the replay reconstructed.
	 *
	 * @return string|null
	 *   NULL on success, or why it did not work.
	 */
	private function writeEntity(string $name, ReplayResult $result): ?string
	{
		$at = strrpos($name, ':');

		if ($at === false) {
			return 'the subject names no entity id';
		}

		$type = substr($name, 0, $at);
		$id = substr($name, $at + 1);

		if (!$result->exists) {
			return sprintf(
				'%s did not exist at this commit; a logical restore does not delete content',
				$name,
			);
		}
		if ($result->fields === []) {
			return 'the reconstruction produced no fields to write';
		}

		try {
			$entity = $this->entityTypeManager->getStorage($type)->load($id);
		} catch (Throwable $error) {
			return $error->getMessage();
		}

		if ($entity === null) {
			return sprintf('%s is no longer present, so there is nothing to restore into', $name);
		}
		if (!($entity instanceof FieldableEntityInterface)) {
			return sprintf('%s has no fields to write', $name);
		}

		return $this->save($entity, $result->fields);
	}

	/**
	 * Sets the reconstructed fields and saves.
	 *
	 * A field the entity no longer has is reported rather than forced: a field removed since the
	 * commit was taken cannot be restored, and inventing a value for it would be worse than saying so.
	 *
	 * @param FieldableEntityInterface $entity
	 *   The entity to write into.
	 * @param array<string, mixed> $fields
	 *   Field name keyed to its reconstructed value.
	 *
	 * @return string|null
	 *   NULL on success, or why it did not work.
	 */
	private function save(FieldableEntityInterface $entity, array $fields): ?string
	{
		$missing = [];

		foreach ($fields as $field => $value) {
			if (in_array($field, self::EXCLUDED_USER_FIELDS, true)) {
				continue;
			}
			if (!$entity->hasField($field)) {
				$missing[] = $field;

				continue;
			}

			try {
				$entity->set($field, $value);
			} catch (Throwable $error) {
				return sprintf(
					'field %s did not accept its value: %s',
					$field,
					$error->getMessage(),
				);
			}
		}

		if ($missing !== []) {
			return sprintf(
				'%s no longer has the fields %s, so it was left alone',
				$this->label($entity),
				implode(', ', $missing),
			);
		}

		try {
			$entity->save();
		} catch (Throwable $error) {
			return $error->getMessage();
		}

		return null;
	}

	#endregion

	/**
	 * Seals the site's current state so the restore can be undone.
	 *
	 * @return string|null
	 *   The snapshot commit id, or NULL when nothing could be sealed. An empty journal is not a
	 *   failure: with nothing pending, the head already IS the state the restore is about to change,
	 *   so that commit is the snapshot.
	 */
	private function snapshot(): ?string
	{
		$result = $this->flusher->flush(true);

		if ($result->commit !== null) {
			return $result->commit;
		}

		// nothing was pending, so the current head already describes the state being replaced
		return $this->replayer->resolve();
	}

	/**
	 * A label for an entity in an error message.
	 *
	 * @param EntityInterface $entity
	 *   The entity.
	 *
	 * @return string
	 *   Its label, or its type and id when it has none.
	 */
	private function label(EntityInterface $entity): string
	{
		return $entity->label() ?? $entity->getEntityTypeId() . ':' . ($entity->id() ?? 'new');
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
