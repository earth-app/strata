<?php

declare(strict_types=1);

namespace Drupal\strata\Health\Tripwire;

use Drupal\strata\Code\VendorDriftDetector;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;

/**
 * A dependency was patched in place, so the lockfile no longer reproduces the tree.
 *
 * `vendor/` is referenced rather than stored, because `composer.lock` reproduces it exactly and is
 * 440x smaller than core's compressed bytes alone. That trade is sound while the assumption holds.
 * When someone patches a dependency by hand, the assumption stops holding and the backup is missing
 * the only copy of a change the site depends on - and nothing else would ever say so.
 *
 * An error rather than a warning: unlike most findings this one means something is NOT in the backup,
 * and the repair is to capture the drifted files, which runs without a human.
 *
 * @see VendorDriftDetector
 */
final class VendorDrift implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'code.vendor_drift';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		if (($observation['vendor_drifted'] ?? false) !== true) {
			return null;
		}

		$files = (int) ($observation['drifted_files'] ?? 0);

		return new Finding(
			$this->code(),
			Finding::ERROR,
			'vendor',
			sprintf(
				'%s%s',
				(string) ($observation['drift_reason'] ??
					'the vendor tree no longer matches its lock'),
				$files > 0 ? sprintf('; %d files differ', $files) : '',
			),
		);
	}
}
