<?php

declare(strict_types=1);

namespace Drupal\strata\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\strata\Capture\CaptureScope;
use Drupal\Core\State\StateInterface;
use Drupal\strata\Capture\Classifier\KeyspaceDiscovery;
use Drupal\strata\Codec\Dictionary\DictionaryPass;
use Drupal\strata\Capture\Reconciler;
use Drupal\strata\Code\CodeCapture;
use Drupal\strata\Flush\Flusher;
use Drupal\strata\Flush\Lease;
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
 *
 * **No stage can stop cron.** A cron hook that throws stops every later hook in the queue, so a
 * storage outage would take the site's search indexing and cache warming down with it. Each stage
 * is caught separately, so one failing does not skip the others either.
 *
 * @see Flusher
 * @see Reconciler
 * @see CodeCapture
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
	 *   Remembers when the dictionary pass last ran.
	 * @param int $dictionaryInterval
	 *   Seconds between dictionary passes.
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
