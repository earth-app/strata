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
	public function relocate(FrameRecord $record): FrameRecord
	{
		$existing = $this->records[$record->hash] ?? null;

		return $this->records[$record->hash] = $record->withReferences(
			$existing === null ? $record->references : $existing->references,
		);
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
	public function page(int $limit = 1000, int $offset = 0): array
	{
		$records = array_values($this->records);

		usort(
			$records,
			static fn(FrameRecord $a, FrameRecord $b): int => [$a->created, $a->hash] <=> [
				$b->created,
				$b->hash,
			],
		);

		return array_slice($records, max(0, $offset), max(0, $limit));
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
	public function dependents(string $hash, int $limit = 100): array
	{
		$dependents = [];

		foreach ($this->records as $record) {
			if (count($dependents) >= $limit) {
				break;
			}
			if ($record->deltaParent === $hash) {
				$dependents[] = $record;
			}
		}

		return $dependents;
	}

	/**
	 * {@inheritdoc}
	 */
	public function deepestChains(int $minimum, int $limit = 100): array
	{
		$found = [];

		foreach ($this->records as $record) {
			if ($record->isDelta() && $record->deltaDepth >= max(0, $minimum)) {
				$found[] = $record;
			}
		}

		usort(
			$found,
			static fn(FrameRecord $a, FrameRecord $b): int => $b->deltaDepth <=> $a->deltaDepth ?:
			strcmp($a->hash, $b->hash),
		);

		return array_slice($found, 0, max(0, $limit));
	}

	/**
	 * {@inheritdoc}
	 */
	public function dictionaries(): array
	{
		$dictionaries = [];

		foreach ($this->records as $record) {
			if ($record->dictionary === null || $record->isOrphan()) {
				continue;
			}

			$dictionaries[$record->dictionary] = ($dictionaries[$record->dictionary] ?? 0) + 1;
		}

		return $dictionaries;
	}

	/**
	 * {@inheritdoc}
	 */
	public function clear(): int
	{
		$removed = count($this->records);
		$this->records = [];

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
