<?php

declare(strict_types=1);

namespace Drupal\strata\Health\Tripwire;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;

/**
 * A tree names a frame the local index has never heard of.
 *
 * Distinct from a missing frame: the bytes may well be in the bucket, but nothing local knows which
 * object holds them or how to decode them, so they cannot be read. This is what an uninstall leaves
 * behind and what `strata:reindex` repairs, which is why it sits at the reindex rung rather than
 * anywhere higher.
 *
 * @see Finding
 */
final class FrameUnindexed implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'frame.unindexed';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$frame = (string) ($observation['frame'] ?? '');

		if ($frame === '' || ($observation['frame_indexed'] ?? true) !== false) {
			return null;
		}

		return new Finding(
			$this->code(),
			Finding::ERROR,
			$frame,
			sprintf(
				'Frame %s is referenced but not in the local index, so nothing knows where it is',
				Hash::abbreviate($frame),
			),
		);
	}
}
