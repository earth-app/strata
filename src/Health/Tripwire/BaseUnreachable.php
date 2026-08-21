<?php

declare(strict_types=1);

namespace Drupal\strata\Health\Tripwire;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;

/**
 * A replay walked back through history without finding a base anchor.
 *
 * A replay starts at an anchor and applies what follows, so a chain with no anchor in it has no
 * starting point and the commits in it cannot be materialised however intact they are. The usual
 * cause is a prune that took the anchor and left its descendants, which is what the receipt exists
 * to prevent.
 *
 * @see Finding
 */
final class BaseUnreachable implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'base.unreachable';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$commit = (string) ($observation['commit'] ?? '');

		if ($commit === '' || ($observation['anchor_reachable'] ?? true) !== false) {
			return null;
		}

		return new Finding(
			$this->code(),
			Finding::CRITICAL,
			$commit,
			sprintf(
				'No base anchor is reachable from commit %s after walking %d commits',
				Hash::abbreviate($commit),
				(int) ($observation['walked'] ?? 0),
			),
		);
	}
}
