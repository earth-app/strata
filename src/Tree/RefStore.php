<?php

declare(strict_types=1);

namespace Drupal\strata\Tree;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Storage\StorageProviderInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Holds the pointers that say where history currently ends.
 *
 * A ref is a mutable name for one commit. `heads/main` is the tip a flush advances; a restore moves
 * it, and a drill reads it without touching it.
 *
 * A ref is the only mutable object in the store, so it is also the only place two writers can
 * collide. Where the endpoint honours a conditional write, RefStore::compareAndSet() uses one, and
 * a caller that loses the race is told rather than silently overwriting the winner. Where it does
 * not, the same call still verifies the expected value first, which narrows the window without
 * closing it; the flush lease in `strata_lock` is what actually serialises writers.
 *
 * @see CommitLog
 */
final class RefStore
{
	/**
	 * Key prefix refs are written under.
	 */
	public const PREFIX = 'refs';

	/**
	 * The ref a flush advances.
	 */
	public const MAIN = 'heads/main';

	/**
	 * Constructs a ref store.
	 *
	 * @param StorageProviderInterface $provider
	 *   Where refs are written.
	 */
	public function __construct(private readonly StorageProviderInterface $provider) {}

	/**
	 * Reads a ref.
	 *
	 * @param string $name
	 *   Ref name, such as RefStore::MAIN.
	 *
	 * @return string|null
	 *   The commit id the ref points at, or NULL when the ref does not exist.
	 *
	 * @throws RuntimeException
	 *   When the ref exists but does not hold a valid commit id.
	 */
	public function read(string $name = self::MAIN): ?string
	{
		$key = $this->key($name);

		if (!$this->provider->exists($key)) {
			return null;
		}

		$value = trim($this->provider->get($key));

		if (!Hash::isValid($value)) {
			throw new RuntimeException(sprintf('Ref %s does not hold a valid commit id', $name));
		}

		return $value;
	}

	/**
	 * Points a ref at a commit.
	 *
	 * @param string $commit
	 *   The commit id.
	 * @param string $name
	 *   Ref name.
	 *
	 * @throws InvalidArgumentException
	 *   When the commit id is not a valid digest.
	 * @throws RuntimeException
	 *   When the write fails.
	 */
	public function write(string $commit, string $name = self::MAIN): void
	{
		if (!Hash::isValid($commit)) {
			throw new InvalidArgumentException('A ref must point at a valid commit id');
		}

		$this->provider->put($this->key($name), $commit . "\n");
	}

	/**
	 * Advances a ref only if it still points where the caller thinks it does.
	 *
	 * @param string|null $expected
	 *   The commit id the ref should currently hold, or NULL to require that it does not exist.
	 * @param string $commit
	 *   The commit id to write.
	 * @param string $name
	 *   Ref name.
	 *
	 * @return bool
	 *   TRUE when the ref was advanced, FALSE when another writer had already moved it.
	 *
	 * @throws InvalidArgumentException
	 *   When the commit id is not a valid digest.
	 * @throws RuntimeException
	 *   When the write fails for a reason other than losing the race.
	 */
	public function compareAndSet(
		?string $expected,
		string $commit,
		string $name = self::MAIN,
	): bool {
		if (!Hash::isValid($commit)) {
			throw new InvalidArgumentException('A ref must point at a valid commit id');
		}

		if ($this->read($name) !== $expected) {
			return false;
		}

		if ($expected === null && $this->provider->capabilities()->conditionalWrite) {
			try {
				$this->provider->put($this->key($name), $commit . "\n", ['ifNoneMatch' => true]);

				return true;
			} catch (RuntimeException) {
				// another writer created the ref between the read and the write
				return false;
			}
		}

		$this->provider->put($this->key($name), $commit . "\n");

		return true;
	}

	/**
	 * Removes a ref.
	 *
	 * The commits it pointed at are untouched; only the name goes away.
	 *
	 * @param string $name
	 *   Ref name.
	 *
	 * @return bool
	 *   TRUE when a ref was removed.
	 */
	public function delete(string $name): bool
	{
		return $this->provider->delete([$this->key($name)]) > 0;
	}

	/**
	 * Every ref the store holds.
	 *
	 * @return array<string, string>
	 *   Ref name keyed to the commit id it points at.
	 *
	 * @throws RuntimeException
	 *   When a ref holds something that is not a commit id.
	 */
	public function all(): array
	{
		$refs = [];
		$cursor = null;

		do {
			$page = $this->provider->list(self::PREFIX . '/', $cursor, 1000);

			foreach ($page->keys() as $key) {
				$name = substr($key, strlen(self::PREFIX) + 1);
				$value = trim($this->provider->get($key));

				if (!Hash::isValid($value)) {
					throw new RuntimeException(
						sprintf('Ref %s does not hold a valid commit id', $name),
					);
				}

				$refs[$name] = $value;
			}

			$cursor = $page->cursor;
		} while ($page->hasMore());

		return $refs;
	}

	/**
	 * The object key a ref lives at.
	 *
	 * @param string $name
	 *   Ref name.
	 *
	 * @return string
	 *   The object key.
	 *
	 * @throws InvalidArgumentException
	 *   When the name is empty or contains a traversal segment.
	 */
	private function key(string $name): string
	{
		$name = trim($name, '/');

		if ($name === '') {
			throw new InvalidArgumentException('A ref must be named');
		}

		foreach (explode('/', $name) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				throw new InvalidArgumentException(
					sprintf('Ref name "%s" contains an empty or traversal segment', $name),
				);
			}
		}

		return self::PREFIX . '/' . $name;
	}
}
