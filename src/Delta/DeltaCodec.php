<?php

declare(strict_types=1);

namespace Drupal\strata\Delta;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Codec\CompressionCodecInterface;
use RuntimeException;

/**
 * Encodes a value against its previous version.
 *
 * This is the mechanism that replaced content-defined chunking. A rewritten field - a body, a JSON
 * blob of notifications, a serialized settings array - is almost entirely the value it replaces,
 * but a byte-level splitter cannot see that because the change is interior. Handing the previous
 * version to the compressor as its dictionary can: measured on 5,967-byte notification blobs with
 * one flag flipped and one entry appended, zstd -19 against the previous version reached 63.70x
 * and 94 bytes per object, against 4.49x and 1,330 bytes standalone - 93.0% smaller. At level 3 it
 * is 51.93x, so the win survives the fast setting used on the flush path.
 *
 * The previous version is already in memory during a capture as the entity's original, and is
 * retrievable from the store by digest during a restore, so neither path pays to fetch it twice.
 *
 * Two rules bound the dependency this creates. A chain deeper than the policy allows is anchored
 * instead. And an encoding that does not beat the standalone form by DeltaCodec::MIN_GAIN is
 * discarded in favour of the anchor, so a codec without dictionary support produces correct output
 * without a branch at any call site.
 *
 * @see ChainDepthPolicy
 * @see DeltaFrame
 */
final class DeltaCodec
{
	/**
	 * How much smaller a delta must be than its anchor to be worth the dependency.
	 *
	 * A delta that saves less than this is discarded. A chain link costs restore latency and widens
	 * the blast radius of a lost frame, so a small saving does not cover it.
	 */
	public const MIN_GAIN = 0.1;

	/**
	 * Constructs a codec.
	 *
	 * @param CompressionCodecInterface $codec
	 *   The compressor. When it reports no dictionary support every frame is an anchor, so
	 *   CodecRegistry prefers a dictionary-capable codec.
	 * @param ChainDepthPolicy $policy
	 *   The chain-depth policy.
	 * @param int|null $level
	 *   Compression level, or NULL for the codec's default.
	 */
	public function __construct(
		private readonly CompressionCodecInterface $codec,
		private readonly ChainDepthPolicy $policy,
		private readonly ?int $level = null,
	) {}

	/**
	 * Whether this codec can actually produce deltas on this host.
	 *
	 * @return bool
	 *   FALSE when the compressor has no dictionary support, in which case every frame anchors.
	 */
	public function canDelta(): bool
	{
		return $this->codec->isAvailable() && $this->codec->supportsDictionary();
	}

	/**
	 * Encodes a value, against its previous version when that is worthwhile.
	 *
	 * @param string $current
	 *   The value to encode.
	 * @param string|null $previous
	 *   The previous version of the same subject, or NULL when there is none.
	 * @param string|null $parentHash
	 *   Digest of $previous, or NULL to compute it. Pass it when the caller already has it.
	 * @param int $parentDepth
	 *   Chain depth of the frame holding $previous; zero when that frame is an anchor.
	 *
	 * @return DeltaFrame
	 *   A delta frame when one is available, cheaper and within the depth policy; otherwise an
	 *   anchor.
	 *
	 * @throws RuntimeException
	 *   When the compressor fails.
	 */
	public function encode(
		string $current,
		?string $previous = null,
		?string $parentHash = null,
		int $parentDepth = 0,
	): DeltaFrame {
		$anchor = new DeltaFrame(
			DeltaFrame::MODE_ANCHOR,
			$this->codec->id(),
			$this->codec->compress($current, $this->level),
			null,
			0,
			strlen($current),
		);

		if ($previous === null || $previous === '' || !$this->canDelta()) {
			return $anchor;
		}
		if ($this->policy->mustAnchor($parentDepth)) {
			return $anchor;
		}

		$delta = new DeltaFrame(
			DeltaFrame::MODE_DELTA,
			$this->codec->id(),
			$this->codec->compress($current, $this->level, $previous),
			$parentHash ?? Hash::of($previous),
			$this->policy->nextDepth($parentDepth),
			strlen($current),
		);

		// a link that saves less than MIN_GAIN does not cover its restore cost
		if ($delta->length() > $anchor->length() * (1.0 - self::MIN_GAIN)) {
			return $anchor;
		}

		return $delta;
	}

	/**
	 * Decodes a frame back to its value.
	 *
	 * @param DeltaFrame $frame
	 *   The frame to decode.
	 * @param string|null $previous
	 *   The parent value, required for a delta frame and ignored for an anchor.
	 *
	 * @return string
	 *   The decoded value.
	 *
	 * @throws RuntimeException
	 *   When a delta frame is handed no parent, the wrong parent, or the decode is truncated.
	 */
	public function decode(DeltaFrame $frame, ?string $previous = null): string
	{
		if ($frame->isAnchor()) {
			return $this->verify($frame, $this->codec->decompress($frame->bytes));
		}

		if ($previous === null) {
			throw new RuntimeException(
				sprintf(
					'Delta frame at depth %d needs its parent %s, which was not supplied',
					$frame->depth,
					Hash::abbreviate((string) $frame->parent),
				),
			);
		}
		if (!Hash::equals(Hash::of($previous), (string) $frame->parent)) {
			throw new RuntimeException(
				sprintf(
					'Delta frame expects parent %s but was given a different value',
					Hash::abbreviate((string) $frame->parent),
				),
			);
		}

		return $this->verify($frame, $this->codec->decompress($frame->bytes, $previous));
	}

	/**
	 * Walks a chain of successive versions to the value the last frame encodes.
	 *
	 * The whole chain is decoded in order because a delta is meaningless without the link before
	 * it. It must begin with an anchor: a chain starting at a delta cannot be decoded without a
	 * parent the caller has not supplied.
	 *
	 * An anchor part-way through is NOT an error and must not be treated as one. Two ordinary
	 * things produce it - the depth cap forcing a re-anchor, and DeltaCodec::encode() discarding a
	 * delta that failed to beat its own anchor by DeltaCodec::MIN_GAIN, which happens whenever a
	 * value is small enough that a chain link cannot pay for itself. Such an anchor RESTARTS the
	 * chain: every frame before it becomes unnecessary and depth counting begins again.
	 *
	 * A depth that does not follow its predecessor within the current run is a corruption symptom
	 * and is named in the error.
	 *
	 * @param list<DeltaFrame> $chain
	 *   Frames from an anchor to the target, in order.
	 *
	 * @return string
	 *   The value the last frame encodes.
	 *
	 * @throws RuntimeException
	 *   When the chain is empty, does not start at an anchor, has a depth discontinuity within a
	 *   run, or any link fails to decode.
	 */
	public function decodeChain(array $chain): string
	{
		if ($chain === []) {
			throw new RuntimeException('Cannot decode an empty delta chain');
		}

		$first = $chain[0];
		if (!$first->isAnchor()) {
			throw new RuntimeException(
				sprintf(
					'A delta chain must start at an anchor, but starts at depth %d',
					$first->depth,
				),
			);
		}

		$value = '';
		$expected = 0;

		foreach ($chain as $position => $frame) {
			if ($frame->isAnchor()) {
				$value = $this->decode($frame);
				$expected = 0;

				continue;
			}

			$expected++;
			if ($frame->depth !== $expected) {
				throw new RuntimeException(
					sprintf(
						'Delta chain is discontinuous: expected depth %d at position %d, found %d',
						$expected,
						$position,
						$frame->depth,
					),
				);
			}

			$value = $this->decode($frame, $value);
		}

		return $value;
	}

	/**
	 * Catches a decode that came back the wrong length.
	 *
	 * A truncated decompression is indistinguishable from correct output until much later, so the
	 * recorded plain length is checked before the bytes leave this class.
	 *
	 * @param DeltaFrame $frame
	 *   The frame that was decoded.
	 * @param string $plain
	 *   The decoded bytes.
	 *
	 * @return string
	 *   The same bytes, once they are known to be complete.
	 *
	 * @throws RuntimeException
	 *   When the decoded length does not match what the frame recorded.
	 */
	private function verify(DeltaFrame $frame, string $plain): string
	{
		if ($frame->plainLength > 0 && strlen($plain) !== $frame->plainLength) {
			throw new RuntimeException(
				sprintf(
					'Decoded %d bytes but the frame records %d; the frame is truncated or corrupt',
					strlen($plain),
					$frame->plainLength,
				),
			);
		}

		return $plain;
	}
}
