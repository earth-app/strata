<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Capture;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Event\StatementExecutionEndEvent;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\strata\Capture\CaptureScope;
use Drupal\strata\Capture\EventSubscriber\StatementCaptureSubscriber;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\HealthLedgerInterface;
use Drupal\strata\Journal\JournalFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Proves the statement tap cannot take a site down.
 *
 * **This subscriber has the widest blast radius in the module.** It runs inside
 * `Connection::execute()` for every write the site performs, so an exception raised here is not a
 * failed capture - it is a failed query, and with it a failed request, on every write. Every other
 * capture source in this module already holds to "a backup never takes a save down with it"; until
 * 1.0.2 this one did not, and it is the one where the rule matters most.
 *
 * The failures are driven through real collaborators rather than a mocked subscriber: a connection
 * that cannot answer once a statement is in flight, and a configuration factory that raises when the
 * tap asks whether it should run at all. Both are shapes a site mid-deployment actually produces.
 *
 * @see StatementCaptureSubscriber
 */
#[CoversClass(StatementCaptureSubscriber::class)]
class StatementCaptureSubscriberTest extends TestCase
{
	#region Surviving a Failure

	#[Test]
	#[TestDox('a statement is dropped rather than failing the query that carried it')]
	#[Group('strata/capture')]
	public function aFailedClassificationDoesNotFailTheQuery(): void
	{
		$subscriber = $this->tapping($this->failingConnection());

		$subscriber->onStatement($this->write());

		$this->assertSame([], $subscriber->pending(), 'nothing was recorded');
	}

	#[Test]
	#[TestDox('the tap comes off, so a repeating failure cannot recurse through its own logging')]
	#[Group('strata/capture')]
	public function aFailureTurnsTheTapOff(): void
	{
		$connection = $this->failingConnection();
		$connection->expects($this->once())->method('disableEvents');

		$subscriber = $this->tapping($connection);

		$this->assertTrue($subscriber->isTapping(), 'the tap was on before the failure');

		$subscriber->onStatement($this->write());

		$this->assertFalse($subscriber->isTapping());
	}

	#[Test]
	#[TestDox('the failure is logged once rather than swallowed')]
	#[Group('strata/capture')]
	public function aFailureIsLogged(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger
			->expects($this->once())
			->method('error')
			->with($this->stringContains('stopped watching statements'));

		$this->tapping($this->failingConnection(), $logger)->onStatement($this->write());
	}

	#[Test]
	#[TestDox('the tap switching itself off reaches the health dashboard, not only the log')]
	#[Group('strata/capture')]
	public function aFailureIsRecordedAsAFinding(): void
	{
		$recorded = null;
		$ledger = $this->createMock(HealthLedgerInterface::class);
		$ledger
			->expects($this->once())
			->method('record')
			->willReturnCallback(function (Finding $finding) use (&$recorded): void {
				$recorded = $finding;
			});

		$this->tapping($this->failingConnection(), ledger: $ledger)->onStatement($this->write());

		$this->assertInstanceOf(Finding::class, $recorded);
		$this->assertSame('capture.tap_disabled', $recorded->code);
		$this->assertSame(
			Finding::WARN,
			$recorded->severity,
			'WARN, because severity picks the repair rung and no unattended pass fixes a tap',
		);
	}

	#[Test]
	#[TestDox('a ledger that is itself broken does not turn a dropped capture into a failed query')]
	#[Group('strata/capture')]
	public function aBrokenLedgerStillDoesNotFailTheQuery(): void
	{
		$ledger = $this->createMock(HealthLedgerInterface::class);
		$ledger->method('record')->willThrowException(new RuntimeException('the table is gone'));

		$subscriber = $this->tapping($this->failingConnection(), ledger: $ledger);

		$subscriber->onStatement($this->write());

		$this->assertFalse($subscriber->isTapping());
	}

	#[Test]
	#[TestDox('a request is served even when the tap cannot decide whether to run')]
	#[Group('strata/capture')]
	public function aRequestIsServedWhenTheTapCannotStart(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger
			->expects($this->once())
			->method('error')
			->with($this->stringContains('could not start watching'));

		$subscriber = $this->subscriber($this->throwingScope(), logger: $logger);

		$subscriber->onRequest(
			new RequestEvent(
				$this->createMock(HttpKernelInterface::class),
				new Request(),
				HttpKernelInterface::MAIN_REQUEST,
			),
		);

		$this->assertFalse($subscriber->isTapping());
	}

	#[Test]
	#[TestDox('a read is still discarded before anything that could fail is reached')]
	#[Group('strata/capture')]
	public function aReadNeverReachesTheScope(): void
	{
		$configFactory = $this->createMock(ConfigFactoryInterface::class);
		$configFactory->expects($this->never())->method('get');

		$subscriber = $this->subscriber(new CaptureScope($configFactory));

		$subscriber->onStatement($this->statement('SELECT nid FROM {node}'));

		$this->assertSame([], $subscriber->pending());
	}

	#endregion

	#region Fixtures

	/**
	 * A subscriber over the collaborators a test wants to control.
	 *
	 * @param CaptureScope $scope
	 *   Decides whether the tap runs and what it covers.
	 * @param Connection|null $connection
	 *   The connection, or NULL for one that records nothing.
	 * @param LoggerInterface|null $logger
	 *   The logger, or NULL for one that records nothing.
	 * @param HealthLedgerInterface|null $ledger
	 *   The ledger, or NULL for a subscriber that records no finding.
	 *
	 * @return StatementCaptureSubscriber
	 *   The subscriber.
	 */
	private function subscriber(
		CaptureScope $scope,
		?Connection $connection = null,
		?LoggerInterface $logger = null,
		?HealthLedgerInterface $ledger = null,
	): StatementCaptureSubscriber {
		$database = $connection ?? $this->connection();

		return new StatementCaptureSubscriber(
			$database,
			new JournalFactory(
				$this->createMock(ConfigFactoryInterface::class),
				$database,
				$this->createMock(LoggerInterface::class),
			),
			$scope,
			$this->createMock(AccountProxyInterface::class),
			$logger ?? $this->createMock(LoggerInterface::class),
			$ledger,
		);
	}

	/**
	 * A subscriber with the tap already on, over a connection that will fail mid-statement.
	 *
	 * @param Connection $connection
	 *   The connection.
	 * @param LoggerInterface|null $logger
	 *   The logger, or NULL for one that records nothing.
	 * @param HealthLedgerInterface|null $ledger
	 *   The ledger, or NULL for a subscriber that records no finding.
	 *
	 * @return StatementCaptureSubscriber
	 *   The subscriber, tapping.
	 */
	private function tapping(
		Connection $connection,
		?LoggerInterface $logger = null,
		?HealthLedgerInterface $ledger = null,
	): StatementCaptureSubscriber {
		$subscriber = $this->subscriber($this->workingScope(), $connection, $logger, $ledger);
		$subscriber->enable();

		return $subscriber;
	}

	/**
	 * A capture scope whose every question raises.
	 *
	 * @return CaptureScope
	 *   The scope.
	 */
	private function throwingScope(): CaptureScope
	{
		$configFactory = $this->createMock(ConfigFactoryInterface::class);
		$configFactory
			->method('get')
			->willThrowException(new RuntimeException('configuration is unreadable'));

		return new CaptureScope($configFactory);
	}

	/**
	 * A capture scope that taps everything.
	 *
	 * @return CaptureScope
	 *   The scope.
	 */
	private function workingScope(): CaptureScope
	{
		$config = $this->createMock(ImmutableConfig::class);
		$config->method('getRawData')->willReturn([
			'enabled' => true,
			'capture' => ['statements' => true, 'table' => true],
		]);

		$configFactory = $this->createMock(ConfigFactoryInterface::class);
		$configFactory->method('get')->willReturn($config);

		return new CaptureScope($configFactory);
	}

	/**
	 * A connection that raises as soon as the tap asks it anything about a statement.
	 *
	 * The prefix read is the first thing `onStatement()` needs from the connection, and a connection
	 * in a state where it cannot answer is exactly the failure this guard exists for.
	 *
	 * @return Connection&MockObject
	 *   The mock.
	 */
	private function failingConnection(): Connection&MockObject
	{
		$connection = $this->connection();
		$connection
			->method('getPrefix')
			->willThrowException(new RuntimeException('the connection is unusable'));

		return $connection;
	}

	/**
	 * A connection that answers only what the subscriber asks it.
	 *
	 * @return Connection&MockObject
	 *   The mock.
	 */
	private function connection(): Connection&MockObject
	{
		// createMock doubles the abstract methods too, which a plain mock builder will not
		return $this->createMock(Connection::class);
	}

	/**
	 * A statement event carrying a write.
	 *
	 * @return StatementExecutionEndEvent
	 *   The event.
	 */
	private function write(): StatementExecutionEndEvent
	{
		// already prefixed, which is the form a statement event actually carries
		return $this->statement('INSERT INTO node_field_data (nid) VALUES (1)');
	}

	/**
	 * A statement event carrying arbitrary SQL.
	 *
	 * @param string $sql
	 *   The statement.
	 *
	 * @return StatementExecutionEndEvent
	 *   The event.
	 */
	private function statement(string $sql): StatementExecutionEndEvent
	{
		return new StatementExecutionEndEvent(1, 'default', 'default', $sql, [], [], 0.0);
	}

	#endregion
}
