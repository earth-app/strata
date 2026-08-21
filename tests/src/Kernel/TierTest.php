<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Engine;
use Drupal\strata\Site\SiteScopedProvider;
use Drupal\strata\Tier\TieredProvider;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

/**
 * Proves history spread across several buckets still reads and restores as one history.
 *
 * Three local directories stand in for three buckets. That is not a shortcut: a provider is reached
 * through one interface and the local one implements the same contract S3 does, so a ladder that
 * works over directories is a ladder whose routing, placement and refusal behaviour have been
 * exercised. What it does not exercise is an endpoint's own failures, which is what the MinIO lane
 * is for.
 *
 * The failure this lane exists to catch is the quiet one. A tiered store can put an object in a
 * bucket that nothing later looks in, and nothing raises: the write succeeded, the read simply finds
 * nothing, and the object is gone. So every test here reads back through the router rather than
 * trusting that a write went somewhere.
 *
 * The other property is the one that makes tiering safe to turn on: **off, it must behave exactly as
 * a single bucket does.** A site that has been writing to one bucket for a year must not change
 * where its objects go because a feature it did not configure exists.
 */
class TierTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'field', 'key', 'strata'];

	/**
	 * A day in seconds.
	 */
	private const DAY = 86_400;

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
			->save();

		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();
	}

	#region Off By Default

	#[Test]
	#[TestDox('a site that configured no ladder writes to one bucket exactly as before')]
	#[Group('strata/tier')]
	public function offMeansUntouched(): void
	{
		$this->assertNull($this->engine()->tierMap(), 'no ladder is configured');
		$this->assertNull($this->engine()->tiers(), 'so no router is built');
		$this->assertNull($this->engine()->tierMigrator(), 'and there is nothing to migrate');

		$this->flush('untiered');

		$this->assertNotSame([], $this->keys($this->storeRoot), 'everything is in the one bucket');
		$this->assertTrue($this->engine()->verifier()->verify()->isClean());
	}

	#[Test]
	#[TestDox('a ladder of one tier stays on the single-bucket path')]
	#[Group('strata/tier')]
	public function oneTierIsNotATieredSite(): void
	{
		$this->configureLadder([
			['name' => 'now', 'provider' => 'local', 'location' => $this->bucket(0)],
		]);

		$this->assertNull(
			$this->engine()->tierMap(),
			'one tier is what an untiered site already does, so it is not a ladder',
		);
	}

	#[Test]
	#[
		TestDox(
			'a ladder that cannot be honoured is refused when it is read, not at the first flush',
		),
	]
	#[Group('strata/tier')]
	public function anUnusableLadderIsRefusedEarly(): void
	{
		$this->configureLadder([
			['name' => 'now', 'provider' => 'local', 'location' => $this->bucket(0)],
			[
				'name' => 'same',
				'provider' => 'local',
				'location' => $this->bucket(0),
				'from_age' => 100,
			],
		]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('storage ladder is not usable');

		$this->engine()->tierMap();
	}

	#endregion

	#region One History, Several Buckets

	#[Test]
	#[TestDox('a tiered site writes into the nearest bucket and leaves the cold ones empty')]
	#[Group('strata/tier')]
	public function writesLandInTheNearestBucket(): void
	{
		$this->tier();
		$this->flush('first');

		$this->assertNotSame(
			[],
			$this->keys($this->bucket(0)),
			'the near bucket holds the history',
		);
		$this->assertSame([], $this->keys($this->bucket(1)), 'nothing was written to last month');
		$this->assertSame([], $this->keys($this->bucket(2)), 'nor to the year bucket');
	}

	#[Test]
	#[TestDox('the router is what the engine hands out, under the site prefix')]
	#[Group('strata/tier')]
	public function theRouterSitsUnderTheSitePrefix(): void
	{
		$this->tier();

		$provider = $this->engine()->provider();

		$this->assertInstanceOf(SiteScopedProvider::class, $provider);
		$this->assertInstanceOf(TieredProvider::class, $provider->unscoped());
		$this->assertSame(3, $this->engine()->tiers()?->tiers()->count());
	}

	#[Test]
	#[TestDox('an object moved to a cold bucket still reads back through the router')]
	#[Group('strata/tier')]
	public function aMovedObjectStillReads(): void
	{
		$this->tier();
		$this->flush('movable');

		$router = $this->engine()->tiers();

		$this->assertNotNull($router);

		$key = $this->keys($this->bucket(0))[0];
		$body = $router->get($key);

		$this->assertNotSame('', $body);

		// what a migration does: write the copy cold, drop the near one, record where it went
		$router->putIn(2, $key, $body);
		$router->deleteFrom(0, $key);
		$router->placement()->place($key, 2, strlen($body));
		$router->placement()->displace($key, 0);

		$this->assertSame(
			$body,
			$router->get($key),
			'a read follows the placement into the cold bucket rather than giving up',
		);
		$this->assertTrue($router->exists($key));
	}

	#[Test]
	#[TestDox('a history split across three buckets verifies clean as one history')]
	#[Group('strata/tier')]
	public function aSplitHistoryVerifiesAsOne(): void
	{
		$this->tier();

		foreach (['one', 'two', 'three', 'four'] as $name) {
			$this->flush($name);
		}

		$router = $this->engine()->tiers();

		$this->assertNotNull($router);

		// spread the history over the ladder, a third to each bucket; a ref is pinned and stays put
		$objects = array_values(
			array_filter(
				$this->keys($this->bucket(0)),
				static fn(string $key): bool => !str_contains($key, '/refs/'),
			),
		);

		$this->assertNotSame([], $objects);

		foreach ($objects as $at => $key) {
			$tier = $at % 3;

			if ($tier === 0) {
				continue;
			}

			$body = $router->get($key);
			$router->putIn($tier, $key, $body);
			$router->deleteFrom(0, $key);
			$router->placement()->place($key, $tier, strlen($body));
			$router->placement()->displace($key, 0);
		}

		$this->assertNotSame(
			[],
			$this->keys($this->bucket(1)),
			'the month bucket holds some of it',
		);
		$this->assertNotSame([], $this->keys($this->bucket(2)), 'and the year bucket holds some');

		$report = $this->engine()->verifier()->verify();

		$this->assertTrue(
			$report->isClean(),
			'a history in three buckets is still one history: ' . $report->summary(),
		);
		$this->assertGreaterThan(0, $report->frames);
	}

	#[Test]
	#[TestDox('a listing across the ladder returns each key once, not once per bucket')]
	#[Group('strata/tier')]
	public function aListingDoesNotRepeatAReplica(): void
	{
		$this->tier();
		$this->flush('replicated');

		$router = $this->engine()->tiers();

		$this->assertNotNull($router);

		$key = $this->keys($this->bucket(0))[0];
		$body = $router->get($key);

		// a replica: the same object in two tiers at once, which is what the safety copy is
		$router->putIn(1, $key, $body);
		$router->placement()->place($key, 1, strlen($body));

		$keys = $router->list('', null, 1000)->keys();

		$this->assertSame(
			count($keys),
			count(array_unique($keys)),
			'an object in two buckets is one object, so it is listed once',
		);
		$this->assertContains($key, $keys);
	}

	#endregion

	#region When A Tier Is Gone

	#[Test]
	#[TestDox('an unreachable tier is reported as unreachable rather than as empty')]
	#[Group('strata/tier')]
	public function anUnreachableTierIsNamed(): void
	{
		$this->configureLadder([
			['name' => 'now', 'provider' => 'local', 'location' => $this->bucket(0)],
			[
				'name' => 'gone',
				'provider' => 'local',
				'location' => 'missing://nowhere',
				'from_age' => 30 * self::DAY,
			],
		]);

		$status = $this->engine()->tiers()?->tierStatus() ?? [];

		// a reachable tier reports NULL, so `??` would read a healthy bucket as a missing row
		$this->assertArrayHasKey(0, $status);
		$this->assertArrayHasKey(1, $status);
		$this->assertNull($status[0], 'the near bucket is reachable');
		$this->assertIsString($status[1], 'the far one says why it is not');
		$this->assertStringContainsString('missing', (string) $status[1]);
	}

	#[Test]
	#[TestDox('a restore says which buckets it needs before it starts')]
	#[Group('strata/tier')]
	public function aRestoreNamesTheBucketsItNeeds(): void
	{
		$this->tier();
		$this->flush('needed');

		$requirements = $this->engine()->tierRequirements();

		$this->assertNotNull($requirements);

		$report = $requirements->require((string) $this->engine()->commitIndex()->newest()['id']);

		$this->assertGreaterThan(0, $report->objects, 'the restore needs objects');
		$this->assertNotSame([], $report->tiers, 'and it names the tier each one is in');
		$this->assertTrue(
			$report->isSatisfiable(),
			'every bucket it needs answers, so the restore can proceed: ' . $report->summary(),
		);
		$this->assertSame([], $report->unreachable(), 'and none of them is named as unreachable');
	}

	#endregion

	#region Rebuilding Placement

	#[Test]
	#[TestDox('placement is rebuilt by listing the buckets, so dropping the table costs nothing')]
	#[Group('strata/tier')]
	public function placementIsRebuiltFromTheBuckets(): void
	{
		$this->tier();
		$this->flush('rebuildable');

		$router = $this->engine()->tiers();

		$this->assertNotNull($router);

		$key = $this->keys($this->bucket(0))[0];
		$body = $router->get($key);

		$router->putIn(2, $key, $body);
		$router->deleteFrom(0, $key);
		$router->placement()->place($key, 2, strlen($body));
		$router->placement()->displace($key, 0);

		$before = $router->placement()->statistics();

		$this->assertGreaterThan(0, $before['objects']);

		// what an uninstall leaves: every bucket intact, the local placement rows gone
		$router->placement()->clear();

		$this->assertSame(0, $router->placement()->statistics()['objects']);

		$this->engine()->reindexer()->reindex();

		$rebuilt = $this->engine()->placementIndex()->get($key);

		$this->assertNotNull($rebuilt, 'the object was found by listing the buckets');
		$this->assertSame([2], $rebuilt->tiers(), 'and it was found in the bucket it was moved to');
		$this->assertSame(
			$body,
			$router->get($key),
			'and it reads back through the router on the rebuilt placement alone',
		);
	}

	#endregion

	#region Fixtures

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
	 * A directory standing in for one bucket.
	 *
	 * @param int $index
	 *   Tier index.
	 *
	 * @return string
	 *   The path.
	 */
	private function bucket(int $index): string
	{
		return $this->siteDirectory . '/strata-bucket-' . $index;
	}

	/**
	 * Configures a ladder and resets the engine so it is read.
	 *
	 * @param list<array<string, mixed>> $levels
	 *   The tier rows.
	 */
	private function configureLadder(array $levels): void
	{
		$this->config('strata.settings')
			->set('tiers.enabled', true)
			->set('tiers.levels', $levels)
			->save();

		$this->engine()->reset();
	}

	/**
	 * Configures the three-bucket ladder the rest of the lane uses.
	 */
	private function tier(): void
	{
		$this->configureLadder([
			['name' => 'now', 'provider' => 'local', 'location' => $this->bucket(0)],
			[
				'name' => 'month',
				'provider' => 'local',
				'location' => $this->bucket(1),
				'from_age' => 30 * self::DAY,
			],
			[
				'name' => 'year',
				'provider' => 'local',
				'location' => $this->bucket(2),
				'from_age' => 365 * self::DAY,
			],
		]);
	}

	/**
	 * Captures a write and seals it.
	 *
	 * @param string $name
	 *   The account name, which makes the operation distinct.
	 */
	private function flush(string $name): void
	{
		$user = User::create([
			'name' => 'tier-' . $name,
			'mail' => 'tier-' . $name . '@example.com',
			'status' => 1,
		]);
		$user->save();

		$this->engine()->flusher()->flush(true);
	}

	/**
	 * Object keys a bucket directory holds, read from disk rather than through the router.
	 *
	 * @param string $root
	 *   The bucket directory.
	 * @param string $prefix
	 *   Only keys under this prefix.
	 *
	 * @return list<string>
	 *   The keys exactly as the bucket holds them, site prefix included, since the router this lane
	 *   drives sits under that prefix rather than above it.
	 */
	private function keys(string $root, string $prefix = ''): array
	{
		if (!is_dir($root)) {
			return [];
		}

		$site = $this->engine()->site()->id();
		$found = [];
		$walk = static function (string $directory, string $at) use (&$walk, &$found): void {
			foreach (scandir($directory) ?: [] as $entry) {
				if ($entry === '.' || $entry === '..') {
					continue;
				}

				$path = $directory . '/' . $entry;
				$key = $at === '' ? $entry : $at . '/' . $entry;

				if (is_dir($path)) {
					$walk($path, $key);

					continue;
				}

				$found[] = $key;
			}
		};

		$walk($root, '');

		$keys = [];

		foreach ($found as $key) {
			if ($prefix === '' || str_starts_with($key, $site . '/' . $prefix)) {
				$keys[] = $key;
			}
		}

		sort($keys);

		return $keys;
	}

	#endregion
}
