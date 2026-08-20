<?php

declare(strict_types=1);

namespace Drupal\strata\Flush;

use Closure;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;

/**
 * Keeps two flushes, or two compactions, from running at once.
 *
 * Cron can overlap with itself, with Drush, and with a request that triggered a flush directly.
 * Two flushes reading the same journal window would each seal it, producing two segments describing
 * the same operations and two commits racing for the same ref.
 *
 * The lease is a row whose name is the primary key, so acquiring it is an INSERT that either
 * succeeds or collides. A holder that dies without releasing leaves a row behind, which is why
 * every lease carries a deadline rather than only an owner.
 *
 * @see Flusher
 */
final class Lease
{
	/**
	 * The table leases live in.
	 */
	public const TABLE = 'strata_lock';

	/**
	 * Lease the flush pipeline takes.
	 */
	public const FLUSH = 'flush';

	/**
	 * Lease compaction takes.
	 */
	public const COMPACTION = 'compaction';

	/**
	 * Seconds a lease is held before it is considered abandoned.
	 */
	public const DEFAULT_TTL = 300;

	/**
	 * The token this instance holds, keyed by lease name.
	 *
	 * @var array<string, string>
	 */
	private array $held = [];

	/**
	 * Returns the current unix timestamp.
	 */
	private readonly Closure $clock;

	/**
	 * Constructs a lease store.
	 *
	 * @param Connection $database
	 *   Where leases are recorded.
	 * @param callable():int $clock
	 *   Returns the current unix timestamp. Injected so a test can drive expiry without sleeping.
	 */
	public function __construct(private readonly Connection $database, ?callable $clock = null)
	{
		$this->clock = $clock === null ? static fn(): int => time() : Closure::fromCallable($clock);
	}

	/**
	 * Takes a lease, or reports that someone else holds it.
	 *
	 * An expired lease is taken over rather than waited for. The previous holder is gone by
	 * definition, and a flush that never runs because a crashed process left a row is worse than one
	 * that overlaps a process which no longer exists.
	 *
	 * @param string $name
	 *   Lease name.
	 * @param int $ttl
	 *   Seconds before this lease expires.
	 *
	 * @return bool
	 *   TRUE when the lease is now held by this instance.
	 */
	public function acquire(string $name, int $ttl = self::DEFAULT_TTL): bool
	{
		$now = ($this->clock)();
		$token = bin2hex(random_bytes(16));

		try {
			$this->database
				->insert(self::TABLE)
				->fields(['name' => $name, 'holder' => $token, 'expires' => $now + $ttl])
				->execute();

			$this->held[$name] = $token;

			return true;
		} catch (IntegrityConstraintViolationException) {
			// the name is the primary key, so a collision means somebody holds it; take it over
			// only once their deadline has passed
			$taken = (int) $this->database
				->update(self::TABLE)
				->fields(['holder' => $token, 'expires' => $now + $ttl])
				->condition('name', $name)
				->condition('expires', $now, '<')
				->execute();

			if ($taken > 0) {
				$this->held[$name] = $token;

				return true;
			}

			return false;
		}
	}

	/**
	 * Releases a lease this instance holds.
	 *
	 * The token is part of the condition, so a process whose lease already expired and was taken
	 * over cannot release the new holder's lease on its way out.
	 *
	 * @param string $name
	 *   Lease name.
	 *
	 * @return bool
	 *   TRUE when a lease held by this instance was released.
	 */
	public function release(string $name): bool
	{
		$token = $this->held[$name] ?? null;

		if ($token === null) {
			return false;
		}

		unset($this->held[$name]);

		return (int) $this->database
			->delete(self::TABLE)
			->condition('name', $name)
			->condition('holder', $token)
			->execute() > 0;
	}

	/**
	 * Whether this instance holds a lease.
	 *
	 * @param string $name
	 *   Lease name.
	 *
	 * @return bool
	 *   TRUE when this instance took it and has not released it.
	 */
	public function holds(string $name): bool
	{
		return isset($this->held[$name]);
	}

	/**
	 * Removes expired leases.
	 *
	 * @return int
	 *   How many were removed.
	 */
	public function collect(): int
	{
		return (int) $this->database
			->delete(self::TABLE)
			->condition('expires', ($this->clock)(), '<')
			->execute();
	}
}
