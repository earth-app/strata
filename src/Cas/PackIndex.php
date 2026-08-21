<?php

declare(strict_types=1);

namespace Drupal\strata\Cas;

use InvalidArgumentException;
use RuntimeException;

/**
 * The per-frame directory a pack carries at its own end.
 *
 * A pack is a flat concatenation of encoded frames with no framing between them, so the only thing
 * that can find a frame inside one is a map of offsets. Keeping that map exclusively in the local
 * database would make every packed frame unreadable the moment the database went away - which is
 * precisely the situation `strata:reindex` exists to recover from, and precisely the situation a
 * module uninstall creates. So the map is written into the object as well, after the frames.
 *
 * The directory sits at the END rather than the front because a frame is read by absolute offset
 * from byte zero; a header would have to be a fixed width or every offset would depend on the size
 * of the thing describing them. A trailer of a known shape is found by reading the last
 * PackIndex::TAIL_BYTES bytes, which is one ranged GET on any endpoint that supports ranges.
 *
 * Each entry carries what a frame record needs and nothing more. At 16 KiB frames in a 1 MiB pack
 * that is about 64 entries and roughly 0.8% of the object.
 *
 * @see Packer
 * @see FrameEnvelope
 */
final class PackIndex
{
	/**
	 * Magic bytes and format version closing every pack.
	 */
	public const MAGIC = 'STRATA-PAK-1';

	/**
	 * Width of the decimal length that follows the magic.
	 */
	public const LENGTH_DIGITS = 10;

	/**
	 * Fixed size of the tail: the magic plus the directory length.
	 */
	public const TAIL_BYTES = 22;

	/**
	 * Serializes a directory into the bytes that close a pack.
	 *
	 * @param list<array<string, mixed>> $entries
	 *   One entry per frame, each carrying hash, offset, length, raw, codec, cipher and optionally
	 *   dictionary, parent and depth.
	 *
	 * @return string
	 *   The trailer, ready to append to the concatenated frames.
	 *
	 * @throws InvalidArgumentException
	 *   When an entry names no valid content address.
	 */
	public static function encode(array $entries): string
	{
		$compact = [];

		foreach ($entries as $entry) {
			$hash = (string) ($entry['hash'] ?? '');

			if (!Hash::isValid($hash)) {
				throw new InvalidArgumentException(
					'Every pack directory entry needs a valid content address',
				);
			}

			$row = [
				'h' => $hash,
				'o' => (int) ($entry['offset'] ?? 0),
				'l' => (int) ($entry['length'] ?? 0),
				'r' => (int) ($entry['raw'] ?? 0),
				'c' => (string) ($entry['codec'] ?? ''),
				'k' => (string) ($entry['cipher'] ?? ''),
			];

			// omitted rather than written as null, since most frames use none of the three
			if (($entry['dictionary'] ?? null) !== null) {
				$row['d'] = (string) $entry['dictionary'];
			}
			if (($entry['parent'] ?? null) !== null) {
				$row['p'] = (string) $entry['parent'];
				$row['n'] = (int) ($entry['depth'] ?? 0);
			}

			$compact[] = $row;
		}

		$body = (string) json_encode(['v' => 1, 'e' => $compact]);

		return $body . self::MAGIC . sprintf('%0' . self::LENGTH_DIGITS . 'd', strlen($body));
	}

	/**
	 * Reads the directory out of a whole pack object.
	 *
	 * @param string $key
	 *   The object key, for error messages.
	 * @param string $object
	 *   The pack bytes, including the trailer.
	 *
	 * @return list<array<string, mixed>>
	 *   One entry per frame, in the order they appear in the pack, with the same keys
	 *   PackIndex::encode() accepts.
	 *
	 * @throws RuntimeException
	 *   When the object is too short, carries no directory, or the directory does not parse.
	 */
	public static function decode(string $key, string $object): array
	{
		$length = self::trailerLength($key, substr($object, -self::TAIL_BYTES));
		$start = strlen($object) - self::TAIL_BYTES - $length;

		if ($start < 0) {
			throw new RuntimeException(
				sprintf(
					'Pack %s claims a %d byte directory it is too short to hold',
					$key,
					$length,
				),
			);
		}

		return self::parse($key, substr($object, $start, $length));
	}

	/**
	 * Reads the directory length out of a pack's fixed-size tail.
	 *
	 * Split out so a reader with range support can fetch PackIndex::TAIL_BYTES, learn how long the
	 * directory is, and fetch only that - two small reads instead of a whole 1 MiB object.
	 *
	 * @param string $key
	 *   The object key, for error messages.
	 * @param string $tail
	 *   The last PackIndex::TAIL_BYTES bytes of the object.
	 *
	 * @return int
	 *   Length of the directory in bytes.
	 *
	 * @throws RuntimeException
	 *   When the tail is the wrong size, or does not carry the magic this release writes.
	 */
	public static function trailerLength(string $key, string $tail): int
	{
		if (strlen($tail) !== self::TAIL_BYTES) {
			throw new RuntimeException(
				sprintf('Pack %s is too short to carry a frame directory', $key),
			);
		}

		$magic = substr($tail, 0, strlen(self::MAGIC));

		if ($magic !== self::MAGIC) {
			throw new RuntimeException(
				sprintf(
					'Pack %s ends with "%s"; this release writes "%s"',
					$key,
					$magic,
					self::MAGIC,
				),
			);
		}

		$digits = substr($tail, strlen(self::MAGIC));

		if (!ctype_digit($digits)) {
			throw new RuntimeException(
				sprintf('Pack %s does not say how long its frame directory is', $key),
			);
		}

		return (int) $digits;
	}

	/**
	 * Expands a serialized directory back into entries.
	 *
	 * @param string $key
	 *   The object key, for error messages.
	 * @param string $body
	 *   The serialized directory.
	 *
	 * @return list<array<string, mixed>>
	 *   The entries.
	 *
	 * @throws RuntimeException
	 *   When the directory does not parse, or names a version this release cannot read.
	 */
	private static function parse(string $key, string $body): array
	{
		/** @var array<string, mixed>|null $decoded */
		$decoded = json_decode($body, true);

		if (!is_array($decoded) || !is_array($decoded['e'] ?? null)) {
			throw new RuntimeException(
				sprintf('Pack %s carries a frame directory that does not parse', $key),
			);
		}
		if ((int) ($decoded['v'] ?? 0) !== 1) {
			throw new RuntimeException(
				sprintf(
					'Pack %s carries a version %s frame directory; this release reads version 1',
					$key,
					(string) ($decoded['v'] ?? '?'),
				),
			);
		}

		$entries = [];

		/** @var array<string, mixed> $row */
		foreach ($decoded['e'] as $row) {
			$entries[] = [
				'hash' => (string) ($row['h'] ?? ''),
				'offset' => (int) ($row['o'] ?? 0),
				'length' => (int) ($row['l'] ?? 0),
				'raw' => (int) ($row['r'] ?? 0),
				'codec' => (string) ($row['c'] ?? ''),
				'cipher' => (string) ($row['k'] ?? ''),
				'dictionary' => isset($row['d']) ? (string) $row['d'] : null,
				'parent' => isset($row['p']) ? (string) $row['p'] : null,
				'depth' => (int) ($row['n'] ?? 0),
			];
		}

		return $entries;
	}
}
