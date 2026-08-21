<?php

declare(strict_types=1);

namespace Drupal\strata\Branch;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Storage\StorageProviderInterface;
use Drupal\strata\Tree\RefStore;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Creates, lists and removes branches in the object store.
 *
 * A branch is a ref, so the tip is a ref and nothing here invents a second naming scheme for it.
 * What a ref cannot hold is where the branch was cut from, who cut it and when, and those three are
 * what makes a branch listing readable, so they go in a small JSON object beside the ref.
 *
 * That split is what keeps the local index derivable. If the fork point lived only in
 * `strata_branch`, dropping the table would lose it and `strata:reindex` could not put it back; with
 * the metadata in the bucket the table is a cache over objects that already exist, exactly like every
 * other table this module installs.
 *
 * **The tip is the ref, never the metadata.** A copy of the tip in the metadata object would be a
 * second answer to the same question, and a crash between the two writes would leave them
 * disagreeing with nothing saying which is right.
 *
 * @see Branch
 * @see BranchIndex
 * @see RefStore
 */
final class BranchStore
{
	/**
	 * Key prefix branch metadata is written under.
	 */
	public const PREFIX = 'branches';

	/**
	 * Constructs a branch store.
	 *
	 * @param StorageProviderInterface $provider
	 *   Where branch metadata is written.
	 * @param RefStore $refs
	 *   Where the tips live.
	 */
	public function __construct(
		private readonly StorageProviderInterface $provider,
		private readonly RefStore $refs,
	) {}

	#region Reading

	/**
	 * One branch.
	 *
	 * @param string $name
	 *   The branch name.
	 *
	 * @return Branch|null
	 *   The branch, or NULL when no ref of that name exists.
	 *
	 * @throws InvalidArgumentException
	 *   When the name is not usable.
	 * @throws RuntimeException
	 *   When the ref exists but does not hold a commit id.
	 */
	public function read(string $name): ?Branch
	{
		Branch::assertName($name);

		$tip = $this->refs->read(Branch::refFor($name));

		if ($tip === null) {
			return null;
		}

		return $this->hydrate($name, $tip);
	}

	/**
	 * Whether a branch of that name exists.
	 *
	 * @param string $name
	 *   The branch name.
	 *
	 * @return bool
	 *   TRUE when a ref of that name is present.
	 *
	 * @throws InvalidArgumentException
	 *   When the name is not usable.
	 */
	public function exists(string $name): bool
	{
		Branch::assertName($name);

		return $this->refs->read(Branch::refFor($name)) !== null;
	}

	/**
	 * Every branch the store holds, trunk included.
	 *
	 * The trunk is listed because an operator merging into it needs to see where it is, and leaving
	 * it out would make the one ref that always exists the one the listing never mentions.
	 *
	 * @return array<string, Branch>
	 *   Branch name keyed to the branch, in name order.
	 *
	 * @throws RuntimeException
	 *   When a ref holds something that is not a commit id.
	 */
	public function all(): array
	{
		$branches = [];

		foreach ($this->refs->all() as $ref => $tip) {
			$name = Branch::nameFor((string) $ref);

			if ($name === null) {
				continue;
			}

			$branches[$name] = $this->hydrate($name, $tip);
		}

		ksort($branches, SORT_STRING);

		return $branches;
	}

	#endregion

	#region Writing

	/**
	 * Cuts a branch off a commit.
	 *
	 * @param string $name
	 *   The branch name.
	 * @param string $from
	 *   Commit to fork from.
	 * @param int|null $actor
	 *   Drupal user id creating it, or NULL for unattended work.
	 * @param int|null $createdAt
	 *   Unix microseconds, or NULL for now.
	 *
	 * @return Branch
	 *   The branch.
	 *
	 * @throws InvalidArgumentException
	 *   When the name is not usable or the commit is not a valid digest.
	 * @throws RuntimeException
	 *   When a branch of that name already exists, or another writer created one first.
	 */
	public function create(
		string $name,
		string $from,
		?int $actor = null,
		?int $createdAt = null,
	): Branch {
		Branch::assertName($name);

		if ($name === Branch::TRUNK) {
			throw new RuntimeException(
				sprintf(
					'"%s" is the trunk, which exists already and is not cut from anything',
					$name,
				),
			);
		}
		if (!Hash::isValid($from)) {
			throw new InvalidArgumentException('A branch must fork from a valid commit id');
		}
		if ($this->exists($name)) {
			throw new RuntimeException(
				sprintf(
					'Branch "%s" exists already, so creating it would move somebody else',
					$name,
				),
			);
		}

		$branch = new Branch(
			$name,
			$from,
			$from,
			$actor,
			$createdAt ?? (int) round(microtime(true) * 1_000_000),
		);

		// the metadata first: an object with no ref is collectable, a ref with no metadata is a branch
		// whose fork point nothing can name
		$this->provider->put($this->key($name), (string) json_encode($branch));

		if (!$this->refs->compareAndSet(null, $from, $branch->ref())) {
			throw new RuntimeException(sprintf('Another writer created branch "%s" first', $name));
		}

		return $branch;
	}

	/**
	 * Moves a branch to another commit, only if it still points where the caller thinks it does.
	 *
	 * @param Branch $branch
	 *   The branch as the caller last read it, whose tip is the expected value.
	 * @param string $commit
	 *   The commit to point it at.
	 *
	 * @return bool
	 *   TRUE when the branch was moved, FALSE when another writer had already moved it.
	 *
	 * @throws InvalidArgumentException
	 *   When the commit is not a valid digest.
	 */
	public function advance(Branch $branch, string $commit): bool
	{
		return $this->refs->compareAndSet($branch->tip, $commit, $branch->ref());
	}

	/**
	 * Removes a branch.
	 *
	 * The commits it pointed at are untouched; only the name goes away, and a prune collects whatever
	 * no surviving ref reaches. The trunk is refused, because deleting the ref every restore resolves
	 * through would leave a store full of history nothing can reach.
	 *
	 * @param string $name
	 *   The branch name.
	 *
	 * @return bool
	 *   TRUE when a branch was removed, FALSE when there was none of that name.
	 *
	 * @throws InvalidArgumentException
	 *   When the name is not usable.
	 * @throws RuntimeException
	 *   When the name is the trunk.
	 */
	public function delete(string $name): bool
	{
		Branch::assertName($name);

		if ($name === Branch::TRUNK) {
			throw new RuntimeException(
				sprintf(
					'"%s" is the trunk; deleting it would leave every commit unreachable',
					$name,
				),
			);
		}

		$removed = $this->refs->delete(Branch::refFor($name));

		$this->provider->delete([$this->key($name)]);

		return $removed;
	}

	#endregion

	/**
	 * A branch from its ref tip and whatever metadata survives beside it.
	 *
	 * A ref with no metadata object is still a branch. The trunk has never had one, an archive import
	 * writes refs before anything else, and a ref written by a future release may carry metadata this
	 * one cannot read; in all three the tip is its own fork point, which is the honest answer rather
	 * than a guess at where it diverged.
	 *
	 * @param string $name
	 *   The branch name.
	 * @param string $tip
	 *   The commit the ref points at.
	 *
	 * @return Branch
	 *   The branch.
	 */
	private function hydrate(string $name, string $tip): Branch
	{
		$key = $this->key($name);

		try {
			if ($this->provider->exists($key)) {
				/** @var array<string, mixed> $data */
				$data = json_decode($this->provider->get($key), true) ?? [];

				return Branch::fromArray($data, $tip);
			}
		} catch (Throwable) {
			// metadata that will not read costs the fork point, not the branch
		}

		return new Branch($name, $tip, $tip);
	}

	/**
	 * The object key a branch's metadata lives at.
	 *
	 * @param string $name
	 *   The branch name.
	 *
	 * @return string
	 *   The object key.
	 */
	private function key(string $name): string
	{
		return self::PREFIX . '/' . $name . '.json';
	}
}
