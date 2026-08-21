<?php

declare(strict_types=1);

namespace Drupal\strata\Cas;

use RuntimeException;

/**
 * The plaintext header a standalone frame object carries.
 *
 * A frame's object key is the digest of its DECODED bytes, not of the object, so nothing about the
 * key says which codec compressed it, which cipher sealed it or which dictionary it needs. Without
 * that, an object in the bucket cannot be decoded by anything but the database row that happens to
 * describe it - and a reindex exists for exactly the case where that row is gone.
 *
 * The header is the same shape a segment carries, for the same reason: a reader has to know which
 * codec and cipher to reach for before it can open anything, so those fields cannot themselves be
 * compressed or sealed. Nothing here is secret; the codec id, the cipher id and the decoded length
 * of one frame reveal nothing the object's own size does not.
 *
 * Packed frames carry the same fields in their pack's trailer instead, because a ranged read of one
 * frame inside a pack must not have to skip a per-frame header of unknown width.
 *
 * @see PackIndex
 * @see ObjectStore
 */
final class FrameEnvelope
{
	/**
	 * Magic bytes and format version leading every standalone frame object.
	 */
	public const MAGIC = 'STRATA-FRM-1';

	/**
	 * Written in place of a dictionary or delta parent the frame does not have.
	 */
	public const ABSENT = '-';

	/**
	 * How many newline-delimited fields the header carries.
	 */
	private const FIELDS = 6;

	/**
	 * Builds the object bytes for a standalone frame.
	 *
	 * @param string $codec
	 *   Codec id the body was compressed with.
	 * @param string $cipher
	 *   Cipher id the body was sealed with.
	 * @param string|null $dictionary
	 *   Dictionary id the codec needs, or NULL.
	 * @param int $rawSize
	 *   Decoded size in bytes, so a short decode is caught without consulting the index.
	 * @param string|null $parent
	 *   Content address this frame is a delta against, or NULL when it stands alone.
	 * @param int $depth
	 *   Position in the delta chain; zero for an anchor.
	 * @param string $body
	 *   The compressed, sealed frame.
	 *
	 * @return string
	 *   The object bytes.
	 */
	public static function wrap(
		string $codec,
		string $cipher,
		?string $dictionary,
		int $rawSize,
		?string $parent,
		int $depth,
		string $body,
	): string {
		return implode("\n", [
			self::MAGIC,
			$codec,
			$cipher,
			$dictionary ?? self::ABSENT,
			(string) $rawSize,
			$parent === null ? self::ABSENT : $parent . ':' . $depth,
		]) .
			"\n" .
			$body;
	}

	/**
	 * Splits a stored frame into its header fields and sealed body.
	 *
	 * @param string $key
	 *   The object key, for error messages.
	 * @param string $stored
	 *   The stored bytes.
	 *
	 * @return array{codec: string, cipher: string, dictionary: string|null, raw: int, parent: string|null, depth: int, body: string}
	 *   The header fields and the sealed body.
	 *
	 * @throws RuntimeException
	 *   When the header is absent, truncated, or names a format this release cannot read.
	 */
	public static function parse(string $key, string $stored): array
	{
		$fields = [];
		$offset = 0;

		for ($i = 0; $i < self::FIELDS; $i++) {
			$at = strpos($stored, "\n", $offset);

			if ($at === false) {
				throw new RuntimeException(
					sprintf(
						'Frame %s has no readable header, so it is truncated or not a frame',
						$key,
					),
				);
			}

			$fields[] = substr($stored, $offset, $at - $offset);
			$offset = $at + 1;
		}

		[$magic, $codec, $cipher, $dictionary, $raw, $delta] = $fields;

		if ($magic !== self::MAGIC) {
			throw new RuntimeException(
				sprintf(
					'Frame %s is marked "%s"; this release reads "%s"',
					$key,
					$magic,
					self::MAGIC,
				),
			);
		}
		if (!ctype_digit($raw)) {
			throw new RuntimeException(
				sprintf('Frame %s does not record its decoded length', $key),
			);
		}

		$parent = null;
		$depth = 0;

		if ($delta !== self::ABSENT) {
			$at = strrpos($delta, ':');

			if ($at === false) {
				throw new RuntimeException(
					sprintf('Frame %s names a delta parent with no chain depth', $key),
				);
			}

			$parent = substr($delta, 0, $at);
			$depth = (int) substr($delta, $at + 1);

			if (!Hash::isValid($parent)) {
				throw new RuntimeException(
					sprintf('Frame %s names a delta parent that is not a valid digest', $key),
				);
			}
		}

		return [
			'codec' => $codec,
			'cipher' => $cipher,
			'dictionary' => $dictionary === self::ABSENT ? null : $dictionary,
			'raw' => (int) $raw,
			'parent' => $parent,
			'depth' => $depth,
			'body' => substr($stored, $offset),
		];
	}
}
