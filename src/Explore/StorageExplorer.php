<?php

declare(strict_types=1);

namespace Drupal\strata\Explore;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\strata\Cas\FrameIndexInterface;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Codec\Dictionary\DictionaryStore;
use Drupal\strata\Compaction\Reachability;
use Drupal\strata\Journal\DatabaseJournal;
use Drupal\strata\Segment\SegmentReader;
use Drupal\strata\Tree\CommitIndex;
use Throwable;

/**
 * Answers what the store holds now, and what deleting something would take with it.
 *
 * **The three reachability classes are what makes this more than a size report.** An operator looking
 * at a commit wants to know what pruning it would free, and the honest answer is almost never the
 * bytes that commit wrote: a frame it references may be shared with fifty other commits, may be the
 * anchor a live delta chain decodes against, or may be a dictionary a thousand frames name. So the
 * cost of removing a commit is computed by asking what would become unreachable, never by adding up
 * what it wrote.
 *
 * **Nothing here deletes anything.** The explorer is a read; `Compactor::prune()` is the write, and
 * it does its own reachability walk rather than trusting a number a page rendered earlier. A store
 * that changed between the preview and the confirmation is the normal case on a live site.
 *
 * @see ExplorerReport
 * @see Reachability
 */
final class StorageExplorer
{
	/**
	 * Collectable frames one report lists.
	 */
	public const MAX_COLLECTABLE = 1_000;

	/**
	 * Constructs an explorer.
	 *
	 * @param Reachability $reachability
	 *   Walks the three classes.
	 * @param FrameIndexInterface $frames
	 *   Supplies the store totals.
	 * @param CommitIndex $commits
	 *   Supplies the commit count and per-commit sizes.
	 * @param DictionaryStore $dictionaries
	 *   Supplies what dictionaries exist.
	 * @param SegmentReader $segments
	 *   Reads which frames a commit's own segment references.
	 * @param Connection $database
	 *   Reads the per-realm attribution out of the journal.
	 */
	public function __construct(
		private readonly Reachability $reachability,
		private readonly FrameIndexInterface $frames,
		private readonly CommitIndex $commits,
		private readonly DictionaryStore $dictionaries,
		private readonly SegmentReader $segments,
		private readonly Connection $database,
	) {}

	/**
	 * What the store holds.
	 *
	 * @param int $limit
	 *   Collectable frames to list.
	 *
	 * @return ExplorerReport
	 *   The report.
	 */
	public function report(int $limit = self::MAX_COLLECTABLE): ExplorerReport
	{
		$started = microtime(true);
		$statistics = $this->frames->statistics();
		$complete = $this->reachability->isComplete();
		$collectable = [];
		$collectableBytes = 0;

		if ($complete) {
			foreach ($this->reachability->collectableFrames($limit) as $record) {
				$collectable[] = $record->hash;
				$collectableBytes += $record->storedSize;
			}
		}

		return new ExplorerReport(
			(int) $statistics['frames'],
			(int) $statistics['rawBytes'],
			(int) $statistics['storedBytes'],
			$this->commits->count(),
			count($this->dictionaries->ids()),
			$this->reachability->statistics(),
			$collectable,
			$collectableBytes,
			$complete,
			$this->byRealm(),
			microtime(true) - $started,
		);
	}

	/**
	 * What removing one commit would make unreachable.
	 *
	 * The frames that commit references are checked one at a time against every other reachability
	 * class, so a frame shared with another commit, held by a delta chain, or named by a dictionary is
	 * reported as retained rather than as freed.
	 *
	 * @param string $commit
	 *   The commit id.
	 *
	 * @return RemovalCost
	 *   What would be freed, what would be kept and why, and whether the walk that decided it
	 *   finished.
	 */
	public function costOfRemoving(string $commit): RemovalCost
	{
		$row = $this->commits->get($commit);
		$freed = [];
		$retained = [];
		$bytes = 0;

		if ($row === null || !$this->reachability->isComplete()) {
			return new RemovalCost($commit, [], [], 0, false);
		}

		foreach ($this->framesOf($commit) as $hash) {
			$reason = $this->reachability->frameReason($hash);

			if ($reason !== null) {
				$retained[$hash] = $reason;

				continue;
			}

			$record = $this->frames->get($hash);

			// present in the segment and absent from the index; its size is unknown, not zero
			if ($record === null) {
				$retained[$hash] = 'the frame is referenced but not indexed';

				continue;
			}

			$freed[] = $hash;
			$bytes += $record->storedSize;
		}

		return new RemovalCost($commit, $freed, $retained, $bytes, true);
	}

	/**
	 * The largest commits by what they wrote.
	 *
	 * @param int $limit
	 *   Most rows to return.
	 *
	 * @return list<array<string, mixed>>
	 *   Commit index rows, largest first.
	 */
	public function largestCommits(int $limit = 20): array
	{
		return $this->database
			->select(CommitIndex::TABLE, 'c')
			->fields('c')
			->orderBy('stored_bytes', 'DESC')
			->range(0, max(1, $limit))
			->execute()
			?->fetchAll(FetchAs::Associative) ?? [];
	}

	/**
	 * Why one dictionary cannot be removed.
	 *
	 * @param string $id
	 *   The dictionary id.
	 *
	 * @return string|null
	 *   The reason, or NULL when nothing needs it.
	 */
	public function dictionaryReason(string $id): ?string
	{
		return $this->reachability->dictionaryReason($id);
	}

	/**
	 * Frames a commit's own segment references.
	 *
	 * Only the segment's frames, not the anchor's. An anchor is shared by every commit between it and
	 * the next one, so attributing its frames to any single commit would report the same bytes as
	 * freeable several times over.
	 *
	 * @param string $commit
	 *   The commit id.
	 *
	 * @return list<string>
	 *   Frame addresses.
	 */
	private function framesOf(string $commit): array
	{
		$row = $this->commits->get($commit);
		$key = (string) ($row['segment_key'] ?? '');

		if ($row === null || $key === '') {
			return [];
		}

		try {
			return $this->segments->read($key)->frames();
		} catch (Throwable) {
			return [];
		}
	}

	/**
	 * Stored bytes attributed to each realm.
	 *
	 * Attributed from the journal's pending operations and the commit labels, which is what is
	 * available locally: a frame is content-addressed and carries no realm, so the store itself cannot
	 * say which realm a given frame belongs to. The figures are therefore a breakdown of what has been
	 * captured rather than of what is on disk, and the explorer labels them that way.
	 *
	 * @return array<string, int>
	 *   Realm value keyed to bytes.
	 */
	private function byRealm(): array
	{
		$query = $this->database->select(DatabaseJournal::TABLE, 'j');
		$query->addField('j', 'realm');
		$query->addExpression('COALESCE(SUM([j].[payload_length]), 0)', 'bytes');
		$query->groupBy('j.realm');

		$rows = $query->execute()?->fetchAll(FetchAs::Associative) ?? [];
		$byRealm = [];

		foreach ($rows as $row) {
			$byRealm[(string) $row['realm']] = (int) $row['bytes'];
		}

		return $byRealm;
	}

	/**
	 * Shortens a frame address for a table cell.
	 *
	 * @param string $hash
	 *   The address.
	 *
	 * @return string
	 *   The abbreviated form.
	 */
	public static function abbreviate(string $hash): string
	{
		return Hash::isValid($hash) ? Hash::abbreviate($hash) : $hash;
	}
}
