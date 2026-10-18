<?php

declare(strict_types=1);

namespace Drupal\strata\Drill;

/**
 * Reduces a value to a string two sides of a drill can be compared on.
 *
 * A stored value has been through JSON and a live one has not, so an object may come back as an
 * array. Key order is the other half: a field's properties come out in whatever order the loader
 * built them, and two maps holding the same properties in different orders are the same value. A
 * drill that compared raw JSON called every entity with a structured field drifted, which on a real
 * site is every node with a body.
 *
 * A list keeps its order, since a different order is a different value.
 *
 * @see DrillRunner
 */
final class Comparable
{
	/**
	 * The comparable form of a value.
	 *
	 * @param mixed $value
	 *   The value.
	 *
	 * @return string
	 *   JSON with every map's keys sorted, or the serialized value when it has no JSON form.
	 */
	public static function of(mixed $value): string
	{
		$encoded = json_encode(self::sorted($value));

		return $encoded === false ? serialize($value) : $encoded;
	}

	/**
	 * A value with every map's keys in order, all the way down.
	 *
	 * @param mixed $value
	 *   The value.
	 *
	 * @return mixed
	 *   The same value.
	 */
	private static function sorted(mixed $value): mixed
	{
		if (!is_array($value)) {
			return $value;
		}

		$value = array_map(self::sorted(...), $value);

		if (!array_is_list($value)) {
			ksort($value, SORT_STRING);
		}

		return $value;
	}
}
