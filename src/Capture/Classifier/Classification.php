<?php

declare(strict_types=1);

namespace Drupal\strata\Capture\Classifier;

/**
 * What a piece of ephemeral state is worth backing up.
 *
 * Caches, sessions, locks, queues and the rest of the ephemeral keyspace are not one thing. Some of
 * it is the only copy of something a site needs - a queue holding work nobody else has recorded, a
 * flood counter enforcing a lockout, a session someone is logged in with. Most of it is a derived
 * copy of data that lives somewhere else and rebuilds itself the moment it is asked for.
 *
 * Backing up the second kind costs storage to store something whose correct value five minutes from
 * now is different, and restoring it writes a stale entry over a live one. Backing up the first kind
 * is the difference between a restore that works and a restore that loses a day of queued work.
 *
 * Three states rather than two, because the third is what keeps this honest. Anything the heuristics
 * cannot place is UNCLASSIFIED, which means captured verbatim and surfaced for a human to decide -
 * never quietly dropped. A backup system that silently skips what it does not recognise is a backup
 * system with gaps nobody knows about.
 *
 * @see Heuristics
 * @see ClassificationRegistry
 */
enum Classification: string
{
	// the only copy; losing it loses something, so it is captured and restored
	case AUTHORITATIVE = 'authoritative';

	// a derived copy that rebuilds itself, so it is neither captured nor restored
	case DERIVABLE = 'derivable';

	// nothing recognised it, so it is captured verbatim and flagged for a decision
	case UNCLASSIFIED = 'unclassified';

	/**
	 * A short label for the admin UI and the audit log.
	 *
	 * Never parse this back; Classification::from() reads the case value.
	 *
	 * @return string
	 *   The label.
	 */
	public function label(): string
	{
		return match ($this) {
			self::AUTHORITATIVE => 'Authoritative',
			self::DERIVABLE => 'Derivable',
			self::UNCLASSIFIED => 'Unclassified',
		};
	}

	/**
	 * Whether keys under this classification are captured.
	 *
	 * Unclassified is captured. That is the whole point of having a third state: an unrecognised
	 * key is a gap in the heuristics, not a gap in what matters, and the cost of storing it until
	 * someone decides is far lower than the cost of finding out it mattered after a restore.
	 *
	 * @return bool
	 *   TRUE for everything except Classification::DERIVABLE.
	 */
	public function isCaptured(): bool
	{
		return $this !== self::DERIVABLE;
	}

	/**
	 * Whether a restore writes keys under this classification back.
	 *
	 * Only what is known to be authoritative. An unclassified key is stored so it can be examined
	 * and so nothing is lost, but writing an unrecognised ephemeral value over a live one during a
	 * restore is the kind of help nobody asked for.
	 *
	 * @return bool
	 *   TRUE only for Classification::AUTHORITATIVE.
	 */
	public function isRestored(): bool
	{
		return $this === self::AUTHORITATIVE;
	}

	/**
	 * Whether a human still has to look at this.
	 *
	 * @return bool
	 *   TRUE when the classification is a placeholder rather than a decision.
	 */
	public function needsDecision(): bool
	{
		return $this === self::UNCLASSIFIED;
	}
}
