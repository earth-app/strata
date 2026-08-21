<?php

declare(strict_types=1);

namespace Drupal\strata\Health\Tripwire;

use Drupal\strata\File\ShiftDetector;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;

/**
 * A file's content is shifting rather than being edited, so fixed blocks are storing most of it.
 *
 * Fixed blocks make a change cost the blocks it touched, which on a 256 MiB file with a 2 MiB in-place
 * edit is 0.81%. An insertion is the case they cannot follow: every block after it moves, and the same
 * file costs 60.79% on every change. The storage bill is usually the first sign.
 *
 * A warning rather than an error. Nothing stored is wrong and every version restores exactly; the file
 * is simply costing far more than it needs to, and the fix is a per-file-type decision only a person
 * can make - whole-object storage, or the content-defined chunker at 4.29 MB/s.
 *
 * @see ShiftDetector
 */
final class FileShiftDetected implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'file.shift_detected';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$file = (string) ($observation['file'] ?? '');

		if ($file === '' || ($observation['file_shifted'] ?? false) !== true) {
			return null;
		}

		return new Finding(
			$this->code(),
			Finding::WARN,
			$file,
			sprintf(
				'%d of %d blocks moved (%.1f%%, threshold %.0f%%), so %s is shifting rather than being ' .
					'edited and each version stores %.1f%% of it',
				(int) ($observation['changed_blocks'] ?? 0),
				(int) ($observation['total_blocks'] ?? 0),
				(float) ($observation['changed_ratio'] ?? 0.0) * 100,
				(float) ($observation['shift_threshold'] ?? ShiftDetector::DEFAULT_THRESHOLD) * 100,
				$file,
				(float) ($observation['upload_share'] ?? 0.0) * 100,
			),
		);
	}
}
