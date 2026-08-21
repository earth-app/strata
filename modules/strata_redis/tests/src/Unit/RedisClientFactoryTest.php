<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_redis\Unit;

use Drupal\strata\Journal\JournalFactory;
use Drupal\strata_redis\RedisClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Proves an absent Redis ends in a client of NULL and a sentence, never in an exception.
 *
 * The factory sits under a cron hook, and a cron hook that raises stops every later hook in the
 * queue, so "there is no Redis here" has to be an answer rather than a fault. This host has no
 * `ext-redis`, which makes it the shipped default case rather than an edge one: the tests that need
 * the extension present say so and skip.
 *
 * The connection defaults are asserted against the journal's own, because both halves of the module
 * read one `redis.connection` settings key and a site that configured it once configures nothing else.
 */
#[CoversClass(RedisClientFactory::class)]
class RedisClientFactoryTest extends TestCase
{
	#region An Injected Client

	#[Test]
	#[TestDox('an injected client is handed back with no reason attached')]
	#[Group('strata/redis')]
	public function injectedClientIsHandedBack(): void
	{
		$client = new FakeRedis();
		$factory = new RedisClientFactory(new RecordingLogger(), $client);

		$this->assertSame($client, $factory->create());
		$this->assertTrue($factory->isAvailable());
		$this->assertSame('', $factory->reason());
	}

	#[Test]
	#[TestDox('the resolved answer is held, so a second caller gets the same client')]
	#[Group('strata/redis')]
	public function resolvedAnswerIsHeld(): void
	{
		$factory = new RedisClientFactory(new RecordingLogger(), new FakeRedis());

		$this->assertSame($factory->create(), $factory->create());
	}

	#endregion

	#region An Absent Extension

	#[Test]
	#[TestDox('a host without ext-redis reports no client instead of raising')]
	#[Group('strata/redis')]
	public function absentExtensionIsReported(): void
	{
		$logger = new RecordingLogger();
		$factory = $this->unavailable($logger);

		$this->assertNull($factory->create());
		$this->assertFalse($factory->isAvailable());
		$this->assertSame('ext-redis is not loaded on this host', $factory->reason());
	}

	#[Test]
	#[TestDox('an absent extension is not logged, because it is not a fault')]
	#[Group('strata/redis')]
	public function absentExtensionIsNotLogged(): void
	{
		$logger = new RecordingLogger();

		$this->unavailable($logger)->isAvailable();

		$this->assertSame([], $logger->records);
	}

	#[Test]
	#[TestDox('a repeated ask gets the same answer rather than a second attempt')]
	#[Group('strata/redis')]
	public function absentExtensionIsAskedOnce(): void
	{
		$factory = $this->unavailable(new RecordingLogger());

		$this->assertNull($factory->create());
		$this->assertNull($factory->create());
		$this->assertSame('ext-redis is not loaded on this host', $factory->reason());
	}

	#[Test]
	#[TestDox('support follows what the host has loaded')]
	#[Group('strata/redis')]
	public function supportFollowsTheHost(): void
	{
		$this->assertSame(extension_loaded('redis'), RedisClientFactory::isSupported());
	}

	#endregion

	#region Connection Settings

	#[Test]
	#[TestDox('the connection is read from the same settings key the journal reads')]
	#[Group('strata/redis')]
	public function connectionSettingsMatchTheJournal(): void
	{
		$this->assertSame('redis.connection', Constant::of(RedisClientFactory::class, 'SETTINGS'));

		foreach (['DEFAULT_HOST', 'DEFAULT_PORT', 'TIMEOUT'] as $name) {
			$this->assertSame(
				Constant::of(JournalFactory::class, $name),
				Constant::of(RedisClientFactory::class, $name),
				sprintf('%s has to agree with the journal, which reads the same settings', $name),
			);
		}
	}

	#[Test]
	#[TestDox('the connect timeout is short enough not to hold up the rest of cron')]
	#[Group('strata/redis')]
	public function connectTimeoutIsShort(): void
	{
		$this->assertLessThanOrEqual(1.0, RedisClientFactory::TIMEOUT);
		$this->assertGreaterThan(0.0, RedisClientFactory::TIMEOUT);
	}

	#endregion

	/**
	 * A factory that has to resolve its own client, on a host where it cannot.
	 *
	 * Skipped where the extension is loaded, because the factory would then open a real socket to
	 * whatever is listening on the default port and the outcome would depend on the host.
	 *
	 * @param RecordingLogger $logger
	 *   The logger the factory reports through.
	 *
	 * @return RedisClientFactory
	 *   The factory.
	 */
	private function unavailable(RecordingLogger $logger): RedisClientFactory
	{
		if (RedisClientFactory::isSupported()) {
			$this->markTestSkipped(
				'ext-redis is loaded here, so an absent client cannot be driven',
			);
		}

		return new RedisClientFactory($logger);
	}
}
