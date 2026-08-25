<?php

declare(strict_types=1);

namespace Drupal\strata\Codec;

use RuntimeException;

/**
 * Raw deflate with a preset dictionary, from ext-zlib on any ordinary host.
 *
 * The dictionary is worth more than the algorithm here: measured on Drupal-shaped data, gzip -9
 * reaches 3.52x standalone and delta coding against the previous version reaches 63.70x. Delta
 * coding is "hand the previous version to the compressor as its dictionary", so every codec
 * reporting no dictionary support meant every frame anchored and the store kept full copies.
 *
 * **`gzdeflate()` cannot carry a dictionary but `deflate_init()` can.** The incremental zlib API has
 * taken a `dictionary` option since PHP 7.0, and `ext-zlib` is already a hard requirement of this
 * package, so a VPS, a container and a shared host all get this codec with nothing installed. That
 * is the whole mechanism; `ext-zstd`, `ext-brotli` and a `zstd` binary stay optional upgrades.
 *
 * **The host bridge is a fallback for runtimes that lack the incremental API, not the mechanism.**
 * A PHP built for a JavaScript host - a Worker deployment compiled to WASM - can ship without
 * `deflate_init()` while still being able to deflate; such a host installs a function this codec
 * calls instead. Neither path changes the bytes: both emit raw deflate, so a frame written through
 * one is readable through the other.
 *
 * A host with neither leaves this codec unavailable and the registry falls back, the same contract
 * every other codec here follows. `id()` is stable and recorded in the frame header, so a store
 * stays readable in the sense that matters: a host that cannot decode reports why rather than
 * returning damaged bytes.
 *
 * @see \Drupal\strata\Delta\DeltaCodec
 * @see CodecRegistry
 */
final class DeflateDictCodec implements CompressionCodecInterface
{
	/**
	 * The host function used when the incremental zlib API is absent.
	 *
	 * Named for the one runtime known to install it. A different host wanting this path installs a
	 * function under this name; nothing else about the codec changes.
	 */
	private const BRIDGE = 'cfw_zlib_dict';

	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return 'deflate-dict';
	}

	/**
	 * {@inheritdoc}
	 */
	public function isAvailable(): bool
	{
		return function_exists('deflate_init') || function_exists(self::BRIDGE);
	}

	/**
	 * {@inheritdoc}
	 */
	public function unavailableReason(): ?string
	{
		return $this->isAvailable()
			? null
			: 'this host has neither deflate_init() from ext-zlib nor a cfw_zlib_dict() bridge, so deflate cannot be given a preset dictionary';
	}

	/**
	 * {@inheritdoc}
	 */
	public function supportsDictionary(): bool
	{
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function levels(): array
	{
		return ['min' => 1, 'max' => 9, 'default' => 6, 'fast' => 1, 'dense' => 9];
	}

	/**
	 * {@inheritdoc}
	 *
	 * A NULL or empty dictionary is a plain deflate rather than an error: the caller decides whether
	 * a frame anchors, and refusing here would make the anchor frame of every chain fail.
	 */
	public function compress(string $data, ?int $level = null, ?string $dictionary = null): string
	{
		if ($data === '') {
			return '';
		}
		$this->assertAvailable();

		if ($dictionary === null || $dictionary === '') {
			$out = @gzdeflate($data, $this->clamp($level));
			if ($out === false) {
				throw new RuntimeException('deflate-dict compression failed with no dictionary');
			}
			return $out;
		}

		$out = function_exists('deflate_init')
			? self::native($data, $dictionary, $this->clamp($level))
			: self::bridge('deflate', $data, $dictionary, $this->clamp($level));

		if (!is_string($out)) {
			throw new RuntimeException('deflate-dict compression failed');
		}
		return $out;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Raises rather than returning partial output. A frame decompressed against the WRONG dictionary
	 * does not error in zlib, it produces plausible garbage, so the caller has to hand back the same
	 * bytes it compressed with; the chain in ObjectStore is what guarantees that.
	 */
	public function decompress(string $data, ?string $dictionary = null): string
	{
		if ($data === '') {
			return '';
		}
		$this->assertAvailable();

		if ($dictionary === null || $dictionary === '') {
			$plain = @gzinflate($data);
			if ($plain === false) {
				throw new RuntimeException(
					'deflate-dict decompression failed; the frame is not valid deflate data',
				);
			}
			return $plain;
		}

		$plain = function_exists('inflate_init')
			? self::nativeInflate($data, $dictionary)
			: self::bridge('inflate', $data, $dictionary, 0);

		if (!is_string($plain)) {
			throw new RuntimeException(
				'deflate-dict decompression failed; the frame or the dictionary does not match',
			);
		}
		return $plain;
	}

	/**
	 * Deflates against a preset dictionary using ext-zlib.
	 *
	 * `ZLIB_ENCODING_RAW` rather than the zlib container, so the output is the same raw deflate stream
	 * `gzdeflate()` produces and an anchor frame and a delta frame stay one format.
	 *
	 * @param string $data
	 *   The bytes to compress.
	 * @param string $dictionary
	 *   The preset dictionary.
	 * @param int $level
	 *   The compression level.
	 *
	 * @return string|false
	 *   The deflated bytes, or FALSE.
	 */
	private static function native(string $data, string $dictionary, int $level): string|false
	{
		$context = deflate_init(ZLIB_ENCODING_RAW, [
			'level' => $level,
			'dictionary' => $dictionary,
		]);

		return $context === false ? false : deflate_add($context, $data, ZLIB_FINISH);
	}

	/**
	 * Inflates against a preset dictionary using ext-zlib.
	 *
	 * @param string $data
	 *   The bytes to expand.
	 * @param string $dictionary
	 *   The preset dictionary the bytes were compressed against.
	 *
	 * @return string|false
	 *   The original bytes, or FALSE.
	 */
	private static function nativeInflate(string $data, string $dictionary): string|false
	{
		$context = inflate_init(ZLIB_ENCODING_RAW, ['dictionary' => $dictionary]);

		return $context === false ? false : @inflate_add($context, $data, ZLIB_FINISH);
	}

	/**
	 * Calls the host function without naming it at compile time.
	 *
	 * Only reached on a host without the incremental zlib API. No presence check here: the callers
	 * pick this path precisely because the native one is absent, and `assertAvailable()` has already
	 * established that one of the two exists. `stubs/cfw.php` is what gives the analyser the
	 * signature, the same way the extension stubs beside it do.
	 *
	 * @param string $op
	 *   Either "deflate" or "inflate".
	 * @param string $data
	 *   The bytes to transform.
	 * @param string $dictionary
	 *   The preset dictionary.
	 * @param int $level
	 *   The compression level; ignored when inflating.
	 *
	 * @return string|false
	 *   The transformed bytes, or FALSE.
	 */
	private static function bridge(
		string $op,
		string $data,
		string $dictionary,
		int $level,
	): string|false {
		$fn = self::BRIDGE;
		return $fn($op, $data, $dictionary, $level);
	}

	/**
	 * Brings a requested level into the supported range.
	 *
	 * @param int|null $level
	 *   The requested level, or NULL for the default.
	 *
	 * @return int
	 *   A level this codec accepts.
	 */
	private function clamp(?int $level): int
	{
		$levels = $this->levels();

		if ($level === null) {
			return $levels['default'];
		}

		return max($levels['min'], min($levels['max'], $level));
	}

	/**
	 * Guards every entry point.
	 *
	 * @throws RuntimeException
	 *   When the host function is absent.
	 */
	private function assertAvailable(): void
	{
		if (!$this->isAvailable()) {
			throw new RuntimeException(
				'deflate-dict codec is unavailable: ' . (string) $this->unavailableReason(),
			);
		}
	}
}
