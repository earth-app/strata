<?php

declare(strict_types=1);

namespace Drupal\strata_s3\Credentials;

use Closure;

/**
 * Tries several credential sources in order and keeps the first that answers.
 *
 * The default order matches every AWS tool: environment, shared files, instance metadata. A site
 * can reorder it, and the key module provider slots in wherever an operator puts it.
 *
 * The winner is cached until it expires. Without that, a long-running prune would ask the instance
 * metadata service once per request; with it, a session is fetched once and then refreshed the
 * moment it lapses.
 *
 * Every source returning NULL is not an error. It means no credentials are configured, which the
 * caller reports as a requirement rather than as a failure mid-flush.
 *
 * @see CredentialProviderInterface
 */
final class CredentialChain implements CredentialProviderInterface
{
	/**
	 * The sources, in the order they are tried.
	 *
	 * @var list<CredentialProviderInterface>
	 */
	private readonly array $providers;

	/**
	 * Reads the current Unix timestamp.
	 */
	private readonly Closure $clock;

	/**
	 * The cached winner, or NULL when nothing has resolved yet.
	 */
	private ?Credentials $cached = null;

	/**
	 * How the cached winner describes itself.
	 */
	private ?string $winner = null;

	/**
	 * Constructs a chain.
	 *
	 * @param list<CredentialProviderInterface> $providers
	 *   The sources, in the order they are tried.
	 * @param callable|null $clock
	 *   A `callable(): int` returning the current Unix timestamp, or NULL to use time(). Injected so
	 *   a test can walk a session past its expiry without sleeping.
	 */
	public function __construct(array $providers, ?callable $clock = null)
	{
		$this->providers = array_values($providers);
		$this->clock = $clock === null ? time(...) : $clock(...);
	}

	/**
	 * {@inheritdoc}
	 */
	public function resolve(): ?Credentials
	{
		$now = (int) ($this->clock)();

		if ($this->cached !== null && !$this->cached->isExpired($now)) {
			return $this->cached;
		}

		$this->cached = null;
		$this->winner = null;

		foreach ($this->providers as $provider) {
			$found = $provider->resolve();

			// a source that answers with a spent session is the same as a source with nothing
			if ($found === null || $found->isExpired($now)) {
				continue;
			}

			$this->cached = $found;
			$this->winner = $provider->describe();

			return $found;
		}

		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function describe(): string
	{
		if ($this->winner !== null) {
			return $this->winner;
		}

		return sprintf('Credential chain over %d sources, none resolved', count($this->providers));
	}

	/**
	 * Forgets the cached winner.
	 *
	 * Called when the settings form changes a credential source, so the next request re-resolves
	 * instead of signing with the previous one.
	 */
	public function reset(): void
	{
		$this->cached = null;
		$this->winner = null;
	}
}
