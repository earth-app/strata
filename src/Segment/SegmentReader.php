<?php

declare(strict_types=1);

namespace Drupal\strata\Segment;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Codec\CodecRegistry;
use Drupal\strata\Crypto\CipherInterface;
use Drupal\strata\Storage\StorageProviderInterface;
use Generator;
use RuntimeException;

/**
 * Reads segment manifests back out of the store.
 *
 * The codec a segment was written with is read from the object's own plaintext header rather than
 * from the store's current settings, so changing a setting never makes an existing segment
 * unreadable and nothing depends on provider metadata. The object key is the associated data the
 * body was sealed with.
 *
 * @see SegmentWriter
 * @see SegmentManifest
 */
final class SegmentReader
{
	/**
	 * Constructs a reader.
	 *
	 * @param StorageProviderInterface $provider
	 *   Where segments live.
	 * @param CodecRegistry $codecs
	 *   Used to find the codec a segment was written with.
	 * @param CipherInterface $cipher
	 *   Opens the manifest.
	 */
	public function __construct(
		private readonly StorageProviderInterface $provider,
		private readonly CodecRegistry $codecs,
		private readonly CipherInterface $cipher,
	) {}

	/**
	 * Reads one segment.
	 *
	 * @param string $key
	 *   The object key the segment was written to.
	 *
	 * @return SegmentManifest
	 *   The manifest.
	 *
	 * @throws RuntimeException
	 *   When the segment is absent, fails to open, does not match its recorded digest, or carries a
	 *   format version this release cannot read.
	 */
	public function read(string $key): SegmentManifest
	{
		if (!$this->provider->exists($key)) {
			throw new RuntimeException(sprintf('Segment %s is not present', $key));
		}

		[$codecId, $digest, $body] = self::split($key, $this->provider->get($key));

		$bytes = $this->codecs->reader($codecId)->decompress($this->cipher->open($body, $key));

		if (!Hash::equals(Hash::of($bytes), $digest)) {
			throw new RuntimeException(
				sprintf('Segment %s does not match the digest in its header', $key),
			);
		}

		/** @var array<string, mixed> $decoded */
		$decoded = json_decode($bytes, true) ?? [];

		return SegmentManifest::fromArray($decoded);
	}

	/**
	 * Splits a stored segment into its header fields and sealed body.
	 *
	 * @param string $key
	 *   The object key, for error messages.
	 * @param string $stored
	 *   The stored bytes.
	 *
	 * @return array{0: string, 1: string, 2: string}
	 *   The codec id, the plaintext digest and the sealed body.
	 *
	 * @throws RuntimeException
	 *   When the header is absent, truncated, or names a format this release cannot read.
	 */
	private static function split(string $key, string $stored): array
	{
		$fields = [];
		$offset = 0;

		for ($i = 0; $i < 4; $i++) {
			$at = strpos($stored, "\n", $offset);

			if ($at === false) {
				throw new RuntimeException(
					sprintf(
						'Segment %s has no readable header, so it is truncated or not a segment',
						$key,
					),
				);
			}

			$fields[] = substr($stored, $offset, $at - $offset);
			$offset = $at + 1;
		}

		[$magic, $codec, , $digest] = $fields;

		if ($magic !== SegmentWriter::MAGIC) {
			throw new RuntimeException(
				sprintf(
					'Segment %s is marked "%s"; this release reads "%s"',
					$key,
					$magic,
					SegmentWriter::MAGIC,
				),
			);
		}
		if (!Hash::isValid($digest)) {
			throw new RuntimeException(sprintf('Segment %s carries no valid digest', $key));
		}

		return [$codec, $digest, substr($stored, $offset)];
	}

	/**
	 * Every segment key at a level, in capture order.
	 *
	 * @param int $level
	 *   Compaction level.
	 *
	 * @return Generator<int, string>
	 *   Object keys, oldest first.
	 *
	 * @throws RuntimeException
	 *   When the listing fails.
	 */
	public function keys(int $level = 0): Generator
	{
		$cursor = null;

		do {
			$page = $this->provider->list(SegmentWriter::levelPrefix($level), $cursor, 1000);

			yield from $page->keys();

			$cursor = $page->cursor;
		} while ($page->hasMore());
	}

	/**
	 * Every segment at a level, in capture order.
	 *
	 * @param int $level
	 *   Compaction level.
	 *
	 * @return Generator<string, SegmentManifest>
	 *   Object key keyed to its manifest, oldest first.
	 *
	 * @throws RuntimeException
	 *   When a segment cannot be read.
	 */
	public function all(int $level = 0): Generator
	{
		foreach ($this->keys($level) as $key) {
			yield $key => $this->read($key);
		}
	}

	/**
	 * Segment keys covering a time range at a level.
	 *
	 * Filters on the key rather than by reading manifests, since the key carries the epoch second
	 * the segment starts at. A compaction pass over an hour therefore costs one listing.
	 *
	 * @param int $fromSecond
	 *   Unix second to start at, inclusive.
	 * @param int $toSecond
	 *   Unix second to end at, inclusive.
	 * @param int $level
	 *   Compaction level.
	 *
	 * @return list<string>
	 *   Object keys, oldest first.
	 *
	 * @throws RuntimeException
	 *   When the listing fails.
	 */
	public function keysBetween(int $fromSecond, int $toSecond, int $level = 0): array
	{
		$keys = [];

		foreach ($this->keys($level) as $key) {
			$second = self::secondOf($key);

			if ($second !== null && $second >= $fromSecond && $second <= $toSecond) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/**
	 * The epoch second encoded in a segment key.
	 *
	 * @param string $key
	 *   The object key.
	 *
	 * @return int|null
	 *   The second, or NULL when the key is not shaped like a segment key.
	 */
	public static function secondOf(string $key): ?int
	{
		if (preg_match('#^' . SegmentWriter::PREFIX . '/\d+/(\d+)/#', $key, $matches) !== 1) {
			return null;
		}

		return (int) $matches[1];
	}
}
