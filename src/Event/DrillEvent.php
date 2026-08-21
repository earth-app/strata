<?php

declare(strict_types=1);

namespace Drupal\strata\Event;

use Drupal\strata\Drill\DrillReport;

/**
 * A restore drill finished.
 *
 * Dispatched whatever the verdict. A drill that passed is the signal an operator wants to see
 * arriving on schedule, and its absence is itself information; a channel that only carried failures
 * would make a silent drill indistinguishable from a healthy one.
 *
 * @see DrillReport
 */
final class DrillEvent extends StrataEvent
{
	/**
	 * Constructs an event.
	 *
	 * @param DrillReport $report
	 *   What the drill found.
	 */
	public function __construct(public readonly DrillReport $report) {}

	/**
	 * {@inheritdoc}
	 */
	public function name(): string
	{
		return StrataEvents::DRILL_FINISHED;
	}

	/**
	 * Whether this drill is one an operator should be paged about.
	 *
	 * @return bool
	 *   TRUE when it did not pass.
	 */
	public function isFailure(): bool
	{
		return !$this->report->passed();
	}

	/**
	 * {@inheritdoc}
	 */
	protected function body(): array
	{
		return $this->report->jsonSerialize();
	}
}
