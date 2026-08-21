<?php

declare(strict_types=1);

namespace Drupal\strata\Branch;

/**
 * What a merge does with a key both sides changed.
 *
 * **There is no silent default.** Every caller names one, and the one it names by default refuses,
 * because a conflict is two people having written different answers to the same question and picking
 * between them without being asked is how a merge destroys the edit nobody was shown.
 *
 * Resolution is per key, not per object. A strategy applies only to the keys that actually collide;
 * every other key still merges as it would have, so choosing `ours` on an object with one collision
 * does not throw away the branch's other edits to the same object.
 *
 * @see ThreeWayMerge
 * @see MergePlan
 */
enum MergeStrategy: string
{
	// a conflicting key is left unresolved and the plan cannot be applied
	case REFUSE = 'refuse';

	// a conflicting key keeps the value the target already has
	case OURS = 'ours';

	// a conflicting key takes the value from the branch
	case THEIRS = 'theirs';

	/**
	 * A short label for the confirm form and the command's output.
	 *
	 * Never parse this back; MergeStrategy::from() reads the case value.
	 *
	 * @return string
	 *   The label.
	 */
	public function label(): string
	{
		return match ($this) {
			self::REFUSE => 'Refuse',
			self::OURS => 'Keep Ours',
			self::THEIRS => 'Take Theirs',
		};
	}

	/**
	 * One sentence saying what this strategy will do.
	 *
	 * @return string
	 *   The description.
	 */
	public function describe(): string
	{
		return match ($this) {
			self::REFUSE
				=> 'a key both sides changed stops the merge and is listed with both values',
			self::OURS => 'a key both sides changed keeps the value the target already has',
			self::THEIRS => 'a key both sides changed takes the value from the branch',
		};
	}

	/**
	 * Whether this strategy leaves a conflict for a person to decide.
	 *
	 * @return bool
	 *   TRUE for MergeStrategy::REFUSE.
	 */
	public function isRefusal(): bool
	{
		return $this === self::REFUSE;
	}

	/**
	 * The strategy a name asks for.
	 *
	 * @param string|null $name
	 *   The case value, or NULL for the default.
	 *
	 * @return self
	 *   The strategy, refusing by default and for a name this release does not know. A misspelled
	 *   strategy resolving to "take theirs" would overwrite the target with a typo.
	 */
	public static function named(?string $name): self
	{
		return self::tryFrom(strtolower(trim((string) $name))) ?? self::REFUSE;
	}
}
