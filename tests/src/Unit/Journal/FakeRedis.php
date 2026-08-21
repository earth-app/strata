<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Journal;

use RuntimeException;

/**
 * A Redis stand-in implementing exactly the stream commands the journal uses.
 *
 * `ext-redis` is not loaded on this host, and a journal is not the place to find that out at runtime,
 * so the contract is exercised against a stand-in that behaves the way the commands are documented:
 * `xAdd` assigns `<milliseconds>-<counter>` with the counter breaking ties inside a millisecond, and
 * `xRange` returns entries oldest first keyed by id.
 */
class FakeRedis
{
	/**
	 * Entries, id keyed to fields.
	 *
	 * @var array<string, array<string, string>>
	 */
	public array $entries = [];

	/**
	 * Milliseconds the next id is stamped with.
	 */
	public int $clock = 1_700_000_000_000;

	/**
	 * Commands that throw instead of answering.
	 *
	 * @var list<string>
	 */
	public array $failing = [];

	/**
	 * Counter within the current millisecond.
	 */
	private int $counter = 0;

	/**
	 * Appends an entry, assigning an id when asked with a star.
	 *
	 * @param string $stream
	 *   The stream key, unused beyond matching the real signature.
	 * @param string $id
	 *   The id to use, or "*" to have one assigned.
	 * @param array<string, string> $fields
	 *   The entry's fields.
	 *
	 * @return string
	 *   The id the entry was stored under.
	 */
	public function xAdd(string $stream, string $id, array $fields): string
	{
		$this->guard('xAdd');

		$assigned = $id === '*' ? sprintf('%d-%d', $this->clock, $this->counter++) : $id;
		$this->entries[$assigned] = $fields;

		return $assigned;
	}

	/**
	 * Reads entries in id order.
	 *
	 * @param string $stream
	 *   The stream key.
	 * @param string $start
	 *   Range start, unused because the fixture holds one stream.
	 * @param string $end
	 *   Range end, likewise.
	 * @param int $count
	 *   Most entries to return, or zero for all of them.
	 *
	 * @return array<string, array<string, string>>
	 *   Id keyed to fields, oldest first.
	 */
	public function xRange(string $stream, string $start, string $end, int $count = 0): array
	{
		$this->guard('xRange');

		$entries = $this->entries;
		ksort($entries);

		return $count > 0 ? array_slice($entries, 0, $count, true) : $entries;
	}

	/**
	 * How many entries the stream holds.
	 *
	 * @param string $stream
	 *   The stream key.
	 *
	 * @return int
	 *   The count.
	 */
	public function xLen(string $stream): int
	{
		$this->guard('xLen');

		return count($this->entries);
	}

	/**
	 * Removes entries by id.
	 *
	 * @param string $stream
	 *   The stream key.
	 * @param list<string> $ids
	 *   Ids to remove.
	 *
	 * @return int
	 *   How many were removed.
	 */
	public function xDel(string $stream, array $ids): int
	{
		$this->guard('xDel');

		$removed = 0;

		foreach ($ids as $id) {
			if (isset($this->entries[$id])) {
				unset($this->entries[$id]);
				$removed++;
			}
		}

		return $removed;
	}

	/**
	 * Drops the stream.
	 *
	 * @param string $stream
	 *   The stream key.
	 *
	 * @return int
	 *   One, matching what Redis returns for a key that existed.
	 */
	public function del(string $stream): int
	{
		$this->guard('del');

		$this->entries = [];

		return 1;
	}

	/**
	 * Advances the clock, so a test can put two appends in different milliseconds.
	 *
	 * @param int $milliseconds
	 *   How far to advance.
	 */
	public function tick(int $milliseconds = 1): void
	{
		$this->clock += $milliseconds;
		$this->counter = 0;
	}

	/**
	 * Throws when a command has been marked as failing.
	 *
	 * @param string $command
	 *   The command being run.
	 */
	private function guard(string $command): void
	{
		if (in_array($command, $this->failing, true)) {
			throw new RuntimeException(sprintf('%s is unavailable', $command));
		}
	}
}
