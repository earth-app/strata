<?php

declare(strict_types=1);

namespace Drupal\strata\Event;

use Drupal\strata\Budget\BudgetAssessment;

/**
 * Stored bytes or projected spend crossed a configured ceiling.
 *
 * @see BudgetAssessment
 */
final class BudgetEvent extends StrataEvent
{
	/**
	 * Constructs an event.
	 *
	 * @param BudgetAssessment $assessment
	 *   What was measured and what the ladder decided to do about it.
	 */
	public function __construct(public readonly BudgetAssessment $assessment) {}

	/**
	 * {@inheritdoc}
	 */
	public function name(): string
	{
		return StrataEvents::BUDGET_BREACHED;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function body(): array
	{
		return $this->assessment->jsonSerialize();
	}
}
