<?php

declare(strict_types=1);

namespace Drupal\strata\Codec;

use RuntimeException;

/**
 * Zstandard through `ext-zstd`.
 *
 * The codec Strata is designed around. Measured on Drupal-shaped data in 8 KiB frames: 3.71x
 * alone, and 6.20x with a trained dictionary - 76% fewer stored bytes than gzip at the same frame
 * size. Level 1 runs at 287 MB/s and level 19 at 2.6 MB/s, which is why the flush path and the
 * compaction path use different levels rather than one compromise.
 *
 * The extension is absent on many hosts, so ZstdCodec::isAvailable() gates every entry point.
 * ZstdPipeCodec covers the same algorithm through the `zstd` binary for bulk work when the
 * extension is missing.
 *
 * Dictionary support is probed per function, because the extension has shipped more than one
 * spelling of the dictionary API and a host can have the base functions without them.
 *
 * @see ZstdPipeCodec
 * @see CodecRegistry
 */
final class ZstdCodec implements CompressionCodecInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return 'zstd';
	}

	/**
	 * {@inheritdoc}
	 */
	public function isAvailable(): bool
	{
		return function_exists('zstd_compress') && function_exists('zstd_uncompress');
	}

	/**
	 * {@inheritdoc}
	 */
	public function unavailableReason(): ?string
	{
		return $this->isAvailable() ? null : 'ext-zstd is not loaded';
	}

	/**
	 * {@inheritdoc}
	 */
	public function supportsDictionary(): bool
	{
		return $this->isAvailable() &&
			function_exists('zstd_compress_dict') &&
			function_exists('zstd_uncompress_dict');
	}

	/**
	 * {@inheritdoc}
	 */
	public function levels(): array
	{
		// measured whole-stream: 1 is 5.34x at 287 MB/s, 9 is 5.70x at 73 MB/s, 19 is 7.22x at
		// 2.6 MB/s. 22 needs --ultra and buys almost nothing over 19 on this shape of data
		return ['min' => 1, 'max' => 19, 'default' => 3, 'fast' => 1, 'dense' => 19];
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

		// the three-argument form is the only one that honours BOTH a level and a dictionary.
		// zstd_compress_dict($data, $dict) takes its level THIRD, so passing two arguments there
		// silently compresses at the default 3 however dense a level the caller asked for
		$compressed = @zstd_compress(
			$data,
			$this->clamp($level),
			$dictionary === '' ? null : $dictionary,
		);

		if ($compressed === false) {
			throw new RuntimeException('zstd compression failed');
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

		$plain = @zstd_uncompress($data, $dictionary === '' ? null : $dictionary);

		if ($plain === false) {
			throw new RuntimeException(
				'zstd decompression failed; the frame is not valid zstd data, or the wrong ' .
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
	 *   When ext-zstd is not loaded.
	 */
	private function assertAvailable(): void
	{
		if (!$this->isAvailable()) {
			throw new RuntimeException('zstd codec is unavailable: ext-zstd is not loaded');
		}
	}
}
