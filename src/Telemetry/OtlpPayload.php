<?php

declare(strict_types=1);

namespace Drupal\strata\Telemetry;

/**
 * Renders spans and metrics as OTLP over HTTP with a JSON body.
 *
 * A pure transform with no client and no configuration beyond the resource attributes, so the wire
 * shape is unit-testable without a collector. The protobuf encoding is the other OTLP transport and
 * would need an extension or a generated stub library; the JSON one is accepted by every collector
 * this would be pointed at and costs nothing to ship.
 *
 * **Ids travel as hex, not as bytes.** OTLP/JSON specifies base16 for `traceId` and `spanId`, unlike
 * the protobuf encoding which carries raw bytes. A collector fed base64 there accepts the request
 * and then silently indexes the trace under the wrong id.
 *
 * **Integers travel as strings.** Unix nanoseconds exceed what a JSON number can hold exactly in a
 * consumer using doubles, so the specification requires `fixed64` fields to be quoted. Sending them
 * unquoted loses the low digits of every timestamp, which reorders spans inside a trace.
 *
 * @see Span
 * @see MetricSet
 * @see OtlpExporter
 */
final class OtlpPayload
{
	/**
	 * The path a collector accepts spans on.
	 */
	public const TRACES_PATH = '/v1/traces';

	/**
	 * The path a collector accepts metrics on.
	 */
	public const METRICS_PATH = '/v1/metrics';

	/**
	 * The instrumentation scope every span and metric is attributed to.
	 */
	public const SCOPE = 'strata';

	/**
	 * Constructs a payload renderer.
	 *
	 * @param string $serviceName
	 *   What the collector files this process under.
	 * @param string $version
	 *   The module version, so a graph can be split across a deploy.
	 * @param array<string, string> $resource
	 *   Further resource attributes, such as the site id and the deployment environment.
	 */
	public function __construct(
		private readonly string $serviceName = 'strata',
		private readonly string $version = '',
		private readonly array $resource = [],
	) {}

	/**
	 * Renders spans.
	 *
	 * @param list<Span> $spans
	 *   The spans.
	 *
	 * @return array<string, mixed>
	 *   An `ExportTraceServiceRequest` body.
	 */
	public function traces(array $spans): array
	{
		$rendered = [];

		foreach ($spans as $span) {
			$entry = [
				'traceId' => $span->traceId,
				'spanId' => $span->spanId,
				'name' => $span->name,
				'kind' => 1,
				'startTimeUnixNano' => (string) $span->startNanos,
				'endTimeUnixNano' => (string) $span->endNanos(),
				'attributes' => $this->attributes($span->attributes),
				'status' => $span->isError()
					? ['code' => 2, 'message' => $span->message]
					: ['code' => 1],
			];

			if ($span->parentId !== null) {
				$entry['parentSpanId'] = $span->parentId;
			}

			$rendered[] = $entry;
		}

		return [
			'resourceSpans' => [
				[
					'resource' => ['attributes' => $this->attributes($this->resourceAttributes())],
					'scopeSpans' => [['scope' => $this->scope(), 'spans' => $rendered]],
				],
			],
		];
	}

	/**
	 * Renders metrics.
	 *
	 * @param MetricSet $metrics
	 *   The metrics.
	 * @param int $nanos
	 *   Unix nanoseconds the readings were taken at.
	 *
	 * @return array<string, mixed>
	 *   An `ExportMetricsServiceRequest` body.
	 */
	public function metrics(MetricSet $metrics, int $nanos): array
	{
		$rendered = [];

		// the array key carries the dimensions too, so the wire name comes from the entry itself
		foreach ($metrics->all() as $metric) {
			$point = [
				'timeUnixNano' => (string) $nanos,
				'asDouble' => $metric['value'],
				'attributes' => $this->attributes($metric['attributes']),
			];

			$rendered[] = [
				'name' => $metric['name'],
				'unit' => $metric['unit'],
				$metric['kind'] === MetricSet::COUNTER ? 'sum' : 'gauge' =>
					$metric['kind'] === MetricSet::COUNTER
						? [
							'dataPoints' => [$point + ['startTimeUnixNano' => (string) $nanos]],
							'aggregationTemporality' => 2,
							'isMonotonic' => true,
						]
						: ['dataPoints' => [$point]],
			];
		}

		return [
			'resourceMetrics' => [
				[
					'resource' => ['attributes' => $this->attributes($this->resourceAttributes())],
					'scopeMetrics' => [['scope' => $this->scope(), 'metrics' => $rendered]],
				],
			],
		];
	}

	/**
	 * The resource every span and metric is attributed to.
	 *
	 * @return array<string, string>
	 *   Attribute name keyed to value.
	 */
	public function resourceAttributes(): array
	{
		$attributes = ['service.name' => $this->serviceName] + $this->resource;

		if ($this->version !== '') {
			$attributes['service.version'] = $this->version;
		}

		return $attributes;
	}

	/**
	 * The instrumentation scope.
	 *
	 * @return array<string, string>
	 *   The scope name and version.
	 */
	private function scope(): array
	{
		$scope = ['name' => self::SCOPE];

		if ($this->version !== '') {
			$scope['version'] = $this->version;
		}

		return $scope;
	}

	/**
	 * Renders attributes as OTLP key-value pairs.
	 *
	 * @param array<string, string|int|float|bool> $attributes
	 *   Name keyed to value.
	 *
	 * @return list<array<string, mixed>>
	 *   The pairs, each with the value typed as OTLP requires.
	 */
	private function attributes(array $attributes): array
	{
		$rendered = [];

		foreach ($attributes as $name => $value) {
			$rendered[] = [
				'key' => (string) $name,
				'value' => match (true) {
					is_bool($value) => ['boolValue' => $value],
					is_int($value) => ['intValue' => (string) $value],
					is_float($value) => ['doubleValue' => $value],
					default => ['stringValue' => (string) $value],
				},
			];
		}

		return $rendered;
	}
}
