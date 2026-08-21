<?php

declare(strict_types=1);

namespace Drupal\strata\Telemetry;

use JsonSerializable;

/**
 * Named numbers with their units, ready to be exported.
 *
 * OTLP distinguishes a gauge from a counter and a collector treats them differently - a counter is
 * expected to only ever rise, and one that falls is read as a process restart rather than as a real
 * decrease. So the kind is recorded here rather than guessed at export time: stored bytes is a
 * gauge, requests performed is a counter, and getting that backwards produces a graph that is wrong
 * in a way nobody notices.
 *
 * @see OtlpPayload
 */
final class MetricSet implements JsonSerializable
{
	/**
	 * A value that can move in either direction.
	 */
	public const GAUGE = 'gauge';

	/**
	 * A value that only rises for the life of the process.
	 */
	public const COUNTER = 'counter';

	/**
	 * The metrics, keyed by name.
	 *
	 * @var array<string, array{value: float, kind: string, unit: string, attributes: array<string, string>}>
	 */
	private array $metrics = [];

	/**
	 * Records a gauge.
	 *
	 * @param string $name
	 *   The metric name, such as "strata.stored_bytes".
	 * @param float $value
	 *   The reading.
	 * @param string $unit
	 *   The UCUM unit, such as "By" for bytes or "s" for seconds.
	 * @param array<string, string> $attributes
	 *   Dimensions, such as the realm or the provider.
	 *
	 * @return self
	 *   This set, for chaining.
	 */
	public function gauge(
		string $name,
		float $value,
		string $unit = '',
		array $attributes = [],
	): self {
		return $this->set($name, $value, self::GAUGE, $unit, $attributes);
	}

	/**
	 * Records a counter.
	 *
	 * @param string $name
	 *   The metric name.
	 * @param float $value
	 *   The total so far.
	 * @param string $unit
	 *   The UCUM unit.
	 * @param array<string, string> $attributes
	 *   Dimensions.
	 *
	 * @return self
	 *   This set, for chaining.
	 */
	public function counter(
		string $name,
		float $value,
		string $unit = '',
		array $attributes = [],
	): self {
		return $this->set($name, $value, self::COUNTER, $unit, $attributes);
	}

	/**
	 * Every metric recorded.
	 *
	 * @return array<string, array{value: float, kind: string, unit: string, attributes: array<string, string>}>
	 *   Name keyed to the reading.
	 */
	public function all(): array
	{
		return $this->metrics;
	}

	/**
	 * One metric's value.
	 *
	 * @param string $name
	 *   The metric name.
	 *
	 * @return float|null
	 *   The value, or NULL when it was not recorded.
	 */
	public function get(string $name): ?float
	{
		return $this->metrics[$name]['value'] ?? null;
	}

	/**
	 * How many metrics are recorded.
	 *
	 * @return int
	 *   The count.
	 */
	public function count(): int
	{
		return count($this->metrics);
	}

	/**
	 * Whether nothing has been recorded.
	 *
	 * @return bool
	 *   TRUE when the set is empty.
	 */
	public function isEmpty(): bool
	{
		return $this->metrics === [];
	}

	/**
	 * {@inheritdoc}
	 */
	public function jsonSerialize(): array
	{
		return $this->metrics;
	}

	/**
	 * Records one metric.
	 *
	 * A name recorded twice keeps the later value, because the second reading is the more recent one
	 * and a set is a snapshot rather than a log.
	 *
	 * @param string $name
	 *   The metric name.
	 * @param float $value
	 *   The reading.
	 * @param string $kind
	 *   Either GAUGE or COUNTER.
	 * @param string $unit
	 *   The UCUM unit.
	 * @param array<string, string> $attributes
	 *   Dimensions.
	 *
	 * @return self
	 *   This set, for chaining.
	 */
	private function set(
		string $name,
		float $value,
		string $kind,
		string $unit,
		array $attributes,
	): self {
		$this->metrics[$name] = [
			'value' => $value,
			'kind' => $kind,
			'unit' => $unit,
			'attributes' => $attributes,
		];

		return $this;
	}
}
