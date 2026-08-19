<?php

declare(strict_types=1);

namespace Drupal\strata\Cas;

/**
 * An index held in memory.
 *
 * Used by the unit lane and by a one-shot command that builds an index it does not need to keep.
 * A site uses the database-backed index, which survives a request.
 *
 * @see FrameIndexInterface
 */
final class MemoryFrameIndex implements FrameIndexInterface
{
	/**
	 * Records keyed by content address.
	 *
	 * @var array<string, FrameRecord>
	 */
	private array $records = [];

	/**
	 * {@inheritdoc}
	 */
	public function has(string $hash): bool
	{
		return isset($this->records[$hash]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get(string $hash): ?FrameRecord
	{
		return $this->records[$hash] ?? null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function record(FrameRecord $record): FrameRecord
	{
		$existing = $this->records[$record->hash] ?? null;

		if ($existing !== null) {
			$this->records[$record->hash] = $existing->withReferences(
				$existing->references + max(1, $record->references),
			);

			return $this->records[$record->hash];
		}

		$this->records[$record->hash] = $record;

		return $record;
	}

	/**
	 * {@inheritdoc}
	 */
	public function reference(string $hash, int $count = 1): int
	{
		$record = $this->records[$hash] ?? null;

		if ($record === null) {
			return 0;
		}

		$this->records[$hash] = $record->withReferences($record->references + max(0, $count));

		return $this->records[$hash]->references;
	}

	/**
	 * {@inheritdoc}
	 */
	public function dereference(string $hash, int $count = 1): int
	{
		$record = $this->records[$hash] ?? null;

		if ($record === null) {
			return 0;
		}

		$this->records[$hash] = $record->withReferences(
			max(0, $record->references - max(0, $count)),
		);

		return $this->records[$hash]->references;
	}

	/**
	 * {@inheritdoc}
	 */
	public function orphans(int $limit = 1000): array
	{
		$orphans = [];

		foreach ($this->records as $record) {
			if (count($orphans) >= $limit) {
				break;
			}
			if ($record->isOrphan()) {
				$orphans[] = $record;
			}
		}

		return $orphans;
	}

	/**
	 * {@inheritdoc}
	 */
	public function forget(array $hashes): int
	{
		$removed = 0;

		foreach ($hashes as $hash) {
			if (isset($this->records[$hash])) {
				unset($this->records[$hash]);
				$removed++;
			}
		}

		return $removed;
	}

	/**
	 * {@inheritdoc}
	 */
	public function statistics(): array
	{
		$raw = 0;
		$stored = 0;
		$orphans = 0;

		foreach ($this->records as $record) {
			$raw += $record->rawSize;
			$stored += $record->storedSize;

			if ($record->isOrphan()) {
				$orphans++;
			}
		}

		return [
			'frames' => count($this->records),
			'rawBytes' => $raw,
			'storedBytes' => $stored,
			'orphans' => $orphans,
			'ratio' => $stored > 0 ? $raw / $stored : 1.0,
		];
	}
}
