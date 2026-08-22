<?php

declare(strict_types=1);

namespace Drupal\strata\Cas;

use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

/**
 * The content address every stored object is named by.
 *
 * BLAKE2b-256 through `ext-sodium`, which ships with PHP 8.3+ and needs no extra dependency.
 * Measured at 718 MB/s on the reference host, against 229 MB/s for SHA-256 and 643 MB/s for MD5,
 * so the digest is never the bottleneck in a flush; an xxHash pre-filter would add a collision
 * class for no throughput that matters.
 *
 * Digests are handled as lowercase hex everywhere they cross a boundary - a bucket key, a database
 * column, a manifest - because a binary digest cannot survive JSON, a URL or a log line. The raw
 * form stays available through Hash::raw() for callers doing their own framing.
 *
 * @see Framer
 */
final class Hash
{
	/**
	 * Name recorded in frame headers so a future digest change stays decodable.
	 */
	public const ALGORITHM = 'blake2b-256';

	/**
	 * Digest length in bytes.
	 */
	public const BYTES = 32;

	/**
	 * Digest length as lowercase hex.
	 */
	public const HEX_LENGTH = 64;

	/**
	 * How many two-character directories a shard path carries.
	 *
	 * Two levels gives 65,536 buckets, which keeps any single prefix listing small on providers
	 * that paginate a flat namespace.
	 */
	public const SHARD_DEPTH = 2;

	/**
	 * Bytes read per iteration when digesting a stream.
	 */
	private const STREAM_CHUNK = 1048576;

	/**
	 * Digests a value and returns lowercase hex.
	 *
	 * @param string $data
	 *   The bytes to digest. A string is digested as-is; no encoding is applied.
	 * @param string $key
	 *   Optional keyed-hashing key, at most 64 bytes. An empty string means unkeyed.
	 *
	 * @return string
	 *   A 64-character lowercase hex digest.
	 *
	 * @throws InvalidArgumentException
	 *   When the key is longer than sodium allows.
	 */
	public static function of(string $data, #[SensitiveParameter] string $key = ''): string
	{
		return bin2hex(self::raw($data, $key));
	}

	/**
	 * Digests a value and returns the raw 32 bytes.
	 *
	 * Prefer Hash::of() unless you are packing the digest into a binary header yourself.
	 *
	 * @param string $data
	 *   The bytes to digest.
	 * @param string $key
	 *   Optional keyed-hashing key, at most 64 bytes.
	 *
	 * @return string
	 *   Exactly Hash::BYTES bytes.
	 *
	 * @throws InvalidArgumentException
	 *   When the key is longer than sodium allows.
	 */
	public static function raw(string $data, #[SensitiveParameter] string $key = ''): string
	{
		if ($key !== '' && strlen($key) > SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MAX) {
			throw new InvalidArgumentException(
				sprintf(
					'Hash key is %d bytes; the maximum is %d',
					strlen($key),
					SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MAX,
				),
			);
		}

		return sodium_crypto_generichash($data, $key, self::BYTES);
	}

	/**
	 * Digests a stream without holding it in memory.
	 *
	 * Reads from the current position to EOF, or to $limit bytes if given, and leaves the pointer
	 * where it stopped so a caller can keep reading. Used by the file realm, where a 256 MiB
	 * object must not become a 256 MiB string.
	 *
	 * @param resource $stream
	 *   An open, readable stream.
	 * @param int|null $limit
	 *   Stop after this many bytes, or NULL to read to EOF.
	 * @param string $key
	 *   Optional keyed-hashing key, at most 64 bytes.
	 *
	 * @return string
	 *   A 64-character lowercase hex digest.
	 *
	 * @throws InvalidArgumentException
	 *   When $stream is not a stream resource, or $limit is negative.
	 * @throws RuntimeException
	 *   When a read fails before the limit or EOF is reached.
	 */
	public static function ofStream(
		$stream,
		?int $limit = null,
		#[SensitiveParameter] string $key = '',
	): string {
		if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
			throw new InvalidArgumentException('Hash::ofStream() needs an open stream resource');
		}
		if ($limit !== null && $limit < 0) {
			throw new InvalidArgumentException('Hash::ofStream() limit cannot be negative');
		}

		$state = sodium_crypto_generichash_init($key, self::BYTES);
		$remaining = $limit;

		while ($remaining === null || $remaining > 0) {
			$want = $remaining === null ? self::STREAM_CHUNK : min(self::STREAM_CHUNK, $remaining);
			$block = fread($stream, $want);

			if ($block === false) {
				throw new RuntimeException('Hash::ofStream() failed reading the stream');
			}
			if ($block === '') {
				break;
			}

			sodium_crypto_generichash_update($state, $block);

			if ($remaining !== null) {
				$remaining -= strlen($block);
			}
		}

		return bin2hex(sodium_crypto_generichash_final($state, self::BYTES));
	}

	/**
	 * Digests a file by path.
	 *
	 * @param string $path
	 *   Absolute or stream-wrapper path to a readable file.
	 * @param string $key
	 *   Optional keyed-hashing key, at most 64 bytes.
	 *
	 * @return string
	 *   A 64-character lowercase hex digest.
	 *
	 * @throws RuntimeException
	 *   When the file cannot be opened.
	 */
	public static function ofFile(string $path, #[SensitiveParameter] string $key = ''): string
	{
		$handle = @fopen($path, 'rb');
		if ($handle === false) {
			throw new RuntimeException(sprintf('Cannot open %s for hashing', $path));
		}

		try {
			return self::ofStream($handle, null, $key);
		} finally {
			fclose($handle);
		}
	}

	/**
	 * Digests a structure, whatever bytes it holds.
	 *
	 * For a digest that is compared and never read back: a watermark over sampled rows, a fingerprint
	 * over a file tree, a deploy digest over per-file hashes. Such a digest only has to be
	 * deterministic and binary-safe.
	 *
	 * JSON is tried first so a digest a previous release stored stays byte-identical and an upgrade
	 * does not report a change that did not happen. `serialize()` is the fallback because
	 * `json_encode()` returns FALSE for anything that is not valid UTF-8, and the callers of this
	 * method all key on something the site controls - a database row, a filesystem path - which on
	 * POSIX is bytes rather than text. `(string) false` is the empty string, so every such structure
	 * used to digest to `Hash::of('')`, two different structures compared equal, and the tripwire
	 * built on the comparison was blind rather than wrong.
	 *
	 * @param array<array-key, mixed> $value
	 *   The structure to digest. Order matters, so sort it first if the caller's identity does not
	 *   depend on the order it was built in.
	 * @param string $key
	 *   Optional keyed-hashing key, at most 64 bytes.
	 *
	 * @return string
	 *   A 64-character lowercase hex digest.
	 */
	public static function ofData(array $value, #[SensitiveParameter] string $key = ''): string
	{
		$encoded = json_encode($value);

		return self::of($encoded === false ? serialize($value) : $encoded, $key);
	}

	/**
	 * Compares two digests without leaking where they differ.
	 *
	 * Accepts hex or raw on either side, so a digest read out of a manifest can be compared with
	 * one just computed. Mismatched lengths return FALSE rather than throwing, because a truncated
	 * digest is a corruption symptom the caller wants to report, not an argument error.
	 *
	 * @param string $left
	 *   A digest, hex or raw.
	 * @param string $right
	 *   A digest, hex or raw.
	 *
	 * @return bool
	 *   TRUE when the two digests are the same value.
	 */
	public static function equals(string $left, string $right): bool
	{
		$left = self::normalize($left);
		$right = self::normalize($right);

		if ($left === null || $right === null) {
			return false;
		}

		return hash_equals($left, $right);
	}

	/**
	 * Splits a digest into its bucket path.
	 *
	 * @param string $hex
	 *   A 64-character lowercase hex digest.
	 *
	 * @return string
	 *   A path fragment such as "bd/dd", with no leading or trailing slash.
	 *
	 * @throws InvalidArgumentException
	 *   When $hex is not a valid digest.
	 */
	public static function shard(string $hex): string
	{
		self::assertValid($hex);

		$parts = [];
		for ($i = 0; $i < self::SHARD_DEPTH; $i++) {
			$parts[] = substr($hex, $i * 2, 2);
		}

		return implode('/', $parts);
	}

	/**
	 * Builds the full object key a digest is stored under.
	 *
	 * @param string $hex
	 *   A 64-character lowercase hex digest.
	 * @param string $prefix
	 *   Key prefix, such as "frames" or "media". Slashes are trimmed.
	 *
	 * @return string
	 *   A key such as "frames/bd/dd/bddd813c...".
	 *
	 * @throws InvalidArgumentException
	 *   When $hex is not a valid digest.
	 */
	public static function key(string $hex, string $prefix): string
	{
		$prefix = trim($prefix, '/');
		$shard = self::shard($hex);

		return $prefix === '' ? $shard . '/' . $hex : $prefix . '/' . $shard . '/' . $hex;
	}

	/**
	 * Shortens a digest for display.
	 *
	 * Never use the result as an identifier; it is for a table cell or a log line.
	 *
	 * @param string $hex
	 *   A 64-character lowercase hex digest.
	 * @param int $length
	 *   How many characters to keep, between 4 and Hash::HEX_LENGTH.
	 *
	 * @return string
	 *   The first $length characters of the digest.
	 *
	 * @throws InvalidArgumentException
	 *   When $hex is not a valid digest, or $length is out of range.
	 */
	public static function abbreviate(string $hex, int $length = 12): string
	{
		self::assertValid($hex);

		if ($length < 4 || $length > self::HEX_LENGTH) {
			throw new InvalidArgumentException(
				sprintf(
					'Abbreviation length must be between 4 and %d, got %d',
					self::HEX_LENGTH,
					$length,
				),
			);
		}

		return substr($hex, 0, $length);
	}

	/**
	 * Whether a string is a well-formed digest of this algorithm.
	 *
	 * @param string $hex
	 *   The candidate.
	 *
	 * @return bool
	 *   TRUE for exactly 64 lowercase hex characters.
	 */
	public static function isValid(string $hex): bool
	{
		return strlen($hex) === self::HEX_LENGTH && ctype_xdigit($hex) && strtolower($hex) === $hex;
	}

	/**
	 * Reduces a digest in either representation to its raw bytes.
	 *
	 * @param string $digest
	 *   A digest, hex or raw.
	 *
	 * @return string|null
	 *   The raw bytes, or NULL when $digest is neither representation.
	 */
	private static function normalize(string $digest): ?string
	{
		if (strlen($digest) === self::BYTES) {
			return $digest;
		}
		if (self::isValid($digest)) {
			return hex2bin($digest) ?: null;
		}
		// uppercase hex is still a digest a caller may have read out of another system
		if (strlen($digest) === self::HEX_LENGTH && ctype_xdigit($digest)) {
			return hex2bin(strtolower($digest)) ?: null;
		}

		return null;
	}

	/**
	 * Guards a digest argument.
	 *
	 * @param string $hex
	 *   The candidate.
	 *
	 * @throws InvalidArgumentException
	 *   When $hex is not a valid digest.
	 */
	private static function assertValid(string $hex): void
	{
		if (!self::isValid($hex)) {
			throw new InvalidArgumentException(
				sprintf(
					'Expected %d lowercase hex characters, got %s',
					self::HEX_LENGTH,
					strlen($hex) === 0 ? 'an empty string' : sprintf('%d characters', strlen($hex)),
				),
			);
		}
	}
}
