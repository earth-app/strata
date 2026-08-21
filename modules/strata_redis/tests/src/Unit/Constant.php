<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_redis\Unit;

use ReflectionClass;

/**
 * Reads a class constant at run time.
 *
 * A test pinning a shipped default compares values rather than restating a literal, so the read
 * happens here instead of inside the assertion: a renamed constant then fails the test rather than
 * quietly passing it, and the comparison is a comparison rather than one token against itself.
 */
final class Constant
{
	/**
	 * One class constant.
	 *
	 * @param class-string $class
	 *   The class holding it.
	 * @param string $name
	 *   The constant name.
	 *
	 * @return mixed
	 *   Its value, or FALSE when the class has no constant of that name.
	 */
	public static function of(string $class, string $name): mixed
	{
		return (new ReflectionClass($class))->getConstant($name);
	}
}
