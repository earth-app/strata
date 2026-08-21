<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_redis\Unit;

use RuntimeException;

/**
 * A Redis stand-in implementing exactly the commands this module calls.
 *
 * `ext-redis` is not loaded on this host and `drupal/redis` is not installed, so the module cannot be
 * installed by a kernel test and there is no server to read. Every class in the module types its
 * client as `object` and checks each command with `method_exists()`, so the contract is driven against
 * a stand-in that answers the way phpredis is documented to: `SCAN` walks a cursor and reports a
 * finished walk with a zero cursor, `TYPE` answers with an integer reply code, and `STRLEN` refuses a
 * container type.
 *
 * Range arguments are accepted and ignored, because the module only ever reads a whole container.
 */
class FakeRedis
{
	/**
	 * Reply codes `TYPE` answers with, as phpredis numbers them.
	 *
	 * Declared here rather than read from the module, so a module map that drifts from phpredis is
	 * reported instead of agreeing with itself.
	 *
	 * @var array<string, int>
	 */
	public const CODES = [
		'string' => 1,
		'set' => 2,
		'list' => 3,
		'zset' => 4,
		'hash' => 5,
		'stream' => 6,
	];

	/**
	 * Keys in the order they were added, name keyed to its type and value.
	 *
	 * @var array<string, array{type: string, value: mixed}>
	 */
	public array $keys = [];

	/**
	 * Commands that throw instead of answering.
	 *
	 * @var list<string>
	 */
	public array $failing = [];

	/**
	 * Whether `scan` reports a refused walk rather than a page.
	 */
	public bool $scanRefuses = false;

	/**
	 * Whether `type` answers with the type name rather than the reply code.
	 */
	public bool $typeAsName = false;

	/**
	 * How many `scan` calls have been made, so a test can prove a walk paged.
	 */
	public int $scans = 0;

	/**
	 * Adds a key.
	 *
	 * @param string $key
	 *   The key name.
	 * @param string $type
	 *   The Redis type it holds.
	 * @param mixed $value
	 *   What the read command answers with, including a value that cannot be read.
	 *
	 * @return self
	 *   The same fake, so keys can be chained.
	 */
	public function hold(string $key, string $type, mixed $value): self
	{
		$this->keys[$key] = ['type' => $type, 'value' => $value];

		return $this;
	}

	/**
	 * Walks the keyspace one page at a time.
	 *
	 * @param mixed $cursor
	 *   NULL to start, and set to the next offset, or to zero once the walk is finished.
	 * @param string|null $pattern
	 *   Match pattern, accepted and ignored.
	 * @param int $count
	 *   Keys asked for, or zero for all of them.
	 *
	 * @return list<string>|false
	 *   A page of key names, or FALSE for a refused walk.
	 *
	 * @throws RuntimeException
	 *   When the command is marked as failing.
	 */
	public function scan(mixed &$cursor, ?string $pattern = null, int $count = 0): array|false
	{
		$this->guard('scan');
		$this->scans++;

		if ($this->scanRefuses) {
			return false;
		}

		$names = array_keys($this->keys);
		$offset = is_int($cursor) ? $cursor : 0;
		$page = array_slice($names, $offset, $count < 1 ? count($names) : $count);
		$next = $offset + count($page);
		$cursor = $next >= count($names) ? 0 : $next;

		return $page;
	}

	/**
	 * The length of a string key.
	 *
	 * @param string $key
	 *   The key name.
	 *
	 * @return int
	 *   The length, or zero for a key that is not there.
	 *
	 * @throws RuntimeException
	 *   When the key holds a container, which is what Redis answers a STRLEN against one with.
	 */
	public function strlen(string $key): int
	{
		$this->guard('strlen');

		if (!isset($this->keys[$key])) {
			return 0;
		}

		$value = $this->keys[$key]['value'];

		if (!is_string($value)) {
			throw new RuntimeException('WRONGTYPE, STRLEN is only defined for a string');
		}

		return strlen($value);
	}

	/**
	 * What type a key holds.
	 *
	 * @param string $key
	 *   The key name.
	 *
	 * @return int|string
	 *   The reply code, or the type name when the fake is set to answer with one.
	 *
	 * @throws RuntimeException
	 *   When the command is marked as failing.
	 */
	public function type(string $key): int|string
	{
		$this->guard('type');

		$type = $this->keys[$key]['type'] ?? '';

		if ($type === '') {
			return $this->typeAsName ? 'none' : 0;
		}

		return $this->typeAsName ? $type : self::CODES[$type] ?? 0;
	}

	/**
	 * The value a string key holds.
	 *
	 * @param string $key
	 *   The key name.
	 *
	 * @return mixed
	 *   The stored value, or FALSE for a key that is not there.
	 *
	 * @throws RuntimeException
	 *   When the command is marked as failing.
	 */
	public function get(string $key): mixed
	{
		$this->guard('get');

		return $this->value($key);
	}

	/**
	 * Every field of a hash.
	 *
	 * @param string $key
	 *   The key name.
	 *
	 * @return mixed
	 *   Field name keyed to its value, or FALSE for a key that is not there.
	 *
	 * @throws RuntimeException
	 *   When the command is marked as failing.
	 */
	public function hGetAll(string $key): mixed
	{
		$this->guard('hGetAll');

		return $this->value($key);
	}

	/**
	 * A range of a list.
	 *
	 * @param string $key
	 *   The key name.
	 * @param int $start
	 *   Range start, accepted and ignored.
	 * @param int $end
	 *   Range end, accepted and ignored.
	 *
	 * @return mixed
	 *   The members in list order, or FALSE for a key that is not there.
	 *
	 * @throws RuntimeException
	 *   When the command is marked as failing.
	 */
	public function lRange(string $key, int $start, int $end): mixed
	{
		$this->guard('lRange');

		return $this->value($key);
	}

	/**
	 * Every member of a set.
	 *
	 * @param string $key
	 *   The key name.
	 *
	 * @return mixed
	 *   The members, or FALSE for a key that is not there.
	 *
	 * @throws RuntimeException
	 *   When the command is marked as failing.
	 */
	public function sMembers(string $key): mixed
	{
		$this->guard('sMembers');

		return $this->value($key);
	}

	/**
	 * A range of a sorted set, with its scores.
	 *
	 * @param string $key
	 *   The key name.
	 * @param int $start
	 *   Range start, accepted and ignored.
	 * @param int $end
	 *   Range end, accepted and ignored.
	 * @param bool $withScores
	 *   Whether scores come back, accepted and ignored because the module always asks for them.
	 *
	 * @return mixed
	 *   Member keyed to its score, or FALSE for a key that is not there.
	 *
	 * @throws RuntimeException
	 *   When the command is marked as failing.
	 */
	public function zRange(string $key, int $start, int $end, bool $withScores = false): mixed
	{
		$this->guard('zRange');

		return $this->value($key);
	}

	/**
	 * What a key holds.
	 *
	 * @param string $key
	 *   The key name.
	 *
	 * @return mixed
	 *   The stored value, or FALSE for a key that is not there.
	 */
	private function value(string $key): mixed
	{
		return $this->keys[$key]['value'] ?? false;
	}

	/**
	 * Throws when a command has been marked as failing.
	 *
	 * @param string $command
	 *   The command being run.
	 *
	 * @throws RuntimeException
	 *   When the command is marked as failing.
	 */
	private function guard(string $command): void
	{
		if (in_array($command, $this->failing, true)) {
			throw new RuntimeException(sprintf('%s is unavailable', $command));
		}
	}
}
