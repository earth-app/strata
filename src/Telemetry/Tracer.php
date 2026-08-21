<?php

declare(strict_types=1);

namespace Drupal\strata\Telemetry;

use Throwable;

/**
 * Opens and closes spans, and holds them until something exports them.
 *
 * A tracer that posted each span as it closed would put an outbound HTTP call inside a flush, which
 * is the thing telemetry is supposed to be measuring. So spans accumulate in memory and are drained
 * by whoever is exporting; on a request that is the end of the request, and on cron it is the end of
 * the run.
 *
 * **The buffer is capped.** A long-running command that traced a million subjects would otherwise
 * spend more memory on the measurement than on the work. Past the cap, spans are counted and
 * dropped, and the count is exported as its own metric so the gap is visible rather than silent.
 *
 * @see Span
 * @see OtlpExporter
 */
final class Tracer
{
	/**
	 * Spans held before further ones are counted and dropped.
	 */
	public const MAX_SPANS = 2048;

	/**
	 * Closed spans waiting to be exported.
	 *
	 * @var list<Span>
	 */
	private array $spans = [];

	/**
	 * Ids of the spans currently open, innermost last.
	 *
	 * @var list<string>
	 */
	private array $open = [];

	/**
	 * How many spans were dropped because the buffer was full.
	 */
	private int $dropped = 0;

	/**
	 * The trace every span in this process belongs to.
	 */
	private ?string $traceId = null;

	/**
	 * Constructs a tracer.
	 *
	 * @param bool $enabled
	 *   FALSE to make every method a no-op, which is the default a site without a collector runs in.
	 */
	public function __construct(private readonly bool $enabled = true) {}

	/**
	 * Whether spans are being recorded.
	 *
	 * @return bool
	 *   TRUE when tracing is on.
	 */
	public function isEnabled(): bool
	{
		return $this->enabled;
	}

	/**
	 * Times a callable and records a span around it.
	 *
	 * An exception is recorded on the span and then rethrown. Telemetry never changes what the
	 * program does; a tracer that swallowed the failure it just measured would be worse than no
	 * tracer at all.
	 *
	 * @param string $name
	 *   What is being timed.
	 * @param callable $work
	 *   The work.
	 * @param array<string, string|int|float|bool> $attributes
	 *   What the operation is about.
	 *
	 * @return mixed
	 *   Whatever the callable returned.
	 *
	 * @throws Throwable
	 *   Whatever the callable threw.
	 */
	public function measure(string $name, callable $work, array $attributes = []): mixed
	{
		if (!$this->enabled) {
			return $work();
		}

		$span = $this->start($name, $attributes);
		$started = hrtime(true);

		try {
			$result = $work();
		} catch (Throwable $e) {
			$this->close($span, hrtime(true) - $started, [], Span::ERROR, $e->getMessage());

			throw $e;
		}

		$this->close($span, hrtime(true) - $started);

		return $result;
	}

	/**
	 * Opens a span.
	 *
	 * @param string $name
	 *   What is being timed.
	 * @param array<string, string|int|float|bool> $attributes
	 *   What the operation is about.
	 *
	 * @return Span
	 *   The open span, to be handed back to close().
	 */
	public function start(string $name, array $attributes = []): Span
	{
		$span = new Span(
			$name,
			($this->traceId ??= bin2hex(random_bytes(16))),
			bin2hex(random_bytes(8)),
			(int) (microtime(true) * Span::NANOS_PER_SECOND),
			0,
			$attributes,
			Span::OK,
			'',
			$this->open === [] ? null : $this->open[count($this->open) - 1],
		);

		$this->open[] = $span->spanId;

		return $span;
	}

	/**
	 * Closes a span and buffers it.
	 *
	 * @param Span $span
	 *   The span start() returned.
	 * @param int $durationNanos
	 *   How long it took, from `hrtime()`.
	 * @param array<string, string|int|float|bool> $attributes
	 *   Attributes to add now the result is known.
	 * @param int $status
	 *   Either Span::OK or Span::ERROR.
	 * @param string $message
	 *   Why it failed, when it did.
	 */
	public function close(
		Span $span,
		int $durationNanos,
		array $attributes = [],
		int $status = Span::OK,
		string $message = '',
	): void {
		$this->open = array_values(
			array_filter($this->open, static fn(string $id): bool => $id !== $span->spanId),
		);

		if (!$this->enabled) {
			return;
		}
		if (count($this->spans) >= self::MAX_SPANS) {
			$this->dropped++;

			return;
		}

		$this->spans[] = $span->close($durationNanos, $attributes, $status, $message);
	}

	/**
	 * The buffered spans.
	 *
	 * @return list<Span>
	 *   The spans, in the order they closed.
	 */
	public function spans(): array
	{
		return $this->spans;
	}

	/**
	 * How many spans were dropped because the buffer was full.
	 *
	 * @return int
	 *   The count.
	 */
	public function dropped(): int
	{
		return $this->dropped;
	}

	/**
	 * Takes the buffered spans and empties the buffer.
	 *
	 * @return list<Span>
	 *   The spans.
	 */
	public function drain(): array
	{
		$spans = $this->spans;
		$this->spans = [];

		return $spans;
	}
}
