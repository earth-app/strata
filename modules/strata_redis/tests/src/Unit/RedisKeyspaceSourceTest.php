<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_redis\Unit;

use Drupal\strata\Capture\Classifier\KeyspaceSourceInterface;
use Drupal\strata_redis\RedisClientFactory;
use Drupal\strata_redis\RedisKeyspaceSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves the walk is bounded, cursored, and silent about a backend that is not there.
 *
 * A source has two obligations from KeyspaceSourceInterface: stop at the limit it is given, and report
 * an unreachable backend rather than blocking on it. Both are asserted here, and so is the paging
 * itself - a source that answered a whole keyspace in one reply would pass every count assertion while
 * being the thing this class exists to avoid.
 *
 * `isAvailable()` returning FALSE is the shipped default on a host with no Redis, so it is driven as a
 * first-class case rather than as an error path.
 */
#[CoversClass(RedisKeyspaceSource::class)]
class RedisKeyspaceSourceTest extends TestCase
{
	#region Identity And Availability

	#[Test]
	#[TestDox('the source names itself the way a problem report needs')]
	#[Group('strata/redis')]
	public function sourceNamesItself(): void
	{
		$this->assertSame('redis', Constant::of(RedisKeyspaceSource::class, 'ID'));
		$this->assertSame(RedisKeyspaceSource::ID, $this->source(new FakeRedis())->id());
	}

	#[Test]
	#[TestDox('a host with no client is unavailable rather than an error')]
	#[Group('strata/redis')]
	public function hostWithNoClientIsUnavailable(): void
	{
		$source = $this->unavailableSource();

		$this->assertFalse($source->isAvailable());
		$this->assertSame('ext-redis is not loaded on this host', $source->reason());
	}

	#[Test]
	#[TestDox('a client that cannot scan is unavailable and says which command is missing')]
	#[Group('strata/redis')]
	public function clientThatCannotScanIsUnavailable(): void
	{
		$source = $this->source(new TypeOnlyRedis());

		$this->assertFalse($source->isAvailable());
		$this->assertSame(
			'the Redis client does not support scan, which a bounded walk needs',
			$source->reason(),
		);

		foreach (RedisKeyspaceSource::REQUIRED as $command) {
			$this->assertStringContainsString(
				$command,
				$source->reason(),
				sprintf('a client missing %s has to be told which command it is', $command),
			);
		}
	}

	#[Test]
	#[TestDox('a client that can scan is available with nothing to report')]
	#[Group('strata/redis')]
	public function clientThatCanScanIsAvailable(): void
	{
		$source = $this->source(new FakeRedis());

		$this->assertTrue($source->isAvailable());
		$this->assertSame('', $source->reason());
	}

	#endregion

	#region Walking

	#[Test]
	#[TestDox('the walk is a traversable of key names keyed to their sizes')]
	#[Group('strata/redis')]
	public function walkYieldsKeysWithSizes(): void
	{
		$redis = (new FakeRedis())
			->hold('queue:jobs', 'string', 'four')
			->hold('cache_render:page', 'hash', ['field' => 'value']);
		$walk = $this->source($redis)->keys(10);

		$this->assertSame(
			['queue:jobs' => 4, 'cache_render:page' => 0],
			iterator_to_array($walk),
			'a string is measured by STRLEN and a container reports zero rather than being read',
		);
	}

	#[Test]
	#[TestDox('the walk stops at the limit it was given')]
	#[Group('strata/redis')]
	public function walkStopsAtItsLimit(): void
	{
		$redis = (new FakeRedis())
			->hold('a', 'string', 'x')
			->hold('b', 'string', 'x')
			->hold('c', 'string', 'x');

		$this->assertSame(['a' => 1, 'b' => 1], iterator_to_array($this->source($redis)->keys(2)));
	}

	#[Test]
	#[TestDox('a limit below one reads nothing and does not talk to the server')]
	#[Group('strata/redis')]
	public function limitBelowOneReadsNothing(): void
	{
		$redis = (new FakeRedis())->hold('a', 'string', 'x');

		$this->assertSame([], iterator_to_array($this->source($redis)->keys(0)));
		$this->assertSame([], iterator_to_array($this->source($redis)->keys(-5)));
		$this->assertSame(0, $redis->scans, 'a walk of nothing is not a round trip');
	}

	#[Test]
	#[TestDox('the walk pages through the cursor rather than asking for everything at once')]
	#[Group('strata/redis')]
	public function walkPagesThroughTheCursor(): void
	{
		$redis = new FakeRedis();

		foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
			$redis->hold($name, 'string', 'x');
		}

		$this->assertCount(5, iterator_to_array($this->source($redis, 2)->keys(10)));
		$this->assertSame(3, $redis->scans, 'five keys at two per page is three calls');
	}

	#[Test]
	#[TestDox('a page size below one is clamped rather than asking for an empty page forever')]
	#[Group('strata/redis')]
	public function pageSizeBelowOneIsClamped(): void
	{
		$redis = (new FakeRedis())->hold('a', 'string', 'x')->hold('b', 'string', 'x');

		$this->assertCount(2, iterator_to_array($this->source($redis, 0)->keys(10)));
		$this->assertSame(2, $redis->scans);
	}

	#[Test]
	#[TestDox('an empty key name is passed over')]
	#[Group('strata/redis')]
	public function emptyKeyNameIsPassedOver(): void
	{
		$redis = (new FakeRedis())->hold('', 'string', 'x')->hold('queue:jobs', 'string', 'x');

		$this->assertSame(['queue:jobs' => 1], iterator_to_array($this->source($redis)->keys(10)));
	}

	#[Test]
	#[TestDox('a refused walk ends the pass rather than raising')]
	#[Group('strata/redis')]
	public function refusedWalkEndsThePass(): void
	{
		$redis = (new FakeRedis())->hold('a', 'string', 'x');
		$redis->scanRefuses = true;

		$this->assertSame([], iterator_to_array($this->source($redis)->keys(10)));
	}

	#[Test]
	#[TestDox('a client with no strlen reports every size as zero')]
	#[Group('strata/redis')]
	public function clientWithNoStrlenReportsZeroSizes(): void
	{
		$redis = new ScanOnlyRedis();
		$redis->names = ['queue:jobs', 'cache_render:page'];

		$this->assertSame(
			['queue:jobs' => 0, 'cache_render:page' => 0],
			iterator_to_array($this->source($redis)->keys(10)),
		);
	}

	#[Test]
	#[TestDox('a key that refuses strlen is measured as zero rather than read to be measured')]
	#[Group('strata/redis')]
	public function keyThatRefusesStrlenIsZero(): void
	{
		$redis = (new FakeRedis())->hold('queue:jobs', 'string', 'four');
		$redis->failing = ['strlen'];

		$this->assertSame(['queue:jobs' => 0], iterator_to_array($this->source($redis)->keys(10)));
	}

	#endregion

	#region Refusals

	#[Test]
	#[TestDox('the walk raises only once it is iterated, and names why')]
	#[Group('strata/redis')]
	public function walkRaisesOnlyWhenIterated(): void
	{
		$walk = $this->unavailableSource()->keys(10);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('ext-redis is not loaded on this host');

		iterator_to_array($walk);
	}

	#[Test]
	#[TestDox('a walk over a client that cannot scan raises rather than reporting nothing')]
	#[Group('strata/redis')]
	public function walkOverAClientThatCannotScanRaises(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('the Redis client does not support scan');

		iterator_to_array($this->source(new TypeOnlyRedis())->keys(10));
	}

	#endregion

	/**
	 * A source over an injected client.
	 *
	 * @param object $client
	 *   The stand-in the walk runs against.
	 * @param int $page
	 *   Keys asked for per SCAN call.
	 *
	 * @return RedisKeyspaceSource
	 *   The source.
	 */
	private function source(
		object $client,
		int $page = RedisKeyspaceSource::PAGE,
	): RedisKeyspaceSource {
		return new RedisKeyspaceSource(
			new RedisClientFactory(new RecordingLogger(), $client),
			$page,
		);
	}

	/**
	 * A source over a factory that cannot resolve a client.
	 *
	 * Skipped where `ext-redis` is loaded, because the factory would then open a real socket and the
	 * outcome would depend on what is listening on the host.
	 *
	 * @return RedisKeyspaceSource
	 *   The source.
	 */
	private function unavailableSource(): RedisKeyspaceSource
	{
		if (RedisClientFactory::isSupported()) {
			$this->markTestSkipped(
				'ext-redis is loaded here, so an absent client cannot be driven',
			);
		}

		return new RedisKeyspaceSource(new RedisClientFactory(new RecordingLogger()));
	}
}
