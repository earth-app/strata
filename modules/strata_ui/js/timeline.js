/**
 * @file
 * Wheel-zoom and drag-pan for the timeline.
 *
 * Every navigation the timeline offers is already a link, and the bars are links too, so the page is
 * fully usable - and fully keyboard navigable - with this file absent. What it adds is the two
 * gestures a chart invites and links cannot express.
 *
 * Both gestures resolve to a normal navigation rather than an in-place redraw. Redrawing would mean
 * shipping the bucketing arithmetic twice, once here and once in PHP, and the two would drift.
 */

((Drupal, once) => {
	/**
	 * How much of the window one wheel notch zooms.
	 */
	const ZOOM_STEP = 0.2;

	/**
	 * Pixels a pointer must travel before a drag counts as a pan rather than a click.
	 *
	 * Below this a drag is somebody clicking a bar with a slightly unsteady hand, and treating it as
	 * a pan would swallow the click.
	 */
	const DRAG_THRESHOLD = 8;

	/**
	 * Navigates to a window.
	 *
	 * @param {number} from
	 *   Inclusive start, in unix microseconds.
	 * @param {number} to
	 *   Inclusive end, in unix microseconds.
	 */
	const go = (from, to) => {
		const url = new URL(window.location.href);

		url.searchParams.set('from', String(Math.round(from)));
		url.searchParams.set('to', String(Math.round(to)));
		url.searchParams.delete('resolution');
		window.location.assign(url.toString());
	};

	/**
	 * The window a chart is currently showing.
	 *
	 * @param {Element} chart
	 *   The chart element.
	 *
	 * @return {{from: number, to: number, span: number}|null}
	 *   The window, or null when the chart carries no usable one.
	 */
	const windowOf = (chart) => {
		const from = Number.parseInt(chart.dataset.strataFrom || '', 10);
		const to = Number.parseInt(chart.dataset.strataTo || '', 10);

		if (!Number.isFinite(from) || !Number.isFinite(to) || to <= from) {
			return null;
		}

		return { from, to, span: to - from };
	};

	Drupal.behaviors.strataTimeline = {
		attach(context) {
			once('strata-timeline', '[data-strata-timeline]', context).forEach((chart) => {
				let anchor = null;

				chart.addEventListener(
					'wheel',
					(event) => {
						const current = windowOf(chart);

						if (current === null || event.ctrlKey) {
							return;
						}

						event.preventDefault();

						// zooming around the pointer keeps whatever is under the cursor under it
						const box = chart.getBoundingClientRect();
						const at = box.width > 0 ? (event.clientX - box.left) / box.width : 0.5;
						const factor = event.deltaY > 0 ? 1 + ZOOM_STEP : 1 - ZOOM_STEP;
						const span = current.span * factor;
						const focus = current.from + current.span * at;

						go(focus - span * at, focus + span * (1 - at));
					},
					{ passive: false }
				);

				chart.addEventListener('pointerdown', (event) => {
					anchor = { x: event.clientX, window: windowOf(chart) };
				});

				chart.addEventListener('pointerup', (event) => {
					if (anchor === null || anchor.window === null) {
						anchor = null;

						return;
					}

					const travelled = anchor.x - event.clientX;

					if (Math.abs(travelled) < DRAG_THRESHOLD) {
						anchor = null;

						return;
					}

					// a real drag is a pan, so the click it would otherwise have been is suppressed
					event.preventDefault();

					const box = chart.getBoundingClientRect();
					const shift = box.width > 0 ? (travelled / box.width) * anchor.window.span : 0;

					go(anchor.window.from + shift, anchor.window.to + shift);
					anchor = null;
				});

				chart.addEventListener('pointerleave', () => {
					anchor = null;
				});
			});
		}
	};
})(Drupal, once);
