<?php

declare(strict_types=1);

namespace Drupal\strata\Restore;

use Drupal\strata\Health\HealthLedgerInterface;
use Drupal\strata\Health\RepairLadder;

/**
 * Says whether a person has taken a restore target, or every restore, out of use.
 *
 * The two rungs above the automatic ceiling are decisions a human makes about restoring, and a rung
 * nothing reads is a label rather than a decision. This is the reader. A finding whose code sits at
 * `quarantine` and whose scope is a commit takes that commit out of use as a restore target, and a
 * code at `refuse` stops every restore while one of its findings is open. The bytes are never
 * touched, so releasing a code puts everything back.
 *
 * A quarantine names a commit, not the frames behind it. A frame a commit needs and cannot read is
 * already reported by the preflight as degraded or unrestorable, subject by subject, which is the
 * more precise answer.
 *
 * @see RepairLadder
 * @see LogicalRestore
 * @see PhysicalRestore
 */
final class RestoreHold
{
	/**
	 * Shortest commit abbreviation that may name a quarantined commit.
	 */
	private const MIN_ABBREVIATION = 8;

	/**
	 * Constructs a hold.
	 *
	 * @param HealthLedgerInterface $ledger
	 *   Holds the findings and the rung each code sits on.
	 */
	public function __construct(private readonly HealthLedgerInterface $ledger) {}

	/**
	 * Why a restore to this commit may not run, if it may not.
	 *
	 * @param string $target
	 *   The commit id, or an abbreviation of at least eight characters.
	 *
	 * @return string|null
	 *   A sentence naming the finding that holds it, or NULL when nothing does.
	 */
	public function reason(string $target): ?string
	{
		$rungs = [];

		foreach ($this->ledger->open() as $finding) {
			$rung = $rungs[$finding->code] ??= $this->ledger->rungFor($finding->code);

			if ($rung === 'refuse') {
				return sprintf(
					'restores are refused while %s is held at refuse: %s',
					$finding->code,
					$finding->context,
				);
			}

			if ($rung === 'quarantine' && $this->names($finding->scope, $target)) {
				return sprintf(
					'commit %s is quarantined by %s: %s',
					substr($finding->scope, 0, 12),
					$finding->code,
					$finding->context,
				);
			}
		}

		return null;
	}

	/**
	 * Whether a finding's scope is this commit.
	 *
	 * @param string $scope
	 *   The finding's scope.
	 * @param string $target
	 *   The commit a restore asked for.
	 *
	 * @return bool
	 *   TRUE when they are the same commit.
	 */
	private function names(string $scope, string $target): bool
	{
		return $scope === $target ||
			(strlen($target) >= self::MIN_ABBREVIATION && str_starts_with($scope, $target));
	}
}
