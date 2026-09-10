<?php

declare(strict_types=1);

namespace Drupal\strata\Codec;

/**
 * Brotli through the `brotli` binary, for hosts without `ext-brotli`.
 *
 * The reason to have it is reading, not writing. Brotli reaches the highest ratio Strata measured
 * on Drupal-shaped data, so a site with the extension may well have a bucket full of brotli frames;
 * without this, moving that site to a host whose PHP was built without `ext-brotli` makes every one
 * of those frames unreadable and the backup stops being a backup. `brotli` ships in the base
 * repositories of every mainstream distribution, so the binary is usually already there.
 *
 * Like every PipeCodec it is registered for reading and bulk work only. It also loses the write
 * path on merit: brotli at its top quality is 0.7 MB/s before a process spawn is added.
 *
 * The CLI's `-D` takes a raw LZ77 dictionary, which is what `brotli_compress()` takes fourth, so
 * the two produce and read each other's dictionary-coded frames.
 *
 * @see BrotliCodec
 * @see PipeCodec
 * @see CodecRegistry
 */
final class BrotliPipeCodec extends PipeCodec
{
	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		// the same on-disk format as BrotliCodec, so a frame written by either is readable by both
		return 'brotli';
	}

	/**
	 * {@inheritdoc}
	 */
	public function levels(): array
	{
		return ['min' => 0, 'max' => 11, 'default' => 6, 'fast' => 4, 'dense' => 11];
	}

	/**
	 * {@inheritdoc}
	 */
	protected function binaryName(): string
	{
		return 'brotli';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function suffix(): string
	{
		return '.br';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function arguments(bool $compressing, int $level, ?string $dictionaryPath): array
	{
		// -q is quality here rather than quiet, which is the one place the two binaries disagree
		$arguments = ['-f'];

		if ($compressing) {
			array_push($arguments, '-q', (string) $level);
		} else {
			$arguments[] = '-d';
		}

		if ($dictionaryPath !== null) {
			array_push($arguments, '-D', $dictionaryPath);
		}

		return $arguments;
	}
}
