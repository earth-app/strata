<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Storage;

use Drupal\strata\Storage\StorageProviderInterface;
use Drupal\strata\Storage\StorageProviderManager;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves the provider registry hands back what was contributed and never raises while probing.
 *
 * `reachability()` is what `strata_requirements()` calls, so it runs on the status report of a site
 * that may have configured nothing, enabled a submodule it never set up, or pointed at a bucket that
 * has gone. Every one of those has to read as a reason string rather than an exception, because the
 * alternative is a white screen where an operator went looking for the health of their backups.
 *
 * @see StorageProviderManager
 */
#[CoversClass(StorageProviderManager::class)]
class StorageProviderManagerTest extends TestCase
{
	/**
	 * A provider double answering a fixed reachability.
	 *
	 * @param string $id
	 *   The provider id.
	 * @param bool $reachable
	 *   What isReachable() answers.
	 * @param string|null $reason
	 *   What unreachableReason() answers.
	 *
	 * @return StorageProviderInterface
	 *   The double.
	 */
	private function provider(
		string $id,
		bool $reachable = true,
		?string $reason = null,
	): StorageProviderInterface {
		$provider = $this->createMock(StorageProviderInterface::class);
		$provider->method('id')->willReturn($id);
		$provider->method('isReachable')->willReturn($reachable);
		$provider->method('unreachableReason')->willReturn($reason);

		return $provider;
	}

	#region Registration

	#[Test]
	#[TestDox('a registered provider is handed back under its own id')]
	#[Group('strata/storage')]
	public function registrationIsByProviderId(): void
	{
		$manager = new StorageProviderManager();
		$provider = $this->provider('demo');

		$manager->register($provider);

		$this->assertTrue($manager->has('demo'));
		$this->assertSame($provider, $manager->get('demo'));
		$this->assertSame(['demo'], $manager->ids());
	}

	#[Test]
	#[
		TestDox(
			'registering a second provider under one id is refused rather than redirecting writes',
		),
	]
	#[Group('strata/storage')]
	public function duplicateRegistrationIsRefused(): void
	{
		$manager = new StorageProviderManager();
		$manager->register($this->provider('demo'));

		$this->expectException(InvalidArgumentException::class);
		$manager->register($this->provider('demo'));
	}

	#[Test]
	#[TestDox('a provider with no id is refused, since nothing could ask for it')]
	#[Group('strata/storage')]
	public function anIdlessProviderIsRefused(): void
	{
		$manager = new StorageProviderManager();

		$this->expectException(InvalidArgumentException::class);
		$manager->register($this->provider(''));
	}

	#[Test]
	#[TestDox('a deferred provider is not built until it is asked for')]
	#[Group('strata/storage')]
	public function aDeferredProviderIsBuiltLate(): void
	{
		$built = 0;
		$manager = new StorageProviderManager();

		$manager->registerFactory('demo', function () use (&$built): StorageProviderInterface {
			$built++;

			return $this->provider('demo');
		});

		$this->assertSame(0, $built, 'registering builds nothing');
		$this->assertTrue($manager->has('demo'));
		$this->assertSame(0, $built, 'asking whether it exists builds nothing');

		$manager->get('demo');
		$manager->get('demo');

		$this->assertSame(1, $built, 'the factory runs once and the result is kept');
	}

	#[Test]
	#[TestDox('an id nothing registered is refused rather than answered with a null provider')]
	#[Group('strata/storage')]
	public function anUnregisteredIdIsRefused(): void
	{
		$manager = new StorageProviderManager();

		$this->expectException(InvalidArgumentException::class);
		$manager->get('absent');
	}

	#endregion

	#region Reachability

	#[Test]
	#[TestDox('a provider that answers is reachable, and says so with no reason')]
	#[Group('strata/storage')]
	public function aReachableProviderHasNoReason(): void
	{
		$manager = new StorageProviderManager();
		$manager->register($this->provider('demo', true));

		$this->assertNull($manager->reachability('demo'));
	}

	#[Test]
	#[TestDox('an unreachable provider reports the reason it gave')]
	#[Group('strata/storage')]
	public function anUnreachableProviderReportsItsReason(): void
	{
		$manager = new StorageProviderManager();
		$manager->register($this->provider('demo', false, 'the endpoint refused the connection'));

		$this->assertSame('the endpoint refused the connection', $manager->reachability('demo'));
	}

	#[Test]
	#[TestDox('an unreachable provider that gives no reason still reports something')]
	#[Group('strata/storage')]
	public function anUnreachableProviderAlwaysReportsSomething(): void
	{
		$manager = new StorageProviderManager();
		$manager->register($this->provider('demo', false, null));

		// a null here would render an empty status line, which reads as "fine"
		$this->assertNotNull($manager->reachability('demo'));
	}

	#[Test]
	#[TestDox('a provider id nothing registered reads as unreachable rather than raising')]
	#[Group('strata/storage')]
	public function anUnregisteredProviderIsUnreachableNotFatal(): void
	{
		$manager = new StorageProviderManager();

		$this->assertNotNull($manager->reachability('absent'));
	}

	#[Test]
	#[TestDox('a factory that throws reads as unreachable rather than raising')]
	#[Group('strata/storage')]
	public function aThrowingFactoryIsUnreachableNotFatal(): void
	{
		$manager = new StorageProviderManager();
		$manager->registerFactory('demo', static function (): StorageProviderInterface {
			throw new RuntimeException('no credentials resolved');
		});

		$this->assertSame('no credentials resolved', $manager->reachability('demo'));
	}

	#[Test]
	#[TestDox('probing one provider does not build any of the others')]
	#[Group('strata/storage')]
	public function probingOneProviderBuildsOnlyThatOne(): void
	{
		$manager = new StorageProviderManager();
		$manager->register($this->provider('configured', true));

		// an enabled-but-unconfigured submodule: building it would raise, and sweeping every
		// registered provider on the status report is what used to make that happen
		$manager->registerFactory('unconfigured', static function (): StorageProviderInterface {
			throw new RuntimeException('this must never be built');
		});

		$this->assertNull($manager->reachability('configured'));
	}

	#endregion
}
