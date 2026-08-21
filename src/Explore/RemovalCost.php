<?php

declare(strict_types=1);

namespace Drupal\strata\Explore;

use JsonSerializable;

/**
 * What removing one commit would actually free.
 *
 * The freed and retained lists are both reported because the retained one is the answer an operator
 * did not expect. A commit that wrote four megabytes usually frees far less than that: its frames are
 * shared with the commits around it, held as delta anchors by later versions of the same subject, or
 * named as dictionaries by frames elsewhere in the store.
 *
 * `complete` is false when the reachability walk could not finish, and then nothing here can be acted
 * on. A partial walk cannot prove a frame is unreferenced, only that it did not happen to see a
 * reference, and those are different claims.
 *
 * @see StorageExplorer
 */
final class RemovalCost implements JsonSerializable
{
	/**
	 * Constructs a removal cost.
	 *
	 * @param string $commit
	 *   The commit that was priced.
	 * @param list<string> $freed
	 *   Frame addresses nothing else would need.
	 * @param array<string, string> $retained
	 *   Frame address keyed to why it would still be needed.
	 * @param int $bytes
	 *   Stored bytes the freed frames occupy.
	 * @param bool $complete
	 *   Whether the reachability walk finished, and so whether any of this can be acted on.
	 */
	public function __construct(
		public readonly string $commit,
		public readonly array $freed = [],
		public readonly array $retained = [],
		public readonly int $bytes = 0,
		public readonly bool $complete = true,
	) {}

	/**
	 * How many frames the commit references in total.
	 *
	 * @return int
	 *   Freed plus retained.
	 */
	public function frames(): int
	{
		return count($this->freed) + count($this->retained);
	}

	/**
	 * The share of the commit's frames that removing it would actually free.
	 *
	 * @return float
	 *   Between 0.0 and 1.0, or 0.0 when the commit references nothing.
	 */
	public function freedShare(): float
	{
		$frames = $this->frames();

		return $frames === 0 ? 0.0 : count($this->freed) / $frames;
	}

	/**
	 * A one-line summary.
	 *
	 * @return string
	 *   What would go and what would stay.
	 */
	public function summary(): string
	{
		if (!$this->complete) {
			return 'the reachability walk did not finish, so nothing can be priced';
		}

		return sprintf(
			'%d of %d frames would be freed (%d bytes); %d are still needed elsewhere',
			count($this->freed),
			$this->frames(),
			$this->bytes,
			count($this->retained),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function jsonSerialize(): array
	{
		return [
			'commit' => $this->commit,
			'freed' => $this->freed,
			'retained' => $this->retained,
			'bytes' => $this->bytes,
			'frames' => $this->frames(),
			'freed_share' => round($this->freedShare(), 4),
			'complete' => $this->complete,
		];
	}
}
