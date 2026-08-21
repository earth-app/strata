<?php

declare(strict_types=1);

namespace Drupal\strata\Capture\Classifier;

use Drupal\strata\Health\Finding;
use Drupal\strata\Health\HealthLedgerInterface;
use Drupal\strata\Health\TripwireRegistry;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds out what is actually in the ephemeral keyspace, and how much of it there is.
 *
 * Classification cannot be decided from a list of what Drupal ships, because the keyspace on a real
 * site is mostly contributed: a module's own cache bin, a queue named after a feed, a lock nobody
 * documented. So the keyspace is discovered by asking whatever holds it, grouped into patterns, and
 * measured.
 *
 * **The measurement is the point.** A pattern covering three keys and a pattern covering two million
 * both need a decision, but only one of them is worth an administrator's attention, and only one of
 * them will dominate the storage bill if it turns out to be captured. So every pattern carries its
 * observed key count and byte total, and the admin UI sorts by the second.
 *
 * Where the keys come from is left to a source, because the answer differs per site: Redis when
 * `strata_redis` is installed, the cache and key-value tables otherwise. A source that fails is
 * reported and the pass continues over the others - one unreachable backend must not stop the rest
 * of the keyspace being described.
 *
 * @see ClassificationRegistry
 * @see KeyspaceSourceInterface
 */
final class KeyspaceDiscovery
{
	/**
	 * Code raised when unclassified keys grow past what was last decided.
	 */
	public const GROWTH = 'classification.unclassified_growth';

	/**
	 * Sources the keyspace is read from.
	 *
	 * @var list<KeyspaceSourceInterface>
	 */
	private array $sources = [];

	/**
	 * Constructs a discovery pass.
	 *
	 * @param ClassificationRegistry $registry
	 *   Where observations and decisions are stored.
	 * @param TripwireRegistry $tripwires
	 *   Runs the growth check over the result.
	 * @param HealthLedgerInterface $ledger
	 *   Where a growth finding is recorded.
	 * @param LoggerInterface $logger
	 *   Records what a pass found.
	 */
	public function __construct(
		private readonly ClassificationRegistry $registry,
		private readonly TripwireRegistry $tripwires,
		private readonly HealthLedgerInterface $ledger,
		private readonly LoggerInterface $logger,
	) {}

	/**
	 * Adds a source of keys.
	 *
	 * Collected from the container, so a submodule contributes one by tagging a service rather than
	 * by this class knowing what backends exist.
	 *
	 * @param KeyspaceSourceInterface $source
	 *   The source.
	 */
	public function addSource(KeyspaceSourceInterface $source): void
	{
		$this->sources[] = $source;
	}

	/**
	 * How many sources are registered.
	 *
	 * @return int
	 *   The count. Zero means nothing can be discovered, which a status page reports rather than
	 *   presenting an empty keyspace as a clean one.
	 */
	public function sourceCount(): int
	{
		return count($this->sources);
	}

	/**
	 * Walks every source and records what it holds.
	 *
	 * @param int $limit
	 *   Most keys to read from each source, so a pass over a large Redis stays bounded.
	 *
	 * @return DiscoveryReport
	 *   What was found.
	 */
	public function discover(int $limit = 10000): DiscoveryReport
	{
		$started = microtime(true);
		$patterns = [];
		$problems = [];
		$keys = 0;

		foreach ($this->sources as $source) {
			try {
				foreach ($source->keys($limit) as $key => $bytes) {
					$pattern = Heuristics::patternFor((string) $key);

					$patterns[$pattern] ??= ['keys' => 0, 'bytes' => 0];
					$patterns[$pattern]['keys']++;
					$patterns[$pattern]['bytes'] += max(0, (int) $bytes);
					$keys++;
				}
			} catch (Throwable $error) {
				$problems[] = sprintf('%s: %s', $source->id(), $error->getMessage());
			}
		}

		foreach ($patterns as $pattern => $totals) {
			$this->registry->observe(
				$pattern,
				Heuristics::classify($pattern),
				$totals['keys'],
				$totals['bytes'],
			);
		}

		$report = new DiscoveryReport(
			$this->sourceCount(),
			$keys,
			count($patterns),
			$this->registry->statistics(),
			$problems,
			microtime(true) - $started,
		);

		$this->raise($report);
		$this->logger->info('Strata %summary', ['%summary' => $report->summary()]);

		return $report;
	}

	/**
	 * Records a finding when too much of the keyspace is undecided.
	 *
	 * @param DiscoveryReport $report
	 *   What the pass found.
	 */
	private function raise(DiscoveryReport $report): void
	{
		$observation = [
			'unclassified_keys' => $report->keysAt(Classification::UNCLASSIFIED),
			'unclassified_bytes' => $report->bytesAt(Classification::UNCLASSIFIED),
			'unclassified_patterns' => $report->patternsAt(Classification::UNCLASSIFIED),
			'total_keys' => $report->keys,
		];

		foreach ($this->tripwires->evaluate($observation) as $finding) {
			$this->ledger->record($finding);
		}

		if ($report->problems === []) {
			return;
		}

		$this->ledger->record(
			new Finding(
				self::GROWTH,
				Finding::WARN,
				'sources',
				sprintf(
					'%d of %d keyspace sources could not be read: %s',
					count($report->problems),
					$report->sources,
					implode('; ', $report->problems),
				),
			),
		);
	}
}
