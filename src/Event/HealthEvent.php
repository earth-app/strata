<?php

declare(strict_types=1);

namespace Drupal\strata\Event;

use Drupal\strata\Health\Finding;

/**
 * A tripwire recorded a finding.
 *
 * Carries the finding rather than a summary of it, so a subscriber can route on severity and code
 * without re-reading the ledger.
 *
 * @see Finding
 */
final class HealthEvent extends StrataEvent
{
	/**
	 * Constructs an event.
	 *
	 * @param Finding $finding
	 *   What was found.
	 * @param string $rung
	 *   The repair rung the ladder puts this code on.
	 */
	public function __construct(
		public readonly Finding $finding,
		public readonly string $rung = 'observe',
	) {}

	/**
	 * {@inheritdoc}
	 */
	public function name(): string
	{
		return StrataEvents::HEALTH_FINDING;
	}

	/**
	 * Whether this finding is one a human has to act on.
	 *
	 * @return bool
	 *   TRUE at ERROR and above.
	 */
	public function needsAttention(): bool
	{
		return $this->finding->severity >= Finding::ERROR;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function body(): array
	{
		return [
			'code' => $this->finding->code,
			'severity' => $this->finding->severity,
			'severity_name' => $this->finding->severityName(),
			'scope' => $this->finding->scope,
			'context' => $this->finding->context,
			'rung' => $this->rung,
		];
	}
}
