<?php

declare(strict_types=1);

namespace Drupal\strata\Codec;

/**
 * Zstandard through the `zstd` binary, for hosts without `ext-zstd`.
 *
 * This exists because the extension is absent on many hosts and gzip costs 76% more stored bytes.
 * It is explicitly NOT a per-frame codec: a process spawn dominates the work at frame sizes, and
 * 1,000 separate `zstd` invocations were measured at 5.88 seconds - roughly 5.9 ms each against
 * the sub-millisecond compression itself. PipeCodec carries that split and the process handling.
 *
 * Frames it writes carry the same id and the same bytes as ZstdCodec's, so a bucket written on a
 * host with the extension reads on a host with only the binary and the other way round.
 *
 * @see ZstdCodec
 * @see PipeCodec
 * @see CodecRegistry
 */
final class ZstdPipeCodec extends PipeCodec
{
	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		// the same on-disk format as ZstdCodec, so a frame written by either is readable by both
		return 'zstd';
	}

	/**
	 * {@inheritdoc}
	 */
	public function levels(): array
	{
		return ['min' => 1, 'max' => 19, 'default' => 3, 'fast' => 1, 'dense' => 19];
	}

	/**
	 * {@inheritdoc}
	 */
	protected function binaryName(): string
	{
		return 'zstd';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function suffix(): string
	{
		return '.zst';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function arguments(bool $compressing, int $level, ?string $dictionaryPath): array
	{
		$arguments = ['-q', '-f'];
		$arguments[] = $compressing ? '-' . $level : '-d';

		if ($dictionaryPath !== null) {
			array_push($arguments, '-D', $dictionaryPath);
		}

		return $arguments;
	}
}
