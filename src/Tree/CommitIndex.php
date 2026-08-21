<?php

declare(strict_types=1);

namespace Drupal\strata\Tree;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * A local index over the commits in the object store.
 *
 * The bucket is the record; this is a copy of the parts of it a page load needs. Rendering a
 * timeline, finding the commit in force at a moment, or counting what a retention level covers all
 * have to answer questions about thousands of commits at once, and answering them by walking parent
 * links through ranged GETs would cost one request per commit.
 *
 * Nothing here is authoritative. Every column is a field of a commit object that already exists
 * under `commits/`, which is what makes it safe for an uninstall to drop the table and for
 * `strata:reindex` to rebuild it.
 *
 * @see CommitLog
 * @see Commit
 */
final class CommitIndex
{
	/**
	 * The table this index lives in.
	 */
	public const TABLE = 'strata_commit';

	/**
	 * Constructs an index.
	 *
	 * @param Connection $database
	 *   The database.
	 */
	public function __construct(private readonly Connection $database) {}

	/**
	 * Records a commit, or updates the row for one already present.
	 *
	 * A commit is immutable and addressed by its content, so re-recording one writes the same values
	 * it already holds. That makes a repeated flush and a repeated reindex both idempotent.
	 *
	 * @param string $id
	 *   The commit id.
	 * @param Commit $commit
	 *   The commit.
	 * @param int $storedBytes
	 *   Bytes the flush actually wrote, which the commit object itself does not know at the time it
	 *   is sealed.
	 */
	public function record(string $id, Commit $commit, int $storedBytes = 0): void
	{
		$this->database
			->merge(self::TABLE)
			->key('id', $id)
			->fields([
				'parent' => $commit->parent,
				'merge_parent' => $commit->merge,
				'index_ref' => $commit->index,
				'chain' => $commit->chain,
				'anchored_at' => $commit->anchoredAt,
				'microtime' => $commit->microtime,
				'label' => mb_substr($commit->label, 0, 255),
				'actor' => $commit->actor,
				'operations' => $commit->operations,
				'raw_bytes' => $commit->rawBytes,
				'stored_bytes' => $storedBytes > 0 ? $storedBytes : $commit->storedBytes,
				'level' => $commit->level,
				'is_base' => $commit->base ? 1 : 0,
				'segment_key' => isset($commit->metadata['segment'])
					? (string) $commit->metadata['segment']
					: null,
			])
			->execute();
	}

	/**
	 * Whether a commit is indexed.
	 *
	 * @param string $id
	 *   The commit id.
	 *
	 * @return bool
	 *   TRUE when a row is present.
	 */
	public function has(string $id): bool
	{
		return (bool) $this->database
			->select(self::TABLE, 'c')
			->fields('c', ['id'])
			->condition('id', $id)
			->range(0, 1)
			->execute()
			?->fetchField();
	}

	/**
	 * The row for one commit.
	 *
	 * @param string $id
	 *   The commit id.
	 *
	 * @return array<string, mixed>|null
	 *   The row, or NULL when the commit is not indexed.
	 */
	public function get(string $id): ?array
	{
		$row = $this->database
			->select(self::TABLE, 'c')
			->fields('c')
			->condition('id', $id)
			->execute()
			?->fetchAssoc();

		return $row === false || $row === null ? null : $row;
	}

	/**
	 * What one commit builds on.
	 *
	 * Two columns rather than the whole row, because a merge base walks thousands of commits and needs
	 * nothing else from any of them.
	 *
	 * @param string $id
	 *   The commit id.
	 *
	 * @return list<string>|null
	 *   Parent addresses, the first parent first, or NULL when the commit is not indexed. An indexed
	 *   root returns an empty list, which is what tells "no parents" apart from "not here".
	 */
	public function parentsOf(string $id): ?array
	{
		$row = $this->database
			->select(self::TABLE, 'c')
			->fields('c', ['parent', 'merge_parent'])
			->condition('id', $id)
			->range(0, 1)
			->execute()
			?->fetchAssoc();

		if ($row === false || $row === null) {
			return null;
		}

		return array_values(
			array_filter([
				$row['parent'] === null ? null : (string) $row['parent'],
				$row['merge_parent'] === null ? null : (string) $row['merge_parent'],
			]),
		);
	}

	/**
	 * The newest indexed commit.
	 *
	 * Read by the status page, which wants the head without a round trip to the bucket.
	 *
	 * @return array<string, mixed>|null
	 *   The row, or NULL when nothing is indexed.
	 */
	public function newest(): ?array
	{
		$row = $this->database
			->select(self::TABLE, 'c')
			->fields('c')
			->orderBy('microtime', 'DESC')
			->range(0, 1)
			->execute()
			?->fetchAssoc();

		return $row === false || $row === null ? null : $row;
	}

	/**
	 * Commits covering a span of time, newest first.
	 *
	 * @param int $fromMicrotime
	 *   Inclusive lower bound in unix microseconds.
	 * @param int $toMicrotime
	 *   Inclusive upper bound in unix microseconds.
	 * @param int $limit
	 *   Most rows to return.
	 * @param int|null $level
	 *   Only commits at this compaction level, or NULL for every level.
	 *
	 * @return list<array<string, mixed>>
	 *   The rows.
	 */
	public function between(
		int $fromMicrotime,
		int $toMicrotime,
		int $limit = 100,
		?int $level = null,
	): array {
		$query = $this->database
			->select(self::TABLE, 'c')
			->fields('c')
			->condition('microtime', $fromMicrotime, '>=')
			->condition('microtime', $toMicrotime, '<=')
			->orderBy('microtime', 'DESC')
			->range(0, max(0, $limit));

		if ($level !== null) {
			$query->condition('level', $level);
		}

		return $query->execute()?->fetchAll(FetchAs::Associative) ?? [];
	}

	/**
	 * How many commits are indexed.
	 *
	 * @return int
	 *   The count.
	 */
	public function count(): int
	{
		return (int) $this->database
			->select(self::TABLE, 'c')
			->countQuery()
			->execute()
			?->fetchField();
	}

	/**
	 * Every indexed commit id, oldest first.
	 *
	 * Used by a verify pass and by the reindex comparison, both of which want the whole set rather
	 * than a page of it.
	 *
	 * @return list<string>
	 *   Commit ids.
	 */
	public function ids(): array
	{
		$rows = $this->database
			->select(self::TABLE, 'c')
			->fields('c', ['id'])
			->orderBy('microtime')
			->orderBy('id')
			->execute()
			?->fetchCol();

		return array_map('strval', $rows ?: []);
	}

	/**
	 * Removes rows for commits that are no longer in the store.
	 *
	 * @param list<string> $ids
	 *   Commit ids to forget.
	 *
	 * @return int
	 *   How many rows were removed.
	 */
	public function forget(array $ids): int
	{
		if ($ids === []) {
			return 0;
		}

		return (int) $this->database->delete(self::TABLE)->condition('id', $ids, 'IN')->execute();
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
