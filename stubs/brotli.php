<?php

/**
 * @file
 * Declaration-only stub for the brotli extension, for STATIC ANALYSIS ONLY.
 *
 * Signatures were read from the running extension with `ReflectionExtension` at version 0.21.0.
 * `brotli_compress()` takes the dictionary fourth, after the mode; `brotli_uncompress()` takes it
 * second.
 *
 * @see BrotliCodec
 */

declare(strict_types=1);

use Drupal\strata\Codec\BrotliCodec;

if (!function_exists('brotli_compress')) {
	/**
	 * Compresses a string with Brotli.
	 *
	 * @param string $data
	 *   The bytes to compress.
	 * @param int $level
	 *   Quality, 0 to 11.
	 * @param int $mode
	 *   One of BROTLI_GENERIC, BROTLI_TEXT or BROTLI_FONT.
	 * @param string|null $dict
	 *   Raw dictionary bytes, or NULL.
	 *
	 * @return string|false
	 *   The compressed bytes, or FALSE on failure.
	 */
	function brotli_compress(
		string $data,
		int $level = 11,
		int $mode = 0,
		?string $dict = null,
	): string|false {
		return false;
	}
}

if (!function_exists('brotli_uncompress')) {
	/**
	 * Decompresses a Brotli string.
	 *
	 * @param string $data
	 *   The compressed bytes.
	 * @param string|null $dict
	 *   The same dictionary bytes used to compress, or NULL.
	 *
	 * @return string|false
	 *   The original bytes, or FALSE on failure.
	 */
	function brotli_uncompress(string $data, ?string $dict = null): string|false
	{
		return false;
	}
}

if (!defined('BROTLI_GENERIC')) {
	define('BROTLI_GENERIC', 0);
}
if (!defined('BROTLI_TEXT')) {
	define('BROTLI_TEXT', 1);
}
if (!defined('BROTLI_FONT')) {
	define('BROTLI_FONT', 2);
}
