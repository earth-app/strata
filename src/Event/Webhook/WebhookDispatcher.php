<?php

declare(strict_types=1);

namespace Drupal\strata\Event\Webhook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\strata\Event\StrataEvent;
use GuzzleHttp\ClientInterface;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues one delivery per interested endpoint, and performs one attempt at a time.
 *
 * Nothing is posted inline. A commit is sealed inside a web request, and a subscriber that made an
 * outbound HTTP call there would put a slow or unreachable endpoint directly into the site's own
 * response time - and a failed one into the flush's error path, where it does not belong. So a
 * dispatch writes queue items and returns; the queue worker does the posting on cron.
 *
 * The body is rendered ONCE per event and reused for every subscription. The signature covers those
 * exact bytes, so re-encoding per endpoint would risk two endpoints being sent bodies that differ
 * in key order and one of them failing verification.
 *
 * A secret is never written to the queue. The item carries the URL, and the worker resolves the
 * subscription again at delivery time; a subscription removed while its deliveries were waiting is
 * dropped rather than sent.
 *
 * @see WebhookSubscription
 * @see WebhookSignature
 */
final class WebhookDispatcher
{
	/**
	 * The configuration object subscriptions are read from.
	 */
	public const CONFIG = 'strata.webhooks';

	/**
	 * The queue deliveries wait in.
	 */
	public const QUEUE = 'strata_webhook';

	/**
	 * Seconds to wait before the second attempt; each further attempt doubles it.
	 */
	public const BACKOFF = 60;

	/**
	 * Longest body an endpoint is sent.
	 *
	 * A payload is a summary of what happened, never the stored content itself, so anything past
	 * this is a bug rather than a large site.
	 */
	public const MAX_BODY = 65_536;

	/**
	 * Constructs a dispatcher.
	 *
	 * @param ClientInterface $http
	 *   The HTTP client deliveries are posted with.
	 * @param ConfigFactoryInterface $configFactory
	 *   Where subscriptions are read from.
	 * @param QueueFactory $queues
	 *   Where deliveries wait.
	 * @param TimeInterface $time
	 *   The clock a signature is stamped from.
	 * @param LoggerInterface $logger
	 *   Records a delivery that failed every attempt.
	 */
	public function __construct(
		private readonly ClientInterface $http,
		private readonly ConfigFactoryInterface $configFactory,
		private readonly QueueFactory $queues,
		private readonly TimeInterface $time,
		private readonly LoggerInterface $logger,
	) {}

	/**
	 * Every configured subscription.
	 *
	 * @return list<WebhookSubscription>
	 *   The subscriptions.
	 */
	public function subscriptions(): array
	{
		/** @var list<array<string, mixed>>|null $sequence */
		$sequence = $this->configFactory->get(self::CONFIG)->get('subscriptions');

		return WebhookSubscription::fromSequence($sequence);
	}

	/**
	 * The subscription for one URL.
	 *
	 * @param string $url
	 *   The endpoint.
	 *
	 * @return WebhookSubscription|null
	 *   The subscription, or NULL when it is no longer configured.
	 */
	public function subscriptionFor(string $url): ?WebhookSubscription
	{
		foreach ($this->subscriptions() as $subscription) {
			if ($subscription->url === $url) {
				return $subscription;
			}
		}

		return null;
	}

	/**
	 * Queues one delivery per subscription that wants this event.
	 *
	 * @param StrataEvent $event
	 *   The event.
	 *
	 * @return int
	 *   How many deliveries were queued.
	 */
	public function dispatch(StrataEvent $event): int
	{
		$wanted = array_filter(
			$this->subscriptions(),
			static fn(WebhookSubscription $subscription): bool => $subscription->wants(
				$event->name(),
			),
		);

		if ($wanted === []) {
			return 0;
		}

		$body = $this->render($event);

		if ($body === null) {
			return 0;
		}

		$queued = 0;

		foreach ($wanted as $subscription) {
			$this->enqueue([
				'event' => $event->name(),
				'url' => $subscription->url,
				'body' => $body,
				'delivery' => $this->deliveryId(),
				'attempt' => 1,
			]);

			$queued++;
		}

		return $queued;
	}

	/**
	 * Performs one attempt, and re-queues with backoff when there are attempts left.
	 *
	 * @param array<string, mixed> $item
	 *   The queue item.
	 *
	 * @return bool
	 *   TRUE when the endpoint accepted the delivery.
	 */
	public function attempt(array $item): bool
	{
		$url = (string) ($item['url'] ?? '');
		$subscription = $this->subscriptionFor($url);

		// removed or switched off while its deliveries were waiting
		if ($subscription === null || !$subscription->enabled) {
			return true;
		}

		$attempt = max(1, (int) ($item['attempt'] ?? 1));
		$event = (string) ($item['event'] ?? '');
		$body = (string) ($item['body'] ?? '');
		$delivery = (string) ($item['delivery'] ?? '');

		if ($this->post($subscription, $event, $body, $delivery)) {
			return true;
		}

		if ($attempt >= $subscription->attempts) {
			$this->logger->error(
				'Strata webhook to @url gave up on @event after @attempts attempts',
				['@url' => $url, '@event' => $event, '@attempts' => $attempt],
			);

			return false;
		}

		$this->enqueue(['attempt' => $attempt + 1] + $item, self::BACKOFF * 2 ** ($attempt - 1));

		return false;
	}

	/**
	 * Posts one delivery.
	 *
	 * @param WebhookSubscription $subscription
	 *   Where it goes.
	 * @param string $event
	 *   The event name.
	 * @param string $body
	 *   The exact bytes to send.
	 * @param string $delivery
	 *   The delivery id.
	 *
	 * @return bool
	 *   TRUE when the endpoint answered with a 2xx.
	 */
	public function post(
		WebhookSubscription $subscription,
		string $event,
		string $body,
		string $delivery,
	): bool {
		$headers = [
			'Content-Type' => 'application/json',
			WebhookSignature::EVENT_HEADER => $event,
			WebhookSignature::DELIVERY_HEADER => $delivery,
		];

		if ($subscription->isSigned()) {
			$headers[WebhookSignature::HEADER] = WebhookSignature::sign(
				$body,
				$subscription->secret,
				$this->time->getRequestTime(),
			);
		}

		try {
			$response = $this->http->request('POST', $subscription->url, [
				'headers' => $headers,
				'body' => $body,
				'timeout' => $subscription->timeout,
				'http_errors' => false,
			]);

			$status = $response->getStatusCode();

			if ($status >= 200 && $status < 300) {
				return true;
			}

			$this->logger->warning('Strata webhook to @url answered @status for @event', [
				'@url' => $subscription->url,
				'@status' => $status,
				'@event' => $event,
			]);
		} catch (Throwable $e) {
			$this->logger->warning('Strata webhook to @url failed for @event: @message', [
				'@url' => $subscription->url,
				'@event' => $event,
				'@message' => $e->getMessage(),
			]);
		}

		return false;
	}

	/**
	 * The body an event is sent as.
	 *
	 * @param StrataEvent $event
	 *   The event.
	 *
	 * @return string|null
	 *   The JSON body, or NULL when it could not be encoded or is implausibly large.
	 */
	private function render(StrataEvent $event): ?string
	{
		try {
			$body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		} catch (JsonException $e) {
			$this->logger->error('Strata webhook payload for @event would not encode: @message', [
				'@event' => $event->name(),
				'@message' => $e->getMessage(),
			]);

			return null;
		}

		if (strlen($body) > self::MAX_BODY) {
			$this->logger->error(
				'Strata webhook payload for @event is @bytes bytes, so it was not sent',
				[
					'@event' => $event->name(),
					'@bytes' => strlen($body),
				],
			);

			return null;
		}

		return $body;
	}

	/**
	 * Puts an item in the queue, delayed when the queue backend can delay.
	 *
	 * @param array<string, mixed> $item
	 *   The item.
	 * @param int $delay
	 *   Seconds to hold it for, or 0 for immediately.
	 */
	private function enqueue(array $item, int $delay = 0): void
	{
		// the new delay goes on the LEFT: `+` keeps the left operand's keys, and a re-queued item
		// already carries the previous delay_until
		$this->queues
			->get(self::QUEUE)
			->createItem(
				['delay_until' => $delay > 0 ? $this->time->getRequestTime() + $delay : 0] + $item,
			);
	}

	/**
	 * An id a receiver can deduplicate on.
	 *
	 * @return string
	 *   32 hex characters.
	 */
	private function deliveryId(): string
	{
		return bin2hex(random_bytes(16));
	}
}
