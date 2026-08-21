<?php

declare(strict_types=1);

namespace Drupal\strata_redis\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\State\StateInterface;
use Drupal\strata_redis\RedisCapture;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the ephemeral capture pass on cron.
 *
 * Redis holds no events. A cache backend does not tell Drupal when a queue gained an item, and there
 * is no decorator to wrap the way state and the key-value collections are wrapped, so the only way
 * to see the keyspace is to go and look at it. Cron is where looking belongs.
 *
 * **On its own interval, not every cron.** A pass costs a cursor walk plus a round trip per key, so
 * running it on a site whose cron fires every minute would spend most of a minute reading a cache.
 * The interval is deliberately coarser than the flush interval: what this captures changes
 * constantly and is never written back by a restore, so reading it more often buys precision in the
 * one realm that cannot use it.
 *
 * **Nothing here throws.** A cron hook that raises stops every later hook in the queue, which would
 * take the site's search indexing down with a Redis outage. The pass reports its own failures and
 * this catches anything left.
 *
 * @see RedisCapture
 */
final class CronCapture
{
	/**
	 * State key holding when the last pass ran.
	 */
	public const KEY = 'strata_redis.captured';

	/**
	 * Seconds between passes.
	 *
	 * Fifteen minutes.
	 */
	public const INTERVAL = 900;

	/**
	 * Constructs the hook.
	 *
	 * @param RedisCapture $capture
	 *   The pass.
	 * @param StateInterface $state
	 *   Remembers when the last pass ran.
	 * @param LoggerInterface $logger
	 *   Records a pass that could not run at all.
	 * @param int $interval
	 *   Seconds between passes.
	 */
	public function __construct(
		private readonly RedisCapture $capture,
		private readonly StateInterface $state,
		private readonly LoggerInterface $logger,
		private readonly int $interval = self::INTERVAL,
	) {}

	/**
	 * Captures the keyspace, if a pass is due.
	 */
	#[Hook('cron')]
	public function onCron(): void
	{
		try {
			if (!$this->isDue()) {
				return;
			}

			// recorded before the pass, so one that dies does not retry on every cron afterwards
			$this->state->set(self::KEY, time());
			$this->capture->capture();
		} catch (Throwable $error) {
			$this->logger->error('Strata could not capture ephemeral state on cron: %message', [
				'%message' => $error->getMessage(),
			]);
		}
	}

	/**
	 * Whether enough time has passed since the last pass.
	 *
	 * @return bool
	 *   TRUE when a pass is due.
	 */
	private function isDue(): bool
	{
		$last = (int) $this->state->get(self::KEY, 0);

		return $last < 1 || time() - $last >= max(60, $this->interval);
	}
}
