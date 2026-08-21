<?php

declare(strict_types=1);

namespace Drupal\strata\Restore;

use Drupal\strata\Journal\JournalOp;
use JsonSerializable;

/**
 * A subject somebody changed while the restore was being decided.
 *
 * **Not the edits a rollback is meant to discard.** Restoring a node to what it was on Tuesday
 * discards Wednesday and Thursday by definition; that is what was asked for and it is not a conflict.
 * The dangerous case is narrower: a plan is built, a person reads it, approves it, and in between
 * somebody edits one of the subjects it covers. The plan they approved no longer describes what is
 * there, and applying it overwrites an edit nobody saw.
 *
 * So the comparison is against the moment the plan was made, not against the restore target. On a
 * plan applied immediately there are no conflicts at all, which is correct; on one that sat waiting
 * for a second approval there may be several.
 *
 * Detected locally and for nothing: the subject index already records when each subject was last
 * written. No reads, no replay.
 *
 * **A conflict does not fail a restore.** The subject is skipped and named, the same shape as a
 * degraded one, unless the scope opted in to overwriting it.
 *
 * @see RestorePlan
 * @see Preflight
 */
final class Conflict implements JsonSerializable
{
	/**
	 * Constructs a conflict.
	 *
	 * @param string $subject
	 *   Subject path, such as "entity/node:42".
	 * @param int $changedAt
	 *   Unix microseconds the subject was last written.
	 * @param int $plannedAt
	 *   Unix microseconds the plan was built.
	 */
	public function __construct(
		public readonly string $subject,
		public readonly int $changedAt,
		public readonly int $plannedAt,
	) {}

	/**
	 * How long after the plan was made the change happened.
	 *
	 * @return float
	 *   Seconds.
	 */
	public function age(): float
	{
		return max(0, $this->changedAt - $this->plannedAt) / JournalOp::MICROSECONDS_PER_SECOND;
	}

	/**
	 * One line naming what a restore would overwrite.
	 *
	 * @return string
	 *   The description.
	 */
	public function describe(): string
	{
		return sprintf(
			'somebody changed it %s after this plan was made, so the plan no longer describes it',
			$this->duration(),
		);
	}

	/**
	 * The age in the largest unit that still reads naturally.
	 *
	 * @return string
	 *   Something such as "3 hours" or "12 seconds".
	 */
	public function duration(): string
	{
		$seconds = $this->age();

		return match (true) {
			$seconds < 60 => sprintf('%d seconds', (int) round($seconds)),
			$seconds < 3600 => sprintf('%d minutes', (int) round($seconds / 60)),
			$seconds < 86_400 => sprintf('%d hours', (int) round($seconds / 3600)),
			default => sprintf('%d days', (int) round($seconds / 86_400)),
		};
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The conflict as data.
	 */
	public function jsonSerialize(): array
	{
		return [
			'subject' => $this->subject,
			'changed_at' => $this->changedAt,
			'planned_at' => $this->plannedAt,
			'age_seconds' => round($this->age(), 3),
		];
	}
}
