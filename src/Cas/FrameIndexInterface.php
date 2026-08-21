<?php

declare(strict_types=1);

namespace Drupal\strata\Cas;

/**
 * Tracks which frames the store already holds.
 *
 * Deduplication is the whole reason this exists: before a frame is compressed, sealed and uploaded,
 * the index is asked whether its content address is already present. A hit costs one local lookup
 * and skips the entire pipeline plus a network round trip.
 *
 * It also holds the reference counts a prune depends on. A frame is collectable only when nothing
 * points at it, and the three things that can point at one - a segment, a tree and a file map - are
 * counted the same way.
 *
 * The index is derived state. Everything in it can be rebuilt from the objects in the bucket by
 * `strata:reindex`, which is what makes it safe for a module uninstall to drop the tables.
 *
 * @see FrameRecord
 * @see ObjectStore
 */
interface FrameIndexInterface
{
	/**
	 * Whether the store already holds a frame.
	 *
	 * @param string $hash
	 *   Content address of the decoded frame.
	 *
	 * @return bool
	 *   TRUE when the frame is present and the pipeline can be skipped.
	 */
	public function has(string $hash): bool;

	/**
	 * The record for a frame.
	 *
	 * @param string $hash
	 *   Content address of the decoded frame.
	 *
	 * @return FrameRecord|null
	 *   The record, or NULL when the frame is unknown.
	 */
	public function get(string $hash): ?FrameRecord;

	/**
	 * Records a newly stored frame, or increments an existing one.
	 *
	 * Recording a frame the index already holds adds a reference rather than replacing the record,
	 * because the stored bytes are identical by construction and the location is already correct.
	 *
	 * @param FrameRecord $record
	 *   The record.
	 *
	 * @return FrameRecord
	 *   The stored record, with its resulting reference count.
	 */
	public function record(FrameRecord $record): FrameRecord;

	/**
	 * Replaces where a frame lives and how it is encoded, keeping its reference count.
	 *
	 * What compaction needs. Recompressing a frame changes its object, its offset, its codec and its
	 * stored size, and changes nothing about its content address or who points at it - the address is
	 * the digest of the decoded bytes, so it survives a change of encoding by construction.
	 *
	 * Distinct from FrameIndexInterface::record(), which treats a frame it already holds as a second
	 * reference to the same bytes in the same place and must not move it.
	 *
	 * @param FrameRecord $record
	 *   The record's new location and encoding. Its reference count is ignored in favour of the one
	 *   already stored.
	 *
	 * @return FrameRecord
	 *   The stored record, with the reference count it kept.
	 */
	public function relocate(FrameRecord $record): FrameRecord;

	/**
	 * Adds references to a frame.
	 *
	 * @param string $hash
	 *   Content address of the decoded frame.
	 * @param int $count
	 *   How many references to add.
	 *
	 * @return int
	 *   The resulting reference count, or 0 when the frame is unknown.
	 */
	public function reference(string $hash, int $count = 1): int;

	/**
	 * Removes references from a frame.
	 *
	 * The record is kept at zero references rather than deleted, so a prune can find it, report
	 * what it is about to remove, and be told not to.
	 *
	 * @param string $hash
	 *   Content address of the decoded frame.
	 * @param int $count
	 *   How many references to remove.
	 *
	 * @return int
	 *   The resulting reference count, never below zero.
	 */
	public function dereference(string $hash, int $count = 1): int;

	/**
	 * Frames nothing points at any more.
	 *
	 * @param int $limit
	 *   Most records to return.
	 *
	 * @return list<FrameRecord>
	 *   Collectable records.
	 */
	public function orphans(int $limit = 1000): array;

	/**
	 * One page of every frame the index holds, referenced or not, oldest first.
	 *
	 * A pass that has to touch the whole store rather than a reachable part of it needs this:
	 * `orphans()` answers what is collectable and a tree walk answers what is reachable, and a key
	 * rotation is neither. Ordered by creation and then by address, so paging is stable while frames
	 * are being added.
	 *
	 * @param int $limit
	 *   Most records to return.
	 * @param int $offset
	 *   Records to skip.
	 *
	 * @return list<FrameRecord>
	 *   The page, empty once the offset is past the end.
	 */
	public function page(int $limit = 1000, int $offset = 0): array;

	/**
	 * Removes records for frames that have been deleted from the store.
	 *
	 * @param list<string> $hashes
	 *   Content addresses to forget.
	 *
	 * @return int
	 *   How many records were removed.
	 */
	public function forget(array $hashes): int;

	/**
	 * Frames that decode against a given frame.
	 *
	 * The upward half of delta-chain reachability: a frame nothing references directly is still not
	 * collectable while something that IS referenced decodes against it.
	 *
	 * @param string $hash
	 *   Content address of the candidate parent.
	 * @param int $limit
	 *   Most records to return, so the query stays bounded on a frame with many children.
	 *
	 * @return list<FrameRecord>
	 *   Records naming this frame as their delta parent.
	 */
	public function dependents(string $hash, int $limit = 100): array;

	/**
	 * Frames whose delta chains are at least a given depth, deepest first.
	 *
	 * What a re-anchoring pass works from. Ordered deepest first because re-anchoring the deepest link
	 * shortens every chain hanging off it, so one rewrite can bring several chains back inside the cap.
	 *
	 * @param int $minimum
	 *   Fewest links a chain must have to be returned. Zero returns every delta frame.
	 * @param int $limit
	 *   Most records to return, so a pass over a store with many long chains stays bounded.
	 *
	 * @return list<FrameRecord>
	 *   The records, deepest chain first.
	 */
	public function deepestChains(int $minimum, int $limit = 100): array;

	/**
	 * Dictionaries the store cannot decode without.
	 *
	 * @return array<string, int>
	 *   Dictionary id keyed to how many referenced frames need it. A dictionary absent from this
	 *   map is not needed by anything that is still reachable.
	 */
	public function dictionaries(): array;

	/**
	 * Drops every record.
	 *
	 * Used by `strata:reindex`, which rebuilds the whole index from the bucket and must not
	 * inherit a stale row for an object that is no longer there.
	 *
	 * @return int
	 *   How many records were removed.
	 */
	public function clear(): int;

	/**
	 * What the store holds, for the settings form and the storage explorer.
	 *
	 * @return array{frames: int, rawBytes: int, storedBytes: int, orphans: int, ratio: float}
	 *   Frame count, decoded and stored byte totals, how many frames are collectable, and the
	 *   overall compression ratio.
	 */
	public function statistics(): array;
}
