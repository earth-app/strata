<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Timeline;

use Drupal\strata\Metrics\MetricSeries;
use Drupal\strata\Timeline\TimelineBucket;
use Drupal\strata\Timeline\TimelineWindow;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Proves the arithmetic a timeline is drawn from, before any database is involved.
 *
 * The resolution ladder is pinned rung by rung, so a ten-second window and a ten-year one both stay
 * under the bucket cap. The alignment property the window docblock claims is measured rather than
 * described: two windows offset by half a bucket produce the same boundary values, so panning does
 * not move the bars a reader is looking at.
 */
#[CoversClass(TimelineWindow::class)]
#[CoversClass(TimelineBucket::class)]
#[CoversClass(MetricSeries::class)]
class TimelineTest extends TestCase
{
	/**
	 * A unix microsecond that is exactly on a one-minute boundary.
	 */
	private const ALIGNED = 1_700_000_040_000_000;

	/**
	 * Microseconds in a second, spelled out so the arithmetic in a case is readable.
	 */
	private const SECOND = TimelineWindow::MICROS_PER_SECOND;

	#region Construction

	#[Test]
	#[TestDox('a window that ends before it starts is refused')]
	#[Group('strata/timeline')]
	public function refusesABackwardsWindow(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot end before it starts');

		new TimelineWindow(self::ALIGNED, self::ALIGNED - 1, 60);
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function badResolutionProvider(): array
	{
		return ['zero' => [0], 'negative' => [-1], 'far negative' => [-86_400]];
	}

	#[Test]
	#[TestDox('a resolution of $_dataName is refused')]
	#[Group('strata/timeline')]
	#[DataProvider('badResolutionProvider')]
	public function refusesASubSecondResolution(int $resolution): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('at least one second wide');

		new TimelineWindow(self::ALIGNED, self::ALIGNED + self::SECOND, $resolution);
	}

	#[Test]
	#[TestDox('a window of zero width is allowed and still holds one bucket')]
	#[Group('strata/timeline')]
	public function zeroWidthWindowHasOneBucket(): void
	{
		$window = new TimelineWindow(self::ALIGNED, self::ALIGNED, 60);

		$this->assertSame(0.0, $window->seconds());
		$this->assertSame(1, $window->bucketCount());
		$this->assertSame([self::ALIGNED], $window->boundaries());
	}

	#endregion

	#region Resolution ladder

	/**
	 * @return array<string, array{int, int}>
	 */
	public static function spanningProvider(): array
	{
		return [
			'nothing at all' => [0, 1],
			'ten seconds' => [10, 1],
			'exactly the bucket cap in seconds' => [TimelineWindow::MAX_BUCKETS, 1],
			'one second past the cap' => [TimelineWindow::MAX_BUCKETS + 1, 5],
			'an hour' => [3_600, 15],
			'six hours' => [21_600, 60],
			'a day' => [86_400, 300],
			'a week' => [604_800, 3_600],
			'thirty days' => [2_592_000, 21_600],
			'a year' => [31_536_000, 86_400],
			'ten years' => [315_360_000, 604_800],
			'a century' => [3_153_600_000, 604_800],
		];
	}

	#[Test]
	#[TestDox('a span of $_dataName is drawn at the finest rung that fits')]
	#[Group('strata/timeline')]
	#[DataProvider('spanningProvider')]
	public function spanningPicksTheFinestRung(int $seconds, int $expected): void
	{
		$window = TimelineWindow::spanning(self::ALIGNED, self::ALIGNED + $seconds * self::SECOND);

		$this->assertSame($expected, $window->resolution);
		$this->assertContains($expected, TimelineWindow::LADDER);
	}

	#[Test]
	#[TestDox('a tighter bucket cap picks a coarser rung')]
	#[Group('strata/timeline')]
	public function aTighterCapPicksACoarserRung(): void
	{
		$to = self::ALIGNED + 3_600 * self::SECOND;

		$this->assertSame(15, TimelineWindow::spanning(self::ALIGNED, $to)->resolution);
		$this->assertSame(60, TimelineWindow::spanning(self::ALIGNED, $to, 60)->resolution);
		$this->assertSame(300, TimelineWindow::spanning(self::ALIGNED, $to, 12)->resolution);
		$this->assertSame(3_600, TimelineWindow::spanning(self::ALIGNED, $to, 1)->resolution);
	}

	#[Test]
	#[TestDox('a bucket cap of zero is read as one rather than dividing by nothing')]
	#[Group('strata/timeline')]
	public function aZeroCapIsReadAsOne(): void
	{
		$to = self::ALIGNED + 3_600 * self::SECOND;

		$this->assertSame(3_600, TimelineWindow::spanning(self::ALIGNED, $to, 0)->resolution);
		$this->assertSame(3_600, TimelineWindow::spanning(self::ALIGNED, $to, -5)->resolution);
	}

	#[Test]
	#[TestDox('a spanning window stays under the bucket cap it was given')]
	#[Group('strata/timeline')]
	public function spanningStaysUnderTheCap(): void
	{
		foreach ([10, 480, 3_600, 86_400, 604_800, 31_536_000] as $seconds) {
			$window = TimelineWindow::spanning(
				self::ALIGNED,
				self::ALIGNED + $seconds * self::SECOND,
			);

			$this->assertLessThanOrEqual(
				TimelineWindow::MAX_BUCKETS + 1,
				$window->bucketCount(),
				(string) $seconds,
			);
		}
	}

	#[Test]
	#[TestDox('lastSeconds() ends at the moment it was given and looks back from there')]
	#[Group('strata/timeline')]
	public function lastSecondsEndsAtNow(): void
	{
		$window = TimelineWindow::lastSeconds(3_600, 1_700_000_000);

		$this->assertSame(1_700_000_000 * self::SECOND, $window->toMicrotime);
		$this->assertSame((1_700_000_000 - 3_600) * self::SECOND, $window->fromMicrotime);
		$this->assertSame(15, $window->resolution);
		$this->assertSame(3_600.0, $window->seconds());
	}

	#[Test]
	#[TestDox('lastSeconds() of nothing is still a one-second window')]
	#[Group('strata/timeline')]
	public function lastSecondsOfNothing(): void
	{
		$window = TimelineWindow::lastSeconds(0, 1_700_000_000);

		$this->assertSame(1.0, $window->seconds());
		$this->assertSame(1, $window->resolution);
	}

	#endregion

	#region Zooming and panning

	#[Test]
	#[TestDox('finer() and coarser() walk the ladder one rung at a time')]
	#[Group('strata/timeline')]
	public function zoomWalksTheLadder(): void
	{
		$window = new TimelineWindow(self::ALIGNED, self::ALIGNED + 3_600 * self::SECOND, 60);

		$this->assertSame(15, $window->finer()->resolution);
		$this->assertSame(300, $window->coarser()->resolution);
		$this->assertSame(60, $window->finer()->coarser()->resolution);
		$this->assertSame(
			$window->fromMicrotime,
			$window->finer()->fromMicrotime,
			'zooming keeps the span',
		);
		$this->assertSame($window->toMicrotime, $window->coarser()->toMicrotime);
	}

	#[Test]
	#[TestDox('zooming saturates at both ends of the ladder')]
	#[Group('strata/timeline')]
	public function zoomSaturates(): void
	{
		$to = self::ALIGNED + 3_600 * self::SECOND;
		$finest = new TimelineWindow(self::ALIGNED, $to, TimelineWindow::LADDER[0]);
		$coarsest = new TimelineWindow(
			self::ALIGNED,
			$to,
			TimelineWindow::LADDER[count(TimelineWindow::LADDER) - 1],
		);

		$this->assertSame($finest->resolution, $finest->finer()->resolution);
		$this->assertSame($coarsest->resolution, $coarsest->coarser()->resolution);
		$this->assertSame(5, $finest->coarser()->resolution);
		$this->assertSame(86_400, $coarsest->finer()->resolution);
	}

	#[Test]
	#[TestDox('a resolution off the ladder zooms to itself rather than to a guess')]
	#[Group('strata/timeline')]
	public function offLadderResolutionDoesNotZoom(): void
	{
		$window = new TimelineWindow(self::ALIGNED, self::ALIGNED + 3_600 * self::SECOND, 7);

		$this->assertSame(7, $window->finer()->resolution);
		$this->assertSame(7, $window->coarser()->resolution);
	}

	#[Test]
	#[TestDox('pan() keeps the width and the resolution and only moves the span')]
	#[Group('strata/timeline')]
	public function panKeepsWidthAndResolution(): void
	{
		$window = new TimelineWindow(self::ALIGNED, self::ALIGNED + 600 * self::SECOND, 60);
		$forward = $window->pan(0.5);
		$back = $window->pan(-1.0);

		$this->assertSame($window->seconds(), $forward->seconds());
		$this->assertSame($window->seconds(), $back->seconds());
		$this->assertSame(60, $forward->resolution);
		$this->assertSame(60, $back->resolution);
		$this->assertSame(self::ALIGNED + 300 * self::SECOND, $forward->fromMicrotime);
		$this->assertSame(self::ALIGNED - 600 * self::SECOND, $back->fromMicrotime);
		$this->assertSame(self::ALIGNED, $back->toMicrotime);
		$this->assertSame($window->fromMicrotime, $window->pan(0.0)->fromMicrotime);
	}

	#endregion

	#region Bucket boundaries

	#[Test]
	#[TestDox('boundaries are aligned to the resolution, not to where the window starts')]
	#[Group('strata/timeline')]
	public function boundariesAreAlignedToTheResolution(): void
	{
		$width = 60 * self::SECOND;
		$aligned = new TimelineWindow(self::ALIGNED, self::ALIGNED + 300 * self::SECOND, 60);
		$offset = new TimelineWindow(
			self::ALIGNED + 30 * self::SECOND,
			self::ALIGNED + 390 * self::SECOND,
			60,
		);

		foreach ($offset->boundaries() as $boundary) {
			$this->assertSame(0, $boundary % $width, 'every boundary sits on the resolution');
		}

		$this->assertNotSame($offset->fromMicrotime, $offset->boundaries()[0]);
		$this->assertSame(
			$aligned->boundaries(),
			array_values(array_intersect($offset->boundaries(), $aligned->boundaries())),
			'panning by half a bucket leaves the shared bars where they were',
		);
	}

	#[Test]
	#[TestDox('boundaries are contiguous, one resolution apart, oldest first')]
	#[Group('strata/timeline')]
	public function boundariesAreContiguous(): void
	{
		$window = new TimelineWindow(self::ALIGNED, self::ALIGNED + 300 * self::SECOND, 60);
		$boundaries = $window->boundaries();

		$this->assertCount(6, $boundaries);
		$this->assertSame(6, $window->bucketCount());
		$this->assertSame(self::ALIGNED, $boundaries[0]);

		for ($i = 1; $i < count($boundaries); $i++) {
			$this->assertSame(60 * self::SECOND, $boundaries[$i] - $boundaries[$i - 1]);
		}
	}

	#[Test]
	#[TestDox('bucketFor() names a boundary the window actually holds')]
	#[Group('strata/timeline')]
	public function bucketForAgreesWithBoundaries(): void
	{
		$window = new TimelineWindow(
			self::ALIGNED + 30 * self::SECOND,
			self::ALIGNED + 300 * self::SECOND,
			60,
		);
		$boundaries = $window->boundaries();

		foreach ([30, 59, 60, 61, 119, 200, 299] as $offset) {
			$this->assertContains(
				$window->bucketFor(self::ALIGNED + $offset * self::SECOND),
				$boundaries,
				(string) $offset,
			);
		}

		$this->assertSame(self::ALIGNED, $window->bucketFor(self::ALIGNED + 59 * self::SECOND));
		$this->assertSame(
			self::ALIGNED + 60 * self::SECOND,
			$window->bucketFor(self::ALIGNED + 60 * self::SECOND),
		);
	}

	#[Test]
	#[TestDox('a moment inside a bucket rounds down to that bucket, not to the nearest one')]
	#[Group('strata/timeline')]
	public function bucketForRoundsDown(): void
	{
		$window = new TimelineWindow(self::ALIGNED, self::ALIGNED + 300 * self::SECOND, 60);

		$this->assertSame(
			self::ALIGNED,
			$window->bucketFor(self::ALIGNED + 59 * self::SECOND + 999_999),
		);
	}

	#[Test]
	#[TestDox('the boundary list is bounded even when a window asks for more than the cap')]
	#[Group('strata/timeline')]
	public function boundariesAreBounded(): void
	{
		$window = new TimelineWindow(self::ALIGNED, self::ALIGNED + 86_400 * self::SECOND, 1);

		$this->assertCount(TimelineWindow::MAX_BUCKETS * 2, $window->boundaries());
	}

	/**
	 * @return array<string, array{int, string}>
	 */
	public static function resolutionLabelProvider(): array
	{
		return [
			'one second' => [1, '1 second'],
			'several seconds' => [15, '15 seconds'],
			'minutes' => [300, '5 minutes'],
			'hours' => [21_600, '6 hours'],
			'days' => [86_400, '1 days'],
			'weeks' => [604_800, '1 weeks'],
		];
	}

	#[Test]
	#[TestDox('resolutionLabel() names a rung measured in $_dataName')]
	#[Group('strata/timeline')]
	#[DataProvider('resolutionLabelProvider')]
	public function resolutionLabelNamesTheUnit(int $resolution, string $expected): void
	{
		$window = new TimelineWindow(self::ALIGNED, self::ALIGNED + self::SECOND, $resolution);

		$this->assertSame($expected, $window->resolutionLabel());
	}

	#[Test]
	#[TestDox('jsonSerialize() carries the span, the rung and its label')]
	#[Group('strata/timeline')]
	public function windowJsonSerialize(): void
	{
		$window = new TimelineWindow(self::ALIGNED, self::ALIGNED + 300 * self::SECOND, 60);

		$this->assertSame(
			[
				'from' => self::ALIGNED,
				'to' => self::ALIGNED + 300 * self::SECOND,
				'resolution' => 60,
				'resolution_label' => '1 minutes',
				'buckets' => 6,
				'seconds' => 300.0,
			],
			$window->jsonSerialize(),
		);
	}

	#endregion

	#region Buckets

	#[Test]
	#[TestDox('an empty bucket reports itself empty and a ratio of one')]
	#[Group('strata/timeline')]
	public function emptyBucket(): void
	{
		$bucket = new TimelineBucket(self::ALIGNED, self::ALIGNED + 60 * self::SECOND);

		$this->assertTrue($bucket->isEmpty());
		$this->assertSame(1.0, $bucket->ratio());
		$this->assertSame(0.0, $bucket->rate());
		$this->assertTrue($bucket->hasEveryId());
	}

	#[Test]
	#[TestDox('ratio() is raw over stored, and one when nothing was stored')]
	#[Group('strata/timeline')]
	public function ratioIsRawOverStored(): void
	{
		$end = self::ALIGNED + 60 * self::SECOND;

		$this->assertSame(
			4.0,
			(new TimelineBucket(self::ALIGNED, $end, 1, 1, 4_000, 1_000))->ratio(),
		);
		$this->assertSame(1.0, (new TimelineBucket(self::ALIGNED, $end, 1, 1, 4_000, 0))->ratio());
	}

	#[Test]
	#[TestDox('rate() is operations a second across the bucket width')]
	#[Group('strata/timeline')]
	public function rateIsPerSecond(): void
	{
		$bucket = new TimelineBucket(self::ALIGNED, self::ALIGNED + 60 * self::SECOND, 3, 120);

		$this->assertSame(2.0, $bucket->rate());
		$this->assertSame(
			0.0,
			(new TimelineBucket(self::ALIGNED, self::ALIGNED, 3, 120))->rate(),
			'a zero-width bucket has no rate rather than an infinite one',
		);
	}

	#[Test]
	#[TestDox('startSecond() drops the microseconds')]
	#[Group('strata/timeline')]
	public function startSecondDropsMicroseconds(): void
	{
		$bucket = new TimelineBucket(self::ALIGNED + 999_999, self::ALIGNED + 60 * self::SECOND);

		$this->assertSame(intdiv(self::ALIGNED, self::SECOND), $bucket->startSecond());
	}

	#[Test]
	#[TestDox('hasEveryId() is false once more commits fell in than ids are held')]
	#[Group('strata/timeline')]
	public function hasEveryIdBoundsTheDrilldown(): void
	{
		$end = self::ALIGNED + 60 * self::SECOND;
		$ids = array_map(static fn(int $i): string => sprintf('%064x', $i), range(1, 3));

		$this->assertTrue(
			(new TimelineBucket(self::ALIGNED, $end, 3, 3, 0, 0, 0, $ids))->hasEveryId(),
		);
		$this->assertFalse(
			(new TimelineBucket(self::ALIGNED, $end, 4, 4, 0, 0, 0, $ids))->hasEveryId(),
		);
		$this->assertTrue(
			(new TimelineBucket(self::ALIGNED, $end, 2, 2, 0, 0, 0, $ids))->hasEveryId(),
		);
	}

	#[Test]
	#[TestDox('jsonSerialize() carries the totals, the ids and whether the list is complete')]
	#[Group('strata/timeline')]
	public function bucketJsonSerialize(): void
	{
		$end = self::ALIGNED + 60 * self::SECOND;
		$bucket = new TimelineBucket(self::ALIGNED, $end, 4, 9, 4_000, 1_000, 1, ['aa'], 2);

		$this->assertSame(
			[
				'start' => self::ALIGNED,
				'end' => $end,
				'commits' => 4,
				'operations' => 9,
				'raw_bytes' => 4_000,
				'stored_bytes' => 1_000,
				'anchors' => 1,
				'restores' => 2,
				'ratio' => 4.0,
				'ids' => ['aa'],
				'complete' => false,
			],
			$bucket->jsonSerialize(),
		);
	}

	#endregion

	#region Metric series

	#[Test]
	#[TestDox('a series reports its extremes, its latest point and its total')]
	#[Group('strata/timeline')]
	public function seriesReportsItsShape(): void
	{
		$series = new MetricSeries('stored_bytes', 'Stored Bytes', [4.0, 9.0, 2.0], 'By');

		$this->assertSame(9.0, $series->max());
		$this->assertSame(2.0, $series->min());
		$this->assertSame(2.0, $series->latest());
		$this->assertSame(15.0, $series->total());
		$this->assertSame(3, $series->count());
		$this->assertFalse($series->isFlat());
		$this->assertFalse($series->cumulative);
	}

	#[Test]
	#[TestDox('an empty series reports zeros rather than failing on max()')]
	#[Group('strata/timeline')]
	public function emptySeriesReportsZeros(): void
	{
		$series = new MetricSeries('stored_bytes', 'Stored Bytes');

		$this->assertSame(0.0, $series->max());
		$this->assertSame(0.0, $series->min());
		$this->assertSame(0.0, $series->latest());
		$this->assertSame(0.0, $series->total());
		$this->assertSame(0, $series->count());
		$this->assertTrue($series->isFlat());
	}

	#[Test]
	#[TestDox('isFlat() is true only when there is nothing to draw')]
	#[Group('strata/timeline')]
	public function isFlatOnlyWhenThereIsNothing(): void
	{
		$this->assertTrue((new MetricSeries('a', 'A', [0.0, 0.0, 0.0]))->isFlat());
		$this->assertFalse((new MetricSeries('a', 'A', [0.0, 1.0]))->isFlat());
		$this->assertFalse((new MetricSeries('a', 'A', [-1.0, 0.0]))->isFlat());
	}

	#[Test]
	#[TestDox('latest() is the last point, which is the one a reader sees first')]
	#[Group('strata/timeline')]
	public function latestIsTheLastPoint(): void
	{
		$this->assertSame(1.0, (new MetricSeries('a', 'A', [9.0, 5.0, 1.0]))->latest());
		$this->assertSame(7.0, (new MetricSeries('a', 'A', [7.0]))->latest());
	}

	#[Test]
	#[TestDox('jsonSerialize() carries the maximum a renderer needs before it draws')]
	#[Group('strata/timeline')]
	public function seriesJsonSerialize(): void
	{
		$series = new MetricSeries('operations', 'Operations', [1.0, 4.0], 'ops', true);

		$this->assertSame(
			[
				'key' => 'operations',
				'label' => 'Operations',
				'unit' => 'ops',
				'cumulative' => true,
				'points' => [1.0, 4.0],
				'max' => 4.0,
				'latest' => 4.0,
			],
			$series->jsonSerialize(),
		);
	}

	#endregion
}
