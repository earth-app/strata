<?php

declare(strict_types=1);

namespace Drupal\strata\Health;

use Closure;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Stops a repair that keeps failing from being attempted forever.
 *
 * Keyed on the finding code, because that is the identifier that survives a class rename and is
 * the same key the ledger and the ladder use. A repair that has failed $threshold times in a row
 * is almost certainly failing for a reason retrying will not fix, and each retry costs the same
 * network round trip, the same lock, the same log line as the one before it.
 *
 * Three states, and only three:
 *
 * - closed: fewer than $threshold consecutive failures. Calls are allowed.
 * - open: the threshold was reached less than $cooldown seconds ago. Calls are refused.
 * - half-open: the cooldown has passed. One trial call is allowed; a failure re-opens the circuit
 *   for a fresh cooldown, and a success closes it.
 *
 * Only a recorded success clears the count. Time alone moves the circuit to half-open and no
 * further, so a permanently broken code makes exactly one attempt per cooldown instead of
 * flapping closed and hammering whatever it is failing against.
 *
 * The clock is injected. A test that had to sleep for a five-minute cooldown would not be a test
 * anyone runs, so time is a parameter here rather than a call to time() buried in a comparison.
 *
 * State is in memory and dies with the process; durable state is the ledger's job.
 *
 * @see HealthLedgerInterface
 * @see RepairLadder
 */
final class CircuitBreaker
{
	/**
	 * The circuit is closed: calls are allowed.
	 */
	public const CLOSED = 'closed';

	/**
	 * The circuit is open: calls are refused until the cooldown elapses.
	 */
	public const OPEN = 'open';

	/**
	 * The cooldown has elapsed: one trial call is allowed.
	 */
	public const HALF_OPEN = 'half-open';

	/**
	 * Consecutive failures that open the circuit.
	 *
	 * @var int
	 */
	private readonly int $threshold;

	/**
	 * Seconds the circuit stays open before a trial call is let through.
	 *
	 * @var int
	 */
	private readonly int $cooldown;

	/**
	 * Returns the current unix timestamp.
	 *
	 * @var Closure
	 */
	private readonly Closure $clock;

	/**
	 * Per-code failure count and the timestamp the circuit last opened at.
	 *
	 * @var array
	 */
	private array $tracked = [];

	/**
	 * Constructs the breaker.
	 *
	 * @param int $threshold
	 *   Consecutive failures that open the circuit.
	 * @param int $cooldown
	 *   Seconds the circuit stays open, measured from the failure that opened it.
	 * @param callable|null $clock
	 *   Returns a unix timestamp as an int. NULL uses time().
	 *
	 * @throws InvalidArgumentException
	 *   When the threshold is below 1, or the cooldown is negative. A threshold of 0 would open
	 *   every circuit before anything had failed.
	 */
	public function __construct(int $threshold = 3, int $cooldown = 300, ?callable $clock = null)
	{
		if ($threshold < 1) {
			throw new InvalidArgumentException(
				sprintf('CircuitBreaker threshold must be at least 1, got %d', $threshold),
			);
		}
		if ($cooldown < 0) {
			throw new InvalidArgumentException(
				sprintf('CircuitBreaker cooldown cannot be negative, got %d', $cooldown),
			);
		}

		$this->threshold = $threshold;
		$this->cooldown = $cooldown;
		$this->clock = $clock === null ? time(...) : $clock(...);
	}

	/**
	 * Failures a code may take before its circuit opens.
	 *
	 * @return int
	 *   The threshold.
	 */
	public function threshold(): int
	{
		return $this->threshold;
	}

	/**
	 * Seconds an open circuit stays open.
	 *
	 * @return int
	 *   The cooldown.
	 */
	public function cooldown(): int
	{
		return $this->cooldown;
	}

	#region Decisions

	/**
	 * Whether a repair for this code may be attempted now.
	 *
	 * @param string $code
	 *   The finding code the repair is for.
	 *
	 * @return bool
	 *   TRUE while closed or half-open, FALSE while open.
	 */
	public function allow(string $code): bool
	{
		return $this->state($code) !== self::OPEN;
	}

	/**
	 * The state of one code's circuit right now.
	 *
	 * Derived from the count and the clock on every call rather than stored, so a circuit cannot
	 * be left stale by nobody having asked about it during the cooldown.
	 *
	 * @param string $code
	 *   The finding code.
	 *
	 * @return string
	 *   CLOSED, OPEN or HALF_OPEN.
	 */
	public function state(string $code): string
	{
		$entry = $this->tracked[$code] ?? null;
		if ($entry === null || $entry['failures'] < $this->threshold) {
			return self::CLOSED;
		}

		return $this->now() - $entry['opened'] < $this->cooldown ? self::OPEN : self::HALF_OPEN;
	}

	#endregion

	#region Recording

	/**
	 * Records a failed attempt.
	 *
	 * Every failure at or past the threshold re-stamps the open time. That is what makes a failed
	 * trial call restart the cooldown instead of leaving the circuit half-open and retrying on
	 * every sweep from then on.
	 *
	 * @param string $code
	 *   The finding code the repair was for.
	 */
	public function recordFailure(string $code): void
	{
		$entry = $this->tracked[$code] ?? ['failures' => 0, 'opened' => 0];
		$entry['failures']++;

		if ($entry['failures'] >= $this->threshold) {
			$entry['opened'] = $this->now();
		}

		$this->tracked[$code] = $entry;
	}

	/**
	 * Records a successful attempt, closing the circuit.
	 *
	 * A success is the only thing that clears the count. Letting the cooldown alone clear it would
	 * turn a permanently broken code into one that retries $threshold times per cooldown.
	 *
	 * @param string $code
	 *   The finding code the repair was for.
	 */
	public function recordSuccess(string $code): void
	{
		$this->reset($code);
	}

	/**
	 * Forgets a code entirely, as if it had never failed.
	 *
	 * For an operator who has fixed the underlying cause and does not want to wait out a cooldown
	 * to find out. Not for the repair path; that reports what happened and lets the count decide.
	 *
	 * @param string $code
	 *   The finding code to forget.
	 */
	public function reset(string $code): void
	{
		unset($this->tracked[$code]);
	}

	#endregion

	/**
	 * Reads the injected clock.
	 *
	 * @return int
	 *   A unix timestamp.
	 *
	 * @throws UnexpectedValueException
	 *   When the injected clock returns anything but an int. Caught here rather than left to a
	 *   comparison, where a string clock would silently make every cooldown look elapsed.
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
