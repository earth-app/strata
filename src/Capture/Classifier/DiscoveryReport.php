<?php

declare(strict_types=1);

namespace Drupal\strata\Capture\Classifier;

use JsonSerializable;

/**
 * What one keyspace discovery pass found.
 *
 * The number to read is how much sits under Classification::UNCLASSIFIED. Everything there is being
 * captured because nothing recognised it, which is the safe default and also the expensive one, so
 * it is the list an administrator works through.
 *
 * @see KeyspaceDiscovery
 */
final class DiscoveryReport implements JsonSerializable
{
	/**
	 * Constructs a report.
	 *
	 * @param int $sources
	 *   How many sources were asked.
	 * @param int $keys
	 *   Keys seen across all of them.
	 * @param int $patterns
	 *   Distinct patterns those keys fell into.
	 * @param array<string, array{patterns: int, keys: int, bytes: int}> $totals
	 *   Classification value keyed to its totals, as the registry reports them.
	 * @param list<string> $problems
	 *   One line per source that could not be read.
	 * @param float $seconds
	 *   How long the pass took.
	 */
	public function __construct(
		public readonly int $sources = 0,
		public readonly int $keys = 0,
		public readonly int $patterns = 0,
		public readonly array $totals = [],
		public readonly array $problems = [],
		public readonly float $seconds = 0.0,
	) {}

	/**
	 * Whether every source was read.
	 *
	 * @return bool
	 *   TRUE when nothing failed.
	 */
	public function isClean(): bool
	{
		return $this->problems === [];
	}

	/**
	 * Whether anything could be discovered at all.
	 *
	 * A pass with no sources is not a clean pass over an empty keyspace; it is a pass that could not
	 * look, and reporting the two the same way is how an unmonitored keyspace looks monitored.
	 *
	 * @return bool
	 *   TRUE when at least one source was registered.
	 */
	public function couldLook(): bool
	{
		return $this->sources > 0;
	}

	/**
	 * Patterns sitting at one classification.
	 *
	 * @param Classification $classification
	 *   The classification.
	 *
	 * @return int
	 *   The count.
	 */
	public function patternsAt(Classification $classification): int
	{
		return (int) ($this->totals[$classification->value]['patterns'] ?? 0);
	}

	/**
	 * Keys sitting at one classification.
	 *
	 * @param Classification $classification
	 *   The classification.
	 *
	 * @return int
	 *   The count.
	 */
	public function keysAt(Classification $classification): int
	{
		return (int) ($this->totals[$classification->value]['keys'] ?? 0);
	}

	/**
	 * Bytes sitting at one classification.
	 *
	 * @param Classification $classification
	 *   The classification.
	 *
	 * @return int
	 *   The byte total.
	 */
	public function bytesAt(Classification $classification): int
	{
		return (int) ($this->totals[$classification->value]['bytes'] ?? 0);
	}

	/**
	 * A one-line summary for a log entry or a command's output.
	 *
	 * @return string
	 *   The summary.
	 */
	public function summary(): string
	{
		if (!$this->couldLook()) {
			return 'no keyspace source is registered, so nothing could be discovered';
		}

		$summary = sprintf(
			'discovered %d keys in %d patterns from %d sources in %.2fs: %d undecided',
			$this->keys,
			$this->patterns,
			$this->sources,
			$this->seconds,
			$this->patternsAt(Classification::UNCLASSIFIED),
		);

		if (!$this->isClean()) {
			$summary .= sprintf(', %d sources unreadable', count($this->problems));
		}

		return $summary;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The report as a plain array.
	 */
	public function jsonSerialize(): array
	{
		return [
			'sources' => $this->sources,
			'keys' => $this->keys,
			'patterns' => $this->patterns,
			'totals' => $this->totals,
			'problems' => $this->problems,
			'seconds' => round($this->seconds, 4),
			'clean' => $this->isClean(),
		];
	}
}
