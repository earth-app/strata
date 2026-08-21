<?php

declare(strict_types=1);

namespace Drupal\strata\File;

use Drupal\strata\Cas\Hash;
use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;

/**
 * One version of one file, as the ordered list of blocks that rebuild it.
 *
 * This is what a file version costs to store: a few dozen bytes per block rather than the blocks
 * themselves, since the blocks are content-addressed and shared with every other version that
 * contains them. A 256 MiB file is 4,096 blocks, so its map is about 270 KiB of digests - and the
 * second version of the same file with a 2 MiB edit stores a second map plus the 33 blocks that
 * changed.
 *
 * The order matters and is the whole point: the same blocks in a different order are a different file.
 * So a map is a list, never a set, and its own address covers the order.
 *
 * @see BlockSplitter
 * @see FileMapDiff
 */
final class FileMap implements JsonSerializable
{
	/**
	 * Format marker, so a future change is a new version rather than a silent misread.
	 */
	public const VERSION = 1;

	/**
	 * Key prefix maps are stored under.
	 */
	public const PREFIX = 'filemaps';

	/**
	 * Constructs a map.
	 *
	 * @param string $path
	 *   The file's path, relative to whatever root the capture walked.
	 * @param list<string> $blocks
	 *   Block content addresses, in file order.
	 * @param int $length
	 *   The file's length in bytes.
	 * @param int $blockSize
	 *   The block size the map was split at, recorded because a map split at one size cannot be
	 *   compared with one split at another and a reader has to be able to tell.
	 * @param int $capturedAt
	 *   Unix seconds.
	 *
	 * @throws InvalidArgumentException
	 *   When the path is empty, a block is not a digest, or the length disagrees with the block count.
	 */
	public function __construct(
		public readonly string $path,
		public readonly array $blocks,
		public readonly int $length,
		public readonly int $blockSize = BlockSplitter::DEFAULT_SIZE,
		public readonly int $capturedAt = 0,
	) {
		if (trim($path) === '') {
			throw new InvalidArgumentException('A file map must name its file');
		}
		if ($length < 0) {
			throw new InvalidArgumentException('A file length cannot be negative');
		}
		if ($blockSize < 1) {
			throw new InvalidArgumentException('A block size must be positive');
		}

		foreach ($blocks as $block) {
			if (!Hash::isValid($block)) {
				throw new InvalidArgumentException(
					'Every block must be addressed by a valid digest',
				);
			}
		}

		$expected = $length < 1 ? 0 : (int) ceil($length / $blockSize);

		if (count($blocks) !== $expected) {
			throw new InvalidArgumentException(
				sprintf(
					'A %d-byte file at %d-byte blocks has %d blocks, not %d',
					$length,
					$blockSize,
					$expected,
					count($blocks),
				),
			);
		}
	}

	/**
	 * This map's content address.
	 *
	 * @return string
	 *   A 64-character lowercase hex digest.
	 */
	public function address(): string
	{
		return Hash::of($this->encode());
	}

	/**
	 * How many blocks the file has.
	 *
	 * @return int
	 *   The count.
	 */
	public function count(): int
	{
		return count($this->blocks);
	}

	/**
	 * Whether the file has no content.
	 *
	 * @return bool
	 *   TRUE for a zero-length file.
	 */
	public function isEmpty(): bool
	{
		return $this->blocks === [];
	}

	/**
	 * The distinct blocks the file is made of.
	 *
	 * A file with a long run of identical content - a sparse image, a padded archive - has far fewer
	 * distinct blocks than blocks, and only the distinct ones are stored.
	 *
	 * @return list<string>
	 *   Block addresses, deduplicated, in first-seen order.
	 */
	public function distinct(): array
	{
		return array_values(array_unique($this->blocks));
	}

	/**
	 * Bytes the distinct blocks occupy, at most.
	 *
	 * The last block of a file is usually short, so this is an upper bound rather than a measurement.
	 *
	 * @return int
	 *   Bytes.
	 */
	public function storedCeiling(): int
	{
		return count($this->distinct()) * $this->blockSize;
	}

	/**
	 * Where one block sits in the file.
	 *
	 * @param int $index
	 *   Block index, zero-based.
	 *
	 * @return array{offset: int, length: int}|null
	 *   The offset and length, or NULL when the index is past the end.
	 */
	public function positionOf(int $index): ?array
	{
		if ($index < 0 || $index >= count($this->blocks)) {
			return null;
		}

		$offset = $index * $this->blockSize;

		return ['offset' => $offset, 'length' => min($this->blockSize, $this->length - $offset)];
	}

	/**
	 * The object key this map is stored under.
	 *
	 * Keyed by the file's identity and the map's own address, so every version of a file lists
	 * together and no two versions collide.
	 *
	 * @return string
	 *   The key.
	 */
	public function key(): string
	{
		return sprintf(
			'%s/%s/%s.map',
			self::PREFIX,
			substr(Hash::of($this->path), 0, 16),
			Hash::abbreviate($this->address(), 32),
		);
	}

	/**
	 * The stored form.
	 *
	 * @return string
	 *   JSON.
	 */
	public function encode(): string
	{
		return (string) json_encode($this->jsonSerialize());
	}

	/**
	 * A map from its stored form.
	 *
	 * @param string $payload
	 *   The bytes.
	 *
	 * @return FileMap
	 *   The map.
	 *
	 * @throws RuntimeException
	 *   When the payload is not a map this release can read.
	 */
	public static function decode(string $payload): self
	{
		/** @var mixed $decoded */
		$decoded = json_decode($payload, true);

		if (!is_array($decoded) || ($decoded['v'] ?? null) !== self::VERSION) {
			throw new RuntimeException('Not a file map this release can read');
		}

		$blocks = [];

		foreach (is_array($decoded['blocks'] ?? null) ? $decoded['blocks'] : [] as $block) {
			$blocks[] = (string) $block;
		}

		return new self(
			(string) ($decoded['path'] ?? ''),
			$blocks,
			(int) ($decoded['length'] ?? 0),
			(int) ($decoded['blockSize'] ?? BlockSplitter::DEFAULT_SIZE),
			(int) ($decoded['capturedAt'] ?? 0),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The map as data.
	 */
	public function jsonSerialize(): array
	{
		return [
			'v' => self::VERSION,
			'path' => $this->path,
			'length' => $this->length,
			'blockSize' => $this->blockSize,
			'capturedAt' => $this->capturedAt,
			'blocks' => $this->blocks,
		];
	}
}
