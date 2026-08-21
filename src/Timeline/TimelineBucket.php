<?php

declare(strict_types=1);

namespace Drupal\strata\Timeline;

use JsonSerializable;

/**
 * What happened inside one bucket of a timeline.
 *
 * Holds the commit ids as well as the totals, because a bucket is clickable: a reader who sees a
 * spike wants the commits that made it, and re-querying by time range would give a different set
 * from the one that was drawn if a flush landed in between.
 *
 * The id list is bounded. A bucket at the day resolution on a busy site covers thousands of commits,
 * and a chart holding all of them would spend more on the ids than on the numbers. Past the cap the
 * count still rises, so the bar's height stays honest and only the drilldown is partial.
 *
 * @see TimelineQuery
 */
final class TimelineBucket implements JsonSerializable
{
	/**
	 * Commit ids one bucket carries.
	 */
	public const MAX_IDS = 32;

	/**
	 * Constructs a bucket.
	 *
	 * @param int $startMicrotime
	 *   The bucket's start, in unix microseconds.
	 * @param int $endMicrotime
	 *   The bucket's end, in unix microseconds.
	 * @param int $commits
	 *   How many commits fell in it.
	 * @param int $operations
	 *   Operations those commits carried.
	 * @param int $rawBytes
	 *   Bytes before compression.
	 * @param int $storedBytes
	 *   Bytes actually written.
	 * @param int $anchors
	 *   How many of the commits were base anchors.
	 * @param list<string> $ids
	 *   Commit ids, up to MAX_IDS of them.
	 * @param int $restores
	 *   How many restores were run inside the bucket.
	 */
	public function __construct(
		public readonly int $startMicrotime,
		public readonly int $endMicrotime,
		public readonly int $commits = 0,
		public readonly int $operations = 0,
		public readonly int $rawBytes = 0,
		public readonly int $storedBytes = 0,
		public readonly int $anchors = 0,
		public readonly array $ids = [],
		public readonly int $restores = 0,
	) {}

	/**
	 * Whether nothing happened in this bucket.
	 *
	 * @return bool
	 *   TRUE when no commit fell in it.
	 */
	public function isEmpty(): bool
	{
		return $this->commits === 0;
	}

	/**
	 * The compression ratio across the bucket.
	 *
	 * @return float
	 *   Raw over stored, or 1.0 when nothing was stored.
	 */
	public function ratio(): float
	{
		return $this->storedBytes > 0 ? $this->rawBytes / $this->storedBytes : 1.0;
	}

	/**
	 * Operations a second inside the bucket.
	 *
	 * @return float
	 *   The rate.
	 */
	public function rate(): float
	{
		$seconds =
			($this->endMicrotime - $this->startMicrotime) / TimelineWindow::MICROS_PER_SECOND;

		return $seconds > 0.0 ? $this->operations / $seconds : 0.0;
	}

	/**
	 * The bucket's start as a unix second.
	 *
	 * @return int
	 *   Unix seconds.
	 */
	public function startSecond(): int
	{
		return intdiv($this->startMicrotime, TimelineWindow::MICROS_PER_SECOND);
	}

	/**
	 * Whether the drilldown list is complete.
	 *
	 * @return bool
	 *   FALSE when more commits fell in the bucket than the id list holds.
	 */
	public function hasEveryId(): bool
	{
		return count($this->ids) >= $this->commits;
	}

	/**
	 * {@inheritdoc}
	 */
	public function jsonSerialize(): array
	{
		return [
			'start' => $this->startMicrotime,
			'end' => $this->endMicrotime,
			'commits' => $this->commits,
			'operations' => $this->operations,
			'raw_bytes' => $this->rawBytes,
			'stored_bytes' => $this->storedBytes,
			'anchors' => $this->anchors,
			'restores' => $this->restores,
			'ratio' => round($this->ratio(), 3),
			'ids' => $this->ids,
			'complete' => $this->hasEveryId(),
		];
	}
}
