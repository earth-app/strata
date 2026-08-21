<?php

declare(strict_types=1);

namespace Drupal\strata\Compaction;

use Drupal\strata\Cas\FrameEnvelope;
use Drupal\strata\Cas\FrameIndexInterface;
use Drupal\strata\Cas\FrameRecord;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Cas\PackIndex;
use Drupal\strata\Cas\Packer;
use Drupal\strata\Codec\CodecRegistry;
use Drupal\strata\Codec\CompressionCodecInterface;
use Drupal\strata\Crypto\CipherInterface;
use Drupal\strata\Storage\StorageProviderInterface;
use RuntimeException;
use Throwable;

/**
 * Rewrites stored objects at a denser compression setting.
 *
 * The flush path compresses at zstd level 1 because it runs inside a web request: 4.28x at
 * 422.9 MB/s, against level 19's 4.87x at 4.5 MB/s. Compaction is not in a request and can spend
 * the
 * CPU, and with a trained dictionary level 19 reaches 5.86x. On a store where tree manifests are
 * 72%
 * of the bytes, that is the largest single reduction available after the base interval itself.
 *
 * A frame's content address does not change, because the address is the digest of the DECODED
 * bytes.
 * That is what makes this safe to do to live data: every tree, segment and delta parent that named
 * the frame still names it, and nothing has to be rewritten to follow it. Only the object the frame
 * lives in changes, and the index row that says where.
 *
 * **Packs are rewritten whole, never frame by frame.** Densifying one 16 KiB frame out of a 1 MiB
 * pack into its own object would trade a compression gain for one Class A operation per frame,
 * which
 * is the cost Packer exists to avoid. One pack in, one pack out, one GET and one PUT.
 *
 * Every frame in a pack is carried forward, including ones nothing references any more. Compaction
 * changes how bytes are stored and never which bytes exist; removing content is a prune, and a
 * prune
 * produces a receipt.
 *
 * @see Compactor
 * @see Packer
 * @see PruneReceipt
 */
final class Recompressor
{
	/**
	 * How much smaller a rewrite must be to be worth the requests it costs.
	 *
	 * An object that shrinks by less than this is left alone. The saving has to cover a GET, a PUT
	 * and the index writes.
	 */
	public const MIN_GAIN = 0.05;

	/**
	 * Constructs a recompressor.
	 *
	 * @param StorageProviderInterface $provider
	 *   Where objects are read from and written to.
	 * @param FrameIndexInterface $index
	 *   Repointed at each frame's new location and size.
	 * @param ObjectStore $store
	 *   Reads each frame back through whatever encoded it.
	 * @param CodecRegistry $codecs
	 *   Supplies the dense writer, which may be one that shells out.
	 * @param CipherInterface $cipher
	 *   Re-seals each rewritten frame.
	 * @param Packer $packer
	 *   Rebuilds the pack, so the rewritten object carries a directory like any other.
	 * @param int $level
	 *   Compression level to rewrite at.
	 * @param string|null $dictionary
	 *   Dictionary bytes to compress against, or NULL.
	 * @param string|null $dictionaryId
	 *   Identifier recorded in each rewritten frame, required whenever a dictionary is given.
	 */
	public function __construct(
		private readonly StorageProviderInterface $provider,
		private readonly FrameIndexInterface $index,
		private readonly ObjectStore $store,
		private readonly CodecRegistry $codecs,
		private readonly CipherInterface $cipher,
		private readonly Packer $packer = new Packer(),
		private readonly int $level = 19,
		private readonly ?string $dictionary = null,
		private readonly ?string $dictionaryId = null,
	) {}

	#region Packs

	/**
	 * Rewrites one pack at the dense setting.
	 *
	 * @param string $key
	 *   The pack's object key.
	 * @param bool $force
	 *   TRUE to write the new object however little it saves. A key rotation re-seals bytes without
	 *   shrinking them, and refusing on the gain would leave it unable to finish.
	 *
	 * @return array{frames: int, before: int, after: int, saved: int, rewritten: bool}
	 *   How many frames were carried forward, the object's size before and after, how many bytes
	 *   that saved, and whether the new object was actually written.
	 *
	 * @throws RuntimeException
	 *   When the pack cannot be read or a frame inside it does not decode. Either is a corruption
	 *   symptom and neither is safe to skip silently while rewriting the rest.
	 */
	public function recompressPack(string $key, bool $force = false): array
	{
		$object = $this->provider->get($key);
		$entries = PackIndex::decode($key, $object);
		$before = strlen($object);

		$this->packer->discard();
		$carried = [];

		foreach ($entries as $entry) {
			$hash = (string) $entry['hash'];
			$parent = ($entry['parent'] ?? null) === null ? null : (string) $entry['parent'];
			$plain = $this->decoded($hash);
			$sealed = $this->encode($plain, $hash, $parent);

			$this->packer->add($hash, $sealed, [
				'raw' => strlen($plain),
				'codec' => $this->writer()->id(),
				'cipher' => $this->cipher->id(),
				// a delta is coded against its parent, so it carries no dictionary of its own
				'dictionary' => $parent === null ? $this->dictionaryFor() : null,
				'parent' => $parent,
				'depth' => (int) $entry['depth'],
			]);

			$carried[] = $hash;
		}

		$pack = $this->packer->flush();

		if ($pack === null) {
			return [
				'frames' => 0,
				'before' => $before,
				'after' => $before,
				'saved' => 0,
				'rewritten' => false,
			];
		}

		$after = strlen((string) $pack['bytes']);

		if (!$force && $after > $before * (1.0 - self::MIN_GAIN)) {
			return [
				'frames' => count($carried),
				'before' => $before,
				'after' => $before,
				'saved' => 0,
				'rewritten' => false,
			];
		}

		// the new object is written before the index moves, and the old one is left for the prune:
		// a row pointing at an object that was never written is data loss, a stale object is garbage
		$this->provider->put(
			Hash::key((string) $pack['id'], ObjectStore::PACK_PREFIX),
			$pack['bytes'],
		);

		foreach ($pack['entries'] as $entry) {
			$this->relocatePacked((string) $pack['id'], $entry);
		}

		return [
			'frames' => count($carried),
			'before' => $before,
			'after' => $after,
			'saved' => $before - $after,
			'rewritten' => true,
		];
	}

	/**
	 * Rewrites one standalone frame at the dense setting.
	 *
	 * Only frames at or above the pack target are stored this way, so this path is rare and stays
	 * standalone rather than being folded into a pack.
	 *
	 * @param string $hash
	 *   Frame content address.
	 * @param bool $force
	 *   TRUE to write the new object however little it saves, for the same reason
	 *   Recompressor::recompressPack() takes the flag.
	 *
	 * @return int
	 *   Bytes saved, or zero when the frame was left as it was. Zero is also a re-seal that saved
	 *   nothing, so it is not a statement that nothing was written when $force is TRUE.
	 *
	 * @throws RuntimeException
	 *   When the frame is not indexed, is packed, or does not decode.
	 */
	public function recompressFrame(string $hash, bool $force = false): int
	{
		$record = $this->index->get($hash);

		if ($record === null) {
			throw new RuntimeException(
				sprintf(
					'Frame %s is not indexed, so it cannot be rewritten',
					Hash::abbreviate($hash),
				),
			);
		}
		if ($record->isPacked()) {
			throw new RuntimeException(
				sprintf(
					'Frame %s lives in a pack and is rewritten with it, not on its own',
					Hash::abbreviate($hash),
				),
			);
		}

		$plain = $this->decoded($hash);
		$sealed = $this->encode($plain, $hash, $record->deltaParent);

		if (!$force && strlen($sealed) > $record->storedSize * (1.0 - self::MIN_GAIN)) {
			return 0;
		}

		// a delta is coded against its parent, so it carries no dictionary of its own
		$dictionary = $record->deltaParent === null ? $this->dictionaryFor() : null;

		$this->provider->put(
			Hash::key($hash, ObjectStore::FRAME_PREFIX),
			FrameEnvelope::wrap(
				$this->writer()->id(),
				$this->cipher->id(),
				$dictionary,
				strlen($plain),
				$record->deltaParent,
				$record->deltaDepth,
				$sealed,
			),
		);

		$this->index->relocate(
			new FrameRecord(
				$hash,
				strlen($plain),
				strlen($sealed),
				$this->writer()->id(),
				$this->cipher->id(),
				$dictionary,
				null,
				0,
				$record->references,
				$record->created,
				$record->deltaParent,
				$record->deltaDepth,
			),
		);

		return $record->storedSize - strlen($sealed);
	}

	#endregion

	#region Batches

	/**
	 * Rewrites a run of packs, stopping at a byte budget.
	 *
	 * Compaction runs inside a cron window, so the caller sets how much work one pass may do rather
	 * than discovering it afterwards. A pack that fails is reported and skipped, because one corrupt
	 * object must not stop the pass from densifying the rest.
	 *
	 * @param list<string> $keys
	 *   Pack object keys.
	 * @param int $budget
	 *   Most stored bytes to read in this pass; zero for no limit.
	 *
	 * @return array{packs: int, frames: int, before: int, after: int, skipped: int, problems: list<string>}
	 *   How many packs were rewritten, how many frames that moved, what those packs occupied before
	 *   and occupy now, how many packs were already dense enough, and what could not be read.
	 */
	public function recompressPacks(array $keys, int $budget = 0): array
	{
		$packs = 0;
		$frames = 0;
		$before = 0;
		$after = 0;
		$skipped = 0;
		$problems = [];
		$spent = 0;

		foreach ($keys as $key) {
			if ($budget > 0 && $spent >= $budget) {
				break;
			}

			try {
				$result = $this->recompressPack($key);
			} catch (Throwable $error) {
				$problems[] = sprintf('%s: %s', $key, $error->getMessage());

				continue;
			}

			$spent += $result['before'];

			if (!$result['rewritten']) {
				$skipped++;

				continue;
			}

			$packs++;
			$frames += $result['frames'];
			$before += $result['before'];
			$after += $result['after'];
		}

		return [
			'packs' => $packs,
			'frames' => $frames,
			'before' => $before,
			'after' => $after,
			'skipped' => $skipped,
			'problems' => $problems,
		];
	}

	#endregion

	/**
	 * Reads a frame back and checks it against its address.
	 *
	 * @param string $hash
	 *   Frame content address.
	 *
	 * @return string
	 *   The decoded frame.
	 *
	 * @throws RuntimeException
	 *   When the frame does not read back, or reads back as different content. Re-encoding content
	 *   that does not match its address would file the wrong bytes under a name something else
	 *   depends on.
	 */
	private function decoded(string $hash): string
	{
		$plain = $this->store->frame($hash);

		if ($plain === null) {
			throw new RuntimeException(
				sprintf('Frame %s did not read back', Hash::abbreviate($hash)),
			);
		}
		if (!Hash::equals(Hash::of($plain), $hash)) {
			throw new RuntimeException(
				sprintf(
					'Frame %s decoded to different content, so it was not rewritten',
					Hash::abbreviate($hash),
				),
			);
		}

		return $plain;
	}

	/**
	 * Compresses and seals a frame at the dense setting.
	 *
	 * @param string $plain
	 *   The decoded frame.
	 * @param string $hash
	 *   Its content address, used as the associated data exactly as the flush path does.
	 * @param string|null $parent
	 *   The frame this one is a delta of, or NULL when it stands alone. A delta has to be re-encoded
	 *   against the same parent it was coded against: the index goes on calling it a delta, so a
	 *   frame recompressed against the realm dictionary instead would be decoded with the parent's
	 *   bytes as its dictionary and come back as noise.
	 *
	 * @return string
	 *   The encoded frame.
	 *
	 * @throws RuntimeException
	 *   When the codec or the cipher fails, or the parent does not read back.
	 */
	private function encode(string $plain, string $hash, ?string $parent = null): string
	{
		$dictionary = $parent === null ? $this->dictionaryBytes() : $this->decoded($parent);

		return $this->cipher->seal(
			$this->writer()->compress($plain, $this->level, $dictionary),
			$hash,
		);
	}

	/**
	 * Points the index at a frame's new home inside a rewritten pack.
	 *
	 * @param string $pack
	 *   The new pack's id.
	 * @param array<string, mixed> $entry
	 *   The directory entry describing where the frame now sits.
	 */
	private function relocatePacked(string $pack, array $entry): void
	{
		$hash = (string) $entry['hash'];
		$existing = $this->index->get($hash);
		$references = $existing === null ? 1 : $existing->references;
		$created = $existing === null ? 0 : $existing->created;

		$this->index->relocate(
			new FrameRecord(
				$hash,
				(int) ($entry['raw'] ?? 0),
				(int) $entry['length'],
				(string) $entry['codec'],
				(string) $entry['cipher'],
				($entry['dictionary'] ?? null) === null ? null : (string) $entry['dictionary'],
				$pack,
				(int) $entry['offset'],
				$references,
				$created,
				($entry['parent'] ?? null) === null ? null : (string) $entry['parent'],
				(int) ($entry['depth'] ?? 0),
			),
		);
	}

	/**
	 * The codec that writes the dense form.
	 *
	 * The bulk writer rather than the per-frame one, because a codec whose per-call overhead rules
	 * it out of the request path is exactly what compaction wants.
	 *
	 * @return CompressionCodecInterface
	 *   The codec.
	 */
	private function writer(): CompressionCodecInterface
	{
		return $this->codecs->bulkWriter();
	}

	/**
	 * The dictionary bytes to compress against, if the codec can take one.
	 *
	 * @return string|null
	 *   The bytes, or NULL.
	 */
	private function dictionaryBytes(): ?string
	{
		return $this->writer()->supportsDictionary() ? $this->dictionary : null;
	}

	/**
	 * The dictionary id to record, if a dictionary is actually being used.
	 *
	 * @return string|null
	 *   The id, or NULL.
	 */
	private function dictionaryFor(): ?string
	{
		return $this->dictionaryBytes() === null ? null : $this->dictionaryId;
	}
}
