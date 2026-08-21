<?php

declare(strict_types=1);

namespace Drupal\strata_redis;

use Drupal\strata\Capture\Classifier\KeyspaceDiscovery;
use Drupal\strata\Capture\Classifier\KeyspaceSourceInterface;
use Generator;
use RuntimeException;
use Throwable;
use Traversable;

/**
 * Enumerates a Redis keyspace with a cursor, so a discovery pass cannot stall the server.
 *
 * **`SCAN`, never `KEYS`.** `KEYS *` is a single blocking command: Redis is single-threaded, so it
 * serves nothing else until the whole keyspace has been walked, and a site's cache backend routinely
 * holds millions of keys. `SCAN` walks the same keyspace in pages with a bounded `COUNT`, which
 * costs more round trips and blocks for none of them. A source that took the shortcut would be a
 * backup module that takes the site down on cron.
 *
 * The guarantees a cursor gives are weaker than a snapshot's, and they are the right ones here: a
 * key present for the whole walk is returned at least once, and a key created or deleted during the
 * walk may or may not appear. Discovery ranks patterns by observed volume, so a key seen twice or
 * missed once changes a total rather than a decision.
 *
 * **Sizes cover string keys only.** `STRLEN` is O(1), so a string reports its real length. Measuring
 * a hash, list, set or sorted set means reading the whole container, which is the cost this class
 * exists to avoid, so those report zero and the byte totals are understood as a lower bound. The
 * capture pass measures real bytes, because it reads the values anyway.
 *
 * The key is yielded verbatim. Classification matches on a key's own leading namespace, so anything
 * prepended here would namespace every key under this module instead of under the bin that owns it.
 *
 * @see KeyspaceSourceInterface
 * @see KeyspaceDiscovery
 * @see RedisCapture
 */
final class RedisKeyspaceSource implements KeyspaceSourceInterface
{
	/**
	 * How this source names itself in a problem report.
	 */
	public const ID = 'redis';

	/**
	 * Keys asked for per `SCAN` call.
	 *
	 * A hint rather than a promise; Redis may return more or fewer. Larger pages mean fewer round
	 * trips and a longer single command, and this is the middle of that trade.
	 */
	public const PAGE = 500;

	/**
	 * Commands a walk needs.
	 *
	 * @var list<string>
	 */
	public const REQUIRED = ['scan'];

	/**
	 * Constructs a source.
	 *
	 * @param RedisClientFactory $clients
	 *   Resolves the client, or reports why there is not one.
	 * @param int $page
	 *   Keys asked for per `SCAN` call.
	 */
	public function __construct(
		private readonly RedisClientFactory $clients,
		private readonly int $page = self::PAGE,
	) {}

	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return self::ID;
	}

	/**
	 * {@inheritdoc}
	 */
	public function isAvailable(): bool
	{
		$client = $this->clients->create();

		return $client !== null && method_exists($client, 'scan');
	}

	/**
	 * {@inheritdoc}
	 */
	public function keys(int $limit): Traversable
	{
		if ($limit < 1) {
			return;
		}

		$client = $this->clients->create();

		if ($client === null) {
			throw new RuntimeException(
				$this->clients->reason() === ''
					? 'there is no Redis client'
					: $this->clients->reason(),
			);
		}
		if (!method_exists($client, 'scan')) {
			throw new RuntimeException(
				'the Redis client does not support scan, which a bounded walk needs',
			);
		}

		yield from $this->walk($client, $limit);
	}

	/**
	 * Why this source cannot be read.
	 *
	 * @return string
	 *   A sentence, or an empty string when it can be.
	 */
	public function reason(): string
	{
		if ($this->isAvailable()) {
			return '';
		}

		$reason = $this->clients->reason();

		return $reason === ''
			? 'the Redis client does not support scan, which a bounded walk needs'
			: $reason;
	}

	/**
	 * Walks the keyspace one page at a time.
	 *
	 * @param object $client
	 *   The client.
	 * @param int $limit
	 *   Most keys to yield.
	 *
	 * @return Generator<string, int>
	 *   Key name keyed to its size in bytes.
	 */
	private function walk(object $client, int $limit): Generator
	{
		$cursor = null;
		$yielded = 0;
		$page = max(1, $this->page);

		do {
			/** @var mixed $found */
			$found = $client->scan($cursor, null, $page);

			// FALSE is how a client reports a finished or refused walk rather than an empty page
			if (!is_array($found)) {
				return;
			}

			foreach ($found as $key) {
				$name = (string) $key;

				if ($name === '') {
					continue;
				}

				yield $name => $this->sizeOf($client, $name);

				if (++$yielded >= $limit) {
					return;
				}
			}
		} while (self::isOpen($cursor));
	}

	/**
	 * The size of one key, when it is one that can be measured in O(1).
	 *
	 * @param object $client
	 *   The client.
	 * @param string $key
	 *   The key.
	 *
	 * @return int
	 *   The string length, or zero for a container type and for a key that went away mid-walk.
	 */
	private function sizeOf(object $client, string $key): int
	{
		if (!method_exists($client, 'strlen')) {
			return 0;
		}

		try {
			/** @var mixed $length */
			$length = $client->strlen($key);
		} catch (Throwable) {
			// a container type refuses STRLEN, and reading it to measure it is the cost being avoided
			return 0;
		}

		return is_int($length) ? max(0, $length) : 0;
	}

	/**
	 * Whether the cursor has more to give.
	 *
	 * @param mixed $cursor
	 *   The cursor the last call left behind.
	 *
	 * @return bool
	 *   TRUE while the walk is unfinished. Redis reports a finished walk with a zero cursor, and a
	 *   client that clears it instead is treated the same way.
	 */
	private static function isOpen(mixed $cursor): bool
	{
		return $cursor !== null && (int) $cursor !== 0;
	}
}
