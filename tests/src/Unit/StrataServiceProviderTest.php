<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\strata\Capture\KeyValueCaptureFactory;
use Drupal\strata\StrataServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Proves key-value capture is actually wired onto a site.
 *
 * **This is the only test that can prove it, and without it the realm ships unverified.** The
 * decoration cannot be declared in `strata.services.yml` because `KernelTestBase` replaces
 * `keyvalue` with a synthetic service and Symfony cannot decorate one, so it is applied here in
 * `alter()` instead - which means the kernel lane takes the synthetic early return and never
 * executes the branch that matters. `KeyValueCaptureTest` covers what the decorator *does* once it
 * exists; nothing covered whether it ever comes into existence.
 *
 * A wrong service id, a dropped tag or a lost public flag would leave `keyvalue` undecorated, the
 * key-value realm silently capturing nothing, and every existing test still green. For a backup
 * tool that is the worst shape of bug there is: the failure is invisible until a restore needs the
 * data that was never taken.
 *
 * @see StrataServiceProvider
 * @see KeyValueCaptureFactory
 */
#[CoversClass(StrataServiceProvider::class)]
class StrataServiceProviderTest extends TestCase
{
	/**
	 * A container holding a `keyvalue` definition shaped like the one core registers.
	 *
	 * @return ContainerBuilder
	 *   The container.
	 */
	private function container(): ContainerBuilder
	{
		$container = new ContainerBuilder();

		$keyvalue = new Definition('Drupal\Core\KeyValueStore\KeyValueFactory');
		$keyvalue->setArguments([new Reference('service_container')]);
		$keyvalue->setPublic(true);
		$keyvalue->addTag('needs_destruction');

		$container->setDefinition('keyvalue', $keyvalue);
		$container->setDefinition('strata.key_recorder', new Definition('stdClass'));

		return $container;
	}

	#region Decoration

	#[Test]
	#[TestDox('a site gets its keyvalue factory replaced by the capturing one')]
	#[Group('strata/capture')]
	public function keyValueIsDecoratedOnASite(): void
	{
		$container = $this->container();

		(new StrataServiceProvider())->alter($container);

		$this->assertSame(
			KeyValueCaptureFactory::class,
			$container->getDefinition('keyvalue')->getClass(),
			'nothing would capture the key-value realm if this were still core\'s factory',
		);
	}

	#[Test]
	#[TestDox('the original factory is kept, so the decorator has something to delegate to')]
	#[Group('strata/capture')]
	public function theInnerFactoryIsPreserved(): void
	{
		$container = $this->container();

		(new StrataServiceProvider())->alter($container);

		$this->assertTrue($container->hasDefinition(StrataServiceProvider::INNER));
		$this->assertSame(
			'Drupal\Core\KeyValueStore\KeyValueFactory',
			$container->getDefinition(StrataServiceProvider::INNER)->getClass(),
		);
	}

	#[Test]
	#[TestDox('the decorator takes the inner factory first and the recorder as a closure')]
	#[Group('strata/capture')]
	public function theDecoratorIsWiredToBoth(): void
	{
		$container = $this->container();

		(new StrataServiceProvider())->alter($container);

		$arguments = $container->getDefinition('keyvalue')->getArguments();

		$this->assertCount(2, $arguments);
		$this->assertInstanceOf(Reference::class, $arguments[0]);
		$this->assertSame(StrataServiceProvider::INNER, (string) $arguments[0]);

		// a plain reference here would be resolved while the container is still compiling, which is
		// what the recorder cannot survive
		$this->assertInstanceOf(ServiceClosureArgument::class, $arguments[1]);
	}

	#[Test]
	#[TestDox('the tags move to whatever answers to the id, or state stops persisting')]
	#[Group('strata/capture')]
	public function tagsFollowTheId(): void
	{
		$container = $this->container();

		(new StrataServiceProvider())->alter($container);

		$this->assertTrue(
			$container->getDefinition('keyvalue')->hasTag('needs_destruction'),
			'a dropped needs_destruction tag means nothing is ever flushed to storage',
		);
	}

	#[Test]
	#[TestDox('the public flag moves too, since core fetches keyvalue by id')]
	#[Group('strata/capture')]
	public function visibilityFollowsTheId(): void
	{
		$container = $this->container();

		(new StrataServiceProvider())->alter($container);

		$this->assertTrue($container->getDefinition('keyvalue')->isPublic());
	}

	#endregion

	#region Leaving It Alone

	#[Test]
	#[TestDox('a synthetic keyvalue is left alone, since there is no definition to wrap')]
	#[Group('strata/capture')]
	public function aSyntheticKeyValueIsUntouched(): void
	{
		$container = new ContainerBuilder();
		$synthetic = new Definition();
		$synthetic->setSynthetic(true);
		$container->setDefinition('keyvalue', $synthetic);

		(new StrataServiceProvider())->alter($container);

		$this->assertFalse($container->hasDefinition(StrataServiceProvider::INNER));
		$this->assertTrue($container->getDefinition('keyvalue')->isSynthetic());
	}

	#[Test]
	#[TestDox('a container with no keyvalue at all is left alone rather than raising')]
	#[Group('strata/capture')]
	public function anAbsentKeyValueIsUntouched(): void
	{
		$container = new ContainerBuilder();

		(new StrataServiceProvider())->alter($container);

		$this->assertFalse($container->hasDefinition('keyvalue'));
		$this->assertFalse($container->hasDefinition(StrataServiceProvider::INNER));
	}

	#[Test]
	#[TestDox('altering twice does not wrap the decorator around itself')]
	#[Group('strata/capture')]
	public function alteringTwiceIsNotDoubleWrapped(): void
	{
		$container = $this->container();
		$provider = new StrataServiceProvider();

		$provider->alter($container);
		$provider->alter($container);

		// the inner definition must still be core's factory, not a second decorator, or every
		// key-value write would be journaled twice
		$this->assertSame(
			'Drupal\Core\KeyValueStore\KeyValueFactory',
			$container->getDefinition(StrataServiceProvider::INNER)->getClass(),
		);
	}

	#endregion
}
