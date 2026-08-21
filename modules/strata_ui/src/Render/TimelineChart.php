<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Render;

use Drupal\Core\Url;
use Drupal\strata\Timeline\TimelineBucket;
use Drupal\strata\Timeline\TimelineWindow;

/**
 * Turns timeline buckets into the bars, links and labels a template draws.
 *
 * **Bars are scaled by operations and coloured by what else happened in the window.** An anchor and a
 * restore are both rare and both matter more than the height of the bar they sit in, so they are
 * marked rather than plotted on their own axis - a reader scanning for "when did someone roll back"
 * should not have to compare two charts by eye.
 *
 * Every bar carries a link to its own window. That is what makes the chart navigable without any
 * scripting at all: keyboard users tab through the bars and follow one, and the zoom and pan controls
 * are ordinary links to other windows. The JavaScript that ships with this only adds wheel and drag,
 * so a page with scripting off loses nothing but convenience.
 *
 * @see Chart
 * @see TimelineWindow
 */
final class TimelineChart
{
	/**
	 * Time labels drawn along the bottom.
	 *
	 * More than this and they overlap at the widths this chart is drawn at.
	 */
	public const LABELS = 6;

	/**
	 * Constructs a chart builder.
	 *
	 * @param Chart $chart
	 *   The plot area.
	 */
	public function __construct(private readonly Chart $chart = new Chart()) {}

	/**
	 * Everything a template needs to draw one timeline.
	 *
	 * @param TimelineWindow $window
	 *   The window drawn.
	 * @param list<TimelineBucket> $buckets
	 *   The buckets, oldest first.
	 *
	 * @return array<string, mixed>
	 *   The bars, gridlines, labels, navigation links and the totals.
	 */
	public function build(TimelineWindow $window, array $buckets): array
	{
		$max = $this->peak($buckets);

		return [
			'view_box' => $this->chart->viewBox(),
			'width' => $this->chart->width,
			'height' => $this->chart->height,
			'baseline' => $this->chart->baseline(),
			'plot_left' => Chart::PADDING_LEFT,
			'plot_right' => $this->chart->width - Chart::PADDING_RIGHT,
			'bars' => $this->bars($window, $buckets, $max),
			'gridlines' => $this->gridlines($max),
			'labels' => $this->labels($window, $buckets),
			'navigation' => $this->navigation($window),
			'max' => $max,
			'resolution' => $window->resolutionLabel(),
			'empty' => $max <= 0.0,
		];
	}

	/**
	 * One bar per bucket.
	 *
	 * @param TimelineWindow $window
	 *   The window drawn.
	 * @param list<TimelineBucket> $buckets
	 *   The buckets.
	 * @param float $max
	 *   The tallest value on the axis.
	 *
	 * @return list<array<string, mixed>>
	 *   Each bar's geometry, classes, title and link.
	 */
	private function bars(TimelineWindow $window, array $buckets, float $max): array
	{
		$columns = max(1, count($buckets));
		$bars = [];

		foreach ($buckets as $index => $bucket) {
			$value = (float) $bucket->operations;
			$classes = ['strata-timeline__bar'];

			if ($bucket->isEmpty()) {
				$classes[] = 'strata-timeline__bar--empty';
			}
			if ($bucket->anchors > 0) {
				$classes[] = 'strata-timeline__bar--anchor';
			}
			if ($bucket->restores > 0) {
				$classes[] = 'strata-timeline__bar--restore';
			}

			$bars[] = [
				'x' => $this->chart->x($index, $columns),
				'y' => $this->chart->y($value, $max),
				'width' => $this->chart->columnWidth($columns),
				'height' => $this->chart->barHeight($value, $max),
				'classes' => implode(' ', $classes),
				'title' => $this->describe($bucket),
				'url' => $bucket->isEmpty()
					? null
					: Url::fromRoute('strata_ui.bucket', [
						'start' => $bucket->startSecond(),
						'resolution' => $window->resolution,
					])->toString(),
			];
		}

		return $bars;
	}

	/**
	 * The horizontal gridlines and their labels.
	 *
	 * @param float $max
	 *   The tallest value on the axis.
	 *
	 * @return list<array<string, mixed>>
	 *   Each line's y coordinate and label.
	 */
	private function gridlines(float $max): array
	{
		$lines = [];

		foreach ($this->chart->gridlines($max) as $line) {
			$lines[] = [
				'y' => $line['y'],
				'label' => Format::count($line['value']),
				'x1' => Chart::PADDING_LEFT,
				'x2' => $this->chart->width - Chart::PADDING_RIGHT,
			];
		}

		return $lines;
	}

	/**
	 * The time labels along the bottom.
	 *
	 * @param TimelineWindow $window
	 *   The window drawn.
	 * @param list<TimelineBucket> $buckets
	 *   The buckets.
	 *
	 * @return list<array<string, mixed>>
	 *   Each label's x coordinate and text.
	 */
	private function labels(TimelineWindow $window, array $buckets): array
	{
		$columns = max(1, count($buckets));
		$every = max(1, (int) floor($columns / self::LABELS));
		$labels = [];

		foreach ($buckets as $index => $bucket) {
			if ($index % $every !== 0) {
				continue;
			}

			$labels[] = [
				'x' => $this->chart->x($index, $columns),
				'text' => $this->tick($bucket->startSecond(), $window->resolution),
			];
		}

		return $labels;
	}

	/**
	 * The zoom and pan links.
	 *
	 * @param TimelineWindow $window
	 *   The window drawn.
	 *
	 * @return array<string, array<string, string>>
	 *   Each control's label and URL.
	 */
	private function navigation(TimelineWindow $window): array
	{
		$controls = [
			'earlier' => ['label' => 'Earlier', 'window' => $window->pan(-0.5)],
			'finer' => ['label' => 'Zoom In', 'window' => $window->finer()],
			'coarser' => ['label' => 'Zoom Out', 'window' => $window->coarser()],
			'later' => ['label' => 'Later', 'window' => $window->pan(0.5)],
		];

		$links = [];

		foreach ($controls as $key => $control) {
			$links[$key] = [
				'label' => $control['label'],
				'url' => Url::fromRoute(
					'strata_ui.timeline',
					[],
					[
						'query' => [
							'from' => $control['window']->fromMicrotime,
							'to' => $control['window']->toMicrotime,
							'resolution' => $control['window']->resolution,
						],
					],
				)->toString(),
			];
		}

		return $links;
	}

	/**
	 * The tallest value any bar will be scaled against.
	 *
	 * @param list<TimelineBucket> $buckets
	 *   The buckets.
	 *
	 * @return float
	 *   The peak operation count.
	 */
	private function peak(array $buckets): float
	{
		$peak = 0.0;

		foreach ($buckets as $bucket) {
			$peak = max($peak, (float) $bucket->operations);
		}

		return $peak;
	}

	/**
	 * What one bucket's tooltip says.
	 *
	 * @param TimelineBucket $bucket
	 *   The bucket.
	 *
	 * @return string
	 *   A single line.
	 */
	private function describe(TimelineBucket $bucket): string
	{
		if ($bucket->isEmpty()) {
			return sprintf('%s: nothing changed', Format::moment($bucket->startMicrotime));
		}

		$parts = [
			sprintf('%s commits', Format::count($bucket->commits)),
			sprintf('%s operations', Format::count($bucket->operations)),
			Format::bytes($bucket->storedBytes),
			Format::ratio($bucket->ratio()),
		];

		if ($bucket->anchors > 0) {
			$parts[] = sprintf('%d anchors', $bucket->anchors);
		}
		if ($bucket->restores > 0) {
			$parts[] = sprintf('%d restores', $bucket->restores);
		}

		return sprintf('%s: %s', Format::moment($bucket->startMicrotime), implode(', ', $parts));
	}

	/**
	 * A time label at a resolution a reader can place.
	 *
	 * A per-second window wants a clock and a per-week window wants a date; showing both everywhere
	 * makes the axis unreadable at one end or ambiguous at the other.
	 *
	 * @param int $second
	 *   Unix seconds.
	 * @param int $resolution
	 *   The bucket width in seconds.
	 *
	 * @return string
	 *   The label.
	 */
	private function tick(int $second, int $resolution): string
	{
		return match (true) {
			$resolution < 60 => gmdate('H:i:s', $second),
			$resolution < 86_400 => gmdate('m-d H:i', $second),
			default => gmdate('Y-m-d', $second),
		};
	}
}
