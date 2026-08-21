<?php

declare(strict_types=1);

namespace Drupal\strata\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\strata\Capture\CaptureScope;
use Drupal\Core\State\StateInterface;
use Drupal\strata\Anomaly\Anomaly;
use Drupal\strata\Anomaly\AnomalyDetector;
use Drupal\strata\Capture\Classifier\KeyspaceDiscovery;
use Drupal\strata\Codec\Dictionary\DictionaryPass;
use Drupal\strata\Capture\Reconciler;
use Drupal\strata\Code\CodeCapture;
use Drupal\strata\Drill\DrillReport;
use Drupal\strata\Drill\DrillRunner;
use Drupal\strata\Flush\Flusher;
use Drupal\strata\Flush\Lease;
use Drupal\strata\Telemetry\TelemetryPass;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drives everything that has no request to run in.
 *
 * Cron is what makes capture into a backup on a site nobody is watching. A busy site also flushes
 * from the request that filled the window, but a quiet one may go hours between writes, and the age
 * bound exists precisely so those few writes still reach the store.
 *
 * Four things run here, in this order and for these reasons:
 *
 * 1. **Code capture**, because a deploy is not an event Drupal dispatches. It runs FIRST so the
 *    operations it appends are sealed by the flush in the same run rather than waiting for the
 *    next.
 * 2. **The reconciler**, which detects tables that changed with nothing capturing them and
 *    appends the rows to close the gap - again before the flush, so those rows
 *    are sealed too.
 * 3. **The flush**, sealing whatever the window now holds.
 * 4. **Keyspace discovery**, which writes no operations and only describes what is there, so it
 *    runs last and cannot delay anything that does.
 * 5. **Dictionary training**, on its own long interval. It reads segments back and compresses the
 *    samples several times to score the candidates, which is far too slow to run every cron and
 *    pointless to run often: a dictionary describes the shape of what a realm writes, and that
 *    shape changes over weeks rather than minutes.
 * 6. **Anomaly detection**, which takes one reading of the store and scores it against the site's
 *    own earlier readings. It runs after the flush so the reading includes what this cron just
 *    sealed rather than describing the state before it.
 * 7. **A restore drill**, on its own interval and off by default. It replays a sample of subjects
 *    and compares them against what the site holds, which costs a replay per subject - the one
 *    stage here whose cost scales with the size of the sample rather than with what changed.
 * 8. **Telemetry export**, last, because it reports what every earlier stage did and an export that
 *    ran first would describe the previous run.
 *
 * **No stage can stop cron.** A cron hook that throws stops every later hook in the queue, so a
 * storage outage would take the site's search indexing and cache warming down with it. Each stage
 * is caught separately, so one failing does not skip the others either.
 *
 * @see Flusher
 * @see Reconciler
 * @see CodeCapture
 * @see AnomalyDetector
 * @see DrillRunner
 * @see TelemetryPass
 */
final class CronCapture
{
	/**
	 * State key holding when the dictionary pass last ran.
	 */
	public const DICTIONARY_KEY = 'strata.dictionary.trained';

	/**
	 * Seconds between dictionary passes.
	 *
	 * A week. Retraining more often costs a pass and gains nothing, because a new version only
	 * replaces the old one when it measures materially better.
	 */
	public const DICTIONARY_INTERVAL = 604_800;

	/**
	 * State key holding when the last restore drill ran.
	 */
	public const DRILL_KEY = 'strata.drill.ran';

	/**
	 * Seconds between restore drills when nothing is configured.
	 */
	public const DRILL_INTERVAL = 86_400;

	/**
	 * Constructs the cron capture.
	 *
	 * @param Flusher $flusher
	 *   Seals a window when one is due.
	 * @param CaptureScope $scope
	 *   Decides whether capture is on at all.
	 * @param Lease $lease
	 *   Collected on each run so an abandoned lease cannot block a flush forever.
	 * @param LoggerInterface $logger
	 *   Records a stage that could not run.
	 * @param Reconciler|null $reconciler
	 *   Detects uncaptured table changes, or NULL to skip that stage.
	 * @param CodeCapture|null $code
	 *   Captures the site's own code, or NULL to skip that stage.
	 * @param KeyspaceDiscovery|null $keyspace
	 *   Describes the ephemeral keyspace, or NULL to skip that stage.
	 * @param DictionaryPass|null $dictionaries
	 *   Trains the per-realm dictionaries, or NULL to skip that stage.
	 * @param StateInterface|null $state
	 *   Remembers when the dictionary pass and the last drill ran.
	 * @param int $dictionaryInterval
	 *   Seconds between dictionary passes.
	 * @param AnomalyDetector|null $anomalies
	 *   Scores each reading against the site's own history, or NULL to skip that stage.
	 * @param DrillRunner|null $drill
	 *   Proves the store reproduces the site, or NULL to skip that stage.
	 * @param TelemetryPass|null $telemetry
	 *   Exports what the store looks like, or NULL to skip that stage.
	 * @param ConfigFactoryInterface|null $configFactory
	 *   Decides whether the anomaly and drill stages are on, and how often the drill runs.
	 */
	public function __construct(
		private readonly Flusher $flusher,
		private readonly CaptureScope $scope,
		private readonly Lease $lease,
		private readonly LoggerInterface $logger,
		private readonly ?Reconciler $reconciler = null,
		private readonly ?CodeCapture $code = null,
		private readonly ?KeyspaceDiscovery $keyspace = null,
		private readonly ?DictionaryPass $dictionaries = null,
		private readonly ?StateInterface $state = null,
		private readonly int $dictionaryInterval = self::DICTIONARY_INTERVAL,
		private readonly ?AnomalyDetector $anomalies = null,
		private readonly ?DrillRunner $drill = null,
		private readonly ?TelemetryPass $telemetry = null,
		private readonly ?ConfigFactoryInterface $configFactory = null,
	) {}

	/**
	 * Runs every unattended stage.
	 */
	#[Hook('cron')]
	public function onCron(): void
	{
		try {
			$this->lease->collect();
		} catch (Throwable $error) {
			$this->failed('collect abandoned leases', $error);
		}

		if (!$this->scope->isEnabled()) {
			return;
		}

		$this->stage('capture code', fn(): mixed => $this->code?->capture());
		$this->stage('reconcile tables', fn(): mixed => $this->reconciler?->reconcile());
		$this->stage('flush', fn(): mixed => $this->flusher->flush());
		$this->stage('discover the keyspace', fn(): mixed => $this->keyspace?->discover());
		$this->stage('train the dictionaries', fn(): mixed => $this->trainDictionaries());
		$this->stage('detect anomalies', fn(): mixed => $this->detectAnomalies());
		$this->stage('run a restore drill', fn(): mixed => $this->runDrill());
		$this->stage('export telemetry', fn(): mixed => $this->telemetry?->run());
	}

	/**
	 * Takes a reading and scores it, if anomaly detection is on.
	 *
	 * @return list<Anomaly>|null
	 *   What departed from the site's own history, or NULL when the stage is off.
	 */
	private function detectAnomalies(): ?array
	{
		if ($this->anomalies === null || !$this->setting('anomaly.enabled', true)) {
			return null;
		}

		return $this->anomalies->run();
	}

	/**
	 * Runs a restore drill, if one is due.
	 *
	 * @return DrillReport|null
	 *   What the drill proved, or NULL when it is off or not due.
	 */
	private function runDrill(): ?DrillReport
	{
		if ($this->drill === null || $this->state === null) {
			return null;
		}
		if (!$this->setting('drill.enabled', false)) {
			return null;
		}

		$interval = max(60, (int) $this->setting('drill.interval', self::DRILL_INTERVAL));
		$last = (int) $this->state->get(self::DRILL_KEY, 0);
		$now = time();

		if ($last > 0 && $now - $last < $interval) {
			return null;
		}

		// recorded before the drill, so one that dies does not retry on every cron afterwards
		$this->state->set(self::DRILL_KEY, $now);

		return $this->drill->run(
			null,
			(int) $this->setting('drill.sample', DrillRunner::DEFAULT_SAMPLE),
		);
	}

	/**
	 * One setting, or a default when nothing is configured.
	 *
	 * @param string $key
	 *   The setting key.
	 * @param mixed $default
	 *   What to use when the setting is absent.
	 *
	 * @return mixed
	 *   The configured value.
	 */
	private function setting(string $key, mixed $default): mixed
	{
		if ($this->configFactory === null) {
			return $default;
		}

		return $this->configFactory->get('strata.settings')->get($key) ?? $default;
	}

	/**
	 * Trains the dictionaries, if the interval has elapsed.
	 *
	 * @return array<string, array{stored: bool, reason: string, ratio: float, source: string}>|null
	 *   What each realm did, or NULL when the pass was not due.
	 */
	private function trainDictionaries(): ?array
	{
		if ($this->dictionaries === null || $this->state === null) {
			return null;
		}

		$last = (int) $this->state->get(self::DICTIONARY_KEY, 0);
		$now = time();

		if ($last > 0 && $now - $last < $this->dictionaryInterval) {
			return null;
		}

		// recorded before the pass, so a pass that dies does not retry on every cron afterwards
		$this->state->set(self::DICTIONARY_KEY, $now);

		return $this->dictionaries->run();
	}

	/**
	 * Runs one stage, catching whatever it throws.
	 *
	 * @param string $what
	 *   What the stage was doing, for the log line.
	 * @param callable $stage
	 *   The stage.
	 */
	private function stage(string $what, callable $stage): void
	{
		try {
			$stage();
		} catch (Throwable $error) {
			$this->failed($what, $error);
		}
	}

	/**
	 * Records a stage that failed.
	 *
	 * @param string $what
	 *   What it was doing.
	 * @param Throwable $error
	 *   What went wrong.
	 */
	private function failed(string $what, Throwable $error): void
	{
		$this->logger->error('Strata could not %what on cron: %message', [
			'%what' => $what,
			'%message' => $error->getMessage(),
		]);
	}
}
