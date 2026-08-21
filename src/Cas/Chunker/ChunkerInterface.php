<?php

declare(strict_types=1);

namespace Drupal\strata\Cas\Chunker;

use Drupal\strata\Cas\Framer;
use Traversable;

/**
 * Splits a byte stream into pieces the store addresses individually.
 *
 * There are two ways to do this and Strata ships both, for different reasons.
 *
 * `Framer` splits at fixed offsets and runs at 683 MB/s including a BLAKE2b per frame. It is what
 * every default path uses. Its one weakness is an INSERTION: inserting two bytes at the front shifts
 * every boundary after it, so nothing downstream deduplicates.
 *
 * A content-defined chunker splits at boundaries the content itself decides, so an insertion shifts
 * one chunk and the rest still match. Measured in pure PHP, that costs 4.29 MB/s per-byte and
 * 5.63 MB/s over unpacked windows - 120 to 160 times slower than the fixed splitter - and
 * `unpack('C*')` over an 8 MB buffer exhausts a 128 MB memory limit because an int array costs
 * roughly 16x the string.
 *
 * So content-defined chunking is not a default and not an option on any hot path. It exists for one
 * case: a large file that genuinely has content inserted into it rather than rewritten - an appended
 * log, a growing tar, a SQL dump - where `ShiftDetector` has already found that fixed blocks are
 * storing 60% of the file on every change. The UI shows the measured throughput next to the switch,
 * so an administrator turning it on is looking at what it costs.
 *
 * @see Framer
 * @see FastCdcChunker
 */
interface ChunkerInterface
{
	/**
	 * A short lowercase identifier.
	 *
	 * @return string
	 *   For example "fastcdc".
	 */
	public function id(): string;

	/**
	 * Measured throughput on the reference host, in bytes per second.
	 *
	 * Part of the interface rather than documentation, because the UI prints it next to the switch
	 * and an administrator deciding whether to enable a 4 MB/s code path needs the number in front
	 * of them. Re-measured by `strata:calibrate` on the real host.
	 *
	 * @return int
	 *   Bytes per second.
	 */
	public function throughput(): int;

	/**
	 * Splits a stream into chunks.
	 *
	 * @param resource $stream
	 *   An open, readable stream positioned where splitting should start.
	 * @param int $budget
	 *   Most bytes to read before stopping, so one cron run cannot be consumed by one file. Zero
	 *   means no limit and is for a command an operator is watching.
	 *
	 * @return Traversable<int, array{offset: int, bytes: string}>
	 *   Each chunk's offset in the stream and its content, in order.
	 */
	public function chunk($stream, int $budget = 0): Traversable;

	/**
	 * The smallest chunk this chunker will emit.
	 *
	 * @return int
	 *   Bytes.
	 */
	public function minimum(): int;

	/**
	 * The largest chunk this chunker will emit.
	 *
	 * @return int
	 *   Bytes.
	 */
	public function maximum(): int;
}
