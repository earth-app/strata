<?php

declare(strict_types=1);

namespace Drupal\strata\Health\Tripwire;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;

/**
 * A delta chain is longer than the depth policy allows.
 *
 * Every link is a frame that must be fetched and decoded before the next one, so depth is restore
 * latency and it is also blast radius: one lost frame takes out everything after it. The encoder
 * anchors at the cap, so a chain past it means an anchor was skipped or the cap was lowered after
 * the chain was written. Re-anchoring fixes it, which is a rebuild rather than a human decision.
 *
 * @see Finding
 */
final class DeltaChainTooDeep implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'delta.chain_too_deep';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$depth = (int) ($observation['delta_depth'] ?? 0);
		$max = (int) ($observation['max_depth'] ?? 0);

		if ($max < 1 || $depth <= $max) {
			return null;
		}

		return new Finding(
			$this->code(),
			Finding::WARN,
			(string) ($observation['frame'] ?? ''),
			sprintf(
				'Frame %s sits %d links deep, past the %d link cap',
				Hash::abbreviate((string) ($observation['frame'] ?? '')),
				$depth,
				$max,
			),
		);
	}
}
