<?php

declare(strict_types=1);

namespace Drupal\strata\Storage;

use InvalidArgumentException;

/**
 * A byte range for a partial read.
 *
 * Reading one frame out of a 1 MiB pack is the common case; without a range the whole pack comes
 * down to serve a few kilobytes.
 *
 * @see StorageProviderInterface
 */
final class ByteRange
{
	/**
	 * Constructs a range.
	 *
	 * @param int $offset
	 *   First byte to read, counting from zero.
	 * @param int $length
	 *   How many bytes to read.
	 *
	 * @throws InvalidArgumentException
	 *   When the offset is negative or the length is not positive. A zero-length range is refused
	 *   rather than returning an empty string, since it is always a caller bug.
	 */
	public function __construct(public readonly int $offset, public readonly int $length)
	{
		if ($offset < 0) {
			throw new InvalidArgumentException('A byte range offset cannot be negative');
		}
		if ($length < 1) {
			throw new InvalidArgumentException('A byte range length must be at least one byte');
		}
	}

	/**
	 * The last byte the range covers, inclusive.
	 *
	 * @return int
	 *   The end offset.
	 */
	public function end(): int
	{
		return $this->offset + $this->length - 1;
	}

	/**
	 * The range as an HTTP Range header value.
	 *
	 * @return string
	 *   A value such as "bytes=0-16383".
	 */
	public function toHeader(): string
	{
		return sprintf('bytes=%d-%d', $this->offset, $this->end());
	}
}
