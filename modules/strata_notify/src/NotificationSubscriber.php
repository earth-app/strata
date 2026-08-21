<?php

declare(strict_types=1);

namespace Drupal\strata_notify;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\strata\Event\DrillEvent;
use Drupal\strata\Event\HealthEvent;
use Drupal\strata\Event\RestoreEvent;
use Drupal\strata\Event\StrataEvent;
use Drupal\strata\Event\StrataEvents;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

/**
 * Mails what the policy says is worth mailing.
 *
 * **The webhook leg is not here.** The top-level module already queues a delivery for every
 * subscription that wants an event, so a second dispatcher would double every payload. This module
 * adds the mail channel and nothing else.
 *
 * **Mail is sent inline, and a failure is swallowed.** Drupal's mail manager is synchronous, so a
 * slow SMTP host does add to the request that sealed the commit; queueing it would trade that for a
 * notification that arrives only when cron next runs, which for a failed restore is the wrong
 * trade. What is not negotiable is that a mail failure cannot fail the operation it is reporting -
 * a restore that succeeded must not be recorded as failed because the mail server was down.
 *
 * @see NotificationPolicy
 */
final class NotificationSubscriber implements EventSubscriberInterface
{
	/**
	 * The mail module key.
	 */
	public const MODULE = 'strata_notify';

	/**
	 * Constructs a subscriber.
	 *
	 * @param NotificationPolicy $policy
	 *   Decides what is worth sending.
	 * @param MailManagerInterface $mail
	 *   Sends it.
	 * @param LanguageManagerInterface $languages
	 *   Supplies the language a notification is composed in.
	 * @param LoggerInterface $logger
	 *   Records a notification that could not be sent.
	 */
	public function __construct(
		private readonly NotificationPolicy $policy,
		private readonly MailManagerInterface $mail,
		private readonly LanguageManagerInterface $languages,
		private readonly LoggerInterface $logger,
	) {}

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
	 * Mails one event, if the policy wants it.
	 *
	 * @param StrataEvent $event
	 *   The event.
	 */
	public function onEvent(StrataEvent $event): void
	{
		if (!$this->policy->wants($event)) {
			return;
		}

		$language = $this->languages->getDefaultLanguage()->getId();

		foreach ($this->policy->recipients() as $address) {
			try {
				$this->mail->mail(self::MODULE, $this->key($event), $address, $language, [
					'strata_event' => $event,
					'subject' => $this->subject($event),
				]);
			} catch (Throwable $error) {
				// a broken mail host must never turn a completed flush or restore into a failure
				$this->logger->error('Strata could not notify @address about @event: @message', [
					'@address' => $address,
					'@event' => $event->name(),
					'@message' => $error->getMessage(),
				]);
			}
		}
	}

	/**
	 * The mail key one event is composed under.
	 *
	 * @param StrataEvent $event
	 *   The event.
	 *
	 * @return string
	 *   The key, which is the last segment of the event name.
	 */
	public function key(StrataEvent $event): string
	{
		$parts = explode('.', $event->name());

		return count($parts) > 1 ? $parts[1] : $event->name();
	}

	/**
	 * The subject line for one event.
	 *
	 * The outcome is in the subject rather than only in the body, because a notification an operator
	 * has to open to learn whether it is bad news is a notification they will start leaving unopened.
	 *
	 * @param StrataEvent $event
	 *   The event.
	 *
	 * @return string
	 *   The subject.
	 */
	public function subject(StrataEvent $event): string
	{
		return match (true) {
			$event instanceof RestoreEvent => sprintf(
				'Strata: restore %s (%s)',
				$event->result->outcome(),
				$event->scope === '' ? 'every realm' : $event->scope,
			),
			$event instanceof HealthEvent => sprintf(
				'Strata: %s %s',
				$event->finding->severityName(),
				$event->finding->code,
			),
			$event instanceof DrillEvent => sprintf(
				'Strata: restore drill %s',
				$event->report->verdict(),
			),
			default => sprintf('Strata: %s', $event->name()),
		};
	}
}
