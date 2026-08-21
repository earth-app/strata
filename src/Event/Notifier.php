<?php

declare(strict_types=1);

namespace Drupal\strata\Event;

use Drupal\strata\Budget\BudgetAssessment;
use Drupal\strata\Compaction\PruneReceipt;
use Drupal\strata\Drill\DrillReport;
use Drupal\strata\Flush\FlushResult;
use Drupal\strata\Health\Finding;
use Drupal\strata\Restore\RestoreResult;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * The one place the pipeline announces what it did.
 *
 * Exists so that the classes doing the work take a single optional collaborator instead of an event
 * dispatcher, a name and an event class each. A flusher should not know what Symfony is, and a unit
 * test of a flusher should not have to build a dispatcher to run.
 *
 * **A subscriber can never break the pipeline.** Every dispatch is wrapped: a listener that throws
 * is logged and swallowed. A notification is a side effect of a successful flush or restore, and
 * letting a broken webhook subscription turn a completed flush into a failed one would invert that.
 *
 * @see StrataEvents
 */
final class Notifier
{
	/**
	 * Constructs a notifier.
	 *
	 * @param EventDispatcherInterface|null $dispatcher
	 *   Where events go, or NULL to discard them, which is what a unit test passes.
	 * @param LoggerInterface|null $logger
	 *   Records a listener that threw.
	 */
	public function __construct(
		private readonly ?EventDispatcherInterface $dispatcher = null,
		private readonly ?LoggerInterface $logger = null,
	) {}

	/**
	 * A flush sealed a window.
	 *
	 * @param FlushResult $result
	 *   What it did.
	 * @param string $site
	 *   The site it was appended to.
	 */
	public function commitSealed(FlushResult $result, string $site = ''): void
	{
		if ($result->commit === null) {
			return;
		}

		$this->fire(new CommitEvent($result, $site));
	}

	/**
	 * A restore finished.
	 *
	 * @param RestoreResult $result
	 *   What it did.
	 * @param string $mode
	 *   Either "logical" or "physical".
	 * @param string $scope
	 *   The scope that was asked for.
	 * @param int|null $actor
	 *   Who ran it.
	 */
	public function restoreFinished(
		RestoreResult $result,
		string $mode = 'logical',
		string $scope = '',
		?int $actor = null,
	): void {
		$this->fire(new RestoreEvent($result, $mode, $scope, $actor));
	}

	/**
	 * A tripwire recorded a finding.
	 *
	 * @param Finding $finding
	 *   What was found.
	 * @param string $rung
	 *   The rung its code sits on.
	 */
	public function findingRecorded(Finding $finding, string $rung = 'observe'): void
	{
		$this->fire(new HealthEvent($finding, $rung));
	}

	/**
	 * A budget ceiling was crossed.
	 *
	 * @param BudgetAssessment $assessment
	 *   What was measured.
	 */
	public function budgetBreached(BudgetAssessment $assessment): void
	{
		if (!$assessment->isOverBudget()) {
			return;
		}

		$this->fire(new BudgetEvent($assessment));
	}

	/**
	 * A prune destroyed restore points.
	 *
	 * @param PruneReceipt $receipt
	 *   What it removed.
	 */
	public function pruneApplied(PruneReceipt $receipt): void
	{
		if (!$receipt->applied && !$receipt->wasRefused()) {
			return;
		}

		$this->fire(new PruneEvent($receipt));
	}

	/**
	 * A restore drill finished.
	 *
	 * @param DrillReport $report
	 *   What it found.
	 */
	public function drillFinished(DrillReport $report): void
	{
		$this->fire(new DrillEvent($report));
	}

	/**
	 * Dispatches one event, swallowing whatever a listener does with it.
	 *
	 * @param StrataEvent $event
	 *   The event.
	 */
	private function fire(StrataEvent $event): void
	{
		if ($this->dispatcher === null) {
			return;
		}

		try {
			$this->dispatcher->dispatch($event, $event->name());
		} catch (Throwable $e) {
			$this->logger?->error('A Strata @event listener threw: @message', [
				'@event' => $event->name(),
				'@message' => $e->getMessage(),
			]);
		}
	}
}
