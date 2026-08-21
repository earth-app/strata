<?php

declare(strict_types=1);

namespace Drupal\strata\Crypto;

use Throwable;

/**
 * Seals with the active key and opens with whichever key on the ring works.
 *
 * A drop-in `CipherInterface`, so nothing in the pipeline knows a rotation is in progress. The object
 * store, the segment writer and the verifier all take a cipher and this is one.
 *
 * **Sealing only ever uses the active key.** A rotation is only finished when nothing is left sealed
 * under a retired key, and sealing with anything but the newest key would mean the rotation could
 * never finish.
 *
 * **The last key that opened something is remembered**, because that is the only way to tell a frame
 * that is up to date from one that predates the rotation. There is no key fingerprint in the frame
 * format, so the fact that a retired key was the one that worked IS the signal, and
 * `KeyRotation` reads it to measure how much of the store still needs the old key.
 *
 * That memory is per-instance and not thread-safe in any sense; it describes the most recent `open()`
 * on this object and is meaningless after any other call. Read it immediately or not at all.
 *
 * **A failure to open reports the cipher's own error, not a guess about the key.** A wrong key and a
 * corrupt byte both fail the Poly1305 tag identically, so claiming the key is at fault would be a
 * conclusion drawn from an observation that does not support it.
 *
 * @see KeyRing
 * @see KeyRotation
 */
final class RotatingCipher implements CipherInterface
{
	/**
	 * The ciphers, in the order they are tried.
	 *
	 * @var list<CipherInterface>
	 */
	private array $ciphers;

	/**
	 * Fingerprint of the key that opened the most recent value.
	 */
	private ?string $openedWith = null;

	/**
	 * Constructs a rotating cipher.
	 *
	 * @param KeyRing $ring
	 *   The keys, active first.
	 */
	public function __construct(private readonly KeyRing $ring)
	{
		$this->ciphers = array_map(
			static fn(KeyProviderInterface $key): CipherInterface => new XChaCha20Poly1305Cipher(
				$key->key(),
			),
			$ring->all(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		// the stored id names the algorithm, not the key, so a rotation does not change it
		return $this->ciphers[0]->id();
	}

	/**
	 * {@inheritdoc}
	 */
	public function isAvailable(): bool
	{
		return $this->ciphers[0]->isAvailable();
	}

	/**
	 * {@inheritdoc}
	 */
	public function unavailableReason(): ?string
	{
		return $this->ciphers[0]->unavailableReason();
	}

	/**
	 * {@inheritdoc}
	 */
	public function seal(string $plain, string $associated = ''): string
	{
		$this->openedWith = null;

		return $this->ciphers[0]->seal($plain, $associated);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @throws AuthenticationFailure
	 *   When no key on the ring opens the value, which cannot be told apart from corruption.
	 */
	public function open(string $sealed, string $associated = ''): string
	{
		$keys = $this->ring->all();
		$last = null;

		foreach ($this->ciphers as $at => $cipher) {
			try {
				$plain = $cipher->open($sealed, $associated);
			} catch (Throwable $error) {
				$last = $error;

				continue;
			}

			$this->openedWith = $keys[$at]->fingerprint();

			return $plain;
		}

		$this->openedWith = null;

		throw new AuthenticationFailure(
			sprintf(
				'None of the %d keys on the ring opened this value; it is sealed under a key that is ' .
					'not configured, or the bytes have changed',
				count($this->ciphers),
			),
			0,
			$last,
		);
	}

	/**
	 * The fingerprint of the key that opened the most recent value.
	 *
	 * @return string|null
	 *   The fingerprint, or NULL when nothing has been opened or the last open failed.
	 */
	public function openedWith(): ?string
	{
		return $this->openedWith;
	}

	/**
	 * Whether the most recent value was opened by the active key.
	 *
	 * FALSE means the value predates the rotation and would be re-sealed by finishing it.
	 *
	 * @return bool
	 *   TRUE when the active key opened it.
	 */
	public function openedWithActiveKey(): bool
	{
		return $this->openedWith !== null && $this->ring->isActive($this->openedWith);
	}

	/**
	 * The ring this cipher draws on.
	 *
	 * @return KeyRing
	 *   The ring.
	 */
	public function ring(): KeyRing
	{
		return $this->ring;
	}
}
