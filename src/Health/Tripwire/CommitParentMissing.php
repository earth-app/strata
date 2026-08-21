<?php

declare(strict_types=1);

namespace Drupal\strata\Health\Tripwire;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;

/**
 * A commit names a parent that is not in the store.
 *
 * History is a chain, so a missing link truncates every restore point older than it: the walk from
 * the ref stops there and nothing before it can be reached, however intact those objects are. That
 * makes one missing commit object far more expensive than one missing frame.
 *
 * @see Finding
 */
final class CommitParentMissing implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'commit.parent_missing';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$parent = (string) ($observation['commit_parent'] ?? '');

		if ($parent === '' || ($observation['commit_parent_present'] ?? true) !== false) {
			return null;
		}

		return new Finding(
			$this->code(),
			Finding::CRITICAL,
			$parent,
			sprintf(
				'Commit %s names parent %s, which is not in the store, so history stops here',
				Hash::abbreviate((string) ($observation['commit'] ?? '')),
				Hash::abbreviate($parent),
			),
		);
	}
}
