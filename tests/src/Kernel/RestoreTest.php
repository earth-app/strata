<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Engine;
use Drupal\strata\Restore\RestoreAudit;
use Drupal\strata\Restore\SubjectStatus;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves a stored history can be put back, and says so when it cannot.
 */
class RestoreTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'field', 'key', 'strata'];

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->installEntitySchema('user');
		$this->installSchema('user', ['users_data']);

		$this->config('strata.settings')
			->set('enabled', true)
			->set('provider', 'local')
			->set('local_path', $this->storeRoot)
			->set('cipher.id', 'none')
			->save();

		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();
	}

	private function engine(): Engine
	{
		return $this->container->get('strata.engine');
	}

	private function audit(): RestoreAudit
	{
		return $this->container->get('strata.restore_audit');
	}

	private function user(string $name): User
	{
		$user = User::create(['name' => $name, 'mail' => $name . '@example.com', 'status' => 1]);
		$user->save();

		return $user;
	}

	/**
	 * The head commit id, having flushed whatever is pending.
	 */
	private function commit(): string
	{
		$this->engine()->flusher()->flush(true);

		return (string) $this->engine()->commitLog()->head()?->id();
	}

	/**
	 * @return list<string>
	 *   Object keys under a prefix.
	 */
	private function keys(string $prefix): array
	{
		return $this->engine()->provider()->list($prefix, null, 1000)->keys();
	}

	#region Replay

	#[Test]
	#[TestDox('a subject reconstructs to the fields it had at the target commit')]
	#[Group('strata/restore')]
	public function replayReconstructsFields(): void
	{
		$user = $this->user('original-name');
		$first = $this->commit();

		$user->set('name', 'renamed-once')->save();
		$second = $this->commit();

		$subject = 'entity/user:' . $user->id();
		$replayer = $this->engine()->replayer();

		$before = $replayer->materialize($subject, $first);
		$after = $replayer->materialize($subject, $second);

		$this->assertSame(SubjectStatus::RESTORABLE, $before->status());
		$this->assertTrue($before->exists);
		$this->assertSame('original-name', $this->fieldValue($before->fields, 'name'));
		$this->assertSame('renamed-once', $this->fieldValue($after->fields, 'name'));
		$this->assertSame(2, $after->versions, 'the create and the rename both applied');
	}

	#[Test]
	#[TestDox('a field delta merges over the state before it rather than replacing it')]
	#[Group('strata/restore')]
	public function deltasAccumulate(): void
	{
		$user = $this->user('accumulated');
		$this->commit();

		$user->set('name', 'accumulated-renamed')->save();
		$target = $this->commit();

		$result = $this->engine()
			->replayer()
			->materialize('entity/user:' . $user->id(), $target);

		$this->assertSame('accumulated-renamed', $this->fieldValue($result->fields, 'name'));
		$this->assertArrayHasKey(
			'mail',
			$result->fields,
			'a field the rename did not touch survives from the create',
		);
	}

	#[Test]
	#[TestDox('a subject history never mentions comes back unrestorable rather than empty')]
	#[Group('strata/restore')]
	public function unknownSubjectIsUnrestorable(): void
	{
		$this->user('present');
		$target = $this->commit();

		$result = $this->engine()->replayer()->materialize('entity/node:999', $target);

		$this->assertSame(SubjectStatus::UNRESTORABLE, $result->status());
		$this->assertFalse($result->wasFound());
		$this->assertFalse($result->exists);
		$this->assertSame([], $result->fields);
	}

	#[Test]
	#[TestDox('a deleted subject reconstructs as absent rather than as its last fields')]
	#[Group('strata/restore')]
	public function deletedSubjectIsAbsent(): void
	{
		$user = $this->user('doomed');
		$id = (string) $user->id();
		$this->commit();

		$user->delete();
		$target = $this->commit();

		$result = $this->engine()
			->replayer()
			->materialize('entity/user:' . $id, $target);

		$this->assertFalse($result->exists);
		$this->assertSame([], $result->fields);
		$this->assertSame(2, $result->versions);
	}

	#[Test]
	#[TestDox('many subjects replay in one pass, reading each segment once')]
	#[Group('strata/restore')]
	public function manySubjectsReplayTogether(): void
	{
		$first = $this->user('together-one');
		$second = $this->user('together-two');
		$target = $this->commit();

		$results = $this->engine()
			->replayer()
			->materializeAll(
				['entity/user:' . $first->id(), 'entity/user:' . $second->id()],
				$target,
			);

		$this->assertCount(2, $results);

		foreach ($results as $result) {
			$this->assertSame(SubjectStatus::RESTORABLE, $result->status());
			$this->assertGreaterThan(0, $result->versions);
		}
	}

	#[Test]
	#[TestDox('the subjects a commit covers come from its tree, not from a history walk')]
	#[Group('strata/restore')]
	public function subjectsComeFromTheTree(): void
	{
		$first = $this->user('enumerated-one');
		$this->commit();
		$second = $this->user('enumerated-two');
		$target = $this->commit();

		$subjects = $this->engine()->replayer()->subjectsAt($target);

		$this->assertContains('entity/user:' . $first->id(), $subjects);
		$this->assertContains('entity/user:' . $second->id(), $subjects);
	}

	#[Test]
	#[TestDox('losing one version of two leaves the subject degraded, not silently short')]
	#[Group('strata/restore')]
	public function lostVersionDegradesTheSubject(): void
	{
		$user = $this->user('degradable');
		$this->commit();
		$first = $this->keys('packs/');

		$user->set('name', 'degradable-renamed')->save();
		$target = $this->commit();

		// only the second version's payload goes, so the first still reads and the merge is partial
		$second = array_values(array_diff($this->keys('packs/'), $first));
		$this->assertNotEmpty($second, 'the rename produced its own pack');
		$this->engine()->provider()->delete($second);

		$result = $this->engine()
			->replayer()
			->materialize('entity/user:' . $user->id(), $target);

		$this->assertSame(SubjectStatus::DEGRADED, $result->status());
		$this->assertFalse($result->isComplete());
		$this->assertSame(1, $result->versions, 'one version read, one did not');
		$this->assertCount(1, $result->unreadable);
		$this->assertSame(
			'degradable',
			$this->fieldValue($result->fields, 'name'),
			'the fields are the ones that survived, never a guess at the ones that did not',
		);
	}

	#[Test]
	#[TestDox('losing every version leaves the subject unrestorable, with no fields at all')]
	#[Group('strata/restore')]
	public function lostEveryVersionIsUnrestorable(): void
	{
		$user = $this->user('unrecoverable');
		$target = $this->commit();
		$this->engine()->provider()->delete($this->keys('packs/'));

		$result = $this->engine()
			->replayer()
			->materialize('entity/user:' . $user->id(), $target);

		$this->assertSame(SubjectStatus::UNRESTORABLE, $result->status());
		$this->assertSame(0, $result->versions);
		$this->assertSame([], $result->fields);
		$this->assertNotEmpty($result->unreadable);
	}

	#[Test]
	#[TestDox('a degraded subject is written only when the scope opts in to partial values')]
	#[Group('strata/restore')]
	public function degradedIsWrittenOnlyOnOptIn(): void
	{
		$user = $this->user('opt-in');
		$this->commit();
		$first = $this->keys('packs/');

		$user->set('name', 'opt-in-renamed')->save();
		$target = $this->commit();

		$this->engine()
			->provider()
			->delete(array_values(array_diff($this->keys('packs/'), $first)));

		$subject = 'entity/user:' . $user->id();
		$restore = $this->engine()->logicalRestore();

		$refused = $restore->restore($target, [$subject], true);

		$this->assertTrue($refused->wasRefused(), 'a partial value is not written by default');
		$this->assertSame('opt-in-renamed', User::load((int) $user->id())?->getAccountName());

		$applied = $restore->restore($target, [$subject], true, true);

		$this->assertFalse($applied->wasRefused(), (string) $applied->refused);
		$this->assertSame([$subject], $applied->restored);
		$this->assertSame('opt-in', User::load((int) $user->id())?->getAccountName());
	}

	#endregion

	#region Preflight

	#[Test]
	#[TestDox('a plan classifies every subject and says how deep the replay is')]
	#[Group('strata/restore')]
	public function planClassifiesEverySubject(): void
	{
		$this->user('planned-one');
		$this->user('planned-two');
		$target = $this->commit();

		$plan = $this->engine()->preflight()->plan($target);

		$this->assertTrue($plan->isComplete(), implode('; ', $plan->problems));
		$this->assertSame(2, $plan->counts()[SubjectStatus::RESTORABLE->value]);
		$this->assertSame(0, $plan->counts()[SubjectStatus::DEGRADED->value]);
		$this->assertSame(0, $plan->counts()[SubjectStatus::UNRESTORABLE->value]);
		$this->assertGreaterThan(0, $plan->depth);
		$this->assertCount(2, $plan->writable());
		$this->assertSame([], $plan->skipped());
		$this->assertStringContainsString('2 restorable', $plan->summary());
	}

	#[Test]
	#[TestDox('a degraded subject is listed and skipped rather than written')]
	#[Group('strata/restore')]
	public function degradedSubjectIsSkipped(): void
	{
		$user = $this->user('unwritable');
		$target = $this->commit();
		$this->engine()->provider()->delete($this->keys('packs/'));

		$plan = $this->engine()
			->preflight()
			->planSubjects($target, ['entity/user:' . $user->id()]);

		$this->assertFalse($plan->isComplete());
		$this->assertTrue($plan->isEmpty(), 'nothing is written by default');
		$this->assertCount(1, $plan->skipped());
		$this->assertStringContainsString(
			'nothing usable survives',
			implode(' ', $plan->skipped()),
		);
	}

	#[Test]
	#[TestDox('an ephemeral subject is excluded from a restore rather than written back stale')]
	#[Group('strata/restore')]
	public function ephemeralRealmIsExcluded(): void
	{
		$target = $this->commit();
		$plan = $this->engine()
			->preflight()
			->planSubjects($target, ['ephemeral/cache:page']);

		$this->assertCount(1, $plan->problems);
		$this->assertStringContainsString('a restore does not write back', $plan->problems[0]);
		$this->assertSame([], $plan->subjects);
	}

	#[Test]
	#[TestDox('a target commit that does not read is reported rather than planned around')]
	#[Group('strata/restore')]
	public function unreadableTargetIsReported(): void
	{
		$this->user('unreadable-target');
		$target = $this->commit();
		$this->engine()->provider()->delete($this->keys('commits/'));
		$this->engine()->commitLog()->flushCache();

		$plan = $this->engine()->preflight()->plan($target);

		$this->assertFalse($plan->isComplete());
		$this->assertStringContainsString('does not read', $plan->problems[0]);
		$this->assertTrue($plan->isEmpty());
	}

	#[Test]
	#[TestDox('a partial plan raises a finding even though no restore ran')]
	#[Group('strata/restore')]
	public function partialPlanRaisesFinding(): void
	{
		$user = $this->user('finding');
		$target = $this->commit();
		$this->engine()->provider()->delete($this->keys('packs/'));

		$this->engine()
			->preflight()
			->planSubjects($target, ['entity/user:' . $user->id()]);

		$codes = array_map(
			static fn(object $finding): string => $finding->code,
			$this->container->get('strata.health_ledger')->open(),
		);

		$this->assertContains('restore.partial', $codes);
	}

	#endregion

	#region Applying

	#[Test]
	#[TestDox('a restore puts a renamed user back and records who did it')]
	#[Group('strata/restore')]
	public function restorePutsFieldBack(): void
	{
		$user = $this->user('before-rename');
		$target = $this->commit();

		$user->set('name', 'after-rename')->save();
		$this->commit();

		$subject = 'entity/user:' . $user->id();
		$result = $this->engine()
			->logicalRestore()
			->restore($target, [$subject], true);

		$this->assertFalse($result->wasRefused(), (string) $result->refused);
		$this->assertSame([$subject], $result->restored);
		$this->assertSame([], $result->failed);
		$this->assertSame(RestoreAudit::SUCCEEDED, $result->outcome());

		$reloaded = User::load((int) $user->id());

		$this->assertSame('before-rename', $reloaded?->getAccountName());
	}

	#[Test]
	#[TestDox('a restore takes a snapshot first, so it can itself be undone')]
	#[Group('strata/restore')]
	public function restoreTakesSnapshot(): void
	{
		$user = $this->user('undoable');
		$target = $this->commit();

		$user->set('name', 'undoable-changed')->save();
		$this->commit();

		$subject = 'entity/user:' . $user->id();
		$result = $this->engine()
			->logicalRestore()
			->restore($target, [$subject], true);

		$this->assertNotNull($result->snapshot);
		$this->assertTrue($this->engine()->commitLog()->exists((string) $result->snapshot));

		// the snapshot describes the state the restore replaced, so replaying it gives the new name
		$replayed = $this->engine()->replayer()->materialize($subject, (string) $result->snapshot);

		$this->assertSame('undoable-changed', $this->fieldValue($replayed->fields, 'name'));
	}

	#[Test]
	#[TestDox('a dry run reports what it would write and changes nothing')]
	#[Group('strata/restore')]
	public function dryRunWritesNothing(): void
	{
		$user = $this->user('dry-original');
		$target = $this->commit();

		$user->set('name', 'dry-changed')->save();
		$this->commit();

		$subject = 'entity/user:' . $user->id();
		$result = $this->engine()
			->logicalRestore()
			->restore($target, [$subject], false);

		$this->assertSame([$subject], $result->restored);
		$this->assertNull($result->snapshot, 'a dry run takes no snapshot');
		$this->assertSame('dry-changed', User::load((int) $user->id())?->getAccountName());
	}

	#[Test]
	#[TestDox('a restore of a subject with nothing to write refuses rather than reporting success')]
	#[Group('strata/restore')]
	public function nothingWritableIsRefused(): void
	{
		$this->user('present-so-history-exists');
		$target = $this->commit();
		$result = $this->engine()
			->logicalRestore()
			->restore($target, ['entity/node:404'], true);

		$this->assertTrue($result->wasRefused());
		$this->assertStringContainsString('nothing in the plan', (string) $result->refused);
		$this->assertSame(RestoreAudit::REFUSED, $result->outcome());
	}

	#[Test]
	#[TestDox('a restore does not delete content that postdates the restore point')]
	#[Group('strata/restore')]
	public function restoreDoesNotDeleteNewerContent(): void
	{
		$this->user('existing');
		$target = $this->commit();

		$later = $this->user('created-later');
		$this->commit();

		$subject = 'entity/user:' . $later->id();
		$result = $this->engine()
			->logicalRestore()
			->restore($target, [$subject], true);

		$this->assertTrue($result->wasRefused());
		$this->assertNotNull(
			User::load((int) $later->id()),
			'a logical restore never removes content',
		);
	}

	#[Test]
	#[TestDox('a whole-commit restore writes every restorable subject the commit covers')]
	#[Group('strata/restore')]
	public function wholeCommitRestore(): void
	{
		$first = $this->user('whole-one');
		$second = $this->user('whole-two');
		$target = $this->commit();

		$first->set('name', 'whole-one-changed')->save();
		$second->set('name', 'whole-two-changed')->save();
		$this->commit();

		$result = $this->engine()->logicalRestore()->restoreAll($target, true);

		$this->assertFalse($result->wasRefused(), (string) $result->refused);
		$this->assertCount(2, $result->restored);
		$this->assertSame('whole-one', User::load((int) $first->id())?->getAccountName());
		$this->assertSame('whole-two', User::load((int) $second->id())?->getAccountName());
	}

	#endregion

	#region The Audit

	#[Test]
	#[TestDox('the audit row is opened before the restore runs and closed with the outcome')]
	#[Group('strata/restore')]
	public function auditRecordsTheRestore(): void
	{
		$user = $this->user('audited');
		$target = $this->commit();

		$user->set('name', 'audited-changed')->save();
		$this->commit();

		$subject = 'entity/user:' . $user->id();
		$this->engine()
			->logicalRestore()
			->restore($target, [$subject], true);

		$rows = $this->audit()->recent();

		$this->assertCount(1, $rows);
		$this->assertSame($target, $rows[0]['target']);
		$this->assertSame(RestoreAudit::LOGICAL, $rows[0]['mode']);
		$this->assertSame(RestoreAudit::SUCCEEDED, $rows[0]['outcome']);
		$this->assertSame($subject, $rows[0]['scope']);
		$this->assertSame(1, (int) $rows[0]['restored']);
		$this->assertNotNull($rows[0]['finished']);
		$this->assertNotNull($rows[0]['pre_snapshot']);
		$this->assertStringContainsString('"outcome":"succeeded"', (string) $rows[0]['detail']);
		$this->assertSame([], $this->audit()->unfinished());
	}

	#[Test]
	#[TestDox('a refused restore is recorded with its reason rather than not recorded at all')]
	#[Group('strata/restore')]
	public function auditRecordsRefusal(): void
	{
		$target = $this->commit();
		$this->engine()
			->logicalRestore()
			->restore($target, ['entity/node:404'], true);

		$rows = $this->audit()->recent();

		$this->assertCount(1, $rows);
		$this->assertSame(RestoreAudit::REFUSED, $rows[0]['outcome']);
		$this->assertSame(0, (int) $rows[0]['restored']);
		$this->assertStringContainsString('"refused"', (string) $rows[0]['detail']);
	}

	#[Test]
	#[TestDox('the audit row can be read back by id')]
	#[Group('strata/restore')]
	public function auditRowReadsBackById(): void
	{
		$user = $this->user('by-id');
		$target = $this->commit();
		$user->set('name', 'by-id-changed')->save();
		$this->commit();

		$this->engine()
			->logicalRestore()
			->restore($target, ['entity/user:' . $user->id()], true);

		$id = (int) $this->audit()->recent()[0]['id'];

		$this->assertNotNull($this->audit()->get($id));
		$this->assertNull($this->audit()->get($id + 1000));
	}

	#endregion

	#region Exclusions

	#[Test]
	#[TestDox('access and login timestamps are never written back to a user')]
	#[Group('strata/restore')]
	public function accessFieldsAreNotRestored(): void
	{
		$user = $this->user('timestamped');
		$user->set('access', 1_600_000_000)->set('login', 1_600_000_000)->save();
		$target = $this->commit();

		$user->set('name', 'timestamped-renamed')->save();
		$user->set('access', 1_700_000_000)->set('login', 1_700_000_000)->save();
		$this->commit();

		$this->engine()
			->logicalRestore()
			->restore($target, ['entity/user:' . $user->id()], true);

		$reloaded = User::load((int) $user->id());

		$this->assertSame('timestamped', $reloaded?->getAccountName());
		$this->assertSame(
			1_700_000_000,
			(int) $reloaded?->get('access')->value,
			'rolling back access would make an active account look dormant',
		);
		$this->assertSame(1_700_000_000, (int) $reloaded?->get('login')->value);
	}

	#endregion

	/**
	 * Reads a scalar out of a replayed field value.
	 *
	 * Field values come back in Drupal's list-of-columns shape, so a caller comparing against a
	 * plain string has to reach through it.
	 *
	 * @param array<string, mixed> $fields
	 *   The replayed field set.
	 * @param string $name
	 *   Field name.
	 *
	 * @return string|null
	 *   The first column's value, or NULL when the field is absent.
	 */
	private function fieldValue(array $fields, string $name): ?string
	{
		$value = $fields[$name] ?? null;

		if (is_string($value)) {
			return $value;
		}
		if (!is_array($value) || $value === []) {
			return null;
		}

		$first = reset($value);

		if (is_array($first)) {
			$column = reset($first);

			return is_scalar($column) ? (string) $column : null;
		}

		return is_scalar($first) ? (string) $first : null;
	}
}
