<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Health;

use Drupal\strata\Health\CircuitBreaker;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\MemoryHealthLedger;
use Drupal\strata\Health\RepairPass;
use Drupal\strata\Health\RepairReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Proves the repair ladder is actually walked, and walked safely.
 *
 * **Every assertion here covers behaviour that did not exist until 1.0.3 and was documented as
 * though it did.** `health.auto_repair` shipped on by default with a checkbox writing it and nothing
 * reading it; `CircuitBreaker` had a full unit suite and no caller anywhere in `src/`; and
 * `HealthLedgerInterface::resolve()` had no production call site at all, so a ledger only ever grew.
 * The README told operators that four rungs run on cron gated by a breaker. None of it ran.
 *
 * The two rules worth stating are the ones a careless implementation gets backwards. Findings are
 * cleared BEFORE a pass, because a pass that finishes proves the work was done and not that the
 * symptom is gone. And a rung's pass runs ONCE however many codes name it, because the passes are a
 * reindex and a full deep verify.
 *
 * @see RepairPass
 * @see CircuitBreaker
 */
#[CoversClass(RepairPass::class)]
#[CoversClass(RepairReport::class)]
class RepairPassTest extends TestCase
{
	/**
	 * How many times each rung's pass has been invoked this test.
	 *
	 * @var array<string, int>
	 */
	private array $ran = [];

	/**
	 * What the ledger held at the moment each pass was invoked.
	 *
	 * @var array<string, int>
	 */
	private array $openWhenRun = [];

	/**
	 * The ledger under test.
	 */
	private MemoryHealthLedger $ledger;

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->ran = [];
		$this->openWhenRun = [];
		$this->ledger = new MemoryHealthLedger();
	}

	#region Choosing

	#[Test]
	#[TestDox('an empty ledger is not a reason to reindex a bucket')]
	#[Group('strata/health')]
	public function nothingOpenRunsNothing(): void
	{
		$report = $this->pass()->run();

		$this->assertSame(0, $report->considered);
		$this->assertSame([], $this->ran);
		$this->assertStringContainsString('Nothing is open', $report->summary());
	}

	#[Test]
	#[TestDox('a code that only watches is counted and left alone')]
	#[Group('strata/health')]
	public function anObserveCodeRunsNothing(): void
	{
		$this->ledger->record(new Finding('delta.chain_too_deep', Finding::WARN, 'frame:a'));

		$report = $this->pass()->run();

		$this->assertSame(1, $report->considered);
		$this->assertSame(0, $report->repaired);
		$this->assertSame([], $this->ran);
		$this->assertCount(1, $this->ledger->open(), 'nothing was cleared either');
	}

	#[Test]
	#[TestDox('a rung a person has to decide is held rather than taken')]
	#[Group('strata/health')]
	public function aQuarantineCodeIsHeld(): void
	{
		$this->ledger->record(new Finding('frame.missing', Finding::CRITICAL, 'frame:a'));

		$report = $this->pass()->run();

		$this->assertSame('quarantine', $this->ledger->rungFor('frame.missing'));
		$this->assertSame(1, $report->held);
		$this->assertSame([], $this->ran);
		$this->assertCount(1, $this->ledger->open(), 'a held code keeps its findings');
	}

	#endregion

	#region Running

	#[Test]
	#[TestDox('a code at reindex runs the reindex pass and clears what it had open')]
	#[Group('strata/health')]
	public function aReindexCodeRunsItsPass(): void
	{
		$this->ledger->record(new Finding('frame.unindexed', Finding::ERROR, 'frame:a'));
		$this->ledger->record(new Finding('frame.unindexed', Finding::ERROR, 'frame:b'));

		$report = $this->pass()->run();

		$this->assertSame(['reindex' => 1], $this->ran);
		$this->assertSame(1, $report->repaired);
		$this->assertSame(2, $report->cleared);
		$this->assertSame([], $this->ledger->open());
	}

	#[Test]
	#[TestDox('four codes on one rung cost one pass, not four')]
	#[Group('strata/health')]
	public function onePassCoversEveryCodeOnItsRung(): void
	{
		foreach (
			['frame.unindexed', 'frame.unreadable', 'pack.short_read', 'segment.truncated']
			as $code
		) {
			$this->ledger->record(new Finding($code, Finding::ERROR, 'frame:a'));
		}

		$report = $this->pass()->run();

		$this->assertSame(['reindex' => 1], $this->ran, 'a reindex lists the whole bucket');
		$this->assertSame(4, $report->considered);
		$this->assertSame(4, $report->repaired);
	}

	#[Test]
	#[TestDox('findings are cleared before the pass runs, never after it finishes')]
	#[Group('strata/health')]
	public function findingsAreClearedBeforeTheRun(): void
	{
		$this->ledger->record(new Finding('frame.unindexed', Finding::ERROR, 'frame:a'));

		$this->pass()->run();

		// a pass that finishes proves the work was done, not that the symptom is gone; verify
		// re-records whatever is still true on its way through, and clearing afterwards would delete
		// exactly that
		$this->assertSame(0, $this->openWhenRun['reindex']);
	}

	#[Test]
	#[TestDox('one code can be repaired by name whether or not anything is open for it')]
	#[Group('strata/health')]
	public function oneCodeCanBeRunByName(): void
	{
		$this->ledger->setRung('frame.unreadable', 'refetch');

		$report = $this->pass()->runCode('frame.unreadable');

		$this->assertSame(['refetch' => 1], $this->ran);
		$this->assertSame(1, $report->repaired);
	}

	#endregion

	#region Failing

	#[Test]
	#[TestDox('a pass that raises moves its code one rung up')]
	#[Group('strata/health')]
	public function aFailedPassEscalates(): void
	{
		$this->ledger->record(new Finding('frame.unindexed', Finding::ERROR, 'frame:a'));

		$report = $this->pass(failing: ['reindex'])->run();

		$this->assertSame(1, $report->failed);
		$this->assertSame('refetch', $this->ledger->rungFor('frame.unindexed'));
	}

	#[Test]
	#[TestDox('a pass that raises leaves the symptom in the ledger rather than clearing it')]
	#[Group('strata/health')]
	public function aFailedPassPutsTheFindingsBack(): void
	{
		$this->ledger->record(new Finding('frame.unindexed', Finding::ERROR, 'frame:a', 'gone'));

		$report = $this->pass(failing: ['reindex'])->run();

		$open = $this->ledger->open();

		$this->assertSame(0, $report->cleared, 'nothing was repaired, so nothing was cleared');
		$this->assertCount(1, $open);
		$this->assertSame('frame:a', $open[0]->scope);
		$this->assertSame('gone', $open[0]->context);
	}

	#[Test]
	#[TestDox('a code that keeps failing climbs off the automatic set and waits for a person')]
	#[Group('strata/health')]
	public function repeatedFailureReachesQuarantine(): void
	{
		$this->ledger->record(new Finding('frame.unindexed', Finding::ERROR, 'frame:a'));

		// a fresh pass each time, because the breaker is per process and a cron run is one process
		foreach (['refetch', 'rebuild', 'quarantine'] as $expected) {
			$this->pass(failing: ['reindex', 'refetch', 'rebuild'])->run();

			$this->assertSame($expected, $this->ledger->rungFor('frame.unindexed'));
		}

		$report = $this->pass(failing: ['reindex', 'refetch', 'rebuild'])->run();

		$this->assertSame(1, $report->held, 'quarantine is a decision for a person');
		$this->assertSame(0, $report->failed);
	}

	#[Test]
	#[TestDox('the breaker stops a code that has failed three times in one run')]
	#[Group('strata/health')]
	public function theBreakerStopsARepeatingFailure(): void
	{
		$pass = $this->pass(failing: ['reindex', 'refetch', 'rebuild', 'quarantine']);

		// the rung is pinned so failure cannot walk the code off the automatic set, which is the
		// other brake; what is under test here is the breaker on its own
		for ($attempt = 1; $attempt <= 3; $attempt++) {
			$this->ledger->setRung('frame.unindexed', 'reindex');

			$this->assertSame(1, $pass->runCode('frame.unindexed')->failed);
		}

		$this->ledger->setRung('frame.unindexed', 'reindex');

		$report = $pass->runCode('frame.unindexed');

		$this->assertSame(1, $report->held);
		$this->assertSame(3, $this->ran['reindex'], 'the fourth attempt never reached the pass');
	}

	#endregion

	#region Fixtures

	/**
	 * A pass over the ledger under test, recording what it ran.
	 *
	 * @param list<string> $failing
	 *   Rungs whose pass raises instead of returning.
	 *
	 * @return RepairPass
	 *   The pass.
	 */
	private function pass(array $failing = []): RepairPass
	{
		$passes = [];

		foreach (['reindex', 'refetch', 'rebuild'] as $rung) {
			$passes[$rung] = function () use ($rung, $failing): string {
				$this->ran[$rung] = ($this->ran[$rung] ?? 0) + 1;
				$this->openWhenRun[$rung] = count($this->ledger->open());

				if (in_array($rung, $failing, true)) {
					throw new RuntimeException(sprintf('the %s pass could not run', $rung));
				}

				return sprintf('%s finished', $rung);
			};
		}

		return new RepairPass($this->ledger, new CircuitBreaker(), new NullLogger(), $passes);
	}

	#endregion
}
