<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\strata\Engine;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

/**
 * Shared wiring for the report pages.
 *
 * Every page here reads from the engine and none of them writes anything, so they all take the same
 * two collaborators and all attach the same library.
 *
 * **None of these pages is cacheable.** They describe the state of a store that changes on every
 * flush, and a cached timeline is a timeline that is wrong. The render arrays therefore carry
 * `max-age: 0` rather than a cache tag, because there is no tag a flush could invalidate: the store
 * is not an entity and a flush happens in a queue worker that knows nothing about the render cache.
 *
 * @see Engine
 */
abstract class StrataControllerBase extends ControllerBase
{
	/**
	 * The library every Strata page attaches.
	 */
	public const LIBRARY = 'strata_ui/strata';

	/**
	 * Constructs a report page.
	 *
	 * Declared final so every subclass is constructed the same way, which is what makes the shared
	 * create() below safe.
	 *
	 * @param Engine $engine
	 *   Where every page reads from.
	 * @param TimeInterface $time
	 *   The clock, for a page that needs to know now.
	 */
	final public function __construct(
		protected readonly Engine $engine,
		protected readonly TimeInterface $time,
	) {}

	/**
	 * {@inheritdoc}
	 */
	public static function create(ContainerInterface $container): static
	{
		return new static($container->get('strata.engine'), $container->get('datetime.time'));
	}

	/**
	 * A render array carrying the library and no cache.
	 *
	 * @param array<string, mixed> $build
	 *   The page.
	 *
	 * @return array<string, mixed>
	 *   The page, with the library attached and caching switched off.
	 */
	protected function render(array $build): array
	{
		$build['#attached']['library'][] = self::LIBRARY;
		$build['#cache'] = ['max-age' => 0];

		return $build;
	}

	/**
	 * A message shown where a page would otherwise be blank.
	 *
	 * @param string $message
	 *   What to say.
	 *
	 * @return array<string, mixed>
	 *   A render array.
	 */
	protected function nothing(string $message): array
	{
		return [
			'#type' => 'container',
			'#attributes' => ['class' => ['strata-empty']],
			'message' => ['#markup' => $message],
		];
	}

	/**
	 * Builds a page, or explains why it could not be built.
	 *
	 * **A report must never be the thing that takes an admin page down.** Assembling the engine reads
	 * the storage provider, the cipher and the codec registry, and any of those can refuse: encryption
	 * is on by default with no key until someone chooses one, so a fresh install reaches this before it
	 * reaches anything else. An uncaught refusal there is a white screen on the page an operator went
	 * to in order to find out what was wrong.
	 *
	 * @param callable $build
	 *   Builds the page.
	 *
	 * @return array<string, mixed>
	 *   The page, or a container naming what has to be configured first.
	 */
	protected function guard(callable $build): array
	{
		try {
			return $this->render($build());
		} catch (Throwable $error) {
			return $this->render([
				'#type' => 'container',
				'#attributes' => ['class' => ['strata-warning']],
				'reason' => [
					'#markup' => $this->t('This report is unavailable: @why', [
						'@why' => $error->getMessage(),
					]),
				],
				'settings' => [
					'#type' => 'link',
					'#title' => $this->t('Strata Storage Settings'),
					'#url' => Url::fromRoute('strata.settings.storage'),
				],
			]);
		}
	}
}
