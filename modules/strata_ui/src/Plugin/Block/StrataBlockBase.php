<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\strata\Engine;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

/**
 * Shared behaviour for the dashboard blocks.
 *
 * **A block that cannot read the store renders as an error rather than as an exception.** These blocks
 * sit on a dashboard beside other people's blocks, and an unreachable bucket must not take that whole
 * page down with it - which is exactly what an uncaught throw from a block build does.
 *
 * **Nothing here is cached.** A cached status block is a status block that is wrong, and there is no
 * cache tag a flush could invalidate: the store is not an entity, and a flush happens in a queue
 * worker that knows nothing about the render cache.
 *
 * @see StatusBlock
 */
abstract class StrataBlockBase extends BlockBase implements ContainerFactoryPluginInterface
{
	// declared here rather than inherited from PluginBase, so __wakeup() sits in the scope that
	// declares the readonly property below and can therefore reinitialize it on php 8.3
	use DependencySerializationTrait;

	/**
	 * Constructs a block.
	 *
	 * Declared final so every subclass is constructed the same way, which is what makes the shared
	 * create() below safe.
	 *
	 * @param array<string, mixed> $configuration
	 *   Plugin configuration.
	 * @param string $pluginId
	 *   The plugin id.
	 * @param mixed $pluginDefinition
	 *   The plugin definition.
	 * @param Engine $engine
	 *   Where every block reads from.
	 */
	final public function __construct(
		array $configuration,
		$pluginId,
		$pluginDefinition,
		protected readonly Engine $engine,
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
	): static {
		return new static(
			$configuration,
			$plugin_id,
			$plugin_definition,
			$container->get('strata.engine'),
		);
	}

	/**
	 * The rows this block shows.
	 *
	 * @return list<array{label: string, value: string, severity?: string}>
	 *   Each label, value and severity.
	 */
	abstract protected function rows(): array;

	/**
	 * The route the block's details link points at.
	 *
	 * @return string
	 *   A route name.
	 */
	abstract protected function detailRoute(): string;

	/**
	 * The permissions any one of which may see this block.
	 *
	 * @return list<string>
	 *   Permission names.
	 */
	abstract protected function requiredPermissions(): array;

	/**
	 * {@inheritdoc}
	 */
	public function build(): array
	{
		// the detail url is inside the guard with the rows: a block sits on pages that are nothing to
		// do with Strata, and Url::fromRoute() raises on a route the router no longer knows
		try {
			$rows = $this->rows();
			$url = Url::fromRoute($this->detailRoute())->toString();
		} catch (Throwable $error) {
			return [
				'#theme' => 'strata_status',
				'#rows' => [
					[
						'label' => (string) $this->t('Unavailable'),
						'value' => $error->getMessage(),
						'severity' => 'error',
					],
				],
				'#severity' => 'error',
				'#url' => '',
				'#attached' => ['library' => ['strata_ui/strata']],
				'#cache' => ['max-age' => 0],
			];
		}

		return [
			'#theme' => 'strata_status',
			'#rows' => $rows,
			'#severity' => $this->severityFor($rows),
			'#url' => $url,
			'#attached' => ['library' => ['strata_ui/strata']],
			'#cache' => ['max-age' => 0],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	protected function blockAccess(AccountInterface $account): AccessResultInterface
	{
		return AccessResult::allowedIfHasPermissions($account, $this->requiredPermissions(), 'OR');
	}

	/**
	 * The worst severity among the rows.
	 *
	 * @param list<array{label: string, value: string, severity?: string}> $rows
	 *   The rows.
	 *
	 * @return string
	 *   One of "ok", "warn" or "error".
	 */
	protected function severityFor(array $rows): string
	{
		$worst = 'ok';

		foreach ($rows as $row) {
			$severity = (string) ($row['severity'] ?? 'ok');

			if ($severity === 'error') {
				return 'error';
			}
			if ($severity === 'warn') {
				$worst = 'warn';
			}
		}

		return $worst;
	}
}
