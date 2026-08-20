<?php

declare(strict_types=1);

namespace Drupal\strata\Journal;

use InvalidArgumentException;

/**
 * Decides when a window of captured operations should be sealed into a segment.
 *
 * The interval is a durability window, not a restore granularity. Operations inside a segment stay
 * individually addressable and individually replayable, so a rollback reaches any single operation
 * whatever the interval is. What the interval decides is how much is lost if the server dies before
 * a flush.
 *
 * It is also the dominant cost dial, and the cost is requests rather than bytes. Cloudflare R2
 * charges $4.50 per million Class A operations, and the segment count per month depends only on the
 * interval, not on the site's size: one second costs 3,024,518 writes a month and is over the free
 * tier on its own, while fifteen seconds costs 201,635 and is a fifth of it.
 *
 * Three conditions therefore fire a flush, whichever comes first. Age bounds the loss window. Bytes
 * bound the memory a request holds. Operations bound the work one flush does, so a burst does not
 * produce a segment too large to seal inside a cron run.
 *
 * @see JournalInterface
 */
final class FlushPolicy
{
	/**
	 * Seconds before a window is sealed.
	 */
	public const DEFAULT_MAX_AGE = 15;

	/**
	 * Buffered payload bytes before a window is sealed.
	 */
	public const DEFAULT_MAX_BYTES = 4_194_304;

	/**
	 * Buffered operations before a window is sealed.
	 */
	public const DEFAULT_MAX_OPS = 5_000;

	/**
	 * Constructs a policy.
	 *
	 * @param int $maxAge
	 *   Seconds before a flush. Zero means age never triggers one.
	 * @param int $maxBytes
	 *   Buffered payload bytes before a flush. Zero means size never triggers one.
	 * @param int $maxOps
	 *   Buffered operations before a flush. Zero means count never triggers one.
	 *
	 * @throws InvalidArgumentException
	 *   When any bound is negative, or when all three are zero, which would mean a window is never
	 *   sealed and the journal grows without limit.
	 */
	public function __construct(
		private readonly int $maxAge = self::DEFAULT_MAX_AGE,
		private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
		private readonly int $maxOps = self::DEFAULT_MAX_OPS,
	) {
		if ($maxAge < 0 || $maxBytes < 0 || $maxOps < 0) {
			throw new InvalidArgumentException('A flush bound cannot be negative');
		}
		if ($maxAge === 0 && $maxBytes === 0 && $maxOps === 0) {
			throw new InvalidArgumentException(
				'At least one flush bound must be set, or a window is never sealed',
			);
		}
	}

	/**
	 * The configured age bound.
	 *
	 * @return int
	 *   Seconds, or 0 when age never triggers a flush.
	 */
	public function maxAge(): int
	{
		return $this->maxAge;
	}

	/**
	 * The configured size bound.
	 *
	 * @return int
	 *   Bytes, or 0 when size never triggers a flush.
	 */
	public function maxBytes(): int
	{
		return $this->maxBytes;
	}

	/**
	 * The configured count bound.
	 *
	 * @return int
	 *   Operations, or 0 when count never triggers a flush.
	 */
	public function maxOps(): int
	{
		return $this->maxOps;
	}

	/**
	 * Whether a window should be sealed now.
	 *
	 * @param int $pending
	 *   Operations waiting.
	 * @param int $bytes
	 *   Payload bytes waiting.
	 * @param int|null $oldest
	 *   Unix microseconds of the oldest waiting operation, or NULL when nothing is waiting.
	 * @param int $now
	 *   Unix microseconds to compare against.
	 *
	 * @return bool
	 *   TRUE when at least one bound has been reached.
	 */
	public function shouldFlush(int $pending, int $bytes, ?int $oldest, int $now): bool
	{
		return $this->reason($pending, $bytes, $oldest, $now) !== null;
	}

	/**
	 * Which bound fired, for the log line and the timeline.
	 *
	 * Age is checked first, then count, then size, so the reason reported is the one an operator is
	 * most likely to act on.
	 *
	 * @param int $pending
	 *   Operations waiting.
	 * @param int $bytes
	 *   Payload bytes waiting.
	 * @param int|null $oldest
	 *   Unix microseconds of the oldest waiting operation, or NULL when nothing is waiting.
	 * @param int $now
	 *   Unix microseconds to compare against.
	 *
	 * @return string|null
	 *   One of "age", "operations" or "bytes", or NULL when no bound has been reached.
	 */
	public function reason(int $pending, int $bytes, ?int $oldest, int $now): ?string
	{
		if ($pending < 1) {
			return null;
		}

		if ($this->maxAge > 0 && $oldest !== null) {
			$age = ($now - $oldest) / JournalOp::MICROSECONDS_PER_SECOND;

			if ($age >= $this->maxAge) {
				return 'age';
			}
		}
		if ($this->maxOps > 0 && $pending >= $this->maxOps) {
			return 'operations';
		}
		if ($this->maxBytes > 0 && $bytes >= $this->maxBytes) {
			return 'bytes';
		}

		return null;
	}

	/**
	 * How long until the age bound fires, in seconds.
	 *
	 * Surfaced so the UI can show the recovery-point lag rather than only reporting a flush after it
	 * has happened.
	 *
	 * @param int|null $oldest
	 *   Unix microseconds of the oldest waiting operation, or NULL when nothing is waiting.
	 * @param int $now
	 *   Unix microseconds to compare against.
	 *
	 * @return float
	 *   Seconds remaining, 0.0 when the bound has passed, or INF when age never triggers a flush.
	 */
	public function secondsUntilAge(?int $oldest, int $now): float
	{
		if ($this->maxAge === 0) {
			return INF;
		}
		if ($oldest === null) {
			return (float) $this->maxAge;
		}

		$age = ($now - $oldest) / JournalOp::MICROSECONDS_PER_SECOND;

		return max(0.0, $this->maxAge - $age);
	}

	/**
	 * Segment writes per month at this interval.
	 *
	 * The figure the settings form shows next to the interval, because it is what the request bill
	 * is proportional to and it does not depend on the site's size.
	 *
	 * @return float
	 *   Segments per 30.44-day month, or 0.0 when age never triggers a flush.
	 */
	public function segmentsPerMonth(): float
	{
		if ($this->maxAge === 0) {
			return 0.0;
		}

		return (86_400 / $this->maxAge) * 30.44;
	}
}
