<?php

declare(strict_types=1);

namespace Drupal\strata\Health;

/**
 * The escalation ladder, and the rule about which rungs a machine is allowed to take.
 *
 * Rungs run lowest-first, cheapest and most reversible first. A repair only ever moves one rung at
 * a time, in either direction, so a code that keeps failing climbs at a rate an operator can watch
 * and a code that goes quiet walks back down on its own.
 *
 * The line that matters sits between rebuild and quarantine. Everything at or below rebuild
 * reconstructs derived state from data that still exists, so the worst case of running it wrongly
 * is wasted work. Quarantine removes a restore target and refuse blocks a restore outright; both
 * take away the thing a backup exists to provide, which is a decision a human makes, not cron.
 *
 * @see Finding
 * @see CircuitBreaker
 */
final class RepairLadder
{
	/**
	 * The rungs, lowest first.
	 */
	public const RUNGS = ['observe', 'reindex', 'refetch', 'rebuild', 'quarantine', 'refuse'];

	/**
	 * Highest rung an unattended run may take by itself.
	 */
	public const AUTOMATIC_CEILING = 'rebuild';

	/**
	 * Rank returned for a rung that is not on the ladder.
	 */
	public const UNRANKED = -1;

	/**
	 * Where a finding of this severity starts.
	 *
	 * Read with >= rather than ==, so an ordinal above CRITICAL - a caller inventing its own scale,
	 * or a value read back from a newer schema - lands on quarantine instead of falling through to
	 * observe. Being wrong upward costs a paused code; being wrong downward costs a restore.
	 *
	 * @param int $severity
	 *   One of the Finding severity ordinals.
	 *
	 * @return string
	 *   A rung name from RUNGS.
	 */
	public static function initialRung(int $severity): string
	{
		if ($severity >= Finding::CRITICAL) {
			return 'quarantine';
		}
		if ($severity >= Finding::ERROR) {
			return 'reindex';
		}

		return self::RUNGS[0];
	}

	/**
	 * One rung up, saturating at the top.
	 *
	 * Saturation rather than an exception, because escalation runs on the failure path: a code that
	 * has already reached refuse and fails again should stay at refuse, not throw on top of the
	 * failure that got it there.
	 *
	 * @param string $rung
	 *   Current rung.
	 *
	 * @return string
	 *   The next rung up, the same rung at the top, or the lowest rung when $rung is not on the
	 *   ladder at all.
	 */
	public static function escalate(string $rung): string
	{
		$at = self::rank($rung);
		if ($at === self::UNRANKED) {
			return self::RUNGS[0];
		}

		return self::RUNGS[min($at + 1, count(self::RUNGS) - 1)];
	}

	/**
	 * One rung down, or NULL at the bottom.
	 *
	 * NULL is the signal to stop tracking the code entirely rather than to park it at observe
	 * forever; a ledger that never forgets a resolved code grows without limit.
	 *
	 * @param string $rung
	 *   Current rung.
	 *
	 * @return string|null
	 *   The rung below, or NULL when already at the bottom or not on the ladder.
	 */
	public static function decay(string $rung): ?string
	{
		$at = self::rank($rung);
		if ($at <= 0) {
			return null;
		}

		return self::RUNGS[$at - 1];
	}

	/**
	 * Whether an unattended run may take this rung without asking.
	 *
	 * Fails closed: a rung this class does not recognise is not automatic. The cost of refusing a
	 * repair is a finding that stays in the ledger until someone looks; the cost of running
	 * quarantine unattended is a restore target that is gone when it is needed.
	 *
	 * @param string $rung
	 *   The rung being considered.
	 *
	 * @return bool
	 *   TRUE for observe, reindex, refetch and rebuild. FALSE for quarantine, refuse, and anything
	 *   not on the ladder.
	 */
	public static function isAutomatic(string $rung): bool
	{
		$at = self::rank($rung);

		return $at !== self::UNRANKED && $at <= self::rank(self::AUTOMATIC_CEILING);
	}

	/**
	 * The position of a rung on the ladder.
	 *
	 * @param string $rung
	 *   The rung name.
	 *
	 * @return int
	 *   Its index in RUNGS, or UNRANKED when the name is not a rung.
	 */
	public static function rank(string $rung): int
	{
		$at = array_search($rung, self::RUNGS, true);

		return $at === false ? self::UNRANKED : $at;
	}
}
