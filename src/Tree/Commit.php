<?php

declare(strict_types=1);

namespace Drupal\strata\Tree;

use Drupal\strata\Cas\Hash;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;

/**
 * One point in the site's history.
 *
 * Shaped like a git commit: a parent, a tree, a time, an actor and a message. A restore target is
 * always a commit, and every commit is reachable from a ref by walking parents.
 *
 * The root commit has no parent and almost every other commit has exactly one, so history is a
 * chain. The single exception is a merge commit, which also names the branch tip it brought in
 * through Commit::$merge, and that is the only way a second line of history becomes reachable from
 * a ref.
 *
 * **`merge` is serialized only when it is set.** A commit is addressed by the bytes of its own JSON,
 * so writing the key as NULL on every commit would change the address of every commit ever written
 * and orphan the whole store. A single-parent commit therefore serializes exactly as it did before
 * merging existed, which CommitTest proves byte for byte.
 *
 * **A commit does not carry an index of the site.** It names the anchor its history resolves against,
 * and only the commit that wrote that anchor is marked as one. Writing a per-commit index was
 * measured at 3,989,289 bytes to record a 201-byte change across 50,000 subjects, because the cost
 * scaled with how many subjects the site had rather than with how many changed. So the index belongs
 * to an anchor on its own interval, and the commits between two anchors inherit its address, its
 * chain length and its time - which keeps a flush at four objects whatever the site's size.
 *
 * @see CommitLog
 * @see BaseManifest
 * @see BasePolicy
 */
final class Commit implements JsonSerializable
{
	/**
	 * Constructs a commit.
	 *
	 * @param string $index
	 *   Address of the base anchor this commit's history resolves against. Its own when this commit
	 *   is an anchor, otherwise the one it inherited from its parent.
	 * @param string|null $parent
	 *   Address of the previous commit, or NULL for the root of history.
	 * @param int $microtime
	 *   Unix microseconds the commit was sealed at.
	 * @param string $label
	 *   Short human summary, such as "42 nodes, 3 config objects".
	 * @param int|null $actor
	 *   Drupal user id responsible, or NULL for cron and other unattended work.
	 * @param int $operations
	 *   Captured operations this commit covers.
	 * @param int $rawBytes
	 *   Decoded bytes the operations described.
	 * @param int $storedBytes
	 *   Bytes actually written, after deduplication, compression and sealing.
	 * @param int $level
	 *   Compaction level; 0 for a freshly flushed commit, higher after a rollup.
	 * @param bool $base
	 *   Whether this commit wrote the anchor it names, so a replay can stop here.
	 * @param int $chain
	 *   How many anchors stand between the one this commit names and the full anchor behind it,
	 *   counting the full one. Carried on the commit so a flush can apply the full-anchor policy
	 *   without reading the chain.
	 * @param int $anchoredAt
	 *   Unix microseconds the anchor this commit names was written at, so a flush can tell whether
	 *   the next anchor is due without reading it.
	 * @param array<string, mixed> $metadata
	 *   Anything a capture wants to carry, such as the segment key it came from.
	 * @param string|null $merge
	 *   Address of the second parent, which is the branch tip a merge brought in, or NULL for every
	 *   other commit. Declared last so every existing positional caller is untouched.
	 *
	 * @throws InvalidArgumentException
	 *   When an address is not a valid digest, a count is negative, or a merge parent is named by a
	 *   commit that has no first parent.
	 */
	public function __construct(
		public readonly string $index,
		public readonly ?string $parent = null,
		public readonly int $microtime = 0,
		public readonly string $label = '',
		public readonly ?int $actor = null,
		public readonly int $operations = 0,
		public readonly int $rawBytes = 0,
		public readonly int $storedBytes = 0,
		public readonly int $level = 0,
		public readonly bool $base = false,
		public readonly int $chain = 0,
		public readonly int $anchoredAt = 0,
		public readonly array $metadata = [],
		public readonly ?string $merge = null,
	) {
		if (!Hash::isValid($index)) {
			throw new InvalidArgumentException(
				'A commit must address its anchor with a valid digest',
			);
		}
		if ($parent !== null && !Hash::isValid($parent)) {
			throw new InvalidArgumentException('A commit parent must be a valid digest');
		}
		if ($merge !== null && !Hash::isValid($merge)) {
			throw new InvalidArgumentException('A commit merge parent must be a valid digest');
		}
		if ($merge !== null && $parent === null) {
			throw new InvalidArgumentException(
				'A merge commit joins two lines of history, so it cannot be the root of one',
			);
		}
		if ($microtime < 0) {
			throw new InvalidArgumentException('A commit time cannot be negative');
		}
		if ($operations < 0 || $rawBytes < 0 || $storedBytes < 0 || $level < 0) {
			throw new InvalidArgumentException('A commit count cannot be negative');
		}
		if ($chain < 0 || $anchoredAt < 0) {
			throw new InvalidArgumentException('A commit anchor cannot be negative');
		}
	}

	/**
	 * This commit's content address.
	 *
	 * Derived from the serialized commit, so an identical commit written twice is the same commit.
	 *
	 * @return string
	 *   A 64-character lowercase hex digest.
	 *
	 * @throws JsonException
	 *   When the label or the metadata holds a string JSON cannot represent. `(string) false` would
	 *   otherwise address every such commit as `Hash::of('')`, so two unrelated commits would be one
	 *   commit and overwrite each other.
	 */
	public function id(): string
	{
		return Hash::of(json_encode($this->jsonSerialize(), JSON_THROW_ON_ERROR));
	}

	/**
	 * Whether this commit begins the history.
	 *
	 * @return bool
	 *   TRUE when it has no parent.
	 */
	public function isRoot(): bool
	{
		return $this->parent === null;
	}

	/**
	 * Whether this commit joined a second line of history.
	 *
	 * @return bool
	 *   TRUE when it names a merge parent.
	 */
	public function isMerge(): bool
	{
		return $this->merge !== null;
	}

	/**
	 * Every commit this one builds on.
	 *
	 * A walk that follows only Commit::$parent stays on the line the ref describes, which is what a
	 * replay wants; a walk that has to decide whether one commit is an ancestor of another needs both,
	 * which is what a merge base wants.
	 *
	 * @return list<string>
	 *   Parent addresses, the first parent first, empty for the root of history.
	 */
	public function parents(): array
	{
		return array_values(array_filter([$this->parent, $this->merge]));
	}

	/**
	 * Whether a replay can stop at this commit rather than walking further back.
	 *
	 * @return bool
	 *   TRUE for a base anchor or the root of history.
	 */
	public function isAnchor(): bool
	{
		return $this->base || $this->isRoot();
	}

	/**
	 * The commit time as a float unix timestamp.
	 *
	 * @return float
	 *   Seconds since the epoch, with microsecond precision.
	 */
	public function timestamp(): float
	{
		return $this->microtime / 1_000_000;
	}

	/**
	 * How much smaller the stored form is than what it describes.
	 *
	 * @return float
	 *   Raw bytes divided by stored bytes; 1.0 when either is zero.
	 */
	public function ratio(): float
	{
		if ($this->rawBytes === 0 || $this->storedBytes === 0) {
			return 1.0;
		}

		return $this->rawBytes / $this->storedBytes;
	}

	/**
	 * The same commit marked as having written its anchor.
	 *
	 * @return self
	 *   A new commit.
	 */
	public function asBase(): self
	{
		return new self(
			$this->index,
			$this->parent,
			$this->microtime,
			$this->label,
			$this->actor,
			$this->operations,
			$this->rawBytes,
			$this->storedBytes,
			$this->level,
			true,
			max(1, $this->chain),
			$this->anchoredAt === 0 ? $this->microtime : $this->anchoredAt,
			$this->metadata,
			$this->merge,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The commit as a plain array. The merge parent appears only on a commit that has one, so a
	 *   single-parent commit hashes to the address it always did.
	 */
	public function jsonSerialize(): array
	{
		$data = [
			'index' => $this->index,
			'parent' => $this->parent,
			'microtime' => $this->microtime,
			'label' => $this->label,
			'actor' => $this->actor,
			'operations' => $this->operations,
			'rawBytes' => $this->rawBytes,
			'storedBytes' => $this->storedBytes,
			'level' => $this->level,
			'base' => $this->base,
			'chain' => $this->chain,
			'anchoredAt' => $this->anchoredAt,
			'metadata' => $this->metadata,
		];

		if ($this->merge !== null) {
			$data['merge'] = $this->merge;
		}

		return $data;
	}

	/**
	 * Rebuilds a commit from its serialized form.
	 *
	 * @param array<string, mixed> $data
	 *   The array produced by Commit::jsonSerialize().
	 *
	 * @return self
	 *   The commit.
	 *
	 * @throws InvalidArgumentException
	 *   When the anchor address is missing or the commit is incoherent.
	 */
	public static function fromArray(array $data): self
	{
		if (!array_key_exists('index', $data)) {
			throw new InvalidArgumentException('A commit is missing "index"');
		}

		/** @var array<string, mixed> $metadata */
		$metadata = $data['metadata'] ?? [];

		return new self(
			(string) $data['index'],
			isset($data['parent']) ? (string) $data['parent'] : null,
			(int) ($data['microtime'] ?? 0),
			(string) ($data['label'] ?? ''),
			isset($data['actor']) ? (int) $data['actor'] : null,
			(int) ($data['operations'] ?? 0),
			(int) ($data['rawBytes'] ?? 0),
			(int) ($data['storedBytes'] ?? 0),
			(int) ($data['level'] ?? 0),
			(bool) ($data['base'] ?? false),
			(int) ($data['chain'] ?? 0),
			(int) ($data['anchoredAt'] ?? 0),
			$metadata,
			isset($data['merge']) ? (string) $data['merge'] : null,
		);
	}
}
