<?php

declare(strict_types=1);

namespace Drupal\strata\Health\Tripwire;

use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;

/**
 * A segment could not be read back.
 *
 * A segment is the ordered record of what one flush captured, so an unreadable one loses the
 * ability to replay that window even when every frame it names is intact. The manifest is derived
 * from the frames and the commit, so a rebuild can regenerate it without a human.
 *
 * @see Finding
 */
final class SegmentTruncated implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'segment.truncated';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$segment = (string) ($observation['segment'] ?? '');

		if ($segment === '' || ($observation['segment_readable'] ?? true) !== false) {
			return null;
		}

		return new Finding(
			$this->code(),
			Finding::ERROR,
			$segment,
			sprintf(
				'Segment %s did not read back: %s',
				$segment,
				(string) ($observation['segment_error'] ?? 'no reason recorded'),
			),
		);
	}
}
