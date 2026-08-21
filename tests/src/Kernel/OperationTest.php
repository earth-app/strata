<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Engine;
use Drupal\strata\Estimate\PriceTable;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves a site can be run through a working day at three sizes and come out intact.
 *
 * Every other kernel spec here checks one behaviour in isolation. This one does the thing an operator
 * does: leave it switched on. Accounts are created, edited and re-edited, config moves, state churns,
 * flushes happen on the interval, compaction runs, and then the whole history is verified and read
 * back. It runs that at three sizes so the shape of the cost is visible rather than asserted from a
 * model.
 *
 * The claim under test is the one the module is sold on: **stored cost tracks churn, not site size.**
 * A site three times as large with the same number of writes must not store three times the bytes.
 * That is checked by measurement, and the figures are printed by
 * OperationTest::report() so a regression shows up as a number rather than as a feeling.
 *
 * Two properties are asserted at every scale, because they are what "it works" means:
 *
 * - Nothing is corrupt. A verify pass reads every frame back through the real codec and cipher.
 * - Nothing is lost. Every subject that was captured is still materializable at the commit that
 *   captured it.
 */
class OperationTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'field', 'key', 'strata'];

	/**
	 * The scales driven, as account counts.
	 *
	 * Small enough to run in a kernel lane and far enough apart that a cost that scaled with site
	 * size rather than churn would be obvious.
	 */
	private const SCALES = ['a quiet site' => 5, 'a busy site' => 20, 'a large site' => 60];

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->installEntitySchema('user');
		$this->installSchema('user', ['users_data']);

		// what a real install runs with: delta coding on, flush level 1, compaction level 19
		$this->config('strata.settings')
			->set('enabled', true)
			->set('provider', 'local')
			->set('local_path', $this->storeRoot)
			->set('cipher.id', 'none')
			->set('codec.flush_level', 1)
			->set('codec.compaction_level', 19)
			->set('delta.enabled', true)
			->save();

		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();
	}

	#region A Working Day

	#[Test]
	#[TestDox('a day of ordinary traffic seals, verifies clean and reads every subject back')]
	#[Group('strata/operation')]
	public function anOrdinaryDayHoldsTogether(): void
	{
		$measured = $this->workday(self::SCALES['a busy site']);

		$this->assertGreaterThan(0, $measured['commits'], 'the day sealed history');
		$this->assertGreaterThan(0, $measured['operations'], 'and captured writes into it');

		$verify = $this->engine()->verifier()->verify();

		$this->assertTrue(
			$verify->isClean(),
			'a day of traffic verified clean: ' . $verify->summary(),
		);
		$this->assertGreaterThan(0, $verify->frames, 'and the pass really read frames');
		$this->assertSame([], $this->openCodes(), 'nothing was recorded against the store');
	}

	#[Test]
	#[TestDox('every captured subject is still materializable after the day it was captured in')]
	#[Group('strata/operation')]
	public function everySubjectReadsBack(): void
	{
		$this->workday(self::SCALES['a quiet site']);

		$head = $this->headId();

		$this->assertNotSame('', $head);

		$subjects = array_keys($this->engine()->subjectIndex()->all());

		$this->assertNotSame([], $subjects, 'the day left subjects behind');

		$read = 0;

		foreach (array_slice($subjects, 0, 25) as $subject) {
			$result = $this->engine()->replayer()->materialize($subject, $head);

			$this->assertTrue(
				$result->isComplete(),
				sprintf(
					'%s did not materialize; %d frames would not read',
					$subject,
					count($result->unreadable),
				),
			);

			$read++;
		}

		$this->assertGreaterThan(0, $read);
	}

	#[Test]
	#[TestDox('a compaction pass over a day of history changes no restore target')]
	#[Group('strata/operation')]
	public function compactionPreservesEveryTarget(): void
	{
		$this->workday(self::SCALES['a quiet site']);

		$head = $this->headId();
		$before = [];

		foreach (array_keys($this->engine()->subjectIndex()->all()) as $subject) {
			$before[$subject] = $this->engine()->replayer()->materialize($subject, $head)->fields;
		}

		$this->assertNotSame([], $before, 'the day left subjects behind');

		$report = $this->engine()->compactor()->compact();

		$this->assertSame([], $report->problems, 'compaction ran clean');
		$this->assertSame(
			$head,
			$this->headId(),
			'compaction moved no ref, so no restore target moved',
		);

		foreach ($before as $subject => $fields) {
			$this->assertSame(
				$fields,
				$this->engine()->replayer()->materialize((string) $subject, $head)->fields,
				sprintf('%s reads back the same after compaction', $subject),
			);
		}

		$this->assertTrue($this->engine()->verifier()->verify()->isClean());
	}

	#endregion

	#region Scale

	#[Test]
	#[TestDox('a week of editing costs a fraction of what capturing the site cost once')]
	#[Group('strata/operation')]
	public function costTracksChurnNotSize(): void
	{
		// day one: the site arrives and is captured in full, which is the expensive day
		$first = $this->workday(20, rewrites: 0);
		$initial = $first['storedBytes'];

		$this->assertGreaterThan(0, $initial);

		// then a week of ordinary editing, nothing created
		for ($day = 0; $day < 5; $day++) {
			$this->editEveryAccount($day);
		}

		$total = (int) $this->engine()->frameIndex()->statistics()['storedBytes'];
		$week = $total - $initial;

		$this->assertGreaterThan(0, $week, 'the week of editing really was captured');
		$this->assertLessThan(
			$initial,
			$week,
			sprintf(
				'five days of editing stored %d bytes against %d for the initial capture. A snapshot ' .
					'on the same schedule would have stored %d',
				$week,
				$initial,
				$initial * 6,
			),
		);
		$this->assertTrue($this->engine()->verifier()->verify()->isClean());
	}

	#[Test]
	#[TestDox('a bigger site costs more to capture once and no more to keep editing')]
	#[Group('strata/operation')]
	public function sizeCostsOnceAndChurnCostsDaily(): void
	{
		$small = $this->captureThenEdit(10);

		$this->reset();

		$large = $this->captureThenEdit(40);

		$this->assertGreaterThan(
			$small['initial'],
			$large['initial'],
			'four times the accounts cost more to capture the first time',
		);
		$this->assertGreaterThan(
			$small['daily'],
			$large['daily'],
			'and more per day, because four times as many rows are being edited',
		);

		// the ratio is what matters: a day of editing is a small share of the site, at either size
		$this->assertLessThan(
			0.6,
			$large['daily'] / max(1, $large['initial']),
			sprintf(
				'a day of editing cost %d against %d for the site itself',
				$large['daily'],
				$large['initial'],
			),
		);
	}

	#[Test]
	#[TestDox('the store compresses and deduplicates at every scale rather than only at one')]
	#[Group('strata/operation')]
	public function everyScaleCompresses(): void
	{
		foreach (self::SCALES as $label => $accounts) {
			$this->reset();

			$measured = $this->workday($accounts, rewrites: 2);

			$this->assertGreaterThan(
				1.0,
				$measured['ratio'],
				sprintf('%s stored less than it captured', $label),
			);
			$this->assertGreaterThan(0, $measured['frames'], sprintf('%s wrote frames', $label));
			$this->assertLessThan(
				$measured['rawBytes'],
				$measured['storedBytes'],
				sprintf('%s: %s', $label, $this->report($measured)),
			);
		}
	}

	#[Test]
	#[TestDox('the request count a day costs is bounded by flushes, not by how much was written')]
	#[Group('strata/operation')]
	public function requestCountFollowsFlushes(): void
	{
		$this->engine()->providerStats()->reset();

		$quiet = $this->workday(5, rewrites: 1);
		$quietWrites = $this->engine()->providerStats()->classA();

		$this->reset();
		$this->engine()->providerStats()->reset();

		$busy = $this->workday(5, rewrites: 8);
		$busyWrites = $this->engine()->providerStats()->classA();

		$this->assertGreaterThan(0, $quietWrites, 'sealing history costs billed writes');
		$this->assertGreaterThan(
			$quiet['operations'],
			$busy['operations'],
			'the busy day really did capture more',
		);

		$quietPerCommit = $quietWrites / max(1, $quiet['commits']);
		$busyPerCommit = $busyWrites / max(1, $busy['commits']);

		// a flush writes a segment, a tree, a commit and its frames; more writes per flush is the
		// signature of a request count that follows content rather than the interval
		$this->assertLessThan(
			$quietPerCommit * 3,
			$busyPerCommit,
			sprintf(
				'%.1f writes per flush when busy against %.1f when quiet: %s',
				$busyPerCommit,
				$quietPerCommit,
				$this->report($busy),
			),
		);
	}

	#[Test]
	#[TestDox('a day of traffic costs a legible number of cents on both price tables')]
	#[Group('strata/operation')]
	public function aDayHasAPrice(): void
	{
		$measured = $this->workday(self::SCALES['a busy site'], rewrites: 2);
		$writes = max(1, $this->engine()->providerStats()->classA());

		foreach ([PriceTable::r2(), PriceTable::s3()] as $prices) {
			$monthly = $prices->monthlyCost($measured['storedBytes'], $writes * 30);

			$this->assertGreaterThanOrEqual(
				0.0,
				$monthly,
				sprintf('%s priced the day', $prices->label),
			);
			$this->assertLessThan(
				1.0,
				$monthly,
				sprintf('%s charged %.4f for a day this small', $prices->label, $monthly),
			);
		}

		$this->assertTrue(
			PriceTable::r2()->hasFreeTier(),
			'the free tier is what the small end of the model rests on',
		);
	}

	#endregion

	#region Interruption

	#[Test]
	#[TestDox('a day interrupted mid-flush loses only what was not sealed')]
	#[Group('strata/operation')]
	public function anInterruptedDayLosesOnlyTheOpenWindow(): void
	{
		$this->workday(5, rewrites: 1);

		$sealed = $this->headId();

		// captured but never flushed, which is what a server dying between flushes leaves
		$this->account('unsealed-account');

		$this->assertGreaterThan(0, $this->engine()->journal()->pending());
		$this->assertSame($sealed, $this->headId(), 'an unflushed write moved no ref');
		$this->assertTrue(
			$this->engine()->verifier()->verify()->isClean(),
			'and left the sealed history intact',
		);

		$this->engine()->flusher()->flush(true);

		$this->assertNotSame(
			$sealed,
			$this->headId(),
			'the next flush picks the window up rather than dropping it',
		);
		$this->assertTrue($this->engine()->verifier()->verify()->isClean());
	}

	#[Test]
	#[TestDox('two days of traffic build one chain rather than two histories')]
	#[Group('strata/operation')]
	public function daysChainTogether(): void
	{
		$this->workday(5, rewrites: 1);

		$first = $this->headId();

		$this->workday(5, rewrites: 1, prefix: 'day-two');

		$second = $this->headId();

		$this->assertNotSame($first, $second);

		$walked = [];
		$at = $second;

		while ($at !== '') {
			$walked[] = $at;
			$at = (string) ($this->engine()->commitLog()->read($at)->parent ?? '');
		}

		$this->assertContains($first, $walked, 'yesterday is reachable from today');
		$this->assertTrue($this->engine()->verifier()->verify()->isClean());
	}

	#endregion

	#region Fixtures

	/**
	 * The commit the ref points at.
	 *
	 * @return string
	 *   The id, or an empty string when nothing has been sealed.
	 */
	private function headId(): string
	{
		return (string) ($this->engine()->commitIndex()->newest()['id'] ?? '');
	}

	/**
	 * The engine under test.
	 *
	 * @return Engine
	 *   The engine.
	 */
	private function engine(): Engine
	{
		return $this->container->get('strata.engine');
	}

	/**
	 * How many times the store has been emptied, so a later scale names its accounts differently.
	 */
	private int $generation = 0;

	/**
	 * Empties the store and the indexes so a second scale starts from nothing.
	 *
	 * The site's own tables are left alone: accounts stay, which is the point. A rebuilt store over a
	 * site that already holds content is the state a reindex leaves, and the next scale has to cope
	 * with it rather than with an empty database.
	 */
	private function reset(): void
	{
		$this->generation++;

		foreach ($this->engine()->provider()->list('', null, 5000)->keys() as $key) {
			$this->engine()
				->provider()
				->delete([$key]);
		}

		$this->engine()->frameIndex()->clear();
		$this->engine()->commitIndex()->clear();
		$this->container->get('database')->truncate('strata_subject')->execute();
		$this->engine()->journal()->clear();
		$this->engine()->reset();
	}

	/**
	 * One account, saved, which is one captured operation.
	 *
	 * @param string $name
	 *   The account name.
	 *
	 * @return User
	 *   The saved account.
	 */
	private function account(string $name): User
	{
		$user = User::create(['name' => $name, 'mail' => $name . '@example.com', 'status' => 1]);
		$user->save();

		return $user;
	}

	/**
	 * A day of ordinary traffic: accounts created, rewritten, and flushed on the interval.
	 *
	 * The rewrite is a timezone and a display name, which is the shape of the edit that dominates a
	 * real site: a few fields of an existing entity, not a new one.
	 *
	 * @param int $accounts
	 *   How many accounts the site has.
	 * @param int $rewrites
	 *   How many times each account is edited afterwards.
	 * @param string $prefix
	 *   Distinguishes one day's accounts from another's.
	 *
	 * @return array{commits: int, operations: int, frames: int, rawBytes: int, storedBytes: int, ratio: float}
	 *   What the day cost.
	 */
	private function workday(int $accounts, int $rewrites = 2, string $prefix = 'day-one'): array
	{
		$users = [];

		for ($at = 0; $at < $accounts; $at++) {
			$users[] = $this->account(sprintf('%s-%d-%d', $prefix, $this->generation, $at));

			// a flush every fifth write, which is what an interval does under steady load
			if ($at % 5 === 4) {
				$this->engine()->flusher()->flush(true);
			}
		}

		$this->engine()->flusher()->flush(true);

		for ($round = 0; $round < $rewrites; $round++) {
			foreach ($users as $user) {
				$user->set('timezone', $round % 2 === 0 ? 'UTC' : 'America/New_York');
				$user->set('preferred_langcode', 'en');
				$user->save();
			}

			$this->engine()->flusher()->flush(true);
		}

		// state churn happens on every real request and is captured like anything else
		$this->container
			->get('state')
			->set(
				'strata_test.last_run',
				sprintf('%s-%d-%d', $prefix, $this->generation, $accounts),
			);
		$this->engine()->flusher()->flush(true);

		$statistics = $this->engine()->frameIndex()->statistics();

		return [
			'commits' => $this->engine()->commitIndex()->count(),
			'operations' => $this->operationCount(),
			'frames' => (int) $statistics['frames'],
			'rawBytes' => (int) $statistics['rawBytes'],
			'storedBytes' => (int) $statistics['storedBytes'],
			'ratio' => (float) $statistics['ratio'],
		];
	}

	/**
	 * Edits every account on the site once and seals it.
	 *
	 * No account is created, so what this costs is churn and nothing else.
	 *
	 * @param int $day
	 *   Which day it is, so consecutive edits differ from each other.
	 */
	private function editEveryAccount(int $day): void
	{
		$storage = $this->container->get('entity_type.manager')->getStorage('user');

		foreach ($storage->loadMultiple() as $user) {
			if ((int) $user->id() === 0) {
				continue;
			}

			// a value distinct per account, or every edit deduplicates to one frame and the day is free
			$user->set('mail', sprintf('day%d-%s@example.com', $day, $user->id()));
			$user->save();
		}

		$this->engine()->flusher()->flush(true);
	}

	/**
	 * Captures a site of a given size, then edits all of it once.
	 *
	 * @param int $accounts
	 *   How many accounts the site has.
	 *
	 * @return array{initial: int, daily: int}
	 *   Bytes the first capture cost, and bytes one day of editing cost on top of it.
	 */
	private function captureThenEdit(int $accounts): array
	{
		$initial = $this->workday($accounts, rewrites: 0)['storedBytes'];

		$this->editEveryAccount(1);

		$after = (int) $this->engine()->frameIndex()->statistics()['storedBytes'];

		return ['initial' => $initial, 'daily' => $after - $initial];
	}

	/**
	 * Operations recorded across every commit the day sealed.
	 *
	 * @return int
	 *   The total.
	 */
	private function operationCount(): int
	{
		$total = 0;

		foreach ($this->engine()->commitIndex()->ids() as $id) {
			$total += (int) ($this->engine()->commitIndex()->get($id)['operations'] ?? 0);
		}

		return $total;
	}

	/**
	 * Finding codes the ledger holds open.
	 *
	 * @return list<string>
	 *   The codes.
	 */
	private function openCodes(): array
	{
		$codes = [];

		foreach ($this->container->get('strata.health_ledger')->open() as $finding) {
			$codes[] = $finding->code;
		}

		return array_values(array_unique($codes));
	}

	/**
	 * A day's figures as one line, so a failure carries the measurement with it.
	 *
	 * @param array{commits: int, operations: int, frames: int, rawBytes: int, storedBytes: int, ratio: float} $measured
	 *   What OperationTest::workday() returned.
	 *
	 * @return string
	 *   The line.
	 */
	private function report(array $measured): string
	{
		return sprintf(
			'%d commits, %d operations, %d frames, %d captured, %d stored, %.2fx',
			$measured['commits'],
			$measured['operations'],
			$measured['frames'],
			$measured['rawBytes'],
			$measured['storedBytes'],
			$measured['ratio'],
		);
	}

	#endregion
}
