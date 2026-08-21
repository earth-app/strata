<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Engine;
use Drupal\strata\Restore\Conflict;
use Drupal\strata\Restore\RestorePlan;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves a restore notices an edit made while the plan was waiting.
 *
 * The distinction this rests on is easy to get backwards. A rollback discards everything that happened
 * after its target - that is what a rollback is, and it is not a conflict. What is a conflict is an
 * edit somebody made after the plan was built and reviewed: the plan that was approved no longer
 * describes what is there, and applying it overwrites a change nobody saw.
 */
class ConflictTest extends StrataKernelTestBase
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

	/**
	 * Creates a user, flushes, and returns it with its subject path.
	 *
	 * @return array{User, string}
	 *   The user and its subject path.
	 */
	private function subject(string $name): array
	{
		$user = User::create(['name' => $name, 'mail' => $name . '@example.com', 'status' => 1]);
		$user->save();
		$this->engine()->flusher()->flush(true);

		return [$user, 'entity/user:' . $user->id()];
	}

	/**
	 * The commit at the head of history.
	 */
	private function head(): string
	{
		$head = $this->engine()->commitLog()->head();

		$this->assertNotNull($head);

		return $head->id();
	}

	/**
	 * A plan built as if at a given moment, so a test does not have to wait.
	 */
	private function planAt(RestorePlan $plan, int $microtime): RestorePlan
	{
		return new RestorePlan(
			$plan->target,
			$plan->depth,
			$plan->subjects,
			$plan->problems,
			$plan->fillDegraded,
			$plan->seconds,
			$microtime,
			$plan->acceptConflicts,
		);
	}

	#region Detection

	#[Test]
	#[TestDox('a rollback is not a conflict, however many edits it discards')]
	#[Group('strata/restore')]
	public function aRollbackIsNotAConflict(): void
	{
		[$user, $subject] = $this->subject('rolled-back');
		$target = $this->head();

		$user->set('name', 'edited-once')->save();
		$this->engine()->flusher()->flush(true);
		$user->set('name', 'edited-twice')->save();
		$this->engine()->flusher()->flush(true);

		$plan = $this->engine()
			->preflight()
			->planSubjects($target, [$subject]);

		$this->assertSame(
			[],
			$this->engine()->preflight()->concurrentChanges($plan),
			'the edits being rolled back are the point of the restore',
		);
		$this->assertArrayHasKey($subject, $plan->writable());
	}

	#[Test]
	#[TestDox('an edit made after the plan was built is a conflict')]
	#[Group('strata/restore')]
	public function editAfterThePlanIsAConflict(): void
	{
		[$user, $subject] = $this->subject('concurrent');
		$target = $this->head();

		// the plan was built a minute ago; the edit lands now
		$plan = $this->planAt(
			$this->engine()
				->preflight()
				->planSubjects($target, [$subject]),
			(int) round((microtime(true) - 60) * 1_000_000),
		);

		$user->set('name', 'edited-while-waiting')->save();
		$this->engine()->flusher()->flush(true);

		$conflicts = $this->engine()->preflight()->concurrentChanges($plan);

		$this->assertArrayHasKey($subject, $conflicts);
		$this->assertInstanceOf(Conflict::class, $conflicts[$subject]);
		$this->assertGreaterThan(0.0, $conflicts[$subject]->age());
		$this->assertStringContainsString(
			'no longer describes it',
			$conflicts[$subject]->describe(),
		);
	}

	#[Test]
	#[TestDox('a plan applied straight away finds no conflicts, which is the ordinary case')]
	#[Group('strata/restore')]
	public function immediateApplyHasNoConflicts(): void
	{
		[$user, $subject] = $this->subject('immediate');
		$target = $this->head();

		$user->set('name', 'changed')->save();
		$this->engine()->flusher()->flush(true);

		$plan = $this->engine()
			->preflight()
			->planSubjects($target, [$subject]);

		$this->assertSame([], $this->engine()->preflight()->concurrentChanges($plan));
	}

	#[Test]
	#[TestDox('only the subject somebody touched conflicts, not every subject in the plan')]
	#[Group('strata/restore')]
	public function onlyTheTouchedSubjectConflicts(): void
	{
		[$edited, $editedPath] = $this->subject('edited');
		[, $untouchedPath] = $this->subject('untouched');
		$target = $this->head();

		$planned = (int) round(microtime(true) * 1_000_000);
		$plan = $this->planAt(
			$this->engine()
				->preflight()
				->planSubjects($target, [$editedPath, $untouchedPath]),
			$planned,
		);

		$edited->set('name', 'edited-while-waiting')->save();
		$this->engine()->flusher()->flush(true);

		$conflicts = $this->engine()->preflight()->concurrentChanges($plan);

		$this->assertArrayHasKey($editedPath, $conflicts);
		$this->assertArrayNotHasKey($untouchedPath, $conflicts);
	}

	#[Test]
	#[TestDox('a plan with no build time recorded detects nothing rather than guessing')]
	#[Group('strata/restore')]
	public function planWithoutABuildTimeDetectsNothing(): void
	{
		[$user, $subject] = $this->subject('untimed');
		$target = $this->head();

		$user->set('name', 'changed')->save();
		$this->engine()->flusher()->flush(true);

		$plan = $this->planAt(
			$this->engine()
				->preflight()
				->planSubjects($target, [$subject]),
			0,
		);

		$this->assertSame([], $this->engine()->preflight()->concurrentChanges($plan));
	}

	#[Test]
	#[TestDox('a plan records when it was built, which is what a conflict is measured against')]
	#[Group('strata/restore')]
	public function planRecordsWhenItWasBuilt(): void
	{
		[, $subject] = $this->subject('timed');
		$plan = $this->engine()
			->preflight()
			->planSubjects($this->head(), [$subject]);

		$this->assertGreaterThan(0, $plan->plannedAt);
		$this->assertSame($plan->plannedAt, $plan->jsonSerialize()['plannedAt']);
	}

	#endregion

	#region Applying

	#[Test]
	#[TestDox('a conflicted subject is skipped and named rather than silently overwritten')]
	#[Group('strata/restore')]
	public function conflictedSubjectIsSkipped(): void
	{
		[$user, $subject] = $this->subject('protected');
		$target = $this->head();

		$user->set('name', 'edited-while-waiting')->save();
		$this->engine()->flusher()->flush(true);

		$plan = $this->planAt(
			$this->engine()
				->preflight()
				->planSubjects($target, [$subject]),
			(int) round((microtime(true) - 60) * 1_000_000),
		);

		$result = $this->engine()->logicalRestore()->apply($plan, 'test');

		$this->assertSame([], $result->restored);
		$this->assertArrayHasKey($subject, $result->skipped);
		$this->assertStringContainsString('no longer describes it', $result->skipped[$subject]);

		$reloaded = User::load((int) $user->id());

		$this->assertSame(
			'edited-while-waiting',
			$reloaded?->getAccountName(),
			'the concurrent edit is still there',
		);
	}

	#[Test]
	#[
		TestDox(
			'accepting conflicts writes the subject anyway, since that is a decision somebody made',
		),
	]
	#[Group('strata/restore')]
	public function acceptedConflictIsWritten(): void
	{
		[$user, $subject] = $this->subject('overridden');
		$target = $this->head();

		$user->set('name', 'edited-while-waiting')->save();
		$this->engine()->flusher()->flush(true);

		$planned = (int) round((microtime(true) - 60) * 1_000_000);
		$base = $this->engine()
			->preflight()
			->planSubjects($target, [$subject], false, true);
		$plan = new RestorePlan(
			$base->target,
			$base->depth,
			$base->subjects,
			$base->problems,
			$base->fillDegraded,
			$base->seconds,
			$planned,
			true,
		);

		$result = $this->engine()->logicalRestore()->apply($plan, 'test');

		$this->assertSame([$subject], $result->restored);
		$this->assertSame('overridden', User::load((int) $user->id())?->getAccountName());
	}

	#[Test]
	#[TestDox('a dry run reports the conflict without writing anything')]
	#[Group('strata/restore')]
	public function dryRunReportsTheConflict(): void
	{
		[$user, $subject] = $this->subject('dry');
		$target = $this->head();

		$user->set('name', 'edited-while-waiting')->save();
		$this->engine()->flusher()->flush(true);

		$plan = $this->planAt(
			$this->engine()
				->preflight()
				->planSubjects($target, [$subject]),
			(int) round((microtime(true) - 60) * 1_000_000),
		);

		$result = $this->engine()->logicalRestore()->apply($plan, 'test', false);

		$this->assertSame([], $result->restored);
		$this->assertArrayHasKey($subject, $result->skipped);
		$this->assertSame('edited-while-waiting', User::load((int) $user->id())?->getAccountName());
	}

	#endregion

	#region The Value Object

	#[Test]
	#[TestDox('a conflict describes its age in the largest unit that still reads naturally')]
	#[Group('strata/restore')]
	public function ageReadsNaturally(): void
	{
		$planned = 1_700_000_000_000_000;

		$this->assertStringContainsString(
			'seconds',
			(new Conflict('entity/user:1', $planned + 30_000_000, $planned))->duration(),
		);
		$this->assertStringContainsString(
			'minutes',
			(new Conflict('entity/user:1', $planned + 600_000_000, $planned))->duration(),
		);
		$this->assertStringContainsString(
			'hours',
			(new Conflict('entity/user:1', $planned + 7_200_000_000, $planned))->duration(),
		);
		$this->assertStringContainsString(
			'days',
			(new Conflict('entity/user:1', $planned + 172_800_000_000, $planned))->duration(),
		);
	}

	#[Test]
	#[TestDox('a change recorded before the plan reports no age rather than a negative one')]
	#[Group('strata/restore')]
	public function ageIsNeverNegative(): void
	{
		$conflict = new Conflict('entity/user:1', 1_000, 2_000);

		$this->assertSame(0.0, $conflict->age());
		$this->assertSame('0 seconds', $conflict->duration());
	}

	#[Test]
	#[TestDox('a conflict serializes to something the audit log can keep')]
	#[Group('strata/restore')]
	public function conflictSerializes(): void
	{
		$data = (new Conflict('entity/user:1', 2_000_000, 1_000_000))->jsonSerialize();

		$this->assertSame('entity/user:1', $data['subject']);
		$this->assertSame(2_000_000, $data['changed_at']);
		$this->assertSame(1_000_000, $data['planned_at']);
		$this->assertSame(1.0, $data['age_seconds']);
	}

	#endregion
}
