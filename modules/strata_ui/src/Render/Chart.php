<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Render;

/**
 * The plot area a chart is drawn into.
 *
 * Geometry is computed in PHP and handed to a template as plain numbers, so the drawing itself is a
 * loop over rectangles in Twig and the arithmetic is unit-testable without rendering anything.
 *
 * **The y axis is inverted.** SVG measures y downward from the top, and a chart measures value upward
 * from the baseline, so every value has to be subtracted from the height. Doing that once here is the
 * difference between one correct conversion and one per template.
 *
 * @see TimelineChart
 * @see SeriesChart
 */
final class Chart
{
	/**
	 * The drawing width, in user units.
	 *
	 * The SVG is scaled by its viewBox rather than by these numbers, so a chart is resolution
	 * independent and the CSS decides how large it actually appears.
	 */
	public const WIDTH = 960;

	/**
	 * The drawing height, in user units.
	 */
	public const HEIGHT = 220;

	/**
	 * Space left for the axis labels.
	 *
	 * Wide enough for the longest label any series produces, which is a rate in its own unit -
	 * "41 per second" is thirteen characters and was clipped to "er second" at 56.
	 */
	public const PADDING_LEFT = 92;

	/**
	 * Space left below for the time labels.
	 */
	public const PADDING_BOTTOM = 28;

	/**
	 * Space left above so the tallest bar is not flush with the edge.
	 */
	public const PADDING_TOP = 12;

	/**
	 * Space left at the right edge.
	 */
	public const PADDING_RIGHT = 12;

	/**
	 * Constructs a plot area.
	 *
	 * @param int $width
	 *   Total width in user units.
	 * @param int $height
	 *   Total height in user units.
	 */
	public function __construct(
		public readonly int $width = self::WIDTH,
		public readonly int $height = self::HEIGHT,
	) {}

	/**
	 * The width available for data.
	 *
	 * @return float
	 *   User units.
	 */
	public function plotWidth(): float
	{
		return max(1.0, $this->width - self::PADDING_LEFT - self::PADDING_RIGHT);
	}

	/**
	 * The height available for data.
	 *
	 * @return float
	 *   User units.
	 */
	public function plotHeight(): float
	{
		return max(1.0, $this->height - self::PADDING_TOP - self::PADDING_BOTTOM);
	}

	/**
	 * The y coordinate of the baseline.
	 *
	 * @return float
	 *   User units from the top.
	 */
	public function baseline(): float
	{
		return $this->height - self::PADDING_BOTTOM;
	}

	/**
	 * The x coordinate of a column.
	 *
	 * @param int $index
	 *   Which column, counting from zero.
	 * @param int $columns
	 *   How many columns there are.
	 *
	 * @return float
	 *   User units from the left.
	 */
	public function x(int $index, int $columns): float
	{
		return self::PADDING_LEFT + ($this->plotWidth() / max(1, $columns)) * $index;
	}

	/**
	 * The width of one column, leaving a hairline gap.
	 *
	 * @param int $columns
	 *   How many columns there are.
	 *
	 * @return float
	 *   User units, never below a value that would render as nothing.
	 */
	public function columnWidth(int $columns): float
	{
		return max(0.75, $this->plotWidth() / max(1, $columns) - 1.0);
	}

	/**
	 * The y coordinate for a value.
	 *
	 * @param float $value
	 *   The value.
	 * @param float $max
	 *   The largest value on the axis.
	 *
	 * @return float
	 *   User units from the top.
	 */
	public function y(float $value, float $max): float
	{
		if ($max <= 0.0) {
			return $this->baseline();
		}

		return self::PADDING_TOP + $this->plotHeight() * (1.0 - min(1.0, $value / $max));
	}

	/**
	 * The height of a bar for a value.
	 *
	 * A non-zero value always draws at least a hairline. A bar rounded to zero height is
	 * indistinguishable from no data, which is the one thing a reader must not confuse.
	 *
	 * @param float $value
	 *   The value.
	 * @param float $max
	 *   The largest value on the axis.
	 *
	 * @return float
	 *   User units.
	 */
	public function barHeight(float $value, float $max): float
	{
		if ($value <= 0.0 || $max <= 0.0) {
			return 0.0;
		}

		return max(1.0, $this->baseline() - $this->y($value, $max));
	}

	/**
	 * The viewBox attribute.
	 *
	 * @return string
	 *   The four numbers an SVG viewBox needs.
	 */
	public function viewBox(): string
	{
		return sprintf('0 0 %d %d', $this->width, $this->height);
	}

	/**
	 * Evenly spaced gridline values up to a maximum.
	 *
	 * @param float $max
	 *   The largest value on the axis.
	 * @param int $lines
	 *   How many lines to draw.
	 *
	 * @return list<array{value: float, y: float}>
	 *   Each line's value and its y coordinate.
	 */
	public function gridlines(float $max, int $lines = 4): array
	{
		if ($max <= 0.0 || $lines < 1) {
			return [];
		}

		$grid = [];

		for ($line = 1; $line <= $lines; $line++) {
			$value = ($max / $lines) * $line;
			$grid[] = ['value' => $value, 'y' => $this->y($value, $max)];
		}

		return $grid;
	}
}
