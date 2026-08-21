<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Tier;

use Drupal\strata\Tier\MemoryPlacementIndex;
use Drupal\strata\Tier\Placement;
use Drupal\strata\Tier\Tier;
use Drupal\strata\Tier\TierMap;
use Drupal\strata\Tier\TierPolicy;
use Drupal\strata\Tier\TierTarget;
use Drupal\strata\Tree\RefStore;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Proves a ladder of buckets decides where an object goes and where to look for it.
 *
 * Tiering is the one feature here that can lose history by being subtly wrong rather than by failing:
 * an object written to a bucket nothing later reads is gone, and nothing raises. So the rules are
 * tested as rules, separately from any storage.
 *
 * Four properties, and each has a way of being got wrong that this pins:
 *
 * - **A ladder cannot be built in a state that loses objects.** Thresholds that do not increase, a
 *   nearest tier with an age, two tiers naming one bucket - each would place an object somewhere a
 *   read never looks, so the constructor refuses rather than accepting and coping.
 * - **A write always lands in the nearest tier.** Age decides where a copy is MOVED to later, never
 *   where it is written first, because an object's age is zero when it is written.
 * - **A read tries the tier the placement names before the ladder order.** The placement is the
 *   record of where a thing actually is; the ladder is only a guess when there is no record.
 * - **A ref is pinned to the nearest tier.** A ladder that let the tip migrate would leave a site
 *   whose current state lived in a bucket meant for last year.
 */
#[CoversClass(TierMap::class)]
#[CoversClass(TierPolicy::class)]
#[CoversClass(Placement::class)]
#[CoversClass(MemoryPlacementIndex::class)]
class TierTest extends TestCase
{
	/**
	 * A day in seconds, which is the unit the ladder is configured in.
	 */
	private const DAY = 86_400;

	#region Fixtures

	/**
	 * One tier.
	 *
	 * @param int $index
	 *   Position on the ladder.
	 * @param string $name
	 *   What an operator calls it.
	 * @param int $fromAge
	 *   Seconds untouched before an object belongs here.
	 * @param bool $retainBelow
	 *   TRUE to keep the copy below as well, making this a replica.
	 *
	 * @return Tier
	 *   The tier.
	 */
	private function tier(
		int $index,
		string $name,
		int $fromAge = 0,
		bool $retainBelow = false,
	): Tier {
		return new Tier(
			$index,
			new TierTarget($name, 'local', '/tmp/' . $name),
			$fromAge,
			$retainBelow,
		);
	}

	/**
	 * The four-tier ladder the feature was asked for: now, last month, six months, two years.
	 *
	 * @return TierMap
	 *   The ladder.
	 */
	private function ladder(): TierMap
	{
		return new TierMap([
			$this->tier(0, 'now'),
			$this->tier(1, 'month', 30 * self::DAY),
			$this->tier(2, 'half-year', 180 * self::DAY),
			$this->tier(3, 'two-years', 730 * self::DAY),
		]);
	}

	#endregion

	#region Ladders It Refuses

	#[Test]
	#[TestDox('an empty ladder is refused, since there would be nowhere to write')]
	#[Group('strata/tier')]
	public function anEmptyLadderIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new TierMap([]);
	}

	#[Test]
	#[TestDox('a nearest tier with an age threshold is refused')]
	#[Group('strata/tier')]
	public function aNearestTierWithAnAgeIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new Tier(0, new TierTarget('now', 'local'), 3600);
	}

	#[Test]
	#[
		TestDox(
			'thresholds that do not increase are refused, because an object would have two homes',
		),
	]
	#[Group('strata/tier')]
	public function nonIncreasingThresholdsAreRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new TierMap([
			$this->tier(0, 'now'),
			$this->tier(1, 'later', 90 * self::DAY),
			$this->tier(2, 'sooner', 30 * self::DAY),
		]);
	}

	#[Test]
	#[TestDox('two tiers naming one bucket are refused, since a move would be a no-op')]
	#[Group('strata/tier')]
	public function aRepeatedBucketIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new TierMap([
			new Tier(0, new TierTarget('now', 'local', '/tmp/shared')),
			new Tier(1, new TierTarget('month', 'local', '/tmp/shared'), 30 * self::DAY),
		]);
	}

	#[Test]
	#[TestDox('a ladder deeper than the cap is refused rather than silently truncated')]
	#[Group('strata/tier')]
	public function aLadderPastTheCapIsRefused(): void
	{
		$tiers = [$this->tier(0, 'now')];

		for ($at = 1; $at <= TierMap::MAX_TIERS; $at++) {
			$tiers[] = $this->tier($at, 'tier-' . $at, $at * self::DAY);
		}

		$this->expectException(InvalidArgumentException::class);

		new TierMap($tiers);
	}

	#endregion

	#region Where A Thing Goes

	#[Test]
	#[TestDox('the ladder reports itself as split only once it has more than one tier')]
	#[Group('strata/tier')]
	public function oneTierIsNotSplit(): void
	{
		$single = new TierMap([$this->tier(0, 'only')]);

		$this->assertFalse($single->isSplit());
		$this->assertSame(1, $single->count());
		$this->assertTrue($single->nearest()->isNearest());
		$this->assertSame(0, $single->coldest()->index, 'one tier is both ends of the ladder');

		$this->assertTrue($this->ladder()->isSplit());
		$this->assertSame(4, $this->ladder()->count());
		$this->assertSame(3, $this->ladder()->coldest()->index);
	}

	#[Test]
	#[TestDox('a write lands in the nearest tier whatever the ladder says about age')]
	#[Group('strata/tier')]
	public function everyWriteLandsNearest(): void
	{
		$this->assertSame(0, (new TierPolicy($this->ladder()))->tierForWrite());
	}

	#[Test]
	#[TestDox('age decides which tier an object belongs in, oldest threshold winning')]
	#[Group('strata/tier')]
	public function ageChoosesTheTier(): void
	{
		$ladder = $this->ladder();

		$this->assertSame(0, $ladder->forAge(0)->index);
		$this->assertSame(0, $ladder->forAge(29 * self::DAY)->index);
		$this->assertSame(1, $ladder->forAge(30 * self::DAY)->index, 'the threshold is inclusive');
		$this->assertSame(1, $ladder->forAge(179 * self::DAY)->index);
		$this->assertSame(2, $ladder->forAge(180 * self::DAY)->index);
		$this->assertSame(3, $ladder->forAge(1000 * self::DAY)->index, 'past the last threshold');
	}

	#[Test]
	#[TestDox('a ref stays in the nearest tier however old it is')]
	#[Group('strata/tier')]
	public function aRefIsPinnedNearest(): void
	{
		$policy = new TierPolicy($this->ladder());
		$ref = RefStore::PREFIX . '/heads/main';

		$this->assertSame(
			0,
			$policy->targetFor($ref, 1000 * self::DAY),
			'the tip is what a site reads first, so it never migrates',
		);
		$this->assertGreaterThan(
			0,
			$policy->targetFor('frames/aa/bb/cc', 1000 * self::DAY),
			'an ordinary object of the same age does migrate',
		);
	}

	#[Test]
	#[TestDox('a read tries where the placement says it is before the ladder order')]
	#[Group('strata/tier')]
	public function placementLeadsTheReadOrder(): void
	{
		$policy = new TierPolicy($this->ladder());
		$key = 'frames/aa/bb/cc';

		$this->assertSame(
			[0, 1, 2, 3],
			$policy->readOrder($key, null),
			'with no record the ladder order is the only guess there is',
		);
		$this->assertSame(
			2,
			$policy->readOrder($key, new Placement($key, [2]))[0],
			'a recorded tier is tried first, because it is where the object actually is',
		);
	}

	#endregion

	#region What The Placement Records

	#[Test]
	#[TestDox('a placement in two tiers is a replica and reports both')]
	#[Group('strata/tier')]
	public function aReplicatedPlacementReportsBothTiers(): void
	{
		$placement = new Placement('frames/aa', [0, 2], 4096);

		$this->assertSame([0, 2], $placement->tiers());
		$this->assertSame(0, $placement->nearest());
		$this->assertSame(2, $placement->coldest());
		$this->assertTrue($placement->isReplicated());
		$this->assertSame(2, $placement->copies());
		$this->assertTrue($placement->has(2));
		$this->assertFalse($placement->has(1));
		$this->assertFalse($placement->isEmpty());
	}

	#[Test]
	#[TestDox('a placement nothing holds is empty rather than pointing at tier zero')]
	#[Group('strata/tier')]
	public function anEmptyPlacementPointsNowhere(): void
	{
		$placement = new Placement('frames/aa', []);

		$this->assertTrue($placement->isEmpty());
		$this->assertNull($placement->nearest());
		$this->assertNull($placement->coldest());
		$this->assertSame(0, $placement->copies());
	}

	#[Test]
	#[TestDox('placing and displacing a key moves it without losing the byte count')]
	#[Group('strata/tier')]
	public function placingAndDisplacingMovesAKey(): void
	{
		$index = new MemoryPlacementIndex();

		$index->place('frames/aa', 0, 8192);

		$this->assertSame([0], (array) $index->get('frames/aa')?->tiers());
		$this->assertSame(8192, $index->get('frames/aa')?->bytes);

		$index->place('frames/aa', 2);

		$this->assertSame([0, 2], (array) $index->get('frames/aa')?->tiers());
		$this->assertSame(8192, $index->get('frames/aa')?->bytes, 'the size is not lost by a move');

		$index->displace('frames/aa', 0);

		$this->assertSame([2], (array) $index->get('frames/aa')?->tiers());
		$this->assertSame(
			[2 => ['objects' => 1, 'bytes' => 8192]],
			$index->byTier(),
			'one object, in the cold tier, still carrying its size',
		);
	}

	#[Test]
	#[TestDox('displacing the last tier leaves a record that nothing holds it')]
	#[Group('strata/tier')]
	public function displacingEverythingLeavesAnEmptyRecord(): void
	{
		$index = new MemoryPlacementIndex();
		$index->place('frames/aa', 1, 100);

		$placement = $index->displace('frames/aa', 1);

		$this->assertTrue($placement->isEmpty());
		$this->assertSame(
			[],
			$index->keysIn(1),
			'a key nothing holds is not listed as being in the tier it left',
		);
	}

	#[Test]
	#[TestDox('the index pages every key it holds and forgets the ones it is told to')]
	#[Group('strata/tier')]
	public function theIndexPagesAndForgets(): void
	{
		$index = new MemoryPlacementIndex();

		foreach (['a', 'b', 'c'] as $at => $key) {
			$index->place('frames/' . $key, $at % 2, 10);
		}

		$this->assertCount(3, $index->page(100));
		$this->assertCount(2, $index->page(2));
		$this->assertCount(1, $index->page(100, 2), 'an offset skips rather than repeats');
		$this->assertSame(1, $index->forget(['frames/a']));
		$this->assertCount(2, $index->page(100));
		$this->assertSame(2, $index->clear());
		$this->assertSame([], $index->page(100));
	}

	#[Test]
	#[TestDox('statistics report objects and bytes per tier rather than one total')]
	#[Group('strata/tier')]
	public function statisticsAreReportedPerTier(): void
	{
		$index = new MemoryPlacementIndex();
		$index->place('frames/a', 0, 1000);
		$index->place('frames/b', 2, 2000);
		$index->place('frames/b', 0);

		$statistics = $index->statistics();

		$this->assertSame(2, $statistics['objects']);
		$this->assertSame(3, $statistics['copies'], 'one object is in two tiers');
		$this->assertSame(3000, $statistics['bytes']);
		$this->assertSame(
			[0 => ['objects' => 2, 'bytes' => 3000], 2 => ['objects' => 1, 'bytes' => 2000]],
			$index->byTier(),
			'the near tier holds both, the cold tier holds the replica',
		);
	}

	#endregion

	#region Replicas

	#[Test]
	#[TestDox('a replica tier keeps the copy below, so a promotion is a copy and not a move')]
	#[Group('strata/tier')]
	public function aReplicaTierRetainsTheCopyBelow(): void
	{
		$ladder = new TierMap([
			$this->tier(0, 'now'),
			$this->tier(1, 'month-replica', 30 * self::DAY, true),
			$this->tier(2, 'cold', 180 * self::DAY),
		]);
		$policy = new TierPolicy($ladder);

		$this->assertTrue($policy->retainsBelow(1), 'the month tier is a safety copy');
		$this->assertFalse($policy->retainsBelow(2), 'the cold tier is a destination');
		$this->assertTrue($ladder->at(1)->retainBelow);
	}

	#[Test]
	#[TestDox('an object already in its target tier is not promoted again')]
	#[Group('strata/tier')]
	public function anObjectAtRestIsNotPromoted(): void
	{
		$policy = new TierPolicy($this->ladder());

		$this->assertNull(
			$policy->promotionFor('frames/aa', 1, 40 * self::DAY),
			'40 days belongs in tier 1, which is where it already is',
		);
		$this->assertSame(
			2,
			$policy->promotionFor('frames/aa', 1, 200 * self::DAY),
			'200 days belongs one tier colder',
		);
		$this->assertNull(
			$policy->promotionFor(RefStore::PREFIX . '/heads/main', 0, 900 * self::DAY),
			'a ref is pinned, so it is never promoted',
		);
	}

	#endregion
}
