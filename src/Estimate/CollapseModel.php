<?php

declare(strict_types=1);

namespace Drupal\strata\Estimate;

/**
 * How much a rollup window collapses, derived rather than assumed.
 *
 * Compaction rolls several fine windows into one coarse window, and the saving comes from a subject
 * written more than once inside that window: only its last value needs to survive. So the collapse
 * factor is writes per DISTINCT subject, which is the coupon-collector expectation - throw `w`
 * writes at `s` subjects and the number of subjects hit at least once is
 * `s * (1 - (1 - 1/s)^w)`.
 *
 * **This corrects an earlier flat assumption.** A first draft of the cost model used 0.42 for every
 * level, which was invented. The real factor is 1.00 at every level below a day, because a site
 * with hundreds of thousands of subjects and tens of thousands of writes a day almost never writes
 * the same subject twice in a minute, and it only becomes interesting at monthly rollups. That
 * reverses what compaction is for at fine levels: the saving there is recompression and delta
 * re-anchoring, not collapse.
 *
 * **The uniform assumption is the conservative one.** Real writes are skewed - a few hot subjects
 * take a large share - and skew means fewer distinct subjects for the same write count, so the real
 * collapse is at least what this reports. An estimate that promises less saving than it delivers is
 * the right direction to be wrong in.
 *
 * @see Estimator
 */
final class CollapseModel
{
	/**
	 * Seconds in a day, for turning a daily rate into a window's worth of writes.
	 */
	public const SECONDS_PER_DAY = 86_400;

	/**
	 * The collapse factor for one population over one window.
	 *
	 * @param int $writes
	 *   Writes falling inside the window.
	 * @param int $subjects
	 *   Distinct subjects those writes could land on.
	 *
	 * @return float
	 *   Writes per distinct subject, never below 1.0. One means nothing collapses.
	 */
	public static function factor(int $writes, int $subjects): float
	{
		if ($writes < 2 || $subjects < 1) {
			return 1.0;
		}
		if ($subjects === 1) {
			return (float) $writes;
		}

		$distinct = self::distinct($writes, $subjects);

		return $distinct < 1.0 ? (float) $writes : max(1.0, $writes / $distinct);
	}

	/**
	 * How many distinct subjects a number of writes touches.
	 *
	 * @param int $writes
	 *   Writes falling inside the window.
	 * @param int $subjects
	 *   Distinct subjects those writes could land on.
	 *
	 * @return float
	 *   The expected count, which is fractional because it is an expectation.
	 */
	public static function distinct(int $writes, int $subjects): float
	{
		if ($writes < 1 || $subjects < 1) {
			return 0.0;
		}

		// computed through exp/log rather than pow, which underflows to zero at large write counts
		$miss = exp($writes * log(1.0 - 1.0 / $subjects));

		return $subjects * (1.0 - $miss);
	}

	/**
	 * The collapse factor for a daily write rate rolled up over a window.
	 *
	 * @param float $writesPerDay
	 *   Writes a day across the population.
	 * @param int $subjects
	 *   Distinct subjects.
	 * @param int $windowSeconds
	 *   The rollup window.
	 *
	 * @return float
	 *   The factor.
	 */
	public static function forWindow(float $writesPerDay, int $subjects, int $windowSeconds): float
	{
		$writes = (int) round(
			(max(0.0, $writesPerDay) * max(0, $windowSeconds)) / self::SECONDS_PER_DAY,
		);

		return self::factor($writes, $subjects);
	}

	/**
	 * One factor for several populations, weighted by the bytes each contributes.
	 *
	 * The aggregate figure a retention table shows is not any single population's factor. A realm
	 * writing millions of rows to a handful of subjects collapses enormously and contributes almost
	 * nothing; a realm writing once per subject collapses not at all and contributes most of the
	 * bytes. Weighting by bytes is what makes the total honest, and it is why a level's overall
	 * factor sits near 1.00 while one realm inside it is far above.
	 *
	 * @param list<array{bytes: float, factor: float}> $populations
	 *   What each population contributes and how much it collapses.
	 *
	 * @return float
	 *   The weighted factor, 1.0 when nothing contributes anything.
	 */
	public static function weighted(array $populations): float
	{
		$bytes = 0.0;
		$collapsed = 0.0;

		foreach ($populations as $population) {
			$share = max(0.0, $population['bytes']);
			$factor = max(1.0, $population['factor']);

			$bytes += $share;
			$collapsed += $share / $factor;
		}

		if ($bytes <= 0.0 || $collapsed <= 0.0) {
			return 1.0;
		}

		return $bytes / $collapsed;
	}
}
