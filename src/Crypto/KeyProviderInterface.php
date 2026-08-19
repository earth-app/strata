<?php

declare(strict_types=1);

namespace Drupal\strata\Crypto;

use RuntimeException;

/**
 * Supplies the key frames are sealed under.
 *
 * Separate from CipherInterface because where a key comes from is a deployment decision and how it
 * is used is not. A site holds its key in the `key` module, which can back it with a file, an
 * environment variable or a secrets manager; a test holds it in memory; neither should change the
 * cipher.
 *
 * The rule this interface exists to enforce: **losing the key loses every backup.** No part of
 * Strata can recover a sealed frame without it, by construction. An implementation that cannot
 * produce the key must raise rather than return a default, an empty string, or a key derived from
 * something guessable - all three would silently seal a bucket nobody can open later, and the
 * failure would not surface until a restore.
 *
 * @see XChaCha20Poly1305Cipher
 */
interface KeyProviderInterface
{
	/**
	 * Bytes a key must contain.
	 */
	public const KEY_BYTES = 32;

	/**
	 * The key material.
	 *
	 * @return string
	 *   Exactly KeyProviderInterface::KEY_BYTES bytes.
	 *
	 * @throws RuntimeException
	 *   When no key is configured, the configured key cannot be read, or it is the wrong length.
	 */
	public function key(): string;

	/**
	 * Whether a usable key is configured right now.
	 *
	 * Lets hook_requirements() and the settings form report the problem before a flush hits it.
	 *
	 * @return bool
	 *   TRUE when KeyProviderInterface::key() would succeed.
	 */
	public function hasKey(): bool;

	/**
	 * A stable, non-secret identifier for the key currently in use.
	 *
	 * Recorded alongside a frame so a rotation can tell which key sealed what without holding
	 * either key. It must not be reversible to the key: implementations derive it by hashing the
	 * key with a fixed personalisation rather than truncating it.
	 *
	 * @return string
	 *   A short hex fingerprint.
	 *
	 * @throws RuntimeException
	 *   When no key is configured.
	 */
	public function fingerprint(): string;
}
