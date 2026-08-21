<?php

/**
 * @file
 * Declaration-only stub for the redis extension, for STATIC ANALYSIS ONLY.
 *
 * `ext-redis` is a suggest rather than a require, so the class is absent on a host without it and an
 * analyser or an editor has nothing to resolve. Only what this module calls is declared, and the
 * signatures are phpredis 6.x.
 *
 * `RedisJournal` types its client as `object` and checks each stream command with
 * `method_exists()`, so nothing here is needed at runtime; `JournalFactory` is the only place the
 * class itself is named, and it reaches that line only after `RedisJournal::isSupported()`.
 *
 * @see JournalFactory
 * @see RedisJournal
 */

declare(strict_types=1);

use Drupal\strata\Journal\JournalFactory;
use Drupal\strata\Journal\RedisJournal;

if (!class_exists('Redis')) {
	/**
	 * A phpredis client.
	 */
	class Redis
	{
		/**
		 * Opens a connection.
		 *
		 * @param string $host
		 *   Host name, or a unix socket path.
		 * @param int $port
		 *   Port, or 0 for a unix socket.
		 * @param float $timeout
		 *   Connect timeout in seconds.
		 *
		 * @return bool
		 *   TRUE when the connection was opened.
		 */
		public function connect(string $host, int $port = 6379, float $timeout = 0.0): bool
		{
			return false;
		}

		/**
		 * Authenticates.
		 *
		 * @param mixed $credentials
		 *   A password, or a user and password pair.
		 *
		 * @return bool
		 *   TRUE when the credentials were accepted.
		 */
		public function auth(mixed $credentials): bool
		{
			return false;
		}

		/**
		 * Selects a database.
		 *
		 * @param int $index
		 *   The database index.
		 *
		 * @return bool
		 *   TRUE when the database was selected.
		 */
		public function select(int $index): bool
		{
			return false;
		}

		/**
		 * Appends an entry to a stream.
		 *
		 * @param string $key
		 *   The stream key.
		 * @param string $id
		 *   The entry id, or "*" to have one assigned.
		 * @param array<string, string> $values
		 *   The entry fields.
		 *
		 * @return string|false
		 *   The assigned id, or FALSE on failure.
		 */
		public function xAdd(string $key, string $id, array $values): string|false
		{
			return false;
		}

		/**
		 * Reads a range of entries.
		 *
		 * @param string $key
		 *   The stream key.
		 * @param string $start
		 *   Lowest id, or "-" for the first.
		 * @param string $end
		 *   Highest id, or "+" for the last.
		 * @param int $count
		 *   Most entries to return, or -1 for every entry.
		 *
		 * @return array<string, array<string, string>>|false
		 *   Entry id keyed to its fields, or FALSE on failure.
		 */
		public function xRange(
			string $key,
			string $start,
			string $end,
			int $count = -1,
		): array|false {
			return false;
		}

		/**
		 * Removes entries.
		 *
		 * @param string $key
		 *   The stream key.
		 * @param array<int, string> $ids
		 *   Entry ids to remove.
		 *
		 * @return int|false
		 *   How many were removed, or FALSE on failure.
		 */
		public function xDel(string $key, array $ids): int|false
		{
			return false;
		}

		/**
		 * How many entries a stream holds.
		 *
		 * @param string $key
		 *   The stream key.
		 *
		 * @return int|false
		 *   The length, or FALSE on failure.
		 */
		public function xLen(string $key): int|false
		{
			return false;
		}

		/**
		 * Removes a key.
		 *
		 * @param mixed $key
		 *   The key, or several.
		 *
		 * @return int|false
		 *   How many keys were removed, or FALSE on failure.
		 */
		public function del(mixed $key): int|false
		{
			return 0;
		}
	}
}
