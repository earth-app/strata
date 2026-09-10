<?php

declare(strict_types=1);

namespace Drupal\strata\Health;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the repair a finding's rung names, unattended or for one code on request.
 *
 * Until 1.0.3 the ladder had no runner. `health.auto_repair` shipped on by default, a checkbox on
 * the retention form wrote it, the schema declared it, the README said the four rungs at or below
 * `rebuild` run on cron - and nothing read the setting. `CircuitBreaker` had a full unit suite and
 * no caller. So a site that recorded a finding recorded it forever: nothing ran a pass, and nothing
 * called `HealthLedgerInterface::resolve()` anywhere in the module, so the dashboard only ever grew.
 *
 * **Findings are resolved before a pass runs, not after, and put back when it raises.** A pass that
 * succeeds proves the work was done, never that the symptom is gone, and only some passes re-check:
 * `Verifier::verify()` sweeps the tripwires again and re-records whatever is still true, while
 * `Reindexer::reindex()` does not. Clearing afterwards would therefore delete a symptom the pass had
 * just re-confirmed. Clearing first and restoring on failure keeps both ends honest: the ledger
 * shows the newest sweep rather than a running total, and a pass that could not run leaves the store
 * looking exactly as broken as it is.
 *
 * **A rung's pass runs once however many codes name it.** Four codes sitting at `reindex` are one
 * reindex, not four.
 *
 * **Failure escalates, and escalation is how cron hands the problem over.** A code whose pass raises
 * climbs one rung; climbing past `rebuild` reaches `quarantine`, which `RepairLadder::isAutomatic()`
 * refuses, so the code stops being retried and waits for a person. The breaker is the shorter leash
 * inside a single run: three failures on a code and it is left alone.
 *
 * @see RepairLadder
 * @see CircuitBreaker
 * @see HealthLedgerInterface
 */
final class RepairPass
{
	/**
	 * Constructs the pass.
	 *
	 * @param HealthLedgerInterface $ledger
	 *   Where findings and rungs are read and written.
	 * @param CircuitBreaker $breaker
	 *   Stops a code that keeps failing from being retried for the rest of the process.
	 * @param LoggerInterface $logger
	 *   Records every pass that ran and every one that raised.
	 * @param array<string, callable(): string> $passes
	 *   Rung name keyed to what running it does, each returning its own summary line. Callables
	 *   rather than objects so a site with no encryption key does not assemble a store to discover
	 *   there is nothing to repair.
	 */
	public function __construct(
		private readonly HealthLedgerInterface $ledger,
		private readonly CircuitBreaker $breaker,
		private readonly LoggerInterface $logger,
		private readonly array $passes,
	) {}

	/**
	 * The breaker this pass retries through.
	 *
	 * @internal
	 *   Exists so a test can assert the configured threshold reached it, which is the only way to
	 *   tell a dial that is read from a dial that is collected and dropped.
	 *
	 * @return CircuitBreaker
	 *   The breaker.
	 */
	public function breaker(): CircuitBreaker
	{
		return $this->breaker;
	}

	/**
	 * Repairs every open code whose rung an unattended run may take.
	 *
	 * @return RepairReport
	 *   What the pass did.
	 */
	public function run(): RepairReport
	{
		return $this->repair($this->openCodes());
	}

	/**
	 * Repairs one code, whether or not it is open.
	 *
	 * @param string $code
	 *   The finding code.
	 *
	 * @return RepairReport
	 *   What the pass did.
	 */
	public function runCode(string $code): RepairReport
	{
		return $this->repair([$code]);
	}

	/**
	 * What running a rung actually does, in a sentence an operator can act on.
	 *
	 * @param string $rung
	 *   The rung.
	 *
	 * @return string
	 *   The description.
	 */
	public function describe(string $rung): string
	{
		return match ($rung) {
			'reindex' => 'rebuild the local indexes from the objects in the bucket',
			'refetch', 'rebuild' => 'fetch and decode every referenced frame',
			'quarantine' => 'stop using the affected objects as restore targets',
			'refuse' => 'block a restore outright',
			default => 'watch only, and record what is seen',
		};
	}

	#region Running

	/**
	 * Repairs a set of codes, running each rung's pass at most once.
	 *
	 * @param list<string> $codes
	 *   The finding codes.
	 *
	 * @return RepairReport
	 *   What the pass did.
	 */
	private function repair(array $codes): RepairReport
	{
		$held = 0;
		$byRung = [];

		foreach ($codes as $code) {
			$rung = $this->ledger->rungFor($code);

			if (!isset($this->passes[$rung])) {
				// observe, and anything a person has to decide
				if (!RepairLadder::isAutomatic($rung)) {
					$held++;
				}

				continue;
			}
			if (!$this->breaker->allow($code)) {
				$this->logger->warning(
					'Strata is not retrying %code: it has failed repeatedly and the breaker is %state.',
					['%code' => $code, '%state' => $this->breaker->state($code)],
				);
				$held++;

				continue;
			}

			$byRung[$rung][] = $code;
		}

		if ($byRung === []) {
			return new RepairReport(count($codes), 0, 0, 0, $held);
		}

		return $this->execute($byRung, count($codes), $held);
	}

	/**
	 * Clears what each rung's codes have open, then runs that rung once.
	 *
	 * @param array<string, list<string>> $byRung
	 *   Rung name keyed to the codes sitting on it.
	 * @param int $considered
	 *   How many codes were looked at in total.
	 * @param int $held
	 *   How many were left for a person before this ran.
	 *
	 * @return RepairReport
	 *   What the pass did.
	 */
	private function execute(array $byRung, int $considered, int $held): RepairReport
	{
		$repaired = 0;
		$failed = 0;
		$cleared = 0;
		$ran = [];

		foreach ($byRung as $rung => $codes) {
			$resolved = $this->clear($codes);
			$cleared += count($resolved);

			try {
				$summary = $this->passes[$rung]();
				$repaired += count($codes);
				$ran[] = sprintf('%s: %s', $rung, $summary);

				foreach ($codes as $code) {
					$this->breaker->recordSuccess($code);
				}

				$this->logger->notice('Strata ran the %rung pass for %codes: %summary', [
					'%rung' => $rung,
					'%codes' => implode(', ', $codes),
					'%summary' => $summary,
				]);
			} catch (Throwable $error) {
				$failed += count($codes);
				$cleared -= count($resolved);
				$ran[] = sprintf('%s: %s', $rung, $error->getMessage());

				// the pass did not run, so the symptoms it was going to answer for are still true.
				// clearing before the pass is what keeps a re-recording pass honest; leaving them
				// cleared after a failure would make a broken store look like a healthy one
				foreach ($resolved as $finding) {
					$this->ledger->record($finding);
				}

				foreach ($codes as $code) {
					$this->breaker->recordFailure($code);
					$this->ledger->setRung($code, RepairLadder::escalate($rung));
				}

				$this->logger->error(
					'Strata could not run the %rung pass for %codes, so they have climbed a rung: %message',
					[
						'%rung' => $rung,
						'%codes' => implode(', ', $codes),
						'%message' => $error->getMessage(),
					],
				);
			}
		}

		return new RepairReport($considered, $repaired, $failed, $cleared, $held, $ran);
	}

	/**
	 * Resolves everything these codes currently hold open, and hands it back.
	 *
	 * The findings are returned so a pass that raises can put them straight back. A ledger keys one
	 * open row per code and scope and re-stamps a repeat, so this is exactly what was there.
	 *
	 * @param list<string> $codes
	 *   The codes about to be repaired.
	 *
	 * @return list<Finding>
	 *   What was cleared.
	 */
	private function clear(array $codes): array
	{
		$cleared = [];

		foreach ($this->ledger->open() as $finding) {
			if (in_array($finding->code, $codes, true)) {
				$this->ledger->resolve($finding->code, $finding->scope);

				$cleared[] = $finding;
			}
		}

		return $cleared;
	}

	/**
	 * The distinct codes the ledger currently holds open.
	 *
	 * @return list<string>
	 *   The codes, each once.
	 */
	private function openCodes(): array
	{
		$codes = [];

		foreach ($this->ledger->open() as $finding) {
			$codes[$finding->code] = $finding->code;
		}

		return array_values($codes);
	}

	#endregion
}
