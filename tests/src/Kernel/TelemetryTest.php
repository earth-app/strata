<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\strata\Engine;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\HealthLedgerInterface;
use Drupal\strata\Telemetry\MetricSet;
use Drupal\strata\Telemetry\OtlpPayload;
use Drupal\strata\Telemetry\TelemetryPass;
use Drupal\strata\Telemetry\Tracer;
use Drupal\user\Entity\User;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Proves a telemetry pass measures the real store and posts only when a collector is configured.
 *
 * The HTTP client in the container is a test double, so the export path is exercised without a
 * network and the posted body is available to assert against.
 *
 * `openFindingsCarryEverySeverity` pins the property a dimensioned metric has to hold: a `MetricSet`
 * keys on the metric name AND its attributes, so four severities recorded under one name stay four
 * points rather than overwriting each other down to whichever was recorded last.
 */
class TelemetryTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'field', 'key', 'strata'];

	/**
	 * The client an export posts through.
	 */
	private ClientInterface&MockObject $client;

	/**
	 * Requests the client was asked to make.
	 *
	 * @var list<array{string, string, array<string, mixed>}>
	 */
	private array $requests = [];

	/**
	 * {@inheritdoc}
	 */
	public function register(ContainerBuilder $container): void
	{
		parent::register($container);

		$this->client = $this->createMock(ClientInterface::class);
		$this->client
			->method('request')
			->willReturnCallback(function (string $method, string $url, array $options): Response {
				$this->requests[] = [$method, $url, $options];

				return new Response(200);
			});

		$container->set('http_client', $this->client);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->installEntitySchema('user');
		$this->installSchema('user', ['users_data']);

		$this->config('strata.settings')
			->set('enabled', true)
			->set('provider', 'local')
			->set('local_path', $this->storeRoot)
			->set('cipher.id', 'none')
			->save();

		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();
	}

	private function engine(): Engine
	{
		return $this->container->get('strata.engine');
	}

	private function telemetry(): TelemetryPass
	{
		return $this->engine()->telemetryPass();
	}

	private function ledger(): HealthLedgerInterface
	{
		return $this->engine()->ledger();
	}

	/**
	 * Points a collector at the exporter and rebuilds the engine around it.
	 *
	 * @param string $endpoint
	 *   The collector's base URL.
	 */
	private function withCollector(string $endpoint): void
	{
		$this->config('strata.settings')
			->set('telemetry.endpoint', $endpoint)
			->set('telemetry.headers', ['api-key' => 'abc'])
			->save();

		$this->engine()->reset();
	}

	/**
	 * Saves a user and seals the window, so the store has something to measure.
	 *
	 * @param string $name
	 *   The account name.
	 */
	private function commitUser(string $name): void
	{
		$user = User::create(['name' => $name, 'mail' => $name . '@example.com', 'status' => 1]);
		$user->save();

		$this->assertTrue($this->engine()->flusher()->flush(true)->ran);
	}

	/**
	 * The decoded body of the last request.
	 *
	 * @return array<string, mixed>
	 *   The body.
	 */
	private function lastBody(): array
	{
		$this->assertNotSame([], $this->requests, 'something was posted');

		$options = $this->requests[count($this->requests) - 1][2];

		/** @var array<string, mixed> $body */
		$body = json_decode((string) $options['body'], true);

		return $body;
	}

	#region Measuring

	#[Test]
	#[TestDox('a pass over a store with history reports non-zero bytes, frames and commits')]
	#[Group('strata/telemetry')]
	public function measureReportsTheStore(): void
	{
		$this->commitUser('measured');

		$metrics = $this->telemetry()->measure();

		$this->assertGreaterThan(0.0, (float) $metrics->get('strata.stored_bytes'));
		$this->assertGreaterThan(0.0, (float) $metrics->get('strata.raw_bytes'));
		$this->assertGreaterThan(0.0, (float) $metrics->get('strata.frames'));
		$this->assertSame(1.0, $metrics->get('strata.commits'));
		$this->assertGreaterThan(0.0, (float) $metrics->get('strata.compression_ratio'));
		$this->assertSame(0.0, $metrics->get('strata.orphan_frames'));
		$this->assertSame(0.0, $metrics->get('strata.journal_pending'));
	}

	#[Test]
	#[TestDox('a pass over an empty store reports zeros rather than nothing at all')]
	#[Group('strata/telemetry')]
	public function measureOfAnEmptyStore(): void
	{
		$metrics = $this->telemetry()->measure();

		$this->assertFalse($metrics->isEmpty());
		$this->assertSame(0.0, $metrics->get('strata.stored_bytes'));
		$this->assertSame(0.0, $metrics->get('strata.commits'));
		$this->assertSame(0.0, $metrics->get('strata.rpo_lag_seconds'));
	}

	#[Test]
	#[TestDox('every metric carries a unit, since a collector renders one')]
	#[Group('strata/telemetry')]
	public function everyMetricCarriesAUnit(): void
	{
		$this->commitUser('united');

		foreach ($this->telemetry()->measure()->all() as $name => $metric) {
			$this->assertNotSame('', $metric['unit'], (string) $name);
		}
	}

	#[Test]
	#[TestDox('a pending operation is reported, so a stalled flush is visible')]
	#[Group('strata/telemetry')]
	public function pendingWorkIsReported(): void
	{
		$user = User::create(['name' => 'pending', 'mail' => 'p@example.com', 'status' => 1]);
		$user->save();

		$metrics = $this->telemetry()->measure();

		$this->assertGreaterThan(0.0, (float) $metrics->get('strata.journal_pending'));
		$this->assertGreaterThan(0.0, (float) $metrics->get('strata.journal_pending_bytes'));
	}

	#endregion

	#region Recovery point

	#[Test]
	#[TestDox('a store with no history has no lag, rather than the age of the epoch')]
	#[Group('strata/telemetry')]
	public function noHistoryHasNoLag(): void
	{
		$this->assertSame(0.0, $this->telemetry()->rpoLag());
	}

	#[Test]
	#[TestDox('lag rises with the age of the newest commit')]
	#[Group('strata/telemetry')]
	public function lagRisesWithCommitAge(): void
	{
		$this->commitUser('recent');

		$this->assertGreaterThanOrEqual(0.0, $this->telemetry()->rpoLag());
		$this->assertLessThan(
			60.0,
			$this->telemetry()->rpoLag(),
			'a fresh commit is barely behind',
		);

		$commits = $this->engine()->commitIndex();
		$newest = $commits->newest();

		$this->assertNotNull($newest);

		$this->container
			->get('database')
			->update($commits::TABLE)
			->fields(['microtime' => ((int) $newest['microtime']) - 3_600_000_000])
			->condition('id', (string) $newest['id'])
			->execute();

		// the commit is stamped after the request time, so the lag lands just under the hour
		$this->assertGreaterThan(3_500.0, $this->telemetry()->rpoLag());
		$this->assertLessThan(3_700.0, $this->telemetry()->rpoLag());
		$this->assertSame(
			$this->telemetry()->rpoLag(),
			$this->telemetry()->measure()->get('strata.rpo_lag_seconds'),
		);
	}

	#endregion

	#region Findings

	#[Test]
	#[TestDox('findingsBySeverity() reports every severity, including the ones at zero')]
	#[Group('strata/telemetry')]
	public function findingsBySeverityReportsZeros(): void
	{
		$counts = $this->telemetry()->findingsBySeverity();

		$this->assertSame(['info' => 0, 'warn' => 0, 'error' => 0, 'critical' => 0], $counts);

		$this->ledger()->record(new Finding('frame.orphaned', Finding::WARN, 'frames/aa'));

		$counts = $this->telemetry()->findingsBySeverity();

		$this->assertSame(1, $counts['warn']);
		$this->assertSame(0, $counts['error'], 'a severity with nothing open is still reported');
		$this->assertSame(0, $counts['info']);
		$this->assertSame(0, $counts['critical']);
	}

	#[Test]
	#[TestDox('the exported metrics carry one open-findings point per severity')]
	#[Group('strata/telemetry')]
	public function openFindingsCarryEverySeverity(): void
	{
		$this->ledger()->record(new Finding('frame.orphaned', Finding::WARN, 'frames/aa'));
		$this->withCollector('https://collector.example');

		$this->telemetry()->run();

		$points = [];

		foreach ($this->lastBody()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] as $metric) {
			if ($metric['name'] !== 'strata.open_findings') {
				continue;
			}

			foreach ($metric['gauge']['dataPoints'] as $point) {
				foreach ($point['attributes'] as $attribute) {
					$points[(string) $attribute['value']['stringValue']] = $point['asDouble'];
				}
			}
		}

		// compared loosely: json_encode writes an integer-valued float as `0`, so the int-versus-float
		// distinction cannot survive the round trip and a receiver does not care either
		$this->assertEquals(
			['info' => 0.0, 'warn' => 1.0, 'error' => 0.0, 'critical' => 0.0],
			$points,
			'a severity that vanishes at zero leaves a collector alerting on a stale value',
		);
	}

	#endregion

	#region Exporting

	#[Test]
	#[TestDox('with no collector configured the metrics are returned and nothing is posted')]
	#[Group('strata/telemetry')]
	public function noCollectorPostsNothing(): void
	{
		$this->commitUser('unexported');

		$metrics = $this->telemetry()->run();

		$this->assertInstanceOf(MetricSet::class, $metrics);
		$this->assertFalse($metrics->isEmpty());
		$this->assertSame([], $this->requests);
		$this->assertFalse($this->engine()->telemetryExporter()->isEnabled());
	}

	#[Test]
	#[TestDox('with a collector configured the reading is posted to the metrics path')]
	#[Group('strata/telemetry')]
	public function collectorReceivesTheReading(): void
	{
		$this->commitUser('exported');
		$this->withCollector('https://collector.example');

		$metrics = $this->telemetry()->run();

		$this->assertTrue($this->engine()->telemetryExporter()->isEnabled());
		$this->assertCount(1, $this->requests);
		$this->assertSame('POST', $this->requests[0][0]);
		$this->assertSame(
			'https://collector.example' . OtlpPayload::METRICS_PATH,
			$this->requests[0][1],
		);
		$this->assertSame('abc', $this->requests[0][2]['headers']['api-key']);

		$rendered = $this->lastBody()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'];
		$names = array_map(static fn(array $metric): string => (string) $metric['name'], $rendered);

		$this->assertContains('strata.stored_bytes', $names);
		$this->assertContains('strata.rpo_lag_seconds', $names);
		$this->assertSame($metrics->count(), count($rendered));
	}

	#[Test]
	#[TestDox('the resource names the site, so two sites in one collector stay apart')]
	#[Group('strata/telemetry')]
	public function resourceNamesTheSite(): void
	{
		$this->withCollector('https://collector.example');
		$this->telemetry()->run();

		$attributes = [];

		foreach ($this->lastBody()['resourceMetrics'][0]['resource']['attributes'] as $attribute) {
			$attributes[(string) $attribute['key']] = (string) $attribute['value']['stringValue'];
		}

		$this->assertSame('strata', $attributes['service.name']);
		$this->assertSame($this->container->get('strata.site')->id(), $attributes['strata.site']);
	}

	#[Test]
	#[TestDox('a tracer handed to the pass is drained in the same run')]
	#[Group('strata/telemetry')]
	public function tracerIsDrainedAlongside(): void
	{
		$this->withCollector('https://collector.example');

		$tracer = new Tracer();

		$tracer->measure('strata.flush', static fn(): bool => true);

		$this->telemetry()->run($tracer);

		$this->assertSame([], $tracer->spans(), 'the tracer was drained');
		$this->assertCount(2, $this->requests, 'metrics and traces are separate signals');
		$this->assertSame(
			'https://collector.example' . OtlpPayload::TRACES_PATH,
			$this->requests[1][1],
		);
	}

	#[Test]
	#[TestDox('a tracer is off with no collector, so tracing costs nothing by default')]
	#[Group('strata/telemetry')]
	public function tracerIsOffWithoutACollector(): void
	{
		$this->assertFalse($this->engine()->telemetryExporter()->isEnabled());
		$this->assertFalse($this->engine()->tracer()->isEnabled());
	}

	#[Test]
	#[TestDox('a tracer is on once a collector is configured')]
	#[Group('strata/telemetry')]
	public function tracerIsOnWithACollector(): void
	{
		// the engine memoizes the tracer and the exporter and reset() clears neither, so the
		// enabled case needs a container of its own
		$this->withCollector('https://collector.example');

		$this->assertTrue($this->engine()->telemetryExporter()->isEnabled());
		$this->assertTrue($this->engine()->tracer()->isEnabled());
	}

	#endregion
}
