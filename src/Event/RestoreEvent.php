<?php

declare(strict_types=1);

namespace Drupal\strata\Event;

use Drupal\strata\Restore\RestoreResult;

/**
 * A restore finished.
 *
 * Dispatched whatever the outcome, including a refusal, because a refused rollback is the case an
 * operator most needs to hear about and the one a success-only channel would swallow.
 *
 * @see RestoreResult
 */
final class RestoreEvent extends StrataEvent
{
	/**
	 * Constructs an event.
	 *
	 * @param RestoreResult $result
	 *   What the restore did.
	 * @param string $mode
	 *   Either "logical" or "physical".
	 * @param string $scope
	 *   The scope that was asked for.
	 * @param int|null $actor
	 *   The account that ran it, or NULL for a command line run.
	 */
	public function __construct(
		public readonly RestoreResult $result,
		public readonly string $mode = 'logical',
		public readonly string $scope = '',
		public readonly ?int $actor = null,
	) {}

	/**
	 * {@inheritdoc}
	 */
	public function name(): string
	{
		return StrataEvents::RESTORE_FINISHED;
	}

	/**
	 * Whether this restore is one an operator should be paged about.
	 *
	 * @return bool
	 *   TRUE when it was refused, or wrote nothing it was asked to write.
	 */
	public function isFailure(): bool
	{
		return $this->result->wasRefused() || $this->result->failed !== [];
	}

	/**
	 * {@inheritdoc}
	 */
	protected function body(): array
	{
		return [
			'mode' => $this->mode,
			'scope' => $this->scope,
			'actor' => $this->actor,
			'outcome' => $this->result->outcome(),
			'target' => $this->result->target,
			'snapshot' => $this->result->snapshot,
			'restored' => count($this->result->restored),
			'skipped' => count($this->result->skipped),
			'failed' => count($this->result->failed),
			'refused' => $this->result->refused,
			'seconds' => round($this->result->seconds, 4),
		];
	}
}
