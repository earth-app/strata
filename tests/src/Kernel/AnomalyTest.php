<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Anomaly\Anomaly;
use Drupal\strata\Anomaly\AnomalyDetector;
use Drupal\strata\Anomaly\MetricSampler;
use Drupal\strata\Anomaly\Series;
use Drupal\strata\Engine;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\HealthLedgerInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves a departure is judged against the site's own window and nothing else.
 *
 * The window is planted in state and the newest reading is taken by the real sampler, so what is
 * being scored is a measurement of the store rather than a fixture. Every planted reading carries
 * the request time, which keeps the operation rate at zero and leaves one metric to depart.
 *
 * The directional rule is measured in both directions. A compression ratio that rose is the pipeline
 * working better and raises nothing; the same magnitude of fall raises.
 */
class AnomalyTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'field', 'key', 'strata'];

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

	private function sampler(): MetricSampler
	{
		return $this->engine()->metricSampler();
	}

	private function detector(): AnomalyDetector
	{
		return $this->engine()->anomalyDetector();
	}

	private function ledger(): HealthLedgerInterface
	{
		return $this->engine()->ledger();
	}

	private function now(): int
	{
		return $this->container->get('datetime.time')->getRequestTime();
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
	 * The reading the real sampler takes of the store as it stands.
	 *
	 * @return array<string, float>
	 *   The reading.
	 */
	private function reading(): array
	{
		$sampler = $this->sampler();

		$sampler->clear();

		$reading = $sampler->sample();

		$sampler->clear();

		return $reading;
	}

	/**
	 * Plants a window whose only spread is on one metric.
	 *
	 * @param string $metric
	 *   The metric to give a history to.
	 * @param float $low
	 *   The lower of its two planted values.
	 * @param float $high
	 *   The higher of its two planted values.
	 * @param int $count
	 *   How many readings to plant.
	 */
	private function seed(
		string $metric,
		float $low,
		float $high,
		int $count = Series::MIN_OBSERVATIONS + 2,
	): void {
		$baseline = $this->reading();
		$window = [];

		for ($i = 0; $i < $count; $i++) {
			$reading = $baseline;
			$reading['at'] = (float) $this->now();
			$reading[$metric] = $i % 2 === 0 ? $low : $high;
			$window[] = $reading;
		}

		$this->container->get('state')->set(MetricSampler::STATE_KEY, $window);
	}

	/**
	 * Takes a reading and scores one metric against the window before it.
	 *
	 * @param string $metric
	 *   A key of MetricSampler::METRICS.
	 *
	 * @return Anomaly|null
	 *   The departure, or NULL when the reading was ordinary.
	 */
	private function scoreOf(string $metric): ?Anomaly
	{
		$detector = $this->detector();

		$this->sampler()->sample();

		return $detector->score($metric);
	}

	/**
	 * Open findings recorded under one code.
	 *
	 * @param string $code
	 *   The finding code.
	 *
	 * @return list<Finding>
	 *   The findings.
	 */
	private function findings(string $code): array
	{
		return array_values(
			array_filter(
				$this->ledger()->open(),
				static fn(Finding $finding): bool => $finding->code === $code,
			),
		);
	}

	#region Sampling

	#[Test]
	#[TestDox('sample() appends a reading carrying every metric and a stamp')]
	#[Group('strata/anomaly')]
	public function sampleAppendsAReading(): void
	{
		$sampler = $this->sampler();

		$this->assertSame([], $sampler->window());

		$reading = $sampler->sample();

		$this->assertCount(1, $sampler->window());
		$this->assertArrayHasKey('at', $reading);
		$this->assertGreaterThan(0.0, $reading['at']);

		foreach (array_keys(MetricSampler::METRICS) as $metric) {
			$this->assertArrayHasKey($metric, $reading, $metric);
		}

		$sampler->sample();

		$this->assertCount(2, $sampler->window());
	}

	#[Test]
	#[TestDox('the window is capped by count, so a site running cron every minute is bounded')]
	#[Group('strata/anomaly')]
	public function windowCapsByCount(): void
	{
		$baseline = $this->reading();
		$window = [];

		for ($i = 0; $i < MetricSampler::WINDOW; $i++) {
			$reading = $baseline;
			$reading['at'] = (float) $i;
			$window[] = $reading;
		}

		$this->container->get('state')->set(MetricSampler::STATE_KEY, $window);

		$sampler = $this->sampler();

		$this->assertCount(MetricSampler::WINDOW, $sampler->window());

		$sampler->sample();

		$kept = $sampler->window();

		$this->assertCount(MetricSampler::WINDOW, $kept, 'the cap held');
		$this->assertSame(1.0, $kept[0]['at'], 'the oldest reading was dropped');
	}

	#[Test]
	#[TestDox('clear() forgets every reading')]
	#[Group('strata/anomaly')]
	public function clearForgetsTheWindow(): void
	{
		$sampler = $this->sampler();

		$sampler->sample();
		$sampler->clear();

		$this->assertSame([], $sampler->window());
		$this->assertSame(0, $sampler->series('stored_bytes')->count());
	}

	#[Test]
	#[
		TestDox(
			'the first sample has no operation rate, since there is nothing to difference against',
		),
	]
	#[Group('strata/anomaly')]
	public function firstSampleHasNoRate(): void
	{
		$this->commitUser('measured');

		$reading = $this->sampler()->sample();

		$this->assertSame(0.0, $reading['op_rate']);
		$this->assertGreaterThan(0.0, $reading['stored_bytes'], 'the store was measured');
	}

	#[Test]
	#[TestDox('a series too short to have a shape scores nothing at all')]
	#[Group('strata/anomaly')]
	public function shortSeriesScoresNothing(): void
	{
		$detector = $this->detector();

		$this->assertSame([], $detector->run());

		foreach (array_keys(MetricSampler::METRICS) as $metric) {
			$this->assertNull($detector->score($metric), $metric);
		}

		$this->assertLessThan(
			Series::MIN_OBSERVATIONS,
			$this->sampler()->series('stored_bytes')->count(),
		);
	}

	#[Test]
	#[TestDox('series() reports one series per metric, for a graph')]
	#[Group('strata/anomaly')]
	public function seriesPerMetric(): void
	{
		$this->sampler()->sample();
		$this->sampler()->sample();

		$series = $this->detector()->series();

		$this->assertSame(array_keys(MetricSampler::METRICS), array_keys($series));

		foreach ($series as $metric => $one) {
			$this->assertSame(2, $one->count(), (string) $metric);
		}
	}

	#endregion

	#region Departures

	#[Test]
	#[TestDox('a large departure from a flat window raises one anomaly and records a finding')]
	#[Group('strata/anomaly')]
	public function largeDepartureRaisesOneAnomaly(): void
	{
		$this->seed('stored_bytes', 1_000_000.0, 1_000_001.0);

		$anomalies = $this->detector()->run();

		$this->assertCount(1, $anomalies, 'only the metric with a history departed');
		$this->assertSame('stored_bytes', $anomalies[0]->metric);
		$this->assertSame('below', $anomalies[0]->direction());
		$this->assertSame(Finding::ERROR, $anomalies[0]->severity());
		$this->assertGreaterThan(AnomalyDetector::ERROR_SIGMA, abs($anomalies[0]->deviations));
		$this->assertSame(Series::MIN_OBSERVATIONS + 2, $anomalies[0]->samples);

		$findings = $this->findings(Anomaly::CODE);

		$this->assertCount(1, $findings);
		$this->assertSame('stored_bytes', $findings[0]->scope);
		$this->assertSame(Finding::ERROR, $findings[0]->severity);
		$this->assertSame($anomalies[0]->describe(), $findings[0]->context);
	}

	#[Test]
	#[TestDox('a rise is reported as well as a fall for a metric with no direction')]
	#[Group('strata/anomaly')]
	public function aRiseIsReportedToo(): void
	{
		$this->commitUser('grown');
		$this->seed('stored_bytes', 10.0, 11.0);

		$anomalies = $this->detector()->run();

		$this->assertCount(1, $anomalies);
		$this->assertSame('stored_bytes', $anomalies[0]->metric);
		$this->assertSame('above', $anomalies[0]->direction());
	}

	#[Test]
	#[TestDox('a reading inside the window raises nothing')]
	#[Group('strata/anomaly')]
	public function anOrdinaryReadingRaisesNothing(): void
	{
		$reading = $this->reading();
		$stored = $reading['stored_bytes'];

		$this->seed('stored_bytes', $stored - 1.0, $stored + 1.0);

		$this->assertSame([], $this->detector()->run());
		$this->assertSame([], $this->findings(Anomaly::CODE));
	}

	#[Test]
	#[TestDox('a wider band configured by the site raises less')]
	#[Group('strata/anomaly')]
	public function aWiderBandRaisesLess(): void
	{
		$this->seed('stored_bytes', 1_000.0, 1_001.0);

		$this->assertNotNull($this->scoreOf('stored_bytes'));

		$this->config('strata.settings')->set('anomaly.warn_sigma', 100_000.0)->save();
		$this->engine()->reset();

		$this->assertNull($this->scoreOf('stored_bytes'));
	}

	#endregion

	#region Direction

	#[Test]
	#[TestDox('a compression ratio that rose raises nothing, since that is the pipeline working')]
	#[Group('strata/anomaly')]
	public function aRisingRatioRaisesNothing(): void
	{
		$this->assertContains('compression_ratio', AnomalyDetector::FALL_ONLY);

		$this->commitUser('compressed');
		$this->seed('compression_ratio', 0.001, 0.002);

		$this->assertGreaterThan(0.01, $this->reading()['compression_ratio']);
		$this->assertNull($this->scoreOf('compression_ratio'));
		$this->assertSame([], $this->findings(Anomaly::CODE));
	}

	#[Test]
	#[TestDox('a compression ratio that fell raises, at the magnitude the rise did not')]
	#[Group('strata/anomaly')]
	public function aFallingRatioRaises(): void
	{
		$this->commitUser('compressed');
		$this->seed('compression_ratio', 1_000.0, 1_001.0);

		$anomaly = $this->scoreOf('compression_ratio');

		$this->assertNotNull($anomaly);
		$this->assertSame('below', $anomaly->direction());
		$this->assertSame('Compression ratio', $anomaly->label);
		$this->assertSame('x', $anomaly->unit);
		$this->assertGreaterThan(AnomalyDetector::ERROR_SIGMA, abs($anomaly->deviations));
	}

	#[Test]
	#[TestDox('a deduplication ratio is directional the same way')]
	#[Group('strata/anomaly')]
	public function aRisingDedupRatioRaisesNothing(): void
	{
		$this->assertContains('dedup_ratio', AnomalyDetector::FALL_ONLY);

		$this->seed('dedup_ratio', 0.001, 0.002);

		$this->assertNull($this->scoreOf('dedup_ratio'));

		$this->seed('dedup_ratio', 1_000.0, 1_001.0);

		$this->assertNotNull($this->scoreOf('dedup_ratio'));
	}

	#endregion

	#region Recording

	#[Test]
	#[TestDox('a preview scores without writing a finding')]
	#[Group('strata/anomaly')]
	public function previewWritesNoFinding(): void
	{
		$this->seed('stored_bytes', 1_000_000.0, 1_000_001.0);

		$anomalies = $this->detector()->run(false);

		$this->assertCount(1, $anomalies);
		$this->assertSame('stored_bytes', $anomalies[0]->metric);
		$this->assertSame([], $this->findings(Anomaly::CODE));
	}

	#[Test]
	#[TestDox('a preview still takes the reading, since the window is what it scores against')]
	#[Group('strata/anomaly')]
	public function previewStillSamples(): void
	{
		$this->seed('stored_bytes', 1_000_000.0, 1_000_001.0);

		$before = count($this->sampler()->window());

		$this->detector()->run(false);

		$this->assertCount($before + 1, $this->sampler()->window());
	}

	#[Test]
	#[TestDox('anomalies are returned worst first, so a dashboard leads with the largest')]
	#[Group('strata/anomaly')]
	public function anomaliesAreWorstFirst(): void
	{
		$baseline = $this->reading();
		$window = [];

		for ($i = 0; $i < Series::MIN_OBSERVATIONS + 2; $i++) {
			$reading = $baseline;
			$reading['at'] = (float) $this->now();
			$reading['stored_bytes'] = $i % 2 === 0 ? 100.0 : 101.0;
			$reading['chain_depth'] = $i % 2 === 0 ? 1_000_000.0 : 1_000_001.0;
			$window[] = $reading;
		}

		$this->container->get('state')->set(MetricSampler::STATE_KEY, $window);

		$anomalies = $this->detector()->run();

		$this->assertCount(2, $anomalies);
		$this->assertSame('chain_depth', $anomalies[0]->metric);
		$this->assertSame('stored_bytes', $anomalies[1]->metric);
		$this->assertGreaterThan(abs($anomalies[1]->deviations), abs($anomalies[0]->deviations));
		$this->assertCount(2, $this->findings(Anomaly::CODE));
	}

	#endregion
}
