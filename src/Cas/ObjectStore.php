<?php

declare(strict_types=1);

namespace Drupal\strata\Cas;

use Drupal\strata\Codec\CodecRegistry;
use Drupal\strata\Codec\Dictionary\DictionaryStore;
use Drupal\strata\Delta\ChainDepthPolicy;
use Drupal\strata\Delta\DeltaCodec;
use Drupal\strata\Crypto\CipherInterface;
use Drupal\strata\Storage\ByteRange;
use Drupal\strata\Storage\StorageProviderInterface;
use Throwable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Puts a value into the store and gets it back.
 *
 * The one path every captured byte travels: split into frames, deduplicate against the index,
 * compress, seal, batch into a pack, upload. Reading reverses it, fetching only the ranges a value
 * actually needs.
 *
 * Order matters and is fixed. Compression runs before sealing, since ciphertext does not compress.
 * The content address is taken from the DECODED frame, so the address is stable across a change of
 * codec, cipher, level or dictionary; two sites with different settings storing the same content
 * agree on what to call it. And the address is passed to the cipher as associated data, so a frame
 * relocated to another key fails to open rather than opening as the wrong content.
 *
 * Every frame carries the codec, cipher and dictionary that wrote it in its index record rather
 * than inheriting the store's current settings, so changing a setting never orphans what is already
 * written. The same fields are written into the OBJECT as well - a standalone frame carries them in
 * a plaintext header, a packed frame in the pack's own directory - so the whole index is
 * reconstructible from the bucket after the local tables are dropped.
 *
 * @see Framer
 * @see Packer
 * @see FrameEnvelope
 * @see PackIndex
 * @see FrameIndexInterface
 */
final class ObjectStore
{
	/**
	 * Key prefix standalone frames are written under.
	 */
	public const FRAME_PREFIX = 'frames';

	/**
	 * Key prefix packs are written under.
	 */
	public const PACK_PREFIX = 'packs';

	/**
	 * Constructs a store.
	 *
	 * @param StorageProviderInterface $provider
	 *   Where objects are written.
	 * @param FrameIndexInterface $index
	 *   The deduplication and reference index.
	 * @param CodecRegistry $codecs
	 *   Used to pick a writer and to find a reader for whatever wrote an existing frame.
	 * @param CipherInterface $cipher
	 *   Seals frames on the way out.
	 * @param Framer $framer
	 *   Splits values into frames.
	 * @param Packer $packer
	 *   Batches small frames.
	 * @param int|null $level
	 *   Compression level, or NULL for the codec's default.
	 * @param string|null $dictionary
	 *   Dictionary bytes to compress with, or NULL.
	 * @param string|null $dictionaryId
	 *   Identifier recorded in each frame record so the dictionary can be found again. Required
	 *   whenever $dictionary is given, since a frame that cannot name its dictionary cannot be
	 *   decoded.
	 * @param DictionaryStore|null $dictionaries
	 *   Where a dictionary a frame names but this store is not holding can be fetched from. Without
	 *   it, retraining would strand every frame written against the previous version.
	 * @param ChainDepthPolicy|null $chainPolicy
	 *   Bounds how long a delta chain may grow, or NULL to store every frame standalone. A chain is a
	 *   dependency chain - frame N needs frame N-1 - so without a cap a hot subject would build a
	 *   chain thousands of links long and every read of it would walk the lot.
	 *
	 * @throws InvalidArgumentException
	 *   When a dictionary is supplied without an id.
	 */
	public function __construct(
		private readonly StorageProviderInterface $provider,
		private readonly FrameIndexInterface $index,
		private readonly CodecRegistry $codecs,
		private readonly CipherInterface $cipher,
		private readonly Framer $framer = new Framer(),
		private readonly Packer $packer = new Packer(),
		private readonly ?int $level = null,
		private readonly ?string $dictionary = null,
		private readonly ?string $dictionaryId = null,
		private readonly ?DictionaryStore $dictionaries = null,
		private readonly ?ChainDepthPolicy $chainPolicy = null,
	) {
		if ($dictionary !== null && ($dictionaryId === null || $dictionaryId === '')) {
			throw new InvalidArgumentException(
				'A dictionary must be given an id, or the frames it writes cannot be decoded later',
			);
		}
	}

	#region Writing

	/**
	 * Stores a value and returns the frame map that reassembles it.
	 *
	 * Frames the index already holds are skipped entirely: no compression, no sealing, no upload.
	 * The map still lists them, so two values sharing content share frames.
	 *
	 * @param string $value
	 *   The bytes to store.
	 * @param list<string> $previous
	 *   The frame map of the subject's previous version, when there is one. A rewritten value
	 *   compresses against its own previous version far better than against anything else - measured
	 *   at 63.70x on the rewrite class, where a 5,967-byte blob with one flag flipped encodes to 94
	 *   bytes - and this is where that gain comes from. Empty when there is no previous version or
	 *   when the caller does not have it, which stores standalone.
	 *
	 * @return list<string>
	 *   Frame content addresses, in order. An empty value produces an empty map.
	 *
	 * @throws RuntimeException
	 *   When a codec, the cipher or the provider fails.
	 */
	public function write(string $value, array $previous = []): array
	{
		$map = [];
		$against = $this->deltaSource($value, $previous);

		foreach ($this->framer->frames($value) as $frame) {
			$map[] = $frame['hash'];

			if ($this->index->has($frame['hash'])) {
				$this->index->reference($frame['hash']);

				continue;
			}

			$this->store($frame['hash'], $frame['bytes'], $against);
			$against = null;
		}

		$this->drain();

		return $map;
	}

	/**
	 * The previous version a value can be delta coded against, if any.
	 *
	 * Only a single-frame value against a single-frame previous version. A value spanning several
	 * frames is already large enough that the framer's own deduplication covers the unchanged parts,
	 * and coding frame two of the new value against frame one of the old one would produce a chain
	 * whose links do not correspond to anything.
	 *
	 * @param string $value
	 *   The value being stored.
	 * @param list<string> $previous
	 *   The previous version's frame map.
	 *
	 * @return array{hash: string, bytes: string, depth: int}|null
	 *   The parent frame's address, its decoded bytes and its chain depth, or NULL when this value
	 *   should be stored standalone.
	 */
	private function deltaSource(string $value, array $previous): ?array
	{
		if (
			$this->chainPolicy === null ||
			count($previous) !== 1 ||
			strlen($value) > $this->framer->size() ||
			$value === ''
		) {
			return null;
		}

		$parent = $previous[0];
		$record = $this->index->get($parent);

		if ($record === null || $this->chainPolicy->mustAnchor($record->deltaDepth)) {
			return null;
		}

		try {
			$bytes = $this->frame($parent);
		} catch (Throwable) {
			// a parent that will not read is the verifier's problem; this value stores standalone
			return null;
		}

		return $bytes === null
			? null
			: ['hash' => $parent, 'bytes' => $bytes, 'depth' => $record->deltaDepth];
	}

	/**
	 * Flushes any partially filled pack.
	 *
	 * Called at the end of a segment so nothing is left buffered in memory across a request.
	 *
	 * @return int
	 *   How many packs were written.
	 *
	 * @throws RuntimeException
	 *   When the provider fails.
	 */
	public function commit(): int
	{
		return $this->drain(true);
	}

	/**
	 * Encodes one frame and either buffers or uploads it.
	 *
	 * @param string $hash
	 *   Content address of the decoded frame.
	 * @param string $bytes
	 *   The decoded frame.
	 * @param array{hash: string, bytes: string, depth: int}|null $against
	 *   The previous version to code against, or NULL to store standalone.
	 *
	 * @throws RuntimeException
	 *   When a codec, the cipher or the provider fails.
	 */
	private function store(string $hash, string $bytes, ?array $against = null): void
	{
		$codec = $this->codecs->writer();
		$dictionary = $codec->supportsDictionary() ? $this->dictionary : null;
		$dictionaryId = $dictionary === null ? null : $this->dictionaryId;
		$parent = null;
		$depth = 0;

		if ($against !== null && $codec->supportsDictionary()) {
			$candidate = $codec->compress($bytes, $this->level, $against['bytes']);
			$standalone = $codec->compress($bytes, $this->level, $dictionary);

			// the previous version replaces the realm dictionary rather than joining it: a codec takes
			// one dictionary, and the version this value was derived from is by far the better of the two
			if (strlen($candidate) < strlen($standalone) * (1.0 - DeltaCodec::MIN_GAIN)) {
				$dictionary = $against['bytes'];
				$dictionaryId = null;
				$parent = $against['hash'];
				$depth = $this->chainPolicy?->nextDepth($against['depth']) ?? 1;
			}
		}

		$encoded = $this->cipher->seal($codec->compress($bytes, $this->level, $dictionary), $hash);

		$meta = [
			'raw' => strlen($bytes),
			'codec' => $codec->id(),
			'cipher' => $this->cipher->id(),
			'dictionary' => $dictionaryId,
			'parent' => $parent,
			'depth' => $depth,
		];

		// a frame at or above the pack target gains nothing from batching
		if ($this->packer->shouldStoreAlone(strlen($encoded))) {
			$this->provider->put(
				Hash::key($hash, self::FRAME_PREFIX),
				FrameEnvelope::wrap(
					$codec->id(),
					$this->cipher->id(),
					$dictionaryId,
					strlen($bytes),
					$parent,
					$depth,
					$encoded,
				),
			);

			$this->index->record(
				new FrameRecord(
					$hash,
					strlen($bytes),
					strlen($encoded),
					$codec->id(),
					$this->cipher->id(),
					$dictionaryId,
					null,
					0,
					1,
					time(),
					$parent,
					$depth,
				),
			);

			return;
		}

		$this->packer->add($hash, $encoded, $meta);
	}

	/**
	 * Uploads full packs, and a partial one when forced.
	 *
	 * @param bool $force
	 *   TRUE to upload a pack that has not reached its target.
	 *
	 * @return int
	 *   How many packs were written.
	 *
	 * @throws RuntimeException
	 *   When the provider fails.
	 */
	private function drain(bool $force = false): int
	{
		$written = 0;

		while ($this->packer->pending() > 0 && ($force || $this->packer->isFull())) {
			$pack = $this->packer->flush();

			if ($pack === null) {
				break;
			}

			$key = Hash::key($pack['id'], self::PACK_PREFIX);
			$this->provider->put($key, $pack['bytes']);
			$written++;

			foreach ($pack['entries'] as $entry) {
				$this->index->record(
					new FrameRecord(
						(string) $entry['hash'],
						(int) ($entry['raw'] ?? $entry['length']),
						(int) $entry['length'],
						(string) ($entry['codec'] ?? $this->codecs->writer()->id()),
						(string) ($entry['cipher'] ?? $this->cipher->id()),
						($entry['dictionary'] ?? null) === null
							? null
							: (string) $entry['dictionary'],
						(string) $pack['id'],
						(int) $entry['offset'],
						1,
						time(),
						($entry['parent'] ?? null) === null ? null : (string) $entry['parent'],
						(int) ($entry['depth'] ?? 0),
					),
				);
			}
		}

		return $written;
	}

	#endregion

	#region Reading

	/**
	 * Rebuilds a value from its frame map.
	 *
	 * @param list<string> $map
	 *   Frame content addresses, in order, as returned by ObjectStore::write().
	 *
	 * @return string
	 *   The value.
	 *
	 * @throws RuntimeException
	 *   When a frame is unknown, absent, undecodable, or does not match its address.
	 */
	public function read(array $map): string
	{
		return $this->framer->reassemble($map, fn(string $hash): ?string => $this->frame($hash));
	}

	/**
	 * Fetches and decodes one frame.
	 *
	 * A packed frame is fetched with a ranged read, so reading one frame out of a 1 MiB pack costs
	 * that frame's bytes rather than the whole pack. An endpoint without range support falls back
	 * to the whole object.
	 *
	 * @param string $hash
	 *   Content address of the decoded frame.
	 *
	 * @return string|null
	 *   The decoded frame, or NULL when the index does not know it.
	 *
	 * @throws RuntimeException
	 *   When the frame is known but absent, its codec cannot be read here, its dictionary is
	 *   missing, or it fails to open.
	 */
	public function frame(string $hash): ?string
	{
		$record = $this->index->get($hash);

		if ($record === null) {
			return null;
		}

		$encoded = $record->isPacked()
			? $this->fetchPacked($record)
			: $this->unwrap($hash, $record);

		$plain = $this->codecs
			->reader($record->codec)
			->decompress(
				$this->cipher->open($encoded, $hash),
				$record->isDelta()
					? $this->parentBytes($hash, (string) $record->deltaParent)
					: $this->dictionaryFor($hash, $record->dictionary),
			);

		if (strlen($plain) !== $record->rawSize) {
			throw new RuntimeException(
				sprintf(
					'Frame %s decoded to %d bytes but the index records %d',
					Hash::abbreviate($hash),
					strlen($plain),
					$record->rawSize,
				),
			);
		}

		return $plain;
	}

	/**
	 * Fetches a standalone frame and strips its header.
	 *
	 * The header's own account of the codec and cipher is checked against the index rather than
	 * trusted over it. They disagree only when the index describes a different object than the one
	 * at that key, and decoding either way would produce garbage that looks like data.
	 *
	 * @param string $hash
	 *   Content address of the decoded frame.
	 * @param FrameRecord $record
	 *   The frame's record.
	 *
	 * @return string
	 *   The sealed body.
	 *
	 * @throws RuntimeException
	 *   When the object is absent, unreadable, or describes itself differently than the index does.
	 */
	private function unwrap(string $hash, FrameRecord $record): string
	{
		$key = Hash::key($hash, self::FRAME_PREFIX);
		$envelope = FrameEnvelope::parse($key, $this->provider->get($key));

		if ($envelope['codec'] !== $record->codec || $envelope['cipher'] !== $record->cipher) {
			throw new RuntimeException(
				sprintf(
					'Frame %s is stored as %s/%s but the index records %s/%s',
					Hash::abbreviate($hash),
					$envelope['codec'],
					$envelope['cipher'],
					$record->codec,
					$record->cipher,
				),
			);
		}

		return $envelope['body'];
	}

	/**
	 * Fetches a frame that lives inside a pack.
	 *
	 * @param FrameRecord $record
	 *   The frame's record.
	 *
	 * @return string
	 *   The encoded frame.
	 *
	 * @throws RuntimeException
	 *   When the pack is absent or shorter than the record says.
	 */
	private function fetchPacked(FrameRecord $record): string
	{
		$key = Hash::key((string) $record->pack, self::PACK_PREFIX);

		if (!$this->provider->capabilities()->rangeRead) {
			return Packer::extract(
				$this->provider->get($key),
				$record->offset,
				$record->storedSize,
			);
		}

		return $this->provider->get($key, new ByteRange($record->offset, $record->storedSize));
	}

	#endregion

	/**
	 * The decoded parent of a delta frame.
	 *
	 * Reading a delta frame reads its parent, and that parent may itself be a delta, so a read walks
	 * the chain. `ChainDepthPolicy` is what keeps that walk short; this only refuses when the parent is
	 * not there at all, because a delta frame without its parent decodes to nothing usable and
	 * returning it would be worse than failing.
	 *
	 * @param string $hash
	 *   The frame being read, for the error.
	 * @param string $parent
	 *   Its delta parent's address.
	 *
	 * @return string
	 *   The parent's decoded bytes.
	 *
	 * @throws RuntimeException
	 *   When the parent is unknown or unreadable.
	 */
	private function parentBytes(string $hash, string $parent): string
	{
		$bytes = $this->frame($parent);

		if ($bytes === null) {
			throw new RuntimeException(
				sprintf(
					'Frame %s is coded against %s, which the index does not know',
					Hash::abbreviate($hash),
					Hash::abbreviate($parent),
				),
			);
		}

		return $bytes;
	}

	/**
	 * The dictionary bytes one frame has to be decoded against.
	 *
	 * Usually the one this store is compressing with, since most frames were written by a store
	 * configured the same way. After a retrain they are not the same, and a frame written against an
	 * older version has to be given that version - which is why an older version is never pruned while
	 * a frame names it.
	 *
	 * @param string $hash
	 *   The frame's content address, for the error.
	 * @param string|null $id
	 *   The dictionary id the frame recorded, or NULL when it was written without one.
	 *
	 * @return string|null
	 *   The dictionary bytes, or NULL when the frame needs none.
	 *
	 * @throws RuntimeException
	 *   When the frame names a dictionary this store cannot produce. Decoding against the wrong
	 *   dictionary yields plausible garbage, so this refuses instead.
	 */
	private function dictionaryFor(string $hash, ?string $id): ?string
	{
		if ($id === null) {
			return null;
		}
		if ($id === $this->dictionaryId) {
			return $this->dictionary;
		}

		$bytes = $this->dictionaries?->get($id);

		if ($bytes === null) {
			throw new RuntimeException(
				sprintf(
					'Frame %s was written with dictionary %s, which is not available here',
					Hash::abbreviate($hash),
					$id,
				),
			);
		}

		return $bytes;
	}

	/**
	 * The dictionary this store can decode against, if any.
	 *
	 * A frame naming a different dictionary cannot be read here, so a verify pass asks for this
	 * rather than assuming every dictionary a frame might name is available.
	 *
	 * @return string|null
	 *   The dictionary id, or NULL when the store holds none.
	 */
	public function dictionaryId(): ?string
	{
		return $this->dictionary === null ? null : $this->dictionaryId;
	}

	/**
	 * The index this store writes to.
	 *
	 * @return FrameIndexInterface
	 *   The index.
	 */
	public function index(): FrameIndexInterface
	{
		return $this->index;
	}
}
