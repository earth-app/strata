<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_redis\Unit;

/**
 * A client that can name a type and read none of them.
 *
 * A capture resolves its own client separately from the one the walk uses, so this stands in for the
 * capture client while a full stand-in walks the keyspace. It drives the guard that refuses to store a
 * value the client has no command to read whole.
 */
class TypeOnlyRedis
{
	/**
	 * The type every key is reported as holding.
	 */
	public string $type = 'string';

	/**
	 * What type a key holds.
	 *
	 * @param string $key
	 *   The key name, accepted and ignored.
	 *
	 * @return string
	 *   The configured type name.
	 */
	public function type(string $key): string
	{
		return $this->type;
	}
}
