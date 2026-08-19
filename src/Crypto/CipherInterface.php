<?php

declare(strict_types=1);

namespace Drupal\strata\Crypto;

use RuntimeException;

/**
 * Seals a frame before it leaves the site.
 *
 * Anyone with read access to the bucket otherwise reads the entire database history, including
 * session state and API-key rows, so encryption is on by default rather than offered.
 *
 * The contract has one unusual requirement: sealing must be DETERMINISTIC for a given key and
 * plaintext. Strata addresses every object by the digest of its content, so if the same frame
 * sealed twice produced different bytes the store would lose deduplication entirely - the measured
 * dedup and delta gains are what make second-granularity affordable, and a random nonce would
 * spend all of them. CipherInterface::seal() must therefore be a pure function of the key and the
 * plaintext. How an implementation achieves that without reusing a nonce across different
 * plaintexts is the implementation's problem, and XChaCha20Poly1305Cipher documents its answer.
 *
 * @see XChaCha20Poly1305Cipher
 * @see NullCipher
 */
interface CipherInterface
{
	/**
	 * The stable identifier recorded in a frame header.
	 *
	 * Never change this for an existing cipher; add a new one, or frames already in the bucket stop
	 * being decryptable.
	 *
	 * @return string
	 *   A short lowercase token such as "xchacha20poly1305" or "none".
	 */
	public function id(): string;

	/**
	 * Whether this cipher can run on this host.
	 *
	 * @return bool
	 *   TRUE when every extension it needs is present.
	 */
	public function isAvailable(): bool;

	/**
	 * Why the cipher is unavailable, for the settings form and hook_requirements().
	 *
	 * @return string|null
	 *   A short human-readable reason, or NULL when the cipher is available.
	 */
	public function unavailableReason(): ?string;

	/**
	 * Seals a frame.
	 *
	 * @param string $plain
	 *   The bytes to seal. An empty string seals to an empty string, so an absent payload never
	 *   becomes a non-empty frame.
	 * @param string $associated
	 *   Additional authenticated data - bound to the ciphertext but not encrypted. Strata passes
	 *   the frame's own digest, which is what makes a frame moved to a different key in the bucket
	 *   fail to open rather than open as the wrong content.
	 *
	 * @return string
	 *   The sealed bytes, including whatever nonce and tag the implementation needs to open them.
	 *
	 * @throws RuntimeException
	 *   When the cipher is unavailable, or sealing fails.
	 */
	public function seal(string $plain, string $associated = ''): string;

	/**
	 * Opens a sealed frame.
	 *
	 * Must raise on any failure. A cipher that returned partial or unauthenticated output would
	 * hand Drupal plausible-looking content that nothing downstream can tell from the real thing.
	 *
	 * @param string $sealed
	 *   The sealed bytes.
	 * @param string $associated
	 *   The same additional authenticated data used to seal.
	 *
	 * @return string
	 *   The original bytes.
	 *
	 * @throws RuntimeException
	 *   When the cipher is unavailable, the input is malformed, the tag does not verify, or the
	 *   associated data does not match.
	 */
	public function open(string $sealed, string $associated = ''): string;
}
