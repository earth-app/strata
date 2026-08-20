<?php

declare(strict_types=1);

namespace Drupal\strata\Delta;

use InvalidArgumentException;

/**
 * Decides when a delta chain must be broken by a self-contained frame.
 *
 * Delta coding is what makes a rewritten value cheap: measured on 5,967-byte user notification
 * blobs with one flag flipped and one entry appended, compressing against the previous version
 * reached 63.70x where standalone compression reached 4.49x - 94 bytes against 1,330.
 *
 * The cost is a dependency. Frame N decodes only if frame N-1 is present and intact, so an
 * unbounded chain turns one lost frame into an unbounded loss and makes restore latency grow
 * without limit. Git solves the same problem in its packfiles with `pack.depth`, default 50.
 * Strata's default is 32, chosen lower because a restore here walks the chain synchronously while
 * a user waits, and because the marginal ratio past ~30 links is small against the marginal risk.
 *
 * A chain break is called an anchor: a frame encoded standalone, which any later frame can be
 * rebuilt from without reaching further back.
 *
 * @see DeltaCodec
 * @see DeltaFrame
 */
final class ChainDepthPolicy
{
	/**
	 * Chain depth Strata ships with.
	 */
	public const DEFAULT_MAX_DEPTH = 32;

	/**
	 * Deepest chain that may be configured.
	 *
	 * Past this the restore-latency and blast-radius costs above grow without bound.
	 */
	public const HARD_MAX_DEPTH = 256;

	/**
	 * Maximum links between anchors.
	 */
	private readonly int $maxDepth;

	/**
	 * Constructs a policy.
	 *
	 * @param int $maxDepth
	 *   Maximum chain depth, between 0 and ChainDepthPolicy::HARD_MAX_DEPTH. Zero means every frame
	 *   is an anchor, which is the correct setting for a store that values restore latency over
	 *   size.
	 *
	 * @throws InvalidArgumentException
	 *   When $maxDepth is negative or above the hard maximum.
	 */
	public function __construct(int $maxDepth = self::DEFAULT_MAX_DEPTH)
	{
		if ($maxDepth < 0 || $maxDepth > self::HARD_MAX_DEPTH) {
			throw new InvalidArgumentException(
				sprintf(
					'Chain depth must be between 0 and %d, got %d',
					self::HARD_MAX_DEPTH,
					$maxDepth,
				),
			);
		}

		$this->maxDepth = $maxDepth;
	}

	/**
	 * The configured maximum depth.
	 *
	 * @return int
	 *   Maximum links between anchors.
	 */
	public function maxDepth(): int
	{
		return $this->maxDepth;
	}

	/**
	 * Whether a frame at this parent depth must be written standalone.
	 *
	 * @param int $parentDepth
	 *   The depth of the frame this one would be encoded against.
	 *
	 * @return bool
	 *   TRUE when the new frame must be an anchor.
	 *
	 * @throws InvalidArgumentException
	 *   When $parentDepth is negative.
	 */
	public function mustAnchor(int $parentDepth): bool
	{
		if ($parentDepth < 0) {
			throw new InvalidArgumentException('Parent depth cannot be negative');
		}

		return $parentDepth + 1 > $this->maxDepth;
	}

	/**
	 * The depth a new frame would have.
	 *
	 * @param int $parentDepth
	 *   The depth of the frame this one is encoded against.
	 *
	 * @return int
	 *   Zero when the frame must anchor, otherwise one past the parent.
	 *
	 * @throws InvalidArgumentException
	 *   When $parentDepth is negative.
	 */
	public function nextDepth(int $parentDepth): int
	{
		return $this->mustAnchor($parentDepth) ? 0 : $parentDepth + 1;
	}

	/**
	 * How many more links a chain at this depth can take.
	 *
	 * Surfaced so the UI can show headroom rather than only reporting the break after it happens.
	 *
	 * @param int $depth
	 *   Current chain depth.
	 *
	 * @return int
	 *   Remaining links before the next anchor, never negative.
	 *
	 * @throws InvalidArgumentException
	 *   When $depth is negative.
	 */
	public function remaining(int $depth): int
	{
		if ($depth < 0) {
			throw new InvalidArgumentException('Depth cannot be negative');
		}

		return max(0, $this->maxDepth - $depth);
	}
}
