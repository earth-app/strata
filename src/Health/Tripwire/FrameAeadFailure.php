<?php

declare(strict_types=1);

namespace Drupal\strata\Health\Tripwire;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;

/**
 * A frame failed authenticated decryption.
 *
 * Three causes produce this and the symptom cannot tell them apart: the key changed, the bytes were
 * tampered with, or the object was moved to a key it was not sealed under. All three mean the frame
 * cannot be opened here and none of them are repairable by refetching the same object, so the
 * finding names the possibilities rather than picking one.
 *
 * @see Finding
 */
final class FrameAeadFailure implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'frame.aead_fail';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$frame = (string) ($observation['frame'] ?? '');

		if ($frame === '' || ($observation['aead_failed'] ?? false) !== true) {
			return null;
		}

		return new Finding(
			$this->code(),
			Finding::CRITICAL,
			$frame,
			sprintf(
				'Frame %s did not open: the key changed, the bytes changed, or it was relocated',
				Hash::abbreviate($frame),
			),
		);
	}
}
