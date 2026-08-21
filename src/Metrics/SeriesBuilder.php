<?php

declare(strict_types=1);

namespace Drupal\strata\Metrics;

use Drupal\strata\Cas\FrameIndexInterface;
use Drupal\strata\Estimate\PriceTable;
use Drupal\strata\Timeline\TimelineBucket;
use Drupal\strata\Timeline\TimelineQuery;
use Drupal\strata\Timeline\TimelineWindow;

/**
 * Derives every graphed line from one pass over the timeline's buckets.
 *
 * The buckets are queried once and every series is computed from them, so the six charts on a page
 * cost one query rather than six, and no two of them can disagree about what happened in a bucket.
 *
 * **Stored size is cumulative and everything else is per-bucket.** Stored bytes only ever accumulate
 * until a prune runs, so drawing it per bucket would show a site's storage as a series of small
 * spikes rather than as the line that crosses a budget ceiling. Operation rate, ratios and cost are
 * rates and are drawn as such.
 *
 * The cumulative line starts from what the store holds NOW and is walked backwards, because the frame
 * index knows the current total exactly and the history of commits only knows what each one added. A
 * line built forwards from zero would be correct in shape and wrong at every point on any site whose
 * history has ever been pruned or that installed the module after it had content.
 *
 * **Two of the six lines are store-wide values drawn flat, and are labelled as such.** Deduplication
 * ratio and delta-chain depth are properties of the whole frame index, which records one number and
 * not a history of it: a frame written today may be referenced by a commit from last year, so there
 * is no bucket it belongs to. They are drawn at their real current value across the window rather
 * than apportioned per bucket, because an apportioned figure would be a plausible-looking number
 * with nothing behind it.
 *
 * @see MetricSeries
 * @see TimelineQuery
 */
final class SeriesBuilder
{
	/**
	 * Every series this builds, keyed to its label and unit.
	 *
	 * @var array<string, array{label: string, unit: string, cumulative: bool}>
	 */
	public const SERIES = [
		'stored_bytes' => ['label' => 'Stored Size', 'unit' => 'bytes', 'cumulative' => true],
		'operations' => [
			'label' => 'Operation Rate',
			'unit' => 'per second',
			'cumulative' => false,
		],
		'dedup_ratio' => ['label' => 'Deduplication Ratio', 'unit' => 'x', 'cumulative' => false],
		'compression_ratio' => [
			'label' => 'Compression Ratio',
			'unit' => 'x',
			'cumulative' => false,
		],
		'chain_depth' => ['label' => 'Delta Chain Depth', 'unit' => 'links', 'cumulative' => false],
		'cost' => ['label' => 'Monthly Cost', 'unit' => 'dollars', 'cumulative' => true],
	];

	/**
	 * Constructs a builder.
	 *
	 * @param TimelineQuery $timeline
	 *   Supplies the buckets.
	 * @param FrameIndexInterface $frames
	 *   Supplies what the store holds now, which the cumulative lines are anchored to.
	 * @param PriceTable $prices
	 *   Turns bytes into a monthly figure.
	 */
	public function __construct(
		private readonly TimelineQuery $timeline,
		private readonly FrameIndexInterface $frames,
		private readonly PriceTable $prices,
	) {}

	/**
	 * Every series over a window.
	 *
	 * @param TimelineWindow $window
	 *   The window.
	 *
	 * @return array<string, MetricSeries>
	 *   Series key to series.
	 */
	public function build(TimelineWindow $window): array
	{
		return $this->fromBuckets($this->timeline->buckets($window));
	}

	/**
	 * Every series from a set of buckets already queried.
	 *
	 * @param list<TimelineBucket> $buckets
	 *   The buckets, oldest first.
	 *
	 * @return array<string, MetricSeries>
	 *   Series key to series.
	 */
	public function fromBuckets(array $buckets): array
	{
		$points = [
			'stored_bytes' => $this->cumulativeStored($buckets),
			'operations' => [],
			'dedup_ratio' => [],
			'compression_ratio' => [],
			'chain_depth' => [],
			'cost' => [],
		];

		// both are whole-store readings, so they are repeated rather than apportioned per bucket
		$deepest = (float) $this->deepestChain();
		$dedup = $this->dedupRatio();

		foreach ($buckets as $bucket) {
			$points['operations'][] = $bucket->rate();
			$points['compression_ratio'][] = $bucket->ratio();
			$points['dedup_ratio'][] = $dedup;
			$points['chain_depth'][] = $deepest;
		}

		foreach ($points['stored_bytes'] as $stored) {
			$points['cost'][] = $this->prices->storageCost((int) $stored);
		}

		$series = [];

		foreach (self::SERIES as $key => $definition) {
			$series[$key] = new MetricSeries(
				$key,
				$definition['label'],
				$points[$key],
				$definition['unit'],
				$definition['cumulative'],
			);
		}

		return $series;
	}

	/**
	 * One named series over a window.
	 *
	 * @param string $key
	 *   A key of SERIES.
	 * @param TimelineWindow $window
	 *   The window.
	 *
	 * @return MetricSeries|null
	 *   The series, or NULL when the key is not one this builds.
	 */
	public function one(string $key, TimelineWindow $window): ?MetricSeries
	{
		return $this->build($window)[$key] ?? null;
	}

	/**
	 * Stored bytes at the end of each bucket, anchored to what the store holds now.
	 *
	 * @param list<TimelineBucket> $buckets
	 *   The buckets, oldest first.
	 *
	 * @return list<float>
	 *   One total per bucket.
	 */
	private function cumulativeStored(array $buckets): array
	{
		$now = (float) $this->frames->statistics()['storedBytes'];
		$totals = [];
		$running = $now;

		// walked backwards from the current total, then reversed, so the newest point is exact
		foreach (array_reverse($buckets) as $bucket) {
			$totals[] = $running;
			$running = max(0.0, $running - $bucket->storedBytes);
		}

		return array_reverse($totals);
	}

	/**
	 * How much repetition the content addressing removed, across the whole store.
	 *
	 * Bytes the commits say were captured, over the distinct decoded bytes the frame index holds. A
	 * value written ten times is captured ten times and framed once, so that is a ratio of ten.
	 *
	 * @return float
	 *   The ratio, or 1.0 when nothing has been stored.
	 */
	private function dedupRatio(): float
	{
		$distinct = (float) $this->frames->statistics()['rawBytes'];

		if ($distinct <= 0.0) {
			return 1.0;
		}

		return max(1.0, $this->timeline->capturedRawBytes() / $distinct);
	}

	/**
	 * The deepest delta chain the store holds.
	 *
	 * @return int
	 *   Links, or 0 when nothing is delta coded.
	 */
	private function deepestChain(): int
	{
		$deepest = $this->frames->deepestChains(1, 1);

		return $deepest === [] ? 0 : $deepest[0]->deltaDepth;
	}
}
