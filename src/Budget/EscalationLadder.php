<?php

declare(strict_types=1);

namespace Drupal\strata\Budget;

use Drupal\strata\Journal\Realm;

/**
 * What Strata gives up as a budget fills, and the order it gives it up in.
 *
 * Rungs run lowest first, cheapest and most reversible first, and a reading only ever moves one
 * rung at a time in either direction. A budget that keeps overshooting climbs at a rate an operator
 * can watch, and one that comes back under walks down on its own instead of staying clamped until
 * somebody notices.
 *
 * - normal: under budget, nothing changes.
 * - warn: over the soft fraction. Logged and surfaced; capture and retention are untouched.
 * - reduce: retention shortens, so compaction prunes more history on each pass.
 * - pause: the non-critical realms stop being captured. Entity, config, state, key-value, table and
 *   schema keep going.
 * - stop: nothing is captured at all and the journal stops accepting appends.
 *
 * The line that matters sits between reduce and pause. Everything at or below reduce keeps every
 * realm covered and only trades away depth of history, so the worst case is a shorter window to
 * restore from. Pause and stop drop data that is never captured and therefore can never be
 * restored, which is the ladder giving up the thing a backup exists to provide.
 *
 * The realms that go first are the ones a site can rebuild from somewhere else: files usually exist
 * in the media library's own storage, ephemeral state rebuilds itself, and code comes from the
 * release artifact. What stays is what only the database holds.
 *
 * @see BudgetGuard
 * @see Realm
 */
final class EscalationLadder
{
	/**
	 * Under budget.
	 */
	public const NORMAL = 'normal';

	/**
	 * Over the soft fraction; reported and nothing else.
	 */
	public const WARN = 'warn';

	/**
	 * Retention shortens and compaction prunes harder.
	 */
	public const REDUCE = 'reduce';

	/**
	 * The non-critical realms stop being captured.
	 */
	public const PAUSE = 'pause';

	/**
	 * Nothing is captured and the journal refuses appends.
	 */
	public const STOP = 'stop';

	/**
	 * The rungs, lowest first.
	 */
	public const RUNGS = [self::NORMAL, self::WARN, self::REDUCE, self::PAUSE, self::STOP];

	/**
	 * Rank returned for a rung that is not on the ladder.
	 */
	public const UNRANKED = -1;

	/**
	 * The realms capture drops at the pause rung.
	 *
	 * Held as realm values rather than cases so a rung read back out of state can be compared
	 * without being parsed first.
	 */
	public const PAUSED_REALMS = [Realm::FILE->value, Realm::EPHEMERAL->value, Realm::CODE->value];

	/**
	 * Fraction of budget at which the warn rung starts.
	 */
	public const WARN_AT = 0.8;

	/**
	 * Fraction of budget at which the reduce rung starts; the ceiling itself.
	 */
	public const REDUCE_AT = 1.0;

	/**
	 * Fraction of budget at which the pause rung starts.
	 */
	public const PAUSE_AT = 1.25;

	/**
	 * Fraction of budget at which the stop rung starts.
	 */
	public const STOP_AT = 1.5;

	/**
	 * The rung a projected usage lands on.
	 *
	 * Read with >= at every step, so a fraction above STOP_AT lands on stop rather than falling
	 * through. A negative fraction, or one that is not a number at all, lands on normal: neither is
	 * evidence of overspending, and neither should stop a backup.
	 *
	 * @param float $usedFraction
	 *   Projected usage divided by the ceiling. 1.0 is exactly the ceiling.
	 *
	 * @return string
	 *   A rung name from RUNGS.
	 */
	public static function rungFor(float $usedFraction): string
	{
		if ($usedFraction >= self::STOP_AT) {
			return self::STOP;
		}
		if ($usedFraction >= self::PAUSE_AT) {
			return self::PAUSE;
		}
		if ($usedFraction >= self::REDUCE_AT) {
			return self::REDUCE;
		}
		if ($usedFraction >= self::WARN_AT) {
			return self::WARN;
		}

		return self::NORMAL;
	}

	/**
	 * One rung up, saturating at the top.
	 *
	 * Saturation rather than an exception, because escalation runs on the path where a budget has
	 * already been exceeded; throwing there adds a failure on top of the one being reported.
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
	 * NULL is the signal to stop tracking the budget state entirely rather than to store normal
	 * forever; a site that has never been near its ceiling should carry no rung at all.
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
	 * Whether capture of one realm is suspended at this rung.
	 *
	 * An unrecognised rung suspends nothing. The ladder is driven by BudgetGuard, which only ever
	 * produces names from RUNGS, so the only way to arrive here with something else is state that
	 * has been corrupted or written by a version that disagrees; stopping a backup on the strength
	 * of that costs more than the overspend it might have prevented.
	 *
	 * @param string $rung
	 *   The rung in force.
	 * @param string $realm
	 *   A realm value, as Realm stores it.
	 *
	 * @return bool
	 *   TRUE when nothing in that realm should be captured.
	 */
	public static function pausesRealm(string $rung, string $realm): bool
	{
		if (self::stopsEverything($rung)) {
			return true;
		}

		return $rung === self::PAUSE && in_array($realm, self::PAUSED_REALMS, true);
	}

	/**
	 * Whether this rung stops capture outright.
	 *
	 * @param string $rung
	 *   The rung in force.
	 *
	 * @return bool
	 *   TRUE only at STOP.
	 */
	public static function stopsEverything(string $rung): bool
	{
		return $rung === self::STOP;
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
