<?php

declare(strict_types=1);

namespace Drupal\strata\Health;

use Closure;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * A health ledger held in process memory.
 *
 * This is the ledger a single run uses: a cron sweep that records what it found, decides what to
 * do about it and reports at the end, all inside one request. It keeps no state between processes,
 * which makes it the right thing for tests and for a one-shot sweep and the wrong thing for
 * anything that has to remember across a deploy.
 *
 * What it does share with a durable ledger is the bound. Retention is capped per code, at
 * MAX_PER_CODE by default, for the same reason Finding clamps its context: the unbounded field
 * here is scope - every frame hash is a new one - and a code that flaps produces a new scope on
 * every pass. Trusting a purge to run is how a table nobody looks at becomes the largest thing in
 * the database. When the cap is reached the oldest finding for that code is dropped, because the
 * most recent occurrences are the ones that describe what is happening now.
 *
 * @see HealthLedgerInterface
 * @see Finding
 */
final class MemoryHealthLedger implements HealthLedgerInterface
{
	/**
	 * Findings retained per code before the oldest is dropped.
	 */
	public const MAX_PER_CODE = 50;

	/**
	 * How many findings to keep per code.
	 *
	 * @var int
	 */
	private readonly int $maxPerCode;

	/**
	 * Returns the current unix timestamp.
	 *
	 * @var Closure
	 */
	private readonly Closure $clock;

	/**
	 * Retained findings, keyed by code, oldest first within each code.
	 *
	 * @var array<string, list<array{finding: Finding, at: int, seq: int}>>
	 */
	private array $entries = [];

	/**
	 * The rung each code has been explicitly moved to.
	 *
	 * @var array<string, string>
	 */
	private array $rungs = [];

	/**
	 * Monotonic counter giving every recorded finding a total order.
	 *
	 * Timestamps tie constantly - a sweep records everything it found in the same second - so the
	 * clock alone cannot order the ledger for a reader.
	 *
	 * @var int
	 */
	private int $sequence = 0;

	/**
	 * Constructs the ledger.
	 *
	 * @param int $maxPerCode
	 *   Findings retained per code.
	 * @param callable|null $clock
	 *   Returns a unix timestamp as an int. NULL uses time().
	 *
	 * @throws InvalidArgumentException
	 *   When $maxPerCode is below 1, which would make record() a no-op that still reported success.
	 */
	public function __construct(int $maxPerCode = self::MAX_PER_CODE, ?callable $clock = null)
	{
		if ($maxPerCode < 1) {
			throw new InvalidArgumentException(
				sprintf('MemoryHealthLedger retention must be at least 1, got %d', $maxPerCode),
			);
		}

		$this->maxPerCode = $maxPerCode;
		$this->clock = $clock === null ? time(...) : $clock(...);
	}

	#region Findings

	/**
	 * {@inheritdoc}
	 */
	public function record(Finding $finding): void
	{
		$this->sequence++;
		$this->entries[$finding->code][] = [
			'finding' => $finding,
			'at' => $this->now(),
			'seq' => $this->sequence,
		];

		if (count($this->entries[$finding->code]) > $this->maxPerCode) {
			$this->entries[$finding->code] = array_slice(
				$this->entries[$finding->code],
				-$this->maxPerCode,
			);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function open(): array
	{
		$ordered = [];
		foreach ($this->entries as $rows) {
			foreach ($rows as $row) {
				$ordered[$row['seq']] = $row['finding'];
			}
		}
		ksort($ordered);

		return array_values($ordered);
	}

	/**
	 * {@inheritdoc}
	 */
	public function resolve(string $code, string $scope): int
	{
		$rows = $this->entries[$code] ?? null;
		if ($rows === null) {
			return 0;
		}

		$kept = array_values(
			array_filter($rows, static fn(array $row): bool => $row['finding']->scope !== $scope),
		);
		$cleared = count($rows) - count($kept);

		$this->settle($code, $kept);

		return $cleared;
	}

	/**
	 * {@inheritdoc}
	 */
	public function purge(int $olderThan): int
	{
		$dropped = 0;

		foreach ($this->entries as $code => $rows) {
			$kept = array_values(
				array_filter($rows, static fn(array $row): bool => $row['at'] >= $olderThan),
			);
			$dropped += count($rows) - count($kept);
			$this->settle($code, $kept);
		}

		return $dropped;
	}

	#endregion

	#region Rungs

	/**
	 * {@inheritdoc}
	 *
	 * With no rung set explicitly, the answer is derived from the worst severity recorded for the
	 * code, so a caller that has only ever recorded findings still gets a usable starting rung
	 * instead of having to seed one first. A code with nothing recorded sits at the bottom.
	 */
	public function rungFor(string $code): string
	{
		if (isset($this->rungs[$code])) {
			return $this->rungs[$code];
		}

		$rows = $this->entries[$code] ?? [];
		if ($rows === []) {
			return RepairLadder::RUNGS[0];
		}

		$worst = Finding::INFO;
		foreach ($rows as $row) {
			$worst = max($worst, $row['finding']->severity);
		}

		return RepairLadder::initialRung($worst);
	}

	/**
	 * {@inheritdoc}
	 *
	 * An unknown rung is refused rather than stored, because RepairLadder ranks a name it does not
	 * know as UNRANKED and isAutomatic() then refuses it forever - a typo would quietly park the
	 * code where no repair ever runs.
	 */
	public function setRung(string $code, string $rung): void
	{
		if (RepairLadder::rank($rung) === RepairLadder::UNRANKED) {
			throw new InvalidArgumentException(sprintf('"%s" is not a rung on the ladder', $rung));
		}

		$this->rungs[$code] = $rung;
	}

	#endregion

	/**
	 * Writes back what is left of a code's findings, forgetting the code when nothing is.
	 *
	 * The rung goes with the last finding. A code with nothing outstanding is one the caller has
	 * stopped tracking, which is the same thing RepairLadder::decay() signals with NULL at the
	 * bottom of the ladder.
	 *
	 * @param string $code
	 *   The finding code.
	 * @param array $kept
	 *   The rows that survived, in order.
	 */
	private function settle(string $code, array $kept): void
	{
		if ($kept === []) {
			unset($this->entries[$code], $this->rungs[$code]);

			return;
		}

		$this->entries[$code] = $kept;
	}

	/**
	 * Reads the injected clock.
	 *
	 * @return int
	 *   A unix timestamp.
	 *
	 * @throws UnexpectedValueException
	 *   When the injected clock returns anything but an int, which would otherwise reach purge()
	 *   as a comparison that quietly keeps or drops everything.
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
