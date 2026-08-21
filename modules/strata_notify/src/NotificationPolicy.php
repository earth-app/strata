<?php

declare(strict_types=1);

namespace Drupal\strata_notify;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\strata\Event\StrataEvent;
use Drupal\strata\Event\StrataEvents;
use Drupal\strata\Health\Finding;

/**
 * Decides whether one event is worth an email.
 *
 * **A channel that notifies too much is a channel nobody reads**, which is worse than no channel:
 * the operator who filtered Strata's mail into a folder in week one is the operator who misses the
 * failed restore in week nine. So the defaults are deliberately narrow - failures, budget breaches,
 * drills and findings at ERROR or above - and the noisy events are off until somebody asks for them.
 *
 * A commit event is off by default for the same reason. A site flushing every fifteen seconds would
 * send 5,760 mails a day, and the one that mattered would be indistinguishable from the rest.
 *
 * **A restore that succeeded is still notified.** Unlike a flush, a restore is rare, deliberate and
 * consequential, and somebody other than the person who ran it usually needs to know it happened.
 *
 * @see StrataEvents
 * @see NotificationSubscriber
 */
final class NotificationPolicy
{
	/**
	 * The configuration object this reads.
	 */
	public const CONFIG = 'strata_notify.settings';

	/**
	 * Which setting key gates each event.
	 *
	 * @var array<string, string>
	 */
	public const GATES = [
		StrataEvents::RESTORE_FINISHED => 'restore',
		StrataEvents::BUDGET_BREACHED => 'budget',
		StrataEvents::HEALTH_FINDING => 'health',
		StrataEvents::DRILL_FINISHED => 'drill',
		StrataEvents::PRUNE_APPLIED => 'prune',
		StrataEvents::COMMIT_SEALED => 'commit',
	];

	/**
	 * Constructs a policy.
	 *
	 * @param ConfigFactoryInterface $configFactory
	 *   Where the settings are read from.
	 */
	public function __construct(private readonly ConfigFactoryInterface $configFactory) {}

	/**
	 * Whether an event should be mailed.
	 *
	 * @param StrataEvent $event
	 *   The event.
	 *
	 * @return bool
	 *   TRUE when somebody is configured to receive it and the event passes its own gate.
	 */
	public function wants(StrataEvent $event): bool
	{
		if ($this->recipients() === []) {
			return false;
		}

		$gate = self::GATES[$event->name()] ?? null;

		if ($gate === null || $this->setting('events.' . $gate) !== true) {
			return false;
		}

		return $this->passesSeverity($event);
	}

	/**
	 * Every address to notify.
	 *
	 * @return list<string>
	 *   The addresses, empty when none is configured.
	 */
	public function recipients(): array
	{
		$configured = (string) ($this->setting('recipients') ?? '');

		return array_values(
			array_filter(
				array_map('trim', explode(',', $configured)),
				static fn(string $address): bool => $address !== '' &&
					filter_var($address, FILTER_VALIDATE_EMAIL) !== false,
			),
		);
	}

	/**
	 * The lowest finding severity that is mailed.
	 *
	 * @return int
	 *   A Finding severity ordinal.
	 */
	public function severityFloor(): int
	{
		$configured = $this->setting('severity');

		return $configured === null ? Finding::ERROR : (int) $configured;
	}

	/**
	 * Whether repeats of one code collapse into a single mail.
	 *
	 * @return bool
	 *   TRUE when digesting is on.
	 */
	public function digests(): bool
	{
		return $this->setting('digest') !== false;
	}

	/**
	 * Whether an event clears the severity floor.
	 *
	 * Only a health event carries a severity. Every other event is either notified or not by its own
	 * gate, and applying a finding threshold to a failed restore would silently suppress it.
	 *
	 * @param StrataEvent $event
	 *   The event.
	 *
	 * @return bool
	 *   TRUE when the event is severe enough, or carries no severity to test.
	 */
	private function passesSeverity(StrataEvent $event): bool
	{
		$body = $event->jsonSerialize();

		if (!isset($body['severity'])) {
			return true;
		}

		return (int) $body['severity'] >= $this->severityFloor();
	}

	/**
	 * One setting.
	 *
	 * @param string $key
	 *   The dotted config key.
	 *
	 * @return mixed
	 *   The configured value.
	 */
	private function setting(string $key): mixed
	{
		return $this->configFactory->get(self::CONFIG)->get($key);
	}
}
