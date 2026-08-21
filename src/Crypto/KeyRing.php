<?php

declare(strict_types=1);

namespace Drupal\strata\Crypto;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * The key new frames are sealed with, plus the retired keys old frames still need.
 *
 * **A rotation cannot re-seal the whole store, so the store holds several generations at once.**
 * Re-sealing every frame means reading, decrypting, re-encrypting and re-uploading every byte of
 * history, which for a site with a year of files is hours of work and a full month's request budget.
 * So a rotation changes which key SEALS and leaves what is already sealed alone; the retired keys stay
 * on the ring for as long as any frame still needs one.
 *
 * **The frame format carries no key fingerprint**, and that is what makes a ring necessary rather
 * than optional. A frame records the cipher id, not which key sealed it, so the only way to find the
 * right key is to try them. The cost is one failed AEAD open per stale key per frame, and AEAD runs
 * at 392 MB/s, so a ring of two or three keys is not measurable next to the network.
 *
 * **The honest limitation.** When no key on the ring opens a frame, that is indistinguishable from
 * the bytes being corrupt - a wrong key and a flipped bit both fail the Poly1305 tag. So a frame that
 * no key opens is reported as unreadable, never as "sealed under a key you have lost", because
 * nothing here can tell those apart.
 *
 * Order matters: the active key is tried first, so the common case costs one open and the retired
 * keys are only reached for frames that predate the rotation.
 *
 * @see RotatingCipher
 * @see KeyRotation
 */
final class KeyRing
{
	/**
	 * Retired keys one ring will hold.
	 *
	 * Each one costs a failed open on every frame that predates it, so a ring that grew without bound
	 * would make the oldest frames the most expensive to read. A site needing more generations than
	 * this has not finished a rotation it started.
	 */
	public const MAX_RETIRED = 4;

	/**
	 * The retired keys, in the order they are tried.
	 *
	 * @var list<KeyProviderInterface>
	 */
	private array $retired;

	/**
	 * Constructs a ring.
	 *
	 * @param KeyProviderInterface $active
	 *   The key new frames are sealed with.
	 * @param list<KeyProviderInterface> $retired
	 *   Keys that still open older frames, newest first.
	 *
	 * @throws InvalidArgumentException
	 *   When the active key holds no value, or too many retired keys are given.
	 */
	public function __construct(private readonly KeyProviderInterface $active, array $retired = [])
	{
		if (!$active->hasKey()) {
			throw new InvalidArgumentException('A key ring needs an active key that holds a value');
		}
		if (count($retired) > self::MAX_RETIRED) {
			throw new InvalidArgumentException(
				sprintf(
					'A key ring holds at most %d retired keys; finish the rotation in progress first',
					self::MAX_RETIRED,
				),
			);
		}

		$this->retired = array_values(
			array_filter($retired, static fn(KeyProviderInterface $key): bool => $key->hasKey()),
		);
	}

	/**
	 * The key new frames are sealed with.
	 *
	 * @return KeyProviderInterface
	 *   The active key.
	 */
	public function active(): KeyProviderInterface
	{
		return $this->active;
	}

	/**
	 * Every key, active first.
	 *
	 * The order a caller must try them in: the active key opens everything written since the last
	 * rotation, which on any site that has been running is nearly everything.
	 *
	 * @return list<KeyProviderInterface>
	 *   The keys.
	 */
	public function all(): array
	{
		return array_merge([$this->active], $this->retired);
	}

	/**
	 * The retired keys.
	 *
	 * @return list<KeyProviderInterface>
	 *   The keys, newest first.
	 */
	public function retired(): array
	{
		return $this->retired;
	}

	/**
	 * Whether this ring holds more than the active key.
	 *
	 * @return bool
	 *   TRUE when a rotation is in progress or has not been finished.
	 */
	public function isRotating(): bool
	{
		return $this->retired !== [];
	}

	/**
	 * How many keys a caller may have to try.
	 *
	 * @return int
	 *   The count, at least one.
	 */
	public function count(): int
	{
		return 1 + count($this->retired);
	}

	/**
	 * The fingerprint of every key on the ring, active first.
	 *
	 * A fingerprint is safe to log and to show an operator; the key itself is not.
	 *
	 * @return list<string>
	 *   The fingerprints.
	 */
	public function fingerprints(): array
	{
		return array_map(
			static fn(KeyProviderInterface $key): string => $key->fingerprint(),
			$this->all(),
		);
	}

	/**
	 * Whether a fingerprint names the active key.
	 *
	 * What decides whether a frame is up to date or still needs a retired key.
	 *
	 * @param string $fingerprint
	 *   The fingerprint to test.
	 *
	 * @return bool
	 *   TRUE when it is the active key's.
	 */
	public function isActive(string $fingerprint): bool
	{
		return hash_equals($this->active->fingerprint(), $fingerprint);
	}

	/**
	 * A ring holding one key and nothing else.
	 *
	 * @param string $key
	 *   Raw key material.
	 *
	 * @return self
	 *   The ring.
	 */
	public static function of(#[SensitiveParameter] string $key): self
	{
		return new self(new StaticKeyProvider($key));
	}
}
