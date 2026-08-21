<?php

declare(strict_types=1);

namespace Drupal\strata\Anomaly;

/**
 * A run of observations of one measurement, and the statistics a detector asks of it.
 *
 * The median and the median absolute deviation are used rather than the mean and the standard
 * deviation. The series a detector watches is a site's own history, and a site's history contains
 * the very spikes the detector exists to find - a mean and a standard deviation computed over a
 * window containing one large spike are both dragged toward it, which raises the threshold enough
 * for the next spike to look ordinary. The median absolute deviation is not moved by a minority of
 * outliers, so yesterday's incident does not hide today's.
 *
 * The 1.4826 scale factor makes the deviation comparable to a standard deviation for normally
 * distributed data, so a threshold expressed in sigma means roughly what a reader expects.
 *
 * @see AnomalyDetector
 */
final class Series
{
	/**
	 * Scales the median absolute deviation onto the standard-deviation scale.
	 */
	public const SCALE = 1.4826;

	/**
	 * Fewest observations before a deviation is meaningful.
	 *
	 * Under this a series has no shape to depart from, and a detector says so rather than reporting
	 * that the first value it ever saw is unusual.
	 */
	public const MIN_OBSERVATIONS = 8;

	/**
	 * The observations, sorted ascending.
	 *
	 * @var list<float>
	 */
	private array $sorted;

	/**
	 * Constructs a series.
	 *
	 * @param list<float> $values
	 *   The observations, in any order.
	 */
	public function __construct(private readonly array $values = [])
	{
		$this->sorted = $values;
		sort($this->sorted);
	}

	/**
	 * How many observations the series holds.
	 *
	 * @return int
	 *   The count.
	 */
	public function count(): int
	{
		return count($this->values);
	}

	/**
	 * Whether the series is long enough to have a shape.
	 *
	 * @param int $minimum
	 *   Fewest observations to accept.
	 *
	 * @return bool
	 *   TRUE when a deviation can be computed meaningfully.
	 */
	public function isEstablished(int $minimum = self::MIN_OBSERVATIONS): bool
	{
		return $this->count() >= max(2, $minimum);
	}

	/**
	 * The middle observation.
	 *
	 * @return float
	 *   The median, or 0.0 for an empty series.
	 */
	public function median(): float
	{
		return self::medianOf($this->sorted);
	}

	/**
	 * The median absolute deviation, scaled onto the standard-deviation scale.
	 *
	 * @return float
	 *   The deviation, or 0.0 when every observation is identical.
	 */
	public function deviation(): float
	{
		if ($this->sorted === []) {
			return 0.0;
		}

		$median = $this->median();
		$distances = [];

		foreach ($this->sorted as $value) {
			$distances[] = abs($value - $median);
		}

		sort($distances);

		return self::medianOf($distances) * self::SCALE;
	}

	/**
	 * How far a value sits from the middle, in deviations.
	 *
	 * A series whose observations are all identical has no deviation, so any different value is
	 * infinitely unusual by the arithmetic. That is not useful, so the distance is expressed against
	 * the median instead: a value double the median scores 1.0 rather than infinity.
	 *
	 * @param float $value
	 *   The observation to score.
	 *
	 * @return float
	 *   Signed deviations from the median.
	 */
	public function score(float $value): float
	{
		$deviation = $this->deviation();

		if ($deviation > 0.0) {
			return ($value - $this->median()) / $deviation;
		}

		$median = $this->median();

		if ($median === 0.0) {
			return $value === 0.0 ? 0.0 : ($value > 0.0 ? 1.0 : -1.0);
		}

		return ($value - $median) / abs($median);
	}

	/**
	 * The most recent observation.
	 *
	 * @return float|null
	 *   The last value passed in, or NULL for an empty series.
	 */
	public function latest(): ?float
	{
		return $this->values === [] ? null : $this->values[count($this->values) - 1];
	}

	/**
	 * The series without its most recent observation.
	 *
	 * A detector scores the newest value against the history BEFORE it, so the value being judged
	 * does not shift the baseline it is judged against.
	 *
	 * @return self
	 *   The earlier observations.
	 */
	public function history(): self
	{
		$values = $this->values;
		array_pop($values);

		return new self($values);
	}

	/**
	 * The mean, for a report that wants one alongside the median.
	 *
	 * @return float
	 *   The mean, or 0.0 for an empty series.
	 */
	public function mean(): float
	{
		return $this->values === [] ? 0.0 : array_sum($this->values) / count($this->values);
	}

	/**
	 * The middle value of an already-sorted list.
	 *
	 * @param list<float> $sorted
	 *   Ascending values.
	 *
	 * @return float
	 *   The median, or 0.0 for an empty list.
	 */
	private static function medianOf(array $sorted): float
	{
		$count = count($sorted);

		if ($count === 0) {
			return 0.0;
		}
		if ($count % 2 === 1) {
			return $sorted[intdiv($count, 2)];
		}

		return ($sorted[intdiv($count, 2) - 1] + $sorted[intdiv($count, 2)]) / 2.0;
	}
}
