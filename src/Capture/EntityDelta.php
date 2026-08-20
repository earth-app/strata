<?php

declare(strict_types=1);

namespace Drupal\strata\Capture;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Reduces an entity save to the fields that actually changed.
 *
 * A full entity image is expensive and mostly redundant: measured on Drupal-shaped data, a node
 * image is 2,661 bytes and a title edit is 97. The previous version is already in memory during a
 * save as the entity's original, so the comparison costs nothing to obtain and 4.1 to 4.3
 * microseconds to compute.
 *
 * Some fields are excluded by name rather than by comparison. `changed` moves on every save and
 * describes the save rather than the content. `access`, `login` and `init` on a user move on every
 * authenticated request and are two thirds of a Drupal site's total write volume; restoring an old
 * `access` would make an active account look dormant, which is corruption rather than recovery, so
 * they are never part of a delta and never part of a restore.
 *
 * @see EntityCapture
 */
final class EntityDelta
{
	/**
	 * Fields that describe a save rather than content, so a change to one alone is not a change.
	 */
	public const IGNORED = ['changed', 'revision_timestamp', 'revision_uid', 'revision_id', 'vid'];

	/**
	 * Fields excluded from a user delta and from a user restore.
	 */
	public const ACCESS_FIELDS = ['access', 'login', 'init'];

	/**
	 * The fields that changed between an entity and its original.
	 *
	 * @param EntityInterface $entity
	 *   The entity as saved.
	 * @param EntityInterface|null $original
	 *   The entity as it was, or NULL for a create.
	 *
	 * @return list<string>
	 *   Field names that changed, excluding the ignored ones. A create reports every field it has.
	 */
	public static function changedFields(EntityInterface $entity, ?EntityInterface $original): array
	{
		if (!($entity instanceof FieldableEntityInterface)) {
			return [];
		}

		$ignored = self::ignoredFor($entity);
		$current = self::values($entity);

		if (!($original instanceof FieldableEntityInterface)) {
			return array_values(array_diff(array_keys($current), $ignored));
		}

		$previous = self::values($original);
		$changed = [];

		foreach ($current as $name => $value) {
			if (in_array($name, $ignored, true)) {
				continue;
			}
			if (!array_key_exists($name, $previous) || $previous[$name] !== $value) {
				$changed[] = $name;
			}
		}

		foreach (array_keys($previous) as $name) {
			if (!in_array($name, $ignored, true) && !array_key_exists($name, $current)) {
				$changed[] = $name;
			}
		}

		return $changed;
	}

	/**
	 * The delta payload for an entity save.
	 *
	 * Carries only the changed fields, so a restore has exactly what it needs to put the subject
	 * back and nothing that would overwrite an unrelated later change.
	 *
	 * @param EntityInterface $entity
	 *   The entity as saved.
	 * @param EntityInterface|null $original
	 *   The entity as it was, or NULL for a create.
	 *
	 * @return array<string, mixed>
	 *   Field name keyed to its new value.
	 */
	public static function payload(EntityInterface $entity, ?EntityInterface $original): array
	{
		if (!($entity instanceof FieldableEntityInterface)) {
			return [];
		}

		$values = self::values($entity);
		$delta = [];

		foreach (self::changedFields($entity, $original) as $name) {
			$delta[$name] = $values[$name] ?? null;
		}

		return $delta;
	}

	/**
	 * Whether a save changed anything worth capturing.
	 *
	 * A save that moved only `changed`, or only a user's access timestamps, is not a content change.
	 *
	 * @param EntityInterface $entity
	 *   The entity as saved.
	 * @param EntityInterface|null $original
	 *   The entity as it was, or NULL for a create.
	 *
	 * @return bool
	 *   TRUE when at least one meaningful field changed.
	 */
	public static function isMeaningful(EntityInterface $entity, ?EntityInterface $original): bool
	{
		return self::changedFields($entity, $original) !== [];
	}

	/**
	 * Whether a save touched only a user's access timestamps.
	 *
	 * This is the two-thirds case. Such a save is recorded as a compact login event rather than as a
	 * field delta, which keeps the security trail without the volume.
	 *
	 * @param EntityInterface $entity
	 *   The entity as saved.
	 * @param EntityInterface|null $original
	 *   The entity as it was, or NULL for a create.
	 *
	 * @return bool
	 *   TRUE when the only difference is in the access fields.
	 */
	public static function isAccessTouch(EntityInterface $entity, ?EntityInterface $original): bool
	{
		if ($entity->getEntityTypeId() !== 'user' || $original === null) {
			return false;
		}
		if (
			!($entity instanceof FieldableEntityInterface) ||
			!($original instanceof FieldableEntityInterface)
		) {
			return false;
		}

		$current = self::values($entity);
		$previous = self::values($original);
		$moved = false;

		foreach ($current as $name => $value) {
			if (in_array($name, self::IGNORED, true)) {
				continue;
			}

			$differs = !array_key_exists($name, $previous) || $previous[$name] !== $value;

			if (!$differs) {
				continue;
			}
			if (!in_array($name, self::ACCESS_FIELDS, true)) {
				return false;
			}

			$moved = true;
		}

		return $moved;
	}

	/**
	 * Field names excluded for a given entity.
	 *
	 * @param EntityInterface $entity
	 *   The entity.
	 *
	 * @return list<string>
	 *   Field names to skip.
	 */
	private static function ignoredFor(EntityInterface $entity): array
	{
		if ($entity->getEntityTypeId() === 'user') {
			return [...self::IGNORED, ...self::ACCESS_FIELDS];
		}

		return self::IGNORED;
	}

	/**
	 * An entity's field values in a comparable form.
	 *
	 * Computed fields are skipped: they are derived from other fields, so capturing one would record
	 * a change that has no cause and cannot be restored independently.
	 *
	 * Scalars are normalised to strings before comparison. An entity held in memory carries an
	 * integer where the same entity loaded from the database carries the string the driver returned,
	 * so a strict comparison between a saved entity and its original reports every scalar field as
	 * changed. Normalising keeps a genuine change visible - 1 and 2 still differ - while a value that
	 * only crossed the database boundary does not.
	 *
	 * @param FieldableEntityInterface $entity
	 *   The entity.
	 *
	 * @return array<string, mixed>
	 *   Field name keyed to its normalised value list.
	 */
	private static function values(FieldableEntityInterface $entity): array
	{
		$values = [];

		foreach ($entity->getFields(false) as $name => $field) {
			if ($field->getFieldDefinition()->isComputed()) {
				continue;
			}

			$values[$name] = self::normalize($field->getValue());
		}

		return $values;
	}

	/**
	 * Reduces a value to a form that survives a database round trip unchanged.
	 *
	 * @param mixed $value
	 *   Any field value.
	 *
	 * @return mixed
	 *   The value with every scalar cast to a string, recursively. NULL stays NULL, since an absent
	 *   value and an empty string are different things.
	 */
	private static function normalize(mixed $value): mixed
	{
		if (is_array($value)) {
			return array_map(static fn(mixed $item): mixed => self::normalize($item), $value);
		}
		if ($value === null || is_object($value)) {
			return $value;
		}
		if (is_bool($value)) {
			return $value ? '1' : '0';
		}

		return (string) $value;
	}
}
