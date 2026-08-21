<?php

declare(strict_types=1);

namespace Drupal\strata\Codec\Dictionary;

use Drupal\strata\Cas\Hash;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Names one version of one realm's dictionary.
 *
 * Every frame compressed against a dictionary records this id, and a frame that cannot name its
 * dictionary cannot be decoded - which makes a dictionary the one kind of object a prune must never
 * collect while any live frame references it.
 *
 * Versioned rather than mutable. Retraining produces a new version and leaves the old one in place,
 * because the frames written against it are still there and still have to open.
 *
 * @see DictionaryStore
 * @see DictionaryTrainer
 */
final class DictionaryRef implements JsonSerializable
{
	/**
	 * Key prefix dictionaries are stored under.
	 */
	public const PREFIX = 'dicts';

	/**
	 * A dictionary built by concatenating samples.
	 *
	 * Zstd accepts any byte string as a raw content dictionary, and on a self-similar corpus it beats
	 * a trained one outright: measured 11.64x against 8.42x on 200 entity payloads.
	 */
	public const RAW = 'raw';

	/**
	 * A dictionary built by the zstd trainer.
	 *
	 * Wins on a diverse corpus - measured 5.42x against 4.96x on 400 mixed payloads - and needs the
	 * `zstd` binary, since no PHP extension exposes the training API.
	 */
	public const TRAINED = 'trained';

	/**
	 * Constructs a reference.
	 *
	 * @param string $realm
	 *   Realm value the dictionary was trained for.
	 * @param int $version
	 *   Version, counting from one.
	 * @param string $address
	 *   Content address of the dictionary bytes.
	 * @param int $bytes
	 *   How large the dictionary is.
	 * @param string $source
	 *   Either RAW or TRAINED.
	 * @param float $ratio
	 *   The compression ratio measured on the samples it was scored against, so the UI can show what
	 *   the dictionary is worth rather than asserting that it helps.
	 * @param int $samples
	 *   How many samples it was built from.
	 * @param int $trainedAt
	 *   Unix seconds.
	 *
	 * @throws InvalidArgumentException
	 *   When the realm is empty, the version is below one, or the address is not a digest.
	 */
	public function __construct(
		public readonly string $realm,
		public readonly int $version,
		public readonly string $address,
		public readonly int $bytes = 0,
		public readonly string $source = self::RAW,
		public readonly float $ratio = 1.0,
		public readonly int $samples = 0,
		public readonly int $trainedAt = 0,
	) {
		if (trim($realm) === '') {
			throw new InvalidArgumentException(
				'A dictionary must name the realm it was trained for',
			);
		}
		if ($version < 1) {
			throw new InvalidArgumentException('A dictionary version starts at one');
		}
		if (!Hash::isValid($address)) {
			throw new InvalidArgumentException('A dictionary address must be a valid digest');
		}
		if ($source !== self::RAW && $source !== self::TRAINED) {
			throw new InvalidArgumentException(sprintf('Unknown dictionary source "%s"', $source));
		}
	}

	/**
	 * The identifier a frame records.
	 *
	 * @return string
	 *   Something such as "entity:3".
	 */
	public function id(): string
	{
		return $this->realm . ':' . $this->version;
	}

	/**
	 * The object key the dictionary lives at.
	 *
	 * @return string
	 *   The key.
	 */
	public function key(): string
	{
		return sprintf('%s/%s/%d.zdict', self::PREFIX, $this->realm, $this->version);
	}

	/**
	 * The realm and version an id names.
	 *
	 * @param string $id
	 *   An id in the form produced by DictionaryRef::id().
	 *
	 * @return array{realm: string, version: int}|null
	 *   The parts, or NULL when the id is not one of ours.
	 */
	public static function parse(string $id): ?array
	{
		$at = strrpos($id, ':');

		if ($at === false || $at === 0 || $at === strlen($id) - 1) {
			return null;
		}

		$version = substr($id, $at + 1);

		if (!ctype_digit($version) || (int) $version < 1) {
			return null;
		}

		return ['realm' => substr($id, 0, $at), 'version' => (int) $version];
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The reference as data.
	 */
	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id(),
			'realm' => $this->realm,
			'version' => $this->version,
			'address' => $this->address,
			'bytes' => $this->bytes,
			'source' => $this->source,
			'ratio' => $this->ratio,
			'samples' => $this->samples,
			'trained_at' => $this->trainedAt,
		];
	}
}
