<?php

declare(strict_types=1);

namespace Drupal\strata\Event\Webhook;

use Drupal\strata\Event\StrataEvents;
use JsonSerializable;

/**
 * One endpoint, the events it wants, and the secret its payloads are signed with.
 *
 * A value object read out of configuration rather than a config entity, because a subscription has
 * no identity beyond its URL and nothing else in the site refers to one. A list in a config object
 * exports and imports with the rest of the configuration and needs no entity type, no list builder
 * and no access handler.
 *
 * An empty event list means every event. That is the shape an operator reaches for first, and
 * making them tick six boxes to get it would only teach them to ignore the boxes.
 *
 * @see WebhookDispatcher
 * @see WebhookSignature
 */
final class WebhookSubscription implements JsonSerializable
{
	/**
	 * Seconds to wait for an endpoint before giving up on one attempt.
	 */
	public const DEFAULT_TIMEOUT = 10;

	/**
	 * Attempts one delivery gets, including the first.
	 */
	public const DEFAULT_ATTEMPTS = 3;

	/**
	 * Constructs a subscription.
	 *
	 * @param string $url
	 *   Where the payload is posted.
	 * @param string $secret
	 *   The signing secret, or an empty string to send unsigned.
	 * @param list<string> $events
	 *   Event names this endpoint wants, or an empty list for every event.
	 * @param bool $enabled
	 *   Whether deliveries are attempted at all.
	 * @param int $timeout
	 *   Seconds one attempt may take.
	 * @param int $attempts
	 *   Attempts before the delivery is abandoned.
	 */
	public function __construct(
		public readonly string $url,
		public readonly string $secret = '',
		public readonly array $events = [],
		public readonly bool $enabled = true,
		public readonly int $timeout = self::DEFAULT_TIMEOUT,
		public readonly int $attempts = self::DEFAULT_ATTEMPTS,
	) {}

	/**
	 * Whether this subscription wants a given event.
	 *
	 * @param string $event
	 *   The event name.
	 *
	 * @return bool
	 *   TRUE when the subscription is enabled and either lists the event or lists none.
	 */
	public function wants(string $event): bool
	{
		if (!$this->enabled) {
			return false;
		}

		return $this->events === [] || in_array($event, $this->events, true);
	}

	/**
	 * Whether payloads to this endpoint are signed.
	 *
	 * @return bool
	 *   TRUE when a secret is set.
	 */
	public function isSigned(): bool
	{
		return $this->secret !== '';
	}

	/**
	 * A label for an operator-facing list.
	 *
	 * @return string
	 *   The host, and what it is subscribed to.
	 */
	public function label(): string
	{
		$host = parse_url($this->url, PHP_URL_HOST);

		return sprintf(
			'%s (%s)',
			is_string($host) && $host !== '' ? $host : $this->url,
			$this->events === [] ? 'every event' : implode(', ', $this->events),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function jsonSerialize(): array
	{
		return [
			'url' => $this->url,
			'events' => $this->events,
			'enabled' => $this->enabled,
			'timeout' => $this->timeout,
			'attempts' => $this->attempts,
			'signed' => $this->isSigned(),
		];
	}

	/**
	 * Reads a subscription out of configuration.
	 *
	 * Unknown event names are dropped rather than kept, so a subscription written against a later
	 * release does not silently receive nothing on this one; what it does receive is what this
	 * release can actually send. An empty result after filtering is the every-event shape, which is
	 * the safe direction: an operator sees too much rather than nothing.
	 *
	 * @param array<string, mixed> $values
	 *   One entry of the `subscriptions` sequence.
	 *
	 * @return self
	 *   The subscription.
	 */
	public static function fromArray(array $values): self
	{
		/** @var list<mixed> $names */
		$names = is_array($values['events'] ?? null) ? array_values($values['events']) : [];
		$events = array_values(
			array_filter(
				array_map('strval', $names),
				static fn(string $name): bool => StrataEvents::has($name),
			),
		);

		return new self(
			trim((string) ($values['url'] ?? '')),
			(string) ($values['secret'] ?? ''),
			$events,
			(bool) ($values['enabled'] ?? true),
			max(1, (int) ($values['timeout'] ?? self::DEFAULT_TIMEOUT)),
			max(1, (int) ($values['attempts'] ?? self::DEFAULT_ATTEMPTS)),
		);
	}

	/**
	 * Reads every subscription out of a configuration sequence.
	 *
	 * @param list<array<string, mixed>>|null $sequence
	 *   The `subscriptions` value, or NULL when none is configured.
	 *
	 * @return list<self>
	 *   The subscriptions that name a URL.
	 */
	public static function fromSequence(?array $sequence): array
	{
		$subscriptions = [];

		foreach ($sequence ?? [] as $values) {
			if (!is_array($values)) {
				continue;
			}

			$subscription = self::fromArray($values);

			if ($subscription->url !== '') {
				$subscriptions[] = $subscription;
			}
		}

		return $subscriptions;
	}
}
