<?php

declare(strict_types=1);

namespace Drupal\strata\Telemetry;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\strata\Cas\FrameIndexInterface;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\HealthLedgerInterface;
use Drupal\strata\Journal\JournalInterface;
use Drupal\strata\Storage\ProviderStats;
use Drupal\strata\Tree\CommitIndex;

/**
 * Takes one reading of everything worth graphing, and exports it.
 *
 * Every number here is already computed by something else; this class exists so there is one place
 * that decides which of them are metrics and what they are called. A collector's dashboards are
 * built against those names, so they are as much a public interface as a route is.
 *
 * **RPO lag is the one metric an operator should alert on.** It is the age of the newest sealed
 * commit, which is exactly how much work would be lost if the host died now. Every other metric
 * describes the shape of the history; this one describes the risk.
 *
 * A site with no history reports a lag of zero rather than the age of the epoch. A brand new install
 * has lost nothing, and a graph starting at fifty years of lag would be alerted on immediately.
 *
 * @see OtlpExporter
 * @see MetricSet
 */
final class TelemetryPass
{
	/**
	 * Constructs a pass.
	 *
	 * @param OtlpExporter $exporter
	 *   Where the readings go.
	 * @param CommitIndex $commits
	 *   Supplies the head and the operation counts.
	 * @param FrameIndexInterface $frames
	 *   Supplies the store totals.
	 * @param JournalInterface $journal
	 *   Supplies what is captured and not yet flushed.
	 * @param HealthLedgerInterface $ledger
	 *   Supplies open findings by severity.
	 * @param TimeInterface $time
	 *   Measures the lag.
	 * @param ProviderStats|null $stats
	 *   Request counts and latencies from this process, or NULL when the provider is not recording.
	 */
	public function __construct(
		private readonly OtlpExporter $exporter,
		private readonly CommitIndex $commits,
		private readonly FrameIndexInterface $frames,
		private readonly JournalInterface $journal,
		private readonly HealthLedgerInterface $ledger,
		private readonly TimeInterface $time,
		private readonly ?ProviderStats $stats = null,
	) {}

	/**
	 * Takes a reading and exports it.
	 *
	 * @param Tracer|null $tracer
	 *   A tracer to drain alongside the metrics, or NULL to export metrics only.
	 *
	 * @return MetricSet
	 *   What was read, whether or not the export landed.
	 */
	public function run(?Tracer $tracer = null): MetricSet
	{
		$metrics = $this->measure();

		$this->exporter->exportMetrics($metrics);

		if ($tracer !== null) {
			$this->exporter->drain($tracer);
		}

		return $metrics;
	}

	/**
	 * Takes a reading without exporting it.
	 *
	 * @return MetricSet
	 *   The metrics.
	 */
	public function measure(): MetricSet
	{
		$metrics = new MetricSet();
		$statistics = $this->frames->statistics();

		$metrics
			->gauge('strata.stored_bytes', (float) $statistics['storedBytes'], 'By')
			->gauge('strata.raw_bytes', (float) $statistics['rawBytes'], 'By')
			->gauge('strata.frames', (float) $statistics['frames'], '{frame}')
			->gauge('strata.orphan_frames', (float) $statistics['orphans'], '{frame}')
			->gauge('strata.compression_ratio', $statistics['ratio'], '1')
			->gauge('strata.commits', (float) $this->commits->count(), '{commit}')
			->gauge('strata.rpo_lag_seconds', $this->rpoLag(), 's')
			->gauge('strata.journal_pending', (float) $this->journal->pending(), '{operation}')
			->gauge('strata.journal_pending_bytes', (float) $this->journal->pendingBytes(), 'By');

		foreach ($this->findingsBySeverity() as $name => $count) {
			$metrics->gauge('strata.open_findings', (float) $count, '{finding}', [
				'severity' => $name,
			]);
		}

		$this->addProviderMetrics($metrics);

		return $metrics;
	}

	/**
	 * How much captured work would be lost if the host died now.
	 *
	 * @return float
	 *   Seconds since the newest commit was sealed, or 0.0 when there is no history.
	 */
	public function rpoLag(): float
	{
		$newest = $this->commits->newest();

		if ($newest === null) {
			return 0.0;
		}

		$sealed = (int) ($newest['microtime'] ?? 0) / 1_000_000;

		return max(0.0, $this->time->getRequestTime() - $sealed);
	}

	/**
	 * How many findings are open at each severity.
	 *
	 * Every severity is reported, including the ones at zero. A gauge that disappears when it reaches
	 * zero leaves a collector holding the last non-zero value, so a resolved error keeps alerting.
	 *
	 * @return array<string, int>
	 *   Severity name keyed to count.
	 */
	public function findingsBySeverity(): array
	{
		$counts = [];

		foreach (Finding::severities() as $name) {
			$counts[strtolower($name)] = 0;
		}

		foreach ($this->ledger->summary() as $row) {
			$name = strtolower(Finding::severities()[(int) $row['severity']] ?? 'unknown');
			$counts[$name] = ($counts[$name] ?? 0) + (int) $row['scopes'];
		}

		return $counts;
	}

	/**
	 * Adds what this process asked of the storage provider.
	 *
	 * @param MetricSet $metrics
	 *   The set to add to.
	 */
	private function addProviderMetrics(MetricSet $metrics): void
	{
		if ($this->stats === null || $this->stats->operations() === 0) {
			return;
		}

		$metrics
			->counter('strata.provider_requests', (float) $this->stats->classA(), '{request}', [
				'class' => 'A',
			])
			->counter('strata.provider_requests', (float) $this->stats->classB(), '{request}', [
				'class' => 'B',
			])
			->counter('strata.provider_bytes', (float) $this->stats->bytes(), 'By')
			->counter('strata.provider_failures', (float) $this->stats->failures(), '{request}');

		foreach (ProviderStats::OPERATIONS as $operation) {
			$latency = $this->stats->latency($operation);

			if ((int) ($latency['count'] ?? 0) === 0) {
				continue;
			}

			$metrics->gauge('strata.provider_latency_p95', (float) $latency['p95'], 's', [
				'operation' => $operation,
			]);
		}
	}
}
