<?php

declare(strict_types=1);

namespace Drupal\strata\Health\Tripwire;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;

/**
 * A frame's range runs past the end of the pack holding it.
 *
 * A pack is a flat concatenation, so a range that does not fit means the index and the object
 * disagree about the object's length - a truncated upload, or an index row written against a
 * different pack. Reading it anyway returns short bytes that decompress to nothing or to garbage.
 *
 * @see Finding
 */
final class PackShortRead implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'pack.short_read';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$pack = (string) ($observation['pack'] ?? '');
		$size = (int) ($observation['pack_size'] ?? 0);
		$end =
			(int) ($observation['frame_offset'] ?? 0) + (int) ($observation['frame_length'] ?? 0);

		if ($pack === '' || $size < 1 || $end <= $size) {
			return null;
		}

		return new Finding(
			$this->code(),
			Finding::ERROR,
			$pack,
			sprintf(
				'Frame %s ends at byte %d of a %d byte pack',
				Hash::abbreviate((string) ($observation['frame'] ?? '')),
				$end,
				$size,
			),
		);
	}
}
