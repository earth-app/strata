<?php

declare(strict_types=1);

namespace Drupal\strata\Health\Tripwire;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;

/**
 * A frame names a compression dictionary that is not available.
 *
 * A dictionary is not an optimisation once a frame has been written against it; it is part of what
 * decodes that frame. This is why a dictionary is never pruned while any live frame references it,
 * and why the reference lives in the frame's own record rather than being inferred from the store's
 * current settings.
 *
 * @see Finding
 */
final class DictionaryMissing implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'dict.missing';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$dictionary = (string) ($observation['dictionary'] ?? '');

		if ($dictionary === '' || ($observation['dictionary_present'] ?? true) !== false) {
			return null;
		}

		return new Finding(
			$this->code(),
			Finding::CRITICAL,
			$dictionary,
			sprintf(
				'Frame %s needs dictionary %s, which is not loaded',
				Hash::abbreviate((string) ($observation['frame'] ?? '')),
				$dictionary,
			),
		);
	}
}
