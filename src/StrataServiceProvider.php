<?php

declare(strict_types=1);

namespace Drupal\strata;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Drupal\strata\Capture\KeyValueCaptureFactory;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Wires the capture decorators that cannot be declared in YAML.
 *
 * `keyvalue` is a normal service on a running site and a SYNTHETIC one under `KernelTestBase`,
 * which replaces it with an in-memory factory so state survives a container rebuild between test
 * phases. Symfony refuses to decorate a synthetic service - there is no definition to wrap - so a
 * `decorates: keyvalue` line in `strata.services.yml` would compile on every site and fail on every
 * kernel test in the project, including the ones that have nothing to do with key-value capture.
 *
 * So the decoration is applied here, where the definition can be inspected first. A site gets
 * key-value capture; the kernel lane does not, and the decorator's own behaviour is covered by unit
 * tests over a real in-memory factory instead.
 *
 * @see KeyValueCaptureFactory
 */
final class StrataServiceProvider implements ServiceModifierInterface
{
	/**
	 * Service id the undecorated factory is moved to.
	 */
	public const INNER = 'strata.keyvalue.inner';

	/**
	 * {@inheritdoc}
	 */
	public function alter(ContainerBuilder $container): void
	{
		if (!$container->hasDefinition('keyvalue')) {
			return;
		}

		$inner = $container->getDefinition('keyvalue');

		// a synthetic definition is set at runtime and has nothing to wrap
		if ($inner->isSynthetic()) {
			return;
		}

		$container->setDefinition(self::INNER, $inner);

		// a service closure, not a reference: core asks `keyvalue` for a store while this very
		// container is still compiling, and a recorder cannot be constructed at that point
		$decorator = new Definition(KeyValueCaptureFactory::class, [
			new Reference(self::INNER),
			new ServiceClosureArgument(new Reference('strata.key_recorder')),
		]);

		// the tags belong to whatever answers to the original id, which is now the decorator
		$decorator->setTags($inner->getTags());
		$decorator->setPublic($inner->isPublic());

		$container->setDefinition('keyvalue', $decorator);
	}
}
