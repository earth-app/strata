<?php

declare(strict_types=1);

namespace Drupal\strata\Crypto;

/**
 * Stores frames unsealed, for a site that has turned encryption off.
 *
 * Present so that the encryption setting is a choice of cipher rather than a branch at every call
 * site, and so a frame header always records which cipher wrote it - including "none", which is
 * what lets a store written with encryption off stay readable after it is turned on.
 */
final class NullCipher implements CipherInterface
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
	public function seal(string $plain, string $associated = ''): string
	{
		return $plain;
	}

	/**
	 * {@inheritdoc}
	 */
	public function open(string $sealed, string $associated = ''): string
	{
		return $sealed;
	}
}
