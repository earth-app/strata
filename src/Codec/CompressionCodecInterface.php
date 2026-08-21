<?php

declare(strict_types=1);

namespace Drupal\strata\Codec;

use RuntimeException;

/**
 * One compression algorithm Strata can store a frame under.
 *
 * A codec is identified by a short stable string recorded in every frame header, so a store
 * written by one codec stays readable after the site's extensions change. That is the reason
 * CompressionCodecInterface::id() exists separately from the class name: renaming a class must
 * never orphan bytes already in the bucket.
 *
 * Availability is a runtime property, not a build-time one. `ext-zstd` and `ext-brotli` are
 * frequently absent, so a codec reports whether it can run instead of fataling on first use, and
 * the registry falls back without losing the ability to READ what a better-equipped host wrote.
 *
 * Measured on Drupal-shaped data in 8 KiB frames: gzip -9 reaches 3.52x, zstd -19 alone 3.71x, and
 * zstd -19 with a trained dictionary 6.20x. The dictionary is worth more than the algorithm, which
 * is why CompressionCodecInterface::supportsDictionary() is part of the contract rather than an
 * implementation detail.
 *
 * @see CodecRegistry
 */
interface CompressionCodecInterface
{
	/**
	 * The stable identifier recorded in a frame header.
	 *
	 * Never change this for an existing codec; add a new one instead.
	 *
	 * @return string
	 *   A short lowercase token such as "zstd", "gzip" or "none".
	 */
	public function id(): string;

	/**
	 * Whether this codec can run on this host right now.
	 *
	 * @return bool
	 *   TRUE when every extension or binary the codec needs is present.
	 */
	public function isAvailable(): bool;

	/**
	 * Why the codec is unavailable, for the settings form and hook_requirements().
	 *
	 * @return string|null
	 *   A short human-readable reason, or NULL when the codec is available.
	 */
	public function unavailableReason(): ?string;

	/**
	 * Whether the codec accepts a training dictionary.
	 *
	 * @return bool
	 *   TRUE when compress() and decompress() honour their $dictionary argument.
	 */
	public function supportsDictionary(): bool;

	/**
	 * The compression levels this codec accepts.
	 *
	 * @return array{min: int, max: int, default: int, fast: int, dense: int}
	 *   The usable range, the default, the level to use on the flush path where throughput matters,
	 *   and the level to use during compaction where ratio matters.
	 */
	public function levels(): array;

	/**
	 * Compresses a buffer.
	 *
	 * @param string $data
	 *   The bytes to compress. An empty string compresses to an empty string, so that an absent
	 *   payload never becomes a non-empty frame.
	 * @param int|null $level
	 *   A level within CompressionCodecInterface::levels(), or NULL for the default.
	 * @param string|null $dictionary
	 *   Raw dictionary bytes, or NULL. Ignored by codecs that report no dictionary support, so a
	 *   caller never has to branch on it.
	 *
	 * @return string
	 *   The compressed bytes.
	 *
	 * @throws RuntimeException
	 *   When the codec is unavailable, or compression fails.
	 */
	public function compress(string $data, ?int $level = null, ?string $dictionary = null): string;

	/**
	 * Decompresses a buffer.
	 *
	 * Must raise rather than return partial output. A truncated decompression is indistinguishable
	 * from correct output until much later.
	 *
	 * @param string $data
	 *   The compressed bytes.
	 * @param string|null $dictionary
	 *   The same dictionary bytes used to compress, or NULL.
	 *
	 * @return string
	 *   The original bytes.
	 *
	 * @throws RuntimeException
	 *   When the codec is unavailable, or the input is not valid for this codec.
	 */
	public function decompress(string $data, ?string $dictionary = null): string;
}
