<?php

declare(strict_types=1);

namespace Drupal\strata\Health\Tripwire;

use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;

/**
 * One segment's frames need more than one key to open.
 *
 * The symptom of a rotation that landed while a flush was in progress: some frames in the window were
 * sealed with the outgoing key and the rest with the incoming one. Both open, so nothing is lost, and
 * that is exactly why it needs saying - the store is readable now and stops being readable the moment
 * the retired key is removed from the ring.
 *
 * A whole segment sealed under one retired key is NOT this symptom. That is a segment that predates
 * the rotation, which is the normal state of every byte written before it and is what the ring exists
 * to handle. The finding is about a segment split ACROSS keys.
 *
 * @see Finding
 */
final class KeyRotatedMidFlight implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'key.rotated_mid_flight';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $observation
	 *   Expects `segment` and `fingerprints`, the distinct key fingerprints that opened its frames.
	 */
	public function check(array $observation): ?Finding
	{
		$segment = (string) ($observation['segment'] ?? '');
		$fingerprints = $observation['fingerprints'] ?? [];

		if ($segment === '' || !is_array($fingerprints)) {
			return null;
		}

		$distinct = array_values(array_unique(array_map('strval', $fingerprints)));

		if (count($distinct) < 2) {
			return null;
		}

		return new Finding(
			$this->code(),
			Finding::WARN,
			$segment,
			sprintf(
				'Segment %s has frames under %d keys, so a rotation landed mid-flush; it stays ' .
					'readable only while every one of those keys is on the ring',
				$segment,
				count($distinct),
			),
		);
	}
}
