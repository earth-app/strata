<?php

declare(strict_types=1);

namespace Drupal\strata\Codec;

/**
 * Stores frames verbatim.
 *
 * Not a placeholder. Two real cases need it: already-compressed payloads such as media blocks,
 * where compression spends CPU to add bytes, and a calibration baseline, where the ratio of every
 * other codec is measured against this one.
 */
final class NoneCodec implements CompressionCodecInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return 'none';
	}

	/**
	 * {@inheritdoc}
	 */
	public function isAvailable(): bool
	{
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function unavailableReason(): ?string
	{
		return null;
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
		return ['min' => 0, 'max' => 0, 'default' => 0, 'fast' => 0, 'dense' => 0];
	}

	/**
	 * {@inheritdoc}
	 */
	public function compress(string $data, ?int $level = null, ?string $dictionary = null): string
	{
		return $data;
	}

	/**
	 * {@inheritdoc}
	 */
	public function decompress(string $data, ?string $dictionary = null): string
	{
		return $data;
	}
}
