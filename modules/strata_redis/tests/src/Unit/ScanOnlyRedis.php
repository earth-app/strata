<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_redis\Unit;

/**
 * A client that can walk a keyspace and read nothing out of it.
 *
 * Every command the module calls is guarded by `method_exists()`, and this drives the two guards that
 * a full stand-in cannot reach: a walk with no `STRLEN` reports every size as zero rather than
 * raising, and a capture with no `TYPE` names each key as one it could not read rather than storing it
 * approximately.
 */
class ScanOnlyRedis
{
	/**
	 * Key names the walk answers with.
	 *
	 * @var list<string>
	 */
	public array $names = [];

	/**
	 * Walks the keyspace, in one page.
	 *
	 * @param mixed $cursor
	 *   Set to zero, which is how a finished walk is reported.
	 * @param string|null $pattern
	 *   Match pattern, accepted and ignored.
	 * @param int $count
	 *   Keys asked for, accepted and ignored.
	 *
	 * @return list<string>
	 *   Every key name.
	 */
	public function scan(mixed &$cursor, ?string $pattern = null, int $count = 0): array
	{
		$cursor = 0;

		return $this->names;
	}
}
