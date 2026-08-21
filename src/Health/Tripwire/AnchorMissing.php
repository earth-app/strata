<?php

declare(strict_types=1);

namespace Drupal\strata\Health\Tripwire;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;

/**
 * A base anchor a commit points at is not in the store.
 *
 * An anchor is what says which frames belong to which subject, so a missing one loses the index of
 * the site at that point even though the frames it named are still there. The commits between two
 * anchors all name the same one, so a single missing anchor takes a whole interval of history out of
 * reach - and the chain behind it with it, since a delta anchor is meaningless without its parent.
 *
 * Recoverable rather than fatal: the segments the interval covers still hold every operation, so a
 * rebuild can write the anchor again from them.
 *
 * @see Finding
 */
final class AnchorMissing implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'anchor.missing';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$node = (string) ($observation['anchor'] ?? '');

		if ($node === '' || ($observation['anchor_present'] ?? true) !== false) {
			return null;
		}

		return new Finding(
			$this->code(),
			Finding::CRITICAL,
			$node,
			sprintf(
				'Base anchor %s is referenced by commit %s but is not in the store',
				Hash::abbreviate($node),
				Hash::abbreviate((string) ($observation['commit'] ?? '')),
			),
		);
	}
}
