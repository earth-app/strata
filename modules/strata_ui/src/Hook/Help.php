<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Page-level help for each report.
 *
 * Each page's help answers the question that page raises rather than describing what is on the
 * screen. The timeline's is what an interval does and does not control; the diff's is why values are
 * gated behind a permission; the health page's is what the ledger cannot notice on its own.
 *
 * Returned as a list of short sentences rather than one paragraph, because each has to be a single
 * string literal for the translation extractor to find it.
 */
final class Help
{
	use StringTranslationTrait;

	/**
	 * Implements hook_help().
	 *
	 * @param string $routeName
	 *   The current route.
	 * @param RouteMatchInterface $routeMatch
	 *   The route match.
	 *
	 * @return array<string, mixed>
	 *   A render array, empty for a route with no help.
	 */
	#[Hook('help')]
	public function forRoute(string $routeName, RouteMatchInterface $routeMatch): array
	{
		$points = $this->points($routeName);

		return $points === [] ? [] : ['#theme' => 'item_list', '#items' => $points];
	}

	/**
	 * What one page's help says.
	 *
	 * @param string $routeName
	 *   The current route.
	 *
	 * @return list<string>
	 *   One sentence each, empty for a route with no help.
	 */
	private function points(string $routeName): array
	{
		return match ($routeName) {
			'help.page.strata_ui' => [
				(string) $this->t(
					'Adds the Strata reports: timeline, diff, graphs, explorer, health, branches.',
				),
				(string) $this->t('It captures nothing and stores nothing of its own.'),
				(string) $this->t('Uninstalling it leaves the stored history untouched.'),
			],
			'strata_ui.status' => [
				(string) $this->t('The checks below are the ones on the Drupal status report.'),
				(string) $this->t('Unprotected work is what a host failure right now would cost.'),
				(string) $this->t('Nothing on this page reaches the storage provider twice.'),
			],
			'strata_ui.timeline' => [
				(string) $this->t(
					'Each bar is a window of captured operations, and links to its commits.',
				),
				(string) $this->t(
					'The flush interval is how much is lost if the host dies before a flush.',
				),
				(string) $this->t('It does not coarsen what a restore can target.'),
				(string) $this->t('Operations inside a window stay individually addressable.'),
			],
			'strata_ui.diff', 'strata_ui.diff.pair' => [
				(string) $this->t('Field names are shown to anyone who may see diffs.'),
				(string) $this->t('The values themselves need the payload permission.'),
				(string) $this->t(
					'Stored values are the content of the site, including unpublished work.',
				),
			],
			'strata_ui.graphs' => [
				(string) $this->t('Each measurement has its own axis, because they share no unit.'),
				(string) $this->t('Deduplication ratio and chain depth are whole-store readings.'),
				(string) $this->t(
					'Both are drawn flat, because no single bucket owns either of them.',
				),
			],
			'strata_ui.explorer' => [
				(string) $this->t('What a deletion frees is rarely the size of the thing deleted.'),
				(string) $this->t('A frame can be shared by many commits, or be a delta anchor.'),
				(string) $this->t('This page prices a removal by what would become unreachable.'),
			],
			'strata_ui.branches' => [
				(string) $this->t(
					'A branch is a ref, and it carries the configuration realm only.',
				),
				(string) $this->t(
					'Configuration is captured whole, so merging it is well defined.',
				),
				(string) $this->t('Content and rows are captured as deltas against a parent.'),
				(string) $this->t(
					'Merging two divergent delta chains would mean inventing values.',
				),
				(string) $this->t('Flush onto a branch with drush strata:flush --ref=heads/NAME.'),
			],
			'strata_ui.merge' => [
				(string) $this->t(
					'Every object one side or the other touched is listed before you.',
				),
				(string) $this->t('Two edits to different keys of one object both survive.'),
				(string) $this->t(
					'Two edits to the same key stop the merge and print both values.',
				),
				(string) $this->t('A snapshot is captured before anything is written, always.'),
			],
			'strata_ui.health' => [
				(string) $this->t('Findings are collapsed to one row per code.'),
				(string) $this->t('Observe, reindex, refetch and rebuild run on cron.'),
				(string) $this->t('Quarantine and refuse never do; both need a person.'),
				(string) $this->t(
					'Silent corruption needs a deep verify, which reads every frame back.',
				),
			],
			'strata_ui.estimate' => [
				(string) $this->t('Every constant here was measured through the real pipeline.'),
				(string) $this->t(
					'Two thirds of a typical write volume is access-timestamp churn.',
				),
				(string) $this->t(
					'How that one field is treated moves the total more than site size.',
				),
			],
			'strata_ui.flush' => [
				(string) $this->t(
					'Sealing writes what is captured; it never reads the site again.',
				),
				(string) $this->t('Cron does the same thing whenever a flush bound is reached.'),
				(string) $this->t(
					'One commit has to exist before any report has anything to show.',
				),
			],
			default => [],
		};
	}
}
