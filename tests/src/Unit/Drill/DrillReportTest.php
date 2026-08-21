<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Drill;

use Drupal\strata\Drill\DrillReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Proves the verdict a drill reports across the whole matrix of outcomes.
 *
 * The case the class exists for is the one where nothing could be judged. A drill that skipped every
 * subject has zero differences and has proved nothing, so it reports `inconclusive`; reading that as
 * a pass is how a drill starts reassuring an operator about a backup it never checked.
 */
#[CoversClass(DrillReport::class)]
class DrillReportTest extends TestCase
{
	#region Verdict matrix

	/**
	 * @return array<string, array{DrillReport, string, bool}>
	 */
	public static function verdictProvider(): array
	{
		$target = str_repeat('a', 64);

		return [
			'refused before it ran' => [
				new DrillReport($target, 'replay', [], [], [], [], 0, 0, 0.0, 0, 'no history'),
				'refused',
				false,
			],
			'refused with subjects already matched' => [
				new DrillReport(
					$target,
					'replay',
					['entity/node/1' => 'ok'],
					[],
					[],
					[],
					1,
					1,
					0.0,
					0,
					'aborted',
				),
				'refused',
				false,
			],
			'nothing judged at all' => [new DrillReport($target), 'inconclusive', false],
			'every subject skipped' => [
				new DrillReport(
					$target,
					'replay',
					[],
					[],
					['entity/node/1' => 'changed since', 'entity/node/2' => 'changed since'],
					[],
					2,
					2,
				),
				'inconclusive',
				false,
			],
			'everything matched' => [
				new DrillReport(
					$target,
					'replay',
					['entity/node/1' => 'ok', 'entity/node/2' => 'ok'],
					[],
					[],
					[],
					2,
					2,
				),
				'pass',
				true,
			],
			'matched plus skipped' => [
				new DrillReport(
					$target,
					'replay',
					['entity/node/1' => 'ok'],
					[],
					['entity/node/2' => 'changed since'],
					[],
					2,
					2,
				),
				'pass',
				true,
			],
			'one subject differed' => [
				new DrillReport(
					$target,
					'replay',
					['entity/node/1' => 'ok'],
					['entity/node/2' => 'title differs'],
					[],
					[],
					2,
					2,
				),
				'fail',
				false,
			],
			'one subject unreadable' => [
				new DrillReport(
					$target,
					'replay',
					['entity/node/1' => 'ok'],
					[],
					[],
					['entity/node/2' => 'frame missing'],
					2,
					2,
				),
				'fail',
				false,
			],
			'differed and unreadable together' => [
				new DrillReport(
					$target,
					'replay',
					[],
					['entity/node/1' => 'title differs'],
					[],
					['entity/node/2' => 'frame missing'],
					2,
					2,
				),
				'fail',
				false,
			],
			'unreadable and skipped with nothing matched' => [
				new DrillReport(
					$target,
					'replay',
					[],
					[],
					['entity/node/1' => 'changed since'],
					['entity/node/2' => 'frame missing'],
					2,
					2,
				),
				'fail',
				false,
			],
		];
	}

	#[Test]
	#[TestDox('a drill that was $_dataName reports the matching verdict')]
	#[Group('strata/drill')]
	#[DataProvider('verdictProvider')]
	public function verdictCoversTheMatrix(DrillReport $report, string $verdict, bool $passed): void
	{
		$this->assertSame($verdict, $report->verdict());
		$this->assertSame($passed, $report->passed());
	}

	#[Test]
	#[TestDox('nothing judged is inconclusive rather than a pass, even with no differences')]
	#[Group('strata/drill')]
	public function nothingJudgedIsNeverAPass(): void
	{
		$report = new DrillReport(
			str_repeat('a', 64),
			'replay',
			[],
			[],
			['entity/node/1' => 'changed since the commit'],
			[],
			1,
			400,
		);

		$this->assertSame(0, $report->judged());
		$this->assertSame([], $report->differed);
		$this->assertSame([], $report->unreadable);
		$this->assertFalse($report->passed());
		$this->assertSame('inconclusive', $report->verdict());
	}

	#[Test]
	#[TestDox('wasRefused() is true only when a reason was recorded')]
	#[Group('strata/drill')]
	public function wasRefusedFollowsTheReason(): void
	{
		$target = str_repeat('a', 64);

		$this->assertFalse((new DrillReport($target))->wasRefused());
		$this->assertTrue(
			(new DrillReport(
				$target,
				'replay',
				[],
				[],
				[],
				[],
				0,
				0,
				0.0,
				0,
				'no subjects',
			))->wasRefused(),
		);
		$this->assertTrue(
			(new DrillReport($target, 'replay', [], [], [], [], 0, 0, 0.0, 0, ''))->wasRefused(),
			'an empty reason is still a refusal',
		);
	}

	#endregion

	#region Counts

	#[Test]
	#[TestDox('judged() counts matched, differed and unreadable but never skipped')]
	#[Group('strata/drill')]
	public function judgedExcludesSkipped(): void
	{
		$report = new DrillReport(
			str_repeat('a', 64),
			'replay',
			['a' => 'ok', 'b' => 'ok'],
			['c' => 'differs'],
			['d' => 'changed since', 'e' => 'changed since', 'f' => 'changed since'],
			['g' => 'frame missing'],
			7,
			100,
		);

		$this->assertSame(4, $report->judged());
		$this->assertCount(3, $report->skipped);
		$this->assertSame(7, $report->sampled);
		$this->assertSame(100, $report->population);
	}

	#[Test]
	#[TestDox('accuracy() is the matched share of what was judged')]
	#[Group('strata/drill')]
	public function accuracyIsOverJudged(): void
	{
		$target = str_repeat('a', 64);

		$this->assertSame(
			1.0,
			(new DrillReport($target, 'replay', ['a' => 'ok', 'b' => 'ok']))->accuracy(),
		);
		$this->assertSame(
			0.5,
			(new DrillReport($target, 'replay', ['a' => 'ok'], ['b' => 'differs']))->accuracy(),
		);
		$this->assertSame(
			0.0,
			(new DrillReport($target, 'replay', [], ['a' => 'differs']))->accuracy(),
		);
	}

	#[Test]
	#[TestDox('accuracy() is zero rather than undefined when nothing could be judged')]
	#[Group('strata/drill')]
	public function accuracyOfNothingJudged(): void
	{
		$report = new DrillReport(str_repeat('a', 64), 'replay', [], [], ['a' => 'changed since']);

		$this->assertSame(0.0, $report->accuracy());
	}

	#[Test]
	#[TestDox('skipped subjects do not dilute the accuracy the drill reports')]
	#[Group('strata/drill')]
	public function skippedDoesNotDiluteAccuracy(): void
	{
		$withoutSkips = new DrillReport(str_repeat('a', 64), 'replay', ['a' => 'ok']);
		$withSkips = new DrillReport(
			str_repeat('a', 64),
			'replay',
			['a' => 'ok'],
			[],
			['b' => 'changed since', 'c' => 'changed since'],
		);

		$this->assertSame($withoutSkips->accuracy(), $withSkips->accuracy());
		$this->assertSame(1.0, $withSkips->accuracy());
	}

	#endregion

	#region Reporting

	#[Test]
	#[TestDox('summary() names the verdict and every count')]
	#[Group('strata/drill')]
	public function summaryNamesTheCounts(): void
	{
		$report = new DrillReport(
			str_repeat('a', 64),
			'replay',
			['a' => 'ok', 'b' => 'ok'],
			['c' => 'differs'],
			['d' => 'changed since'],
			['e' => 'frame missing'],
			5,
			200,
			1.2345,
		);

		$this->assertSame(
			'fail: 2 matched, 1 differed, 1 unreadable, 1 skipped of 5 sampled in 1.23s',
			$report->summary(),
		);
	}

	#[Test]
	#[TestDox('summary() of a refused drill reports the reason instead of zeros')]
	#[Group('strata/drill')]
	public function summaryOfARefusedDrill(): void
	{
		$report = new DrillReport(
			str_repeat('a', 64),
			'replay',
			[],
			[],
			[],
			[],
			0,
			0,
			0.0,
			0,
			'the store has no history',
		);

		$this->assertSame('drill refused: the store has no history', $report->summary());
	}

	#[Test]
	#[TestDox('summary() of an inconclusive drill says so rather than reading as a pass')]
	#[Group('strata/drill')]
	public function summaryOfAnInconclusiveDrill(): void
	{
		$report = new DrillReport(
			str_repeat('a', 64),
			'replay',
			[],
			[],
			['a' => 'changed since'],
			[],
			1,
			10,
			0.5,
		);

		$this->assertStringStartsWith('inconclusive:', $report->summary());
	}

	#[Test]
	#[TestDox('jsonSerialize() names the failures and counts the rest')]
	#[Group('strata/drill')]
	public function jsonSerializeNamesTheFailures(): void
	{
		$target = str_repeat('a', 64);
		$report = new DrillReport(
			$target,
			'scratch',
			['a' => 'ok', 'b' => 'ok'],
			['c' => 'title differs'],
			['d' => 'changed since'],
			['e' => 'frame missing'],
			5,
			200,
			1.234_567,
			1_700_000_000,
		);

		$this->assertSame(
			[
				'target' => $target,
				'mode' => 'scratch',
				'verdict' => 'fail',
				'matched' => 2,
				'differed' => ['c' => 'title differs'],
				'skipped' => 1,
				'unreadable' => ['e' => 'frame missing'],
				'sampled' => 5,
				'population' => 200,
				'accuracy' => 0.5,
				'seconds' => 1.2346,
				'ran_at' => 1_700_000_000,
				'refused' => null,
			],
			$report->jsonSerialize(),
		);
	}

	#endregion
}
