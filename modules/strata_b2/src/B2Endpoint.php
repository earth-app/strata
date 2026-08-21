<?php

declare(strict_types=1);

namespace Drupal\strata_b2;

use Drupal\strata\Storage\ObjectKeys;
use InvalidArgumentException;

/**
 * Which B2 bucket to write to, named both ways.
 *
 * B2 addresses a bucket by id when writing and by name when reading, and the two are not derivable
 * from each other: `b2_upload_file` needs the id, `/file/<bucket>/<name>` needs the name. Both are
 * configured rather than looked up, because looking one up costs a `b2_list_buckets` on every
 * request and the account may not be allowed to list buckets at all.
 *
 * The key prefix keeps a bucket shareable. The engine passes `_strata/<site-id>/`, so two sites can
 * back up into one bucket and neither sees the other's files in a listing.
 *
 * @see B2StorageProvider
 */
final class B2Endpoint
{
	/**
	 * Where keys sit inside the bucket.
	 */
	public readonly ObjectKeys $keys;

	/**
	 * Constructs an endpoint.
	 *
	 * @param string $bucketId
	 *   The bucket id, which every write and every listing names.
	 * @param string $bucketName
	 *   The bucket name, which every read names.
	 * @param string $keyPrefix
	 *   Prefix every key sits under, normalised to end in a slash.
	 * @param int|null $largeFileThreshold
	 *   Bytes above which a file is uploaded in parts, or NULL to split only when one request
	 *   cannot carry it.
	 *
	 * @throws InvalidArgumentException
	 *   When either name is empty or the threshold is not positive.
	 */
	public function __construct(
		public readonly string $bucketId,
		public readonly string $bucketName,
		string $keyPrefix = '',
		public readonly ?int $largeFileThreshold = null,
	) {
		if (trim($bucketId) === '') {
			throw new InvalidArgumentException('An endpoint needs a bucket id');
		}
		if (trim($bucketName) === '') {
			throw new InvalidArgumentException('An endpoint needs a bucket name');
		}
		if ($largeFileThreshold !== null && $largeFileThreshold < 1) {
			throw new InvalidArgumentException('A large file threshold must be at least one byte');
		}

		$this->keys = new ObjectKeys($keyPrefix);
	}
}
