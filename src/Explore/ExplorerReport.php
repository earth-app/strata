<?php

declare(strict_types=1);

namespace Drupal\strata\Explore;

use JsonSerializable;

/**
 * What the store holds, and what deleting one thing would cost.
 *
 * The three reachability classes are reported separately rather than as one total, because they
 * answer different questions. Frames held by a commit go when that commit goes; frames held by a
 * dictionary or by a delta chain are held by something a reader did not ask about and would be
 * surprised to lose.
 *
 * `complete` is the field that decides whether the rest can be trusted. A reachability walk over a
 * store whose commit chain is broken cannot enumerate everything that is reachable, so anything it
 * calls collectable might in fact be needed. A prune refuses in that state, and so does this report:
 * the collectable figure is reported as unknown rather than as zero.
 *
 * @see StorageExplorer
 */
final class ExplorerReport implements JsonSerializable
{
	/**
	 * Constructs a report.
	 *
	 * @param int $frames
	 *   Frames the index holds.
	 * @param int $rawBytes
	 *   Decoded bytes those frames represent.
	 * @param int $storedBytes
	 *   Bytes actually written.
	 * @param int $commits
	 *   Commits the index holds.
	 * @param int $dictionaries
	 *   Dictionaries the store holds.
	 * @param array<string, int> $reachable
	 *   How many frames each reachability class holds.
	 * @param list<string> $collectable
	 *   Frame addresses nothing reachable references.
	 * @param int $collectableBytes
	 *   Bytes those frames occupy.
	 * @param bool $complete
	 *   Whether the reachability walk finished.
	 * @param array<string, int> $byRealm
	 *   Stored bytes per realm, as far as the commit index can attribute them.
	 * @param float $seconds
	 *   How long the walk took.
	 */
	public function __construct(
		public readonly int $frames = 0,
		public readonly int $rawBytes = 0,
		public readonly int $storedBytes = 0,
		public readonly int $commits = 0,
		public readonly int $dictionaries = 0,
		public readonly array $reachable = [],
		public readonly array $collectable = [],
		public readonly int $collectableBytes = 0,
		public readonly bool $complete = true,
		public readonly array $byRealm = [],
		public readonly float $seconds = 0.0,
	) {}

	/**
	 * The compression ratio across the whole store.
	 *
	 * @return float
	 *   Raw over stored, or 1.0 when nothing is stored.
	 */
	public function ratio(): float
	{
		return $this->storedBytes > 0 ? $this->rawBytes / $this->storedBytes : 1.0;
	}

	/**
	 * How many frames could be collected.
	 *
	 * @return int|null
	 *   The count, or NULL when the walk did not finish and the answer is unknown.
	 */
	public function collectableCount(): ?int
	{
		return $this->complete ? count($this->collectable) : null;
	}

	/**
	 * The share of stored bytes nothing needs.
	 *
	 * @return float
	 *   Between 0.0 and 1.0, or 0.0 when the answer is unknown.
	 */
	public function wasteShare(): float
	{
		if (!$this->complete || $this->storedBytes === 0) {
			return 0.0;
		}

		return $this->collectableBytes / $this->storedBytes;
	}

	/**
	 * A one-line summary.
	 *
	 * @return string
	 *   What the store holds.
	 */
	public function summary(): string
	{
		$summary = sprintf(
			'%d frames, %d commits, %d dictionaries, %s stored at %.2fx',
			$this->frames,
			$this->commits,
			$this->dictionaries,
			$this->bytes($this->storedBytes),
			$this->ratio(),
		);

		if (!$this->complete) {
			return $summary . '; reachability incomplete, so nothing is safe to collect';
		}
		if ($this->collectable === []) {
			return $summary . '; nothing is collectable';
		}

		return $summary .
			sprintf(
				'; %d frames (%s, %.1f%%) are collectable',
				count($this->collectable),
				$this->bytes($this->collectableBytes),
				$this->wasteShare() * 100,
			);
	}

	/**
	 * {@inheritdoc}
	 */
	public function jsonSerialize(): array
	{
		return [
			'frames' => $this->frames,
			'raw_bytes' => $this->rawBytes,
			'stored_bytes' => $this->storedBytes,
			'commits' => $this->commits,
			'dictionaries' => $this->dictionaries,
			'ratio' => round($this->ratio(), 3),
			'reachable' => $this->reachable,
			'collectable' => $this->collectableCount(),
			'collectable_bytes' => $this->collectableBytes,
			'waste_share' => round($this->wasteShare(), 4),
			'complete' => $this->complete,
			'by_realm' => $this->byRealm,
			'seconds' => round($this->seconds, 4),
		];
	}

	/**
	 * Renders a byte count in the largest unit that stays readable.
	 *
	 * @param int $bytes
	 *   The count.
	 *
	 * @return string
	 *   The rendered size.
	 */
	private function bytes(int $bytes): string
	{
		$units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
		$value = (float) $bytes;
		$unit = 0;

		while ($value >= 1024.0 && $unit < count($units) - 1) {
			$value /= 1024.0;
			$unit++;
		}

		return $unit === 0
			? sprintf('%d %s', (int) $value, $units[$unit])
			: sprintf('%.1f %s', $value, $units[$unit]);
	}
}
