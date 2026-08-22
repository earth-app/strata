<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Compaction;

use Drupal\strata\Compaction\LevelPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(LevelPolicy::class)]
class LevelPolicyTest extends TestCase
{
	#region Construction

	#[Test]
	#[TestDox('the shipped ladder runs from 15 second windows to monthly, kept forever at the top')]
	#[Group('strata/compaction')]
	public function shippedLadder(): void
	{
		$policy = new LevelPolicy();

		$this->assertSame(5, $policy->depth());
		$this->assertSame(15, $policy->window(0));
		$this->assertSame(3600, $policy->retention(0));
		$this->assertSame(2592000, $policy->window(4));
		$this->assertTrue($policy->isPermanent(4));
		$this->assertFalse($policy->isPermanent(0));
	}

	#[Test]
	#[TestDox('an empty ladder is refused')]
	#[Group('strata/compaction')]
	public function refusesEmptyLadder(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('at least one level');

		new LevelPolicy([]);
	}

	/**
	 * @return array<string, array{list<array{window: int, keep: int}>, string}>
	 */
	public static function badLadderProvider(): array
	{
		return [
			'zero window' => [[['window' => 0, 'keep' => 60]], 'window of 0 seconds'],
			'negative window' => [[['window' => -5, 'keep' => 60]], 'window of -5 seconds'],
			'negative retention' => [[['window' => 15, 'keep' => -1]], 'keeps history for -1'],
			'windows not increasing' => [
				[['window' => 60, 'keep' => 60], ['window' => 60, 'keep' => 600]],
				'not wider than level 0',
			],
			'windows decreasing' => [
				[['window' => 3600, 'keep' => 60], ['window' => 60, 'keep' => 600]],
				'not wider than level 0',
			],
		];
	}

	#[Test]
	#[TestDox('a ladder with $_dataName is refused')]
	#[Group('strata/compaction')]
	#[DataProvider('badLadderProvider')]
	public function refusesBadLadder(array $levels, string $message): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		new LevelPolicy($levels);
	}

	#[Test]
	#[TestDox('settings with no levels fall back to the shipped ladder')]
	#[Group('strata/compaction')]
	public function emptySettingsFallBack(): void
	{
		$this->assertSame((new LevelPolicy())->levels(), LevelPolicy::fromSettings([])->levels());
	}

	#[Test]
	#[TestDox('settings are read as integers even when they arrive as strings')]
	#[Group('strata/compaction')]
	public function settingsAreCoerced(): void
	{
		$policy = LevelPolicy::fromSettings([
			['window' => '30', 'keep' => '900'],
			['window' => '600', 'keep' => '0'],
		]);

		$this->assertSame(30, $policy->window(0));
		$this->assertSame(900, $policy->retention(0));
		$this->assertTrue($policy->isPermanent(1));
	}

	#[Test]
	#[TestDox('a level off the end of the ladder is named rather than defaulted')]
	#[Group('strata/compaction')]
	public function refusesUnknownLevel(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Level 9 is not on a ladder of 5 levels');

		(new LevelPolicy())->window(9);
	}

	#endregion

	#region Promotion

	#[Test]
	#[TestDox('every level promotes into the one above it, and the top promotes into nothing')]
	#[Group('strata/compaction')]
	public function promotion(): void
	{
		$policy = new LevelPolicy();

		$this->assertSame(1, $policy->promotes(0));
		$this->assertSame(4, $policy->promotes(3));
		$this->assertNull($policy->promotes(4), 'the coarsest level has nowhere to go');
	}

	#[Test]
	#[TestDox('windows are anchored on the epoch, so two hosts agree on the boundaries')]
	#[Group('strata/compaction')]
	public function windowsAreEpochAnchored(): void
	{
		$policy = new LevelPolicy();

		$this->assertSame(1_700_000_010, $policy->windowStart(1_700_000_019, 0));
		$this->assertSame(1_700_000_010, $policy->windowStart(1_700_000_010, 0));
		$this->assertSame(1_699_999_980, $policy->windowStart(1_700_000_019, 1));
		$this->assertSame(1_699_999_200, $policy->windowStart(1_700_000_019, 2));
	}

	#endregion

	#region Timing

	#[Test]
	#[TestDox('a window is due for rollup once its retention has elapsed')]
	#[Group('strata/compaction')]
	public function rollupTiming(): void
	{
		$policy = new LevelPolicy();
		$window = 1_700_000_000;

		$this->assertFalse($policy->isDueForRollup($window, 0, $window + 3599));
		$this->assertTrue($policy->isDueForRollup($window, 0, $window + 3600));
		$this->assertTrue($policy->isDueForRollup($window, 0, $window + 100000));
	}

	#[Test]
	#[TestDox('the coarsest level never rolls up, having nowhere to roll up into')]
	#[Group('strata/compaction')]
	public function coarsestLevelNeverRollsUp(): void
	{
		$policy = new LevelPolicy();

		$this->assertFalse($policy->isDueForRollup(1_700_000_000, 4, 2_000_000_000));
	}

	#[Test]
	#[TestDox('a level kept forever never rolls up even when a coarser level exists')]
	#[Group('strata/compaction')]
	public function permanentLevelNeverRollsUp(): void
	{
		$policy = new LevelPolicy([
			['window' => 60, 'keep' => 0],
			['window' => 3600, 'keep' => 86400],
		]);

		$this->assertFalse($policy->isDueForRollup(1_700_000_000, 0, 2_000_000_000));
	}

	#[Test]
	#[TestDox('a cutoff is the retention behind now, and nothing for a permanent level')]
	#[Group('strata/compaction')]
	public function cutoffs(): void
	{
		$policy = new LevelPolicy();

		$this->assertSame(1_700_000_000 - 3600, $policy->cutoff(0, 1_700_000_000));
		$this->assertSame(1_700_000_000 - 7776000, $policy->cutoff(3, 1_700_000_000));
		$this->assertNull($policy->cutoff(4, 1_700_000_000));
	}

	#endregion

	#region Ladders At Both Extremes

	#[Test]
	#[TestDox('a ladder of one level never rolls up, however old the window is')]
	#[Group('strata/compaction')]
	public function singleLevelLadderNeverRollsUp(): void
	{
		$policy = new LevelPolicy([['window' => 60, 'keep' => 900]]);

		$this->assertSame(1, $policy->depth());
		$this->assertNull($policy->promotes(0));
		$this->assertFalse($policy->isDueForRollup(0, 0, PHP_INT_MAX));

		// the retention still applies, so a prune has a cutoff even with nowhere to promote into
		$this->assertSame(1_700_000_000 - 900, $policy->cutoff(0, 1_700_000_000));
	}

	#[Test]
	#[TestDox('a twenty level ladder promotes every level but its last')]
	#[Group('strata/compaction')]
	public function twentyLevelLadderPromotesAllButTheTop(): void
	{
		$levels = [];

		for ($depth = 0; $depth < 20; $depth++) {
			$levels[] = ['window' => 15 * 2 ** $depth, 'keep' => 3600 * ($depth + 1)];
		}

		$policy = new LevelPolicy($levels);

		$this->assertSame(20, $policy->depth());
		$this->assertSame(15 * 2 ** 19, $policy->window(19));

		for ($depth = 0; $depth < 19; $depth++) {
			$this->assertSame($depth + 1, $policy->promotes($depth));
		}

		$this->assertNull($policy->promotes(19));
	}

	#[Test]
	#[TestDox('a window wider than the retention below it is due the moment the window closes')]
	#[Group('strata/compaction')]
	public function windowWiderThanItsRetentionIsDueAtOnce(): void
	{
		$policy = new LevelPolicy([
			['window' => 86_400, 'keep' => 60],
			['window' => 604_800, 'keep' => 0],
		]);

		// the window is a day and it is kept a minute, so it is due before the day is over
		$this->assertTrue($policy->isDueForRollup(1_700_000_000, 0, 1_700_000_060));
		$this->assertFalse($policy->isDueForRollup(1_700_000_000, 0, 1_700_000_059));
	}

	#[Test]
	#[TestDox('the first window starts at the epoch and the largest second does not overflow')]
	#[Group('strata/compaction')]
	public function windowBoundsAtBothEnds(): void
	{
		$policy = new LevelPolicy();

		$this->assertSame(0, $policy->windowStart(0, 0));
		$this->assertSame(0, $policy->windowStart(14, 0));
		$this->assertSame(15, $policy->windowStart(15, 0));

		$top = $policy->windowStart(PHP_INT_MAX, 0);

		$this->assertSame(intdiv(PHP_INT_MAX, 15) * 15, $top);
		$this->assertLessThanOrEqual(PHP_INT_MAX, $top);
	}

	#endregion
}
