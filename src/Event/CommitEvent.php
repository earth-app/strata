<?php

declare(strict_types=1);

namespace Drupal\strata\Event;

use Drupal\strata\Flush\FlushResult;

/**
 * A flush sealed a window into a commit.
 *
 * Dispatched only when a flush actually wrote something. A flush that found nothing pending is not
 * an event; a site that fired one every fifteen seconds on an idle site would teach its operators
 * to ignore the channel.
 *
 * @see FlushResult
 */
final class CommitEvent extends StrataEvent
{
	/**
	 * Constructs an event.
	 *
	 * @param FlushResult $result
	 *   What the flush did.
	 * @param string $site
	 *   The site whose history it was appended to.
	 */
	public function __construct(
		public readonly FlushResult $result,
		public readonly string $site = '',
	) {}

	/**
	 * {@inheritdoc}
	 */
	public function name(): string
	{
		return StrataEvents::COMMIT_SEALED;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function body(): array
	{
		return [
			'site' => $this->site,
			'commit' => $this->result->commit,
			'segment' => $this->result->segment,
			'operations' => $this->result->captured,
			'raw_bytes' => $this->result->rawBytes,
			'stored_bytes' => $this->result->stored,
			'seconds' => round($this->result->seconds, 4),
		];
	}
}
