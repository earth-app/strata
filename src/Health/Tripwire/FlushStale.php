<?php

declare(strict_types=1);

namespace Drupal\strata\Health\Tripwire;

use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;

/**
 * Captured changes have been waiting for a flush for longer than the site allows.
 *
 * Every other tripwire reads something the store holds, so none of them can see a store that has
 * stopped being written to: a bucket that refuses writes, a lease that never releases or a flush that
 * raises on every cron run all leave the history intact and growing further out of date. The only
 * trace is the recovery-point lag, the age of the oldest operation that exists on this server alone.
 *
 * A warning rather than an error, because the repair ladder sends an error to `reindex` and nothing
 * unattended repairs a flush that fails for a reason outside the site.
 *
 * @see Finding
 */
final class FlushStale implements TripwireInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function code(): string
	{
		return 'flush.stale';
	}

	/**
	 * {@inheritdoc}
	 */
	public function check(array $observation): ?Finding
	{
		$lag = (float) ($observation['flush_lag'] ?? 0.0);
		$limit = (int) ($observation['flush_stale_after'] ?? 0);

		if ($limit < 1 || $lag < $limit) {
			return null;
		}

		return new Finding(
			$this->code(),
			Finding::WARN,
			'flush',
			sprintf(
				'The oldest unsealed change is %s old and the limit is %s, so that much history exists ' .
					'only on this server',
				self::duration((int) $lag),
				self::duration($limit),
			),
		);
	}

	/**
	 * A span of seconds in the largest whole unit that fits.
	 *
	 * @param int $seconds
	 *   The span.
	 *
	 * @return string
	 *   Days, hours, minutes or seconds.
	 */
	private static function duration(int $seconds): string
	{
		[$count, $unit] = match (true) {
			$seconds >= 86400 => [intdiv($seconds, 86400), 'day'],
			$seconds >= 3600 => [intdiv($seconds, 3600), 'hour'],
			$seconds >= 60 => [intdiv($seconds, 60), 'minute'],
			default => [$seconds, 'second'],
		};

		return sprintf('%d %s%s', $count, $unit, $count === 1 ? '' : 's');
	}
}
