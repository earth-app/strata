<?php

declare(strict_types=1);

namespace Drupal\strata\Cas;

use InvalidArgumentException;
use RuntimeException;

/**
 * Batches small frames into one object.
 *
 * At 16 KiB a frame is far too small to be its own object. R2 charges $4.50 per million Class A
 * operations and a 15-second flush interval already costs about 202,000 of them per month before
 * any frames are counted; storing each frame separately would add one per frame and put the request
 * bill an order of magnitude above the storage bill.
 *
 * A pack is a flat concatenation with a separate index. The bytes carry no framing of their own, so
 * a reader that knows a frame's offset and length fetches exactly that range with one ranged GET
 * and never downloads the rest. The index lives in the frame records, not in the pack, so reading
 * one frame costs no extra request.
 *
 * @see Framer
 * @see ObjectStore
 */
final class Packer
{
	/**
	 * Target pack size in bytes.
	 */
	public const DEFAULT_TARGET = 1_048_576;

	/**
	 * Smallest useful target. Below this the request saving stops covering the read amplification.
	 */
	public const MIN_TARGET = 65_536;

	/**
	 * Largest target. A pack this size makes a ranged read the only sane way to touch it.
	 */
	public const MAX_TARGET = 134_217_728;

	/**
	 * Target size in bytes.
	 */
	private readonly int $target;

	/**
	 * Frames waiting to be flushed, in insertion order.
	 *
	 * @var list<array{hash: string, bytes: string}>
	 */
	private array $pending = [];

	/**
	 * Bytes currently buffered.
	 */
	private int $buffered = 0;

	/**
	 * Constructs a packer.
	 *
	 * @param int $target
	 *   Target pack size in bytes, between Packer::MIN_TARGET and Packer::MAX_TARGET.
	 *
	 * @throws InvalidArgumentException
	 *   When the target is outside the supported range.
	 */
	public function __construct(int $target = self::DEFAULT_TARGET)
	{
		if ($target < self::MIN_TARGET || $target > self::MAX_TARGET) {
			throw new InvalidArgumentException(
				sprintf(
					'A pack target must be between %d and %d bytes, got %d',
					self::MIN_TARGET,
					self::MAX_TARGET,
					$target,
				),
			);
		}

		$this->target = $target;
	}

	/**
	 * The configured target size.
	 *
	 * @return int
	 *   Target pack size in bytes.
	 */
	public function target(): int
	{
		return $this->target;
	}

	/**
	 * Bytes currently buffered.
	 *
	 * @return int
	 *   The buffered total.
	 */
	public function buffered(): int
	{
		return $this->buffered;
	}

	/**
	 * How many frames are waiting.
	 *
	 * @return int
	 *   The pending count.
	 */
	public function pending(): int
	{
		return count($this->pending);
	}

	/**
	 * Whether the buffer has reached its target.
	 *
	 * @return bool
	 *   TRUE when Packer::flush() should be called.
	 */
	public function isFull(): bool
	{
		return $this->buffered >= $this->target;
	}

	/**
	 * Whether a frame should bypass packing entirely.
	 *
	 * A frame at or above the target gains nothing from being batched and would push every frame
	 * behind it into a second pack, so it is stored as its own object.
	 *
	 * @param int $size
	 *   Encoded frame size in bytes.
	 *
	 * @return bool
	 *   TRUE when the frame should be stored standalone.
	 */
	public function shouldStoreAlone(int $size): bool
	{
		return $size >= $this->target;
	}

	/**
	 * Adds a frame to the buffer.
	 *
	 * @param string $hash
	 *   Content address of the decoded frame.
	 * @param string $bytes
	 *   The encoded frame, after compression and sealing.
	 *
	 * @throws InvalidArgumentException
	 *   When the hash is not a valid digest, the frame is empty, or the frame belongs in its own
	 *   object.
	 */
	public function add(string $hash, string $bytes): void
	{
		if (!Hash::isValid($hash)) {
			throw new InvalidArgumentException('A packed frame needs a valid content address');
		}
		if ($bytes === '') {
			throw new InvalidArgumentException('An empty frame is not stored');
		}
		if ($this->shouldStoreAlone(strlen($bytes))) {
			throw new InvalidArgumentException(
				sprintf(
					'A %d byte frame is at or above the %d byte pack target and belongs in its own object',
					strlen($bytes),
					$this->target,
				),
			);
		}

		$this->pending[] = ['hash' => $hash, 'bytes' => $bytes];
		$this->buffered += strlen($bytes);
	}

	/**
	 * Seals the buffer into a pack and clears it.
	 *
	 * The pack id is the content address of the concatenated bytes, so two flushes producing the
	 * same content produce the same pack and the store deduplicates whole packs as well as frames.
	 *
	 * @return array<string, mixed>|null
	 *   Keys id (the pack's content address), bytes (the concatenation) and entries (a list of
	 *   hash, offset and length per frame), or NULL when nothing was buffered.
	 */
	public function flush(): ?array
	{
		if ($this->pending === []) {
			return null;
		}

		$bytes = '';
		$entries = [];
		$offset = 0;

		foreach ($this->pending as $frame) {
			$length = strlen($frame['bytes']);
			$entries[] = ['hash' => $frame['hash'], 'offset' => $offset, 'length' => $length];
			$bytes .= $frame['bytes'];
			$offset += $length;
		}

		$this->pending = [];
		$this->buffered = 0;

		return ['id' => Hash::of($bytes), 'bytes' => $bytes, 'entries' => $entries];
	}

	/**
	 * Discards the buffer without producing a pack.
	 *
	 * Used when a flush fails and the frames will be rebuilt from the journal rather than retried
	 * from memory.
	 */
	public function discard(): void
	{
		$this->pending = [];
		$this->buffered = 0;
	}

	/**
	 * Extracts one frame from a pack's bytes.
	 *
	 * @param string $pack
	 *   The pack bytes.
	 * @param int $offset
	 *   Byte offset of the frame.
	 * @param int $length
	 *   Frame length in bytes.
	 *
	 * @return string
	 *   The encoded frame.
	 *
	 * @throws RuntimeException
	 *   When the range falls outside the pack, which means the index and the object disagree.
	 */
	public static function extract(string $pack, int $offset, int $length): string
	{
		if ($offset < 0 || $length < 1 || $offset + $length > strlen($pack)) {
			throw new RuntimeException(
				sprintf(
					'A frame at offset %d length %d does not fit in a %d byte pack',
					$offset,
					$length,
					strlen($pack),
				),
			);
		}

		return substr($pack, $offset, $length);
	}
}
