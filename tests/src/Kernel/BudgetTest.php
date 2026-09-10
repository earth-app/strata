<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Budget\BudgetGuard;
use Drupal\strata\Budget\EscalationLadder;
use Drupal\strata\Engine;
use Drupal\strata\Event\BudgetEvent;
use Drupal\strata\Event\StrataEvents;
use Drupal\strata\Health\CircuitBreaker;
use Drupal\strata\Hook\CronCapture;
use Drupal\strata\Storage\ProviderStats;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves a dial the settings forms collect is a dial something reads.
 *
 * **The unit lane cannot answer this question and never could.** `BudgetGuardTest` and
 * `EscalationLadderTest` both passed for three releases while nothing in `src/` constructed a
 * `BudgetGuard` at all, so a site could set a ceiling of 10 GiB and "Stop Capturing", have the values
 * saved and handed back on reload, and never be measured against either. `CircuitBreaker` had the
 * same shape until 1.0.3, and `health.circuit_threshold` still did after it was wired: the breaker
 * was constructed with no arguments, so the form confirmed a setting that could not take effect.
 *
 * A test that constructs the class proves the class works. What has to be proved separately is that
 * configuration reaches it, which is what every test here does.
 *
 * @see Engine::budgetAssessment()
 * @see CronCapture
 */
class BudgetTest extends StrataKernelTestBase
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

		$this->settings()
			->set('enabled', true)
			->set('provider', 'local')
			->set('local_path', $this->storeRoot)
			->set('cipher.id', 'none')
			->save();

		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();
	}

	#region The Ceilings Reach the Guard

	#[Test]
	#[TestDox('the configured ceilings are the ones the guard measures against')]
	#[Group('strata/budget')]
	public function theConfiguredCeilingsReachTheGuard(): void
	{
		$this->settings()
			->set('budget.bytes_per_month', 1024)
			->set('budget.dollars_per_month', 7)
			->save();
		$this->engine()->reset();

		$assessment = $this->engine()->budgetAssessment();

		$this->assertSame(1024, $assessment->bytesCeiling);
		$this->assertSame(7.0, $assessment->dollarsCeiling);
	}

	#[Test]
	#[TestDox('a site with no ceiling set is on the normal rung rather than over one')]
	#[Group('strata/budget')]
	public function noCeilingIsNotAnOverspend(): void
	{
		$assessment = $this->engine()->budgetAssessment();

		$this->assertSame(EscalationLadder::NORMAL, $assessment->rung);
		$this->assertFalse($assessment->isOverBudget());
	}

	#[Test]
	#[TestDox('traffic past a byte ceiling lands above the normal rung')]
	#[Group('strata/budget')]
	public function trafficPastTheCeilingEscalates(): void
	{
		$this->settings()
			->set('budget.bytes_per_month', 1024)
			->set('budget.action', EscalationLadder::STOP)
			->save();
		$this->engine()->reset();
		$this->trafficOf(64 * 1024 * 1024);

		$assessment = $this->engine()->budgetAssessment();

		$this->assertGreaterThan(1.0, $assessment->usedFraction);
		$this->assertNotSame(EscalationLadder::NORMAL, $assessment->rung);
		$this->assertTrue($assessment->isOverBudget());
	}

	#[Test]
	#[TestDox('the chosen action caps the rung, so warn only never stops capture')]
	#[Group('strata/budget')]
	public function theChosenActionCapsTheRung(): void
	{
		// the ladder escalates on the measured overspend alone, and until 1.0.3 budget.action was
		// not an input to anything; a site that asked to be warned would have been stopped at 1.5x
		$this->settings()
			->set('budget.bytes_per_month', 1024)
			->set('budget.action', EscalationLadder::WARN)
			->save();
		$this->engine()->reset();
		$this->trafficOf(64 * 1024 * 1024);

		$assessment = $this->engine()->budgetAssessment();

		$this->assertSame(EscalationLadder::WARN, $assessment->rung);
		$this->assertFalse(EscalationLadder::stopsEverything($assessment->rung));
		$this->assertGreaterThan(1.0, $assessment->usedFraction, 'the measurement is unchanged');
	}

	#endregion

	#region Cron Runs It

	#[Test]
	#[TestDox('cron prices the month and raises a finding when the ceiling is passed')]
	#[Group('strata/budget')]
	public function cronRaisesAFindingOverTheCeiling(): void
	{
		$this->settings()->set('budget.bytes_per_month', 1024)->save();
		$this->engine()->reset();
		$this->trafficOf(64 * 1024 * 1024);

		$this->cron();

		$this->assertContains(CronCapture::BUDGET_CODE, $this->openCodes());
	}

	#[Test]
	#[TestDox('a month back under the ceiling clears what it raised')]
	#[Group('strata/budget')]
	public function comingBackUnderTheCeilingClearsTheFinding(): void
	{
		$this->settings()->set('budget.bytes_per_month', 1024)->save();
		$this->engine()->reset();
		$this->trafficOf(64 * 1024 * 1024);
		$this->cron();

		$this->assertContains(CronCapture::BUDGET_CODE, $this->openCodes());

		$this->settings()
			->set('budget.bytes_per_month', 1024 ** 4)
			->save();
		$this->engine()->reset();
		$this->cron();

		$this->assertNotContains(CronCapture::BUDGET_CODE, $this->openCodes());
	}

	#[Test]
	#[TestDox('a site with no ceiling is not measured at all, so cron costs it nothing')]
	#[Group('strata/budget')]
	public function noCeilingIsNotMeasured(): void
	{
		$this->trafficOf(64 * 1024 * 1024);
		$this->cron();

		$this->assertNotContains(CronCapture::BUDGET_CODE, $this->openCodes());
	}

	#[Test]
	#[TestDox('an overspend announces itself, so a subscription hears about the bill')]
	#[Group('strata/budget')]
	public function anOverspendIsAnnounced(): void
	{
		$heard = [];

		$this->container
			->get('event_dispatcher')
			->addListener(StrataEvents::BUDGET_BREACHED, static function (BudgetEvent $event) use (
				&$heard,
			): void {
				$heard[] = $event;
			});

		$this->settings()->set('budget.bytes_per_month', 1024)->save();
		$this->engine()->reset();
		$this->trafficOf(64 * 1024 * 1024);
		$this->cron();

		$this->assertCount(1, $heard);
		$this->assertTrue($heard[0]->assessment->isOverBudget());
	}

	#endregion

	#region The Circuit Settings Reach the Breaker

	#[Test]
	#[TestDox('the configured circuit threshold is the one the repair pass opens on')]
	#[Group('strata/health')]
	public function theConfiguredCircuitReachesTheBreaker(): void
	{
		$this->settings()
			->set('health.circuit_threshold', 7)
			->set('health.circuit_cooldown', 900)
			->save();
		$this->engine()->reset();

		$breaker = $this->engine()->repairPass()->breaker();

		$this->assertSame(7, $breaker->threshold());
		$this->assertSame(900, $breaker->cooldown());
	}

	#[Test]
	#[TestDox('a threshold below one is clamped rather than raising inside a cron stage')]
	#[Group('strata/health')]
	public function anImpossibleCircuitThresholdIsClamped(): void
	{
		// the form carries #min and drush config:set does not, and CircuitBreaker raises on zero
		$this->settings()
			->set('health.circuit_threshold', 0)
			->set('health.circuit_cooldown', -1)
			->save();
		$this->engine()->reset();

		$breaker = $this->engine()->repairPass()->breaker();

		$this->assertInstanceOf(CircuitBreaker::class, $breaker);
		$this->assertSame(1, $breaker->threshold());
		$this->assertSame(0, $breaker->cooldown());
	}

	#endregion

	#region Fixtures

	/**
	 * Records a month of traffic against the store.
	 *
	 * Written straight into the per-day table, which is where BudgetGuard reads a window from. The
	 * alternative is performing the requests, which would measure the emulator rather than the guard.
	 *
	 * @param int $bytes
	 *   Bytes to record as having crossed the wire.
	 */
	private function trafficOf(int $bytes): void
	{
		$stats = new ProviderStats();
		$stats->record('put', $bytes, 0.5);

		$this->engine()->providerStatStore()->record('local', $stats);
	}

	/**
	 * Runs the module's cron hook.
	 */
	private function cron(): void
	{
		$this->container->get('module_handler')->invokeAll('cron');
	}

	/**
	 * The finding codes currently open.
	 *
	 * @return list<string>
	 *   The codes, with repeats.
	 */
	private function openCodes(): array
	{
		$codes = [];

		foreach ($this->container->get('strata.health_ledger')->open() as $finding) {
			$codes[] = $finding->code;
		}

		return $codes;
	}

	/**
	 * The engine.
	 *
	 * @return Engine
	 *   The engine.
	 */
	private function engine(): Engine
	{
		return $this->container->get('strata.engine');
	}

	#endregion
}
