<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Render;

use Drupal\strata\Metrics\MetricSeries;

/**
 * Turns a metric series into the polyline a template draws.
 *
 * **Each series gets its own axis.** Stored bytes and a compression ratio share no unit, and drawing
 * them against one scale makes the ratio a flat line at the bottom of the chart. So every series is
 * one small chart scaled to its own peak, and the peak is labelled - which is the only way a reader
 * can tell a flat line at 5.8x from a flat line at zero.
 *
 * A series whose every point is zero is reported as flat rather than drawn, because a polyline along
 * the baseline is indistinguishable from an axis and invites the reader to conclude the measurement
 * is broken.
 *
 * @see MetricSeries
 * @see Chart
 */
final class SeriesChart
{
	/**
	 * The height one series is drawn at.
	 *
	 * Shorter than a full chart because six of them stack on one page.
	 */
	public const HEIGHT = 120;

	/**
	 * Constructs a chart builder.
	 *
	 * @param Chart|null $chart
	 *   The plot area, or NULL for one at the stacked height.
	 */
	public function __construct(private readonly ?Chart $chart = null) {}

	/**
	 * Everything a template needs to draw one series.
	 *
	 * @param MetricSeries $series
	 *   The series.
	 *
	 * @return array<string, mixed>
	 *   The polyline points, the area path, the gridlines and the labels.
	 */
	public function build(MetricSeries $series): array
	{
		$chart = $this->chart();
		$max = $series->max();
		$points = $this->points($series, $max);

		return [
			'key' => $series->key,
			'label' => $series->label,
			'unit' => $series->unit,
			'cumulative' => $series->cumulative,
			'view_box' => $chart->viewBox(),
			'baseline' => $chart->baseline(),
			'points' => $this->polyline($points),
			'area' => $this->area($points, $chart),
			'dots' => $points,
			'gridlines' => $this->gridlines($max, $series->unit),
			'peak' => Format::inUnit($max, $series->unit),
			'latest' => Format::inUnit($series->latest(), $series->unit),
			'flat' => $series->isFlat(),
			'count' => $series->count(),
		];
	}

	/**
	 * Every series, built.
	 *
	 * @param array<string, MetricSeries> $series
	 *   Series key to series.
	 *
	 * @return list<array<string, mixed>>
	 *   One built chart each, in the order given.
	 */
	public function buildAll(array $series): array
	{
		$charts = [];

		foreach ($series as $one) {
			$charts[] = $this->build($one);
		}

		return $charts;
	}

	/**
	 * The plot area in use.
	 *
	 * @return Chart
	 *   The area.
	 */
	private function chart(): Chart
	{
		return $this->chart ?? new Chart(Chart::WIDTH, self::HEIGHT);
	}

	/**
	 * The coordinates of every point.
	 *
	 * @param MetricSeries $series
	 *   The series.
	 * @param float $max
	 *   The largest value.
	 *
	 * @return list<array{x: float, y: float, value: float, label: string}>
	 *   The points, oldest first.
	 */
	private function points(MetricSeries $series, float $max): array
	{
		$chart = $this->chart();
		$columns = max(1, $series->count() - 1);
		$points = [];

		foreach ($series->points as $index => $value) {
			$points[] = [
				'x' => $chart->x($index, $columns + 1),
				'y' => $chart->y($value, $max),
				'value' => $value,
				'label' => Format::inUnit($value, $series->unit),
			];
		}

		return $points;
	}

	/**
	 * The points attribute of a polyline.
	 *
	 * @param list<array{x: float, y: float, value: float, label: string}> $points
	 *   The points.
	 *
	 * @return string
	 *   Space-separated coordinate pairs.
	 */
	private function polyline(array $points): string
	{
		$pairs = [];

		foreach ($points as $point) {
			$pairs[] = sprintf('%.2f,%.2f', $point['x'], $point['y']);
		}

		return implode(' ', $pairs);
	}

	/**
	 * A closed path filling the area under the line.
	 *
	 * @param list<array{x: float, y: float, value: float, label: string}> $points
	 *   The points.
	 * @param Chart $chart
	 *   The plot area.
	 *
	 * @return string
	 *   The path, empty when there is nothing to fill.
	 */
	private function area(array $points, Chart $chart): string
	{
		if (count($points) < 2) {
			return '';
		}

		$first = $points[0];
		$last = $points[count($points) - 1];
		$path = sprintf('M %.2f %.2f', $first['x'], $chart->baseline());

		foreach ($points as $point) {
			$path .= sprintf(' L %.2f %.2f', $point['x'], $point['y']);
		}

		return $path . sprintf(' L %.2f %.2f Z', $last['x'], $chart->baseline());
	}

	/**
	 * The horizontal gridlines and their labels.
	 *
	 * @param float $max
	 *   The largest value.
	 * @param string $unit
	 *   The series unit.
	 *
	 * @return list<array<string, mixed>>
	 *   Each line's y coordinate and label.
	 */
	private function gridlines(float $max, string $unit): array
	{
		$chart = $this->chart();
		$lines = [];

		foreach ($chart->gridlines($max, 2) as $line) {
			$lines[] = [
				'y' => $line['y'],
				'label' => Format::inUnit($line['value'], $unit),
				'x1' => Chart::PADDING_LEFT,
				'x2' => $chart->width - Chart::PADDING_RIGHT,
			];
		}

		return $lines;
	}
}
