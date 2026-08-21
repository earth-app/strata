<?php

declare(strict_types=1);

namespace Drupal\strata\Timeline;

use InvalidArgumentException;
use JsonSerializable;

/**
 * A span of time and the resolution it is drawn at.
 *
 * **The resolution is derived from the span, never chosen by the caller.** A timeline is pannable and
 * zoomable down to the second, so the same code has to draw a ten-second window and a two-year one.
 * Letting the caller pick a bucket size means a zoomed-out view asking for per-second buckets and
 * building sixty million of them, which is a page that never renders. Instead the window picks the
 * finest rung of the ladder that keeps the bucket count under the cap.
 *
 * Buckets are aligned to the resolution rather than to the window's start, so panning by half a
 * bucket does not shift every boundary and make the same commits appear to move between bars. A
 * reader panning a chart expects the bars to stay put.
 *
 * Everything is in unix microseconds, because that is the precision a commit is stamped with and
 * rounding to seconds here would collapse two commits from the same flush into one point.
 *
 * @see TimelineQuery
 * @see TimelineBucket
 */
final class TimelineWindow implements JsonSerializable
{
	/**
	 * Microseconds in a second.
	 */
	public const MICROS_PER_SECOND = 1_000_000;

	/**
	 * Bucket widths in seconds, finest first.
	 *
	 * A second at the bottom because a flush interval of fifteen seconds means per-second detail is
	 * the finest that shows anything; a week at the top because a year drawn in weeks is 52 bars.
	 */
	public const LADDER = [1, 5, 15, 60, 300, 900, 3_600, 21_600, 86_400, 604_800];

	/**
	 * Buckets one window may hold.
	 *
	 * A chart wider than this has bars under a pixel, so the extra buckets cost work and show
	 * nothing.
	 */
	public const MAX_BUCKETS = 480;

	/**
	 * Constructs a window.
	 *
	 * @param int $fromMicrotime
	 *   Inclusive start, in unix microseconds.
	 * @param int $toMicrotime
	 *   Inclusive end, in unix microseconds.
	 * @param int $resolution
	 *   Bucket width in seconds.
	 *
	 * @throws InvalidArgumentException
	 *   When the window ends before it starts, or the resolution is not positive.
	 */
	public function __construct(
		public readonly int $fromMicrotime,
		public readonly int $toMicrotime,
		public readonly int $resolution,
	) {
		if ($toMicrotime < $fromMicrotime) {
			throw new InvalidArgumentException('A timeline window cannot end before it starts');
		}
		if ($resolution < 1) {
			throw new InvalidArgumentException(
				'A timeline bucket must be at least one second wide',
			);
		}
	}

	/**
	 * A window over a span, at the finest resolution that fits.
	 *
	 * @param int $fromMicrotime
	 *   Inclusive start, in unix microseconds.
	 * @param int $toMicrotime
	 *   Inclusive end, in unix microseconds.
	 * @param int $maxBuckets
	 *   Buckets to stay under.
	 *
	 * @return self
	 *   The window.
	 */
	public static function spanning(
		int $fromMicrotime,
		int $toMicrotime,
		int $maxBuckets = self::MAX_BUCKETS,
	): self {
		$seconds = max(1, (int) ceil(($toMicrotime - $fromMicrotime) / self::MICROS_PER_SECOND));
		$cap = max(1, $maxBuckets);
		$resolution = self::LADDER[count(self::LADDER) - 1];

		foreach (self::LADDER as $rung) {
			if ((int) ceil($seconds / $rung) <= $cap) {
				$resolution = $rung;

				break;
			}
		}

		return new self($fromMicrotime, $toMicrotime, $resolution);
	}

	/**
	 * A window covering the last stretch of time.
	 *
	 * @param int $seconds
	 *   How far back to look.
	 * @param int $now
	 *   The unix second to end at.
	 * @param int $maxBuckets
	 *   Buckets to stay under.
	 *
	 * @return self
	 *   The window.
	 */
	public static function lastSeconds(
		int $seconds,
		int $now,
		int $maxBuckets = self::MAX_BUCKETS,
	): self {
		$end = $now * self::MICROS_PER_SECOND;

		return self::spanning($end - max(1, $seconds) * self::MICROS_PER_SECOND, $end, $maxBuckets);
	}

	/**
	 * How long the window covers.
	 *
	 * @return float
	 *   Seconds.
	 */
	public function seconds(): float
	{
		return ($this->toMicrotime - $this->fromMicrotime) / self::MICROS_PER_SECOND;
	}

	/**
	 * How many buckets the window holds.
	 *
	 * @return int
	 *   The count, at least one.
	 */
	public function bucketCount(): int
	{
		return count($this->boundaries());
	}

	/**
	 * The start of every bucket, in unix microseconds, oldest first.
	 *
	 * @return list<int>
	 *   Bucket starts.
	 */
	public function boundaries(): array
	{
		$width = $this->resolution * self::MICROS_PER_SECOND;
		$start = intdiv($this->fromMicrotime, $width) * $width;
		$boundaries = [];

		for ($at = $start; $at <= $this->toMicrotime; $at += $width) {
			$boundaries[] = $at;

			if (count($boundaries) >= self::MAX_BUCKETS * 2) {
				break;
			}
		}

		return $boundaries === [] ? [$start] : $boundaries;
	}

	/**
	 * Which bucket a moment falls in.
	 *
	 * @param int $microtime
	 *   Unix microseconds.
	 *
	 * @return int
	 *   The bucket's start, in unix microseconds.
	 */
	public function bucketFor(int $microtime): int
	{
		$width = $this->resolution * self::MICROS_PER_SECOND;

		return intdiv($microtime, $width) * $width;
	}

	/**
	 * A window over the same span, one rung finer.
	 *
	 * @return self
	 *   The zoomed window, or this one when already at the finest rung.
	 */
	public function finer(): self
	{
		$at = array_search($this->resolution, self::LADDER, true);

		if ($at === false || $at === 0) {
			return $this;
		}

		return new self($this->fromMicrotime, $this->toMicrotime, self::LADDER[$at - 1]);
	}

	/**
	 * A window over the same span, one rung coarser.
	 *
	 * @return self
	 *   The zoomed window, or this one when already at the coarsest rung.
	 */
	public function coarser(): self
	{
		$at = array_search($this->resolution, self::LADDER, true);

		if ($at === false || $at >= count(self::LADDER) - 1) {
			return $this;
		}

		return new self($this->fromMicrotime, $this->toMicrotime, self::LADDER[$at + 1]);
	}

	/**
	 * A window of the same width and resolution, moved along.
	 *
	 * @param float $fraction
	 *   How much of the window's width to move by; negative moves back in time.
	 *
	 * @return self
	 *   The panned window.
	 */
	public function pan(float $fraction): self
	{
		$by = (int) round(($this->toMicrotime - $this->fromMicrotime) * $fraction);

		return new self($this->fromMicrotime + $by, $this->toMicrotime + $by, $this->resolution);
	}

	/**
	 * The resolution, named for a reader.
	 *
	 * @return string
	 *   Something such as "15 seconds" or "6 hours".
	 */
	public function resolutionLabel(): string
	{
		return match (true) {
			$this->resolution < 60 => sprintf(
				'%d second%s',
				$this->resolution,
				$this->resolution === 1 ? '' : 's',
			),
			$this->resolution < 3_600 => sprintf('%d minutes', intdiv($this->resolution, 60)),
			$this->resolution < 86_400 => sprintf('%d hours', intdiv($this->resolution, 3_600)),
			$this->resolution < 604_800 => sprintf('%d days', intdiv($this->resolution, 86_400)),
			default => sprintf('%d weeks', intdiv($this->resolution, 604_800)),
		};
	}

	/**
	 * {@inheritdoc}
	 */
	public function jsonSerialize(): array
	{
		return [
			'from' => $this->fromMicrotime,
			'to' => $this->toMicrotime,
			'resolution' => $this->resolution,
			'resolution_label' => $this->resolutionLabel(),
			'buckets' => $this->bucketCount(),
			'seconds' => $this->seconds(),
		];
	}
}
