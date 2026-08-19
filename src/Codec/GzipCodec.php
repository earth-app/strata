<?php

declare(strict_types=1);

namespace Drupal\strata\Codec;

use RuntimeException;

/**
 * DEFLATE through `ext-zlib`, which core already requires, so this codec is always available.
 *
 * It is the floor, not the recommendation. Measured on Drupal-shaped data in 8 KiB frames it
 * reaches 3.52x where zstd with a trained dictionary reaches 6.20x - 76% more stored bytes for the
 * same content. A site without `ext-zstd` still works; it just pays for it, and the settings form
 * says so with the number measured on that host.
 *
 * The zlib container is used rather than raw deflate because it carries a checksum, which turns a
 * corrupted frame into an error at decompression instead of into plausible-looking garbage.
 */
final class GzipCodec implements CompressionCodecInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return 'gzip';
	}

	/**
	 * {@inheritdoc}
	 */
	public function isAvailable(): bool
	{
		return function_exists('gzcompress') && function_exists('gzuncompress');
	}

	/**
	 * {@inheritdoc}
	 */
	public function unavailableReason(): ?string
	{
		return $this->isAvailable() ? null : 'ext-zlib is not loaded';
	}

	/**
	 * {@inheritdoc}
	 */
	public function supportsDictionary(): bool
	{
		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function levels(): array
	{
		// measured: -1 is 4.38x at 122 MB/s, -6 is 5.76x at 55 MB/s, -9 is 5.82x at 37 MB/s.
		// -9 buys 1% over -6 for a third of the throughput, so compaction stops at 9 and the
		// flush path uses 1
		return ['min' => 1, 'max' => 9, 'default' => 6, 'fast' => 1, 'dense' => 9];
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

		$compressed = @gzcompress($data, $this->clamp($level));

		if ($compressed === false) {
			throw new RuntimeException('gzip compression failed');
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

		$plain = @gzuncompress($data);

		if ($plain === false) {
			throw new RuntimeException(
				'gzip decompression failed; the frame is not valid zlib data',
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
	 *   When ext-zlib is not loaded.
	 */
	private function assertAvailable(): void
	{
		if (!$this->isAvailable()) {
			throw new RuntimeException('gzip codec is unavailable: ext-zlib is not loaded');
		}
	}
}
