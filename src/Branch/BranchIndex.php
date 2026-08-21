<?php

declare(strict_types=1);

namespace Drupal\strata\Branch;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * A local index over the branches in the object store.
 *
 * Listing branches from the bucket costs one listing plus one GET per branch for the metadata and
 * one more for the ref, so a page that shows a dozen branches would spend two dozen requests on a
 * question the site already knows the answer to. This is that answer in a table.
 *
 * Nothing here is authoritative. Every column is a field of a `branches/` object or a ref that
 * already exists, which is what makes it safe for an uninstall to drop the table and for
 * `strata:reindex` to rebuild it.
 *
 * @see BranchStore
 * @see Branch
 */
final class BranchIndex
{
	/**
	 * The table this index lives in.
	 */
	public const TABLE = 'strata_branch';

	/**
	 * Constructs an index.
	 *
	 * @param Connection $database
	 *   The database.
	 */
	public function __construct(private readonly Connection $database) {}

	/**
	 * Records a branch, or updates the row for one already present.
	 *
	 * @param Branch $branch
	 *   The branch.
	 */
	public function record(Branch $branch): void
	{
		$this->database
			->merge(self::TABLE)
			->key('name', $branch->name)
			->fields([
				'forked_from' => $branch->forkedFrom,
				'tip' => $branch->tip,
				'actor' => $branch->actor,
				'created' => $branch->createdAt,
			])
			->execute();
	}

	/**
	 * The row for one branch.
	 *
	 * @param string $name
	 *   The branch name.
	 *
	 * @return array<string, mixed>|null
	 *   The row, or NULL when the branch is not indexed.
	 */
	public function get(string $name): ?array
	{
		$row = $this->database
			->select(self::TABLE, 'b')
			->fields('b')
			->condition('name', $name)
			->execute()
			?->fetchAssoc();

		return $row === false || $row === null ? null : $row;
	}

	/**
	 * Every indexed branch, newest first.
	 *
	 * @return list<array<string, mixed>>
	 *   The rows.
	 */
	public function all(): array
	{
		return $this->database
			->select(self::TABLE, 'b')
			->fields('b')
			->orderBy('created', 'DESC')
			->orderBy('name')
			->execute()
			?->fetchAll(FetchAs::Associative) ?? [];
	}

	/**
	 * How many branches are indexed.
	 *
	 * @return int
	 *   The count.
	 */
	public function count(): int
	{
		return (int) $this->database
			->select(self::TABLE, 'b')
			->countQuery()
			->execute()
			?->fetchField();
	}

	/**
	 * Removes the row for one branch.
	 *
	 * @param string $name
	 *   The branch name.
	 *
	 * @return int
	 *   How many rows were removed.
	 */
	public function forget(string $name): int
	{
		return (int) $this->database->delete(self::TABLE)->condition('name', $name)->execute();
	}

	/**
	 * Drops every row.
	 *
	 * @return int
	 *   How many rows were removed.
	 */
	public function clear(): int
	{
		return (int) $this->database->delete(self::TABLE)->execute();
	}
}
