<?php

declare(strict_types=1);

namespace Drupal\strata\Health\Tripwire;

use Drupal\strata\Capture\Classifier\KeyspaceDiscovery;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;

/**
 * Too much of the ephemeral keyspace is being captured without anyone knowing what it is.
 *
 * Unclassified keys are captured on purpose: an unrecognised key is a gap in the heuristics rather
 * than evidence it does not matter, and dropping it would create a silent hole. But that default is
 * safe only while the unclassified share stays small. A newly installed module that names its cache
 * bin unconventionally can put millions of derivable keys into the backup, and the first sign would
 * otherwise be the storage bill.
 *
 * A warning, not an error: nothing stored is wrong, and the fix is a person spending a minute on the
 * classification page.
 *
 * @see KeyspaceDiscovery
 */
final class UnclassifiedGrowth implements TripwireInterface
{
	/**
	 * Share of the discovered keyspace that may be undecided before this fires.
	 */
	public const SHARE = 0.25;

	/**
	 * Keys below which the share is not worth reporting.
	 *
	 * On a keyspace of a dozen keys any share is noise, and a fresh install has almost nothing in
	 * it.
	 */
	public const FLOOR = 100;

	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'classification.unclassified_growth';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$total = (int) ($observation['total_keys'] ?? 0);
		$undecided = (int) ($observation['unclassified_keys'] ?? 0);

		if ($total < self::FLOOR || $undecided < 1) {
			return null;
		}

		$share = $undecided / $total;

		if ($share <= self::SHARE) {
			return null;
		}

		return new Finding(
			$this->code(),
			Finding::WARN,
			'keyspace',
			sprintf(
				'%d of %d ephemeral keys (%.1f%%) across %d patterns are captured without a ' .
					'classification',
				$undecided,
				$total,
				$share * 100,
				(int) ($observation['unclassified_patterns'] ?? 0),
			),
		);
	}
}
