<?php

declare(strict_types=1);

namespace Drupal\strata\Anomaly;

use Drupal\strata\Event\Notifier;
use Drupal\strata\Health\HealthLedgerInterface;
use Psr\Log\LoggerInterface;

/**
 * Scores the newest reading of each measurement against the site's own earlier readings.
 *
 * **A threshold that is right for one site is wrong for another**, so nothing here is compared
 * against an absolute number. A site doing four operations an hour and a site doing four hundred
 * thousand both have a normal, and the only useful question is whether today looks like their own
 * yesterday. Every judgement is therefore relative to the series in `MetricSampler`'s window.
 *
 * Findings go through the same ledger as every tripwire, so an anomaly appears on the health
 * dashboard beside a missing frame and inherits the repair ladder, the circuit breaker and the
 * notification channel rather than getting a parallel set of its own. The rung stays at `observe`:
 * an unusual number is a reason to look, never a reason to repair.
 *
 * **Two measurements are directional and the rest are not.** A compression ratio and a
 * deduplication ratio going UP is the pipeline working better, so only a fall is reported. An
 * operation rate, a stored-byte total and a chain depth are reported in both directions - a site
 * that suddenly stops capturing is as interesting as one that suddenly captures far more, and it is
 * the failure mode a one-sided detector would miss entirely.
 *
 * @see Series
 * @see MetricSampler
 * @see Anomaly
 */
final class AnomalyDetector
{
	/**
	 * Deviations from the median before a departure is worth a warning.
	 */
	public const WARN_SIGMA = 4.0;

	/**
	 * Deviations from the median before a departure is worth an error.
	 */
	public const ERROR_SIGMA = 8.0;

	/**
	 * Measurements where only a fall is a symptom.
	 *
	 * @var list<string>
	 */
	public const FALL_ONLY = ['dedup_ratio', 'compression_ratio'];

	/**
	 * Constructs a detector.
	 *
	 * @param MetricSampler $sampler
	 *   Takes the reading and holds the window.
	 * @param HealthLedgerInterface $ledger
	 *   Where an anomaly is recorded.
	 * @param LoggerInterface $logger
	 *   Records what was found.
	 * @param Notifier|null $notifier
	 *   Announces each anomaly, or NULL to stay silent.
	 * @param float $warnAt
	 *   Deviations before a warning, so a site can widen or tighten the band.
	 */
	public function __construct(
		private readonly MetricSampler $sampler,
		private readonly HealthLedgerInterface $ledger,
		private readonly LoggerInterface $logger,
		private readonly ?Notifier $notifier = null,
		private readonly float $warnAt = self::WARN_SIGMA,
	) {}

	/**
	 * Takes a reading and reports what departed from the window before it.
	 *
	 * @param bool $record
	 *   FALSE to score without writing findings, which is what the dashboard's preview does.
	 *
	 * @return list<Anomaly>
	 *   What departed, worst first.
	 */
	public function run(bool $record = true): array
	{
		$this->sampler->sample();

		$anomalies = $this->current();

		if ($record) {
			foreach ($anomalies as $anomaly) {
				$this->report($anomaly);
			}
		}

		return $anomalies;
	}

	/**
	 * Scores the window as it stands, without taking a reading or writing anything.
	 *
	 * What a dashboard calls. `run()` samples first, so rendering a page through it would append a
	 * reading on every page load and move the baseline every time somebody looked at it.
	 *
	 * @return list<Anomaly>
	 *   What departed, worst first.
	 */
	public function current(): array
	{
		$anomalies = [];

		foreach (array_keys(MetricSampler::METRICS) as $metric) {
			$anomaly = $this->score($metric);

			if ($anomaly !== null) {
				$anomalies[] = $anomaly;
			}
		}

		usort(
			$anomalies,
			static fn(Anomaly $a, Anomaly $b): int => abs($b->deviations) <=> abs($a->deviations),
		);

		return $anomalies;
	}

	/**
	 * Scores one measurement's newest reading.
	 *
	 * @param string $metric
	 *   A key of `MetricSampler::METRICS`.
	 *
	 * @return Anomaly|null
	 *   The anomaly, or NULL when the series is too short, the reading is ordinary, or the
	 *   measurement moved in the direction that is not a symptom.
	 */
	public function score(string $metric): ?Anomaly
	{
		$series = $this->sampler->series($metric);
		$observed = $series->latest();

		if ($observed === null) {
			return null;
		}

		$history = $series->history();

		if (!$history->isEstablished()) {
			return null;
		}

		$deviations = $history->score($observed);

		if (abs($deviations) < $this->warnAt) {
			return null;
		}
		if ($deviations > 0.0 && in_array($metric, self::FALL_ONLY, true)) {
			return null;
		}

		$definition = MetricSampler::METRICS[$metric] ?? ['label' => $metric, 'unit' => ''];

		return new Anomaly(
			$metric,
			$definition['label'],
			$observed,
			$history->median(),
			$deviations,
			$history->count(),
			$definition['unit'],
		);
	}

	/**
	 * The window as a series per measurement, for a graph.
	 *
	 * @return array<string, Series>
	 *   Metric key to series.
	 */
	public function series(): array
	{
		$series = [];

		foreach (array_keys(MetricSampler::METRICS) as $metric) {
			$series[$metric] = $this->sampler->series($metric);
		}

		return $series;
	}

	/**
	 * Records one anomaly.
	 *
	 * @param Anomaly $anomaly
	 *   What was found.
	 */
	private function report(Anomaly $anomaly): void
	{
		$finding = $anomaly->toFinding();

		$this->ledger->record($finding);
		$this->notifier?->findingRecorded($finding, $this->ledger->rungFor($finding->code));

		$this->logger->notice('Strata anomaly: @detail', ['@detail' => $anomaly->describe()]);
	}
}
