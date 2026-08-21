<?php

declare(strict_types=1);

namespace Drupal\strata\Event\Webhook;

use Drupal\strata\Event\StrataEvent;
use Drupal\strata\Event\StrataEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Turns every Strata event into queued webhook deliveries.
 *
 * One listener for every event rather than one per event, because a webhook subscription filters by
 * name itself and the dispatcher does not care which event it is queueing. A new event on
 * `StrataEvents` is therefore deliverable without this class changing.
 *
 * @see WebhookDispatcher
 * @see StrataEvents
 */
final class WebhookSubscriber implements EventSubscriberInterface
{
	/**
	 * Constructs a subscriber.
	 *
	 * @param WebhookDispatcher $dispatcher
	 *   Queues the deliveries.
	 */
	public function __construct(private readonly WebhookDispatcher $dispatcher) {}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, string>
	 *   Event name keyed to the method that handles it.
	 */
	public static function getSubscribedEvents(): array
	{
		$events = [];

		foreach (StrataEvents::all() as $name) {
			$events[$name] = 'onEvent';
		}

		return $events;
	}

	/**
	 * Queues one event.
	 *
	 * @param StrataEvent $event
	 *   The event.
	 */
	public function onEvent(StrataEvent $event): void
	{
		$this->dispatcher->dispatch($event);
	}
}
