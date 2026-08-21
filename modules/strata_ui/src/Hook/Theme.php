<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\strata_ui\Render\SeriesChart;
use Drupal\strata_ui\Render\TimelineChart;

/**
 * The templates this module ships.
 *
 * Every chart is drawn by a Twig template from geometry a PHP class computed, rather than by a class
 * that concatenates SVG. That keeps the arithmetic unit-testable, keeps the markup themeable - which
 * is what makes the dark mode and the contrast work overridable rather than hard-coded - and means no
 * markup is ever built by string concatenation and handed to a renderer.
 *
 * @see TimelineChart
 * @see SeriesChart
 */
final class Theme
{
	/**
	 * Implements hook_theme().
	 *
	 * @return array<string, array<string, mixed>>
	 *   Theme hook definitions.
	 */
	#[Hook('theme')]
	public function hooks(): array
	{
		return [
			'strata_timeline' => [
				'variables' => [
					'chart' => [],
					'window' => [],
					'summary' => [],
					'graphs_url' => '',
					'empty' => true,
				],
			],
			'strata_graphs' => [
				'variables' => [
					'charts' => [],
					'window' => [],
					'flat_note' => '',
					'timeline_url' => '',
					'empty' => true,
				],
			],
			'strata_diff' => [
				'variables' => [
					'from' => '',
					'to' => '',
					'subjects' => [],
					'examined' => '',
					'truncated' => false,
					'truncation_note' => '',
					'payloads' => false,
					'realms' => [],
					'timeline_url' => '',
				],
			],
			'strata_explorer' => [
				'variables' => [
					'totals' => [],
					'reachable' => [],
					'by_realm' => [],
					'complete' => true,
					'collectable' => null,
					'collectable_bytes' => '',
					'waste_share' => '',
					'incomplete_note' => '',
					'realm_note' => '',
					'largest' => [],
					'prune_url' => '',
					'summary' => '',
				],
			],
			'strata_health' => [
				'variables' => [
					'findings' => [],
					'counts' => [],
					'clean' => true,
					'drills' => [],
					'anomalies' => [],
					'automatic_note' => '',
					'verify_note' => '',
					'timeline_url' => '',
				],
			],
			'strata_status' => [
				'variables' => ['rows' => [], 'severity' => 'ok', 'url' => ''],
			],
		];
	}
}
