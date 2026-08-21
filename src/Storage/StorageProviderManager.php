<?php

declare(strict_types=1);

namespace Drupal\strata\Storage;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Holds the storage providers a site has configured.
 *
 * A site has one active provider that flushes receive, and may have others registered for a
 * migration, a second-bucket replica or a dry run. An add-on registers here and needs no change
 * anywhere else in the engine.
 *
 * @see StorageProviderInterface
 */
final class StorageProviderManager
{
	/**
	 * Registered providers, keyed by id.
	 *
	 * @var array<string, StorageProviderInterface>
	 */
	private array $providers = [];

	/**
	 * Providers not built yet, keyed by id.
	 *
	 * Building a provider can fail - a missing bucket, an unreachable endpoint, credentials that do
	 * not resolve - so a provider the site is not using is never constructed. Registering an eager
	 * instance would make a misconfigured optional provider take the whole container down.
	 *
	 * @var array<string, Closure(): StorageProviderInterface>
	 */
	private array $deferred = [];

	/**
	 * Factories a submodule contributed, keyed by id.
	 *
	 * Held alongside the deferred closure rather than swallowed by it, because a tiered store has to
	 * ask a factory for a second provider at a different location. A closure can only be called; a
	 * factory can be asked what it is capable of.
	 *
	 * @var array<string, StorageProviderFactoryInterface>
	 */
	private array $factories = [];

	/**
	 * Id of the provider flushes are written to.
	 */
	private ?string $active = null;

	/**
	 * Adds a provider.
	 *
	 * The first provider registered becomes the active one, so a manager holding exactly one
	 * provider never needs it named.
	 *
	 * @param StorageProviderInterface $provider
	 *   The provider.
	 *
	 * @return $this
	 *   The manager, for chaining.
	 *
	 * @throws InvalidArgumentException
	 *   When another provider is already registered under that id. Silently replacing it would
	 *   redirect every subsequent write with no signal.
	 */
	public function register(StorageProviderInterface $provider): self
	{
		$id = $provider->id();

		if ($id === '') {
			throw new InvalidArgumentException('A storage provider must have an id');
		}
		if (isset($this->providers[$id])) {
			throw new InvalidArgumentException(
				sprintf('A storage provider is already registered as "%s"', $id),
			);
		}

		$this->providers[$id] = $provider;
		$this->active ??= $id;

		return $this;
	}

	/**
	 * Adds a provider that is built the first time it is asked for.
	 *
	 * @param string $id
	 *   The provider id, as it appears in configuration.
	 * @param callable(): StorageProviderInterface $factory
	 *   Builds the provider.
	 *
	 * @return $this
	 *   The manager, for chaining.
	 *
	 * @throws InvalidArgumentException
	 *   When another provider is already registered under that id.
	 */
	public function registerFactory(string $id, callable $factory): self
	{
		if ($id === '') {
			throw new InvalidArgumentException('A storage provider must have an id');
		}
		if (isset($this->providers[$id]) || isset($this->deferred[$id])) {
			throw new InvalidArgumentException(
				sprintf('A storage provider is already registered as "%s"', $id),
			);
		}

		$this->deferred[$id] = Closure::fromCallable($factory);
		$this->active ??= $id;

		return $this;
	}

	/**
	 * Adds a provider contributed by a submodule, built on first use.
	 *
	 * The shape a Drupal service collector calls: a submodule tags an object exposing `id()` and
	 * `create()`, and the manager holds it until something asks for that provider. A submodule whose
	 * endpoint is misconfigured therefore reports itself unreachable rather than taking the
	 * container down at compile time.
	 *
	 * @param StorageProviderFactoryInterface $factory
	 *   The contributed factory.
	 *
	 * @return $this
	 *   The manager, for chaining.
	 *
	 * @throws InvalidArgumentException
	 *   When another provider is already registered under that id.
	 */
	public function registerProviderFactory(StorageProviderFactoryInterface $factory): self
	{
		$this->registerFactory(
			$factory->id(),
			static fn(): StorageProviderInterface => $factory->create(),
		);

		$this->factories[$factory->id()] = $factory;

		return $this;
	}

	/**
	 * The factory a provider id was contributed by.
	 *
	 * What a tiered store needs: the same endpoint pointed at a second bucket can only be built by
	 * the thing that knows how to build the first one.
	 *
	 * @param string $id
	 *   A provider id.
	 *
	 * @return StorageProviderFactoryInterface|null
	 *   The factory, or NULL when the provider was registered as an instance or a bare closure rather
	 *   than through a factory.
	 */
	public function factory(string $id): ?StorageProviderFactoryInterface
	{
		return $this->factories[$id] ?? null;
	}

	/**
	 * Chooses which provider receives flushes.
	 *
	 * @param string $id
	 *   A registered provider id.
	 *
	 * @return $this
	 *   The manager, for chaining.
	 *
	 * @throws InvalidArgumentException
	 *   When nothing is registered under that id.
	 */
	public function activate(string $id): self
	{
		if (!$this->has($id)) {
			throw new InvalidArgumentException(
				sprintf('Cannot activate "%s": no such storage provider is registered', $id),
			);
		}

		$this->active = $id;

		return $this;
	}

	/**
	 * The provider flushes are written to.
	 *
	 * @return StorageProviderInterface
	 *   The active provider.
	 *
	 * @throws RuntimeException
	 *   When no provider is registered, which means the site has never been configured.
	 */
	public function active(): StorageProviderInterface
	{
		if ($this->active === null) {
			throw new RuntimeException(
				'No storage provider is configured, so there is nowhere to write a backup',
			);
		}

		return $this->get($this->active);
	}

	/**
	 * A provider by id.
	 *
	 * @param string $id
	 *   A registered provider id.
	 *
	 * @return StorageProviderInterface
	 *   The provider.
	 *
	 * @throws InvalidArgumentException
	 *   When nothing is registered under that id.
	 */
	public function get(string $id): StorageProviderInterface
	{
		if (isset($this->providers[$id])) {
			return $this->providers[$id];
		}

		$factory = $this->deferred[$id] ?? null;

		if ($factory === null) {
			throw new InvalidArgumentException(
				sprintf('No storage provider is registered as "%s"', $id),
			);
		}

		unset($this->deferred[$id]);

		return $this->providers[$id] = $factory();
	}

	/**
	 * Whether a provider is registered.
	 *
	 * @param string $id
	 *   A provider id.
	 *
	 * @return bool
	 *   TRUE when it is registered.
	 */
	public function has(string $id): bool
	{
		return isset($this->providers[$id]) || isset($this->deferred[$id]);
	}

	/**
	 * Every registered provider id.
	 *
	 * @return list<string>
	 *   Ids in registration order.
	 */
	public function ids(): array
	{
		return [...array_keys($this->providers), ...array_keys($this->deferred)];
	}

	/**
	 * Every registered provider.
	 *
	 * @return array<string, StorageProviderInterface>
	 *   Providers keyed by id.
	 */
	public function all(): array
	{
		return $this->providers;
	}

	/**
	 * Which providers can be reached right now.
	 *
	 * Used by hook_requirements() and the status report. A provider that raises while being probed
	 * is reported as unreachable rather than taking the status page down with it.
	 *
	 * @return array<string, string|null>
	 *   Provider id keyed to the reason it is unreachable, or NULL when it is reachable.
	 */
	public function reachability(): array
	{
		$status = [];

		foreach ($this->ids() as $id) {
			try {
				$provider = $this->get($id);
				$status[$id] = $provider->isReachable() ? null : $provider->unreachableReason();
			} catch (RuntimeException | InvalidArgumentException $e) {
				// a provider that cannot even be built is unreachable, not a fatal
				$status[$id] = $e->getMessage();
			}
		}

		return $status;
	}
}
