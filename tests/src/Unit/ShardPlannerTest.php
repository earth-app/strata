<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit;

use Drupal\Tests\strata\ShardPlanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves the CI shard split is balanced, total, and identical in every job.
 *
 * Those three properties are what make sharding safe rather than merely faster. If a class is
 * dropped the suite silently stops covering it; if two jobs disagree about the assignment a class
 * runs twice and another never runs; and if the split is lopsided the matrix takes as long as its
 * slowest leg, which is the whole thing sharding exists to avoid.
 *
 * @see ShardPlanner
 */
#[CoversClass(ShardPlanner::class)]
class ShardPlannerTest extends TestCase
{
	/**
	 * A `phpunit --list-tests` transcript, with the noise the real command emits.
	 *
	 * @return list<string>
	 *   The output lines.
	 */
	private static function listing(): array
	{
		return [
			'PHPUnit 11.5.56 by Sebastian Bergmann and contributors.',
			'',
			'Available test:',
			' - Drupal\Tests\strata\Kernel\BranchTest::aBranchIsARef',
			' - Drupal\Tests\strata\Kernel\BranchTest::aBranchIsRefused',
			' - Drupal\Tests\strata\Kernel\BranchTest::theTrunkIsFixed',
			' - Drupal\Tests\strata\Kernel\DrillTest::aDrillRuns',
			' - Drupal\Tests\strata_s3\Kernel\S3Test::aPutRoundTrips',
			'a line that is not a test at all',
		];
	}

	#region Inventory

	#[Test]
	#[TestDox('the inventory counts tests per class and ignores every other line')]
	#[Group('strata/testing')]
	public function countsByClass(): void
	{
		$counts = ShardPlanner::countByClass(self::listing());

		$this->assertSame(
			[
				'Drupal\Tests\strata\Kernel\BranchTest' => 3,
				'Drupal\Tests\strata\Kernel\DrillTest' => 1,
				'Drupal\Tests\strata_s3\Kernel\S3Test' => 1,
			],
			$counts,
		);
	}

	#[Test]
	#[TestDox('a data provider row counts as its own test, so weights match wall clock')]
	#[Group('strata/testing')]
	public function providerRowsAreCountedIndividually(): void
	{
		$counts = ShardPlanner::countByClass([
			' - Drupal\Tests\strata\Unit\CodecTest::roundTrips with data set "zstd"',
			' - Drupal\Tests\strata\Unit\CodecTest::roundTrips with data set "gzip"',
			' - Drupal\Tests\strata\Unit\CodecTest::roundTrips with data set "brotli"',
		]);

		$this->assertSame(['Drupal\Tests\strata\Unit\CodecTest' => 3], $counts);
	}

	#endregion

	#region Packing

	#[Test]
	#[
		TestDox(
			'every class lands in exactly one shard, so nothing runs twice and nothing is dropped',
		),
	]
	#[Group('strata/testing')]
	public function packingIsATotalPartition(): void
	{
		$counts = [];

		for ($index = 0; $index < 40; $index++) {
			$counts['Class' . $index] = ($index % 7) + 1;
		}

		$shards = ShardPlanner::pack($counts, 6);
		$placed = array_merge(...$shards);

		$this->assertCount(6, $shards);
		$this->assertCount(count($counts), $placed);
		$this->assertSame(
			array_keys($counts),
			array_values(array_intersect(array_keys($counts), $placed)),
		);
		$this->assertSame(count($placed), count(array_unique($placed)));
	}

	#[Test]
	#[TestDox('the heaviest shard stays within one class of the lightest')]
	#[Group('strata/testing')]
	public function packingIsBalanced(): void
	{
		$counts = [];

		for ($index = 0; $index < 60; $index++) {
			$counts['Class' . $index] = ($index % 11) + 1;
		}

		$shards = ShardPlanner::pack($counts, 8);
		$weights = array_map(
			static fn(array $classes): int => array_sum(
				array_map(static fn(string $class): int => $counts[$class], $classes),
			),
			$shards,
		);

		// heaviest-first packing cannot do worse than the largest single class
		$this->assertLessThanOrEqual(max($counts), max($weights) - min($weights));
	}

	#[Test]
	#[TestDox('the same inventory packs identically every time, so no two jobs disagree')]
	#[Group('strata/testing')]
	public function packingIsDeterministic(): void
	{
		$counts = ['A' => 5, 'B' => 5, 'C' => 5, 'D' => 5, 'E' => 5];
		$shuffled = ['E' => 5, 'C' => 5, 'A' => 5, 'D' => 5, 'B' => 5];

		$this->assertSame(ShardPlanner::pack($counts, 3), ShardPlanner::pack($shuffled, 3));
	}

	#[Test]
	#[TestDox('more shards than classes leaves the extra ones empty rather than duplicating work')]
	#[Group('strata/testing')]
	public function moreShardsThanClasses(): void
	{
		$shards = ShardPlanner::pack(['A' => 1, 'B' => 1], 4);

		$this->assertCount(4, $shards);
		$this->assertSame(['A', 'B'], array_merge(...$shards));
	}

	#[Test]
	#[TestDox('a shard count below one is refused rather than dividing by nothing')]
	#[Group('strata/testing')]
	public function zeroShardsIsRefused(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('at least 1');

		ShardPlanner::pack(['A' => 1], 0);
	}

	#endregion

	#region Resolving Files

	#[Test]
	#[TestDox("this module's own test class resolves under tests/src")]
	#[Group('strata/testing')]
	public function ownTestResolves(): void
	{
		$planner = new ShardPlanner(dirname(__DIR__, 3));

		$this->assertSame('tests/src/Unit/ShardPlannerTest.php', $planner->fileFor(self::class));
	}

	#[Test]
	#[TestDox("a submodule's test class resolves under that module's own tests directory")]
	#[Group('strata/testing')]
	public function submoduleTestResolves(): void
	{
		$planner = new ShardPlanner(dirname(__DIR__, 3));

		$this->assertSame(
			'modules/strata_s3/tests/src/Unit/CredentialsTest.php',
			$planner->fileFor('Drupal\Tests\strata_s3\Unit\CredentialsTest'),
		);
	}

	#[Test]
	#[TestDox('a class outside the test namespace is refused rather than guessed at')]
	#[Group('strata/testing')]
	public function foreignNamespaceIsRefused(): void
	{
		$planner = new ShardPlanner(dirname(__DIR__, 3));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('unexpected test namespace');

		$planner->fileFor('Vendor\Package\SomeTest');
	}

	#[Test]
	#[TestDox('a class with no file behind it is refused, so a stale plan fails loudly')]
	#[Group('strata/testing')]
	public function missingFileIsRefused(): void
	{
		$planner = new ShardPlanner(dirname(__DIR__, 3));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('cannot resolve');

		$planner->fileFor('Drupal\Tests\strata\Unit\NothingHereTest');
	}

	#endregion

	#region Writing

	#[Test]
	#[TestDox('the generated config keeps the environment and names only the shard files')]
	#[Group('strata/testing')]
	public function writesAConfigThatStillResolves(): void
	{
		$root = dirname(__DIR__, 3);
		$out = tempnam(sys_get_temp_dir(), 'strata-shard-') . '.xml';
		$planner = new ShardPlanner($root);

		$planner->writeConfig($out, 'Kernel', ['tests/src/Kernel/BranchTest.php']);
		$written = (string) file_get_contents($out);
		unlink($out);

		$this->assertStringContainsString('<file>tests/src/Kernel/BranchTest.php</file>', $written);
		$this->assertStringContainsString('bootstrap="tests/bootstrap.php"', $written);
		$this->assertStringContainsString('SIMPLETEST_DB', $written);
		$this->assertStringNotContainsString('<directory>tests/src/Unit</directory>', $written);
	}

	#endregion
}
