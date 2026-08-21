<?php

declare(strict_types=1);

namespace Drupal\strata\Health\Tripwire;

use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;
use Drupal\strata\Tier\TierMigrator;
use Drupal\strata\Verify\Verifier;

/**
 * A bucket history is spread across could not be reached.
 *
 * The reason this is a finding rather than a log line is that the alternative is a verify pass
 * reporting clean. A pass that walked what it could read and said nothing about the tier it could
 * not would answer "the backup is fine" about a bucket it never opened, and a far tier is exactly
 * where the history nobody has read for a year lives.
 *
 * Raised at ERROR rather than CRITICAL, and only ERROR, because an unreachable bucket is usually
 * credentials or a network and is almost always fixable without touching the data. What makes it
 * serious is what it blocks: a prune refuses while the walk is incomplete, and a restore that needs
 * that tier cannot run.
 *
 * @see Finding
 * @see Verifier
 * @see TierMigrator
 */
final class TierUnreachable implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'tier.unreachable';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$tier = (string) ($observation['tier'] ?? '');

		if ($tier === '' || ($observation['tier_reachable'] ?? true) !== false) {
			return null;
		}

		return new Finding(
			$this->code(),
			Finding::ERROR,
			$tier,
			sprintf(
				'Storage tier "%s" could not be reached, so nothing it holds was checked: %s',
				$tier,
				(string) ($observation['tier_reason'] ?? 'the endpoint did not say why'),
			),
		);
	}
}
