<?php

declare(strict_types=1);

namespace Drupal\strata\Verify;

use JsonSerializable;

/**
 * What one reindex rebuilt.
 *
 * Read to answer the question the operation exists for: is the index back, and does it describe the
 * same history the bucket holds. Skipped counts are the part to look at - an object the walk could
 * not use is a restore target that is not coming back, and reporting it as a success would be worse
 * than failing.
 *
 * @see Reindexer
 */
final class ReindexReport implements JsonSerializable
{
	/**
	 * Constructs a report.
	 *
	 * @param int $commits
	 *   Commit rows written.
	 * @param int $packs
	 *   Pack objects read for their frame directories.
	 * @param int $frames
	 *   Frame rows written.
	 * @param int $references
	 *   Reference counts attributed by reading the segments the refs reach.
	 * @param int $segments
	 *   Segment manifests read.
	 * @param int $skipped
	 *   Objects the walk could not use.
	 * @param list<string> $problems
	 *   One line per skipped object, naming the key and the reason.
	 * @param float $seconds
	 *   How long the rebuild took.
	 * @param int $placements
	 *   Object placements recorded, on a store spread across several buckets. Zero on a store with
	 *   one destination, where every object is in the only place it could be.
	 */
	public function __construct(
		public readonly int $commits = 0,
		public readonly int $packs = 0,
		public readonly int $frames = 0,
		public readonly int $references = 0,
		public readonly int $segments = 0,
		public readonly int $skipped = 0,
		public readonly array $problems = [],
		public readonly float $seconds = 0.0,
		public readonly int $placements = 0,
	) {}

	/**
	 * Whether every object the walk met was usable.
	 *
	 * @return bool
	 *   TRUE when nothing was skipped.
	 */
	public function isClean(): bool
	{
		return $this->skipped === 0;
	}

	/**
	 * A one-line summary for a log entry or a command's output.
	 *
	 * @return string
	 *   The summary.
	 */
	public function summary(): string
	{
		$summary = sprintf(
			'reindexed %d commits, %d frames from %d packs, %d references in %.2fs',
			$this->commits,
			$this->frames,
			$this->packs,
			$this->references,
			$this->seconds,
		);

		if ($this->placements > 0) {
			$summary .= sprintf(', and placed %d objects across their tiers', $this->placements);
		}

		return $this->isClean()
			? $summary
			: sprintf('%s, skipping %d unusable objects', $summary, $this->skipped);
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
			'commits' => $this->commits,
			'packs' => $this->packs,
			'frames' => $this->frames,
			'references' => $this->references,
			'segments' => $this->segments,
			'skipped' => $this->skipped,
			'placements' => $this->placements,
			'problems' => $this->problems,
			'seconds' => round($this->seconds, 4),
			'clean' => $this->isClean(),
		];
	}
}
