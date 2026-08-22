<?php

declare(strict_types=1);

namespace Drupal\strata\Storage;

use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * Holds the storage providers a site has available.
 *
 * A registry, not a policy. Which provider a flush is written to is `strata.settings`'s `provider`
 * key, resolved by Engine; this class only knows what has been contributed and how to build it. An
 * add-on registers here and needs no change anywhere else in the engine.
 *
 * Every enabled submodule registers whether or not the site has configured it, so the ids held here
 * are what is *available* rather than what is in use. A caller wanting to report on the store the
 * site actually writes to has to name that provider itself.
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
	 * Adds a provider.
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
	 * Whether one provider can be reached right now.
	 *
	 * Used by `strata_requirements()` to report the store the site writes to. It names a single
	 * provider rather than sweeping every registered one because registration follows the enabled
	 * submodules and not the configuration: a site running strata_s3 and strata_b2 but configured
	 * for S3 alone would otherwise report B2 as broken on the status page every time it loaded.
	 *
	 * **Three failure modes collapse into one reason string, and none of them raise.** A provider id
	 * nothing registered, a factory that throws while building, and a provider that builds but cannot
	 * answer are all just "unreachable, and here is why" to a caller that must not take the status
	 * report down with it.
	 *
	 * @param string $id
	 *   The provider id to probe, as it appears in `strata.settings`.
	 *
	 * @return string|null
	 *   Why the provider cannot be reached, or NULL when it can be.
	 */
	public function reachability(string $id): ?string
	{
		try {
			$provider = $this->get($id);

			return $provider->isReachable()
				? null
				: $provider->unreachableReason() ?? 'the provider did not say why';
		} catch (Throwable $error) {
			// a provider that cannot even be built is unreachable, not a fatal. Throwable rather than
			// a named list: this runs on the status report, and a factory is submodule code that can
			// raise anything at all
			return $error->getMessage();
		}
	}
}
