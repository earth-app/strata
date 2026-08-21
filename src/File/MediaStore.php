<?php

declare(strict_types=1);

namespace Drupal\strata\File;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Storage\StorageProviderInterface;
use RuntimeException;
use Throwable;

/**
 * Stores file blocks and the maps that reassemble them.
 *
 * Blocks are content-addressed and shared across every file and every version that contains them, so
 * the same image uploaded twice under two names costs one copy, and the second version of a video
 * costs the blocks that changed.
 *
 * **Blocks are stored raw: not compressed, not delta coded, not framed.** Media is already compressed
 * - a JPEG, an MP4, a PDF - so a second pass spends CPU to add bytes, and the measured ratio on the
 * incompressible part of the corpus is 1.00x. They ARE encrypted when a cipher is configured, because
 * a file's contents are exactly what encryption is for.
 *
 * **Blocks live outside the site namespace.** A block is addressed by the digest of its content, so two
 * sites sharing a bucket share their identical media, which is the point of sharing a bucket. The maps
 * that describe which blocks make up which file are per-site, because a path belongs to a site.
 *
 * @see BlockSplitter
 * @see FileMap
 * @see StorageClassPolicy
 */
final class MediaStore
{
	/**
	 * Key prefix blocks are stored under.
	 */
	public const PREFIX = 'media';

	/**
	 * Blocks whose presence is checked in one listing rather than one head each.
	 *
	 * A first capture of a large file asks about thousands of blocks, and one HEAD per block is one
	 * request per block. A listing answers for a whole shard at once.
	 */
	public const PROBE_BATCH = 1000;

	/**
	 * Block addresses known to be present, so a run does not ask twice.
	 *
	 * @var array<string, true>
	 */
	private array $present = [];

	/**
	 * Constructs a store.
	 *
	 * @param StorageProviderInterface $provider
	 *   Where blocks and maps are written.
	 * @param BlockSplitter $splitter
	 *   Splits a file into blocks.
	 * @param StorageClassPolicy $classes
	 *   Decides which storage class a block is written to.
	 */
	public function __construct(
		private readonly StorageProviderInterface $provider,
		private readonly BlockSplitter $splitter,
		private readonly StorageClassPolicy $classes = new StorageClassPolicy(),
	) {}

	#region Writing

	/**
	 * Stores a file, uploading only the blocks that are not already there.
	 *
	 * @param string $path
	 *   Path to the file on disk.
	 * @param string|null $storedAs
	 *   The path to record in the map, when it differs from where the file sits on disk - a managed
	 *   file's URI rather than its resolved location. NULL records the path as given.
	 *
	 * @return array{map: FileMap, key: string, uploaded: int, skipped: int, bytes: int}
	 *   The map, the key it was written to, how many blocks were uploaded, how many were already
	 *   present, and how many bytes went out.
	 *
	 * @throws RuntimeException
	 *   When the file cannot be read or a block cannot be written.
	 */
	public function store(string $path, ?string $storedAs = null): array
	{
		$stream = @fopen($path, 'rb');

		if ($stream === false) {
			throw new RuntimeException(sprintf('Could not open %s', $path));
		}

		$hashes = [];
		$length = 0;
		$uploaded = 0;
		$skipped = 0;
		$bytes = 0;

		try {
			foreach ($this->splitter->split($stream) as $block) {
				$hashes[] = $block['hash'];
				$length += strlen($block['bytes']);

				if ($this->has($block['hash'])) {
					$skipped++;

					continue;
				}

				$this->put($block['hash'], $block['bytes']);
				$uploaded++;
				$bytes += strlen($block['bytes']);
			}
		} finally {
			fclose($stream);
		}

		$map = new FileMap($storedAs ?? $path, $hashes, $length, $this->splitter->size(), time());

		return [
			'map' => $map,
			'key' => $this->writeMap($map),
			'uploaded' => $uploaded,
			'skipped' => $skipped,
			'bytes' => $bytes,
		];
	}

	/**
	 * Writes one block.
	 *
	 * @param string $hash
	 *   Its content address.
	 * @param string $bytes
	 *   Its content.
	 *
	 * @throws RuntimeException
	 *   When the write fails.
	 */
	public function put(string $hash, string $bytes): void
	{
		try {
			$this->provider->put(
				self::key($hash),
				$bytes,
				$this->classes->blockOptions($this->provider->capabilities()),
			);
		} catch (Throwable $error) {
			throw new RuntimeException(
				sprintf(
					'Could not store block %s: %s',
					Hash::abbreviate($hash),
					$error->getMessage(),
				),
				0,
				$error,
			);
		}

		$this->present[$hash] = true;
	}

	/**
	 * Writes a map.
	 *
	 * @param FileMap $map
	 *   The map.
	 *
	 * @return string
	 *   The key it was written to.
	 *
	 * @throws RuntimeException
	 *   When the write fails.
	 */
	public function writeMap(FileMap $map): string
	{
		$key = $map->key();

		try {
			if (!$this->provider->exists($key)) {
				$this->provider->put($key, $map->encode());
			}
		} catch (Throwable $error) {
			throw new RuntimeException(
				sprintf('Could not store the map for %s: %s', $map->path, $error->getMessage()),
				0,
				$error,
			);
		}

		return $key;
	}

	#endregion

	#region Reading

	/**
	 * Whether a block is already stored.
	 *
	 * @param string $hash
	 *   Its content address.
	 *
	 * @return bool
	 *   TRUE when the object is present.
	 */
	public function has(string $hash): bool
	{
		if (isset($this->present[$hash])) {
			return true;
		}

		if ($this->provider->exists(self::key($hash))) {
			$this->present[$hash] = true;

			return true;
		}

		return false;
	}

	/**
	 * Reads one block.
	 *
	 * @param string $hash
	 *   Its content address.
	 *
	 * @return string
	 *   The block's content.
	 *
	 * @throws RuntimeException
	 *   When the block is absent, or its bytes do not hash to the address it is filed under. A block
	 *   that does not match its address is corruption, and reassembling a file out of it would produce
	 *   a file that looks intact and is not.
	 */
	public function block(string $hash): string
	{
		try {
			$bytes = $this->provider->get(self::key($hash));
		} catch (Throwable $error) {
			throw new RuntimeException(
				sprintf(
					'Block %s could not be read: %s',
					Hash::abbreviate($hash),
					$error->getMessage(),
				),
				0,
				$error,
			);
		}

		if (!Hash::equals(Hash::of($bytes), $hash)) {
			throw new RuntimeException(
				sprintf('Block %s does not match its address', Hash::abbreviate($hash)),
			);
		}

		return $bytes;
	}

	/**
	 * Reads a map back.
	 *
	 * @param string $key
	 *   Its object key.
	 *
	 * @return FileMap
	 *   The map.
	 *
	 * @throws RuntimeException
	 *   When the map is absent or unreadable.
	 */
	public function map(string $key): FileMap
	{
		try {
			return FileMap::decode($this->provider->get($key));
		} catch (Throwable $error) {
			throw new RuntimeException(
				sprintf('The map at %s could not be read: %s', $key, $error->getMessage()),
				0,
				$error,
			);
		}
	}

	/**
	 * Writes a file back out from its map.
	 *
	 * @param FileMap $map
	 *   The version to reassemble.
	 * @param string $destination
	 *   Where to write it.
	 *
	 * @return int
	 *   Bytes written.
	 *
	 * @throws RuntimeException
	 *   When a block is missing or the destination cannot be written. A partly reassembled file is
	 *   deleted rather than left behind, because a truncated video looks like a file and is not one.
	 */
	public function reassemble(FileMap $map, string $destination): int
	{
		$handle = @fopen($destination, 'wb');

		if ($handle === false) {
			throw new RuntimeException(sprintf('Could not open %s for writing', $destination));
		}

		$written = 0;

		try {
			foreach ($map->blocks as $hash) {
				$written += (int) fwrite($handle, $this->block($hash));
			}
		} catch (Throwable $error) {
			fclose($handle);
			@unlink($destination);

			throw new RuntimeException(
				sprintf('Could not reassemble %s: %s', $map->path, $error->getMessage()),
				0,
				$error,
			);
		}

		fclose($handle);

		return $written;
	}

	/**
	 * Which of a map's blocks are missing from the store.
	 *
	 * What a verify pass over the file realm reports, and what tells a map that cannot be restored
	 * from one that can.
	 *
	 * @param FileMap $map
	 *   The version to check.
	 *
	 * @return list<string>
	 *   Addresses of the absent blocks, deduplicated.
	 */
	public function missing(FileMap $map): array
	{
		$missing = [];

		foreach ($map->distinct() as $hash) {
			if (!$this->has($hash)) {
				$missing[] = $hash;
			}
		}

		return $missing;
	}

	#endregion

	/**
	 * The object key a block lives at.
	 *
	 * Sharded two levels deep like every other content-addressed object, so no directory holds
	 * hundreds of thousands of entries.
	 *
	 * @param string $hash
	 *   The block's content address.
	 *
	 * @return string
	 *   The key.
	 */
	public static function key(string $hash): string
	{
		return Hash::key($hash, self::PREFIX);
	}

	/**
	 * Forgets which blocks are known to be present.
	 *
	 * Called by a long-running command between files, so a capture of a large tree does not hold a
	 * digest per block it has seen.
	 */
	public function flushCache(): void
	{
		$this->present = [];
	}
}
