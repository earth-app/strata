<?php

declare(strict_types=1);

namespace Drupal\strata\Crypto;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\key\Entity\Key;
use RuntimeException;
use Throwable;

/**
 * Creates the key entity Strata seals frames with.
 *
 * Encryption ships on with no key, so the shipped state of a fresh install is "every flush refuses".
 * Getting out of it meant knowing that the fix lives in another module, that the key type is
 * `encryption`, that the size is 256 bits and that the value has to be exactly 32 bytes - four facts
 * an operator setting up a backup has no reason to know. This turns all four into one button.
 *
 * **The value is stored in configuration, which means it is exported with the site's configuration
 * and captured into the store it protects.** A site that loses its config export loses the key and
 * with it every frame ever written. A deployment that keeps secrets out of config points the key
 * entity at the `file` or `env` provider instead, which is the key module's own job and needs no
 * help from here.
 *
 * @see StaticKeyProvider
 * @see KeyRotation
 */
final class KeyMaker
{
	/**
	 * Machine name a generated key takes when nothing else is asked for.
	 */
	public const DEFAULT_ID = 'strata_backup';

	/**
	 * Most ids tried before giving up on finding a free one.
	 */
	private const ATTEMPTS = 100;

	/**
	 * Constructs the maker.
	 *
	 * @param EntityTypeManagerInterface $entities
	 *   Where the key entity is saved. Cheap to take: nothing here assembles a store.
	 */
	public function __construct(private readonly EntityTypeManagerInterface $entities) {}

	/**
	 * Whether a key can be created on this site at all.
	 *
	 * The key module is a hard dependency, so this is FALSE only where the module list and the
	 * container disagree - which is the state the 1.0.2 install bug left sites in.
	 *
	 * @return bool
	 *   TRUE when the key entity type is installed.
	 */
	public function available(): bool
	{
		try {
			$this->entities->getStorage('key');

			return true;
		} catch (Throwable) {
			return false;
		}
	}

	/**
	 * Creates a key holding fresh random bytes.
	 *
	 * @param string $wanted
	 *   The machine name to prefer. A suffix is added when it is taken, so calling this twice makes
	 *   two keys rather than overwriting the first - the value inside an existing key is the only
	 *   copy of it, and replacing one would make everything it sealed unreadable.
	 *
	 * @return string
	 *   The machine name the key was actually saved under.
	 *
	 * @throws RuntimeException
	 *   When the key entity type is not installed, or every candidate id is taken.
	 */
	public function create(string $wanted = self::DEFAULT_ID): string
	{
		if (!$this->available()) {
			throw new RuntimeException('The key module is not installed, so no key can be created');
		}

		$id = $this->freeId(trim($wanted) === '' ? self::DEFAULT_ID : trim($wanted));

		/** @var Key $key */
		$key = $this->entities->getStorage('key')->create([
			'id' => $id,
			'label' => 'Strata Backup Encryption',
			'description' =>
				'Seals every frame Strata writes. Losing it makes stored history unreadable.',
			'key_type' => 'encryption',
			'key_type_settings' => ['key_size' => KeyProviderInterface::KEY_BYTES * 8],
			'key_provider' => 'config',
			'key_provider_settings' => [
				// raw bytes are not valid utf-8 and configuration is yaml, so the value is stored
				// encoded and the provider decodes it on the way back out
				'base64_encoded' => true,
				'key_value' => base64_encode(random_bytes(KeyProviderInterface::KEY_BYTES)),
			],
			'key_input' => 'text_field',
			'key_input_settings' => ['base64_encoded' => true],
		]);

		$key->save();

		return $id;
	}

	/**
	 * The first machine name in the series that no key holds.
	 *
	 * @param string $wanted
	 *   The preferred name.
	 *
	 * @return string
	 *   A free machine name.
	 *
	 * @throws RuntimeException
	 *   When every candidate is taken.
	 */
	private function freeId(string $wanted): string
	{
		$storage = $this->entities->getStorage('key');

		for ($suffix = 1; $suffix <= self::ATTEMPTS; $suffix++) {
			$id = $suffix === 1 ? $wanted : $wanted . '_' . $suffix;

			if ($storage->load($id) === null) {
				return $id;
			}
		}

		throw new RuntimeException(
			sprintf('Every key id from %s to %s_%d is taken', $wanted, $wanted, self::ATTEMPTS),
		);
	}
}
