<?php

declare(strict_types=1);

namespace Drupal\strata\Tree;

use InvalidArgumentException;

/**
 * Decides when an anchor is due, and when it has to be a full one.
 *
 * Two intervals, and they trade against different things.
 *
 * The anchor interval bounds **replay depth**: a restore walks the segments between the target and
 * the anchor before it, so a four-hour interval on fifteen-second flushes means at most 960 segments.
 * It is a weak lever on stored bytes, and the reason is worth being exact about: a longer interval
 * means fewer anchors, but each one then covers a longer window and records proportionally more
 * changed subjects, so the two effects largely cancel. An operator tuning this is tuning restore
 * latency, not the bill.
 *
 * The full-anchor interval bounds **resolve cost**: reading an anchor means reading the chain back to
 * the last full one, so writing a full anchor every so often keeps that bounded. A full anchor costs
 * one entry per subject on the site rather than one per subject that changed, so it is the expensive
 * kind and it is written rarely.
 *
 * @see BaseManifest
 * @see BaseWriter
 */
final class BasePolicy
{
	/**
	 * Seconds between anchors.
	 *
	 * Four hours, chosen against the replay-depth column rather than a storage one.
	 */
	public const DEFAULT_INTERVAL = 14_400;

	/**
	 * Delta anchors written before a full one is due.
	 *
	 * Twenty-four, so a chain never spans more than four days at the default interval and a resolve
	 * reads at most twenty-five objects.
	 */
	public const DEFAULT_FULL_EVERY = 24;

	/**
	 * Constructs a policy.
	 *
	 * @param int $interval
	 *   Seconds between anchors. Zero means an anchor is written on every flush, which is what a test
	 *   and an export want.
	 * @param int $fullEvery
	 *   Delta anchors before a full one. One means every anchor is full.
	 *
	 * @throws InvalidArgumentException
	 *   When the interval is negative or the full count is below one.
	 */
	public function __construct(
		private readonly int $interval = self::DEFAULT_INTERVAL,
		private readonly int $fullEvery = self::DEFAULT_FULL_EVERY,
	) {
		if ($interval < 0) {
			throw new InvalidArgumentException('An anchor interval cannot be negative');
		}
		if ($fullEvery < 1) {
			throw new InvalidArgumentException(
				'A full anchor must be written at least every other anchor',
			);
		}
	}

	/**
	 * The configured anchor interval.
	 *
	 * @return int
	 *   Seconds.
	 */
	public function interval(): int
	{
		return $this->interval;
	}

	/**
	 * How many delta anchors are written before a full one.
	 *
	 * @return int
	 *   The count.
	 */
	public function fullEvery(): int
	{
		return $this->fullEvery;
	}

	/**
	 * Whether an anchor is due.
	 *
	 * @param int|null $lastAnchor
	 *   Unix microseconds of the newest anchor, or NULL when there is none, in which case one is
	 *   always due: without an anchor a replay walks to the root of history.
	 * @param int $now
	 *   Unix microseconds to compare against.
	 *
	 * @return bool
	 *   TRUE when the next flush should write one.
	 */
	public function isDue(?int $lastAnchor, int $now): bool
	{
		if ($lastAnchor === null || $this->interval === 0) {
			return true;
		}

		return $now - $lastAnchor >= $this->interval * 1_000_000;
	}

	/**
	 * Whether the anchor now due has to be a full one.
	 *
	 * @param int $chainLength
	 *   How many anchors stand between the newest one and the full anchor behind it, counting the
	 *   full one. Zero when there is no anchor at all.
	 *
	 * @return bool
	 *   TRUE when the next anchor must list every subject.
	 */
	public function needsFull(int $chainLength): bool
	{
		return $chainLength < 1 || $chainLength >= $this->fullEvery;
	}

	/**
	 * Which bound made an anchor due, for the log line.
	 *
	 * @param int|null $lastAnchor
	 *   Unix microseconds of the newest anchor, or NULL when there is none.
	 * @param int $now
	 *   Unix microseconds.
	 * @param int $chainLength
	 *   The current chain length.
	 *
	 * @return string|null
	 *   The reason, or NULL when no anchor is due.
	 */
	public function reason(?int $lastAnchor, int $now, int $chainLength): ?string
	{
		if (!$this->isDue($lastAnchor, $now)) {
			return null;
		}
		if ($lastAnchor === null) {
			return 'no anchor exists yet';
		}
		if ($this->needsFull($chainLength)) {
			return sprintf('the anchor chain reached %d links', $chainLength);
		}

		return sprintf('%d seconds since the last anchor', intdiv($now - $lastAnchor, 1_000_000));
	}

	/**
	 * A policy from the module's settings.
	 *
	 * @param int|null $interval
	 *   The configured interval, or NULL for the default.
	 * @param int|null $fullEvery
	 *   The configured full-anchor count, or NULL for the default.
	 *
	 * @return BasePolicy
	 *   The policy.
	 */
	public static function fromSettings(?int $interval, ?int $fullEvery = null): self
	{
		return new self(
			$interval === null ? self::DEFAULT_INTERVAL : max(0, $interval),
			$fullEvery === null || $fullEvery < 1 ? self::DEFAULT_FULL_EVERY : $fullEvery,
		);
	}
}
