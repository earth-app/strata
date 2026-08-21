<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Engine;
use Drupal\strata\Storage\ProviderStats;
use Drupal\strata\Storage\ProviderStatStore;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves the bill a store runs up survives the process that ran it up.
 *
 * Object storage charges for requests as well as for bytes, and the request count is the line a
 * small site cannot escape: at a 15 second flush interval it is site-size independent. A ProviderStats
 * only knows about the process holding it, so a site that flushes in a queue worker and reports in a
 * web request would have no way to see its own traffic without this table.
 *
 * The lane covers what accumulation gets wrong when it is written by hand: a second window on the
 * same day has to add rather than replace, a peak has to survive a later smaller reading, and a
 * duration in milliseconds has to sum exactly however many times it is added to. It also drives the
 * whole path once, from a flush that really writes objects through to rows on disk, because a
 * decorator that is never wired in counts perfectly and reports nothing.
 */
class ProviderStatTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'field', 'key', 'strata'];

	/**
	 * A fixed Monday at midnight UTC, so a day boundary is never near a test's own clock.
	 */
	private const MONDAY = 1_700_000_000 - (1_700_000_000 % 86_400);

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

	#region Accumulating

	#[Test]
	#[TestDox('a window becomes one row per operation it used')]
	#[Group('strata/storage')]
	public function aWindowBecomesOneRowPerOperation(): void
	{
		$stats = new ProviderStats();
		$stats->record('put', 4096, 0.25);
		$stats->record('put', 2048, 0.15);
		$stats->record('get', 1024, 0.05);

		$this->assertSame(2, $this->store()->record('local', $stats, self::MONDAY));

		$rows = $this->store()->byOperation();

		$this->assertSame(['put', 'get'], array_keys($rows), 'order follows OPERATIONS');
		$this->assertSame(2, $rows['put']['requests']);
		$this->assertSame(6144, $rows['put']['bytes']);
		$this->assertSame(1, $rows['get']['requests']);
	}

	#[Test]
	#[TestDox('a second window on the same day adds to the row the first one wrote')]
	#[Group('strata/storage')]
	public function aSecondWindowAccumulates(): void
	{
		$first = new ProviderStats();
		$first->record('put', 1000, 0.5);

		$second = new ProviderStats();
		$second->record('put', 500, 0.25);
		$second->record('put', 500, 0.25);

		$this->store()->record('local', $first, self::MONDAY);
		$this->store()->record('local', $second, self::MONDAY + 3600);

		$rows = $this->store()->byOperation();

		$this->assertSame(1, $this->store()->count(), 'one provider, one day, one verb, one row');
		$this->assertSame(3, $rows['put']['requests']);
		$this->assertSame(2000, $rows['put']['bytes']);
		$this->assertSame(1.0, $rows['put']['seconds'], 'milliseconds sum exactly');
	}

	#[Test]
	#[TestDox('the slowest request stays the slowest after a faster window')]
	#[Group('strata/storage')]
	public function thePeakSurvivesAFasterWindow(): void
	{
		$slow = new ProviderStats();
		$slow->record('put', 1, 4.0);

		$fast = new ProviderStats();
		$fast->record('put', 1, 0.01);

		$this->store()->record('local', $slow, self::MONDAY);
		$this->store()->record('local', $fast, self::MONDAY);

		$this->assertSame(4.0, $this->store()->byOperation()['put']['slowest']);
	}

	#[Test]
	#[TestDox('a window that recorded nothing writes no row')]
	#[Group('strata/storage')]
	public function anEmptyWindowWritesNothing(): void
	{
		$this->assertSame(0, $this->store()->record('local', new ProviderStats(), self::MONDAY));
		$this->assertSame(0, $this->store()->count());
	}

	#[Test]
	#[TestDox('a window with no provider id writes no row')]
	#[Group('strata/storage')]
	public function anUnnamedProviderWritesNothing(): void
	{
		$stats = new ProviderStats();
		$stats->record('put', 1, 0.1);

		$this->assertSame(0, $this->store()->record('', $stats, self::MONDAY));
		$this->assertSame(0, $this->store()->count());
	}

	#[Test]
	#[TestDox('two providers on one day keep separate rows')]
	#[Group('strata/storage')]
	public function twoProvidersStaySeparate(): void
	{
		$local = new ProviderStats();
		$local->record('put', 100, 0.1);

		$s3 = new ProviderStats();
		$s3->record('put', 200, 0.2);
		$s3->record('get', 50, 0.05);

		$this->store()->record('local', $local, self::MONDAY);
		$this->store()->record('s3', $s3, self::MONDAY);

		$byProvider = $this->store()->byProvider();

		$this->assertSame(['s3', 'local'], array_keys($byProvider), 'busiest first');
		$this->assertSame(2, $byProvider['s3']['requests']);
		$this->assertSame(['local', 's3'], $this->store()->providers());
	}

	#endregion

	#region Reading

	#[Test]
	#[TestDox('total() splits the billed writes from the billed reads')]
	#[Group('strata/storage')]
	public function totalSplitsTheTwoBilledClasses(): void
	{
		$stats = new ProviderStats();
		$stats->record('put', 10, 0.1);
		$stats->record('delete', 0, 0.02);
		$stats->record('list', 0, 0.03);
		$stats->record('get', 20, 0.04, true);
		$stats->record('head', 0, 0.01);

		$this->store()->record('local', $stats, self::MONDAY);

		$total = $this->store()->total();

		$this->assertSame(3, $total['classA'], 'put, delete and list are billed as writes');
		$this->assertSame(2, $total['classB'], 'get and head are billed as reads');
		$this->assertSame(5, $total['requests']);
		$this->assertSame(
			1,
			$total['failures'],
			'a refused request was still made and still billed',
		);
		$this->assertSame(30, $total['bytes']);
	}

	#[Test]
	#[TestDox('total() over an empty table is zeroes rather than a division by nothing')]
	#[Group('strata/storage')]
	public function totalOnAnEmptyTableIsZeroes(): void
	{
		$this->assertSame(
			[
				'requests' => 0,
				'failures' => 0,
				'bytes' => 0,
				'seconds' => 0.0,
				'classA' => 0,
				'classB' => 0,
			],
			$this->store()->total(),
		);
		$this->assertSame([], $this->store()->byOperation());
		$this->assertSame([], $this->store()->daily());
		$this->assertSame([], $this->store()->providers());
	}

	#[Test]
	#[TestDox('a window outside the range asked for is left out')]
	#[Group('strata/storage')]
	public function aWindowOutsideTheRangeIsExcluded(): void
	{
		$old = new ProviderStats();
		$old->record('put', 100, 0.1);

		$recent = new ProviderStats();
		$recent->record('put', 200, 0.2);

		$this->store()->record('local', $old, self::MONDAY);
		$this->store()->record('local', $recent, self::MONDAY + 86_400 * 10);

		$this->assertSame(300, $this->store()->total()['bytes'], 'no range reads everything');
		$this->assertSame(
			200,
			$this->store()->total(self::MONDAY + 86_400 * 5)['bytes'],
			'a from bound drops the older day',
		);
		$this->assertSame(
			100,
			$this->store()->total(0, self::MONDAY + 86_400 * 5)['bytes'],
			'a to bound drops the newer day',
		);
	}

	#[Test]
	#[TestDox('daily() is oldest first and a quiet day has no entry at all')]
	#[Group('strata/storage')]
	public function dailyIsOrderedAndSparse(): void
	{
		foreach ([2, 0, 5] as $offset) {
			$stats = new ProviderStats();
			$stats->record('put', 10, 0.1);

			$this->store()->record('local', $stats, self::MONDAY + 86_400 * $offset);
		}

		$days = array_column($this->store()->daily(), 'day');

		$this->assertSame(
			[self::MONDAY, self::MONDAY + 86_400 * 2, self::MONDAY + 86_400 * 5],
			$days,
			'three days recorded, three entries, nothing invented for the gaps',
		);
	}

	#[Test]
	#[TestDox('a day is midnight UTC, so any moment inside it lands on the same row')]
	#[Group('strata/storage')]
	public function aDayIsMidnightUtc(): void
	{
		$this->assertSame(self::MONDAY, ProviderStatStore::day(self::MONDAY));
		$this->assertSame(self::MONDAY, ProviderStatStore::day(self::MONDAY + 86_399));
		$this->assertSame(self::MONDAY + 86_400, ProviderStatStore::day(self::MONDAY + 86_400));
		$this->assertSame(0, ProviderStatStore::day(0));
		$this->assertSame(0, ProviderStatStore::day(-1), 'a clock before the epoch is not a day');
	}

	#[Test]
	#[TestDox('prune() drops earlier days and keeps the day the cutoff falls in')]
	#[Group('strata/storage')]
	public function pruneKeepsTheCutoffDay(): void
	{
		foreach ([0, 1, 2] as $offset) {
			$stats = new ProviderStats();
			$stats->record('put', 10, 0.1);

			$this->store()->record('local', $stats, self::MONDAY + 86_400 * $offset);
		}

		$removed = $this->store()->prune(self::MONDAY + 86_400 + 500);

		$this->assertSame(1, $removed);
		$this->assertSame(2, $this->store()->count());
		$this->assertSame(
			[self::MONDAY + 86_400, self::MONDAY + 86_400 * 2],
			array_column($this->store()->daily(), 'day'),
		);
	}

	#endregion

	#region Wiring

	#[Test]
	#[TestDox('a flush through the engine leaves the requests it made in the accumulator')]
	#[Group('strata/storage')]
	public function aFlushIsCounted(): void
	{
		$this->user('counted');
		$this->engine()->flusher()->flush(true);

		$stats = $this->engine()->providerStats();

		$this->assertGreaterThan(0, $stats->classA(), 'sealing history is a set of billed writes');
		$this->assertGreaterThan(0, $stats->bytes());
		$this->assertSame(0, $stats->failures());
	}

	#[Test]
	#[TestDox('persisting moves the accumulator onto disk and starts a fresh window')]
	#[Group('strata/storage')]
	public function persistingMovesAndClearsTheWindow(): void
	{
		$this->user('persisted');
		$this->engine()->flusher()->flush(true);

		$requests = $this->engine()->providerStats()->operations();

		$this->assertGreaterThan(0, $this->engine()->persistProviderStats());
		$this->assertSame(
			0,
			$this->engine()->providerStats()->operations(),
			'a persisted window is reset, so a second terminate cannot double-count it',
		);
		$this->assertSame($requests, $this->store()->total()['requests']);
		$this->assertSame(['local'], $this->store()->providers());
	}

	#[Test]
	#[TestDox('persisting a process that never touched the store writes nothing')]
	#[Group('strata/storage')]
	public function persistingAnUntouchedStoreWritesNothing(): void
	{
		$this->assertSame(0, $this->engine()->persistProviderStats());
		$this->assertSame(0, $this->store()->count());
	}

	#[Test]
	#[TestDox('the recorder does not change what the provider is or where it writes')]
	#[Group('strata/storage')]
	public function theRecorderIsTransparent(): void
	{
		$provider = $this->engine()->provider();

		$provider->put('probe', 'payload');

		$this->assertSame('payload', $provider->get('probe'));
		$this->assertTrue($provider->exists('probe'));
		$this->assertSame('local', $provider->id());

		$stats = $this->engine()->providerStats();

		$this->assertSame(1, $stats->byOperation()['put']['count']);
		$this->assertSame(1, $stats->byOperation()['get']['count']);
		$this->assertSame(1, $stats->byOperation()['head']['count'], 'exists() is a head');
	}

	#endregion

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
	 * The record under test.
	 *
	 * @return ProviderStatStore
	 *   The store.
	 */
	private function store(): ProviderStatStore
	{
		return $this->engine()->providerStatStore();
	}

	/**
	 * A saved user, which is one captured operation.
	 *
	 * @param string $name
	 *   The account name.
	 *
	 * @return User
	 *   The saved account.
	 */
	private function user(string $name): User
	{
		$user = User::create(['name' => $name, 'mail' => $name . '@example.com', 'status' => 1]);
		$user->save();

		return $user;
	}
}
