<?php

declare(strict_types=1);

namespace Drupal\strata\Health;

/**
 * What one repair pass did.
 *
 * @see RepairPass
 */
final class RepairReport
{
	/**
	 * Constructs the report.
	 *
	 * @param int $considered
	 *   Distinct finding codes the pass looked at.
	 * @param int $repaired
	 *   Codes whose pass ran and returned.
	 * @param int $failed
	 *   Codes whose pass raised, and which have therefore climbed a rung.
	 * @param int $cleared
	 *   Open findings resolved before the passes ran.
	 * @param int $held
	 *   Codes left alone because their rung needs a person, or the breaker is open on them.
	 * @param list<string> $ran
	 *   One line per pass that executed, in the order they ran.
	 */
	public function __construct(
		public readonly int $considered = 0,
		public readonly int $repaired = 0,
		public readonly int $failed = 0,
		public readonly int $cleared = 0,
		public readonly int $held = 0,
		public readonly array $ran = [],
	) {}

	/**
	 * One line naming what happened, for a log, a Drush run or a form message.
	 *
	 * @return string
	 *   The summary.
	 */
	public function summary(): string
	{
		if ($this->considered === 0) {
			return 'Nothing is open that an unattended repair may act on.';
		}

		return sprintf(
			'%d code%s considered: %d repaired, %d failed, %d held for a person; %d finding%s cleared.',
			$this->considered,
			$this->considered === 1 ? '' : 's',
			$this->repaired,
			$this->failed,
			$this->held,
			$this->cleared,
			$this->cleared === 1 ? '' : 's',
		);
	}
}
