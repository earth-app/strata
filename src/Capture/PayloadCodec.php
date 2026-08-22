<?php

declare(strict_types=1);

namespace Drupal\strata\Capture;

use Drupal\strata\Journal\Realm;
use Drupal\strata\Restore\Replayer;
use JsonException;

/**
 * Encodes and decodes an operation's payload, per realm.
 *
 * Most realms hold a map of named values and JSON describes them exactly. State and the key-value
 * collections do not: they hold arbitrary PHP values, and a value that round-tripped through JSON
 * would come back as a different type - an object as an array, an integer-keyed list as an object
 *  - and be written back wrong on a restore. So those two are serialized instead.
 *
 * Keeping both sides of that decision in one class is the point. The recorder and the replayer have
 * to agree byte for byte, and a payload written by one release has to stay readable by every later
 * one, so the choice cannot live in two places that can drift apart.
 *
 * **Objects are refused rather than reconstructed.** Deserializing arbitrary classes out of a
 * backup is how a restore becomes remote code execution, so no class is allowed through. A value
 * that genuinely held an object comes back as unreadable, which makes the subject degraded and
 * leaves the live value alone. Unknown beats incorrect, and a state key holding an object is a bug
 * in whatever put it there.
 *
 * @see KeyRecorder
 * @see Replayer
 */
final class PayloadCodec
{
	/**
	 * The key a single serialized value is presented under.
	 *
	 * A replay merges field maps, so a realm holding one unnamed value needs a name for it.
	 */
	public const VALUE = 'value';

	/**
	 * Realms whose payload is one arbitrary PHP value rather than a map of fields.
	 *
	 * @var list<Realm>
	 */
	public const SERIALIZED_REALMS = [Realm::STATE, Realm::KEY_VALUE];

	/**
	 * Encodes a value for storage.
	 *
	 * @param Realm $realm
	 *   The realm the operation belongs to.
	 * @param mixed $value
	 *   A field map for most realms; any value for state and key-value.
	 *
	 * @return string
	 *   The payload bytes.
	 *
	 * @throws JsonException
	 *   When a JSON realm holds a string that is not valid UTF-8. `(string) false` would otherwise
	 *   store an empty payload that decodes to NULL, so the operation would claim to carry a value
	 *   it does not.
	 */
	public static function encode(Realm $realm, mixed $value): string
	{
		if (self::isSerialized($realm)) {
			return serialize($value);
		}

		return json_encode($value, JSON_THROW_ON_ERROR);
	}

	/**
	 * Decodes a payload back to the field map a replay merges.
	 *
	 * @param Realm $realm
	 *   The realm the operation belongs to.
	 * @param string $payload
	 *   The stored bytes.
	 *
	 * @return array<string, mixed>|null
	 *   The field map, or NULL when the payload does not decode to one. A realm holding a single value
	 *   presents it under PayloadCodec::VALUE.
	 */
	public static function decode(Realm $realm, string $payload): ?array
	{
		if (self::isSerialized($realm)) {
			return self::unserializeValue($payload);
		}

		/** @var array<string, mixed>|null $decoded */
		$decoded = json_decode($payload, true);

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * Whether a realm's payload is a serialized value rather than a field map.
	 *
	 * @param Realm $realm
	 *   The realm.
	 *
	 * @return bool
	 *   TRUE for state and key-value.
	 */
	public static function isSerialized(Realm $realm): bool
	{
		return in_array($realm, self::SERIALIZED_REALMS, true);
	}

	/**
	 * Unserializes one stored value, refusing anything holding an object.
	 *
	 * @param string $payload
	 *   The stored bytes.
	 *
	 * @return array<string, mixed>|null
	 *   The value under PayloadCodec::VALUE, or NULL when it cannot be reconstructed faithfully.
	 */
	private static function unserializeValue(string $payload): ?array
	{
		if ($payload === '') {
			return null;
		}

		$value = @unserialize($payload, ['allowed_classes' => false]);

		// serialize(FALSE) is 'b:0;', which is the one legitimate value unserialize returns FALSE for
		if ($value === false && $payload !== 'b:0;') {
			return null;
		}
		if (self::holdsObject($value)) {
			return null;
		}

		return [self::VALUE => $value];
	}

	/**
	 * Whether a decoded value contains a class that was refused.
	 *
	 * @param mixed $value
	 *   The decoded value.
	 * @param int $depth
	 *   Recursion guard, since a stored value can nest arbitrarily.
	 *
	 * @return bool
	 *   TRUE when anything in it came back as an incomplete class.
	 */
	private static function holdsObject(mixed $value, int $depth = 0): bool
	{
		if ($depth > 32) {
			return true;
		}
		if (is_object($value)) {
			return true;
		}
		if (!is_array($value)) {
			return false;
		}

		foreach ($value as $item) {
			if (self::holdsObject($item, $depth + 1)) {
				return true;
			}
		}

		return false;
	}
}
