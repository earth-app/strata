<?php

declare(strict_types=1);

namespace Drupal\strata\Event;

use Drupal\strata\Compaction\PruneReceipt;

/**
 * A prune destroyed restore points.
 *
 * Dispatched for an applied prune and for a refused one, and not for a dry run. A dry run answers a
 * question; the other two changed what the site can recover to.
 *
 * @see PruneReceipt
 */
final class PruneEvent extends StrataEvent
{
	/**
	 * Constructs an event.
	 *
	 * @param PruneReceipt $receipt
	 *   What the prune removed, or why it refused to.
	 */
	public function __construct(public readonly PruneReceipt $receipt) {}

	/**
	 * {@inheritdoc}
	 */
	public function name(): string
	{
		return StrataEvents::PRUNE_APPLIED;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function body(): array
	{
		return [
			'applied' => $this->receipt->applied,
			'refused' => $this->receipt->refused,
			'frames' => count($this->receipt->frames),
			'objects' => count($this->receipt->objects),
			'commits' => count($this->receipt->commits),
			'kept' => $this->receipt->keptCount(),
			'bytes' => $this->receipt->bytes,
			'seconds' => round($this->receipt->seconds, 4),
		];
	}
}
