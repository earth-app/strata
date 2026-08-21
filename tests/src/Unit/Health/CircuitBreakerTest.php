<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Health;

use Drupal\strata\Health\CircuitBreaker;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

#[CoversClass(CircuitBreaker::class)]
class CircuitBreakerTest extends TestCase
{
	/**
	 * The timestamp the injected clock reports, moved by hand instead of by sleeping.
	 *
	 * @var int
	 */
	private int $now = 1_000_000;

	private function breaker(int $threshold = 3, int $cooldown = 300): CircuitBreaker
	{
		return new CircuitBreaker($threshold, $cooldown, fn(): int => $this->now);
	}

	private function failTimes(CircuitBreaker $breaker, string $code, int $times): void
	{
		for ($i = 0; $i < $times; $i++) {
			$breaker->recordFailure($code);
		}
	}

	#region Construction

	/**
	 * @return array<string, array{int}>
	 */
	public static function badThresholdProvider(): array
	{
		return [
			'zero' => [0],
			'negative' => [-1],
		];
	}

	#[Test]
	#[TestDox('a $_dataName threshold is refused')]
	#[Group('strata/health')]
	#[DataProvider('badThresholdProvider')]
	public function refusesThresholdBelowOne(int $threshold): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('threshold must be at least 1');

		new CircuitBreaker($threshold);
	}

	#[Test]
	#[TestDox('a negative cooldown is refused')]
	#[Group('strata/health')]
	public function refusesNegativeCooldown(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('cooldown cannot be negative');

		new CircuitBreaker(3, -1);
	}

	#[Test]
	#[TestDox('a clock that does not return an int is refused when it is read')]
	#[Group('strata/health')]
	public function refusesClockThatDoesNotReturnAnInt(): void
	{
		$breaker = new CircuitBreaker(1, 300, static fn(): string => 'now');

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('must return an int, got string');

		$breaker->recordFailure('a.b');
	}

	#[Test]
	#[TestDox('with no clock injected the wall clock is used')]
	#[Group('strata/health')]
	public function fallsBackToTheWallClock(): void
	{
		$breaker = new CircuitBreaker(1, 300);
		$breaker->recordFailure('a.b');

		$this->assertSame(CircuitBreaker::OPEN, $breaker->state('a.b'));
		$this->assertFalse($breaker->allow('a.b'));
	}

	#endregion

	#region Opening

	#[Test]
	#[TestDox('an untouched code is closed')]
	#[Group('strata/health')]
	public function startsClosed(): void
	{
		$breaker = $this->breaker();

		$this->assertSame(CircuitBreaker::CLOSED, $breaker->state('a.b'));
		$this->assertTrue($breaker->allow('a.b'));
	}

	#[Test]
	#[TestDox('failures below the threshold leave the circuit closed')]
	#[Group('strata/health')]
	public function staysClosedBelowTheThreshold(): void
	{
		$breaker = $this->breaker();
		$this->failTimes($breaker, 'a.b', 2);

		$this->assertSame(CircuitBreaker::CLOSED, $breaker->state('a.b'));
		$this->assertTrue($breaker->allow('a.b'));
	}

	#[Test]
	#[TestDox('the threshold failure opens the circuit')]
	#[Group('strata/health')]
	public function opensAtTheThreshold(): void
	{
		$breaker = $this->breaker();
		$this->failTimes($breaker, 'a.b', 3);

		$this->assertSame(CircuitBreaker::OPEN, $breaker->state('a.b'));
		$this->assertFalse($breaker->allow('a.b'));
	}

	#[Test]
	#[TestDox('codes open independently of each other')]
	#[Group('strata/health')]
	public function codesAreTrackedSeparately(): void
	{
		$breaker = $this->breaker();
		$this->failTimes($breaker, 'a.b', 3);

		$this->assertFalse($breaker->allow('a.b'));
		$this->assertTrue($breaker->allow('c.d'));
	}

	#endregion

	#region Cooldown

	#[Test]
	#[TestDox('the circuit is still open one second before the cooldown elapses')]
	#[Group('strata/health')]
	public function staysOpenUntilTheCooldownElapses(): void
	{
		$breaker = $this->breaker();
		$this->failTimes($breaker, 'a.b', 3);
		$this->now += 299;

		$this->assertSame(CircuitBreaker::OPEN, $breaker->state('a.b'));
	}

	#[Test]
	#[TestDox('the circuit is half-open the moment the cooldown elapses')]
	#[Group('strata/health')]
	public function becomesHalfOpenWhenTheCooldownElapses(): void
	{
		$breaker = $this->breaker();
		$this->failTimes($breaker, 'a.b', 3);
		$this->now += 300;

		$this->assertSame(CircuitBreaker::HALF_OPEN, $breaker->state('a.b'));
		$this->assertTrue($breaker->allow('a.b'));
	}

	#[Test]
	#[TestDox('a zero cooldown is half-open immediately')]
	#[Group('strata/health')]
	public function aZeroCooldownNeverBlocks(): void
	{
		$breaker = $this->breaker(1, 0);
		$breaker->recordFailure('a.b');

		$this->assertSame(CircuitBreaker::HALF_OPEN, $breaker->state('a.b'));
		$this->assertTrue($breaker->allow('a.b'));
	}

	#[Test]
	#[TestDox('a failed trial call reopens the circuit for a fresh cooldown')]
	#[Group('strata/health')]
	public function aFailedTrialCallReopens(): void
	{
		$breaker = $this->breaker();
		$this->failTimes($breaker, 'a.b', 3);
		$this->now += 300;

		$breaker->recordFailure('a.b');

		$this->assertSame(CircuitBreaker::OPEN, $breaker->state('a.b'));

		$this->now += 299;
		$this->assertSame(CircuitBreaker::OPEN, $breaker->state('a.b'));

		$this->now += 1;
		$this->assertSame(CircuitBreaker::HALF_OPEN, $breaker->state('a.b'));
	}

	#[Test]
	#[TestDox('time alone never closes the circuit')]
	#[Group('strata/health')]
	public function timeAloneDoesNotClose(): void
	{
		$breaker = $this->breaker();
		$this->failTimes($breaker, 'a.b', 3);
		$this->now += 86_400;

		$this->assertSame(CircuitBreaker::HALF_OPEN, $breaker->state('a.b'));
	}

	#endregion

	#region Closing

	#[Test]
	#[TestDox('a success closes the circuit and clears the count')]
	#[Group('strata/health')]
	public function aSuccessCloses(): void
	{
		$breaker = $this->breaker();
		$this->failTimes($breaker, 'a.b', 3);
		$this->now += 300;

		$breaker->recordSuccess('a.b');

		$this->assertSame(CircuitBreaker::CLOSED, $breaker->state('a.b'));

		$this->failTimes($breaker, 'a.b', 2);
		$this->assertSame(CircuitBreaker::CLOSED, $breaker->state('a.b'));
	}

	#[Test]
	#[TestDox('reset() forgets a code without waiting out its cooldown')]
	#[Group('strata/health')]
	public function resetForgetsCode(): void
	{
		$breaker = $this->breaker();
		$this->failTimes($breaker, 'a.b', 5);

		$breaker->reset('a.b');

		$this->assertSame(CircuitBreaker::CLOSED, $breaker->state('a.b'));
		$this->assertTrue($breaker->allow('a.b'));
	}

	#[Test]
	#[TestDox('resetting or succeeding on an untracked code is harmless')]
	#[Group('strata/health')]
	public function clearingAnUntrackedCodeIsHarmless(): void
	{
		$breaker = $this->breaker();

		$breaker->reset('a.b');
		$breaker->recordSuccess('c.d');

		$this->assertSame(CircuitBreaker::CLOSED, $breaker->state('a.b'));
		$this->assertSame(CircuitBreaker::CLOSED, $breaker->state('c.d'));
	}

	#[Test]
	#[TestDox('a threshold of one opens on the first failure')]
	#[Group('strata/health')]
	public function aThresholdOfOneOpensImmediately(): void
	{
		$breaker = $this->breaker(1, 60);
		$breaker->recordFailure('a.b');

		$this->assertSame(CircuitBreaker::OPEN, $breaker->state('a.b'));
	}

	#endregion
}
