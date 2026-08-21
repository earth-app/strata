<?php

declare(strict_types=1);

namespace Drupal\strata\Health\Tripwire;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;

/**
 * A frame that is present, indexed and authentic still would not decode.
 *
 * What is left once the object exists, the index describes it and the tag verified: a codec absent
 * from this host, a header naming a format this release cannot read, or a decompression that came
 * back the wrong length. The reason is carried through verbatim because the causes need different
 * responses and the symptom cannot tell them apart.
 *
 * @see Finding
 */
final class FrameUnreadable implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'frame.unreadable';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$frame = (string) ($observation['frame'] ?? '');
		$reason = (string) ($observation['decode_error'] ?? '');

		if ($frame === '' || $reason === '') {
			return null;
		}

		return new Finding(
			$this->code(),
			Finding::ERROR,
			$frame,
			sprintf('Frame %s did not decode: %s', Hash::abbreviate($frame), $reason),
		);
	}
}
