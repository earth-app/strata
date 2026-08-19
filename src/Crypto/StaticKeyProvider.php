<?php

declare(strict_types=1);

namespace Drupal\strata\Crypto;

use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

/**
 * Holds a key in memory.
 *
 * Used by the test lanes and by a CLI run that is handed a key directly. A site uses the `key`
 * module instead, so that the key is not in configuration a database dump would carry.
 */
final class StaticKeyProvider implements KeyProviderInterface
{
	/**
	 * Personalisation for the fingerprint, so it can never collide with another use of the key.
	 */
	private const FINGERPRINT_CONTEXT = 'strata-key-fingerprint-v1';

	/**
	 * Hex characters a fingerprint carries.
	 */
	private const FINGERPRINT_LENGTH = 16;

	/**
	 * Constructs a provider around raw key bytes.
	 *
	 * @param string $key
	 *   Exactly KeyProviderInterface::KEY_BYTES bytes.
	 *
	 * @throws InvalidArgumentException
	 *   When the key is the wrong length. Refused rather than stretched or truncated, because
	 *   either would give a false impression of the strength in use.
	 */
	public function __construct(#[SensitiveParameter] private readonly string $key)
	{
		if (strlen($key) !== self::KEY_BYTES) {
			throw new InvalidArgumentException(
				sprintf(
					'Encryption key must be exactly %d bytes, got %d',
					self::KEY_BYTES,
					strlen($key),
				),
			);
		}
	}

	/**
	 * Builds a provider around a fresh random key.
	 *
	 * @return self
	 *   A provider holding a newly generated key.
	 */
	public static function generate(): self
	{
		return new self(random_bytes(self::KEY_BYTES));
	}

	/**
	 * Builds a provider from a hex-encoded key.
	 *
	 * @param string $hex
	 *   Exactly 64 hex characters.
	 *
	 * @return self
	 *   A provider holding the decoded key.
	 *
	 * @throws InvalidArgumentException
	 *   When the string is not valid hex of the right length.
	 */
	public static function fromHex(#[SensitiveParameter] string $hex): self
	{
		if (strlen($hex) !== self::KEY_BYTES * 2 || !ctype_xdigit($hex)) {
			throw new InvalidArgumentException(
				sprintf('A hex key must be exactly %d hex characters', self::KEY_BYTES * 2),
			);
		}

		$raw = hex2bin($hex);
		if ($raw === false) {
			throw new InvalidArgumentException('Key is not valid hex');
		}

		return new self($raw);
	}

	/**
	 * {@inheritdoc}
	 */
	public function key(): string
	{
		return $this->key;
	}

	/**
	 * {@inheritdoc}
	 */
	public function hasKey(): bool
	{
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function fingerprint(): string
	{
		if (!function_exists('sodium_crypto_generichash')) {
			throw new RuntimeException('Cannot fingerprint a key: ext-sodium is not loaded');
		}

		// sodium refuses an output below SODIUM_CRYPTO_GENERICHASH_BYTES_MIN, so hash at the
		// minimum and take 64 bits of it; a fingerprint identifies a key, it does not protect one
		$full = sodium_crypto_generichash(
			self::FINGERPRINT_CONTEXT,
			$this->key,
			SODIUM_CRYPTO_GENERICHASH_BYTES_MIN,
		);

		return substr(bin2hex($full), 0, self::FINGERPRINT_LENGTH);
	}
}
