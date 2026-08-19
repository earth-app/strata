<?php

/**
 * @file
 * Declaration-only stub for the zstd extension, for STATIC ANALYSIS ONLY.
 *
 * Signatures were read from the running extension with `ReflectionExtension` at version 0.18.0.
 * `zstd_compress()` takes the dictionary third, after the level, and is the only form that accepts
 * both. `zstd_compress_dict()` takes the level third, so calling it with two arguments compresses
 * at the default 3 regardless of the level the caller intended.
 *
 * @see ZstdCodec
 */

declare(strict_types=1);

use Drupal\strata\Codec\ZstdCodec;

if (!function_exists('zstd_compress')) {
	/**
	 * Compresses a string with Zstandard.
	 *
	 * @param string $data
	 *   The bytes to compress.
	 * @param int $level
	 *   Compression level, 1 to 22.
	 * @param string|null $dict
	 *   Raw dictionary bytes, or NULL.
	 *
	 * @return string|false
	 *   The compressed bytes, or FALSE on failure.
	 */
	function zstd_compress(string $data, int $level = 3, ?string $dict = null): string|false
	{
		return false;
	}
}

if (!function_exists('zstd_uncompress')) {
	/**
	 * Decompresses a Zstandard string.
	 *
	 * @param string $data
	 *   The compressed bytes.
	 * @param string|null $dict
	 *   The same dictionary bytes used to compress, or NULL.
	 *
	 * @return string|false
	 *   The original bytes, or FALSE on failure.
	 */
	function zstd_uncompress(string $data, ?string $dict = null): string|false
	{
		return false;
	}
}

if (!function_exists('zstd_compress_dict')) {
	/**
	 * Compresses a string against a dictionary.
	 *
	 * The level is the third argument, not the dictionary.
	 *
	 * @param string $data
	 *   The bytes to compress.
	 * @param string $dict
	 *   Raw dictionary bytes.
	 * @param int $level
	 *   Compression level, 1 to 22.
	 *
	 * @return string|false
	 *   The compressed bytes, or FALSE on failure.
	 */
	function zstd_compress_dict(string $data, string $dict, int $level = 3): string|false
	{
		return false;
	}
}

if (!function_exists('zstd_uncompress_dict')) {
	/**
	 * Decompresses a string against a dictionary.
	 *
	 * @param string $data
	 *   The compressed bytes.
	 * @param string $dict
	 *   Raw dictionary bytes.
	 *
	 * @return string|false
	 *   The original bytes, or FALSE on failure.
	 */
	function zstd_uncompress_dict(string $data, string $dict): string|false
	{
		return false;
	}
}

if (!defined('ZSTD_COMPRESS_LEVEL_MIN')) {
	define('ZSTD_COMPRESS_LEVEL_MIN', 1);
}
if (!defined('ZSTD_COMPRESS_LEVEL_MAX')) {
	define('ZSTD_COMPRESS_LEVEL_MAX', 22);
}
if (!defined('ZSTD_COMPRESS_LEVEL_DEFAULT')) {
	define('ZSTD_COMPRESS_LEVEL_DEFAULT', 3);
}
