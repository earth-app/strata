<?php

declare(strict_types=1);

namespace Drupal\strata\Health\Tripwire;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;

/**
 * A frame decoded to bytes that do not hash to its address.
 *
 * The address is taken from the decoded frame, so this can only mean the stored bytes changed under
 * us or the index row describes a different object than the key holds. Either way the content is
 * not what it claims to be, and silently accepting it would write wrong data into a live site
 * during a restore.
 *
 * @see Finding
 */
final class FrameHashMismatch implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'frame.hash_mismatch';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$frame = (string) ($observation['frame'] ?? '');
		$decoded = (string) ($observation['decoded_hash'] ?? '');

		if ($frame === '' || $decoded === '' || Hash::equals($frame, $decoded)) {
			return null;
		}

		return new Finding(
			$this->code(),
			Finding::CRITICAL,
			$frame,
			sprintf(
				'Frame %s decoded to content addressed %s',
				Hash::abbreviate($frame),
				Hash::abbreviate($decoded),
			),
		);
	}
}
