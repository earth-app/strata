<?php

declare(strict_types=1);

namespace Drupal\strata_b2;

use Drupal\strata\Storage\ObjectKeys;
use Drupal\strata\Storage\ObjectMeta;

/**
 * One version of one file, as B2 describes it.
 *
 * B2 versions every upload, and a delete removes one named version rather than the name. Nothing
 * else in Strata has a concept of a version, so the id travels only as far as the delete that needs
 * it - which is why a head has to carry it and an ObjectMeta cannot.
 *
 * The content SHA-1 stands in for an entity tag. B2 computes it on the way in and refuses an upload
 * whose declared hash disagrees, so it is a stronger identity than an opaque tag; a large file has
 * no whole-file hash and reports the literal `none`, which is dropped here rather than stored as if
 * it were one.
 *
 * @see B2StorageProvider
 */
final class B2File
{
	/**
	 * What B2 reports as the SHA-1 of a file assembled from parts.
	 */
	public const NO_HASH = 'none';

	/**
	 * Prefix on the headers and fields that carry user metadata.
	 */
	public const INFO_PREFIX = 'x-bz-info-';

	/**
	 * Constructs a file version.
	 *
	 * @param string $id
	 *   The file id, which is what a delete names.
	 * @param string $name
	 *   The file name, prefix included, as B2 stores it.
	 * @param int $size
	 *   Size in bytes.
	 * @param string $sha1
	 *   Content SHA-1 as lowercase hex, or an empty string for a file assembled from parts.
	 * @param int|null $modified
	 *   Unix timestamp of the upload, or NULL when B2 reported none.
	 * @param array<string, string> $info
	 *   User metadata the file carries.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly int $size,
		public readonly string $sha1 = '',
		public readonly ?int $modified = null,
		public readonly array $info = [],
	) {}

	/**
	 * Reads a file out of the headers a download answers a HEAD with.
	 *
	 * @param array<string, string> $headers
	 *   The response headers, with names already lowercased.
	 * @param string $name
	 *   The name that was asked for, since the header carries it percent-encoded.
	 *
	 * @return self
	 *   The file version.
	 */
	public static function fromHeaders(array $headers, string $name): self
	{
		$info = [];

		foreach ($headers as $header => $value) {
			if (str_starts_with($header, self::INFO_PREFIX)) {
				$info[substr($header, strlen(self::INFO_PREFIX))] = $value;
			}
		}

		$stamp = (int) ($headers['x-bz-upload-timestamp'] ?? 0);

		return new self(
			(string) ($headers['x-bz-file-id'] ?? ''),
			$name,
			(int) ($headers['content-length'] ?? 0),
			self::hash((string) ($headers['x-bz-content-sha1'] ?? '')),
			$stamp > 0 ? intdiv($stamp, 1000) : null,
			$info,
		);
	}

	/**
	 * Reads a file out of one `b2_list_file_names` entry.
	 *
	 * @param array<mixed> $entry
	 *   The entry.
	 *
	 * @return self
	 *   The file version.
	 */
	public static function fromListing(array $entry): self
	{
		$info = [];

		foreach ((array) ($entry['fileInfo'] ?? []) as $name => $value) {
			$info[(string) $name] = (string) $value;
		}

		$stamp = (int) ($entry['uploadTimestamp'] ?? 0);

		return new self(
			(string) ($entry['fileId'] ?? ''),
			(string) ($entry['fileName'] ?? ''),
			(int) ($entry['contentLength'] ?? 0),
			self::hash((string) ($entry['contentSha1'] ?? '')),
			$stamp > 0 ? intdiv($stamp, 1000) : null,
			$info,
		);
	}

	/**
	 * The file as the engine's own metadata.
	 *
	 * @param ObjectKeys $keys
	 *   The key namespace, which the store prefix is removed with.
	 *
	 * @return ObjectMeta
	 *   The metadata, with the content hash as the entity tag and no storage class, because B2 has
	 *   no per-object tier.
	 */
	public function toMeta(ObjectKeys $keys): ObjectMeta
	{
		return new ObjectMeta(
			$keys->strip($this->name),
			$this->size,
			$this->sha1 === '' ? null : $this->sha1,
			$this->modified,
			null,
			$this->info,
		);
	}

	/**
	 * Normalises a reported content hash.
	 *
	 * @param string $value
	 *   The hash as B2 reported it.
	 *
	 * @return string
	 *   Lowercase hex, or an empty string when there is no whole-file hash.
	 */
	private static function hash(string $value): string
	{
		$hash = strtolower(trim($value));

		// a large file has no whole-file hash and says so in words
		return $hash === self::NO_HASH ? '' : $hash;
	}
}
