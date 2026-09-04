<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Queue\QueueWorkerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\strata\Event\CommitEvent;
use Drupal\strata\Event\StrataEvents;
use Drupal\strata\Event\Webhook\WebhookDispatcher;
use Drupal\strata\Event\Webhook\WebhookSignature;
use Drupal\strata\Flush\FlushResult;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use stdClass;

/**
 * Proves a delivery is queued rather than posted, and what the queue item is allowed to carry.
 *
 * Nothing here reaches a network. The HTTP client in the container is a test double, so what is
 * asserted is the request the dispatcher would have made: its headers, its body, and a signature
 * that verifies against the exact bytes that were sent.
 *
 * `retryBackoffGrows` fails against the current dispatcher and is the report it belongs to.
 * `enqueue()` merges with `$item + ['delay_until' => ...]`, and every dispatched item already
 * carries `delay_until` as 0, so the left operand wins and the exponential backoff the class and
 * `WebhookDelivery` both describe is never applied - a failing endpoint is retried on the next cron
 * run instead of a minute later.
 */
class WebhookTest extends StrataKernelTestBase
{
	/**
	 * The endpoint most cases post to.
	 */
	private const URL = 'https://hooks.example.com/strata';

	/**
	 * The signing secret the signed cases use.
	 */
	private const SECRET = 'a-shared-secret';

	/**
	 * The client the dispatcher posts through.
	 */
	private ClientInterface&MockObject $client;

	/**
	 * Requests the client was asked to make.
	 *
	 * @var list<array{string, string, array<string, mixed>}>
	 */
	private array $requests = [];

	/**
	 * Statuses to answer with, one per request, the last repeating.
	 *
	 * @var list<int>
	 */
	private array $statuses = [200];

	/**
	 * Whether the client should throw instead of answering.
	 */
	private bool $unreachable = false;

	/**
	 * {@inheritdoc}
	 */
	public function register(ContainerBuilder $container): void
	{
		parent::register($container);

		$this->client = $this->createMock(ClientInterface::class);
		$this->client
			->method('request')
			->willReturnCallback(function (string $method, string $url, array $options): Response {
				$this->requests[] = [$method, $url, $options];

				if ($this->unreachable) {
					throw new ConnectException('no route to host', new Request($method, $url));
				}

				$at = min(count($this->requests) - 1, count($this->statuses) - 1);

				return new Response($this->statuses[$at]);
			});

		$container->set('http_client', $this->client);
	}

	private function dispatcher(): WebhookDispatcher
	{
		return $this->container->get('strata.webhooks');
	}

	private function queue(): QueueInterface
	{
		return $this->container->get('queue')->get(WebhookDispatcher::QUEUE);
	}

	private function now(): int
	{
		return $this->container->get('datetime.time')->getRequestTime();
	}

	/**
	 * Writes the subscription sequence.
	 *
	 * @param list<array<string, mixed>> $subscriptions
	 *   The entries.
	 */
	private function subscribe(array $subscriptions): void
	{
		$this->config(WebhookDispatcher::CONFIG)->set('subscriptions', $subscriptions)->save();
	}

	/**
	 * A commit event with real numbers on it.
	 *
	 * @return CommitEvent
	 *   The event.
	 */
	private function event(): CommitEvent
	{
		return new CommitEvent(
			new FlushResult(
				true,
				'forced',
				'segments/0/1/one.seg',
				str_repeat('c', 64),
				3,
				2,
				4_096,
				3,
				0.25,
			),
			'site-under-test',
		);
	}

	/**
	 * Every item waiting in the queue, oldest first.
	 *
	 * @return list<array<string, mixed>>
	 *   The item data.
	 */
	private function items(): array
	{
		$queue = $this->queue();
		$items = [];
		$claimed = [];

		while (($item = $queue->claimItem(0)) !== false) {
			/** @var array<string, mixed> $data */
			$data = $item->data;
			$items[] = $data;
			$claimed[] = $item;
		}

		foreach ($claimed as $item) {
			$queue->releaseItem($item);
		}

		return $items;
	}

	/**
	 * Takes one item off the queue.
	 *
	 * @return array<string, mixed>
	 *   The item data.
	 */
	private function take(): array
	{
		$queue = $this->queue();
		$item = $queue->claimItem(0);

		$this->assertNotFalse($item, 'an item was waiting');

		$queue->deleteItem($item);

		/** @var array<string, mixed> $data */
		$data = $item->data;

		return $data;
	}

	#region Dispatch

	#[Test]
	#[TestDox('with nothing subscribed a dispatch queues nothing and posts nothing')]
	#[Group('strata/webhook')]
	public function noSubscriptionsQueuesNothing(): void
	{
		$this->assertSame([], $this->dispatcher()->subscriptions());
		$this->assertSame(0, $this->dispatcher()->dispatch($this->event()));
		$this->assertSame(0, $this->queue()->numberOfItems());
		$this->assertSame([], $this->requests);
	}

	#[Test]
	#[TestDox('a subscription wanting every event gets one item carrying no secret')]
	#[Group('strata/webhook')]
	public function oneSubscriptionQueuesOneItem(): void
	{
		$this->subscribe([['url' => self::URL, 'secret' => self::SECRET]]);

		$this->assertSame(1, $this->dispatcher()->dispatch($this->event()));

		$item = $this->take();

		$this->assertSame(StrataEvents::COMMIT_SEALED, $item['event']);
		$this->assertSame(self::URL, $item['url']);
		$this->assertSame(1, $item['attempt']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $item['delivery']);
		$this->assertArrayNotHasKey('secret', $item);
		$this->assertStringNotContainsString(self::SECRET, (string) json_encode($item));

		/** @var array<string, mixed> $body */
		$body = json_decode((string) $item['body'], true);

		$this->assertSame(StrataEvents::COMMIT_SEALED, $body['event']);
		$this->assertSame('site-under-test', $body['site']);
		$this->assertSame(str_repeat('c', 64), $body['commit']);
		$this->assertSame([], $this->requests, 'nothing was posted inline');
	}

	#[Test]
	#[TestDox('a subscription that does not want the event gets nothing')]
	#[Group('strata/webhook')]
	public function unwantedEventQueuesNothing(): void
	{
		$this->subscribe([['url' => self::URL, 'events' => [StrataEvents::PRUNE_APPLIED]]]);

		$this->assertSame(0, $this->dispatcher()->dispatch($this->event()));
		$this->assertSame(0, $this->queue()->numberOfItems());
	}

	#[Test]
	#[TestDox('a disabled subscription gets nothing')]
	#[Group('strata/webhook')]
	public function disabledSubscriptionQueuesNothing(): void
	{
		$this->subscribe([['url' => self::URL, 'enabled' => false]]);

		$this->assertSame(0, $this->dispatcher()->dispatch($this->event()));
		$this->assertSame(0, $this->queue()->numberOfItems());
	}

	#[Test]
	#[TestDox('two subscriptions get two items carrying the same body bytes')]
	#[Group('strata/webhook')]
	public function twoSubscriptionsShareOneBody(): void
	{
		$this->subscribe([
			['url' => 'https://one.example/hook'],
			['url' => 'https://two.example/hook', 'secret' => self::SECRET],
		]);

		$this->assertSame(2, $this->dispatcher()->dispatch($this->event()));

		$items = $this->items();

		$this->assertCount(2, $items);
		$this->assertSame($items[0]['body'], $items[1]['body'], 'the body is rendered once');
		$this->assertNotSame($items[0]['url'], $items[1]['url']);
		$this->assertNotSame(
			$items[0]['delivery'],
			$items[1]['delivery'],
			'each endpoint deduplicates on its own id',
		);
	}

	#[Test]
	#[TestDox('subscriptionFor() finds a configured endpoint and nothing else')]
	#[Group('strata/webhook')]
	public function subscriptionForFindsTheEndpoint(): void
	{
		$this->subscribe([['url' => self::URL, 'secret' => self::SECRET, 'timeout' => 20]]);

		$subscription = $this->dispatcher()->subscriptionFor(self::URL);

		$this->assertNotNull($subscription);
		$this->assertSame(self::SECRET, $subscription->secret);
		$this->assertSame(20, $subscription->timeout);
		$this->assertNull($this->dispatcher()->subscriptionFor('https://elsewhere.example/hook'));
	}

	#endregion

	#region Delivery

	#[Test]
	#[TestDox('a 2xx is a success and nothing is re-queued')]
	#[Group('strata/webhook')]
	public function successfulDeliveryIsDone(): void
	{
		$this->subscribe([['url' => self::URL]]);
		$this->dispatcher()->dispatch($this->event());

		$this->assertTrue($this->dispatcher()->attempt($this->take()));
		$this->assertCount(1, $this->requests);
		$this->assertSame('POST', $this->requests[0][0]);
		$this->assertSame(self::URL, $this->requests[0][1]);
		$this->assertSame(0, $this->queue()->numberOfItems());
	}

	#[Test]
	#[TestDox('an item whose subscription has since been deleted is dropped without a post')]
	#[Group('strata/webhook')]
	public function deletedSubscriptionIsDropped(): void
	{
		$this->subscribe([['url' => self::URL]]);
		$this->dispatcher()->dispatch($this->event());

		$item = $this->take();

		$this->subscribe([]);

		$this->assertTrue($this->dispatcher()->attempt($item));
		$this->assertSame([], $this->requests);
		$this->assertSame(0, $this->queue()->numberOfItems());
	}

	#[Test]
	#[TestDox('an item whose subscription has since been switched off is dropped too')]
	#[Group('strata/webhook')]
	public function disabledSubscriptionIsDropped(): void
	{
		$this->subscribe([['url' => self::URL]]);
		$this->dispatcher()->dispatch($this->event());

		$item = $this->take();

		$this->subscribe([['url' => self::URL, 'enabled' => false]]);

		$this->assertTrue($this->dispatcher()->attempt($item));
		$this->assertSame([], $this->requests);
	}

	#[Test]
	#[TestDox('a 500 is re-queued until the attempts are exhausted, then given up on')]
	#[Group('strata/webhook')]
	public function failedDeliveryIsRetriedThenAbandoned(): void
	{
		$this->statuses = [500];
		$this->subscribe([['url' => self::URL, 'attempts' => 3]]);
		$this->dispatcher()->dispatch($this->event());

		$first = $this->take();

		$this->assertFalse($this->dispatcher()->attempt($first));
		$this->assertSame(1, $this->queue()->numberOfItems(), 'a second attempt is waiting');

		$second = $this->take();

		$this->assertSame(2, $second['attempt']);
		$this->assertFalse($this->dispatcher()->attempt($second));

		$third = $this->take();

		$this->assertSame(3, $third['attempt']);
		$this->assertFalse($this->dispatcher()->attempt($third));
		$this->assertSame(0, $this->queue()->numberOfItems(), 'the delivery was given up on');
		$this->assertCount(3, $this->requests);
	}

	#[Test]
	#[TestDox('each retry waits longer than the last')]
	#[Group('strata/webhook')]
	public function retryBackoffGrows(): void
	{
		$this->statuses = [500];
		$this->subscribe([['url' => self::URL, 'attempts' => 3]]);
		$this->dispatcher()->dispatch($this->event());

		$first = $this->take();

		$this->assertSame(0, $first['delay_until'], 'the first attempt is immediate');
		$this->dispatcher()->attempt($first);

		$second = $this->take();

		$this->assertSame(
			$this->now() + WebhookDispatcher::BACKOFF,
			$second['delay_until'],
			'the second attempt waits one backoff',
		);

		$this->dispatcher()->attempt($second);

		$third = $this->take();

		$this->assertSame(
			$this->now() + WebhookDispatcher::BACKOFF * 2,
			$third['delay_until'],
			'the third waits twice as long',
		);
	}

	#[Test]
	#[TestDox('a single-attempt subscription is given up on immediately')]
	#[Group('strata/webhook')]
	public function oneAttemptIsNotRetried(): void
	{
		$this->statuses = [503];
		$this->subscribe([['url' => self::URL, 'attempts' => 1]]);
		$this->dispatcher()->dispatch($this->event());

		$this->assertFalse($this->dispatcher()->attempt($this->take()));
		$this->assertSame(0, $this->queue()->numberOfItems());
		$this->assertCount(1, $this->requests);
	}

	#[Test]
	#[TestDox('an unreachable endpoint is a failure rather than an exception')]
	#[Group('strata/webhook')]
	public function unreachableEndpointIsAFailure(): void
	{
		$this->unreachable = true;
		$this->subscribe([['url' => self::URL, 'attempts' => 1]]);
		$this->dispatcher()->dispatch($this->event());

		$this->assertFalse($this->dispatcher()->attempt($this->take()));
		$this->assertCount(1, $this->requests);
	}

	#endregion

	#region Signing

	#[Test]
	#[TestDox('a signed endpoint gets a signature that verifies against the bytes it was sent')]
	#[Group('strata/webhook')]
	public function signedDeliveryVerifies(): void
	{
		$this->subscribe([['url' => self::URL, 'secret' => self::SECRET]]);
		$this->dispatcher()->dispatch($this->event());

		$item = $this->take();

		$this->assertTrue($this->dispatcher()->attempt($item));

		[, , $options] = $this->requests[0];

		/** @var array<string, string> $headers */
		$headers = $options['headers'];

		$this->assertArrayHasKey(WebhookSignature::HEADER, $headers);
		$this->assertSame($item['body'], $options['body'], 'the signed bytes are the sent bytes');
		$this->assertTrue(
			WebhookSignature::verify(
				$headers[WebhookSignature::HEADER],
				(string) $options['body'],
				self::SECRET,
				$this->now(),
			),
		);
		$this->assertFalse(
			WebhookSignature::verify(
				$headers[WebhookSignature::HEADER],
				(string) $options['body'] . ' ',
				self::SECRET,
				$this->now(),
			),
			'the signature covers the whole body',
		);
	}

	#[Test]
	#[TestDox('an unsigned endpoint gets no signature header at all')]
	#[Group('strata/webhook')]
	public function unsignedDeliveryHasNoSignature(): void
	{
		$this->subscribe([['url' => self::URL]]);
		$this->dispatcher()->dispatch($this->event());

		$this->assertTrue($this->dispatcher()->attempt($this->take()));

		/** @var array<string, string> $headers */
		$headers = $this->requests[0][2]['headers'];

		$this->assertArrayNotHasKey(WebhookSignature::HEADER, $headers);
	}

	#[Test]
	#[TestDox('every delivery names its event and its id, so a receiver can route and deduplicate')]
	#[Group('strata/webhook')]
	public function deliveryCarriesRoutingHeaders(): void
	{
		$this->subscribe([['url' => self::URL, 'timeout' => 7]]);
		$this->dispatcher()->dispatch($this->event());

		$item = $this->take();

		$this->dispatcher()->attempt($item);

		[, , $options] = $this->requests[0];

		/** @var array<string, string> $headers */
		$headers = $options['headers'];

		$this->assertSame('application/json', $headers['Content-Type']);
		$this->assertSame(StrataEvents::COMMIT_SEALED, $headers[WebhookSignature::EVENT_HEADER]);
		$this->assertSame($item['delivery'], $headers[WebhookSignature::DELIVERY_HEADER]);
		$this->assertSame(7, $options['timeout']);
		$this->assertFalse($options['http_errors']);
	}

	#[Test]
	#[TestDox('a delivery does not follow a redirect, so the endpoint cannot choose another one')]
	#[Group('strata/webhook')]
	public function aDeliveryDoesNotFollowARedirect(): void
	{
		$this->subscribe([['url' => self::URL, 'secret' => self::SECRET]]);
		$this->dispatcher()->dispatch($this->event());
		$this->dispatcher()->attempt($this->take());

		[, , $options] = $this->requests[0];

		// guzzle follows five by default. an endpoint answering 302 - or whoever controls its dns -
		// would otherwise decide where a signed payload describing this site's history goes next,
		// and the operator who approved the subscription never saw that address
		$this->assertFalse($options['allow_redirects']);
	}

	#endregion

	#region The Queue Worker

	#[Test]
	#[TestDox('an item that is not due yet is requeued rather than delivered')]
	#[Group('strata/webhook')]
	public function anItemThatIsNotDueIsRequeued(): void
	{
		$this->expectException(RequeueException::class);

		$this->worker()->processItem(['url' => self::URL, 'delay_until' => $this->now() + 3600]);
	}

	#[Test]
	#[TestDox('a malformed item fails its own delivery rather than the whole cron run')]
	#[Group('strata/webhook')]
	public function aMalformedItemDoesNotAbortCron(): void
	{
		$this->subscribe([['url' => self::URL]]);

		// Cron::processQueues() catches Exception and nothing wider, so an Error raised here does not
		// fail this item - it aborts the run, and every module whose cron had not gone yet is skipped
		$this->worker()->processItem(['url' => new stdClass()]);

		$this->assertSame([], $this->requests, 'nothing was posted');
		$this->assertSame(0, $this->queue()->numberOfItems(), 'and nothing was left half-queued');
	}

	#[Test]
	#[TestDox('an item that is not an array at all is dropped')]
	#[Group('strata/webhook')]
	public function aNonArrayItemIsDropped(): void
	{
		$this->worker()->processItem('not an item');

		$this->assertSame([], $this->requests);
	}

	/**
	 * The queue worker, built the way cron builds it.
	 *
	 * @return QueueWorkerInterface
	 *   The worker.
	 */
	private function worker(): QueueWorkerInterface
	{
		return $this->container
			->get('plugin.manager.queue_worker')
			->createInstance(WebhookDispatcher::QUEUE);
	}

	#endregion
}
