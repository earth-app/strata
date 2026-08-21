<?php

declare(strict_types=1);

namespace Drupal\strata\Branch;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Tree\RefStore;
use InvalidArgumentException;
use JsonSerializable;

/**
 * A named line of configuration history forked off a commit.
 *
 * **A branch carries the configuration realm and nothing else, and that is a property of how each
 * realm is captured rather than a feature that was left out.** A configuration object is captured
 * WHOLE on every save, so its value at any commit is one complete document and a three-way merge
 * over it is an ordinary operation on complete values. Content entities and table rows are captured
 * as FIELD DELTAS against a parent, so merging them would mean replaying two divergent delta chains
 * and inventing a resolution wherever they disagree. That is not a merge; it is data loss with extra
 * steps. So a branch that received a change in any other realm refuses the merge and names the
 * subject rather than dropping it quietly.
 *
 * A branch is a ref. `refs/heads/main` is the trunk, `refs/heads/<name>` is a branch, and everything
 * that already reads refs - reachability, prune, verify, `strata:flush --ref` - treats it as one
 * without being told about branching at all.
 *
 * @see BranchStore
 * @see Merger
 * @see RefStore
 */
final class Branch implements JsonSerializable
{
	/**
	 * The realm a branch may carry.
	 */
	public const REALM = Realm::CONFIG;

	/**
	 * Ref prefix every branch lives under, trunk included.
	 */
	public const HEADS = 'heads/';

	/**
	 * The branch name the trunk answers to.
	 */
	public const TRUNK = 'main';

	/**
	 * Longest name a branch may carry, which is what the local index column holds.
	 */
	public const MAX_NAME = 128;

	/**
	 * Constructs a branch.
	 *
	 * @param string $name
	 *   The branch name, without the `heads/` prefix.
	 * @param string $forkedFrom
	 *   Address of the commit the branch was cut from.
	 * @param string $tip
	 *   Address of the commit it currently points at, which is the fork point until something is
	 *   flushed onto it.
	 * @param int|null $actor
	 *   Drupal user id that created it, or NULL for unattended work.
	 * @param int $createdAt
	 *   Unix microseconds it was created at.
	 *
	 * @throws InvalidArgumentException
	 *   When the name is not usable, or either address is not a valid digest.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $forkedFrom,
		public readonly string $tip,
		public readonly ?int $actor = null,
		public readonly int $createdAt = 0,
	) {
		self::assertName($name);

		if (!Hash::isValid($forkedFrom)) {
			throw new InvalidArgumentException('A branch must fork from a valid commit id');
		}
		if (!Hash::isValid($tip)) {
			throw new InvalidArgumentException('A branch tip must be a valid commit id');
		}
		if ($createdAt < 0) {
			throw new InvalidArgumentException(
				'A branch cannot have been created before the epoch',
			);
		}
	}

	/**
	 * The ref name this branch is stored under.
	 *
	 * @return string
	 *   Something such as `heads/release-12`, which is what RefStore takes.
	 */
	public function ref(): string
	{
		return self::refFor($this->name);
	}

	/**
	 * Whether the branch is the trunk.
	 *
	 * @return bool
	 *   TRUE for `main`.
	 */
	public function isTrunk(): bool
	{
		return $this->name === self::TRUNK;
	}

	/**
	 * Whether anything has been flushed onto the branch since it was cut.
	 *
	 * @return bool
	 *   TRUE when the tip is still the fork point.
	 */
	public function isUnchanged(): bool
	{
		return $this->tip === $this->forkedFrom;
	}

	/**
	 * The same branch pointing at another commit.
	 *
	 * @param string $tip
	 *   The new tip.
	 *
	 * @return self
	 *   A new branch.
	 *
	 * @throws InvalidArgumentException
	 *   When the address is not a valid digest.
	 */
	public function at(string $tip): self
	{
		return new self($this->name, $this->forkedFrom, $tip, $this->actor, $this->createdAt);
	}

	/**
	 * The ref name a branch name maps to.
	 *
	 * @param string $name
	 *   The branch name.
	 *
	 * @return string
	 *   The ref name.
	 *
	 * @throws InvalidArgumentException
	 *   When the name is not usable.
	 */
	public static function refFor(string $name): string
	{
		self::assertName($name);

		return self::HEADS . $name;
	}

	/**
	 * The branch name a ref name maps back to.
	 *
	 * @param string $ref
	 *   The ref name, such as `heads/release-12`.
	 *
	 * @return string|null
	 *   The branch name, or NULL when the ref is not a branch.
	 */
	public static function nameFor(string $ref): ?string
	{
		if (!str_starts_with($ref, self::HEADS)) {
			return null;
		}

		$name = substr($ref, strlen(self::HEADS));

		return self::isValidName($name) ? $name : null;
	}

	/**
	 * Whether a name is one a branch may carry.
	 *
	 * Two rules, both of which have to hold. RefStore refuses an empty or traversal segment, because
	 * a ref name becomes an object key and `heads/../../etc` would write outside the site's own
	 * prefix. On top of that a branch name is lowercase alphanumeric with `-`, `_` and `/`, so the
	 * name reads the same in a URL, a Drush argument and an object key, and so two names cannot differ
	 * only by case on a store that is case-insensitive.
	 *
	 * @param string $name
	 *   The candidate name.
	 *
	 * @return bool
	 *   TRUE when the name is usable.
	 */
	public static function isValidName(string $name): bool
	{
		if ($name === '' || strlen($name) > self::MAX_NAME) {
			return false;
		}

		return preg_match('#^[a-z0-9][a-z0-9_-]*(/[a-z0-9][a-z0-9_-]*)*$#', $name) === 1;
	}

	/**
	 * Refuses a name a branch may not carry.
	 *
	 * @param string $name
	 *   The candidate name.
	 *
	 * @throws InvalidArgumentException
	 *   When the name is not usable, naming it and the rule it broke.
	 */
	public static function assertName(string $name): void
	{
		if (self::isValidName($name)) {
			return;
		}

		throw new InvalidArgumentException(
			sprintf(
				'"%s" is not a usable branch name; use lowercase letters, digits, "-", "_" and "/", ' .
					'at most %d characters, with no empty segment',
				$name,
				self::MAX_NAME,
			),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The branch as a plain array. The tip is deliberately absent: the ref is where the tip lives,
	 *   and a copy of it in the metadata object would be a second answer that can go stale.
	 */
	public function jsonSerialize(): array
	{
		return [
			'name' => $this->name,
			'forkedFrom' => $this->forkedFrom,
			'actor' => $this->actor,
			'createdAt' => $this->createdAt,
		];
	}

	/**
	 * Rebuilds a branch from its stored metadata and the ref that names its tip.
	 *
	 * @param array<string, mixed> $data
	 *   The array produced by Branch::jsonSerialize().
	 * @param string $tip
	 *   The commit the ref points at.
	 *
	 * @return self
	 *   The branch.
	 *
	 * @throws InvalidArgumentException
	 *   When the metadata does not describe a branch.
	 */
	public static function fromArray(array $data, string $tip): self
	{
		if (!isset($data['name'], $data['forkedFrom'])) {
			throw new InvalidArgumentException('Branch metadata names no branch and no fork point');
		}

		return new self(
			(string) $data['name'],
			(string) $data['forkedFrom'],
			$tip,
			isset($data['actor']) ? (int) $data['actor'] : null,
			(int) ($data['createdAt'] ?? 0),
		);
	}
}
