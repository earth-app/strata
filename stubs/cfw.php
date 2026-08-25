<?php

/**
 * @file
 * Declaration-only stub for STATIC ANALYSIS ONLY.
 *
 * A function a JavaScript host installs on the interpreter, absent from every other environment and
 * needed in none of them. `DeflateDictCodec` prefers `deflate_init()` from ext-zlib,
 * which every ordinary PHP has, and reaches for this only where the incremental API is missing.
 *
 * @see DeflateDictCodec
 */

declare(strict_types=1);

use Drupal\strata\Codec\DeflateDictCodec;

if (!function_exists('cfw_zlib_dict')) {
	/**
	 * Raw deflate or inflate with a preset dictionary.
	 *
	 * `gzdeflate()`/`gzinflate()` take no dictionary argument and this runtime has no
	 * `deflate_init()`, which is why this exists at all: delta coding is "hand the previous version
	 * to the compressor as its dictionary", and nothing else reachable here can carry one.
	 *
	 * @param string $op
	 *   Either "deflate" or "inflate".
	 * @param string $data
	 *   The bytes to transform.
	 * @param string $dict
	 *   The preset dictionary. Must be identical on both sides; inflating against the wrong one
	 *   produces plausible garbage rather than an error.
	 * @param int $level
	 *   Compression level 1-9, or -1 for the default. Ignored when inflating.
	 *
	 * @return string|false
	 *   The transformed bytes, or FALSE.
	 */
	function cfw_zlib_dict(string $op, string $data, string $dict, int $level = -1): string|false
	{
		return false;
	}
}
