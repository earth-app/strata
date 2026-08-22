<?php

declare(strict_types=1);

namespace Drupal\strata\Segment;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Codec\CodecRegistry;
use Drupal\strata\Crypto\CipherInterface;
use Drupal\strata\Storage\StorageProviderInterface;
use JsonException;
use RuntimeException;

/**
 * Writes a segment manifest to the store.
 *
 * A manifest goes through the same compression and sealing as a payload frame, because it names
 * every subject that changed and what the change was; an unsealed manifest would leak the shape of
 * the site to anyone with read access to the bucket even if every frame were encrypted.
 *
 * The stored object is self-describing: a short plaintext header naming the format version, the
 * codec and the plaintext digest, followed by the sealed body. Nothing about reading it depends on
 * provider metadata, which not every endpoint returns and which the local filesystem has nowhere to
 * put. The object KEY is the associated data, so a segment moved to another key fails to open
 * instead of opening as the wrong segment.
 *
 * The key carries the level and the time range rather than only a digest, so a compaction pass can
 * find the segments covering an hour with one prefix listing rather than reading manifests.
 *
 * @see SegmentManifest
 * @see SegmentReader
 */
final class SegmentWriter
{
	/**
	 * Key prefix segments are written under.
	 */
	public const PREFIX = 'segments';

	/**
	 * Magic bytes and format version leading every stored segment.
	 */
	public const MAGIC = 'STRATA-SEG-1';

	/**
	 * Constructs a writer.
	 *
	 * @param StorageProviderInterface $provider
	 *   Where segments are written.
	 * @param CodecRegistry $codecs
	 *   Used to pick the codec that compresses the manifest.
	 * @param CipherInterface $cipher
	 *   Seals the manifest.
	 * @param int|null $level
	 *   Compression level, or NULL for the codec's default.
	 */
	public function __construct(
		private readonly StorageProviderInterface $provider,
		private readonly CodecRegistry $codecs,
		private readonly CipherInterface $cipher,
		private readonly ?int $level = null,
	) {}

	/**
	 * Writes a manifest and returns its object key.
	 *
	 * @param SegmentManifest $manifest
	 *   The manifest.
	 * @param int|null $second
	 *   The epoch second to file the segment under, or NULL to take it from the first operation. A
	 *   rollup supplies the coarse window's start, because a segment covering an hour belongs to that
	 *   hour rather than to the second its earliest operation happened to land in.
	 *
	 * @return string
	 *   The object key the segment was written to.
	 *
	 * @throws RuntimeException
	 *   When the manifest is empty, or the write fails.
	 * @throws JsonException
	 *   When the manifest holds a string JSON cannot represent. JournalOp refuses such a string on
	 *   capture, so reaching this is a bug rather than bad input; without it `(string) false` would
	 *   seal an empty object under `Hash::of('')` and the flush would report success.
	 */
	public function write(SegmentManifest $manifest, ?int $second = null): string
	{
		if ($manifest->isEmpty()) {
			throw new RuntimeException('An empty segment is not written');
		}

		$bytes = json_encode($manifest, JSON_THROW_ON_ERROR);
		$codec = $this->codecs->writer();
		$digest = Hash::of($bytes);
		$key = self::key($manifest, $digest, $second);

		// the key is the associated data, so a segment relocated to another key fails to open
		$sealed = $this->cipher->seal($codec->compress($bytes, $this->level), $key);

		$this->provider->put(
			$key,
			self::header($codec->id(), $this->cipher->id(), $digest) . $sealed,
		);

		return $key;
	}

	/**
	 * The plaintext header leading a stored segment.
	 *
	 * Newline delimited so a reader can find the body without a length prefix, and plaintext
	 * because a reader must know which codec and cipher to use before it can open anything.
	 *
	 * @param string $codec
	 *   Codec id the body was compressed with.
	 * @param string $cipher
	 *   Cipher id the body was sealed with.
	 * @param string $digest
	 *   Digest of the serialized manifest, before compression.
	 *
	 * @return string
	 *   The header, ending in a newline.
	 */
	public static function header(string $codec, string $cipher, string $digest): string
	{
		return implode("\n", [self::MAGIC, $codec, $cipher, $digest]) . "\n";
	}

	/**
	 * The object key a manifest is written to.
	 *
	 * Shaped `segments/<level>/<epoch-second>/<sequence>-<digest>.seg`. The level and the epoch
	 * second are directories so a compaction pass can list one hour at one level directly; the
	 * sequence leads the filename so a listing comes back in capture order.
	 *
	 * @param SegmentManifest $manifest
	 *   The manifest.
	 * @param string $digest
	 *   Digest of the serialized manifest.
	 * @param int|null $second
	 *   The epoch second to file it under, or NULL to take it from the first operation.
	 *
	 * @return string
	 *   The object key.
	 */
	public static function key(
		SegmentManifest $manifest,
		string $digest,
		?int $second = null,
	): string {
		return sprintf(
			'%s/%d/%d/%020d-%s.seg',
			self::PREFIX,
			$manifest->level,
			$second ?? intdiv($manifest->firstMicrotime, 1_000_000),
			$manifest->firstSequence,
			Hash::abbreviate($digest, 16),
		);
	}

	/**
	 * The key prefix covering one level.
	 *
	 * @param int $level
	 *   Compaction level.
	 *
	 * @return string
	 *   A prefix suitable for a listing.
	 */
	public static function levelPrefix(int $level): string
	{
		return sprintf('%s/%d/', self::PREFIX, $level);
	}

	/**
	 * The key prefix covering one second at one level.
	 *
	 * @param int $level
	 *   Compaction level.
	 * @param int $second
	 *   Unix second.
	 *
	 * @return string
	 *   A prefix suitable for a listing.
	 */
	public static function secondPrefix(int $level, int $second): string
	{
		return sprintf('%s/%d/%d/', self::PREFIX, $level, $second);
	}
}
