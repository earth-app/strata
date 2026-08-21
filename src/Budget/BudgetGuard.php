<?php

declare(strict_types=1);

namespace Drupal\strata\Budget;

use Closure;
use Drupal\strata\Storage\ProviderStats;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Turns a window of store traffic into a monthly bill, and the bill into a rung.
 *
 * A backup that quietly grows into a four-figure invoice is a worse outcome than one that told
 * somebody at 80% and shortened its own retention at 100%. The guard reads what the store was
 * actually asked to do over a window, extrapolates it to a month, and hands back an assessment the
 * capture and compaction paths can act on.
 *
 * Two ceilings, either of which may be left unset:
 *
 * - bytes per month, for an operator who thinks in volume or has a transfer allowance;
 * - dollars per month, for one who thinks in invoices.
 *
 * With both set, the rung is the worse of the two axes and BudgetAssessment::$reason names which
 * one it came from. Zero on either axis means that axis does not constrain anything.
 *
 * Prices default to Cloudflare R2: $0.015 per GB-month of storage, $4.50 per million class-A
 * operations, $0.36 per million class-B operations, and no egress charge. AWS S3 charges $0.023,
 * $5.00 and $0.40 for the same three lines and bills egress separately, so an S3 site sets its own
 * prices through the constructor. R2's free allowance of 10 GB-months, 1 million class-A and 10
 * million class-B operations is recorded here as constants but is not subtracted from a projection:
 * the allowance is per account, and Strata is rarely the only thing in the account.
 *
 * Egress is not priced at all. R2 does not charge for it, and an S3 site's egress depends on where
 * the reader is rather than on what Strata wrote.
 *
 * A gigabyte is 2^30 bytes everywhere in this class.
 *
 * @see BudgetAssessment
 * @see EscalationLadder
 * @see ProviderStats
 */
final class BudgetGuard
{
	/**
	 * Seconds in the month every projection is made against: 30.44 days.
	 */
	public const SECONDS_PER_MONTH = 2_630_016;

	/**
	 * Bytes in the gigabyte storage is priced by.
	 */
	public const BYTES_PER_GIGABYTE = 1_073_741_824;

	/**
	 * Cloudflare R2 storage, dollars per GB-month.
	 */
	public const R2_DOLLARS_PER_GIGABYTE_MONTH = 0.015;

	/**
	 * Cloudflare R2 class-A operations, dollars per million.
	 */
	public const R2_DOLLARS_PER_MILLION_CLASS_A = 4.5;

	/**
	 * Cloudflare R2 class-B operations, dollars per million.
	 */
	public const R2_DOLLARS_PER_MILLION_CLASS_B = 0.36;

	/**
	 * GB-months of Cloudflare R2 storage included in the free allowance.
	 */
	public const R2_FREE_GIGABYTE_MONTHS = 10;

	/**
	 * Class-A operations included in the Cloudflare R2 free allowance, per month.
	 */
	public const R2_FREE_CLASS_A = 1_000_000;

	/**
	 * Class-B operations included in the Cloudflare R2 free allowance, per month.
	 */
	public const R2_FREE_CLASS_B = 10_000_000;

	/**
	 * AWS S3 standard storage, dollars per GB-month.
	 */
	public const S3_DOLLARS_PER_GIGABYTE_MONTH = 0.023;

	/**
	 * AWS S3 PUT, COPY, POST and LIST requests, dollars per million.
	 */
	public const S3_DOLLARS_PER_MILLION_CLASS_A = 5.0;

	/**
	 * AWS S3 GET and SELECT requests, dollars per million.
	 */
	public const S3_DOLLARS_PER_MILLION_CLASS_B = 0.4;

	/**
	 * Returns the current unix timestamp.
	 *
	 * @var Closure
	 */
	private readonly Closure $clock;

	/**
	 * Constructs the guard.
	 *
	 * @param int $bytesCeiling
	 *   Bytes per month the store may be sent, or 0 for no ceiling on that axis.
	 * @param float $dollarsCeiling
	 *   Dollars per month the store may cost, or 0.0 for no ceiling on that axis.
	 * @param float $dollarsPerGigabyteMonth
	 *   Storage price. Defaults to R2's.
	 * @param float $dollarsPerMillionClassA
	 *   Price per million billed writes: put, delete and list. Defaults to R2's.
	 * @param float $dollarsPerMillionClassB
	 *   Price per million billed reads: get and head. Defaults to R2's.
	 * @param callable|null $clock
	 *   Returns a unix timestamp as an int. NULL uses time(). Read only by
	 *   BudgetGuard::assessSince().
	 *
	 * @throws InvalidArgumentException
	 *   When a ceiling or a price is negative. A negative ceiling would put every reading above it
	 *   at once, and a negative price would let traffic buy budget back.
	 */
	public function __construct(
		private readonly int $bytesCeiling = 0,
		private readonly float $dollarsCeiling = 0.0,
		private readonly float $dollarsPerGigabyteMonth = self::R2_DOLLARS_PER_GIGABYTE_MONTH,
		private readonly float $dollarsPerMillionClassA = self::R2_DOLLARS_PER_MILLION_CLASS_A,
		private readonly float $dollarsPerMillionClassB = self::R2_DOLLARS_PER_MILLION_CLASS_B,
		?callable $clock = null,
	) {
		if ($bytesCeiling < 0) {
			throw new InvalidArgumentException(
				sprintf('A bytes ceiling cannot be negative, got %d', $bytesCeiling),
			);
		}
		if ($dollarsCeiling < 0.0) {
			throw new InvalidArgumentException(
				sprintf('A dollars ceiling cannot be negative, got %.4f', $dollarsCeiling),
			);
		}

		$prices = [
			'storage' => $dollarsPerGigabyteMonth,
			'class-A' => $dollarsPerMillionClassA,
			'class-B' => $dollarsPerMillionClassB,
		];
		foreach ($prices as $line => $price) {
			if ($price < 0.0) {
				throw new InvalidArgumentException(
					sprintf('The %s price cannot be negative, got %.4f', $line, $price),
				);
			}
		}

		$this->clock = $clock === null ? static fn(): int => time() : Closure::fromCallable($clock);
	}

	/**
	 * Reads a window of traffic and returns the rung it lands on.
	 *
	 * The window is extrapolated to a 30.44-day month. Storage is priced on what the store holds
	 * now, held for the whole month; requests are priced on the observed rate extrapolated over it.
	 * Growth is not compounded into the storage line, so the dollars figure is what the current
	 * shape of the site costs to keep rather than a forecast of where it is heading.
	 *
	 * @param int $storedBytes
	 *   What the store holds right now.
	 * @param ProviderStats $stats
	 *   Traffic observed over the window.
	 * @param int $secondsElapsed
	 *   How long the window was. Zero or less means no rate can be read from it, and the reading
	 *   comes back on the normal rung with nothing projected rather than dividing by zero.
	 *
	 * @return BudgetAssessment
	 *   The reading.
	 *
	 * @throws InvalidArgumentException
	 *   When $storedBytes is negative.
	 */
	public function assess(
		int $storedBytes,
		ProviderStats $stats,
		int $secondsElapsed,
	): BudgetAssessment {
		if ($storedBytes < 0) {
			throw new InvalidArgumentException(
				sprintf('Stored bytes cannot be negative, got %d', $storedBytes),
			);
		}

		if ($secondsElapsed <= 0) {
			return new BudgetAssessment(
				EscalationLadder::NORMAL,
				0.0,
				0,
				0.0,
				$this->bytesCeiling,
				$this->dollarsCeiling,
			);
		}

		$months = self::SECONDS_PER_MONTH / $secondsElapsed;
		$projectedBytes = self::saturate($stats->bytes() * $months);
		$projectedDollars = $this->dollarsFor($storedBytes, $stats, $months);

		$used = 0.0;
		$reason = null;

		if ($this->bytesCeiling > 0) {
			$used = $projectedBytes / $this->bytesCeiling;
			$reason = BudgetAssessment::AXIS_BYTES;
		}

		// the worse axis wins; a tie stays on bytes, which is the axis an operator set first
		$dollarsFraction =
			$this->dollarsCeiling > 0.0 ? $projectedDollars / $this->dollarsCeiling : 0.0;
		if ($this->dollarsCeiling > 0.0 && ($reason === null || $dollarsFraction > $used)) {
			$used = $dollarsFraction;
			$reason = BudgetAssessment::AXIS_DOLLARS;
		}

		return new BudgetAssessment(
			EscalationLadder::rungFor($used),
			$used,
			$projectedBytes,
			$projectedDollars,
			$this->bytesCeiling,
			$this->dollarsCeiling,
			$reason,
		);
	}

	/**
	 * Reads a window that started at a given time and runs to now.
	 *
	 * The default way to call the guard from a cron run or a status page, which knows when the
	 * counter was last reset but not how long ago that was. BudgetGuard::assess() takes the
	 * duration directly for a caller that already has it.
	 *
	 * @param int $windowStartedAt
	 *   Unix timestamp the accumulator was last reset at.
	 * @param int $storedBytes
	 *   What the store holds right now.
	 * @param ProviderStats $stats
	 *   Traffic observed since $windowStartedAt.
	 *
	 * @return BudgetAssessment
	 *   The reading.
	 *
	 * @throws InvalidArgumentException
	 *   When $storedBytes is negative.
	 * @throws UnexpectedValueException
	 *   When the injected clock does not return an int.
	 */
	public function assessSince(
		int $windowStartedAt,
		int $storedBytes,
		ProviderStats $stats,
	): BudgetAssessment {
		return $this->assess($storedBytes, $stats, $this->now() - $windowStartedAt);
	}

	/**
	 * What a month of this traffic costs.
	 *
	 * @param int $storedBytes
	 *   What the store holds right now.
	 * @param ProviderStats $stats
	 *   Traffic observed over the window.
	 * @param float $months
	 *   How many months the window extrapolates to.
	 *
	 * @return float
	 *   Dollars, storage and requests together.
	 */
	private function dollarsFor(int $storedBytes, ProviderStats $stats, float $months): float
	{
		$storage = ($storedBytes / self::BYTES_PER_GIGABYTE) * $this->dollarsPerGigabyteMonth;
		$classA = (($stats->classA() * $months) / 1_000_000) * $this->dollarsPerMillionClassA;
		$classB = (($stats->classB() * $months) / 1_000_000) * $this->dollarsPerMillionClassB;

		return $storage + $classA + $classB;
	}

	/**
	 * Narrows a projected byte count to an int without wrapping.
	 *
	 * A short window multiplies the observed volume by a large factor, and a projection past
	 * PHP_INT_MAX cast straight to int produces a number with no relationship to the input. Pinning
	 * it at the maximum keeps the reading above every ceiling, which is where a projection that
	 * large belongs.
	 *
	 * @param float $projected
	 *   The projected byte count.
	 *
	 * @return int
	 *   The count, at most PHP_INT_MAX.
	 */
	private static function saturate(float $projected): int
	{
		if (!is_finite($projected) || $projected >= (float) PHP_INT_MAX) {
			return PHP_INT_MAX;
		}

		return (int) round(max(0.0, $projected));
	}

	/**
	 * Reads the injected clock.
	 *
	 * @return int
	 *   A unix timestamp.
	 *
	 * @throws UnexpectedValueException
	 *   When the injected clock returns anything but an int. Caught here rather than left to the
	 *   subtraction, where a string clock would make every window look empty.
	 */
	private function now(): int
	{
		$now = ($this->clock)();

		if (!is_int($now)) {
			throw new UnexpectedValueException(
				sprintf('The injected clock must return an int, got %s', get_debug_type($now)),
			);
		}

		return $now;
	}
}
