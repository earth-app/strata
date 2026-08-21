<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Telemetry;

use Drupal\strata\Telemetry\MetricSet;
use Drupal\strata\Telemetry\OtlpExporter;
use Drupal\strata\Telemetry\OtlpPayload;
use Drupal\strata\Telemetry\Span;
use Drupal\strata\Telemetry\Tracer;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Proves the telemetry pipeline from a timed call to the bytes a collector reads.
 *
 * The wire lane pins the two encodings the payload docblock names. OTLP/JSON wants base16 for the
 * ids and a quoted string for every `fixed64` field, and a collector fed the other encoding accepts
 * the request and files the data wrongly, so both are asserted against the decoded array.
 *
 * The tracer lane covers what telemetry is not allowed to change: an exception inside a measured
 * call is recorded on the span and rethrown, and a disabled tracer still returns the callable's
 * value. The exporter lane covers the same rule at the transport: a collector that is down produces
 * a warning and a FALSE, never an exception.
 */
#[CoversClass(Span::class)]
#[CoversClass(Tracer::class)]
#[CoversClass(MetricSet::class)]
#[CoversClass(OtlpPayload::class)]
#[CoversClass(OtlpExporter::class)]
class TelemetryTest extends TestCase
{
	#region Span

	#[Test]
	#[TestDox('endNanos() is the wall-clock start plus the monotonic duration')]
	#[Group('strata/telemetry')]
	public function endIsStartPlusDuration(): void
	{
		$span = new Span('strata.flush', str_repeat('a', 32), str_repeat('b', 16), 1_000, 2_500);

		$this->assertSame(3_500, $span->endNanos());
		$this->assertSame(1_000, $span->startNanos);
	}

	#[Test]
	#[TestDox('a negative duration cannot push the end before the start')]
	#[Group('strata/telemetry')]
	public function endNeverPrecedesStart(): void
	{
		$span = new Span('strata.flush', str_repeat('a', 32), str_repeat('b', 16), 1_000, -9_000);

		$this->assertSame(1_000, $span->endNanos());
	}

	#[Test]
	#[TestDox('seconds() converts the nanosecond duration')]
	#[Group('strata/telemetry')]
	public function secondsConvertsTheDuration(): void
	{
		$span = new Span('a', 'b', 'c', 0, Span::NANOS_PER_SECOND);

		$this->assertSame(1.0, $span->seconds());
		$this->assertSame(0.5, (new Span('a', 'b', 'c', 0, 500_000_000))->seconds());
		$this->assertSame(0.0, (new Span('a', 'b', 'c', 0))->seconds());
	}

	#[Test]
	#[TestDox('isError() reads the status')]
	#[Group('strata/telemetry')]
	public function isErrorReadsTheStatus(): void
	{
		$this->assertFalse((new Span('a', 'b', 'c', 0))->isError());
		$this->assertFalse((new Span('a', 'b', 'c', 0, 0, [], Span::OK))->isError());
		$this->assertTrue((new Span('a', 'b', 'c', 0, 0, [], Span::ERROR))->isError());
	}

	#[Test]
	#[TestDox('close() merges attributes without losing the ones the span opened with')]
	#[Group('strata/telemetry')]
	public function closeMergesAttributes(): void
	{
		$open = new Span(
			'strata.flush',
			'trace',
			'span',
			4_000,
			0,
			[
				'strata.realm' => 'entity',
				'strata.subjects' => 3,
			],
			Span::OK,
			'',
			'parent',
		);
		$closed = $open->close(9_000, ['strata.frames' => 12, 'strata.subjects' => 4]);

		$this->assertSame(
			['strata.frames' => 12, 'strata.subjects' => 4, 'strata.realm' => 'entity'],
			$closed->attributes,
		);
		$this->assertSame('strata.flush', $closed->name);
		$this->assertSame('trace', $closed->traceId);
		$this->assertSame('span', $closed->spanId);
		$this->assertSame('parent', $closed->parentId);
		$this->assertSame(4_000, $closed->startNanos);
		$this->assertSame(9_000, $closed->durationNanos);
		$this->assertSame(0, $open->durationNanos, 'the open span is untouched');
	}

	#[Test]
	#[TestDox('close() carries a failure status and its message')]
	#[Group('strata/telemetry')]
	public function closeCarriesAFailure(): void
	{
		$closed = (new Span('a', 'b', 'c', 0))->close(5, [], Span::ERROR, 'disk full');

		$this->assertTrue($closed->isError());
		$this->assertSame('disk full', $closed->message);
	}

	#[Test]
	#[TestDox('jsonSerialize() reports the ids, both clocks and the attributes')]
	#[Group('strata/telemetry')]
	public function spanJsonSerialize(): void
	{
		$span = new Span(
			'strata.flush',
			'tid',
			'sid',
			1_000,
			250_000_000,
			['n' => 1],
			Span::OK,
			'',
			'pid',
		);

		$this->assertSame(
			[
				'name' => 'strata.flush',
				'trace_id' => 'tid',
				'span_id' => 'sid',
				'parent_id' => 'pid',
				'start_nanos' => 1_000,
				'duration_nanos' => 250_000_000,
				'seconds' => 0.25,
				'status' => Span::OK,
				'message' => '',
				'attributes' => ['n' => 1],
			],
			$span->jsonSerialize(),
		);
	}

	#endregion

	#region Tracer

	#[Test]
	#[TestDox('a disabled tracer records nothing and still returns the callable value')]
	#[Group('strata/telemetry')]
	public function disabledTracerRecordsNothing(): void
	{
		$tracer = new Tracer(false);

		$this->assertFalse($tracer->isEnabled());
		$this->assertSame(
			'result',
			$tracer->measure('strata.flush', static fn(): string => 'result'),
		);
		$this->assertSame([], $tracer->spans());
		$this->assertSame(0, $tracer->dropped());
	}

	#[Test]
	#[TestDox('a disabled tracer still rethrows what the callable threw')]
	#[Group('strata/telemetry')]
	public function disabledTracerStillRethrows(): void
	{
		$tracer = new Tracer(false);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('nope');

		$tracer->measure('strata.flush', static function (): void {
			throw new RuntimeException('nope');
		});
	}

	#[Test]
	#[TestDox('measure() records one span with a duration that actually elapsed')]
	#[Group('strata/telemetry')]
	public function measureRecordsOneSpan(): void
	{
		$tracer = new Tracer();
		$result = $tracer->measure(
			'strata.flush',
			static function (): int {
				$sum = 0;

				for ($i = 0; $i < 10_000; $i++) {
					$sum += $i;
				}

				return $sum;
			},
			['strata.realm' => 'entity'],
		);

		$spans = $tracer->spans();

		$this->assertSame(49_995_000, $result);
		$this->assertCount(1, $spans);
		$this->assertSame('strata.flush', $spans[0]->name);
		$this->assertGreaterThan(0, $spans[0]->durationNanos);
		$this->assertGreaterThan(0.0, $spans[0]->seconds());
		$this->assertSame(['strata.realm' => 'entity'], $spans[0]->attributes);
		$this->assertFalse($spans[0]->isError());
		$this->assertNull($spans[0]->parentId);
	}

	#[Test]
	#[TestDox('an exception is recorded on the span as an error and then rethrown')]
	#[Group('strata/telemetry')]
	public function measureRecordsAndRethrows(): void
	{
		$tracer = new Tracer();
		$thrown = null;

		try {
			$tracer->measure('strata.flush', static function (): void {
				throw new RuntimeException('the provider refused');
			});
		} catch (RuntimeException $e) {
			$thrown = $e;
		}

		$spans = $tracer->spans();

		$this->assertNotNull($thrown, 'the exception reached the caller');
		$this->assertSame('the provider refused', $thrown->getMessage());
		$this->assertCount(1, $spans);
		$this->assertSame(Span::ERROR, $spans[0]->status);
		$this->assertTrue($spans[0]->isError());
		$this->assertSame('the provider refused', $spans[0]->message);
	}

	#[Test]
	#[TestDox('a nested span names its parent and the next sibling has none')]
	#[Group('strata/telemetry')]
	public function nestingTracksTheParent(): void
	{
		$tracer = new Tracer();

		$tracer->measure('strata.flush', function () use ($tracer): void {
			$tracer->measure('strata.pack', static fn(): bool => true);
		});
		$tracer->measure('strata.prune', static fn(): bool => true);

		$spans = $tracer->spans();

		$this->assertCount(3, $spans);
		$this->assertSame('strata.pack', $spans[0]->name);
		$this->assertSame('strata.flush', $spans[1]->name);
		$this->assertSame('strata.prune', $spans[2]->name);
		$this->assertSame($spans[1]->spanId, $spans[0]->parentId, 'the inner span names the outer');
		$this->assertNull($spans[1]->parentId);
		$this->assertNull($spans[2]->parentId, 'closing the outer span cleared the stack');
	}

	#[Test]
	#[TestDox('every span in one tracer shares one trace id')]
	#[Group('strata/telemetry')]
	public function everySpanSharesOneTraceId(): void
	{
		$tracer = new Tracer();

		for ($i = 0; $i < 5; $i++) {
			$tracer->measure('strata.flush', static fn(): bool => true);
		}

		$spans = $tracer->spans();
		$traceIds = array_unique(array_map(static fn(Span $s): string => $s->traceId, $spans));
		$spanIds = array_map(static fn(Span $s): string => $s->spanId, $spans);

		$this->assertCount(1, $traceIds);
		$this->assertSame(32, strlen($spans[0]->traceId));
		$this->assertCount(5, array_unique($spanIds), 'each span gets its own id');
		$this->assertSame(16, strlen($spans[0]->spanId));
	}

	#[Test]
	#[TestDox('the buffer caps and the spans past the cap are counted rather than kept')]
	#[Group('strata/telemetry')]
	public function bufferCapsAndCounts(): void
	{
		$tracer = new Tracer();

		for ($i = 0; $i < Tracer::MAX_SPANS + 7; $i++) {
			$tracer->close($tracer->start('strata.subject'), 100);
		}

		$this->assertCount(Tracer::MAX_SPANS, $tracer->spans());
		$this->assertSame(7, $tracer->dropped());
	}

	#[Test]
	#[TestDox('drain() hands over the buffer and empties it')]
	#[Group('strata/telemetry')]
	public function drainEmptiesTheBuffer(): void
	{
		$tracer = new Tracer();

		$tracer->measure('strata.flush', static fn(): bool => true);
		$tracer->measure('strata.prune', static fn(): bool => true);

		$drained = $tracer->drain();

		$this->assertCount(2, $drained);
		$this->assertSame([], $tracer->spans());
		$this->assertSame([], $tracer->drain());
	}

	#endregion

	#region MetricSet

	#[Test]
	#[TestDox('a gauge and a counter each record their kind')]
	#[Group('strata/telemetry')]
	public function kindIsRecorded(): void
	{
		$metrics = new MetricSet();

		$metrics->gauge('strata.stored_bytes', 4096.0, 'By', ['provider' => 'local']);
		$metrics->counter('strata.requests', 12.0, '1');

		// the key carries the dimensions so two severities of one name stay two points; the plain
		// metric name travels in the entry, which is what the wire shape uses
		$this->assertSame(
			[
				'strata.stored_bytes{provider=local}' => [
					'name' => 'strata.stored_bytes',
					'value' => 4096.0,
					'kind' => MetricSet::GAUGE,
					'unit' => 'By',
					'attributes' => ['provider' => 'local'],
				],
				'strata.requests' => [
					'name' => 'strata.requests',
					'value' => 12.0,
					'kind' => MetricSet::COUNTER,
					'unit' => '1',
					'attributes' => [],
				],
			],
			$metrics->all(),
		);
	}

	#[Test]
	#[TestDox('one name with two sets of dimensions stays two readings rather than overwriting')]
	#[Group('strata/telemetry')]
	public function dimensionsDoNotCollide(): void
	{
		$metrics = new MetricSet();

		$metrics->gauge('strata.open_findings', 1.0, '1', ['severity' => 'warn']);
		$metrics->gauge('strata.open_findings', 3.0, '1', ['severity' => 'error']);

		$this->assertCount(2, $metrics->all());
		$this->assertSame(1.0, $metrics->get('strata.open_findings', ['severity' => 'warn']));
		$this->assertSame(3.0, $metrics->get('strata.open_findings', ['severity' => 'error']));
	}

	#[Test]
	#[TestDox('the same dimensions in a different order resolve to one reading, not two')]
	#[Group('strata/telemetry')]
	public function dimensionOrderDoesNotMatter(): void
	{
		$metrics = new MetricSet();

		$metrics->gauge('strata.requests', 1.0, '1', ['class' => 'A', 'provider' => 'r2']);
		$metrics->gauge('strata.requests', 2.0, '1', ['provider' => 'r2', 'class' => 'A']);

		$this->assertCount(1, $metrics->all());
		$this->assertSame(
			2.0,
			$metrics->get('strata.requests', ['class' => 'A', 'provider' => 'r2']),
		);
	}

	#[Test]
	#[TestDox('a name recorded twice keeps the later reading, since a set is a snapshot')]
	#[Group('strata/telemetry')]
	public function laterReadingWins(): void
	{
		$metrics = new MetricSet();

		$metrics->gauge('strata.stored_bytes', 100.0, 'By');
		$metrics->gauge('strata.stored_bytes', 200.0, 'By');

		$this->assertSame(200.0, $metrics->get('strata.stored_bytes'));
		$this->assertSame(1, $metrics->count());
	}

	#[Test]
	#[TestDox('a counter can replace a gauge of the same name, and the kind follows')]
	#[Group('strata/telemetry')]
	public function kindFollowsTheLaterReading(): void
	{
		$metrics = new MetricSet();

		$metrics->gauge('strata.requests', 1.0);
		$metrics->counter('strata.requests', 2.0);

		$this->assertSame(MetricSet::COUNTER, $metrics->all()['strata.requests']['kind']);
	}

	#[Test]
	#[TestDox('an empty set reports itself empty and hands back nothing')]
	#[Group('strata/telemetry')]
	public function emptySetReportsItself(): void
	{
		$metrics = new MetricSet();

		$this->assertTrue($metrics->isEmpty());
		$this->assertSame(0, $metrics->count());
		$this->assertNull($metrics->get('strata.stored_bytes'));
		$this->assertSame([], $metrics->all());
		$this->assertSame([], $metrics->jsonSerialize());

		$metrics->gauge('strata.stored_bytes', 0.0);

		$this->assertFalse($metrics->isEmpty(), 'a reading of zero is still a reading');
		$this->assertSame(0.0, $metrics->get('strata.stored_bytes'));
	}

	#[Test]
	#[TestDox('gauge() and counter() return the set, so readings chain')]
	#[Group('strata/telemetry')]
	public function recordingChains(): void
	{
		$metrics = (new MetricSet())
			->gauge('strata.a', 1.0)
			->counter('strata.b', 2.0)
			->gauge('strata.c', 3.0);

		$this->assertSame(3, $metrics->count());
	}

	#endregion

	#region Wire format

	#[Test]
	#[TestDox('trace and span ids travel as base16 hex rather than base64')]
	#[Group('strata/telemetry')]
	public function idsTravelAsHex(): void
	{
		$tracer = new Tracer();

		$tracer->measure('strata.flush', static fn(): bool => true);

		$span = $tracer->spans()[0];
		$body = (new OtlpPayload())->traces($tracer->spans());
		$entry = $body['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

		$this->assertSame($span->traceId, $entry['traceId']);
		$this->assertSame($span->spanId, $entry['spanId']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $entry['traceId']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $entry['spanId']);
		$this->assertNotSame(base64_encode(hex2bin($span->traceId)), $entry['traceId']);
	}

	#[Test]
	#[TestDox('every fixed64 timestamp travels as a json string, not a number')]
	#[Group('strata/telemetry')]
	public function fixed64FieldsTravelAsStrings(): void
	{
		$span = new Span(
			'strata.flush',
			str_repeat('a', 32),
			str_repeat('b', 16),
			1_700_000_000_123_456_789,
			500,
		);
		$traces = (new OtlpPayload())->traces([$span]);
		$entry = $traces['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

		$this->assertIsString($entry['startTimeUnixNano']);
		$this->assertIsString($entry['endTimeUnixNano']);
		$this->assertSame('1700000000123456789', $entry['startTimeUnixNano']);
		$this->assertSame('1700000000123457289', $entry['endTimeUnixNano']);

		$metrics = (new OtlpPayload())->metrics(
			(new MetricSet())->counter('strata.requests', 3.0)->gauge('strata.stored_bytes', 9.0),
			1_700_000_000_123_456_789,
		);
		$rendered = $metrics['resourceMetrics'][0]['scopeMetrics'][0]['metrics'];

		$this->assertIsString($rendered[0]['sum']['dataPoints'][0]['timeUnixNano']);
		$this->assertIsString($rendered[0]['sum']['dataPoints'][0]['startTimeUnixNano']);
		$this->assertIsString($rendered[1]['gauge']['dataPoints'][0]['timeUnixNano']);
	}

	#[Test]
	#[TestDox('the encoded body quotes the timestamps rather than emitting bare digits')]
	#[Group('strata/telemetry')]
	public function encodedBodyQuotesTimestamps(): void
	{
		$span = new Span(
			'strata.flush',
			str_repeat('a', 32),
			str_repeat('b', 16),
			1_700_000_000_123_456_789,
		);
		$encoded = (string) json_encode((new OtlpPayload())->traces([$span]));

		$this->assertStringContainsString('"startTimeUnixNano":"1700000000123456789"', $encoded);
		$this->assertStringNotContainsString('"startTimeUnixNano":1700000000123456789', $encoded);
	}

	#[Test]
	#[TestDox('a counter renders as a monotonic cumulative sum and a gauge as a gauge')]
	#[Group('strata/telemetry')]
	public function counterAndGaugeRenderDifferently(): void
	{
		$metrics = (new MetricSet())
			->counter('strata.requests', 12.0, '1')
			->gauge('strata.stored_bytes', 4096.0, 'By');
		$body = (new OtlpPayload())->metrics($metrics, 500);
		$rendered = $body['resourceMetrics'][0]['scopeMetrics'][0]['metrics'];

		$this->assertSame('strata.requests', $rendered[0]['name']);
		$this->assertSame('1', $rendered[0]['unit']);
		$this->assertArrayHasKey('sum', $rendered[0]);
		$this->assertArrayNotHasKey('gauge', $rendered[0]);
		$this->assertTrue($rendered[0]['sum']['isMonotonic']);
		$this->assertSame(2, $rendered[0]['sum']['aggregationTemporality']);
		$this->assertSame(12.0, $rendered[0]['sum']['dataPoints'][0]['asDouble']);

		$this->assertSame('strata.stored_bytes', $rendered[1]['name']);
		$this->assertArrayHasKey('gauge', $rendered[1]);
		$this->assertArrayNotHasKey('sum', $rendered[1]);
		$this->assertArrayNotHasKey('isMonotonic', $rendered[1]['gauge']);
		$this->assertSame(4096.0, $rendered[1]['gauge']['dataPoints'][0]['asDouble']);
	}

	/**
	 * @return array<string, array{string|int|float|bool, array<string, mixed>}>
	 */
	public static function attributeValueProvider(): array
	{
		return [
			'a string' => ['entity', ['stringValue' => 'entity']],
			'an integer' => [42, ['intValue' => '42']],
			'a float' => [1.5, ['doubleValue' => 1.5]],
			'true' => [true, ['boolValue' => true]],
			'false' => [false, ['boolValue' => false]],
		];
	}

	#[Test]
	#[TestDox('$_dataName attribute is rendered with the type OTLP expects')]
	#[Group('strata/telemetry')]
	#[DataProvider('attributeValueProvider')]
	public function attributeValuesAreTyped(string|int|float|bool $value, array $expected): void
	{
		$span = new Span('a', 'b', 'c', 0, 0, ['strata.thing' => $value]);
		$body = (new OtlpPayload())->traces([$span]);
		$attributes = $body['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['attributes'];

		$this->assertSame([['key' => 'strata.thing', 'value' => $expected]], $attributes);
	}

	#[Test]
	#[TestDox('an integer attribute is quoted, since OTLP types it as a fixed64')]
	#[Group('strata/telemetry')]
	public function integerAttributeIsQuoted(): void
	{
		$span = new Span('a', 'b', 'c', 0, 0, ['strata.frames' => 12]);
		$body = (new OtlpPayload())->traces([$span]);
		$value = $body['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['attributes'][0]['value'];

		$this->assertIsString($value['intValue']);
	}

	#[Test]
	#[TestDox('parentSpanId is absent for a root span and present for a nested one')]
	#[Group('strata/telemetry')]
	public function parentSpanIdOnlyWhenThereIsAParent(): void
	{
		$root = new Span('strata.flush', 'tid', 'aaaa', 0);
		$child = new Span('strata.pack', 'tid', 'bbbb', 0, 0, [], Span::OK, '', 'aaaa');
		$body = (new OtlpPayload())->traces([$root, $child]);
		$spans = $body['resourceSpans'][0]['scopeSpans'][0]['spans'];

		$this->assertArrayNotHasKey('parentSpanId', $spans[0]);
		$this->assertSame('aaaa', $spans[1]['parentSpanId']);
	}

	#[Test]
	#[TestDox('a failed span carries the OTLP error code and its message')]
	#[Group('strata/telemetry')]
	public function failedSpanCarriesTheErrorCode(): void
	{
		$ok = new Span('a', 'tid', 'sid', 0);
		$bad = new Span('a', 'tid', 'sid', 0, 0, [], Span::ERROR, 'disk full');
		$body = (new OtlpPayload())->traces([$ok, $bad]);
		$spans = $body['resourceSpans'][0]['scopeSpans'][0]['spans'];

		$this->assertSame(['code' => 1], $spans[0]['status']);
		$this->assertSame(['code' => 2, 'message' => 'disk full'], $spans[1]['status']);
		$this->assertSame(1, $spans[0]['kind']);
	}

	#[Test]
	#[TestDox('the resource names the service, and the scope names the module')]
	#[Group('strata/telemetry')]
	public function resourceNamesTheService(): void
	{
		$payload = new OtlpPayload('example.com', '1.2.3', ['deployment.environment' => 'prod']);

		$this->assertSame(
			[
				'service.name' => 'example.com',
				'deployment.environment' => 'prod',
				'service.version' => '1.2.3',
			],
			$payload->resourceAttributes(),
		);

		$body = $payload->traces([new Span('a', 'tid', 'sid', 0)]);
		$resource = $body['resourceSpans'][0]['resource']['attributes'];

		$this->assertSame(
			['key' => 'service.name', 'value' => ['stringValue' => 'example.com']],
			$resource[0],
		);
		$this->assertSame(
			['name' => OtlpPayload::SCOPE, 'version' => '1.2.3'],
			$body['resourceSpans'][0]['scopeSpans'][0]['scope'],
		);
	}

	#[Test]
	#[TestDox('with no version configured neither the resource nor the scope invents one')]
	#[Group('strata/telemetry')]
	public function noVersionMeansNoVersionAttribute(): void
	{
		$payload = new OtlpPayload();
		$body = $payload->traces([new Span('a', 'tid', 'sid', 0)]);

		$this->assertSame(['service.name' => 'strata'], $payload->resourceAttributes());
		$this->assertSame(
			['name' => OtlpPayload::SCOPE],
			$body['resourceSpans'][0]['scopeSpans'][0]['scope'],
		);
	}

	#[Test]
	#[TestDox('the metrics body carries the same resource as the traces body')]
	#[Group('strata/telemetry')]
	public function metricsCarryTheResourceToo(): void
	{
		$payload = new OtlpPayload('example.com');
		$body = $payload->metrics((new MetricSet())->gauge('strata.a', 1.0), 500);

		$this->assertSame(
			[['key' => 'service.name', 'value' => ['stringValue' => 'example.com']]],
			$body['resourceMetrics'][0]['resource']['attributes'],
		);
	}

	#endregion

	#region Exporter

	#[Test]
	#[TestDox('with no endpoint configured nothing is posted and both exports succeed')]
	#[Group('strata/telemetry')]
	public function noEndpointPostsNothing(): void
	{
		$client = $this->createMock(ClientInterface::class);
		$client->expects($this->never())->method('request');

		$exporter = new OtlpExporter($client, new OtlpPayload(), new NullLogger());

		$this->assertFalse($exporter->isEnabled());
		$this->assertTrue($exporter->exportSpans([new Span('a', 'tid', 'sid', 0)]));
		$this->assertTrue($exporter->exportMetrics((new MetricSet())->gauge('strata.a', 1.0)));
	}

	#[Test]
	#[TestDox('an endpoint of only whitespace is no endpoint at all')]
	#[Group('strata/telemetry')]
	public function whitespaceEndpointIsDisabled(): void
	{
		$client = $this->createMock(ClientInterface::class);
		$client->expects($this->never())->method('request');

		$exporter = new OtlpExporter($client, new OtlpPayload(), new NullLogger(), "  \n ");

		$this->assertFalse($exporter->isEnabled());
		$this->assertTrue($exporter->exportSpans([new Span('a', 'tid', 'sid', 0)]));
	}

	#[Test]
	#[TestDox('nothing to send is not sent, even with a collector configured')]
	#[Group('strata/telemetry')]
	public function nothingToSendIsNotSent(): void
	{
		$client = $this->createMock(ClientInterface::class);
		$client->expects($this->never())->method('request');

		$exporter = new OtlpExporter(
			$client,
			new OtlpPayload(),
			new NullLogger(),
			'https://collector.example',
		);

		$this->assertTrue($exporter->isEnabled());
		$this->assertTrue($exporter->exportSpans([]));
		$this->assertTrue($exporter->exportMetrics(new MetricSet()));
		$this->assertTrue($exporter->drain(new Tracer()));
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function urlProvider(): array
	{
		return [
			'a base url' => [
				'https://collector.example',
				OtlpPayload::TRACES_PATH,
				'https://collector.example/v1/traces',
			],
			'a base url with a trailing slash' => [
				'https://collector.example/',
				OtlpPayload::TRACES_PATH,
				'https://collector.example/v1/traces',
			],
			'a base url with surrounding whitespace' => [
				'  https://collector.example  ',
				OtlpPayload::TRACES_PATH,
				'https://collector.example/v1/traces',
			],
			'a base url under a prefix' => [
				'https://collector.example/otlp',
				OtlpPayload::METRICS_PATH,
				'https://collector.example/otlp/v1/metrics',
			],
			'a full traces url' => [
				'https://collector.example/v1/traces',
				OtlpPayload::TRACES_PATH,
				'https://collector.example/v1/traces',
			],
			'a full metrics url' => [
				'https://collector.example/v1/metrics',
				OtlpPayload::METRICS_PATH,
				'https://collector.example/v1/metrics',
			],
			'a full traces url with a trailing slash' => [
				'https://collector.example/v1/traces/',
				OtlpPayload::TRACES_PATH,
				'https://collector.example/v1/traces',
			],
		];
	}

	#[Test]
	#[TestDox('url() for $_dataName appends the signal path exactly once')]
	#[Group('strata/telemetry')]
	#[DataProvider('urlProvider')]
	public function urlAppendsThePathOnce(string $endpoint, string $path, string $expected): void
	{
		$exporter = new OtlpExporter(
			$this->createMock(ClientInterface::class),
			new OtlpPayload(),
			new NullLogger(),
			$endpoint,
		);

		$this->assertSame($expected, $exporter->url($path));
		$this->assertSame(1, substr_count($exporter->url($path), $path));
	}

	#[Test]
	#[TestDox('a 2xx is a success, and the request carries the configured headers')]
	#[Group('strata/telemetry')]
	public function successfulExportCarriesHeaders(): void
	{
		$seen = [];
		$client = $this->createMock(ClientInterface::class);
		$client
			->expects($this->once())
			->method('request')
			->willReturnCallback(function (string $method, string $url, array $options) use (
				&$seen,
			): Response {
				$seen = ['method' => $method, 'url' => $url, 'options' => $options];

				return new Response(202);
			});

		$exporter = new OtlpExporter(
			$client,
			new OtlpPayload(),
			new NullLogger(),
			'https://collector.example',
			['api-key' => 'abc'],
		);

		$this->assertTrue($exporter->exportSpans([new Span('a', 'tid', 'sid', 0)]));
		$this->assertSame('POST', $seen['method']);
		$this->assertSame('https://collector.example/v1/traces', $seen['url']);
		$this->assertSame('application/json', $seen['options']['headers']['Content-Type']);
		$this->assertSame('abc', $seen['options']['headers']['api-key']);
		$this->assertSame(OtlpExporter::TIMEOUT, $seen['options']['timeout']);
		$this->assertFalse($seen['options']['http_errors']);
		$this->assertIsString($seen['options']['body']);
		$this->assertStringContainsString('"resourceSpans"', $seen['options']['body']);
	}

	#[Test]
	#[TestDox('a non-2xx answer is a warning and a false, not an exception')]
	#[Group('strata/telemetry')]
	public function nonSuccessLogsAndReturnsFalse(): void
	{
		$logger = $this->recordingLogger();
		$client = $this->createMock(ClientInterface::class);
		$client->method('request')->willReturn(new Response(503));

		$exporter = new OtlpExporter(
			$client,
			new OtlpPayload(),
			$logger,
			'https://collector.example',
		);

		$this->assertFalse($exporter->exportSpans([new Span('a', 'tid', 'sid', 0)]));
		$this->assertCount(1, $logger->records);
		$this->assertSame('warning', $logger->records[0]['level']);
		$this->assertSame(503, $logger->records[0]['context']['@status']);
		$this->assertSame(OtlpPayload::TRACES_PATH, $logger->records[0]['context']['@path']);
	}

	#[Test]
	#[TestDox('a client that throws is a warning and a false, so telemetry cannot break a flush')]
	#[Group('strata/telemetry')]
	public function clientFailureIsSwallowed(): void
	{
		$logger = $this->recordingLogger();
		$client = $this->createMock(ClientInterface::class);
		$client
			->method('request')
			->willThrowException(
				new ConnectException('no route to host', new Request('POST', 'https://c.example')),
			);

		$exporter = new OtlpExporter(
			$client,
			new OtlpPayload(),
			$logger,
			'https://collector.example',
		);

		$this->assertFalse(
			$exporter->exportMetrics((new MetricSet())->gauge('strata.a', 1.0), 500),
		);
		$this->assertCount(1, $logger->records);
		$this->assertSame('warning', $logger->records[0]['level']);
		$this->assertSame('no route to host', $logger->records[0]['context']['@message']);
	}

	#[Test]
	#[TestDox('drain() posts the spans a tracer held and leaves it empty')]
	#[Group('strata/telemetry')]
	public function drainPostsAndEmpties(): void
	{
		$client = $this->createMock(ClientInterface::class);
		$client->expects($this->once())->method('request')->willReturn(new Response(200));

		$exporter = new OtlpExporter(
			$client,
			new OtlpPayload(),
			new NullLogger(),
			'https://collector.example',
		);
		$tracer = new Tracer();

		$tracer->measure('strata.flush', static fn(): bool => true);

		$this->assertTrue($exporter->drain($tracer));
		$this->assertSame([], $tracer->spans());
	}

	#endregion

	/**
	 * A logger that keeps what it was told.
	 *
	 * @return LoggerInterface&object{records: list<array{level: string, message: string, context: array<string, mixed>}>}
	 *   The logger.
	 */
	private function recordingLogger(): object
	{
		return new class extends AbstractLogger {
			/**
			 * Everything logged, oldest first.
			 *
			 * @var list<array{level: string, message: string, context: array<string, mixed>}>
			 */
			public array $records = [];

			/**
			 * {@inheritdoc}
			 */
			public function log(mixed $level, string|object $message, array $context = []): void
			{
				$this->records[] = [
					'level' => (string) $level,
					'message' => (string) $message,
					'context' => $context,
				];
			}
		};
	}
}
