<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Controller;

use Drupal\Core\Url;
use Drupal\strata\Metrics\SeriesBuilder;
use Drupal\strata\Timeline\TimelineWindow;
use Drupal\strata_ui\Render\SeriesChart;
use Symfony\Component\HttpFoundation\Request;

/**
 * Six measurements over time, each on its own axis.
 *
 * **Two of the six are whole-store readings drawn flat, and the page says so next to them.** A
 * deduplication ratio and a delta-chain depth are properties of the frame index rather than of a
 * moment: a frame written today can be referenced by a commit from last year, so there is no bucket
 * it belongs to. Apportioning them per bucket would produce a moving line with nothing behind it,
 * which is worse than a flat one that is true.
 *
 * @see SeriesBuilder
 * @see SeriesChart
 */
final class GraphController extends StrataControllerBase
{
	/**
	 * The graphs.
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
			$series = $this->engine->series()->build($window);

			return [
				'#theme' => 'strata_graphs',
				'#charts' => (new SeriesChart())->buildAll($series),
				'#window' => $window->jsonSerialize(),
				'#flat_note' => (string) $this->t(
					'Deduplication ratio and chain depth are whole-store readings, so they are drawn flat.',
				),
				'#timeline_url' => Url::fromRoute('strata_ui.timeline')->toString(),
				'#empty' => $this->engine->timeline()->totals($window)['commits'] === 0,
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

		return TimelineWindow::spanning($from, $to);
	}
}
