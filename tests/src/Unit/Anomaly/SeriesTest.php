<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Anomaly;

use Drupal\strata\Anomaly\Anomaly;
use Drupal\strata\Anomaly\AnomalyDetector;
use Drupal\strata\Anomaly\Series;
use Drupal\strata\Health\Finding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Proves the statistics an anomaly is judged by, and the verdict it carries.
 *
 * The property the series exists for is measured here rather than asserted in prose: one large
 * outlier already inside the window leaves the threshold where it was, so the next outlier of the
 * same size still scores high. The same arithmetic is done with the mean and the standard deviation
 * in the same test, and that pair drops the second outlier below the warning band.
 *
 * The anomaly lane covers the severity boundaries and the ceiling. A departure tops out at ERROR
 * whatever its magnitude, so an unusual number cannot put the repair ladder on the quarantine rung.
 */
#[CoversClass(Series::class)]
#[CoversClass(Anomaly::class)]
class SeriesTest extends TestCase
{
	/**
	 * A history with a real spread, used by the outlier cases.
	 *
	 * @var list<float>
	 */
	private const HISTORY = [98.0, 99.0, 99.0, 100.0, 100.0, 100.0, 100.0, 101.0, 101.0, 102.0];

	#region Median

	/**
	 * @return array<string, array{list<float>, float}>
	 */
	public static function medianProvider(): array
	{
		return [
			'empty' => [[], 0.0],
			'one value' => [[7.0], 7.0],
			'an odd count' => [[3.0, 1.0, 2.0], 2.0],
			'an even count' => [[4.0, 1.0, 2.0, 3.0], 2.5],
			'an even count with a gap' => [[1.0, 100.0], 50.5],
			'negatives' => [[-4.0, -1.0, -3.0], -3.0],
			'repeated values' => [[5.0, 5.0, 5.0, 5.0], 5.0],
		];
	}

	#[Test]
	#[TestDox('median() of $_dataName is the middle observation')]
	#[Group('strata/anomaly')]
	#[DataProvider('medianProvider')]
	public function medianOfEveryShape(array $values, float $expected): void
	{
		$this->assertSame($expected, (new Series($values))->median());
	}

	#[Test]
	#[TestDox('the input order does not change the median')]
	#[Group('strata/anomaly')]
	public function medianIsOrderIndependent(): void
	{
		$values = [5.0, 1.0, 9.0, 3.0, 7.0];
		$reversed = array_reverse($values);

		$this->assertSame((new Series($values))->median(), (new Series($reversed))->median());
	}

	#endregion

	#region Deviation

	#[Test]
	#[TestDox('deviation() is zero when every observation is identical')]
	#[Group('strata/anomaly')]
	public function deviationOfAFlatSeriesIsZero(): void
	{
		$this->assertSame(0.0, (new Series([42.0, 42.0, 42.0, 42.0, 42.0]))->deviation());
		$this->assertSame(0.0, (new Series([]))->deviation());
		$this->assertSame(0.0, (new Series([0.0, 0.0]))->deviation());
	}

	#[Test]
	#[TestDox('deviation() is the median absolute deviation on the standard-deviation scale')]
	#[Group('strata/anomaly')]
	public function deviationIsScaledMad(): void
	{
		// distances from the median of 100 are 2,1,1,0,0,0,0,1,1,2, whose median is 1
		$this->assertSame(Series::SCALE, (new Series(self::HISTORY))->deviation());
	}

	#[Test]
	#[TestDox('score() on a series with a real spread counts deviations from the median')]
	#[Group('strata/anomaly')]
	public function scoreCountsDeviations(): void
	{
		$series = new Series(self::HISTORY);

		$this->assertSame(0.0, $series->score(100.0));
		$this->assertEqualsWithDelta(1.0, $series->score(100.0 + Series::SCALE), 1.0e-9);
		$this->assertEqualsWithDelta(-2.0, $series->score(100.0 - Series::SCALE * 2), 1.0e-9);
		$this->assertGreaterThan(0.0, $series->score(1000.0));
		$this->assertLessThan(0.0, $series->score(1.0));
	}

	#endregion

	#region Outlier resistance

	#[Test]
	#[TestDox('an outlier already in the window does not hide the next one of the same size')]
	#[Group('strata/anomaly')]
	public function anOutlierDoesNotRaiseTheThreshold(): void
	{
		$outlier = 1000.0;
		$before = new Series(self::HISTORY);
		$after = new Series([...self::HISTORY, $outlier]);

		$firstScore = $before->score($outlier);
		$secondScore = $after->score($outlier);

		$this->assertGreaterThan(AnomalyDetector::ERROR_SIGMA, $firstScore);
		$this->assertGreaterThan(AnomalyDetector::ERROR_SIGMA, $secondScore);
		$this->assertSame($before->median(), $after->median(), 'the baseline is unmoved');
		$this->assertSame($before->deviation(), $after->deviation(), 'the spread is unmoved');

		// the same arithmetic with a mean and a standard deviation, for contrast
		$firstZ = self::zScore(self::HISTORY, $outlier);
		$secondZ = self::zScore([...self::HISTORY, $outlier], $outlier);

		$this->assertGreaterThan(AnomalyDetector::ERROR_SIGMA, $firstZ);
		$this->assertLessThan(AnomalyDetector::WARN_SIGMA, $secondZ);
		$this->assertGreaterThan($secondZ * 100, $secondScore);
	}

	#[Test]
	#[TestDox('a minority of outliers leaves the median and the deviation where they were')]
	#[Group('strata/anomaly')]
	public function aMinorityOfOutliersIsIgnored(): void
	{
		$clean = new Series(self::HISTORY);
		$dirty = new Series([...self::HISTORY, 5000.0, 6000.0, 7000.0]);

		$this->assertSame($clean->median(), $dirty->median());
		$this->assertSame($clean->deviation(), $dirty->deviation());
		$this->assertGreaterThan($clean->mean() * 10, $dirty->mean());
	}

	#endregion

	#region Score fallbacks

	#[Test]
	#[TestDox('with no deviation a value double the median scores exactly one')]
	#[Group('strata/anomaly')]
	public function flatSeriesScoresAgainstTheMedian(): void
	{
		$series = new Series([50.0, 50.0, 50.0, 50.0, 50.0, 50.0, 50.0, 50.0]);

		$this->assertSame(0.0, $series->deviation());
		$this->assertSame(1.0, $series->score(100.0));
		$this->assertSame(0.0, $series->score(50.0));
		$this->assertSame(-1.0, $series->score(0.0));
		$this->assertSame(-0.5, $series->score(25.0));
	}

	#[Test]
	#[TestDox('with a negative median the fallback still scores by magnitude')]
	#[Group('strata/anomaly')]
	public function negativeMedianFallbackUsesMagnitude(): void
	{
		$series = new Series([-20.0, -20.0, -20.0, -20.0]);

		$this->assertSame(1.0, $series->score(0.0));
		$this->assertSame(-1.0, $series->score(-40.0));
	}

	#[Test]
	#[TestDox('with a median and a deviation of zero a change scores one rather than infinity')]
	#[Group('strata/anomaly')]
	public function zeroSeriesScoresOne(): void
	{
		$series = new Series([0.0, 0.0, 0.0, 0.0, 0.0]);

		$this->assertSame(0.0, $series->median());
		$this->assertSame(0.0, $series->deviation());
		$this->assertSame(0.0, $series->score(0.0));
		$this->assertSame(1.0, $series->score(0.001));
		$this->assertSame(1.0, $series->score(1.0e9));
		$this->assertSame(-1.0, $series->score(-1.0e9));
	}

	#[Test]
	#[TestDox('an empty series scores by the same fallback rather than dividing by nothing')]
	#[Group('strata/anomaly')]
	public function emptySeriesScoresByFallback(): void
	{
		$series = new Series();

		$this->assertSame(0, $series->count());
		$this->assertSame(0.0, $series->score(0.0));
		$this->assertSame(1.0, $series->score(4.0));
	}

	#endregion

	#region Window shape

	/**
	 * @return array<string, array{int, int, bool}>
	 */
	public static function establishedProvider(): array
	{
		return [
			'nothing at all' => [0, Series::MIN_OBSERVATIONS, false],
			'one short of the minimum' => [
				Series::MIN_OBSERVATIONS - 1,
				Series::MIN_OBSERVATIONS,
				false,
			],
			'exactly the minimum' => [Series::MIN_OBSERVATIONS, Series::MIN_OBSERVATIONS, true],
			'past the minimum' => [Series::MIN_OBSERVATIONS + 1, Series::MIN_OBSERVATIONS, true],
			'one value against a minimum of one' => [1, 1, false],
			'two values against a minimum of one' => [2, 1, true],
			'one value against a minimum of zero' => [1, 0, false],
		];
	}

	#[Test]
	#[TestDox('isEstablished() with $_dataName')]
	#[Group('strata/anomaly')]
	#[DataProvider('establishedProvider')]
	public function isEstablishedNeedsTwoObservations(
		int $count,
		int $minimum,
		bool $expected,
	): void {
		$series = new Series(array_fill(0, $count, 1.0));

		$this->assertSame($expected, $series->isEstablished($minimum));
	}

	#[Test]
	#[TestDox('history() drops only the last value, so the baseline excludes what is judged')]
	#[Group('strata/anomaly')]
	public function historyDropsOnlyTheLastValue(): void
	{
		$series = new Series([1.0, 2.0, 3.0, 4.0]);
		$history = $series->history();

		$this->assertSame(3, $history->count());
		$this->assertSame(3.0, $history->latest());
		$this->assertSame(4.0, $series->latest(), 'the original is untouched');
		$this->assertSame(0, (new Series([1.0]))->history()->count());
		$this->assertSame(0, (new Series())->history()->count());
	}

	#[Test]
	#[TestDox('latest() is the value last appended, not the largest one')]
	#[Group('strata/anomaly')]
	public function latestIsInsertionOrder(): void
	{
		$this->assertSame(2.0, (new Series([9.0, 5.0, 2.0]))->latest());
		$this->assertNull((new Series())->latest());
	}

	#[Test]
	#[TestDox('count() and mean() read the observations as given')]
	#[Group('strata/anomaly')]
	public function countAndMean(): void
	{
		$this->assertSame(4, (new Series([1.0, 2.0, 3.0, 4.0]))->count());
		$this->assertSame(2.5, (new Series([1.0, 2.0, 3.0, 4.0]))->mean());
		$this->assertSame(0, (new Series())->count());
		$this->assertSame(0.0, (new Series())->mean());
	}

	#endregion

	#region Anomaly severity

	/**
	 * @return array<string, array{float, int}>
	 */
	public static function severityProvider(): array
	{
		return [
			'no departure' => [0.0, Finding::INFO],
			'just below the warning band' => [AnomalyDetector::WARN_SIGMA - 0.1, Finding::INFO],
			'at the warning band' => [AnomalyDetector::WARN_SIGMA, Finding::WARN],
			'just above the warning band' => [AnomalyDetector::WARN_SIGMA + 0.1, Finding::WARN],
			'just below the error band' => [AnomalyDetector::ERROR_SIGMA - 0.1, Finding::WARN],
			'at the error band' => [AnomalyDetector::ERROR_SIGMA, Finding::ERROR],
			'just above the error band' => [AnomalyDetector::ERROR_SIGMA + 0.1, Finding::ERROR],
			'falling to the warning band' => [-AnomalyDetector::WARN_SIGMA, Finding::WARN],
			'falling just short of it' => [-(AnomalyDetector::WARN_SIGMA - 0.1), Finding::INFO],
			'falling to the error band' => [-AnomalyDetector::ERROR_SIGMA, Finding::ERROR],
			'falling far past it' => [-1000.0, Finding::ERROR],
		];
	}

	#[Test]
	#[TestDox('a departure $_dataName lands in the right severity band')]
	#[Group('strata/anomaly')]
	#[DataProvider('severityProvider')]
	public function severityFollowsTheBands(float $deviations, int $expected): void
	{
		$anomaly = new Anomaly('op_rate', 'Operation rate', 400.0, 4.0, $deviations, 32);

		$this->assertSame($expected, $anomaly->severity());
	}

	#[Test]
	#[TestDox('a departure never reaches CRITICAL however large it is')]
	#[Group('strata/anomaly')]
	public function severityNeverReachesCritical(): void
	{
		foreach ([100.0, 1.0e6, 1.0e15, -1.0e15, PHP_FLOAT_MAX] as $deviations) {
			$anomaly = new Anomaly('op_rate', 'Operation rate', 1.0, 1.0, $deviations, 32);

			$this->assertSame(Finding::ERROR, $anomaly->severity(), (string) $deviations);
			$this->assertLessThan(Finding::CRITICAL, $anomaly->severity());
		}
	}

	#[Test]
	#[TestDox('direction() reads the sign of the departure')]
	#[Group('strata/anomaly')]
	public function directionReadsTheSign(): void
	{
		$rise = new Anomaly('op_rate', 'Operation rate', 400.0, 4.0, 9.0, 32);
		$fall = new Anomaly('op_rate', 'Operation rate', 1.0, 400.0, -9.0, 32);
		$flat = new Anomaly('op_rate', 'Operation rate', 4.0, 4.0, 0.0, 32);

		$this->assertSame('above', $rise->direction());
		$this->assertSame('below', $fall->direction());
		$this->assertSame('above', $flat->direction());
	}

	#endregion

	#region Anomaly reporting

	#[Test]
	#[TestDox('toFinding() scopes the finding to the metric so each is tracked separately')]
	#[Group('strata/anomaly')]
	public function toFindingScopesToTheMetric(): void
	{
		$anomaly = new Anomaly('chain_depth', 'Deepest delta chain', 40.0, 4.0, 9.0, 32, 'links');
		$finding = $anomaly->toFinding();

		$this->assertSame(Anomaly::CODE, $finding->code);
		$this->assertSame('anomaly.detected', $finding->code);
		$this->assertSame('chain_depth', $finding->scope);
		$this->assertSame(Finding::ERROR, $finding->severity);
		$this->assertSame($anomaly->describe(), $finding->context);
	}

	#[Test]
	#[TestDox('describe() is one line naming the measurement, the departure and the sample count')]
	#[Group('strata/anomaly')]
	public function describeIsOneActionableLine(): void
	{
		$anomaly = new Anomaly('op_rate', 'Operation rate', 4200.0, 12.0, 9.31, 47, 'per hour');
		$sentence = $anomaly->describe();

		$this->assertSame(
			'Operation rate is 4200 per hour, 9.3 deviations above a baseline of 12 per hour ' .
				'over 47 samples',
			$sentence,
		);
		$this->assertStringNotContainsString("\n", $sentence);
	}

	#[Test]
	#[TestDox('describe() drops the unit when there is none and keeps fractions readable')]
	#[Group('strata/anomaly')]
	public function describeWithoutAUnit(): void
	{
		$anomaly = new Anomaly('compression_ratio', 'Compression ratio', 1.25, 3.5, -6.0, 20);

		$this->assertSame(
			'Compression ratio is 1.25, 6.0 deviations below a baseline of 3.50 over 20 samples',
			$anomaly->describe(),
		);
	}

	#[Test]
	#[TestDox('jsonSerialize() carries the numbers a dashboard graphs and the verdict it renders')]
	#[Group('strata/anomaly')]
	public function jsonSerializeCarriesTheNumbers(): void
	{
		$anomaly = new Anomaly('stored_bytes', 'Stored bytes', 900.0, 100.0, 12.3456, 64, 'bytes');

		$this->assertSame(
			[
				'metric' => 'stored_bytes',
				'label' => 'Stored bytes',
				'observed' => 900.0,
				'baseline' => 100.0,
				'deviations' => 12.346,
				'direction' => 'above',
				'samples' => 64,
				'unit' => 'bytes',
				'severity' => Finding::ERROR,
			],
			$anomaly->jsonSerialize(),
		);
	}

	#endregion

	/**
	 * The same departure measured with a mean and a standard deviation.
	 *
	 * @param list<float> $values
	 *   The window.
	 * @param float $observed
	 *   The value to score.
	 *
	 * @return float
	 *   Standard deviations from the mean, or 0.0 when the window has no spread.
	 */
	private static function zScore(array $values, float $observed): float
	{
		$mean = array_sum($values) / count($values);
		$squares = 0.0;

		foreach ($values as $value) {
			$squares += ($value - $mean) ** 2;
		}

		$deviation = sqrt($squares / count($values));

		return $deviation > 0.0 ? abs($observed - $mean) / $deviation : 0.0;
	}
}
