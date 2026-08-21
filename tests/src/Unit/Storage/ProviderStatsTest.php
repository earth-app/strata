<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Storage;

use Drupal\strata\Storage\ProviderStats;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProviderStats::class)]
class ProviderStatsTest extends TestCase
{
	#region Fixtures

	private function withDurations(string $operation, float ...$seconds): ProviderStats
	{
		$stats = new ProviderStats();

		foreach ($seconds as $one) {
			$stats->record($operation, 0, $one);
		}

		return $stats;
	}

	private function withRange(string $operation, int $from, int $to): ProviderStats
	{
		$stats = new ProviderStats();

		for ($i = $from; $i <= $to; $i++) {
			$stats->record($operation, 0, (float) $i);
		}

		return $stats;
	}

	#endregion

	#region Empty

	#[Test]
	#[TestDox('a fresh accumulator reports nothing')]
	#[Group('strata/storage')]
	public function startsEmpty(): void
	{
		$stats = new ProviderStats();

		$this->assertSame(0, $stats->operations());
		$this->assertSame(0, $stats->bytes());
		$this->assertSame(0, $stats->failures());
		$this->assertSame(0, $stats->classA());
		$this->assertSame(0, $stats->classB());
		$this->assertSame(0.0, $stats->seconds());
		$this->assertSame([], $stats->byOperation());
	}

	#[Test]
	#[TestDox('an operation nothing was recorded for has zero latency')]
	#[Group('strata/storage')]
	public function latencyIsZeroForUnusedOperation(): void
	{
		$this->assertSame(
			['count' => 0, 'total' => 0.0, 'min' => 0.0, 'max' => 0.0, 'mean' => 0.0, 'p95' => 0.0],
			(new ProviderStats())->latency('put'),
		);
	}

	#endregion

	#region Classification

	/**
	 * @return array<string, array{string, int, int}>
	 */
	public static function billingClassProvider(): array
	{
		return [
			'put is a billed write' => ['put', 1, 0],
			'delete is a billed write' => ['delete', 1, 0],
			'list is a billed write' => ['list', 1, 0],
			'get is a billed read' => ['get', 0, 1],
			'head is a billed read' => ['head', 0, 1],
		];
	}

	#[Test]
	#[TestDox('$_dataName')]
	#[Group('strata/storage')]
	#[DataProvider('billingClassProvider')]
	public function classifiesEveryOperation(string $operation, int $classA, int $classB): void
	{
		$stats = new ProviderStats();
		$stats->record($operation, 0, 0.0);

		$this->assertSame($classA, $stats->classA());
		$this->assertSame($classB, $stats->classB());
		$this->assertSame(1, $stats->operations());
	}

	#[Test]
	#[TestDox('the two billing classes together account for every operation')]
	#[Group('strata/storage')]
	public function everyOperationBelongsToOneClass(): void
	{
		$stats = new ProviderStats();

		foreach (ProviderStats::OPERATIONS as $operation) {
			$stats->record($operation, 0, 0.0);
		}

		$this->assertSame(3, $stats->classA());
		$this->assertSame(2, $stats->classB());
		$this->assertSame($stats->operations(), $stats->classA() + $stats->classB());
		$this->assertSame([], array_intersect(ProviderStats::CLASS_A, ProviderStats::CLASS_B));
	}

	#endregion

	#region Recording

	#[Test]
	#[TestDox('bytes, duration and count add up across operations')]
	#[Group('strata/storage')]
	public function totalsAccumulate(): void
	{
		$stats = new ProviderStats();
		$stats->record('put', 1_024, 0.25);
		$stats->record('put', 2_048, 0.75);
		$stats->record('get', 512, 0.5);

		$this->assertSame(3, $stats->operations());
		$this->assertSame(3_584, $stats->bytes());
		$this->assertSame(1.5, $stats->seconds());
		$this->assertSame(2, $stats->classA());
		$this->assertSame(1, $stats->classB());
	}

	#[Test]
	#[TestDox('a failed request is still counted as a billed one')]
	#[Group('strata/storage')]
	public function failuresAreStillBilled(): void
	{
		$stats = new ProviderStats();
		$stats->record('put', 0, 0.1, true);
		$stats->record('put', 100, 0.1);

		$this->assertSame(2, $stats->operations());
		$this->assertSame(2, $stats->classA());
		$this->assertSame(1, $stats->failures());
	}

	#[Test]
	#[TestDox('failures are attributed to the operation that failed')]
	#[Group('strata/storage')]
	public function failuresAreCountedPerOperation(): void
	{
		$stats = new ProviderStats();
		$stats->record('put', 0, 0.1, true);
		$stats->record('get', 0, 0.1, true);
		$stats->record('get', 0, 0.1, true);

		$this->assertSame(1, $stats->byOperation()['put']['failures']);
		$this->assertSame(2, $stats->byOperation()['get']['failures']);
		$this->assertSame(3, $stats->failures());
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function unknownOperationProvider(): array
	{
		return [
			'not a verb' => ['copy'],
			'empty' => [''],
			'wrong case' => ['PUT'],
			'an http verb' => ['GET'],
		];
	}

	#[Test]
	#[TestDox('recording $_dataName is refused')]
	#[Group('strata/storage')]
	#[DataProvider('unknownOperationProvider')]
	public function refusesUnknownOperation(string $operation): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('is not a billed operation');

		(new ProviderStats())->record($operation, 0, 0.0);
	}

	#[Test]
	#[TestDox('reading the latency of a verb that does not exist is refused')]
	#[Group('strata/storage')]
	public function refusesLatencyForUnknownOperation(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('is not a billed operation');

		(new ProviderStats())->latency('copy');
	}

	#[Test]
	#[TestDox('a negative byte count is refused')]
	#[Group('strata/storage')]
	public function refusesNegativeBytes(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Recorded bytes cannot be negative');

		(new ProviderStats())->record('put', -1, 0.0);
	}

	#[Test]
	#[TestDox('a negative duration is refused')]
	#[Group('strata/storage')]
	public function refusesNegativeDuration(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('duration cannot be negative');

		(new ProviderStats())->record('put', 0, -0.5);
	}

	#endregion

	#region Breakdown

	#[Test]
	#[TestDox('byOperation() carries count, bytes and failures per verb')]
	#[Group('strata/storage')]
	public function byOperationHoldsPerOperationFigures(): void
	{
		$stats = new ProviderStats();
		$stats->record('put', 100, 0.1);
		$stats->record('put', 200, 0.1, true);
		$stats->record('list', 0, 0.1);

		$this->assertSame(
			[
				'put' => ['count' => 2, 'bytes' => 300, 'failures' => 1],
				'list' => ['count' => 1, 'bytes' => 0, 'failures' => 0],
			],
			$stats->byOperation(),
		);
	}

	#[Test]
	#[TestDox('byOperation() leaves out verbs the run never used')]
	#[Group('strata/storage')]
	public function byOperationSkipsUnusedOperations(): void
	{
		$stats = new ProviderStats();
		$stats->record('head', 0, 0.1);

		$this->assertSame(['head'], array_keys($stats->byOperation()));
	}

	#endregion

	#region Latency

	#[Test]
	#[TestDox('latency() describes the sample it holds')]
	#[Group('strata/storage')]
	public function latencySummarisesTheSample(): void
	{
		$latency = $this->withRange('put', 1, 10)->latency('put');

		$this->assertSame(10, $latency['count']);
		$this->assertSame(55.0, $latency['total']);
		$this->assertSame(1.0, $latency['min']);
		$this->assertSame(10.0, $latency['max']);
		$this->assertSame(5.5, $latency['mean']);
		$this->assertSame(10.0, $latency['p95']);
	}

	#[Test]
	#[TestDox('the percentile is nearest-rank, not interpolated')]
	#[Group('strata/storage')]
	public function percentileIsNearestRank(): void
	{
		$latency = $this->withRange('get', 1, 100)->latency('get');

		$this->assertSame(95.0, $latency['p95']);
		$this->assertSame(100.0, $latency['max']);
	}

	#[Test]
	#[TestDox('one sample is its own minimum, maximum and percentile')]
	#[Group('strata/storage')]
	public function oneSampleIsItsOwnPercentile(): void
	{
		$latency = $this->withDurations('head', 0.25)->latency('head');

		$this->assertSame(1, $latency['count']);
		$this->assertSame(0.25, $latency['min']);
		$this->assertSame(0.25, $latency['max']);
		$this->assertSame(0.25, $latency['p95']);
	}

	#[Test]
	#[TestDox('samples are ordered before the figures are taken')]
	#[Group('strata/storage')]
	public function samplesAreSortedBeforeReading(): void
	{
		$latency = $this->withDurations('put', 5.0, 1.0, 3.0)->latency('put');

		$this->assertSame(1.0, $latency['min']);
		$this->assertSame(5.0, $latency['max']);
		$this->assertSame(3.0, $latency['mean']);
	}

	#[Test]
	#[TestDox('the sample stops growing at the cap')]
	#[Group('strata/storage')]
	public function latencySampleIsCapped(): void
	{
		$stats = $this->withRange('put', 1, 1_500);
		$latency = $stats->latency('put');

		$this->assertSame(ProviderStats::LATENCY_SAMPLES, $latency['count']);
		$this->assertSame(501.0, $latency['min']);
		$this->assertSame(1_500.0, $latency['max']);
		$this->assertSame(1_000_500.0, $latency['total']);
	}

	#[Test]
	#[TestDox('the untruncated count and duration survive the cap')]
	#[Group('strata/storage')]
	public function totalsIgnoreTheSampleCap(): void
	{
		$stats = $this->withRange('put', 1, 1_500);

		$this->assertSame(1_500, $stats->operations());
		$this->assertSame(1_500, $stats->byOperation()['put']['count']);
		$this->assertSame(1_125_750.0, $stats->seconds());
	}

	#[Test]
	#[TestDox('each operation gets its own sample window')]
	#[Group('strata/storage')]
	public function samplesAreKeptPerOperation(): void
	{
		$stats = new ProviderStats();
		$stats->record('put', 0, 1.0);
		$stats->record('get', 0, 9.0);

		$this->assertSame(1.0, $stats->latency('put')['max']);
		$this->assertSame(9.0, $stats->latency('get')['max']);
	}

	#[Test]
	#[TestDox('secondsFor() and slowestOf() are per operation and survive the sample cap')]
	#[Group('strata/storage')]
	public function perOperationDurationsIgnoreTheSampleCap(): void
	{
		$stats = $this->withRange('put', 1, 1_500);
		$stats->record('get', 0, 2.5);

		$this->assertSame(
			1_125_750.0,
			$stats->secondsFor('put'),
			'every attempt, not the last 1000',
		);
		$this->assertSame(2.5, $stats->secondsFor('get'));
		$this->assertSame(1_500.0, $stats->slowestOf('put'));
	}

	#[Test]
	#[TestDox('the slowest attempt is reported even once its sample has been dropped')]
	#[Group('strata/storage')]
	public function thePeakOutlivesItsSample(): void
	{
		$stats = new ProviderStats();
		$stats->record('put', 0, 90.0);

		for ($at = 0; $at < ProviderStats::LATENCY_SAMPLES; $at++) {
			$stats->record('put', 0, 0.01);
		}

		$this->assertSame(0.01, $stats->latency('put')['max'], 'the spike is out of the window');
		$this->assertSame(90.0, $stats->slowestOf('put'), 'and still on the record');
	}

	#[Test]
	#[TestDox('an operation that was never attempted has no duration and no peak')]
	#[Group('strata/storage')]
	public function anUnusedOperationHasNoDuration(): void
	{
		$stats = new ProviderStats();

		$this->assertSame(0.0, $stats->secondsFor('put'));
		$this->assertSame(0.0, $stats->slowestOf('put'));
	}

	#[Test]
	#[TestDox('secondsFor() and slowestOf() refuse a verb nothing bills for')]
	#[Group('strata/storage')]
	public function perOperationDurationsGuardTheVerb(): void
	{
		$stats = new ProviderStats();

		try {
			$stats->secondsFor('copy');
			$this->fail('a verb outside OPERATIONS must not be accepted');
		} catch (InvalidArgumentException $refused) {
			$this->assertStringContainsString('copy', $refused->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);

		$stats->slowestOf('copy');
	}

	#endregion

	#region Merging

	#[Test]
	#[TestDox('merge() adds one window into another')]
	#[Group('strata/storage')]
	public function mergeAddsCounters(): void
	{
		$left = new ProviderStats();
		$left->record('put', 100, 0.5);
		$left->record('get', 50, 0.25, true);

		$right = new ProviderStats();
		$right->record('put', 200, 1.0);
		$right->record('delete', 0, 0.25);

		$left->merge($right);

		$this->assertSame(4, $left->operations());
		$this->assertSame(350, $left->bytes());
		$this->assertSame(1, $left->failures());
		$this->assertSame(2.0, $left->seconds());
		$this->assertSame(3, $left->classA());
		$this->assertSame(1, $left->classB());
		$this->assertSame(2, $left->byOperation()['put']['count']);
	}

	#[Test]
	#[TestDox('merge() folds the latency samples in as the newer ones')]
	#[Group('strata/storage')]
	public function mergeFoldsLatencySamples(): void
	{
		$left = $this->withDurations('put', 1.0, 2.0);
		$right = $this->withDurations('put', 8.0);

		$left->merge($right);

		$this->assertSame(3, $left->latency('put')['count']);
		$this->assertSame(8.0, $left->latency('put')['max']);
		$this->assertSame(11.0, $left->latency('put')['total']);
	}

	#[Test]
	#[TestDox('a merge that overflows the sample keeps the newest observations')]
	#[Group('strata/storage')]
	public function mergeRespectsTheSampleCap(): void
	{
		$left = $this->withRange('put', 1, 900);
		$right = $this->withRange('put', 1_001, 1_300);

		$left->merge($right);
		$latency = $left->latency('put');

		$this->assertSame(ProviderStats::LATENCY_SAMPLES, $latency['count']);
		$this->assertSame(201.0, $latency['min']);
		$this->assertSame(1_300.0, $latency['max']);
		$this->assertSame(1_200, $left->operations());
	}

	#[Test]
	#[TestDox('merge() leaves the window it read from alone')]
	#[Group('strata/storage')]
	public function mergeLeavesTheSourceAlone(): void
	{
		$left = new ProviderStats();
		$left->record('put', 100, 0.5);

		$right = new ProviderStats();
		$right->record('put', 200, 1.0);

		$left->merge($right);

		$this->assertSame(1, $right->operations());
		$this->assertSame(200, $right->bytes());
		$this->assertSame(1.0, $right->seconds());
	}

	#[Test]
	#[TestDox('merging an empty window changes nothing')]
	#[Group('strata/storage')]
	public function mergingNothingChangesNothing(): void
	{
		$left = new ProviderStats();
		$left->record('put', 100, 0.5);

		$left->merge(new ProviderStats());

		$this->assertSame(1, $left->operations());
		$this->assertSame(100, $left->bytes());
		$this->assertSame(['put'], array_keys($left->byOperation()));
	}

	#[Test]
	#[TestDox('merge() adds the per-operation durations and keeps the higher peak')]
	#[Group('strata/storage')]
	public function mergeFoldsDurationsAndPeaks(): void
	{
		$left = $this->withDurations('put', 1.0, 6.0);
		$right = $this->withDurations('put', 2.0);
		$right->record('get', 0, 0.5);

		$left->merge($right);

		$this->assertSame(9.0, $left->secondsFor('put'));
		$this->assertSame(6.0, $left->slowestOf('put'), 'the merged window was faster');
		$this->assertSame(0.5, $left->secondsFor('get'));
	}

	#endregion

	#region Reset

	#[Test]
	#[TestDox('reset() starts a fresh window')]
	#[Group('strata/storage')]
	public function resetClearsEverything(): void
	{
		$stats = new ProviderStats();
		$stats->record('put', 100, 0.5, true);
		$stats->record('get', 50, 0.25);

		$stats->reset();

		$this->assertSame(0, $stats->operations());
		$this->assertSame(0, $stats->bytes());
		$this->assertSame(0, $stats->failures());
		$this->assertSame(0.0, $stats->seconds());
		$this->assertSame([], $stats->byOperation());
		$this->assertSame(0, $stats->latency('put')['count']);
		$this->assertSame(0.0, $stats->secondsFor('put'));
		$this->assertSame(0.0, $stats->slowestOf('put'));
	}

	#[Test]
	#[TestDox('a window can be used again after it is reset')]
	#[Group('strata/storage')]
	public function recordsAgainAfterReset(): void
	{
		$stats = new ProviderStats();
		$stats->record('put', 100, 0.5);
		$stats->reset();
		$stats->record('list', 0, 0.125);

		$this->assertSame(1, $stats->operations());
		$this->assertSame(['list'], array_keys($stats->byOperation()));
		$this->assertSame(0.125, $stats->seconds());
	}

	#endregion

	#region Serialization

	#[Test]
	#[TestDox('the window survives a json round trip')]
	#[Group('strata/storage')]
	public function serializesToJson(): void
	{
		$stats = new ProviderStats();
		$stats->record('put', 1_024, 0.5);
		$stats->record('put', 1_024, 1.5, true);
		$stats->record('head', 0, 0.25);

		$encoded = json_encode($stats);
		$this->assertIsString($encoded);

		$decoded = json_decode($encoded, true);

		$this->assertSame(3, $decoded['operations']);
		$this->assertSame(2, $decoded['classA']);
		$this->assertSame(1, $decoded['classB']);
		$this->assertSame(2_048, $decoded['bytes']);
		$this->assertSame(1, $decoded['failures']);
		$this->assertSame(2.25, $decoded['seconds']);
		$this->assertSame(
			['count' => 2, 'bytes' => 2_048, 'failures' => 1],
			$decoded['byOperation']['put'],
		);
		$this->assertSame(1.5, $decoded['latency']['put']['max']);
		// json has one number type, so a whole float comes back as an int
		$this->assertEqualsWithDelta(1.0, $decoded['latency']['put']['mean'], 1e-9);
	}

	#[Test]
	#[TestDox('an empty window serializes without a breakdown')]
	#[Group('strata/storage')]
	public function serializesAnEmptyWindow(): void
	{
		$decoded = json_decode((string) json_encode(new ProviderStats()), true);

		$this->assertSame(0, $decoded['operations']);
		$this->assertSame([], $decoded['byOperation']);
		$this->assertSame([], $decoded['latency']);
	}

	#endregion
}
