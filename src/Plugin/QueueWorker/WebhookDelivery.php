<?php

declare(strict_types=1);

namespace Drupal\strata\Plugin\QueueWorker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\strata\Event\Webhook\WebhookDispatcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

/**
 * Posts one queued webhook delivery.
 *
 * The delay is enforced here rather than by the queue backend, because the database queue core
 * ships cannot hold an item until a moment - `DelayableQueueInterface` is implemented by some
 * backends and not that one. An item that is not due yet is re-queued untouched, which costs one
 * cheap read and keeps the backoff honest on every backend.
 *
 * @see WebhookDispatcher
 */
#[
	QueueWorker(
		id: 'strata_webhook',
		title: new TranslatableMarkup('Strata webhook delivery'),
		cron: ['time' => 30],
	),
]
final class WebhookDelivery extends QueueWorkerBase implements ContainerFactoryPluginInterface
{
	// declared here rather than inherited from PluginBase, so __wakeup() sits in the scope that
	// declares the readonly properties below and can therefore reinitialize them on php 8.3
	use DependencySerializationTrait;

	/**
	 * Constructs a worker.
	 *
	 * @param array<string, mixed> $configuration
	 *   Plugin configuration.
	 * @param string $pluginId
	 *   The plugin id.
	 * @param mixed $pluginDefinition
	 *   The plugin definition.
	 * @param WebhookDispatcher $dispatcher
	 *   Performs the attempt.
	 * @param TimeInterface $time
	 *   Decides whether an item is due.
	 * @param LoggerInterface $logger
	 *   Records a delivery that failed in a way the dispatcher did not expect.
	 */
	public function __construct(
		array $configuration,
		$pluginId,
		$pluginDefinition,
		protected readonly WebhookDispatcher $dispatcher,
		protected readonly TimeInterface $time,
		protected readonly LoggerInterface $logger,
	) {
		parent::__construct($configuration, $pluginId, $pluginDefinition);
	}

	/**
	 * {@inheritdoc}
	 */
	public static function create(
		ContainerInterface $container,
		array $configuration,
		$plugin_id,
		$plugin_definition,
	): self {
		return new self(
			$configuration,
			$plugin_id,
			$plugin_definition,
			$container->get('strata.webhooks'),
			$container->get('datetime.time'),
			$container->get('logger.channel.strata'),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * **Everything below the requeue is caught, including an Error.** `Cron::processQueues()` catches
	 * `Exception` and nothing wider, so a TypeError raised by a malformed item does not fail that
	 * item - it aborts the whole cron run, and every module whose cron had not gone yet is skipped
	 * for as long as the lock is held. A webhook that cannot be delivered is not worth that.
	 *
	 * @param mixed $data
	 *   The queue item.
	 *
	 * @throws RequeueException
	 *   When the item is not due yet. Thrown past the catch, since it is how a worker says "later"
	 *   rather than a failure.
	 */
	public function processItem($data): void
	{
		if (!is_array($data)) {
			return;
		}

		$due = (int) ($data['delay_until'] ?? 0);

		if ($due > 0 && $due > $this->time->getRequestTime()) {
			throw new RequeueException('Not due yet');
		}

		try {
			$this->dispatcher->attempt($data);
		} catch (Throwable $error) {
			$this->logger->error('Strata could not deliver a webhook: @message', [
				'@message' => $error->getMessage(),
			]);
		}
	}
}
