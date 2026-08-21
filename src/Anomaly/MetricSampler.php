<?php

declare(strict_types=1);

namespace Drupal\strata\Anomaly;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;
use Drupal\strata\Cas\FrameIndexInterface;
use Drupal\strata\Tree\CommitIndex;

/**
 * Records what the store looks like now, and keeps a bounded window of earlier readings.
 *
 * A detector needs a series and the store only offers a snapshot: the frame index knows how many
 * frames exist right now, not how many existed an hour ago. So the readings are taken on cron and
 * kept in state, which is the cheapest durable place for a fixed-size ring of numbers and does not
 * need a table of its own.
 *
 * **The window is capped by count, not by age.** A site whose cron stops running for a week should
 * come back and be judged against its last hundred readings rather than against nothing, and a site
 * running cron every minute should not accumulate a million rows.
 *
 * The op rate is a difference between readings rather than a running total, so it is the one value
 * that means nothing on the first sample. It is recorded as zero there and the detector skips it
 * until the series is established.
 *
 * @see AnomalyDetector
 * @see Series
 */
final class MetricSampler
{
	/**
	 * The state key the window lives under.
	 */
	public const STATE_KEY = 'strata.metrics';

	/**
	 * Readings kept.
	 */
	public const WINDOW = 128;

	/**
	 * Every metric this samples, keyed to its label and unit.
	 *
	 * @var array<string, array{label: string, unit: string}>
	 */
	public const METRICS = [
		'op_rate' => ['label' => 'Operation rate', 'unit' => 'per hour'],
		'stored_bytes' => ['label' => 'Stored bytes', 'unit' => 'bytes'],
		'dedup_ratio' => ['label' => 'Deduplication ratio', 'unit' => 'x'],
		'compression_ratio' => ['label' => 'Compression ratio', 'unit' => 'x'],
		'chain_depth' => ['label' => 'Deepest delta chain', 'unit' => 'links'],
	];

	/**
	 * Constructs a sampler.
	 *
	 * @param StateInterface $state
	 *   Where the window is kept.
	 * @param FrameIndexInterface $frames
	 *   Supplies the store totals.
	 * @param CommitIndex $commits
	 *   Supplies the operation counts.
	 * @param Connection $database
	 *   Reads the captured raw byte total the dedup ratio is measured against.
	 * @param TimeInterface $time
	 *   Stamps each reading.
	 */
	public function __construct(
		private readonly StateInterface $state,
		private readonly FrameIndexInterface $frames,
		private readonly CommitIndex $commits,
		private readonly Connection $database,
		private readonly TimeInterface $time,
	) {}

	/**
	 * Takes a reading and appends it to the window.
	 *
	 * @return array<string, float>
	 *   The reading, keyed as METRICS is, with an `at` entry holding the unix second.
	 */
	public function sample(): array
	{
		$window = $this->window();
		$previous = $window === [] ? null : $window[count($window) - 1];
		$now = $this->time->getRequestTime();
		$statistics = $this->frames->statistics();
		$captured = $this->capturedRawBytes();

		$reading = [
			'at' => (float) $now,
			'op_rate' => $this->operationRate($previous, $now),
			'stored_bytes' => (float) $statistics['storedBytes'],
			'dedup_ratio' =>
				$statistics['rawBytes'] > 0 ? $captured / $statistics['rawBytes'] : 1.0,
			'compression_ratio' => $statistics['ratio'],
			'chain_depth' => (float) $this->deepestChain(),
		];

		$window[] = $reading;

		if (count($window) > self::WINDOW) {
			$window = array_slice($window, -self::WINDOW);
		}

		$this->state->set(self::STATE_KEY, array_values($window));

		return $reading;
	}

	/**
	 * Every reading kept, oldest first.
	 *
	 * @return list<array<string, float>>
	 *   The window.
	 */
	public function window(): array
	{
		/** @var list<array<string, float>>|null $window */
		$window = $this->state->get(self::STATE_KEY);

		return is_array($window) ? array_values($window) : [];
	}

	/**
	 * One metric's readings as a series, oldest first.
	 *
	 * @param string $metric
	 *   A key of METRICS.
	 *
	 * @return Series
	 *   The series.
	 */
	public function series(string $metric): Series
	{
		$values = [];

		foreach ($this->window() as $reading) {
			if (isset($reading[$metric])) {
				$values[] = (float) $reading[$metric];
			}
		}

		return new Series($values);
	}

	/**
	 * Forgets every reading.
	 */
	public function clear(): void
	{
		$this->state->delete(self::STATE_KEY);
	}

	/**
	 * Operations an hour since the previous reading.
	 *
	 * @param array<string, float>|null $previous
	 *   The previous reading, or NULL when this is the first.
	 * @param int $now
	 *   The current unix second.
	 *
	 * @return float
	 *   Operations an hour, or 0.0 when there is nothing to difference against.
	 */
	private function operationRate(?array $previous, int $now): float
	{
		if ($previous === null) {
			return 0.0;
		}

		$since = (int) ($previous['at'] ?? 0);
		$elapsed = $now - $since;

		if ($elapsed < 1) {
			return 0.0;
		}

		$operations = 0;

		foreach (
			$this->commits->between($since * 1_000_000, $now * 1_000_000, self::WINDOW * 8)
			as $row
		) {
			$operations += (int) ($row['operations'] ?? 0);
		}

		return ($operations / $elapsed) * 3600.0;
	}

	/**
	 * Raw bytes the commits say were captured.
	 *
	 * Compared against the frame index's own raw total to give a deduplication ratio: the same value
	 * written twice is captured twice and framed once.
	 *
	 * @return float
	 *   Bytes.
	 */
	private function capturedRawBytes(): float
	{
		$query = $this->database->select(CommitIndex::TABLE, 'c');
		$query->addExpression('COALESCE(SUM([c].[raw_bytes]), 0)', 'raw');

		$row = $query->execute()?->fetch(FetchAs::Associative) ?: [];

		return (float) ($row['raw'] ?? 0);
	}

	/**
	 * The deepest delta chain in the store.
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
