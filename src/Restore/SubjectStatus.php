<?php

declare(strict_types=1);

namespace Drupal\strata\Restore;

/**
 * How completely one subject can be put back.
 *
 * Three states rather than two, because "we cannot restore this perfectly" and "we cannot restore
 * this at all" call for different answers and collapsing them produces the worst outcome of the
 * three: a restore that writes a partial value over a live one and reports success.
 *
 * @see Preflight
 * @see RestorePlan
 */
enum SubjectStatus: string
{
	// every version needed is present and decodable
	case RESTORABLE = 'restorable';

	// some versions read and some did not, so the value reconstructs only in part
	case DEGRADED = 'degraded';

	// nothing usable survives, so there is no value to write
	case UNRESTORABLE = 'unrestorable';

	/**
	 * A short label for the confirm form and the audit log.
	 *
	 * Never parse this back; SubjectStatus::from() reads the case value.
	 *
	 * @return string
	 *   The label.
	 */
	public function label(): string
	{
		return match ($this) {
			self::RESTORABLE => 'Restorable',
			self::DEGRADED => 'Degraded',
			self::UNRESTORABLE => 'Unrestorable',
		};
	}

	/**
	 * Whether a default restore writes this subject back.
	 *
	 * Only a fully restorable subject is written by default. A degraded one is listed and skipped
	 * unless the scope opts in, because replacing a live value with a partial reconstruction turns
	 * a recovery into a second incident. Unknown beats incorrect.
	 *
	 * @return bool
	 *   TRUE only for SubjectStatus::RESTORABLE.
	 */
	public function isWrittenByDefault(): bool
	{
		return $this === self::RESTORABLE;
	}

	/**
	 * Whether any value at all can be produced for this subject.
	 *
	 * @return bool
	 *   TRUE unless nothing survives.
	 */
	public function hasValue(): bool
	{
		return $this !== self::UNRESTORABLE;
	}
}
