<?php

declare(strict_types=1);

namespace Drupal\strata\Journal;

/**
 * A journal held in memory.
 *
 * Used by the unit lane and by a one-shot command that captures and flushes in a single process. A
 * site uses the database-backed journal, which survives a request ending between capture and flush.
 *
 * @see JournalInterface
 */
final class MemoryJournal implements JournalInterface
{
	/**
	 * Appended entries in capture order, keyed by sequence.
	 *
	 * @var array<int, array{operation: JournalOp, payload: string|null}>
	 */
	private array $entries = [];

	/**
	 * The last sequence assigned.
	 */
	private int $sequence = 0;

	/**
	 * {@inheritdoc}
	 */
	public function append(JournalOp $operation, ?string $payload = null): JournalOp
	{
		$assigned = $operation->withSequence(++$this->sequence);
		$this->entries[$assigned->sequence] = ['operation' => $assigned, 'payload' => $payload];

		return $assigned;
	}

	/**
	 * {@inheritdoc}
	 */
	public function read(int $limit = 5000): array
	{
		return array_values(array_slice($this->entries, 0, max(0, $limit), false));
	}

	/**
	 * {@inheritdoc}
	 */
	public function trim(int $throughSequence): int
	{
		$removed = 0;

		foreach (array_keys($this->entries) as $sequence) {
			if ($sequence <= $throughSequence) {
				unset($this->entries[$sequence]);
				$removed++;
			}
		}

		return $removed;
	}

	/**
	 * {@inheritdoc}
	 */
	public function pending(): int
	{
		return count($this->entries);
	}

	/**
	 * {@inheritdoc}
	 */
	public function pendingBytes(): int
	{
		$bytes = 0;

		foreach ($this->entries as $entry) {
			$bytes += $entry['payload'] === null ? 0 : strlen($entry['payload']);
		}

		return $bytes;
	}

	/**
	 * {@inheritdoc}
	 */
	public function oldest(): ?int
	{
		foreach ($this->entries as $entry) {
			return $entry['operation']->microtime;
		}

		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function clear(): int
	{
		$removed = count($this->entries);
		$this->entries = [];

		return $removed;
	}
}
