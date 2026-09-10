<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Controller;

use Drupal\Core\Config\Config;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Url;
use Drupal\strata\Health\Finding;
use Drupal\strata_ui\Plugin\Block\StatusBlock;
use Drupal\strata_ui\Render\Format;
use Throwable;

/**
 * What Strata is doing on this site, on one page.
 *
 * **This is the landing page for the whole report section**, and the first thing a fresh install
 * shows. It answers the only question an operator has before anything else: is this site backed up
 * right now, and if not, what has to be set. Every other report assumes the answer is yes.
 *
 * **The requirement rows come from `strata_requirements()` rather than being recomputed here**, so
 * this page and Drupal's own status report can never disagree about whether the bucket is reachable
 * or a key is set. That function deliberately avoids assembling the engine - encryption is on by
 * default with no key, and a status page that refuses to render on a fresh install is the one page
 * that must not.
 *
 * @see StatusBlock
 */
final class StatusController extends StrataControllerBase
{
	/**
	 * Lag in seconds at which the page warns.
	 */
	public const WARN_LAG = 60;

	/**
	 * Lag in seconds at which the page reports an error.
	 */
	public const ERROR_LAG = 900;

	/**
	 * What went wrong reading the store, when something did.
	 */
	private string $unavailable = '';

	/**
	 * The status page.
	 *
	 * @return array<string, mixed>
	 *   The page.
	 */
	public function page(): array
	{
		// every section below guards itself; this is the backstop for anything they do not reach
		return $this->guard(fn(): array => $this->build());
	}

	/**
	 * The page itself.
	 *
	 * @return array<string, mixed>
	 *   The render array, before the library is attached.
	 */
	private function build(): array
	{
		$settings = $this->config('strata.settings');
		$capturing = (bool) $settings->get('enabled');
		$requirements = $this->requirements();
		$worst = $this->worstOf($requirements);

		return [
			'#theme' => 'strata_status_page',
			'#capturing' => $capturing,
			'#verdict' => $this->verdict($capturing, $worst),
			'#severity' => $capturing ? $this->severityName($worst) : 'warn',
			'#requirements' => $requirements,
			'#figures' => $this->figures(),
			'#findings' => $this->findings(),
			'#drill' => $this->drill(),
			'#setup' => $this->setup($settings, $capturing),
			'#links' => $this->links(),
			'#unavailable' => $this->unavailable,
		];
	}

	#region Sections

	/**
	 * The ordered steps that turn a fresh install into a site that is backed up.
	 *
	 * Strata ships doing nothing: capture is off, encryption is on with no key, and the shipped
	 * local directory is a stream wrapper a site without a private file system does not have. Every
	 * one of those was reported somewhere - a form error, a status row, a refusal on another page -
	 * and none of them was reported in order, on the page a new install opens first.
	 *
	 * Returned empty once every step is done, so the checklist disappears rather than becoming
	 * furniture on a site that has been running for a year.
	 *
	 * @param Config $settings
	 *   The module settings.
	 * @param bool $capturing
	 *   Whether capture is switched on.
	 *
	 * @return list<array{title: string, detail: string, done: bool, url: string}>
	 *   One step each, in the order they have to be done.
	 */
	private function setup(Config $settings, bool $capturing): array
	{
		$encrypted = (string) ($settings->get('cipher.id') ?: 'xchacha20poly1305') !== 'none';
		$hasKey = trim((string) $settings->get('key')) !== '';
		$provider = (string) $settings->get('provider');

		$steps = [
			[
				'title' => (string) $this->t('Choose an encryption key'),
				'detail' => $encrypted
					? (string) $this->t(
						'Frames are sealed before they leave this server. The settings page can make a key for you.',
					)
					: (string) $this->t('Encryption is off, so frames are stored in the clear.'),
				'done' => !$encrypted || $hasKey,
				'url' => $this->pathOf('strata.settings.storage'),
			],
			[
				'title' => (string) $this->t('Choose where backups go'),
				'detail' =>
					$provider === 'null'
						? (string) $this->t('The current choice keeps nothing.')
						: (string) $this->t(
							'A remote provider needs its own Strata submodule enabled.',
						),
				'done' => $provider !== '' && $provider !== 'null',
				'url' => $this->pathOf('strata.settings.storage'),
			],
			[
				'title' => (string) $this->t('Turn capture on'),
				'detail' => (string) $this->t('Nothing is watched until this is ticked.'),
				'done' => $capturing,
				'url' => $this->pathOf('strata.settings.storage'),
			],
			[
				'title' => (string) $this->t('Seal the first window'),
				'detail' => (string) $this->t(
					'Nothing has proved this works until one commit exists. Cron does it on its own schedule.',
				),
				'done' => $this->engine->commitIndex()->newest() !== null,
				'url' => $this->pathOf('strata_ui.flush'),
			],
		];

		foreach ($steps as $step) {
			if (!$step['done']) {
				return $steps;
			}
		}

		return [];
	}

	/**
	 * A route's path, or an empty string when this account cannot follow it.
	 *
	 * A step that links somewhere the reader gets a 403 on is worse than a step that only says what
	 * to do, so a denied route degrades to plain text rather than to a dead link.
	 *
	 * @param string $route
	 *   The route name.
	 *
	 * @return string
	 *   The path.
	 */
	private function pathOf(string $route): string
	{
		try {
			$url = Url::fromRoute($route);

			return $url->access() ? $url->toString() : '';
		} catch (Throwable) {
			return '';
		}
	}

	/**
	 * This module's rows on Drupal's status report.
	 *
	 * @return list<array{name: string, title: string, verdict: string, value: string, description: string, severity: string}>
	 *   One row per requirement, already flattened for a template.
	 */
	private function requirements(): array
	{
		$this->moduleHandler()->loadInclude('strata', 'install');

		try {
			$requirements = (array) strata_requirements('runtime');
		} catch (Throwable $error) {
			// the storage row probes the configured provider; a provider that raises instead of
			// answering must not take this page with it
			$this->unavailable = $error->getMessage();

			return [];
		}

		$rows = [];

		foreach ($requirements as $name => $requirement) {
			$severity = $requirement['severity'] ?? RequirementSeverity::OK;
			$severity =
				$severity instanceof RequirementSeverity ? $severity : RequirementSeverity::OK;

			$rows[] = [
				'name' => (string) $name,
				'title' => (string) $requirement['title'],
				'verdict' => $this->verdictOf($severity),
				'value' => (string) ($requirement['value'] ?? ''),
				'description' => (string) ($requirement['description'] ?? ''),
				'severity' => $this->severityName($severity),
			];
		}

		return $rows;
	}

	/**
	 * The numbers that say how much work is protected.
	 *
	 * Each one is a local read. Nothing here touches the storage provider, so opening this page on a
	 * site whose bucket is unreachable still answers with everything the local indexes know.
	 *
	 * @return list<array{label: string, value: string, note: string, severity: string, wide: bool}>
	 *   One card each. A wide card holds a value too long to set at the card size.
	 */
	private function figures(): array
	{
		try {
			$commits = $this->engine->commitIndex();
			$journal = $this->engine->journal();
			$statistics = $this->engine->frameIndex()->statistics();
			$newest = $commits->newest();
			$lag = $newest === null ? null : $this->engine->telemetryPass()->rpoLag();
		} catch (Throwable $error) {
			$this->unavailable = $error->getMessage();

			return [];
		}

		return [
			[
				'label' => (string) $this->t('Unprotected Work'),
				'value' =>
					$lag === null
						? (string) $this->t('Nothing captured yet')
						: Format::duration($lag),
				'note' => (string) $this->t('Lost if the host died now'),
				'severity' => $this->lagSeverity($lag),
				'wide' => false,
			],
			[
				'label' => (string) $this->t('Last Sealed'),
				'value' =>
					$newest === null
						? (string) $this->t('Never')
						: Format::moment((int) $newest['microtime']),
				'note' =>
					$newest === null
						? (string) $this->t('No commit has been written')
						: Format::address((string) $newest['id']),
				'severity' => 'ok',
				'wide' => $newest !== null,
			],
			[
				'label' => (string) $this->t('Restore Points'),
				'value' => Format::count($commits->count()),
				'note' => (string) $this->t('Commits this site can be put back to'),
				'severity' => 'ok',
				'wide' => false,
			],
			[
				'label' => (string) $this->t('Waiting to Seal'),
				'value' => Format::count($journal->pending()),
				'note' => (string) $this->t('@bytes captured but not yet written', [
					'@bytes' => Format::bytes($journal->pendingBytes()),
				]),
				'severity' => 'ok',
				'wide' => false,
			],
			[
				'label' => (string) $this->t('Stored'),
				'value' => Format::bytes($statistics['storedBytes']),
				'note' => (string) $this->t('@raw captured', [
					'@raw' => Format::bytes($statistics['rawBytes']),
				]),
				'severity' => 'ok',
				'wide' => false,
			],
			[
				'label' => (string) $this->t('Compression'),
				'value' => Format::ratio($statistics['ratio']),
				'note' => (string) $this->t('@frames frames held', [
					'@frames' => Format::count($statistics['frames']),
				]),
				'severity' => 'ok',
				'wide' => false,
			],
		];
	}

	/**
	 * How many finding codes are open at each severity.
	 *
	 * @return list<array{name: string, count: int}>
	 *   One entry per severity that has something open, worst first.
	 */
	private function findings(): array
	{
		try {
			$summary = $this->engine->ledger()->summary();
		} catch (Throwable) {
			return [];
		}

		$counts = [];

		foreach ($summary as $row) {
			$name = Finding::severities()[(int) $row['severity']] ?? 'UNKNOWN';
			$counts[$name] = ($counts[$name] ?? 0) + 1;
		}

		$open = [];

		foreach (array_reverse(Finding::severities(), true) as $name) {
			if (($counts[$name] ?? 0) > 0) {
				$open[] = ['name' => $name, 'count' => $counts[$name]];
			}
		}

		return $open;
	}

	/**
	 * What the newest restore drill proved.
	 *
	 * @return array{ran: bool, verdict: string, when: string, detail: string}
	 *   The verdict, or a marker saying none has run.
	 */
	private function drill(): array
	{
		try {
			$newest = $this->engine->drillIndex()->newest();
		} catch (Throwable) {
			$newest = null;
		}

		if ($newest === null) {
			return ['ran' => false, 'verdict' => '', 'when' => '', 'detail' => ''];
		}

		return [
			'ran' => true,
			'verdict' => (string) $newest['verdict'],
			'when' => gmdate('Y-m-d H:i:s', (int) $newest['created']),
			'detail' => (string) ($newest['detail'] ?? ''),
		];
	}

	/**
	 * Where to go next.
	 *
	 * @return list<array{title: string, url: string, description: string}>
	 *   Each destination, only those the account may reach.
	 */
	private function links(): array
	{
		$candidates = [
			[
				'route' => 'strata_ui.timeline',
				'title' => (string) $this->t('Timeline'),
				'description' => (string) $this->t('When the site changed and what changed'),
			],
			[
				'route' => 'strata_ui.diff',
				'title' => (string) $this->t('Diff'),
				'description' => (string) $this->t('What differs between two points'),
			],
			[
				'route' => 'strata_ui.health',
				'title' => (string) $this->t('Health'),
				'description' => (string) $this->t('Open findings and what a drill proved'),
			],
			[
				'route' => 'strata_ui.explorer',
				'title' => (string) $this->t('Storage Explorer'),
				'description' => (string) $this->t('What is stored and what a removal frees'),
			],
			[
				'route' => 'strata_ui.flush',
				'title' => (string) $this->t('Seal Now'),
				'description' => (string) $this->t(
					'Store what is captured without waiting for cron',
				),
			],
			[
				'route' => 'strata.settings.storage',
				'title' => (string) $this->t('Settings'),
				'description' => (string) $this->t('Where backups go and what is captured'),
			],
		];

		$links = [];

		foreach ($candidates as $candidate) {
			// strata_ui may be uninstalled with its rows still in the router table, and this page
			// belongs to the module that would be missing
			try {
				$url = Url::fromRoute($candidate['route']);

				if (!$url->access()) {
					continue;
				}

				$links[] = [
					'title' => $candidate['title'],
					'url' => $url->toString(),
					'description' => $candidate['description'],
				];
			} catch (Throwable) {
				continue;
			}
		}

		return $links;
	}

	#endregion

	#region Verdicts

	/**
	 * The one sentence at the top of the page.
	 *
	 * @param bool $capturing
	 *   Whether capture is switched on.
	 * @param RequirementSeverity $worst
	 *   The worst requirement severity.
	 *
	 * @return string
	 *   The sentence.
	 */
	private function verdict(bool $capturing, RequirementSeverity $worst): string
	{
		if (!$capturing) {
			return (string) $this->t('Capture is off, so nothing on this site is being backed up.');
		}

		return match ($worst) {
			RequirementSeverity::Error => (string) $this->t(
				'Capture is on but something below has to be fixed before a flush can succeed.',
			),
			RequirementSeverity::Warning => (string) $this->t(
				'Capture is on, and something below is worth a look.',
			),
			default => (string) $this->t('Capture is on and every check passes.'),
		};
	}

	/**
	 * The worst severity among the requirement rows.
	 *
	 * @param list<array{severity: string}> $requirements
	 *   The rows.
	 *
	 * @return RequirementSeverity
	 *   The worst one.
	 */
	private function worstOf(array $requirements): RequirementSeverity
	{
		$worst = RequirementSeverity::OK;

		foreach ($requirements as $requirement) {
			if ($requirement['severity'] === 'error') {
				return RequirementSeverity::Error;
			}
			if ($requirement['severity'] === 'warn') {
				$worst = RequirementSeverity::Warning;
			}
		}

		return $worst;
	}

	/**
	 * A severity enum as the class name a template uses.
	 *
	 * @param RequirementSeverity $severity
	 *   The severity.
	 *
	 * @return string
	 *   One of "ok", "warn" or "error".
	 */
	private function severityName(RequirementSeverity $severity): string
	{
		return match ($severity) {
			RequirementSeverity::Error => 'error',
			RequirementSeverity::Warning => 'warn',
			default => 'ok',
		};
	}

	/**
	 * A severity as the word shown on the check's badge.
	 *
	 * @param RequirementSeverity $severity
	 *   The severity.
	 *
	 * @return string
	 *   The word.
	 */
	private function verdictOf(RequirementSeverity $severity): string
	{
		return match ($severity) {
			RequirementSeverity::Error => (string) $this->t('Error'),
			RequirementSeverity::Warning => (string) $this->t('Warning'),
			default => (string) $this->t('OK'),
		};
	}

	/**
	 * How bad a given lag is.
	 *
	 * A site that has captured nothing has no lag rather than an infinite one; a fresh install has
	 * lost nothing and should not be alerting on its first page load.
	 *
	 * @param float|null $lag
	 *   Seconds, or NULL when nothing has been captured.
	 *
	 * @return string
	 *   One of "ok", "warn" or "error".
	 */
	private function lagSeverity(?float $lag): string
	{
		if ($lag === null) {
			return 'ok';
		}

		return match (true) {
			$lag >= self::ERROR_LAG => 'error',
			$lag >= self::WARN_LAG => 'warn',
			default => 'ok',
		};
	}

	#endregion
}
