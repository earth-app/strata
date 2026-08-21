<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Drill\DrillIndex;
use Drupal\strata\Drill\DrillReport;
use Drupal\strata\Drill\DrillRunner;
use Drupal\strata\Engine;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\HealthLedgerInterface;
use Drupal\strata\Tree\SubjectIndex;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves a drill measures the store against the site rather than assuming either side.
 *
 * Three of these pin properties that were absent when the lane was written and are easy to lose
 * again. `entitySubjectPathUsesAColon` fixes the subject format at `entity/user:1`, because a runner
 * that split it on a slash reported every entity as a realm it could not read and a drill of an
 * entity-only site could only ever come back `inconclusive`. `subjectChangedAfterTheCommitIsSkipped`
 * pins the rule both class docblocks describe: a subject the site has moved on from is skipped, never
 * counted as drift. `inconclusiveDrillWarns` pins that a drill which judged nothing records a warning
 * and reports `inconclusive` rather than `pass`.
 *
 * Capture is narrowed per region rather than globally, because a whole-population verdict is only
 * assertable when the population is one realm.
 */
class DrillTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'field', 'key', 'strata'];

	/**
	 * A state key the state-realm cases use.
	 */
	private const STATE_KEY = 'strata_drill_test.value';

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
			->set('capture.config', false)
			->set('capture.state', false)
			->set('capture.keyvalue', false)
			->set('capture.table', false)
			->set('capture.schema', false)
			->set('capture.code', false)
			->set('capture.statements', false)
			->save();

		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();
	}

	private function engine(): Engine
	{
		return $this->container->get('strata.engine');
	}

	private function drill(): DrillRunner
	{
		return $this->engine()->drillRunner();
	}

	private function drillIndex(): DrillIndex
	{
		return $this->engine()->drillIndex();
	}

	private function subjects(): SubjectIndex
	{
		return $this->engine()->subjectIndex();
	}

	private function ledger(): HealthLedgerInterface
	{
		return $this->engine()->ledger();
	}

	private function user(string $name): User
	{
		$user = User::create(['name' => $name, 'mail' => $name . '@example.com', 'status' => 1]);
		$user->save();

		return $user;
	}

	/**
	 * Swaps entity capture for state capture, so a population is one readable realm.
	 */
	private function captureStateOnly(): void
	{
		$this->config('strata.settings')
			->set('capture.entity', false)
			->set('capture.state', true)
			->save();

		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();
	}

	/**
	 * Captures only the table realm, which a drill can store but cannot read back.
	 */
	private function captureTablesOnly(): void
	{
		$this->config('strata.settings')
			->set('capture.entity', false)
			->set('capture.state', false)
			->set('capture.table', true)
			->set('capture.statements', true)
			->save();

		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();
	}

	/**
	 * Seals whatever is pending.
	 *
	 * @return string
	 *   The commit the flush sealed.
	 */
	private function flush(): string
	{
		$result = $this->engine()->flusher()->flush(true);

		$this->assertTrue($result->ran, 'the flush sealed a commit');

		return (string) $result->commit;
	}

	/**
	 * Saves a user and seals the window.
	 *
	 * @param string $name
	 *   The account name.
	 *
	 * @return array{User, string}
	 *   The account and the commit the flush sealed.
	 */
	private function commitUser(string $name): array
	{
		$user = $this->user($name);

		return [$user, $this->flush()];
	}

	/**
	 * Frame and pack objects the store holds.
	 *
	 * @return list<string>
	 *   The keys.
	 */
	private function frameObjects(): array
	{
		$provider = $this->engine()->provider();

		return array_merge(
			$provider->list('frames/', null, 500)->keys(),
			$provider->list('packs/', null, 500)->keys(),
		);
	}

	/**
	 * Open findings recorded under one code.
	 *
	 * @param string $code
	 *   The finding code.
	 *
	 * @return list<Finding>
	 *   The findings.
	 */
	private function findings(string $code): array
	{
		return array_values(
			array_filter(
				$this->ledger()->open(),
				static fn(Finding $finding): bool => $finding->code === $code,
			),
		);
	}

	#region Refusals

	#[Test]
	#[TestDox('a drill against a store with no history is refused')]
	#[Group('strata/drill')]
	public function noHistoryIsRefused(): void
	{
		$report = $this->drill()->run();

		$this->assertTrue($report->wasRefused());
		$this->assertSame('there is no history to drill against', $report->refused);
		$this->assertSame('refused', $report->verdict());
		$this->assertFalse($report->passed());
		$this->assertSame('', $report->target);
	}

	#[Test]
	#[TestDox('a drill against commits with no captured subject is refused, not inconclusive')]
	#[Group('strata/drill')]
	public function noSubjectsIsRefused(): void
	{
		[, $commit] = $this->commitUser('subjectless');

		$this->subjects()->clear();

		$report = $this->drill()->run();

		$this->assertTrue($report->wasRefused());
		$this->assertSame('no subject has been captured yet', $report->refused);
		$this->assertSame($commit, $report->target, 'the commit it would have used is reported');
	}

	#endregion

	#region Entity subjects

	#[Test]
	#[TestDox('the subject index names an entity with a colon, which is what a drill has to read')]
	#[Group('strata/drill')]
	public function entitySubjectPathUsesAColon(): void
	{
		[$user] = $this->commitUser('addressed');

		$this->assertArrayHasKey('entity/user:' . $user->id(), $this->subjects()->all());
	}

	#[Test]
	#[TestDox('an untouched entity is judged rather than dismissed as an unreadable realm')]
	#[Group('strata/drill')]
	public function entitySubjectIsJudged(): void
	{
		[$user, $commit] = $this->commitUser('judged');
		$subject = 'entity/user:' . $user->id();

		[$status, $detail] = $this->drill()->examine($subject, $commit);

		$this->assertSame(
			DrillReport::MATCHED,
			$status,
			sprintf('%s was reported %s: %s', $subject, $status, $detail),
		);
	}

	#[Test]
	#[TestDox('an entity the site has deleted is judged rather than dismissed')]
	#[Group('strata/drill')]
	public function deletedEntityIsJudged(): void
	{
		[$user, $commit] = $this->commitUser('deleted-live');
		$subject = 'entity/user:' . $user->id();

		$user->delete();

		[$status] = $this->drill()->examine($subject, $commit);

		$this->assertNotSame(
			DrillReport::SKIPPED,
			$status,
			'a subject the store holds and the site does not is a difference, not a skip',
		);
	}

	#[Test]
	#[TestDox('a realm a drill genuinely cannot read back is skipped')]
	#[Group('strata/drill')]
	public function unreadableRealmIsSkipped(): void
	{
		[, $commit] = $this->commitUser('realm-check');

		[$status, $detail] = $this->drill()->examine('table/strata_journal', $commit);

		$this->assertSame(DrillReport::SKIPPED, $status);
		$this->assertSame('the subject does not exist at that commit', $detail);
	}

	#[Test]
	#[TestDox('a subject the commit never held is skipped rather than called a difference')]
	#[Group('strata/drill')]
	public function unknownSubjectIsSkipped(): void
	{
		[, $commit] = $this->commitUser('known');

		[$status] = $this->drill()->examine('entity/user:999', $commit);

		$this->assertSame(DrillReport::SKIPPED, $status);
	}

	#endregion

	#region State subjects

	#[Test]
	#[TestDox('an untouched state value reproduces exactly and the drill passes')]
	#[Group('strata/drill')]
	public function untouchedStateMatches(): void
	{
		$this->captureStateOnly();
		$this->container->get('state')->set(self::STATE_KEY, 'the captured value');
		$this->flush();

		$this->assertArrayHasKey('state/' . self::STATE_KEY, $this->subjects()->all());

		$report = $this->drill()->run();

		$this->assertArrayHasKey('state/' . self::STATE_KEY, $report->matched);
		$this->assertSame([], $report->differed, $report->summary());
		$this->assertSame([], $report->unreadable, $report->summary());
		$this->assertSame('pass', $report->verdict(), $report->summary());
		$this->assertTrue($report->passed());
		$this->assertSame(1.0, $report->accuracy());
	}

	#[Test]
	#[TestDox('a subject changed after the replayed commit is skipped, never counted as drift')]
	#[Group('strata/drill')]
	public function subjectChangedAfterTheCommitIsSkipped(): void
	{
		$this->captureStateOnly();

		$state = $this->container->get('state');
		$state->set(self::STATE_KEY, 'as captured');
		$earlier = $this->flush();

		$state->set(self::STATE_KEY, 'as the site holds it now');
		$this->flush();

		$subject = 'state/' . self::STATE_KEY;

		$this->assertGreaterThan(
			0,
			(int) $this->subjects()->changedAt($subject),
			'the index knows the subject moved on',
		);

		[$status, $detail] = $this->drill()->examine($subject, $earlier);

		$this->assertSame(
			DrillReport::SKIPPED,
			$status,
			sprintf(
				'the site being newer is not drift, but it was reported %s: %s',
				$status,
				$detail,
			),
		);
	}

	#[Test]
	#[TestDox('a state value the site has dropped is a difference')]
	#[Group('strata/drill')]
	public function deletedStateDiffers(): void
	{
		$this->captureStateOnly();

		$state = $this->container->get('state');
		$state->set(self::STATE_KEY, 'about to go');
		$commit = $this->flush();

		$state->delete(self::STATE_KEY);

		[$status, $detail] = $this->drill()->examine('state/' . self::STATE_KEY, $commit);

		$this->assertSame(DrillReport::DIFFERED, $status);
		$this->assertSame('the store holds a value and the site does not', $detail);
	}

	#endregion

	#region Unreadable stores

	#[Test]
	#[TestDox('a store whose frames were deleted reports the subject unreadable and fails')]
	#[Group('strata/drill')]
	public function deletedFrameIsUnreadable(): void
	{
		$this->captureStateOnly();
		$this->container->get('state')->set(self::STATE_KEY, 'doomed');
		$this->flush();

		$objects = $this->frameObjects();

		$this->assertNotEmpty($objects, 'the flush stored frames to delete');
		$this->assertGreaterThan(0, $this->engine()->provider()->delete($objects));

		$report = $this->drill()->run();

		$this->assertNotSame([], $report->unreadable, $report->summary());
		$this->assertSame('fail', $report->verdict());
		$this->assertFalse($report->passed());
		$this->assertSame(0.0, $report->accuracy());
	}

	#endregion

	#region Sampling

	#[Test]
	#[TestDox('an explicit sample smaller than the population examines exactly that many subjects')]
	#[Group('strata/drill')]
	public function sampleBoundsTheWork(): void
	{
		for ($i = 0; $i < 6; $i++) {
			$this->user('sampled-' . $i);
		}

		$this->flush();

		$this->assertCount(6, $this->subjects()->all());

		$report = $this->drill()->run(null, 2);

		$this->assertSame(2, $report->sampled);
		$this->assertSame(6, $report->population);
		$this->assertSame(
			2,
			count($report->matched) +
				count($report->differed) +
				count($report->skipped) +
				count($report->unreadable),
		);
	}

	#[Test]
	#[TestDox('the same store drilled twice picks the same subjects, so a failure reproduces')]
	#[Group('strata/drill')]
	public function samplingIsDeterministic(): void
	{
		for ($i = 0; $i < 8; $i++) {
			$this->user('strided-' . $i);
		}

		$this->flush();

		$first = $this->drill()->run(null, 3);
		$second = $this->drill()->run(null, 3);

		$this->assertSame(3, $first->sampled);
		$this->assertSame(3, $second->sampled);
		$this->assertSame(
			array_keys($first->skipped + $first->matched),
			array_keys($second->skipped + $second->matched),
		);
	}

	#[Test]
	#[TestDox('a negative sample examines every subject the index holds')]
	#[Group('strata/drill')]
	public function negativeSampleExaminesEverything(): void
	{
		for ($i = 0; $i < 4; $i++) {
			$this->user('exhaustive-' . $i);
		}

		$this->flush();

		$report = $this->drill()->run(null, -1);

		$this->assertSame(4, $report->sampled);
		$this->assertSame(4, $report->population);
	}

	#endregion

	#region Recording

	#[Test]
	#[TestDox('a drill records a row the index reads back')]
	#[Group('strata/drill')]
	public function drillRecordsARow(): void
	{
		$this->captureStateOnly();
		$this->container->get('state')->set(self::STATE_KEY, 'recorded');
		$commit = $this->flush();

		$this->assertNull($this->drillIndex()->newest());
		$this->assertSame(0, $this->drillIndex()->lastRunAt());

		$report = $this->drill()->run();
		$row = $this->drillIndex()->newest();

		$this->assertNotNull($row);
		$this->assertSame($commit, $row['target']);
		$this->assertSame('replay', $row['mode']);
		$this->assertSame($report->verdict(), $row['verdict']);
		$this->assertSame(count($report->matched), (int) $row['matched']);
		$this->assertSame(count($report->differed), (int) $row['differed']);
		$this->assertSame(count($report->skipped), (int) $row['skipped']);
		$this->assertSame(count($report->unreadable), (int) $row['unreadable']);
		$this->assertSame($report->sampled, (int) $row['sampled']);
		$this->assertSame($report->population, (int) $row['population']);
		$this->assertSame($report->ranAt, (int) $row['created']);
		$this->assertSame($report->summary(), $row['detail']);
		$this->assertSame($report->ranAt, $this->drillIndex()->lastRunAt());
	}

	#[Test]
	#[TestDox('verdicts() counts the drills by their verdict')]
	#[Group('strata/drill')]
	public function verdictsCountTheHistory(): void
	{
		$this->assertSame(
			['passed' => 0, 'failed' => 0, 'inconclusive' => 0, 'refused' => 0],
			$this->drillIndex()->verdicts(),
		);

		$this->captureStateOnly();
		$this->container->get('state')->set(self::STATE_KEY, 'counted');
		$this->flush();

		$this->drill()->run();
		$this->drill()->run();

		$this->assertSame(2, $this->drillIndex()->verdicts()['passed']);
		$this->assertCount(2, $this->drillIndex()->recent());
	}

	#[Test]
	#[TestDox('a passing drill records no drift finding')]
	#[Group('strata/drill')]
	public function passingDrillRecordsNoFinding(): void
	{
		$this->captureStateOnly();
		$this->container->get('state')->set(self::STATE_KEY, 'clean');
		$this->flush();

		$report = $this->drill()->run();

		$this->assertSame('pass', $report->verdict(), $report->summary());
		$this->assertSame([], $this->findings(DrillRunner::CODE));
	}

	#[Test]
	#[TestDox('a failing drill records a drift finding at error severity')]
	#[Group('strata/drill')]
	public function failingDrillRecordsDrift(): void
	{
		$this->captureStateOnly();
		$this->container->get('state')->set(self::STATE_KEY, 'drifting');
		$this->flush();

		$this->engine()->provider()->delete($this->frameObjects());

		$report = $this->drill()->run();
		$findings = $this->findings(DrillRunner::CODE);

		$this->assertSame('fail', $report->verdict());
		$this->assertCount(1, $findings);
		$this->assertSame($report->target, $findings[0]->scope);
		$this->assertSame(Finding::ERROR, $findings[0]->severity);
		$this->assertStringContainsString('unreadable', $findings[0]->context);
	}

	#[Test]
	#[TestDox('a drill that could judge nothing records a warning rather than an error')]
	#[Group('strata/drill')]
	public function inconclusiveDrillWarns(): void
	{
		// the table realm records that a table changed rather than which rows, so a drill can store it
		// and has nothing to read back; a population made only of it is judgeable by nothing
		$this->captureTablesOnly();

		$this->container
			->get('database')
			->schema()
			->createTable('drill_widget', [
				'fields' => ['id' => ['type' => 'serial', 'not null' => true]],
				'primary key' => ['id'],
			]);

		$tap = $this->container->get('strata.statement_capture');
		$tap->enable();
		$this->container
			->get('database')
			->insert('drill_widget')
			->fields(['id' => 1])
			->execute();
		$tap->commit();

		$commit = $this->flush();
		$report = $this->drill()->run($commit);
		$findings = $this->findings(DrillRunner::CODE);

		$this->assertSame(0, $report->judged(), $report->summary());
		$this->assertSame('inconclusive', $report->verdict());
		$this->assertCount(1, $findings);
		$this->assertSame(Finding::WARN, $findings[0]->severity, 'a coverage gap is a warning');
	}

	#endregion
}
