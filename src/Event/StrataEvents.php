<?php

declare(strict_types=1);

namespace Drupal\strata\Event;

/**
 * Every event this module dispatches, named once.
 *
 * The names are part of the public API: a site subscribes to them, a webhook subscription lists
 * them, and both survive an upgrade. So they are dotted, prefixed, and never renamed - a new
 * meaning gets a new constant rather than a redefined one.
 *
 * @see CommitEvent
 * @see RestoreEvent
 * @see HealthEvent
 * @see BudgetEvent
 */
final class StrataEvents
{
	/**
	 * A flush sealed a window into a commit.
	 *
	 * @Event
	 *
	 * @see CommitEvent
	 */
	public const COMMIT_SEALED = 'strata.commit.sealed';

	/**
	 * A restore finished, whether it succeeded, was refused or failed.
	 *
	 * @Event
	 *
	 * @see RestoreEvent
	 */
	public const RESTORE_FINISHED = 'strata.restore.finished';

	/**
	 * A tripwire recorded a finding.
	 *
	 * @Event
	 *
	 * @see HealthEvent
	 */
	public const HEALTH_FINDING = 'strata.health.finding';

	/**
	 * Stored bytes or projected spend crossed a configured ceiling.
	 *
	 * @Event
	 *
	 * @see BudgetEvent
	 */
	public const BUDGET_BREACHED = 'strata.budget.breached';

	/**
	 * A prune destroyed restore points.
	 *
	 * @Event
	 *
	 * @see PruneEvent
	 */
	public const PRUNE_APPLIED = 'strata.prune.applied';

	/**
	 * A restore drill finished.
	 *
	 * @Event
	 *
	 * @see DrillEvent
	 */
	public const DRILL_FINISHED = 'strata.drill.finished';

	/**
	 * Every event name, in the order an operator would read them.
	 *
	 * Read by the webhook subscription form, so a new event appears there without the form being
	 * edited.
	 *
	 * @return list<string>
	 *   The names.
	 */
	public static function all(): array
	{
		return [
			self::COMMIT_SEALED,
			self::RESTORE_FINISHED,
			self::HEALTH_FINDING,
			self::BUDGET_BREACHED,
			self::PRUNE_APPLIED,
			self::DRILL_FINISHED,
		];
	}

	/**
	 * Whether a name is one this release dispatches.
	 *
	 * @param string $name
	 *   The name to check.
	 *
	 * @return bool
	 *   TRUE when it is known.
	 */
	public static function has(string $name): bool
	{
		return in_array($name, self::all(), true);
	}
}
