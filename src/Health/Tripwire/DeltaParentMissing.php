<?php

declare(strict_types=1);

namespace Drupal\strata\Health\Tripwire;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;

/**
 * A delta frame's parent is gone, so the frame decodes to nothing.
 *
 * Delta coding buys 63.7x on rewritten values by encoding each version against the one before it,
 * and the cost is exactly this dependency. A prune that collects a parent because nothing
 * references it directly breaks every frame downstream of it, which is why delta parents are the
 * third class reachability protects.
 *
 * @see Finding
 */
final class DeltaParentMissing implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'delta.parent_missing';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$parent = (string) ($observation['delta_parent'] ?? '');

		if ($parent === '' || ($observation['delta_parent_present'] ?? true) !== false) {
			return null;
		}

		return new Finding(
			$this->code(),
			Finding::CRITICAL,
			$parent,
			sprintf(
				'Frame %s at chain depth %d decodes against %s, which is gone',
				Hash::abbreviate((string) ($observation['frame'] ?? '')),
				(int) ($observation['delta_depth'] ?? 0),
				Hash::abbreviate($parent),
			),
		);
	}
}
