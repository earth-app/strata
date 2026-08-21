<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Controller;

use Drupal\Core\Url;
use Drupal\strata\Anomaly\Anomaly;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\RepairLadder;
use Drupal\strata_ui\Render\Format;

/**
 * Open findings, the rung each one sits on, and what a drill last proved.
 *
 * **Findings are collapsed to one row per code.** A code with four hundred scopes is one thing to act
 * on, not four hundred, and a dashboard that listed every scope would bury the single CRITICAL under
 * a wall of warnings about the same missing pack.
 *
 * **The repair link is offered only for the rungs that are safe to run.** Observe, reindex, refetch
 * and rebuild all reconstruct something from what survives. Quarantine removes a restore target and
 * refuse blocks a restore, so neither is ever one click away and neither is ever run by cron - both
 * are decisions a person makes deliberately, and they get their own confirmation.
 *
 * @see RepairLadder
 * @see Finding
 */
final class HealthController extends StrataControllerBase
{
	/**
	 * The dashboard.
	 *
	 * @return array<string, mixed>
	 *   The page.
	 */
	public function page(): array
	{
		return $this->guard(function (): array {
			$summary = $this->engine->ledger()->summary();

			return [
				'#theme' => 'strata_health',
				'#findings' => $this->findings($summary),
				'#counts' => $this->counts($summary),
				'#clean' => $summary === [],
				'#drills' => $this->drills(),
				'#anomalies' => $this->anomalies(),
				'#automatic_note' => (string) $this->t(
					'Observe, reindex, refetch and rebuild run on cron. Quarantine and refuse never do.',
				),
				'#verify_note' => (string) $this->t(
					'Silent corruption needs drush strata:verify --deep; nothing here notices it.',
				),
				'#timeline_url' => Url::fromRoute('strata_ui.timeline')->toString(),
			];
		});
	}

	/**
	 * One row per open code.
	 *
	 * @param list<array<string, mixed>> $summary
	 *   What the ledger reported.
	 *
	 * @return list<array<string, mixed>>
	 *   What a template draws.
	 */
	private function findings(array $summary): array
	{
		$findings = [];

		foreach ($summary as $row) {
			$code = (string) $row['code'];
			$rung = (string) $row['rung'];

			$findings[] = [
				'code' => $code,
				'severity' => (int) $row['severity'],
				'severity_name' => Finding::severities()[(int) $row['severity']] ?? 'UNKNOWN',
				'scopes' => Format::count((int) $row['scopes']),
				'newest' => gmdate('Y-m-d H:i:s', (int) $row['newest']),
				'rung' => $rung,
				'automatic' => RepairLadder::isAutomatic($rung),
				'repair_url' => RepairLadder::isAutomatic($rung)
					? Url::fromRoute('strata_ui.repair', ['code' => $code])->toString()
					: null,
			];
		}

		return $findings;
	}

	/**
	 * How many codes are open at each severity.
	 *
	 * @param list<array<string, mixed>> $summary
	 *   What the ledger reported.
	 *
	 * @return array<string, int>
	 *   Severity name keyed to a count, every severity present.
	 */
	private function counts(array $summary): array
	{
		$counts = [];

		foreach (Finding::severities() as $name) {
			$counts[$name] = 0;
		}

		foreach ($summary as $row) {
			$name = Finding::severities()[(int) $row['severity']] ?? 'UNKNOWN';
			$counts[$name] = ($counts[$name] ?? 0) + 1;
		}

		return $counts;
	}

	/**
	 * What the recent restore drills proved.
	 *
	 * @return array<string, mixed>
	 *   The verdict counts and the newest drill, or an empty marker when none has run.
	 */
	private function drills(): array
	{
		$index = $this->engine->drillIndex();
		$newest = $index->newest();

		if ($newest === null) {
			return [
				'ran' => false,
				'note' => (string) $this->t(
					'No drill has run, so that the backups restore is a claim, not a measurement.',
				),
			];
		}

		return [
			'ran' => true,
			'verdict' => (string) $newest['verdict'],
			'detail' => (string) ($newest['detail'] ?? ''),
			'when' => gmdate('Y-m-d H:i:s', (int) $newest['created']),
			'verdicts' => $index->verdicts(),
		];
	}

	/**
	 * What departed from this site's own history, without recording anything.
	 *
	 * @return list<array<string, mixed>>
	 *   Each anomaly, worst first.
	 */
	private function anomalies(): array
	{
		$anomalies = [];

		foreach ($this->engine->anomalyDetector()->current() as $anomaly) {
			$anomalies[] = [
				'metric' => $anomaly->metric,
				'label' => $anomaly->label,
				'describe' => $anomaly->describe(),
				'severity' => $anomaly->severity(),
				'direction' => $anomaly->direction(),
			];
		}

		return $anomalies;
	}
}
