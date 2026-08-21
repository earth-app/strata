<?php

declare(strict_types=1);

namespace Drupal\strata\Health\Tripwire;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;

/**
 * A frame the index knows about is not in the bucket.
 *
 * The commonest real failure: a lifecycle rule, a bucket policy or a hand-run delete removed an
 * object the index still points at. Every value that frame belongs to is unrestorable until it
 * comes back, so this is critical rather than an error - a restore that reaches it cannot complete.
 *
 * @see Finding
 */
final class FrameMissing implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'frame.missing';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$frame = (string) ($observation['frame'] ?? '');

		if ($frame === '' || ($observation['frame_present'] ?? true) !== false) {
			return null;
		}

		return new Finding(
			$this->code(),
			Finding::CRITICAL,
			$frame,
			sprintf(
				'Frame %s is indexed but object %s is not in the bucket',
				Hash::abbreviate($frame),
				(string) ($observation['key'] ?? '?'),
			),
		);
	}
}
