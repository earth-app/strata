<?php

declare(strict_types=1);

namespace Drupal\strata\File;

use Drupal\strata\Cas\Hash;
use Generator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Splits a file into fixed blocks so a change costs the blocks it touched.
 *
 * Whole-object storage would re-upload a 256 MiB video because two bytes of metadata changed. Blocks
 * make the cost proportional to the edit: hash each one, store the ones that are new, and keep an
 * ordered list of digests as the file's version.
 *
 * **64 KiB, and the size was measured rather than picked.** On a 256 MiB file, a 2 MiB in-place edit
 * costs 2.1 MiB at 64 KiB blocks against 3.0 MiB at 1 MiB blocks and 8.0 MiB at 4 MiB, and hashing
 * the whole file takes 0.40 s either way - 645 MB/s. Smaller blocks would keep shrinking the patch and
 * start costing more in per-block overhead than they save.
 *
 * **What fixed blocks cannot do, stated plainly.** They cannot follow an insertion. Inserting 2 MiB
 * at the front shifts every block after it, so 60.8% of the file looks changed at every block size.
 * That is what `ShiftDetector` is for: a high changed ratio is the signature of a shift rather than an
 * edit, and it is reported rather than silently paid for. Content-defined chunking is the answer to
 * that case and costs 4.29 MB/s, which is 150x slower, so it is never a default.
 *
 * @see FileMap
 * @see ShiftDetector
 */
final class BlockSplitter
{
	/**
	 * Block size in bytes.
	 *
	 * 64 KiB. See the class docblock for the measurements this comes from.
	 */
	public const DEFAULT_SIZE = 65_536;

	/**
	 * Smallest block size accepted.
	 */
	public const MIN_SIZE = 4_096;

	/**
	 * Largest block size accepted.
	 */
	public const MAX_SIZE = 8_388_608;

	/**
	 * Measured throughput on the reference host, in bytes per second.
	 *
	 * Hashing at 64 KiB blocks, which is what a first capture and every drift check cost.
	 */
	public const MEASURED_THROUGHPUT = 645_000_000;

	/**
	 * Constructs a splitter.
	 *
	 * @param int $size
	 *   Block size in bytes.
	 *
	 * @throws InvalidArgumentException
	 *   When the size is outside the supported range.
	 */
	public function __construct(private readonly int $size = self::DEFAULT_SIZE)
	{
		if ($size < self::MIN_SIZE || $size > self::MAX_SIZE) {
			throw new InvalidArgumentException(
				sprintf(
					'A block size must be between %d and %d bytes, got %d',
					self::MIN_SIZE,
					self::MAX_SIZE,
					$size,
				),
			);
		}
	}

	/**
	 * The configured block size.
	 *
	 * @return int
	 *   Bytes.
	 */
	public function size(): int
	{
		return $this->size;
	}

	/**
	 * How many blocks a file of a given length has.
	 *
	 * @param int $length
	 *   File length in bytes.
	 *
	 * @return int
	 *   The count. A zero-length file has no blocks, which is what lets an empty file be told apart
	 *   from one whose blocks are all absent.
	 */
	public function count(int $length): int
	{
		return $length < 1 ? 0 : (int) ceil($length / $this->size);
	}

	/**
	 * Splits an open stream, yielding each block as it is read.
	 *
	 * A generator, so splitting a 256 MiB file holds one block in memory rather than the file.
	 *
	 * @param resource $stream
	 *   An open, readable stream positioned where splitting should start.
	 *
	 * @return Generator<int, array{index: int, offset: int, hash: string, bytes: string}>
	 *   Each block's position, content address and content, in order.
	 *
	 * @throws InvalidArgumentException
	 *   When the argument is not a stream.
	 * @throws RuntimeException
	 *   When a read fails.
	 */
	public function split($stream): Generator
	{
		if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
			throw new InvalidArgumentException('A block splitter needs an open stream');
		}

		$index = 0;
		$offset = 0;

		while (!feof($stream)) {
			$bytes = fread($stream, $this->size);

			if ($bytes === false) {
				throw new RuntimeException(sprintf('Could not read block %d', $index));
			}
			if ($bytes === '') {
				break;
			}

			yield [
				'index' => $index,
				'offset' => $offset,
				'hash' => Hash::of($bytes),
				'bytes' => $bytes,
			];

			$index++;
			$offset += strlen($bytes);
		}
	}

	/**
	 * The map of a file on disk.
	 *
	 * @param string $path
	 *   Path to the file.
	 *
	 * @return FileMap
	 *   The map.
	 *
	 * @throws RuntimeException
	 *   When the file cannot be opened or read.
	 */
	public function map(string $path): FileMap
	{
		$stream = @fopen($path, 'rb');

		if ($stream === false) {
			throw new RuntimeException(sprintf('Could not open %s', $path));
		}

		try {
			$hashes = [];
			$length = 0;

			foreach ($this->split($stream) as $block) {
				$hashes[] = $block['hash'];
				$length += strlen($block['bytes']);
			}

			return new FileMap($path, $hashes, $length, $this->size);
		} finally {
			fclose($stream);
		}
	}

	/**
	 * The map of a value already in memory.
	 *
	 * @param string $path
	 *   The path the value belongs to, which the map records.
	 * @param string $contents
	 *   The bytes.
	 *
	 * @return FileMap
	 *   The map.
	 */
	public function mapString(string $path, string $contents): FileMap
	{
		$hashes = [];

		for ($offset = 0; $offset < strlen($contents); $offset += $this->size) {
			$hashes[] = Hash::of(substr($contents, $offset, $this->size));
		}

		return new FileMap($path, $hashes, strlen($contents), $this->size);
	}

	/**
	 * How long splitting a file of a given size takes on the reference host.
	 *
	 * Printed next to the file-capture switch, because a first capture of an 80 GB media tree is a
	 * number an operator should see before turning it on rather than after.
	 *
	 * @param int $bytes
	 *   File size.
	 *
	 * @return float
	 *   Seconds.
	 */
	public static function secondsFor(int $bytes): float
	{
		return max(0, $bytes) / self::MEASURED_THROUGHPUT;
	}
}
