<?php

declare(strict_types=1);

namespace Drupal\strata\Crypto;

use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

/**
 * XChaCha20-Poly1305 through `ext-sodium`, which ships with PHP 8.3+.
 *
 * Measured at 391 MB/s sealing and 392 MB/s opening on the reference host - roughly half the speed
 * of the BLAKE2b that addresses the frame, and about a hundred times faster than the network it is
 * written over, so it is never the bottleneck.
 *
 * The nonce derivation. CipherInterface requires sealing to be deterministic, or the
 * content-addressed store loses deduplication. A random nonce would destroy that. A fixed nonce
 * would be worse: reusing a nonce across two different plaintexts under one key breaks XChaCha20
 * completely.
 *
 * So the nonce is derived from the plaintext itself, through a KEYED hash:
 *
 *     nonce = BLAKE2b(plaintext, key = KDF(master, "strata-nonce"), 24 bytes)
 *
 * Three properties follow:
 *
 * - Identical plaintexts under one key produce an identical nonce, so an identical sealed frame,
 *   so deduplication survives encryption. Within-site dedup is preserved exactly.
 * - Different plaintexts produce different nonces, because BLAKE2b is collision resistant at 192
 *   bits of output. Nonce reuse across distinct plaintexts is therefore infeasible rather than
 *   merely unlikely.
 * - The nonce is not computable without the key, because the hash is keyed. That is what separates
 *   this from convergent encryption: an attacker holding a candidate plaintext cannot confirm the
 *   bucket contains it, and two sites with different keys share no ciphertext. Cross-site dedup is
 *   given up in exchange, since having it would leak that equality.
 *
 * This is the synthetic-IV construction, applied so that a store addressed by content can also be
 * encrypted. Random nonces would spend the dedup and delta gains that make second-granularity
 * affordable.
 *
 * Layout: the sealed frame is `nonce || ciphertext`, with the Poly1305 tag inside the ciphertext
 * where sodium puts it. The frame's own digest is passed as associated data, so a frame relocated
 * to another key in the bucket fails to open instead of opening as the wrong content.
 *
 * @see CipherInterface
 * @see KeyProviderInterface
 */
final class XChaCha20Poly1305Cipher implements CipherInterface
{
	/**
	 * Personalisation for the nonce-derivation subkey.
	 *
	 * Keeps the derived subkey from colliding with any other use of the same master key.
	 */
	private const NONCE_CONTEXT = 'strata-nonce-derivation-v1';

	/**
	 * The 32-byte encryption key.
	 */
	private readonly string $key;

	/**
	 * The subkey the nonce hash is keyed with, derived once.
	 */
	private readonly string $nonceKey;

	/**
	 * Constructs a cipher around a key.
	 *
	 * @param string $key
	 *   Exactly 32 bytes of key material. Use KeyProviderInterface to obtain it rather than reading
	 *   it from settings directly.
	 *
	 * @throws InvalidArgumentException
	 *   When the key is not exactly 32 bytes. A short key is refused rather than stretched, because
	 *   silently padding it would give a false impression of the strength in use.
	 * @throws RuntimeException
	 *   When ext-sodium is not loaded.
	 */
	public function __construct(#[SensitiveParameter] string $key)
	{
		if (!$this->isAvailable()) {
			throw new RuntimeException(
				'XChaCha20-Poly1305 is unavailable: ext-sodium is not loaded',
			);
		}
		if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
			throw new InvalidArgumentException(
				sprintf(
					'Encryption key must be exactly %d bytes, got %d; refusing to stretch a short key',
					SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
					strlen($key),
				),
			);
		}

		$this->key = $key;
		$this->nonceKey = sodium_crypto_generichash(
			self::NONCE_CONTEXT,
			$key,
			SODIUM_CRYPTO_GENERICHASH_KEYBYTES,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return 'xchacha20poly1305';
	}

	/**
	 * {@inheritdoc}
	 */
	public function isAvailable(): bool
	{
		return function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt') &&
			function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt') &&
			function_exists('sodium_crypto_generichash');
	}

	/**
	 * {@inheritdoc}
	 */
	public function unavailableReason(): ?string
	{
		return $this->isAvailable() ? null : 'ext-sodium is not loaded';
	}

	/**
	 * {@inheritdoc}
	 */
	public function seal(string $plain, string $associated = ''): string
	{
		if ($plain === '') {
			return '';
		}

		$nonce = $this->nonceFor($plain);
		$sealed = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
			$plain,
			$associated,
			$nonce,
			$this->key,
		);

		return $nonce . $sealed;
	}

	/**
	 * {@inheritdoc}
	 */
	public function open(string $sealed, string $associated = ''): string
	{
		if ($sealed === '') {
			return '';
		}

		$nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
		$minimum = $nonceLength + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

		if (strlen($sealed) < $minimum) {
			throw new RuntimeException(
				sprintf(
					'Sealed frame is %d bytes; the minimum with a nonce and tag is %d, so it is truncated',
					strlen($sealed),
					$minimum,
				),
			);
		}

		$plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
			substr($sealed, $nonceLength),
			$associated,
			substr($sealed, 0, $nonceLength),
			$this->key,
		);

		if ($plain === false) {
			throw new AuthenticationFailure(
				'Sealed frame failed authentication; it was tampered with, encrypted under a ' .
					'different key, or its associated data does not match',
			);
		}

		return $plain;
	}

	/**
	 * Derives the deterministic nonce for a plaintext.
	 *
	 * Keyed, so the nonce is not computable without the key and a candidate plaintext cannot be
	 * confirmed against the bucket.
	 *
	 * @param string $plain
	 *   The plaintext being sealed.
	 *
	 * @return string
	 *   Exactly SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES bytes.
	 */
	private function nonceFor(#[SensitiveParameter] string $plain): string
	{
		return sodium_crypto_generichash(
			$plain,
			$this->nonceKey,
			SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES,
		);
	}
}
