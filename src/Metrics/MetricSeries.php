<?php

declare(strict_types=1);

namespace Drupal\strata\Metrics;

use JsonSerializable;

/**
 * One line on a graph: a name, a unit, and a value per bucket.
 *
 * Points are parallel to the timeline's buckets rather than sparse, so several series drawn on one
 * chart share an x-axis by construction. A sparse series would need every consumer to reconcile
 * indexes, and the first one to get it wrong would draw a line that is subtly out of step with the
 * others.
 *
 * The maximum is carried rather than recomputed by the renderer, because an SVG needs it to scale
 * the y-axis before it draws the first point, and a renderer that walked the points twice would
 * disagree with this one about an empty series.
 *
 * @see SeriesBuilder
 */
final class MetricSeries implements JsonSerializable
{
	/**
	 * Constructs a series.
	 *
	 * @param string $key
	 *   Stable identifier, such as "stored_bytes".
	 * @param string $label
	 *   Operator-facing name.
	 * @param list<float> $points
	 *   One value per bucket, oldest first.
	 * @param string $unit
	 *   What the values are counted in.
	 * @param bool $cumulative
	 *   TRUE when each point includes everything before it, which changes how a reader reads a fall.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly array $points = [],
		public readonly string $unit = '',
		public readonly bool $cumulative = false,
	) {}

	/**
	 * The largest value in the series.
	 *
	 * @return float
	 *   The maximum, or 0.0 for an empty series.
	 */
	public function max(): float
	{
		return $this->points === [] ? 0.0 : max($this->points);
	}

	/**
	 * The smallest value in the series.
	 *
	 * @return float
	 *   The minimum, or 0.0 for an empty series.
	 */
	public function min(): float
	{
		return $this->points === [] ? 0.0 : min($this->points);
	}

	/**
	 * The last value.
	 *
	 * @return float
	 *   The latest point, or 0.0 for an empty series.
	 */
	public function latest(): float
	{
		return $this->points === [] ? 0.0 : $this->points[count($this->points) - 1];
	}

	/**
	 * The total across every point.
	 *
	 * @return float
	 *   The sum, which is only meaningful for a series that is not cumulative.
	 */
	public function total(): float
	{
		return array_sum($this->points);
	}

	/**
	 * Whether every point is zero.
	 *
	 * @return bool
	 *   TRUE when the series has nothing to draw.
	 */
	public function isFlat(): bool
	{
		return $this->max() === 0.0 && $this->min() === 0.0;
	}

	/**
	 * How many points the series holds.
	 *
	 * @return int
	 *   The count.
	 */
	public function count(): int
	{
		return count($this->points);
	}

	/**
	 * {@inheritdoc}
	 */
	public function jsonSerialize(): array
	{
		return [
			'key' => $this->key,
			'label' => $this->label,
			'unit' => $this->unit,
			'cumulative' => $this->cumulative,
			'points' => $this->points,
			'max' => $this->max(),
			'latest' => $this->latest(),
		];
	}
}
