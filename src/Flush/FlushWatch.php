<?php

declare(strict_types=1);

namespace Drupal\strata\Flush;

use Drupal\strata\Health\HealthLedgerInterface;
use Drupal\strata\Health\TripwireRegistry;

/**
 * Turns the recovery-point lag into a finding, and clears it once a flush catches up.
 *
 * @see FlushStale
 */
final class FlushWatch
{
	/**
	 * Finding code this watch raises and clears.
	 */
	public const CODE = 'flush.stale';

	/**
	 * A day, so a cron that runs hourly has failed to seal a window twenty-four times in a row.
	 */
	public const DEFAULT_STALE_AFTER = 86400;

	/**
	 * Constructs a watch.
	 *
	 * @param TripwireRegistry $tripwires
	 *   Holds the stale-flush tripwire.
	 * @param HealthLedgerInterface $ledger
	 *   Where the finding is recorded and resolved.
	 * @param int $staleAfter
	 *   Seconds the oldest unsealed change may wait, or 0 to switch the watch off.
	 */
	public function __construct(
		private readonly TripwireRegistry $tripwires,
		private readonly HealthLedgerInterface $ledger,
		private readonly int $staleAfter,
	) {}

	/**
	 * Reads one lag value and raises or clears the finding.
	 *
	 * @param float $lag
	 *   Seconds since the oldest unsealed operation, 0.0 when nothing is pending.
	 *
	 * @return bool
	 *   TRUE when the finding is open after this reading.
	 */
	public function check(float $lag): bool
	{
		$findings = $this->tripwires->evaluate([
			'flush_lag' => $lag,
			'flush_stale_after' => $this->staleAfter,
		]);

		foreach ($findings as $finding) {
			$this->ledger->record($finding);
		}

		if ($findings === []) {
			$this->ledger->resolve(self::CODE, 'flush');
		}

		return $findings !== [];
	}
}
