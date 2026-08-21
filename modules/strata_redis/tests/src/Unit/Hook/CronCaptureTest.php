<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_redis\Unit\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\strata\Capture\CaptureScope;
use Drupal\strata\Capture\Classifier\ClassificationRegistry;
use Drupal\strata\Capture\Classifier\EphemeralScope;
use Drupal\strata\Capture\KeyRecorder;
use Drupal\strata\Journal\FlushPolicy;
use Drupal\strata\Journal\MemoryJournal;
use Drupal\strata_redis\Hook\CronCapture;
use Drupal\strata_redis\RedisCapture;
use Drupal\strata_redis\RedisClientFactory;
use Drupal\strata_redis\RedisKeyspaceSource;
use Drupal\Tests\strata_redis\Unit\Constant;
use Drupal\Tests\strata_redis\Unit\FakeRedis;
use Drupal\Tests\strata_redis\Unit\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Proves a pass runs on its own interval and that nothing leaves the hook.
 *
 * Redis holds no events, so the keyspace is only seen by going to look at it, and cron is where
 * looking belongs. Two things then have to hold. A pass costs a cursor walk plus a round trip per key,
 * so a site whose cron fires every minute must not run one every minute. And a cron hook that raises
 * stops every later hook in the queue, so a Redis outage must not take the site's search indexing with
 * it.
 *
 * The pass under the hook is the real one over a stand-in client, so "it ran" is asserted as a journal
 * entry rather than as a call on a mock.
 */
#[CoversClass(CronCapture::class)]
class CronCaptureTest extends TestCase
{
	#region Fixtures

	/**
	 * The journal a pass writes to.
	 */
	private MemoryJournal $journal;

	/**
	 * What the hook logged.
	 */
	private RecordingLogger $logger;

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->journal = new MemoryJournal();
		$this->logger = new RecordingLogger();
	}

	/**
	 * The hook over a real pass and a stand-in client holding one authoritative key.
	 *
	 * @param StateInterface $state
	 *   Remembers when the last pass ran.
	 * @param int $interval
	 *   Seconds between passes.
	 * @param bool $breaks
	 *   TRUE to make the pass raise before it can catch anything itself, which is what proves the
	 *   hook's own guard.
	 *
	 * @return CronCapture
	 *   The hook.
	 */
	private function hook(
		StateInterface $state,
		int $interval = CronCapture::INTERVAL,
		bool $breaks = false,
	): CronCapture {
		return new CronCapture($this->pass($breaks), $state, $this->logger, $interval);
	}

	/**
	 * A pass over a stand-in client.
	 *
	 * @param bool $breaks
	 *   TRUE to have the settings read raise, which happens before the pass's own guard.
	 *
	 * @return RedisCapture
	 *   The pass.
	 */
	private function pass(bool $breaks): RedisCapture
	{
		$client = (new FakeRedis())->hold('queue:jobs', 'string', 'work');
		$scope = new CaptureScope($breaks ? $this->brokenSettings() : $this->settings());

		return new RedisCapture(
			new RedisKeyspaceSource(new RedisClientFactory($this->logger, $client)),
			new RedisClientFactory($this->logger, $client),
			new EphemeralScope($scope, new ClassificationRegistry($this->database())),
			new KeyRecorder($this->journal, $scope, $this->account(), $this->logger),
			$this->logger,
		);
	}

	/**
	 * Settings that capture the ephemeral realm.
	 *
	 * @return ConfigFactoryInterface
	 *   The factory.
	 */
	private function settings(): ConfigFactoryInterface
	{
		$config = $this->createMock(ImmutableConfig::class);
		$config->method('getRawData')->willReturn([
			'enabled' => true,
			'capture' => ['ephemeral' => true],
		]);

		$factory = $this->createMock(ConfigFactoryInterface::class);
		$factory->method('get')->willReturn($config);

		return $factory;
	}

	/**
	 * Settings that cannot be read at all.
	 *
	 * The scope check runs before the pass's own try block, so this is how a pass raises out of it.
	 *
	 * @return ConfigFactoryInterface
	 *   The factory.
	 */
	private function brokenSettings(): ConfigFactoryInterface
	{
		$factory = $this->createMock(ConfigFactoryInterface::class);
		$factory
			->method('get')
			->willThrowException(new RuntimeException('settings are unreadable'));

		return $factory;
	}

	/**
	 * A database holding no stored classifications.
	 *
	 * @return Connection
	 *   The connection.
	 */
	private function database(): Connection
	{
		$select = $this->createMock(SelectInterface::class);
		$select->method('fields')->willReturnSelf();
		$select->method('execute')->willReturn(null);

		$database = $this->createMock(Connection::class);
		$database->method('select')->willReturn($select);

		return $database;
	}

	/**
	 * A current-user stand-in that reports nobody, because cron is unattended.
	 *
	 * @return AccountProxyInterface
	 *   The account.
	 */
	private function account(): AccountProxyInterface
	{
		$account = $this->createMock(AccountProxyInterface::class);
		$account->method('id')->willReturn(0);

		return $account;
	}

	/**
	 * A state stand-in reporting when the last pass ran, and expecting a write or refusing one.
	 *
	 * @param mixed $last
	 *   What the state holds for the last run.
	 * @param bool $writes
	 *   TRUE when this cron is expected to record a run.
	 *
	 * @return StateInterface
	 *   The state.
	 */
	private function state(mixed $last, bool $writes): StateInterface
	{
		$state = $this->createMock(StateInterface::class);
		$state->method('get')->willReturn($last);

		if ($writes) {
			$state
				->expects($this->once())
				->method('set')
				->with(
					CronCapture::KEY,
					$this->callback(static fn(mixed $value): bool => is_int($value) && $value > 0),
				);

			return $state;
		}

		$state->expects($this->never())->method('set');

		return $state;
	}

	#endregion

	#region The Interval

	#[Test]
	#[TestDox('a site that has never run a pass runs one')]
	#[Group('strata/redis')]
	public function siteThatHasNeverRunAPassRunsOne(): void
	{
		$this->hook($this->state(0, true))->onCron();

		$this->assertSame(1, $this->journal->pending());
	}

	#[Test]
	#[TestDox('a cron immediately after a pass does not run another')]
	#[Group('strata/redis')]
	public function cronImmediatelyAfterAPassDoesNothing(): void
	{
		$this->hook($this->state(time(), false))->onCron();

		$this->assertSame(0, $this->journal->pending());
		$this->assertSame('', $this->logger->at('error'));
	}

	#[Test]
	#[TestDox('a pass is due once the interval has elapsed')]
	#[Group('strata/redis')]
	public function passIsDueOnceTheIntervalHasElapsed(): void
	{
		$this->hook($this->state(time() - CronCapture::INTERVAL - 1, true))->onCron();

		$this->assertSame(1, $this->journal->pending());
	}

	#[Test]
	#[TestDox('a pass is not due one second before the interval has elapsed')]
	#[Group('strata/redis')]
	public function passIsNotDueJustBeforeTheInterval(): void
	{
		$this->hook($this->state(time() - CronCapture::INTERVAL + 5, false))->onCron();

		$this->assertSame(0, $this->journal->pending());
	}

	#[Test]
	#[TestDox('an interval shorter than a minute is floored at a minute')]
	#[Group('strata/redis')]
	public function intervalShorterThanAMinuteIsFloored(): void
	{
		$this->hook($this->state(time() - 30, false), 1)->onCron();

		$this->assertSame(
			0,
			$this->journal->pending(),
			'a one second interval still waits a minute',
		);

		$this->hook($this->state(time() - 61, true), 1)->onCron();

		$this->assertSame(1, $this->journal->pending());
	}

	#[Test]
	#[TestDox('an unreadable last run is treated as never having run')]
	#[Group('strata/redis')]
	public function unreadableLastRunIsTreatedAsNever(): void
	{
		$this->hook($this->state('not a timestamp', true))->onCron();

		$this->assertSame(1, $this->journal->pending());
	}

	#endregion

	#region Guards

	#[Test]
	#[TestDox('the last run is recorded before the pass, so a dying pass is not retried')]
	#[Group('strata/redis')]
	public function lastRunIsRecordedBeforeThePass(): void
	{
		$this->hook($this->state(0, true), breaks: true)->onCron();

		$this->assertSame(0, $this->journal->pending());
		$this->assertStringContainsString('settings are unreadable', $this->logger->at('error'));
	}

	#[Test]
	#[TestDox('a pass that raises is logged rather than left to stop the rest of cron')]
	#[Group('strata/redis')]
	public function passThatRaisesIsLogged(): void
	{
		$this->hook($this->state(0, true), breaks: true)->onCron();

		$this->assertStringContainsString(
			'could not capture ephemeral state on cron',
			$this->logger->at('error'),
		);
	}

	#endregion

	#region Wiring

	#[Test]
	#[TestDox('the interval is the documented fifteen minutes, coarser than the flush window')]
	#[Group('strata/redis')]
	public function intervalIsFifteenMinutes(): void
	{
		$this->assertSame(15 * 60, Constant::of(CronCapture::class, 'INTERVAL'));
		$this->assertSame('strata_redis.captured', Constant::of(CronCapture::class, 'KEY'));
		$this->assertGreaterThan(
			FlushPolicy::DEFAULT_MAX_AGE,
			CronCapture::INTERVAL,
			'a realm no restore writes back does not need reading at the flush interval',
		);
	}

	#[Test]
	#[TestDox('the pass is wired to cron by attribute, with no module file to hold a hook')]
	#[Group('strata/redis')]
	public function passIsWiredToCronByAttribute(): void
	{
		$attributes = (new ReflectionMethod(CronCapture::class, 'onCron'))->getAttributes(
			Hook::class,
		);

		$this->assertCount(1, $attributes);
		$this->assertSame('cron', $attributes[0]->newInstance()->hook);
		$this->assertFileDoesNotExist(
			dirname(__DIR__, 4) . '/strata_redis.module',
			'a hook attribute is collected, so no module file is needed',
		);
	}

	#endregion
}
