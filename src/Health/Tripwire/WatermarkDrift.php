<?php

declare(strict_types=1);

namespace Drupal\strata\Health\Tripwire;

use Drupal\strata\Capture\Reconciler;
use Drupal\strata\Capture\Watermark;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;

/**
 * A table changed without anything capturing the change.
 *
 * The coverage proof. Every other tripwire reports something wrong with what was stored; this one
 * reports that something was NOT stored, which is the failure mode a backup system cannot detect
 * any other way. A module writing directly to its own table, a query that ran before the statement
 * tap was enabled, a migration executed outside Drupal - all of them look identical from inside the
 * capture path, and all of them show up here.
 *
 * It is a warning rather than an error. Drift means the history has a gap for one table, not that
 * anything already stored is wrong, and the repair - capture the table's current rows - is a
 * rebuild that runs without a human.
 *
 * @see Reconciler
 * @see Watermark
 */
final class WatermarkDrift implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'watermark.drift';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$table = (string) ($observation['table'] ?? '');

		if ($table === '' || ($observation['drifted'] ?? false) !== true) {
			return null;
		}

		return new Finding(
			$this->code(),
			Finding::WARN,
			$table,
			sprintf(
				'%s changed with no captured operation: %s',
				$table,
				(string) ($observation['drift'] ?? 'no detail recorded'),
			),
		);
	}
}
