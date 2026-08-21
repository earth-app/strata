<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_notify\Kernel;

use Drupal\Tests\strata\Kernel\StrataKernelTestBase;
use Drupal\strata\Budget\BudgetAssessment;
use Drupal\strata\Event\BudgetEvent;
use Drupal\strata\Event\CommitEvent;
use Drupal\strata\Event\HealthEvent;
use Drupal\strata\Event\StrataEvents;
use Drupal\strata\Flush\FlushResult;
use Drupal\strata\Health\Finding;
use Drupal\strata_notify\NotificationPolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves the module decides what to mail before it decides how to word it.
 *
 * A notifier gets one of two things wrong and both are quiet. It sends too much, and an operator
 * filters the address into a folder they stop reading, which is the same as sending nothing. Or it
 * sends too little, and the one event that mattered was gated out by a default nobody chose.
 *
 * So what is asserted here is the decision, not the message: which events are on out of the box,
 * that a severity floor applies to the one event that carries a severity and to nothing else, and
 * that no configuration produces mail with nobody to send it to.
 */
class NotifyTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'key', 'strata', 'strata_notify'];

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->installConfig(['strata_notify']);
	}

	#region Defaults

	#[Test]
	#[TestDox('nothing is mailed until somebody is configured to receive it')]
	#[Group('strata/notify')]
	public function noRecipientMeansNoMail(): void
	{
		$policy = $this->policy();

		$this->assertSame([], $policy->recipients());
		$this->assertFalse(
			$policy->wants($this->health(Finding::CRITICAL)),
			'a critical finding on a fresh install goes nowhere, because nowhere is configured',
		);
	}

	#[Test]
	#[TestDox('the shipped defaults are the four events an operator has to act on')]
	#[Group('strata/notify')]
	public function theDefaultsAreNarrow(): void
	{
		$this->recipients('ops@example.com');

		$policy = $this->policy();

		$this->assertTrue($policy->wants($this->health(Finding::ERROR)), 'health is on');
		$this->assertTrue(
			$policy->wants(new BudgetEvent($this->assessment('reduce'))),
			'budget is on',
		);
		$this->assertFalse(
			$policy->wants(new CommitEvent(FlushResult::skipped('nothing pending'))),
			'every commit would be a mail per flush, so it is off',
		);
	}

	#[Test]
	#[TestDox('every event the module can gate has a gate, and every gate names a real event')]
	#[Group('strata/notify')]
	public function everyGateNamesARealEvent(): void
	{
		$settings = $this->config(NotificationPolicy::CONFIG)->get('events') ?? [];

		foreach (NotificationPolicy::GATES as $event => $gate) {
			$this->assertContains(
				$event,
				StrataEvents::all(),
				sprintf('%s is gated but is not an event this module dispatches', $event),
			);
			$this->assertArrayHasKey(
				$gate,
				$settings,
				sprintf('the %s gate has no setting, so it can never be switched on', $gate),
			);
		}

		foreach (array_keys($settings) as $gate) {
			$this->assertContains(
				$gate,
				array_values(NotificationPolicy::GATES),
				sprintf('the %s setting gates nothing, so changing it does nothing', $gate),
			);
		}
	}

	#endregion

	#region The Severity Floor

	#[Test]
	#[TestDox('a finding below the floor is not mailed and one at it is')]
	#[Group('strata/notify')]
	public function theFloorFiltersFindings(): void
	{
		$this->recipients('ops@example.com');

		$policy = $this->policy();

		$this->assertSame(Finding::ERROR, $policy->severityFloor());
		$this->assertFalse($policy->wants($this->health(Finding::WARN)));
		$this->assertTrue($policy->wants($this->health(Finding::ERROR)), 'the floor is inclusive');
		$this->assertTrue($policy->wants($this->health(Finding::CRITICAL)));
	}

	#[Test]
	#[TestDox('the floor applies to a finding and to nothing else')]
	#[Group('strata/notify')]
	public function theFloorDoesNotSuppressOtherEvents(): void
	{
		$this->recipients('ops@example.com');
		$this->config(NotificationPolicy::CONFIG)->set('severity', Finding::CRITICAL)->save();

		$policy = $this->policy();

		$this->assertFalse(
			$policy->wants($this->health(Finding::ERROR)),
			'an error is below a critical floor',
		);
		$this->assertTrue(
			$policy->wants(new BudgetEvent($this->assessment('pause'))),
			'a budget breach carries no severity, so a finding threshold must not silence it',
		);
	}

	#endregion

	#region Recipients

	#[Test]
	#[TestDox('an address list is split, trimmed, and anything that is not an address dropped')]
	#[Group('strata/notify')]
	public function theAddressListIsCleaned(): void
	{
		$this->recipients(' ops@example.com , not-an-address ,, second@example.com ');

		$this->assertSame(
			['ops@example.com', 'second@example.com'],
			$this->policy()->recipients(),
			'a typo in one address must not stop the others being written to',
		);
	}

	#[Test]
	#[TestDox('a list of nothing but rubbish is no recipients rather than one bad one')]
	#[Group('strata/notify')]
	public function rubbishIsNotARecipient(): void
	{
		$this->recipients('nobody, , @example.com');

		$this->assertSame([], $this->policy()->recipients());
		$this->assertFalse($this->policy()->wants($this->health(Finding::CRITICAL)));
	}

	#endregion

	#region Wiring

	#[Test]
	#[TestDox('a digest collapses on the event kind, and the code is carried in the subject')]
	#[Group('strata/notify')]
	public function theSubscriberKeysOnTheFindingCode(): void
	{
		$subscriber = $this->container->get('strata_notify.subscriber');
		$event = $this->health(Finding::ERROR);

		// the digest key is the event kind, so repeats of one kind collapse rather than one per code
		$this->assertSame('health', $subscriber->key($event));
		$this->assertStringContainsString(
			'frame.missing',
			$subscriber->subject($event),
			'the code is in the subject, so a mail says what it is about before it is opened',
		);
		$this->assertTrue($this->policy()->digests(), 'repeats collapse by default');
	}

	#endregion

	#region Fixtures

	/**
	 * The policy under test.
	 *
	 * @return NotificationPolicy
	 *   The policy.
	 */
	private function policy(): NotificationPolicy
	{
		return new NotificationPolicy($this->container->get('config.factory'));
	}

	/**
	 * Sets who is notified.
	 *
	 * @param string $addresses
	 *   The configured comma-separated list.
	 */
	private function recipients(string $addresses): void
	{
		$this->config(NotificationPolicy::CONFIG)->set('recipients', $addresses)->save();
	}

	/**
	 * A budget reading at one rung.
	 *
	 * @param string $rung
	 *   The escalation rung the reading sits at.
	 *
	 * @return BudgetAssessment
	 *   The reading.
	 */
	private function assessment(string $rung): BudgetAssessment
	{
		return new BudgetAssessment(
			$rung,
			1.4,
			2_000_000,
			0.5,
			1_000_000,
			0.25,
			'over the ceiling',
		);
	}

	/**
	 * A health event at one severity.
	 *
	 * @param int $severity
	 *   A Finding severity ordinal.
	 *
	 * @return HealthEvent
	 *   The event.
	 */
	private function health(int $severity): HealthEvent
	{
		return new HealthEvent(new Finding('frame.missing', $severity, 'frames/aa', 'gone'));
	}

	#endregion
}
