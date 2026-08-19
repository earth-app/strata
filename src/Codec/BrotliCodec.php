<?php

declare(strict_types=1);

namespace Drupal\strata\Codec;

use RuntimeException;

/**
 * Brotli through `ext-brotli`.
 *
 * Reaches the highest ratio Strata measured on Drupal-shaped data - 7.68x whole-stream and 6.31x
 * in 8 KiB frames - but at 0.7 MB/s at its top quality, roughly a quarter of zstd -19's throughput
 * for a comparable result once zstd has a trained dictionary. The extension does support
 * dictionaries, through the fourth argument to brotli_compress() and the second to
 * brotli_uncompress().
 *
 * It is offered rather than preferred on throughput, not on capability: a site with `ext-brotli`
 * and not `ext-zstd` is better off here than on gzip, and CodecRegistry orders it accordingly.
 *
 * @see CodecRegistry
 */
final class BrotliCodec implements CompressionCodecInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return 'brotli';
	}

	/**
	 * {@inheritdoc}
	 */
	public function isAvailable(): bool
	{
		return function_exists('brotli_compress') && function_exists('brotli_uncompress');
	}

	/**
	 * {@inheritdoc}
	 */
	public function unavailableReason(): ?string
	{
		return $this->isAvailable() ? null : 'ext-brotli is not loaded';
	}

	/**
	 * {@inheritdoc}
	 */
	public function supportsDictionary(): bool
	{
		// brotli_compress() takes the dictionary fourth and brotli_uncompress() takes it second
		return $this->isAvailable();
	}

	/**
	 * {@inheritdoc}
	 */
	public function levels(): array
	{
		// measured: q4 is 4.97x at 73 MB/s, q9 is 6.26x at 24 MB/s, q11 is 7.68x at 0.7 MB/s
		return ['min' => 0, 'max' => 11, 'default' => 6, 'fast' => 4, 'dense' => 11];
	}

	/**
	 * {@inheritdoc}
	 */
	public function compress(string $data, ?int $level = null, ?string $dictionary = null): string
	{
		if ($data === '') {
			return '';
		}
		$this->assertAvailable();

		$compressed = @brotli_compress(
			$data,
			$this->clamp($level),
			BROTLI_GENERIC,
			$dictionary === '' ? null : $dictionary,
		);

		if ($compressed === false) {
			throw new RuntimeException('brotli compression failed');
		}

		return $compressed;
	}

	/**
	 * {@inheritdoc}
	 */
	public function decompress(string $data, ?string $dictionary = null): string
	{
		if ($data === '') {
			return '';
		}
		$this->assertAvailable();

		$plain = @brotli_uncompress($data, $dictionary === '' ? null : $dictionary);

		if ($plain === false) {
			throw new RuntimeException(
				'brotli decompression failed; the frame is not valid brotli data, or the wrong ' .
					'dictionary was supplied',
			);
		}

		return $plain;
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
	 *   When ext-brotli is not loaded.
	 */
	private function assertAvailable(): void
	{
		if (!$this->isAvailable()) {
			throw new RuntimeException('brotli codec is unavailable: ext-brotli is not loaded');
		}
	}
}
