<?php

declare(strict_types=1);

namespace Drupal\strata\Telemetry;

use GuzzleHttp\ClientInterface;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Posts spans and metrics to an OTLP endpoint.
 *
 * **A failed export is a warning, never an error, and is never retried.** Telemetry describes what
 * already happened; a collector that is down does not make the flush that just succeeded a failure,
 * and a retry queue for observability data would mean an unreachable collector slowly filling the
 * site's own queue table with duplicate readings of a moment that has passed.
 *
 * The endpoint is the collector's base URL and the signal paths are appended, which is what the
 * `OTEL_EXPORTER_OTLP_ENDPOINT` convention specifies. A site that configured the full traces URL
 * instead gets it used as-is, since appending a second `/v1/traces` would silently 404.
 *
 * @see OtlpPayload
 * @see Tracer
 */
final class OtlpExporter
{
	/**
	 * Seconds to wait for the collector.
	 *
	 * Short on purpose. The export runs at the end of a request or a cron run, and a collector that
	 * takes longer than this is one nothing should be waiting on.
	 */
	public const TIMEOUT = 5;

	/**
	 * Constructs an exporter.
	 *
	 * @param ClientInterface $http
	 *   The HTTP client.
	 * @param OtlpPayload $payload
	 *   Renders the wire shape.
	 * @param LoggerInterface $logger
	 *   Records an export that did not land.
	 * @param string $endpoint
	 *   The collector's base URL, or an empty string to export nothing.
	 * @param array<string, string> $headers
	 *   Headers every export carries, which is where an API key goes.
	 */
	public function __construct(
		private readonly ClientInterface $http,
		private readonly OtlpPayload $payload,
		private readonly LoggerInterface $logger,
		private readonly string $endpoint = '',
		private readonly array $headers = [],
	) {}

	/**
	 * Whether an endpoint is configured.
	 *
	 * @return bool
	 *   TRUE when exports will be attempted.
	 */
	public function isEnabled(): bool
	{
		return trim($this->endpoint) !== '';
	}

	/**
	 * Exports spans.
	 *
	 * @param list<Span> $spans
	 *   The spans.
	 *
	 * @return bool
	 *   TRUE when the collector accepted them, or there was nothing to send.
	 */
	public function exportSpans(array $spans): bool
	{
		if ($spans === []) {
			return true;
		}

		return $this->send(OtlpPayload::TRACES_PATH, $this->payload->traces($spans));
	}

	/**
	 * Exports metrics.
	 *
	 * @param MetricSet $metrics
	 *   The metrics.
	 * @param int|null $nanos
	 *   Unix nanoseconds to stamp them with, or NULL for now.
	 *
	 * @return bool
	 *   TRUE when the collector accepted them, or there was nothing to send.
	 */
	public function exportMetrics(MetricSet $metrics, ?int $nanos = null): bool
	{
		if ($metrics->isEmpty()) {
			return true;
		}

		return $this->send(
			OtlpPayload::METRICS_PATH,
			$this->payload->metrics(
				$metrics,
				$nanos ?? (int) (microtime(true) * Span::NANOS_PER_SECOND),
			),
		);
	}

	/**
	 * Drains a tracer and exports what it held.
	 *
	 * @param Tracer $tracer
	 *   The tracer.
	 *
	 * @return bool
	 *   TRUE when the collector accepted the spans, or there were none.
	 */
	public function drain(Tracer $tracer): bool
	{
		return $this->exportSpans($tracer->drain());
	}

	/**
	 * The URL one signal is posted to.
	 *
	 * @param string $path
	 *   The signal path.
	 *
	 * @return string
	 *   The URL.
	 */
	public function url(string $path): string
	{
		$endpoint = rtrim(trim($this->endpoint), '/');

		return str_ends_with($endpoint, $path) ? $endpoint : $endpoint . $path;
	}

	/**
	 * Posts one body.
	 *
	 * @param string $path
	 *   The signal path.
	 * @param array<string, mixed> $body
	 *   The rendered payload.
	 *
	 * @return bool
	 *   TRUE when the collector answered with a 2xx.
	 */
	private function send(string $path, array $body): bool
	{
		if (!$this->isEnabled()) {
			return true;
		}

		try {
			$encoded = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		} catch (JsonException $e) {
			$this->logger->warning('Strata could not encode a telemetry payload: @message', [
				'@message' => $e->getMessage(),
			]);

			return false;
		}

		try {
			$response = $this->http->request('POST', $this->url($path), [
				'headers' => ['Content-Type' => 'application/json'] + $this->headers,
				'body' => $encoded,
				'timeout' => self::TIMEOUT,
				'http_errors' => false,
			]);

			$status = $response->getStatusCode();

			if ($status >= 200 && $status < 300) {
				return true;
			}

			$this->logger->warning('Strata telemetry export to @path answered @status', [
				'@path' => $path,
				'@status' => $status,
			]);
		} catch (Throwable $e) {
			$this->logger->warning('Strata telemetry export to @path failed: @message', [
				'@path' => $path,
				'@message' => $e->getMessage(),
			]);
		}

		return false;
	}
}
