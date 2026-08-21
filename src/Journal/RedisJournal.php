<?php

declare(strict_types=1);

namespace Drupal\strata\Journal;

use RuntimeException;
use Throwable;

/**
 * A journal backed by a Redis stream.
 *
 * The database journal is always available and is the default. This one exists for the case that
 * breaks it: a site whose write volume makes the journal table itself a contended resource. Two
 * thirds of a Drupal site's captured operations are user access-timestamp churn, so a busy site
 * appends tens of thousands of rows an hour to one table, and every one of those appends sits inside
 * a web request.
 *
 * **A stream, not a list.** `XADD` assigns a monotonic id server-side, which is the same property the
 * table's serial column provides and the reason two concurrent requests get a total order without
 * either taking a lock. A list would need a separate counter and a round trip to read it.
 *
 * **Ids are mapped to sequences, because the interface promises an integer sequence.** A Redis stream
 * id is `<milliseconds>-<counter>`, which does not fit an int without losing the counter. The counter
 * is what orders two appends inside the same millisecond, so it cannot be dropped. The mapping keeps
 * both: the sequence is `milliseconds * 1000 + counter`, which is monotonic as long as fewer than a
 * thousand operations land in one millisecond - and at a thousand appends a millisecond the journal is
 * not the bottleneck.
 *
 * **Redis is not durable the way a database table is.** An `XADD` acknowledged before the append-only
 * file is fsynced is lost on a hard kill. That is a real trade and it is the operator's to make: the
 * loss window is bounded by Redis's own `appendfsync` setting, and it is why this is not the default.
 *
 * @see JournalInterface
 * @see DatabaseJournal
 */
final class RedisJournal implements JournalInterface
{
	/**
	 * Default stream key.
	 */
	public const STREAM = 'strata:journal';

	/**
	 * Counters packed into one millisecond of stream id.
	 *
	 * The multiplier that turns a stream id into the integer sequence the interface promises.
	 */
	public const COUNTERS_PER_MILLISECOND = 1000;

	/**
	 * Field name the operation is stored under.
	 */
	public const FIELD_OP = 'op';

	/**
	 * Field name the payload is stored under.
	 */
	public const FIELD_PAYLOAD = 'payload';

	/**
	 * Constructs a journal.
	 *
	 * @param object $redis
	 *   A connected client exposing the stream commands: `xAdd`, `xRange`, `xLen`, `xDel` and `del`.
	 *   Typed as an object rather than `Redis` so this class does not require `ext-redis` to be loaded
	 *   to be autoloaded, and so a test can drive it over a stand-in.
	 * @param string $stream
	 *   The stream key.
	 *
	 * @throws RuntimeException
	 *   When the client does not expose the stream commands, which is what an older Redis client or a
	 *   Predis instance looks like. Refusing here beats discovering it on the first capture.
	 */
	public function __construct(
		private readonly object $redis,
		private readonly string $stream = self::STREAM,
	) {
		foreach (['xAdd', 'xRange', 'xLen', 'xDel', 'del'] as $command) {
			if (!method_exists($redis, $command)) {
				throw new RuntimeException(
					sprintf(
						'The Redis client does not support %s, which a stream journal needs',
						$command,
					),
				);
			}
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function append(JournalOp $operation, ?string $payload = null): JournalOp
	{
		try {
			/** @var mixed $id */
			$id = $this->redis->xAdd($this->stream, '*', [
				self::FIELD_OP => (string) json_encode($operation->jsonSerialize()),
				self::FIELD_PAYLOAD => $payload ?? '',
			]);
		} catch (Throwable $error) {
			throw new RuntimeException(
				sprintf(
					'Cannot append %s to the journal: %s',
					$operation->key(),
					$error->getMessage(),
				),
				0,
				$error,
			);
		}

		if (!is_string($id) || $id === '') {
			throw new RuntimeException(
				sprintf('Redis did not return an id for %s', $operation->key()),
			);
		}

		return $operation->withSequence(self::sequenceOf($id));
	}

	/**
	 * {@inheritdoc}
	 */
	public function read(int $limit = 5000): array
	{
		if ($limit < 1) {
			return [];
		}

		try {
			/** @var mixed $entries */
			$entries = $this->redis->xRange($this->stream, '-', '+', $limit);
		} catch (Throwable $error) {
			throw new RuntimeException(
				sprintf('Cannot read the journal: %s', $error->getMessage()),
				0,
				$error,
			);
		}

		$window = [];

		foreach (is_array($entries) ? $entries : [] as $id => $fields) {
			if (!is_array($fields)) {
				continue;
			}

			/** @var mixed $decoded */
			$decoded = json_decode((string) ($fields[self::FIELD_OP] ?? ''), true);

			if (!is_array($decoded)) {
				// an entry nothing can read is skipped rather than failing the whole window
				continue;
			}

			$payload = (string) ($fields[self::FIELD_PAYLOAD] ?? '');

			$window[] = [
				'operation' => JournalOp::fromArray($decoded)->withSequence(
					self::sequenceOf((string) $id),
				),
				'payload' => $payload === '' ? null : $payload,
			];
		}

		return $window;
	}

	/**
	 * {@inheritdoc}
	 */
	public function trim(int $throughSequence): int
	{
		$ids = [];

		foreach ($this->entries() as $id => $fields) {
			if (self::sequenceOf($id) <= $throughSequence) {
				$ids[] = $id;
			}
		}

		if ($ids === []) {
			return 0;
		}

		try {
			return (int) $this->redis->xDel($this->stream, $ids);
		} catch (Throwable $error) {
			throw new RuntimeException(
				sprintf('Cannot trim the journal: %s', $error->getMessage()),
				0,
				$error,
			);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function pending(): int
	{
		try {
			return (int) $this->redis->xLen($this->stream);
		} catch (Throwable) {
			// an unreachable journal reports nothing waiting rather than breaking the request
			return 0;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function pendingBytes(): int
	{
		$bytes = 0;

		foreach ($this->entries() as $fields) {
			$bytes += strlen((string) ($fields[self::FIELD_PAYLOAD] ?? ''));
		}

		return $bytes;
	}

	/**
	 * {@inheritdoc}
	 */
	public function oldest(): ?int
	{
		try {
			/** @var mixed $entries */
			$entries = $this->redis->xRange($this->stream, '-', '+', 1);
		} catch (Throwable) {
			return null;
		}

		foreach (is_array($entries) ? $entries : [] as $fields) {
			if (!is_array($fields)) {
				continue;
			}

			/** @var mixed $decoded */
			$decoded = json_decode((string) ($fields[self::FIELD_OP] ?? ''), true);

			if (is_array($decoded) && isset($decoded['microtime'])) {
				return (int) $decoded['microtime'];
			}
		}

		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function clear(): int
	{
		$pending = $this->pending();

		try {
			$this->redis->del($this->stream);
		} catch (Throwable $error) {
			throw new RuntimeException(
				sprintf('Cannot clear the journal: %s', $error->getMessage()),
				0,
				$error,
			);
		}

		return $pending;
	}

	/**
	 * Whether a Redis journal can be used on this host.
	 *
	 * @return bool
	 *   TRUE when the extension is loaded.
	 */
	public static function isSupported(): bool
	{
		return extension_loaded('redis');
	}

	/**
	 * The integer sequence a stream id maps to.
	 *
	 * @param string $id
	 *   A stream id, `<milliseconds>-<counter>`.
	 *
	 * @return int
	 *   The sequence, monotonic in the order Redis assigned the ids.
	 */
	public static function sequenceOf(string $id): int
	{
		$at = strpos($id, '-');

		if ($at === false) {
			return (int) $id * self::COUNTERS_PER_MILLISECOND;
		}

		$milliseconds = (int) substr($id, 0, $at);
		$counter = (int) substr($id, $at + 1);

		return $milliseconds * self::COUNTERS_PER_MILLISECOND +
			min($counter, self::COUNTERS_PER_MILLISECOND - 1);
	}

	/**
	 * Every entry in the stream, oldest first.
	 *
	 * @return array<string, array<string, string>>
	 *   Stream id keyed to its fields.
	 */
	private function entries(): array
	{
		try {
			/** @var mixed $entries */
			$entries = $this->redis->xRange($this->stream, '-', '+');
		} catch (Throwable) {
			return [];
		}

		$found = [];

		foreach (is_array($entries) ? $entries : [] as $id => $fields) {
			if (is_array($fields)) {
				$found[(string) $id] = array_map(
					static fn(mixed $v): string => (string) $v,
					$fields,
				);
			}
		}

		return $found;
	}
}
