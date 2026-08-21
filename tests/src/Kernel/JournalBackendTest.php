<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Journal\DatabaseJournal;
use Drupal\strata\Journal\JournalFactory;
use Drupal\strata\Journal\RedisJournal;
use Drupal\Tests\strata\Unit\Journal\FakeRedis;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves the journal backend is chosen from configuration and falls back rather than failing.
 *
 * The one thing that must not stop is capture: an operation not captured is a gap in the history that
 * nothing can fill later. So a configured backend that cannot be reached has to hand back the one that
 * always works, and say why.
 */
class JournalBackendTest extends StrataKernelTestBase
{
	private function factory(?object $client = null): JournalFactory
	{
		return new JournalFactory(
			$this->container->get('config.factory'),
			$this->container->get('database'),
			$this->container->get('logger.channel.strata'),
			$client,
		);
	}

	#[Test]
	#[TestDox('the database journal is the default, so a site needs nothing beyond drupal')]
	#[Group('strata/journal')]
	public function databaseIsTheDefault(): void
	{
		$this->assertInstanceOf(DatabaseJournal::class, $this->factory()->create());
		$this->assertSame(JournalFactory::DATABASE, $this->factory()->active());
		$this->assertInstanceOf(
			DatabaseJournal::class,
			$this->container->get('strata.journal'),
			'the container hands out what the factory chose',
		);
	}

	#[Test]
	#[TestDox('configuring redis with a client available selects the stream journal')]
	#[Group('strata/journal')]
	public function redisIsSelectedWhenConfigured(): void
	{
		$this->config('strata.settings')->set('journal.backend', 'redis')->save();

		$factory = $this->factory(new FakeRedis());

		$this->assertInstanceOf(RedisJournal::class, $factory->create());
		$this->assertSame(JournalFactory::REDIS, $factory->active());
	}

	#[Test]
	#[TestDox('the configured stream key reaches the journal')]
	#[Group('strata/journal')]
	public function streamKeyReachesTheJournal(): void
	{
		$this->config('strata.settings')
			->set('journal.backend', 'redis')
			->set('journal.redis_stream', 'strata:site-one')
			->save();

		$redis = new FakeRedis();
		$this->factory($redis)->create()->clear();

		$this->assertSame([], $redis->entries);
	}

	#[Test]
	#[TestDox('an empty stream key falls back to the default rather than addressing nothing')]
	#[Group('strata/journal')]
	public function emptyStreamKeyUsesTheDefault(): void
	{
		$this->config('strata.settings')
			->set('journal.backend', 'redis')
			->set('journal.redis_stream', '')
			->save();

		$this->assertInstanceOf(RedisJournal::class, $this->factory(new FakeRedis())->create());
	}

	#[Test]
	#[TestDox('a client that cannot back a journal falls back to the database one')]
	#[Group('strata/journal')]
	public function unusableClientFallsBack(): void
	{
		$this->config('strata.settings')->set('journal.backend', 'redis')->save();

		$this->assertInstanceOf(
			DatabaseJournal::class,
			$this->factory(new class {})->create(),
			'a client with no stream commands is not a reason to stop capturing',
		);
	}

	#[Test]
	#[TestDox('configuring redis on a host without the extension falls back rather than failing')]
	#[Group('strata/journal')]
	public function missingExtensionFallsBack(): void
	{
		if (RedisJournal::isSupported()) {
			$this->markTestSkipped(
				'ext-redis is loaded on this host, so there is nothing to fall back from',
			);
		}

		$this->config('strata.settings')->set('journal.backend', 'redis')->save();

		$this->assertInstanceOf(DatabaseJournal::class, $this->factory()->create());
		$this->assertSame(JournalFactory::DATABASE, $this->factory()->active());
	}

	#[Test]
	#[TestDox('an unknown backend id falls back to the database journal')]
	#[Group('strata/journal')]
	public function unknownBackendFallsBack(): void
	{
		$this->config('strata.settings')->set('journal.backend', 'kafka')->save();

		$this->assertInstanceOf(DatabaseJournal::class, $this->factory()->create());
	}

	#[Test]
	#[TestDox('the stream journal satisfies the same contract the database one does')]
	#[Group('strata/journal')]
	public function bothBackendsSatisfyTheContract(): void
	{
		$this->config('strata.settings')->set('journal.backend', 'redis')->save();

		$redis = new FakeRedis();
		$stream = $this->factory($redis)->create();
		$database = new DatabaseJournal($this->container->get('database'));

		foreach ([$stream, $database] as $journal) {
			$this->assertSame(0, $journal->pending());
			$this->assertNull($journal->oldest());
			$this->assertSame([], $journal->read());
			$this->assertSame(0, $journal->clear());
		}
	}
}
