<?php

declare(strict_types=1);

namespace Drupal\strata\Telemetry;

use JsonSerializable;

/**
 * One timed operation, in the shape OpenTelemetry transports.
 *
 * **Wall-clock start and monotonic duration are recorded separately.** OTLP wants unix nanoseconds
 * for both ends of a span, but a wall clock can step - ntp, a leap second, a container resuming -
 * and a span whose end is stamped from a stepped clock reports a negative duration. So the start is
 * wall-clock, because a collector has to line the span up against everything else, and the duration
 * comes from `hrtime()`, which only ever moves forward. The end is start plus duration.
 *
 * Ids are random rather than derived. A trace id derived from the operation would collide across
 * two flushes of the same shape, and a collector would splice unrelated work into one trace.
 *
 * @see Tracer
 */
final class Span implements JsonSerializable
{
	/**
	 * A span that finished as expected.
	 */
	public const OK = 1;

	/**
	 * A span that finished because something failed.
	 */
	public const ERROR = 2;

	/**
	 * Nanoseconds in a second.
	 */
	public const NANOS_PER_SECOND = 1_000_000_000;

	/**
	 * Constructs a span.
	 *
	 * @param string $name
	 *   What was timed, such as "strata.flush".
	 * @param string $traceId
	 *   Thirty-two hex characters.
	 * @param string $spanId
	 *   Sixteen hex characters.
	 * @param int $startNanos
	 *   Unix nanoseconds the span opened at.
	 * @param int $durationNanos
	 *   How long it took, from a monotonic clock.
	 * @param array<string, string|int|float|bool> $attributes
	 *   What the operation was about.
	 * @param int $status
	 *   Either OK or ERROR.
	 * @param string $message
	 *   Why it failed, when it did.
	 * @param string|null $parentId
	 *   The enclosing span's id, or NULL for a root span.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $traceId,
		public readonly string $spanId,
		public readonly int $startNanos,
		public readonly int $durationNanos = 0,
		public readonly array $attributes = [],
		public readonly int $status = self::OK,
		public readonly string $message = '',
		public readonly ?string $parentId = null,
	) {}

	/**
	 * When the span closed.
	 *
	 * @return int
	 *   Unix nanoseconds.
	 */
	public function endNanos(): int
	{
		return $this->startNanos + max(0, $this->durationNanos);
	}

	/**
	 * How long the operation took.
	 *
	 * @return float
	 *   Seconds.
	 */
	public function seconds(): float
	{
		return $this->durationNanos / self::NANOS_PER_SECOND;
	}

	/**
	 * Whether the operation failed.
	 *
	 * @return bool
	 *   TRUE when the status is ERROR.
	 */
	public function isError(): bool
	{
		return $this->status === self::ERROR;
	}

	/**
	 * The same span, closed after a measured duration.
	 *
	 * @param int $durationNanos
	 *   How long it took.
	 * @param array<string, string|int|float|bool> $attributes
	 *   Attributes to merge in, which is where a result's counts are added.
	 * @param int $status
	 *   Either OK or ERROR.
	 * @param string $message
	 *   Why it failed, when it did.
	 *
	 * @return self
	 *   The closed span.
	 */
	public function close(
		int $durationNanos,
		array $attributes = [],
		int $status = self::OK,
		string $message = '',
	): self {
		return new self(
			$this->name,
			$this->traceId,
			$this->spanId,
			$this->startNanos,
			$durationNanos,
			$attributes + $this->attributes,
			$status,
			$message,
			$this->parentId,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function jsonSerialize(): array
	{
		return [
			'name' => $this->name,
			'trace_id' => $this->traceId,
			'span_id' => $this->spanId,
			'parent_id' => $this->parentId,
			'start_nanos' => $this->startNanos,
			'duration_nanos' => $this->durationNanos,
			'seconds' => round($this->seconds(), 6),
			'status' => $this->status,
			'message' => $this->message,
			'attributes' => $this->attributes,
		];
	}
}
