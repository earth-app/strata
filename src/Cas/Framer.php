<?php

declare(strict_types=1);

namespace Drupal\strata\Cas;

use Generator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Splits a byte stream into fixed-size frames.
 *
 * Frames are a fixed size rather than content-defined. A pure-PHP FastCDC runs at 4.29 MB/s
 * per-byte and 5.63 MB/s over unpacked 64 KiB windows, against 683 MB/s for this splitter plus a
 * BLAKE2b per frame; `unpack('C*')` on an 8 MB buffer also exhausts a 128 MB memory limit, since
 * an int array costs roughly 16x the string. Content-defined chunking exists to survive
 * insertions, which shift every following boundary, and an append-only op log has none.
 *
 * DeltaCodec covers the case content-defined chunking would have: it compresses a rewritten value
 * against its previous version, measured at 63.7x on that class of change. Files, where an
 * insertion can happen, are handled by BlockSplitter, which detects the shift.
 *
 * Frame size trades compression against granularity: with a trained zstd dictionary the measured
 * ratio is 6.20x at 8 KiB, 6.39x at 16 KiB, 6.54x at 32 KiB and 6.72x at 64 KiB, while the
 * dictionary's own contribution falls from 40.3% to 15.7% across that range. 16 KiB is the shipped
 * default; calibration against a real site decides whether to move it.
 *
 * @see Hash
 * @see Packer
 */
final class Framer
{
	/**
	 * Default frame size in bytes.
	 */
	public const DEFAULT_SIZE = 16384;

	/**
	 * Smallest frame size that still amortises a 32-byte digest and a frame header.
	 */
	public const MIN_SIZE = 1024;

	/**
	 * Largest frame size.
	 *
	 * Beyond this a single frame stops being independently addressable, which is the property the
	 * whole store is built on.
	 */
	public const MAX_SIZE = 4194304;

	/**
	 * Frame size in bytes.
	 */
	private readonly int $size;

	/**
	 * Constructs a splitter.
	 *
	 * @param int $size
	 *   Frame size in bytes, between Framer::MIN_SIZE and Framer::MAX_SIZE. Powers of two are not
	 *   required but are what the defaults use.
	 *
	 * @throws InvalidArgumentException
	 *   When $size is outside the supported range.
	 */
	public function __construct(int $size = self::DEFAULT_SIZE)
	{
		if ($size < self::MIN_SIZE || $size > self::MAX_SIZE) {
			throw new InvalidArgumentException(
				sprintf(
					'Frame size must be between %d and %d bytes, got %d',
					self::MIN_SIZE,
					self::MAX_SIZE,
					$size,
				),
			);
		}

		$this->size = $size;
	}

	/**
	 * The configured frame size.
	 *
	 * @return int
	 *   Frame size in bytes.
	 */
	public function size(): int
	{
		return $this->size;
	}

	/**
	 * How many frames a payload of a given length produces.
	 *
	 * The last frame is short rather than padded, so that appending to a payload never rewrites a
	 * frame that was already stored under its own digest.
	 *
	 * @param int $length
	 *   Payload length in bytes.
	 *
	 * @return int
	 *   Frame count; zero for an empty payload.
	 *
	 * @throws InvalidArgumentException
	 *   When $length is negative.
	 */
	public function count(int $length): int
	{
		if ($length < 0) {
			throw new InvalidArgumentException('Payload length cannot be negative');
		}

		return intdiv($length + $this->size - 1, $this->size);
	}

	/**
	 * Splits a string into frames.
	 *
	 * An empty payload yields nothing, which is what lets a caller distinguish "no content" from
	 * "one empty frame" without a sentinel.
	 *
	 * @param string $payload
	 *   The bytes to split.
	 *
	 * @return Generator<int, string>
	 *   Frame index keyed to frame bytes, in order.
	 */
	public function split(string $payload): Generator
	{
		$length = strlen($payload);

		for ($index = 0, $offset = 0; $offset < $length; $index++, $offset += $this->size) {
			yield $index => substr($payload, $offset, $this->size);
		}
	}

	/**
	 * Splits a stream into frames without holding it in memory.
	 *
	 * Reads from the current position to EOF. A short read that is not EOF is retried rather than
	 * silently producing an under-length frame in the middle of a payload, because a short frame
	 * anywhere but the end changes every following boundary and would fork the whole frame chain.
	 *
	 * @param resource $stream
	 *   An open, readable stream.
	 *
	 * @return Generator<int, string>
	 *   Frame index keyed to frame bytes, in order.
	 *
	 * @throws InvalidArgumentException
	 *   When $stream is not a stream resource.
	 * @throws RuntimeException
	 *   When a read fails.
	 */
	public function splitStream($stream): Generator
	{
		if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
			throw new InvalidArgumentException(
				'Framer::splitStream() needs an open stream resource',
			);
		}

		$index = 0;

		while (true) {
			$frame = '';

			// fread can return short of the request on a pipe; only EOF may end a frame early
			while (strlen($frame) < $this->size) {
				$block = fread($stream, $this->size - strlen($frame));

				if ($block === false) {
					throw new RuntimeException('Framer::splitStream() failed reading the stream');
				}
				if ($block === '') {
					break;
				}

				$frame .= $block;
			}

			if ($frame === '') {
				return;
			}

			yield $index++ => $frame;

			if (strlen($frame) < $this->size) {
				return;
			}
		}
	}

	/**
	 * Splits a payload and digests each frame.
	 *
	 * The common case: a caller wants the frames and their content addresses together, and
	 * computing the digest separately would walk the payload twice.
	 *
	 * @param string $payload
	 *   The bytes to split.
	 *
	 * @return Generator<int, array{hash: string, bytes: string, offset: int}>
	 *   Frame index keyed to the frame's digest, bytes and byte offset in the payload.
	 */
	public function frames(string $payload): Generator
	{
		$offset = 0;

		foreach ($this->split($payload) as $index => $bytes) {
			yield $index => [
				'hash' => Hash::of($bytes),
				'bytes' => $bytes,
				'offset' => $offset,
			];

			$offset += strlen($bytes);
		}
	}

	/**
	 * The ordered digests of a payload's frames.
	 *
	 * This is the frame map a manifest stores. Reassembly needs the order, so a set will not do.
	 *
	 * @param string $payload
	 *   The bytes to split.
	 *
	 * @return list<string>
	 *   Frame digests in order.
	 */
	public function map(string $payload): array
	{
		$map = [];

		foreach ($this->split($payload) as $bytes) {
			$map[] = Hash::of($bytes);
		}

		return $map;
	}

	/**
	 * Rebuilds a payload from frames supplied in order.
	 *
	 * Verifies each frame against the digest it was stored under, so a corrupted or substituted
	 * frame raises here rather than reaching Drupal as plausible-looking content. The map is the
	 * authority on order and length; a missing frame is named rather than skipped.
	 *
	 * @param list<string> $map
	 *   Frame digests in order, as returned by Framer::map().
	 * @param callable(string): ?string $fetch
	 *   Given a frame digest, returns its bytes, or NULL when the frame cannot be found.
	 *
	 * @return string
	 *   The reassembled payload.
	 *
	 * @throws RuntimeException
	 *   When a frame is missing, or its bytes do not digest to the hash it was stored under.
	 */
	public function reassemble(array $map, callable $fetch): string
	{
		$payload = '';

		foreach ($map as $index => $hash) {
			$bytes = $fetch($hash);

			if ($bytes === null) {
				throw new RuntimeException(
					sprintf('Frame %d (%s) is missing', $index, Hash::abbreviate($hash)),
				);
			}
			if (!Hash::equals(Hash::of($bytes), $hash)) {
				throw new RuntimeException(
					sprintf(
						'Frame %d does not match its digest %s',
						$index,
						Hash::abbreviate($hash),
					),
				);
			}

			$payload .= $bytes;
		}

		return $payload;
	}
}
