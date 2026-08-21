<?php

declare(strict_types=1);

namespace Drupal\strata\Branch;

/**
 * What a three-way merge decided about one configuration object.
 *
 * Five cases rather than four. Unchanged, ours, theirs and conflict are the shape of any three-way
 * merge; MERGED is the one the key-by-key comparison earns. Two people editing different keys of the
 * same object produce a result that is neither side's value, and labelling it "taken from ours" or
 * "taken from theirs" would name a document that was never written on either side. A manifest an
 * operator approves has to say what it will actually write.
 *
 * @see ThreeWayMerge
 * @see MergeEntry
 */
enum MergeOutcome: string
{
	// neither side moved it, or both moved it to the same value
	case UNCHANGED = 'unchanged';

	// only the target changed it, so the branch has nothing to bring
	case OURS = 'ours';

	// only the branch changed it, so the branch's value is taken whole
	case THEIRS = 'theirs';

	// both changed it, in keys that do not overlap, so the result carries both edits
	case MERGED = 'merged';

	// both changed the same key to different values, and nothing here picks a winner
	case CONFLICT = 'conflict';

	/**
	 * A short label for the confirm form and the audit log.
	 *
	 * Never parse this back; MergeOutcome::from() reads the case value.
	 *
	 * @return string
	 *   The label.
	 */
	public function label(): string
	{
		return match ($this) {
			self::UNCHANGED => 'Unchanged',
			self::OURS => 'Kept',
			self::THEIRS => 'Taken From the Branch',
			self::MERGED => 'Merged',
			self::CONFLICT => 'Conflict',
		};
	}

	/**
	 * Whether applying the merge writes this object back to the site.
	 *
	 * An object neither side moved is already what the merge would write, and an object only the
	 * target changed is the target's own value; writing either would spend a save to change nothing
	 * and would put a spurious entry in the site's own history.
	 *
	 * @return bool
	 *   TRUE for MergeOutcome::THEIRS and MergeOutcome::MERGED.
	 */
	public function isWritten(): bool
	{
		return $this === self::THEIRS || $this === self::MERGED;
	}

	/**
	 * Whether this outcome stops a plan being applied until somebody decides.
	 *
	 * @return bool
	 *   TRUE for MergeOutcome::CONFLICT.
	 */
	public function isConflict(): bool
	{
		return $this === self::CONFLICT;
	}
}
