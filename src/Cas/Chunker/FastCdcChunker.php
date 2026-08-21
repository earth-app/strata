<?php

declare(strict_types=1);

namespace Drupal\strata\Cas\Chunker;

use Drupal\strata\Cas\Framer;
use Generator;
use InvalidArgumentException;
use Traversable;

/**
 * Content-defined chunking, off by default and priced in the interface.
 *
 * FastCDC picks boundaries from a rolling hash of the content, so inserting bytes shifts one chunk
 * instead of every chunk after it. That is the one thing fixed-size framing cannot do, and it is why
 * this exists.
 *
 * **It is 120 to 160 times slower than the fixed splitter and that is not a bug to optimise away.**
 * Measured in pure PHP on the reference host: 4.29 MB/s reading byte by byte with `ord()`, 5.63 MB/s
 * over 64 KiB windows unpacked with `unpack`, against 683 MB/s for fixed framing plus a BLAKE2b per
 * frame. An 80 GB initial file capture would spend 5.9 hours of pure CPU here. PHP has no primitive
 * for a per-byte rolling hash, and the array form that would vectorise it costs roughly 16x the
 * string in memory, which exhausts a 128 MB limit at 8 MB of input.
 *
 * So this is reached only from `file.shift_detected`, only when an administrator has turned it on for
 * a file type, only with a byte budget per cron run, and never for media or the operation stream.
 *
 * **Two normalisations matter and are easy to get wrong.** The mask is widened for chunks below the
 * target and narrowed above it, which is what makes the size distribution tight rather than
 * exponential - that is the difference between FastCDC and the older Rabin approach, and it is where
 * most of its speed comes from. And the minimum size is skipped without hashing, because hashing
 * bytes that cannot produce a boundary is pure cost.
 *
 * @see ChunkerInterface
 * @see Framer
 */
final class FastCdcChunker implements ChunkerInterface
{
	/**
	 * Measured throughput on the reference host, in bytes per second.
	 *
	 * The per-byte figure rather than the windowed one, because the windowed variant is what
	 * exhausts memory on a large buffer and is therefore not what runs.
	 */
	public const MEASURED_THROUGHPUT = 4_290_000;

	/**
	 * Default target chunk size.
	 */
	public const DEFAULT_TARGET = 16_384;

	/**
	 * How small a chunk may be, as a fraction of the target.
	 *
	 * Below this the per-chunk overhead - a digest, an index row, a pack entry - starts to cost more
	 * than the deduplication saves.
	 */
	public const MIN_FRACTION = 0.25;

	/**
	 * How large a chunk may be, as a multiple of the target.
	 */
	public const MAX_MULTIPLE = 4;

	/**
	 * How much is read from the stream at a time.
	 */
	private const BUFFER = 262_144;

	/**
	 * The gear table the rolling hash indexes.
	 *
	 * Derived deterministically rather than shipped as a literal, so two hosts produce identical
	 * boundaries for identical content. A random table would make a chunk's identity depend on which
	 * machine wrote it, which would defeat deduplication across a restore.
	 *
	 * @var list<int>|null
	 */
	private static ?array $gear = null;

	/**
	 * Target chunk size in bytes.
	 */
	private readonly int $target;

	/**
	 * Mask used below the target, with more bits set so a boundary is harder to hit.
	 */
	private readonly int $strictMask;

	/**
	 * Mask used above the target, with fewer bits set so a boundary is easier to hit.
	 */
	private readonly int $lenientMask;

	/**
	 * Constructs a chunker.
	 *
	 * @param int $target
	 *   Target chunk size in bytes, between 1 KiB and 4 MiB.
	 *
	 * @throws InvalidArgumentException
	 *   When the target is outside the supported range.
	 */
	public function __construct(int $target = self::DEFAULT_TARGET)
	{
		if ($target < 1024 || $target > 4_194_304) {
			throw new InvalidArgumentException(
				sprintf('A chunk target must be between 1024 and 4194304 bytes, got %d', $target),
			);
		}

		$this->target = $target;

		// the normalisation FastCDC is named for: a tighter mask under target, looser over it
		$bits = (int) round(log($target, 2));
		$this->strictMask = (1 << ($bits + 2)) - 1;
		$this->lenientMask = (1 << ($bits - 2)) - 1;
	}

	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return 'fastcdc';
	}

	/**
	 * {@inheritdoc}
	 */
	public function throughput(): int
	{
		return self::MEASURED_THROUGHPUT;
	}

	/**
	 * {@inheritdoc}
	 */
	public function minimum(): int
	{
		return (int) max(64, $this->target * self::MIN_FRACTION);
	}

	/**
	 * {@inheritdoc}
	 */
	public function maximum(): int
	{
		return $this->target * self::MAX_MULTIPLE;
	}

	/**
	 * {@inheritdoc}
	 */
	public function chunk($stream, int $budget = 0): Traversable
	{
		return $this->split($stream, $budget);
	}

	/**
	 * Splits a stream, yielding each chunk as it is closed.
	 *
	 * @param resource $stream
	 *   The stream.
	 * @param int $budget
	 *   Most bytes to read; zero for no limit.
	 *
	 * @return Generator<int, array{offset: int, bytes: string}>
	 *   The chunks.
	 */
	private function split($stream, int $budget): Generator
	{
		$gear = self::gear();
		$minimum = $this->minimum();
		$maximum = $this->maximum();

		$pending = '';
		$offset = 0;
		$read = 0;
		$hash = 0;

		while (true) {
			if (strlen($pending) < $maximum && !feof($stream)) {
				$wanted = $budget > 0 ? min(self::BUFFER, max(0, $budget - $read)) : self::BUFFER;

				if ($wanted > 0) {
					$block = fread($stream, $wanted);

					if ($block !== false && $block !== '') {
						$pending .= $block;
						$read += strlen($block);
					}
				}
			}

			if ($pending === '') {
				return;
			}

			$length = $this->boundary($pending, $gear, $minimum, $maximum, $hash);

			// nothing conclusive yet and more is coming: read again before deciding
			if ($length === null) {
				if (!feof($stream) && ($budget < 1 || $read < $budget)) {
					continue;
				}

				$length = min(strlen($pending), $maximum);
			}

			yield ['offset' => $offset, 'bytes' => substr($pending, 0, $length)];

			$offset += $length;
			$pending = substr($pending, $length);
			$hash = 0;

			if ($pending === '' && (feof($stream) || ($budget > 0 && $read >= $budget))) {
				return;
			}
		}
	}

	/**
	 * Where the next chunk ends.
	 *
	 * @param string $buffer
	 *   The bytes available.
	 * @param list<int> $gear
	 *   The gear table.
	 * @param int $minimum
	 *   Smallest chunk.
	 * @param int $maximum
	 *   Largest chunk.
	 * @param int $hash
	 *   The rolling hash, carried across calls.
	 *
	 * @return int|null
	 *   The chunk length, or NULL when the buffer holds no boundary and is not yet at the maximum,
	 *   so the caller should read more before deciding.
	 */
	private function boundary(
		string $buffer,
		array $gear,
		int $minimum,
		int $maximum,
		int &$hash,
	): ?int {
		$available = strlen($buffer);

		if ($available <= $minimum) {
			return $available >= $maximum ? $maximum : null;
		}

		$limit = min($available, $maximum);

		// the bytes below the minimum cannot close a chunk, so they are not hashed at all
		for ($i = $minimum; $i < $limit; $i++) {
			$hash = (($hash << 1) + $gear[ord($buffer[$i])]) & 0xffffffff;
			$mask = $i < $this->target ? $this->strictMask : $this->lenientMask;

			if (($hash & $mask) === 0) {
				return $i + 1;
			}
		}

		return $limit >= $maximum ? $maximum : null;
	}

	/**
	 * The gear table, built once per process.
	 *
	 * Derived from a fixed seed with a simple xorshift, so every host produces the same table and
	 * therefore the same boundaries for the same content.
	 *
	 * @return list<int>
	 *   256 values.
	 */
	private static function gear(): array
	{
		if (self::$gear !== null) {
			return self::$gear;
		}

		$table = [];
		$state = 0x1f2e3d4c;

		for ($i = 0; $i < 256; $i++) {
			$state ^= ($state << 13) & 0xffffffff;
			$state ^= $state >> 17;
			$state ^= ($state << 5) & 0xffffffff;
			$table[] = $state & 0xffffffff;
		}

		return self::$gear = $table;
	}
}
