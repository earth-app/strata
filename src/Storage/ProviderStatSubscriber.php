<?php

declare(strict_types=1);

namespace Drupal\strata\Storage;

use Drupal\strata\Engine;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * Writes this request's store traffic into the per-day record once the response is out.
 *
 * Terminate rather than response: the write is bookkeeping and the visitor should not wait on it.
 * A request that never touched the store leaves an empty accumulator and costs no query, so the
 * subscriber is registered unconditionally.
 *
 * A failure here is logged and swallowed. Losing a day's request count is a gap in a cost report;
 * letting the same failure out of a terminate listener would be a fatal on a page that has already
 * been sent.
 *
 * @see ProviderStatStore
 * @see RecordingProvider
 */
final class ProviderStatSubscriber implements EventSubscriberInterface
{
	/**
	 * Constructs the subscriber.
	 *
	 * @param Engine $engine
	 *   The engine holding the accumulator.
	 * @param LoggerInterface $logger
	 *   Where a failed write is reported.
	 */
	public function __construct(
		private readonly Engine $engine,
		private readonly LoggerInterface $logger,
	) {}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, string>
	 *   The events this subscriber listens to.
	 */
	public static function getSubscribedEvents(): array
	{
		return [KernelEvents::TERMINATE => 'onTerminate'];
	}

	/**
	 * Persists whatever the request cost.
	 *
	 * @param TerminateEvent $event
	 *   The terminate event, unused; the accumulator is on the engine.
	 */
	public function onTerminate(TerminateEvent $event): void
	{
		try {
			$this->engine->persistProviderStats();
		} catch (Throwable $error) {
			$this->logger->warning(
				'Storage request counts for this request were not recorded: @why',
				[
					'@why' => $error->getMessage(),
				],
			);
		}
	}
}
