<?php

declare(strict_types=1);

namespace Drupal\strata\Tree;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * Where every subject was last stored, kept locally so a flush reads nothing.
 *
 * Two things need this and neither can afford a fetch.
 *
 * An anchor names the subjects that changed since the anchor before it. Working that set out from the
 * store would mean reading every segment written since - 960 objects at the shipped intervals - on
 * every anchor.
 *
 * Delta coding needs the address of a subject's previous version, because a rewritten value compresses
 * against its own predecessor 63.70x better than against anything else. Resolving that from the anchor
 * chain would cost reads on the flush path, which is the one place that cannot afford them.
 *
 * So this is a complete local index rather than a per-interval accumulator, and it is **never cleared
 * by writing an anchor**. An earlier version truncated it at each anchor, which left the first rewrite
 * after every anchor with no parent to code against - the delta gain silently applied to some writes
 * and not others. Rows are selected by when they last changed instead.
 *
 * **Local and derivable, which is why it lives in a table rather than in the bucket.** Every row is
 * recoverable from the anchors, so an uninstall dropping it loses nothing: the next anchor is written
 * full, which is correct if more expensive, and delta coding resumes as subjects are written again.
 *
 * @see BaseWriter
 * @see BasePolicy
 */
final class SubjectIndex
{
	/**
	 * The table subjects are indexed in.
	 */
	public const TABLE = 'strata_subject';

	/**
	 * Rows read in one batch when an anchor is written.
	 *
	 * A bound on memory rather than on correctness: the read pages until the set is exhausted.
	 */
	public const BATCH = 5000;

	/**
	 * Constructs an index.
	 *
	 * @param Connection $database
	 *   The connection holding the table.
	 */
	public function __construct(private readonly Connection $database) {}

	/**
	 * Records where each subject a window touched is now stored.
	 *
	 * @param array<string, array{frames: list<string>, size: int}|null> $subjects
	 *   Subject path keyed to its frame map, or NULL for a subject the window deleted.
	 * @param int $microtime
	 *   Unix microseconds the window was sealed at.
	 *
	 * @return int
	 *   How many subjects were recorded.
	 */
	public function record(array $subjects, int $microtime): int
	{
		$written = 0;

		foreach ($subjects as $subject => $entry) {
			$this->database
				->merge(self::TABLE)
				->key('subject', (string) $subject)
				->fields([
					'frames' => (string) json_encode($entry['frames'] ?? []),
					'size' => $entry === null ? 0 : $entry['size'],
					'deleted' => $entry === null ? 1 : 0,
					'updated' => $microtime,
				])
				->execute();

			$written++;
		}

		return $written;
	}

	/**
	 * Subjects that changed after a given moment.
	 *
	 * What the next anchor records. Bounded by time rather than by emptying the table, so a subject
	 * appears in exactly the one anchor that follows its change and stays available to delta code
	 * against afterwards.
	 *
	 * @param int $since
	 *   Unix microseconds, exclusive. Zero returns everything.
	 *
	 * @return array<string, array{frames: list<string>, size: int}|null>
	 *   Subject path keyed to its frame map, or NULL for a deletion.
	 */
	public function changedSince(int $since): array
	{
		$changes = [];
		$offset = 0;

		while (true) {
			$query = $this->database
				->select(self::TABLE, 's')
				->fields('s')
				->orderBy('subject')
				->range($offset, self::BATCH);

			if ($since > 0) {
				$query->condition('updated', $since, '>');
			}

			$rows = $query->execute()?->fetchAll(FetchAs::Associative) ?? [];

			if ($rows === []) {
				return $changes;
			}

			foreach ($rows as $row) {
				$subject = (string) $row['subject'];

				if ((int) $row['deleted'] === 1) {
					$changes[$subject] = null;

					continue;
				}

				$changes[$subject] = [
					'frames' => $this->decodeFrames((string) $row['frames']),
					'size' => (int) $row['size'],
				];
			}

			$offset += self::BATCH;
		}
	}

	/**
	 * Every subject the index knows about.
	 *
	 * @return array<string, array{frames: list<string>, size: int}|null>
	 *   Subject path keyed to its frame map, or NULL for a deletion.
	 */
	public function all(): array
	{
		return $this->changedSince(0);
	}

	/**
	 * The frame map one subject was last stored under.
	 *
	 * What delta coding codes against.
	 *
	 * @param string $subject
	 *   Subject path.
	 *
	 * @return list<string>
	 *   Frame addresses, empty when the subject is unknown or was deleted.
	 */
	public function frames(string $subject): array
	{
		$row =
			$this->database
				->select(self::TABLE, 's')
				->fields('s', ['frames', 'deleted'])
				->condition('subject', $subject)
				->execute()
				?->fetchAssoc() ?:
			null;

		return $row === null || (int) $row['deleted'] === 1
			? []
			: $this->decodeFrames((string) $row['frames']);
	}

	/**
	 * When one subject was last written.
	 *
	 * What a restore compares against its target to find out whether it would discard somebody's
	 * later edit.
	 *
	 * @param string $subject
	 *   Subject path.
	 *
	 * @return int|null
	 *   Unix microseconds, or NULL when the index has never seen the subject.
	 */
	public function changedAt(string $subject): ?int
	{
		$found =
			$this->database
				->select(self::TABLE, 's')
				->fields('s', ['updated'])
				->condition('subject', $subject)
				->execute()
				?->fetchField() ?? false;

		return $found === false ? null : (int) $found;
	}

	/**
	 * How many subjects the index holds.
	 *
	 * @return int
	 *   The count, including the deleted ones.
	 */
	public function count(): int
	{
		return (int) $this->database
			->select(self::TABLE, 's')
			->countQuery()
			->execute()
			?->fetchField();
	}

	/**
	 * How many subjects changed after a given moment.
	 *
	 * @param int $since
	 *   Unix microseconds, exclusive.
	 *
	 * @return int
	 *   The count.
	 */
	public function countSince(int $since): int
	{
		return (int) $this->database
			->select(self::TABLE, 's')
			->condition('updated', $since, '>')
			->countQuery()
			->execute()
			?->fetchField();
	}

	/**
	 * Empties the index.
	 *
	 * Called by a reindex, which rebuilds it, and never by writing an anchor: the rows are what the
	 * next delta codes against.
	 *
	 * @return int
	 *   How many rows were removed.
	 */
	public function clear(): int
	{
		return (int) $this->database->delete(self::TABLE)->execute();
	}

	/**
	 * The frame list a stored row holds.
	 *
	 * @param string $json
	 *   The stored column.
	 *
	 * @return list<string>
	 *   Frame addresses.
	 */
	private function decodeFrames(string $json): array
	{
		/** @var mixed $frames */
		$frames = json_decode($json, true);
		$addresses = [];

		foreach (is_array($frames) ? $frames : [] as $frame) {
			$addresses[] = (string) $frame;
		}

		return $addresses;
	}
}
