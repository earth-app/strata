<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Controller;

use Drupal\Core\Url;
use Drupal\strata\Timeline\TimelineBucket;
use Drupal\strata\Timeline\TimelineWindow;
use Drupal\strata_ui\Render\Format;
use Drupal\strata_ui\Render\TimelineChart;
use Symfony\Component\HttpFoundation\Request;

/**
 * The timeline, and the commits inside one of its buckets.
 *
 * **The window comes from the query string, and an absent one means the whole history.** That makes
 * every view of the timeline a URL someone can bookmark, link to in an incident channel, or open
 * twice side by side - which is what an operator comparing two periods actually does. It also means
 * the zoom and pan controls are plain links and the page needs no scripting to be usable.
 *
 * A requested window is clamped rather than trusted. The resolution is derived from the span by
 * `TimelineWindow`, so a query string asking for per-second buckets across a year cannot make this
 * page try to build thirty million of them.
 *
 * @see TimelineChart
 * @see TimelineWindow
 */
final class TimelineController extends StrataControllerBase
{
	/**
	 * The timeline.
	 *
	 * @param Request $request
	 *   The request, whose query string carries the window.
	 *
	 * @return array<string, mixed>
	 *   The page.
	 */
	public function page(Request $request): array
	{
		$window = $this->window($request);

		return $this->guard(function () use ($window): array {
			$timeline = $this->engine->timeline();
			$buckets = $timeline->buckets($window);
			$totals = $timeline->totals($window);

			return [
				'#theme' => 'strata_timeline',
				'#chart' => (new TimelineChart())->build($window, $buckets),
				'#window' => $window->jsonSerialize(),
				'#summary' => [
					'commits' => Format::count($totals['commits']),
					'operations' => Format::count($totals['operations']),
					'raw' => Format::bytes($totals['raw_bytes']),
					'stored' => Format::bytes($totals['stored_bytes']),
					'anchors' => Format::count($totals['anchors']),
					'ratio' => Format::ratio(
						$totals['stored_bytes'] > 0
							? $totals['raw_bytes'] / $totals['stored_bytes']
							: 1.0,
					),
				],
				'#graphs_url' => Url::fromRoute('strata_ui.graphs')->toString(),
				'#empty' => $totals['commits'] === 0,
			];
		});
	}

	/**
	 * The commits inside one bucket.
	 *
	 * @param int $start
	 *   The bucket's start, in unix seconds.
	 * @param int $resolution
	 *   The bucket width in seconds.
	 *
	 * @return array<string, mixed>
	 *   The page.
	 */
	public function bucket(int $start, int $resolution): array
	{
		$width = max(1, $resolution);
		$from = $start * TimelineWindow::MICROS_PER_SECOND;
		$to = $from + $width * TimelineWindow::MICROS_PER_SECOND - 1;

		return $this->guard(function () use ($from, $to): array {
			$rows = [];

			foreach ($this->engine->timeline()->commitsIn(new TimelineBucket($from, $to)) as $row) {
				$rows[] = $this->commitRow($row);
			}

			return [
				'#type' => 'container',
				'window' => [
					'#markup' => $this->t('@from to @to', [
						'@from' => Format::moment($from),
						'@to' => Format::moment($to),
					]),
				],
				'commits' => [
					'#type' => 'table',
					'#header' => [
						$this->t('Sealed'),
						$this->t('Commit'),
						$this->t('Covers'),
						$this->t('Operations'),
						$this->t('Stored'),
						$this->t('Ratio'),
						$this->t('Actions'),
					],
					'#rows' => $rows,
					'#empty' => $this->t('No commit was sealed in this window.'),
				],
				'back' => [
					'#type' => 'link',
					'#title' => $this->t('Back to the Timeline'),
					'#url' => Url::fromRoute('strata_ui.timeline'),
				],
			];
		});
	}

	/**
	 * The window a request asks for.
	 *
	 * @param Request $request
	 *   The request.
	 *
	 * @return TimelineWindow
	 *   The window, or one spanning the whole history when none was asked for.
	 */
	private function window(Request $request): TimelineWindow
	{
		$from = $request->query->getInt('from');
		$to = $request->query->getInt('to');

		if ($from <= 0 || $to <= $from) {
			return $this->engine->timeline()->fullHistory($this->time->getRequestTime());
		}

		$resolution = $request->query->getInt('resolution');

		return $resolution > 0
			? new TimelineWindow(
				$from,
				$to,
				min($resolution, TimelineWindow::LADDER[count(TimelineWindow::LADDER) - 1]),
			)
			: TimelineWindow::spanning($from, $to);
	}

	/**
	 * One row of the commit table.
	 *
	 * @param array<string, mixed> $row
	 *   A commit index row.
	 *
	 * @return array<int, mixed>
	 *   The rendered cells.
	 */
	private function commitRow(array $row): array
	{
		$id = (string) $row['id'];
		$raw = (int) $row['raw_bytes'];
		$stored = (int) $row['stored_bytes'];

		return [
			Format::moment((int) $row['microtime']),
			Format::address($id),
			(string) $row['label'],
			Format::count((int) $row['operations']),
			Format::bytes($stored),
			Format::ratio($stored > 0 ? $raw / $stored : 1.0),
			[
				'data' => [
					'#type' => 'operations',
					'#links' => $this->operations($id),
				],
			],
		];
	}

	/**
	 * The actions offered against one commit.
	 *
	 * @param string $id
	 *   The commit id.
	 *
	 * @return array<string, array<string, mixed>>
	 *   Operation links.
	 */
	private function operations(string $id): array
	{
		return [
			'rollback' => [
				'title' => $this->t('Roll Back Here'),
				'url' => Url::fromRoute('strata_ui.rollback', ['commit' => $id]),
			],
			'explore' => [
				'title' => $this->t('What It Holds'),
				'url' => Url::fromRoute('strata_ui.explorer.commit', ['commit' => $id]),
			],
			'quarantine' => [
				'title' => $this->t('Quarantine'),
				'url' => Url::fromRoute('strata_ui.quarantine', ['commit' => $id]),
			],
		];
	}
}
