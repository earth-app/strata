<?php

declare(strict_types=1);

namespace Drupal\strata\Codec;

use InvalidArgumentException;
use JsonSerializable;

/**
 * One measured compression result.
 *
 * A value object rather than an array so the settings form cannot render a ratio where it meant a
 * throughput, and so a reference figure and a figure measured on this site carry the same shape.
 *
 * @see CodecProfile
 * @see Calibrator
 */
final class CodecMeasurement implements JsonSerializable
{
	/**
	 * Constructs a measurement.
	 *
	 * @param string $codec
	 *   The codec id the figures belong to.
	 * @param int $level
	 *   The compression level used.
	 * @param bool $dictionary
	 *   Whether a trained dictionary was supplied.
	 * @param int $frameSize
	 *   Frame size in bytes the figures were taken at. Ratio moves with this, so a figure without
	 *   it is not comparable to anything.
	 * @param float $ratio
	 *   Uncompressed bytes divided by compressed bytes. Higher is smaller output.
	 * @param float $compressMbPerSecond
	 *   Compression throughput.
	 * @param float $decompressMbPerSecond
	 *   Decompression throughput, or 0.0 when it was not measured.
	 * @param bool $onThisHost
	 *   TRUE when these figures were measured here, FALSE when they are the shipped reference.
	 *
	 * @throws InvalidArgumentException
	 *   When a figure is negative or a ratio is below 1.0, either of which means the measurement
	 *   itself failed rather than the codec performing badly.
	 */
	public function __construct(
		public readonly string $codec,
		public readonly int $level,
		public readonly bool $dictionary,
		public readonly int $frameSize,
		public readonly float $ratio,
		public readonly float $compressMbPerSecond,
		public readonly float $decompressMbPerSecond = 0.0,
		public readonly bool $onThisHost = false,
	) {
		if ($ratio < 1.0) {
			throw new InvalidArgumentException(
				sprintf(
					'A compression ratio below 1.0 means the measurement failed, got %.4f',
					$ratio,
				),
			);
		}
		if ($frameSize < 1) {
			throw new InvalidArgumentException('Frame size must be positive');
		}
		if ($compressMbPerSecond < 0.0 || $decompressMbPerSecond < 0.0) {
			throw new InvalidArgumentException('Throughput cannot be negative');
		}
	}

	/**
	 * Percentage of the original size the output takes.
	 *
	 * The form most people read faster than a ratio: 4.0x is "25% of the original".
	 *
	 * @return float
	 *   A percentage between 0 and 100.
	 */
	public function percentOfOriginal(): float
	{
		return 100.0 / $this->ratio;
	}

	/**
	 * Bytes stored per gigabyte of input, at this ratio.
	 *
	 * @return float
	 *   Megabytes stored per gigabyte captured.
	 */
	public function megabytesPerGigabyte(): float
	{
		return 1024.0 / $this->ratio;
	}

	/**
	 * How long compressing a given volume takes at this throughput.
	 *
	 * @param int $bytes
	 *   Volume to compress.
	 *
	 * @return float
	 *   Seconds, or INF when throughput was measured as zero.
	 */
	public function secondsFor(int $bytes): float
	{
		if ($this->compressMbPerSecond <= 0.0) {
			return INF;
		}

		return $bytes / 1048576 / $this->compressMbPerSecond;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The measurement as a plain array for a render array or a JSON response.
	 */
	public function jsonSerialize(): array
	{
		return [
			'codec' => $this->codec,
			'level' => $this->level,
			'dictionary' => $this->dictionary,
			'frameSize' => $this->frameSize,
			'ratio' => round($this->ratio, 3),
			'percentOfOriginal' => round($this->percentOfOriginal(), 2),
			'compressMbPerSecond' => round($this->compressMbPerSecond, 1),
			'decompressMbPerSecond' => round($this->decompressMbPerSecond, 1),
			'onThisHost' => $this->onThisHost,
		];
	}
}
