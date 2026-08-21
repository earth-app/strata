<?php

declare(strict_types=1);

namespace Drupal\strata\Health\Tripwire;

use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;

/**
 * The local index names commits the store has no ref for.
 *
 * A verify pass walks back from a ref, so a store whose ref is gone has nothing to walk and reads as
 * an empty history. That is indistinguishable from a fresh install by looking at the bucket alone,
 * and it is the state a wiped bucket or a lifecycle rule with the wrong prefix leaves behind - the
 * worst possible moment to be told everything is fine.
 *
 * The local index is what separates the two. It is derived from the bucket and dropped by an
 * uninstall, so rows in it are evidence that commits existed. Rows present and no ref means the
 * objects went, not that they were never written.
 *
 * @see Finding
 */
final class RefMissing implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'ref.missing';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$ref = (string) ($observation['ref'] ?? '');
		$indexed = (int) ($observation['indexed_commits'] ?? 0);

		if ($ref === '' || $indexed < 1 || ($observation['ref_resolved'] ?? true) !== false) {
			return null;
		}

		return new Finding(
			$this->code(),
			Finding::CRITICAL,
			$ref,
			sprintf(
				'Ref %s resolves to nothing while the local index still names %d commits, so the ' .
					'objects behind them are gone rather than never written',
				$ref,
				$indexed,
			),
		);
	}
}
