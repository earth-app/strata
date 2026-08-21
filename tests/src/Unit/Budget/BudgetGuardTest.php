<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Budget;

use Drupal\strata\Budget\BudgetAssessment;
use Drupal\strata\Budget\BudgetGuard;
use Drupal\strata\Budget\EscalationLadder;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Storage\ProviderStats;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

#[CoversClass(BudgetGuard::class)]
#[CoversClass(BudgetAssessment::class)]
class BudgetGuardTest extends TestCase
{
	#region Fixtures

	private const GIB = BudgetGuard::BYTES_PER_GIGABYTE;

	private const MONTH = BudgetGuard::SECONDS_PER_MONTH;

	/**
	 * The timestamp the injected clock reports, moved by hand instead of by waiting.
	 *
	 * @var int
	 */
	private int $now = 5_000_000;

	private function traffic(int $bytes = 0, int $classA = 0, int $classB = 0): ProviderStats
	{
		$stats = new ProviderStats();

		for ($i = 0; $i < $classA; $i++) {
			$stats->record('put', 0, 0.0);
		}
		for ($i = 0; $i < $classB; $i++) {
			$stats->record('get', 0, 0.0);
		}
		if ($bytes > 0) {
			// the volume rides on one extra put, so a case pricing requests passes no bytes
			$stats->record('put', $bytes, 0.0);
		}

		return $stats;
	}

	private function guard(int $bytesCeiling = 0, float $dollarsCeiling = 0.0): BudgetGuard
	{
		return new BudgetGuard($bytesCeiling, $dollarsCeiling, clock: fn(): int => $this->now);
	}

	#endregion

	#region Construction

	#[Test]
	#[TestDox('a negative bytes ceiling is refused')]
	#[Group('strata/budget')]
	public function refusesNegativeBytesCeiling(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('bytes ceiling cannot be negative');

		new BudgetGuard(-1);
	}

	#[Test]
	#[TestDox('a negative dollars ceiling is refused')]
	#[Group('strata/budget')]
	public function refusesNegativeDollarsCeiling(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('dollars ceiling cannot be negative');

		new BudgetGuard(0, -0.01);
	}

	/**
	 * @return array<string, array{float, float, float, string}>
	 */
	public static function negativePriceProvider(): array
	{
		return [
			'storage' => [-0.01, 4.5, 0.36, 'storage'],
			'class-A' => [0.015, -4.5, 0.36, 'class-A'],
			'class-B' => [0.015, 4.5, -0.36, 'class-B'],
		];
	}

	#[Test]
	#[TestDox('a negative $_dataName price is refused')]
	#[Group('strata/budget')]
	#[DataProvider('negativePriceProvider')]
	public function refusesNegativePrice(
		float $storage,
		float $classA,
		float $classB,
		string $line,
	): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(sprintf('The %s price cannot be negative', $line));

		new BudgetGuard(0, 0.0, $storage, $classA, $classB);
	}

	#[Test]
	#[TestDox('a negative stored size is refused')]
	#[Group('strata/budget')]
	public function refusesNegativeStoredBytes(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Stored bytes cannot be negative');

		$this->guard()->assess(-1, $this->traffic(), self::MONTH);
	}

	#[Test]
	#[TestDox('the shipped prices are the published ones')]
	#[Group('strata/budget')]
	public function publishedPricesAreRecorded(): void
	{
		$this->assertSame(0.015, BudgetGuard::R2_DOLLARS_PER_GIGABYTE_MONTH);
		$this->assertSame(4.5, BudgetGuard::R2_DOLLARS_PER_MILLION_CLASS_A);
		$this->assertSame(0.36, BudgetGuard::R2_DOLLARS_PER_MILLION_CLASS_B);
		$this->assertSame(10, BudgetGuard::R2_FREE_GIGABYTE_MONTHS);
		$this->assertSame(1_000_000, BudgetGuard::R2_FREE_CLASS_A);
		$this->assertSame(10_000_000, BudgetGuard::R2_FREE_CLASS_B);
		$this->assertSame(0.023, BudgetGuard::S3_DOLLARS_PER_GIGABYTE_MONTH);
		$this->assertSame(5.0, BudgetGuard::S3_DOLLARS_PER_MILLION_CLASS_A);
		$this->assertSame(0.4, BudgetGuard::S3_DOLLARS_PER_MILLION_CLASS_B);
		$this->assertSame(2_630_016, BudgetGuard::SECONDS_PER_MONTH);
	}

	#endregion

	#region Projection

	/**
	 * @return array<string, array{int}>
	 */
	public static function emptyWindowProvider(): array
	{
		return [
			'a window of no time' => [0],
			'a window that ran backwards' => [-5],
		];
	}

	#[Test]
	#[TestDox('$_dataName projects nothing')]
	#[Group('strata/budget')]
	#[DataProvider('emptyWindowProvider')]
	public function zeroWindowProjectsNothing(int $secondsElapsed): void
	{
		$assessment = $this->guard(10 * self::GIB, 15.0)->assess(
			5_000 * self::GIB,
			$this->traffic(bytes: 100 * self::GIB),
			$secondsElapsed,
		);

		$this->assertSame('normal', $assessment->rung);
		$this->assertSame(0.0, $assessment->usedFraction);
		$this->assertSame(0, $assessment->projectedBytes);
		$this->assertSame(0.0, $assessment->projectedDollars);
		$this->assertNull($assessment->reason);
		$this->assertFalse($assessment->isOverBudget());
		$this->assertTrue($assessment->allowsCapture());
	}

	#[Test]
	#[TestDox('a half-month window doubles what it saw')]
	#[Group('strata/budget')]
	public function extrapolatesTheWindowToOneMonth(): void
	{
		$assessment = $this->guard(4 * self::GIB)->assess(
			0,
			$this->traffic(bytes: 1 * self::GIB),
			intdiv(self::MONTH, 2),
		);

		$this->assertSame(2 * self::GIB, $assessment->projectedBytes);
		$this->assertSame(0.5, $assessment->usedFraction);
		$this->assertSame('normal', $assessment->rung);
	}

	#[Test]
	#[TestDox('requests are priced at the shipped rate per million')]
	#[Group('strata/budget')]
	public function pricesRequestsAtTheConfiguredRate(): void
	{
		$assessment = $this->guard()->assess(0, $this->traffic(classA: 1, classB: 1), 1);

		// one of each per second is 2,630,016 of each per month
		$this->assertEqualsWithDelta(12.78187776, $assessment->projectedDollars, 1e-6);
	}

	#[Test]
	#[TestDox('storage is priced at the shipped rate per gigabyte-month')]
	#[Group('strata/budget')]
	public function pricesStorageAtTheConfiguredRate(): void
	{
		$assessment = $this->guard()->assess(1_000 * self::GIB, $this->traffic(), self::MONTH);

		$this->assertEqualsWithDelta(15.0, $assessment->projectedDollars, 1e-9);
	}

	#[Test]
	#[TestDox('the shipped prices are cloudflare r2 and another endpoint can be priced instead')]
	#[Group('strata/budget')]
	public function defaultPricesFollowCloudflare(): void
	{
		$onS3 = new BudgetGuard(
			0,
			0.0,
			BudgetGuard::S3_DOLLARS_PER_GIGABYTE_MONTH,
			BudgetGuard::S3_DOLLARS_PER_MILLION_CLASS_A,
			BudgetGuard::S3_DOLLARS_PER_MILLION_CLASS_B,
		);

		$onR2 = $this->guard()->assess(1_000 * self::GIB, $this->traffic(), self::MONTH);
		$assessed = $onS3->assess(1_000 * self::GIB, $this->traffic(), self::MONTH);

		$this->assertEqualsWithDelta(15.0, $onR2->projectedDollars, 1e-9);
		$this->assertEqualsWithDelta(23.0, $assessed->projectedDollars, 1e-9);
	}

	#[Test]
	#[TestDox('a projection past the integer ceiling is pinned rather than wrapped')]
	#[Group('strata/budget')]
	public function saturatesAnEnormousProjection(): void
	{
		$assessment = $this->guard(10 * self::GIB)->assess(
			0,
			$this->traffic(bytes: 1_000_000_000_000_000_000),
			1,
		);

		$this->assertSame(PHP_INT_MAX, $assessment->projectedBytes);
		$this->assertSame('stop', $assessment->rung);
	}

	#endregion

	#region The bytes axis

	/**
	 * @return array<string, array{int, string, float}>
	 */
	public static function bytesRungProvider(): array
	{
		return [
			'nothing written' => [0, 'normal', 0.0],
			'seven tenths of the ceiling' => [7, 'normal', 0.7],
			'exactly the soft line' => [8, 'warn', 0.8],
			'a tenth over the ceiling' => [11, 'reduce', 1.1],
			'three tenths over' => [13, 'pause', 1.3],
			'six tenths over' => [16, 'stop', 1.6],
		];
	}

	#[Test]
	#[TestDox('the bytes ceiling at $_dataName')]
	#[Group('strata/budget')]
	#[DataProvider('bytesRungProvider')]
	public function bytesCeilingDrivesEveryRung(
		int $gibibytes,
		string $expected,
		float $usedFraction,
	): void {
		$assessment = $this->guard(10 * self::GIB)->assess(
			0,
			$this->traffic(bytes: $gibibytes * self::GIB),
			self::MONTH,
		);

		$this->assertSame($expected, $assessment->rung);
		$this->assertSame($usedFraction, $assessment->usedFraction);
		$this->assertSame(BudgetAssessment::AXIS_BYTES, $assessment->reason);
		$this->assertSame($gibibytes * self::GIB, $assessment->projectedBytes);
	}

	#[Test]
	#[TestDox('a bytes ceiling on its own leaves the dollars axis unbounded')]
	#[Group('strata/budget')]
	public function bytesCeilingAloneNamesTheBytesAxis(): void
	{
		$assessment = $this->guard(10 * self::GIB)->assess(
			100_000 * self::GIB,
			$this->traffic(bytes: self::GIB),
			self::MONTH,
		);

		$this->assertSame(BudgetAssessment::AXIS_BYTES, $assessment->reason);
		$this->assertSame(0.0, $assessment->dollarsCeiling);
		$this->assertSame('normal', $assessment->rung);
		$this->assertGreaterThan(1_000.0, $assessment->projectedDollars);
	}

	#endregion

	#region The dollars axis

	/**
	 * @return array<string, array{int, string}>
	 */
	public static function dollarsRungProvider(): array
	{
		return [
			'a tenth of the ceiling' => [100, 'normal'],
			'nine tenths of the ceiling' => [900, 'warn'],
			'a tenth over the ceiling' => [1_100, 'reduce'],
			'three tenths over' => [1_300, 'pause'],
			'seven tenths over' => [1_700, 'stop'],
		];
	}

	#[Test]
	#[TestDox('the dollars ceiling at $_dataName')]
	#[Group('strata/budget')]
	#[DataProvider('dollarsRungProvider')]
	public function dollarsCeilingDrivesEveryRung(int $gibibytesStored, string $expected): void
	{
		$assessment = $this->guard(0, 15.0)->assess(
			$gibibytesStored * self::GIB,
			$this->traffic(),
			self::MONTH,
		);

		$this->assertSame($expected, $assessment->rung);
		$this->assertSame(BudgetAssessment::AXIS_DOLLARS, $assessment->reason);
		$this->assertEqualsWithDelta(
			$gibibytesStored * BudgetGuard::R2_DOLLARS_PER_GIGABYTE_MONTH,
			$assessment->projectedDollars,
			1e-9,
		);
	}

	#[Test]
	#[TestDox('a dollars ceiling on its own leaves the bytes axis unbounded')]
	#[Group('strata/budget')]
	public function dollarsCeilingAloneNamesTheDollarsAxis(): void
	{
		$assessment = $this->guard(0, 15.0)->assess(
			100 * self::GIB,
			$this->traffic(bytes: 10_000 * self::GIB),
			self::MONTH,
		);

		$this->assertSame(BudgetAssessment::AXIS_DOLLARS, $assessment->reason);
		$this->assertSame(0, $assessment->bytesCeiling);
		$this->assertSame('normal', $assessment->rung);
	}

	#endregion

	#region Both axes

	#[Test]
	#[TestDox('the dollars axis drives the rung when it is the worse of the two')]
	#[Group('strata/budget')]
	public function theDollarsAxisWinsWhenItIsWorse(): void
	{
		$assessment = $this->guard(10 * self::GIB, 10.0)->assess(
			1_000 * self::GIB,
			$this->traffic(bytes: 9 * self::GIB),
			self::MONTH,
		);

		$this->assertSame('stop', $assessment->rung);
		$this->assertSame(BudgetAssessment::AXIS_DOLLARS, $assessment->reason);
		$this->assertEqualsWithDelta(1.5, $assessment->usedFraction, 1e-6);
	}

	#[Test]
	#[TestDox('the bytes axis drives the rung when it is the worse of the two')]
	#[Group('strata/budget')]
	public function theBytesAxisWinsWhenItIsWorse(): void
	{
		$assessment = $this->guard(10 * self::GIB, 100.0)->assess(
			100 * self::GIB,
			$this->traffic(bytes: 14 * self::GIB),
			self::MONTH,
		);

		$this->assertSame('pause', $assessment->rung);
		$this->assertSame(BudgetAssessment::AXIS_BYTES, $assessment->reason);
		$this->assertEqualsWithDelta(1.4, $assessment->usedFraction, 1e-6);
	}

	#[Test]
	#[TestDox('both axes level leaves the reading on the bytes axis')]
	#[Group('strata/budget')]
	public function tieStaysOnTheBytesAxis(): void
	{
		$assessment = $this->guard(10 * self::GIB, 10.0)->assess(0, $this->traffic(), self::MONTH);

		$this->assertSame(0.0, $assessment->usedFraction);
		$this->assertSame(BudgetAssessment::AXIS_BYTES, $assessment->reason);
		$this->assertSame('normal', $assessment->rung);
	}

	#[Test]
	#[TestDox('with neither ceiling set nothing is named and nothing is constrained')]
	#[Group('strata/budget')]
	public function noCeilingLeavesTheReasonUnset(): void
	{
		$assessment = $this->guard()->assess(
			1_000 * self::GIB,
			$this->traffic(bytes: 5 * self::GIB),
			self::MONTH,
		);

		$this->assertNull($assessment->reason);
		$this->assertSame(0.0, $assessment->usedFraction);
		$this->assertSame('normal', $assessment->rung);
		$this->assertSame(5 * self::GIB, $assessment->projectedBytes);
		$this->assertGreaterThan(14.0, $assessment->projectedDollars);
		$this->assertFalse($assessment->isOverBudget());
	}

	#[Test]
	#[TestDox('the ceilings in force are carried on the reading')]
	#[Group('strata/budget')]
	public function carriesTheCeilingsItMeasuredAgainst(): void
	{
		$assessment = $this->guard(10 * self::GIB, 15.0)->assess(0, $this->traffic(), self::MONTH);

		$this->assertSame(10 * self::GIB, $assessment->bytesCeiling);
		$this->assertSame(15.0, $assessment->dollarsCeiling);
	}

	#endregion

	#region The clock

	#[Test]
	#[TestDox('assessSince() measures the window against the injected clock')]
	#[Group('strata/budget')]
	public function assessSinceReadsTheClock(): void
	{
		$guard = $this->guard(10 * self::GIB);
		$stats = $this->traffic(bytes: 13 * self::GIB);

		$since = $guard->assessSince($this->now - self::MONTH, 0, $stats);
		$direct = $guard->assess(0, $stats, self::MONTH);

		$this->assertSame($direct->rung, $since->rung);
		$this->assertSame($direct->projectedBytes, $since->projectedBytes);
		$this->assertSame('pause', $since->rung);
	}

	#[Test]
	#[TestDox('assessSince() on a window that has not moved projects nothing')]
	#[Group('strata/budget')]
	public function assessSinceOnAnUnmovedWindow(): void
	{
		$assessment = $this->guard(10 * self::GIB)->assessSince(
			$this->now,
			0,
			$this->traffic(bytes: 100 * self::GIB),
		);

		$this->assertSame('normal', $assessment->rung);
		$this->assertSame(0, $assessment->projectedBytes);
	}

	#[Test]
	#[TestDox('with no clock injected the wall clock is used')]
	#[Group('strata/budget')]
	public function fallsBackToTheWallClock(): void
	{
		$guard = new BudgetGuard(10 * self::GIB);

		$assessment = $guard->assessSince(
			time() - self::MONTH,
			0,
			$this->traffic(bytes: 16 * self::GIB),
		);

		$this->assertSame('stop', $assessment->rung);
	}

	#[Test]
	#[TestDox('a clock that does not return an int is refused when it is read')]
	#[Group('strata/budget')]
	public function refusesNonIntegerClock(): void
	{
		$guard = new BudgetGuard(clock: static fn(): string => 'now');

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('must return an int, got string');

		$guard->assessSince(0, 0, $this->traffic());
	}

	#endregion

	#region The reading

	/**
	 * @return array<string, array{float, bool}>
	 */
	public static function overBudgetProvider(): array
	{
		return [
			'nothing used' => [0.0, false],
			'just under the soft line' => [0.79, false],
			'on the soft line' => [0.8, false],
			'just under the ceiling' => [0.99, false],
			'on the ceiling' => [1.0, true],
			'well over' => [2.0, true],
		];
	}

	#[Test]
	#[TestDox('isOverBudget(): $_dataName')]
	#[Group('strata/budget')]
	#[DataProvider('overBudgetProvider')]
	public function overBudgetStartsAtTheCeiling(float $usedFraction, bool $expected): void
	{
		$assessment = new BudgetAssessment(
			EscalationLadder::rungFor($usedFraction),
			$usedFraction,
			0,
			0.0,
		);

		$this->assertSame($expected, $assessment->isOverBudget());
	}

	#[Test]
	#[TestDox('allowsRealm() follows the ladder rung by rung')]
	#[Group('strata/budget')]
	public function allowsRealmFollowsTheLadder(): void
	{
		foreach (EscalationLadder::RUNGS as $rung) {
			$assessment = new BudgetAssessment($rung, 0.0, 0, 0.0);

			foreach (Realm::cases() as $realm) {
				$this->assertSame(
					!EscalationLadder::pausesRealm($rung, $realm->value),
					$assessment->allowsRealm($realm->value),
				);
			}
		}
	}

	#[Test]
	#[TestDox('the file realm is dropped at pause while the entity realm is kept')]
	#[Group('strata/budget')]
	public function pauseKeepsTheRealmsOnlyStrataHolds(): void
	{
		$paused = new BudgetAssessment('pause', 1.3, 0, 0.0);

		$this->assertFalse($paused->allowsRealm(Realm::FILE->value));
		$this->assertFalse($paused->allowsRealm(Realm::CODE->value));
		$this->assertFalse($paused->allowsRealm(Realm::EPHEMERAL->value));
		$this->assertTrue($paused->allowsRealm(Realm::ENTITY->value));
		$this->assertTrue($paused->allowsRealm(Realm::CONFIG->value));
		$this->assertTrue($paused->allowsCapture());
	}

	#[Test]
	#[TestDox('allowsCapture() is false at stop and true everywhere below it')]
	#[Group('strata/budget')]
	public function allowsCaptureStopsOnlyAtStop(): void
	{
		foreach (EscalationLadder::RUNGS as $rung) {
			$assessment = new BudgetAssessment($rung, 0.0, 0, 0.0);

			$this->assertSame($rung !== 'stop', $assessment->allowsCapture());
		}

		$stopped = new BudgetAssessment('stop', 2.0, 0, 0.0);
		foreach (Realm::cases() as $realm) {
			$this->assertFalse($stopped->allowsRealm($realm->value));
		}
	}

	#[Test]
	#[TestDox('the summary names the bytes ceiling it was measured against')]
	#[Group('strata/budget')]
	public function summaryNamesTheBytesAxis(): void
	{
		$assessment = new BudgetAssessment(
			'warn',
			0.8,
			8 * self::GIB,
			1.5,
			10 * self::GIB,
			0.0,
			BudgetAssessment::AXIS_BYTES,
		);

		$summary = $assessment->summary();

		$this->assertStringContainsString('warn', $summary);
		$this->assertStringContainsString('8.0 GB of 10.0 GB', $summary);
		$this->assertStringContainsString('80.0% of the bytes ceiling', $summary);
	}

	#[Test]
	#[TestDox('the summary names the dollars ceiling it was measured against')]
	#[Group('strata/budget')]
	public function summaryNamesTheDollarsAxis(): void
	{
		$assessment = new BudgetAssessment(
			'reduce',
			1.2,
			0,
			18.0,
			0,
			15.0,
			BudgetAssessment::AXIS_DOLLARS,
		);

		$summary = $assessment->summary();

		$this->assertStringContainsString('reduce', $summary);
		$this->assertStringContainsString('$18.00 of $15.00', $summary);
		$this->assertStringContainsString('120.0% of the dollars ceiling', $summary);
	}

	#[Test]
	#[TestDox('the summary says so when no ceiling was set')]
	#[Group('strata/budget')]
	public function summarySaysWhenNothingConstrains(): void
	{
		$summary = (new BudgetAssessment('normal', 0.0, 1_536, 0.25))->summary();

		$this->assertStringContainsString('against no ceiling', $summary);
		$this->assertStringContainsString('1.5 KB', $summary);
		$this->assertStringContainsString('$0.25', $summary);
	}

	#[Test]
	#[TestDox('a byte count under a kilobyte is written out whole')]
	#[Group('strata/budget')]
	public function summaryWritesSmallByteCountsWhole(): void
	{
		$this->assertStringContainsString(
			'512 B',
			(new BudgetAssessment('normal', 0.0, 512, 0.0))->summary(),
		);
	}

	#[Test]
	#[TestDox('the reading survives a json round trip')]
	#[Group('strata/budget')]
	public function serializesToJson(): void
	{
		$assessment = $this->guard(10 * self::GIB, 15.0)->assess(
			0,
			$this->traffic(bytes: 13 * self::GIB),
			self::MONTH,
		);

		$encoded = json_encode($assessment);
		$this->assertIsString($encoded);

		$decoded = json_decode($encoded, true);

		$this->assertSame('pause', $decoded['rung']);
		$this->assertSame('bytes', $decoded['reason']);
		$this->assertSame(1.3, $decoded['usedFraction']);
		$this->assertSame(13 * self::GIB, $decoded['projectedBytes']);
		$this->assertSame(10 * self::GIB, $decoded['bytesCeiling']);
		// json has one number type, so a whole float comes back as an int
		$this->assertEqualsWithDelta(15.0, $decoded['dollarsCeiling'], 1e-9);
		$this->assertTrue($decoded['overBudget']);
		$this->assertTrue($decoded['allowsCapture']);
		$this->assertSame($assessment->summary(), $decoded['summary']);
	}

	#endregion
}
