<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Explore;

use Drupal\strata\Explore\ExplorerReport;
use Drupal\strata\Explore\RemovalCost;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Proves what the explorer reports when the reachability walk finished, and when it did not.
 *
 * An incomplete walk cannot prove a frame is unreferenced, so both classes report the collectable
 * figure as unknown rather than as zero: `collectableCount()` returns NULL and the waste share drops
 * to nothing. A caller that read a zero there would delete frames a partial walk simply never saw a
 * reference to.
 */
#[CoversClass(RemovalCost::class)]
#[CoversClass(ExplorerReport::class)]
class RemovalCostTest extends TestCase
{
	/**
	 * A commit id every case reuses.
	 */
	private const COMMIT = 'c9f4ad1b5e2c7d8a3f6b0e4d9c2a7b5f1e8d6c4a9b3f7e2d5c8a1b6f4e9d3c7a';

	#region Frames

	#[Test]
	#[TestDox('frames() is everything the commit references, freed and retained together')]
	#[Group('strata/explore')]
	public function framesCountsBothLists(): void
	{
		$cost = new RemovalCost(
			self::COMMIT,
			['f1', 'f2'],
			['f3' => 'held by a later delta', 'f4' => 'named as a dictionary'],
			4_096,
		);

		$this->assertSame(4, $cost->frames());
		$this->assertSame(0, (new RemovalCost(self::COMMIT))->frames());
	}

	/**
	 * @return array<string, array{list<string>, array<string, string>, float}>
	 */
	public static function freedShareProvider(): array
	{
		return [
			'nothing referenced at all' => [[], [], 0.0],
			'everything freed' => [['f1', 'f2'], [], 1.0],
			'nothing freed' => [[], ['f1' => 'held'], 0.0],
			'half freed' => [['f1'], ['f2' => 'held'], 0.5],
			'one in four freed' => [['f1'], ['f2' => 'h', 'f3' => 'h', 'f4' => 'h'], 0.25],
		];
	}

	#[Test]
	#[TestDox('freedShare() with $_dataName')]
	#[Group('strata/explore')]
	#[DataProvider('freedShareProvider')]
	public function freedShareIsOverEverythingReferenced(
		array $freed,
		array $retained,
		float $expected,
	): void {
		$cost = new RemovalCost(self::COMMIT, $freed, $retained);

		$this->assertSame($expected, $cost->freedShare());
	}

	#endregion

	#region Removal summary

	#[Test]
	#[TestDox('summary() names what would go and what would stay')]
	#[Group('strata/explore')]
	public function summaryNamesBothSides(): void
	{
		$cost = new RemovalCost(
			self::COMMIT,
			['f1', 'f2'],
			['f3' => 'held by a later delta'],
			8_192,
		);

		$this->assertSame(
			'2 of 3 frames would be freed (8192 bytes); 1 are still needed elsewhere',
			$cost->summary(),
		);
	}

	#[Test]
	#[TestDox('summary() of an incomplete walk refuses to price anything')]
	#[Group('strata/explore')]
	public function summaryOfAnIncompleteWalk(): void
	{
		$cost = new RemovalCost(self::COMMIT, ['f1'], ['f2' => 'held'], 1_024, false);

		$this->assertSame(
			'the reachability walk did not finish, so nothing can be priced',
			$cost->summary(),
		);
		$this->assertStringNotContainsString('freed', $cost->summary());
	}

	#[Test]
	#[TestDox('a commit that references nothing still summarises without dividing by zero')]
	#[Group('strata/explore')]
	public function summaryOfAnEmptyCommit(): void
	{
		$cost = new RemovalCost(self::COMMIT);

		$this->assertSame(
			'0 of 0 frames would be freed (0 bytes); 0 are still needed elsewhere',
			$cost->summary(),
		);
	}

	#[Test]
	#[TestDox('jsonSerialize() carries both lists, the share and whether the walk finished')]
	#[Group('strata/explore')]
	public function removalCostJsonSerialize(): void
	{
		$cost = new RemovalCost(
			self::COMMIT,
			['f1'],
			['f2' => 'held by a later delta', 'f3' => 'named as a dictionary'],
			2_048,
		);

		$this->assertSame(
			[
				'commit' => self::COMMIT,
				'freed' => ['f1'],
				'retained' => ['f2' => 'held by a later delta', 'f3' => 'named as a dictionary'],
				'bytes' => 2_048,
				'frames' => 3,
				'freed_share' => 0.3333,
				'complete' => true,
			],
			$cost->jsonSerialize(),
		);
	}

	#[Test]
	#[TestDox('an incomplete cost still serializes its lists, marked incomplete')]
	#[Group('strata/explore')]
	public function incompleteCostJsonSerialize(): void
	{
		$cost = new RemovalCost(self::COMMIT, ['f1'], [], 512, false);
		$serialized = $cost->jsonSerialize();

		$this->assertFalse($serialized['complete']);
		$this->assertSame(['f1'], $serialized['freed']);
	}

	#endregion

	#region Store totals

	#[Test]
	#[TestDox('ratio() is raw over stored, and one when nothing is stored')]
	#[Group('strata/explore')]
	public function ratioIsRawOverStored(): void
	{
		$this->assertSame(4.0, (new ExplorerReport(1, 4_000, 1_000))->ratio());
		$this->assertSame(1.0, (new ExplorerReport(0, 4_000, 0))->ratio());
		$this->assertSame(1.0, (new ExplorerReport())->ratio());
	}

	#[Test]
	#[TestDox('collectableCount() is the count when the walk finished')]
	#[Group('strata/explore')]
	public function collectableCountWhenComplete(): void
	{
		$report = new ExplorerReport(10, 4_000, 1_000, 3, 1, ['commit' => 8], ['f1', 'f2'], 256);

		$this->assertSame(2, $report->collectableCount());
		$this->assertSame(0, (new ExplorerReport())->collectableCount());
	}

	#[Test]
	#[TestDox('collectableCount() is null, not zero, when the walk did not finish')]
	#[Group('strata/explore')]
	public function collectableCountIsNullWhenIncomplete(): void
	{
		$report = new ExplorerReport(
			10,
			4_000,
			1_000,
			3,
			1,
			['commit' => 8],
			['f1', 'f2'],
			256,
			false,
		);

		$this->assertNull($report->collectableCount());
		$this->assertNotSame(0, $report->collectableCount());
		$this->assertCount(
			2,
			$report->collectable,
			'the list is kept, only the claim is withdrawn',
		);
		$this->assertNull($report->jsonSerialize()['collectable']);
	}

	#[Test]
	#[TestDox('wasteShare() is the collectable share of what is stored')]
	#[Group('strata/explore')]
	public function wasteShareIsOverStoredBytes(): void
	{
		$report = new ExplorerReport(10, 4_000, 1_000, 3, 1, [], ['f1'], 250);

		$this->assertSame(0.25, $report->wasteShare());
	}

	#[Test]
	#[TestDox('wasteShare() is zero when the walk did not finish or nothing is stored')]
	#[Group('strata/explore')]
	public function wasteShareIsZeroWhenUnknown(): void
	{
		$incomplete = new ExplorerReport(10, 4_000, 1_000, 3, 1, [], ['f1'], 250, false);
		$nothingStored = new ExplorerReport(0, 0, 0, 0, 0, [], ['f1'], 250);

		$this->assertSame(0.0, $incomplete->wasteShare());
		$this->assertSame(0.0, $nothingStored->wasteShare());
	}

	#endregion

	#region Store summary

	#[Test]
	#[TestDox('summary() names the collectable frames when the walk finished')]
	#[Group('strata/explore')]
	public function summaryNamesWhatIsCollectable(): void
	{
		$report = new ExplorerReport(10, 8_192, 2_048, 3, 1, ['commit' => 9], ['f1'], 512);

		$this->assertSame(
			'10 frames, 3 commits, 1 dictionaries, 2.0 KiB stored at 4.00x; ' .
				'1 frames (512 B, 25.0%) are collectable',
			$report->summary(),
		);
	}

	#[Test]
	#[TestDox('summary() says nothing is collectable rather than reporting a zero')]
	#[Group('strata/explore')]
	public function summaryOfNothingCollectable(): void
	{
		$report = new ExplorerReport(10, 8_192, 2_048, 3, 1, ['commit' => 10]);

		$this->assertSame(
			'10 frames, 3 commits, 1 dictionaries, 2.0 KiB stored at 4.00x; nothing is collectable',
			$report->summary(),
		);
	}

	#[Test]
	#[TestDox('summary() of an incomplete walk says nothing is safe to collect')]
	#[Group('strata/explore')]
	public function summaryOfAnIncompleteWalkRefuses(): void
	{
		$report = new ExplorerReport(10, 8_192, 2_048, 3, 1, [], ['f1', 'f2'], 512, false);

		$this->assertSame(
			'10 frames, 3 commits, 1 dictionaries, 2.0 KiB stored at 4.00x; ' .
				'reachability incomplete, so nothing is safe to collect',
			$report->summary(),
		);
		$this->assertStringNotContainsString('collectable', $report->summary());
	}

	#[Test]
	#[TestDox('an empty store summarises without dividing by zero')]
	#[Group('strata/explore')]
	public function summaryOfAnEmptyStore(): void
	{
		$this->assertSame(
			'0 frames, 0 commits, 0 dictionaries, 0 B stored at 1.00x; nothing is collectable',
			(new ExplorerReport())->summary(),
		);
	}

	#[Test]
	#[TestDox('jsonSerialize() carries the reachability classes and the per-realm split')]
	#[Group('strata/explore')]
	public function explorerReportJsonSerialize(): void
	{
		$report = new ExplorerReport(
			10,
			8_192,
			2_048,
			3,
			1,
			['commit' => 8, 'dictionary' => 1, 'delta' => 1],
			['f1'],
			512,
			true,
			['entity' => 1_024, 'config' => 1_024],
			0.123_456,
		);

		$this->assertSame(
			[
				'frames' => 10,
				'raw_bytes' => 8_192,
				'stored_bytes' => 2_048,
				'commits' => 3,
				'dictionaries' => 1,
				'ratio' => 4.0,
				'reachable' => ['commit' => 8, 'dictionary' => 1, 'delta' => 1],
				'collectable' => 1,
				'collectable_bytes' => 512,
				'waste_share' => 0.25,
				'complete' => true,
				'by_realm' => ['entity' => 1_024, 'config' => 1_024],
				'seconds' => 0.1235,
			],
			$report->jsonSerialize(),
		);
	}

	#endregion
}
