<?php

declare(strict_types=1);

namespace Drupal\strata\Cas;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * The frame index a site runs on.
 *
 * Deduplication only pays if the index outlives the request that built it: a frame written on
 * Monday has to be recognised on Tuesday, or every flush re-uploads content the bucket already
 * holds. The in-memory index is for a single process; this is the one a site uses.
 *
 * Recording a frame is an upsert rather than a read followed by a write, so two concurrent flushes
 * storing the same content cannot lose one another's reference.
 *
 * Every row here is derived from the objects in the bucket and is rebuilt by `strata:reindex`,
 * which is what makes it safe for an uninstall to drop the table.
 *
 * @see FrameIndexInterface
 * @see MemoryFrameIndex
 */
final class DatabaseFrameIndex implements FrameIndexInterface
{
	/**
	 * The table records live in.
	 */
	public const TABLE = 'strata_frame';

	/**
	 * Constructs an index.
	 *
	 * @param Connection $database
	 *   Where records are kept.
	 */
	public function __construct(private readonly Connection $database) {}

	/**
	 * {@inheritdoc}
	 */
	public function has(string $hash): bool
	{
		return (bool) $this->database
			->select(self::TABLE, 'f')
			->fields('f', ['hash'])
			->condition('hash', $hash)
			->range(0, 1)
			->execute()
			?->fetchField();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get(string $hash): ?FrameRecord
	{
		$row = $this->database
			->select(self::TABLE, 'f')
			->fields('f')
			->condition('hash', $hash)
			->execute()
			?->fetchAssoc();

		return $row === false || $row === null ? null : $this->toRecord($row);
	}

	/**
	 * {@inheritdoc}
	 */
	public function record(FrameRecord $record): FrameRecord
	{
		$existing = $this->get($record->hash);

		if ($existing !== null) {
			$references = $this->reference($record->hash, max(1, $record->references));

			return $existing->withReferences($references);
		}

		$this->database
			->merge(self::TABLE)
			->key('hash', $record->hash)
			->fields([
				'raw_size' => $record->rawSize,
				'stored_size' => $record->storedSize,
				'codec' => $record->codec,
				'cipher' => $record->cipher,
				'dictionary' => $record->dictionary,
				'pack' => $record->pack,
				'offset' => $record->offset,
				'refs' => $record->references,
				'created' => $record->created,
				'delta_parent' => $record->deltaParent,
				'delta_depth' => $record->deltaDepth,
			])
			->execute();

		return $record;
	}

	/**
	 * {@inheritdoc}
	 */
	public function relocate(FrameRecord $record): FrameRecord
	{
		$existing = $this->get($record->hash);
		$references = $existing === null ? $record->references : $existing->references;

		$this->database
			->merge(self::TABLE)
			->key('hash', $record->hash)
			->fields([
				'raw_size' => $record->rawSize,
				'stored_size' => $record->storedSize,
				'codec' => $record->codec,
				'cipher' => $record->cipher,
				'dictionary' => $record->dictionary,
				'pack' => $record->pack,
				'offset' => $record->offset,
				'refs' => $references,
				'created' => $record->created,
				'delta_parent' => $record->deltaParent,
				'delta_depth' => $record->deltaDepth,
			])
			->execute();

		return $record->withReferences($references);
	}

	/**
	 * {@inheritdoc}
	 */
	public function reference(string $hash, int $count = 1): int
	{
		if ($count > 0) {
			$this->database
				->update(self::TABLE)
				->expression('refs', '[refs] + :count', [':count' => $count])
				->condition('hash', $hash)
				->execute();
		}

		return $this->references($hash);
	}

	/**
	 * {@inheritdoc}
	 */
	public function dereference(string $hash, int $count = 1): int
	{
		if ($count > 0) {
			// the floor is in the statement so two concurrent prunes cannot drive it negative
			$this->database
				->update(self::TABLE)
				->expression('refs', 'CASE WHEN [refs] > :count THEN [refs] - :count ELSE 0 END', [
					':count' => $count,
				])
				->condition('hash', $hash)
				->execute();
		}

		return $this->references($hash);
	}

	/**
	 * {@inheritdoc}
	 */
	public function orphans(int $limit = 1000): array
	{
		$rows = $this->database
			->select(self::TABLE, 'f')
			->fields('f')
			->condition('refs', 0)
			->orderBy('created')
			->range(0, max(0, $limit))
			->execute()
			?->fetchAll(FetchAs::Associative);

		return array_map(fn(array $row): FrameRecord => $this->toRecord($row), $rows ?? []);
	}

	/**
	 * {@inheritdoc}
	 */
	public function page(int $limit = 1000, int $offset = 0): array
	{
		$rows = $this->database
			->select(self::TABLE, 'f')
			->fields('f')
			->orderBy('created')
			->orderBy('hash')
			->range(max(0, $offset), max(0, $limit))
			->execute()
			?->fetchAll(FetchAs::Associative);

		return array_map(fn(array $row): FrameRecord => $this->toRecord($row), $rows ?? []);
	}

	/**
	 * {@inheritdoc}
	 */
	public function forget(array $hashes): int
	{
		if ($hashes === []) {
			return 0;
		}

		return (int) $this->database
			->delete(self::TABLE)
			->condition('hash', $hashes, 'IN')
			->execute();
	}

	/**
	 * {@inheritdoc}
	 */
	public function dependents(string $hash, int $limit = 100): array
	{
		$rows = $this->database
			->select(self::TABLE, 'f')
			->fields('f')
			->condition('delta_parent', $hash)
			->range(0, max(0, $limit))
			->execute()
			?->fetchAll(FetchAs::Associative);

		return array_map(fn(array $row): FrameRecord => $this->toRecord($row), $rows ?? []);
	}

	/**
	 * {@inheritdoc}
	 */
	public function deepestChains(int $minimum, int $limit = 100): array
	{
		$rows = $this->database
			->select(self::TABLE, 'f')
			->fields('f')
			->isNotNull('delta_parent')
			->condition('delta_depth', max(0, $minimum), '>=')
			->orderBy('delta_depth', 'DESC')
			->orderBy('hash')
			->range(0, max(0, $limit))
			->execute()
			?->fetchAll(FetchAs::Associative);

		return array_map(fn(array $row): FrameRecord => $this->toRecord($row), $rows ?? []);
	}

	/**
	 * {@inheritdoc}
	 */
	public function dictionaries(): array
	{
		$query = $this->database->select(self::TABLE, 'f');
		$query->addField('f', 'dictionary');
		$query->addExpression('COUNT(*)', 'frames');
		$query->isNotNull('f.dictionary');
		$query->condition('f.refs', 0, '>');
		$query->groupBy('f.dictionary');

		$rows = $query->execute()?->fetchAll(FetchAs::Associative) ?? [];
		$dictionaries = [];

		foreach ($rows as $row) {
			$dictionaries[(string) $row['dictionary']] = (int) $row['frames'];
		}

		return $dictionaries;
	}

	/**
	 * {@inheritdoc}
	 */
	public function clear(): int
	{
		return (int) $this->database->delete(self::TABLE)->execute();
	}

	/**
	 * {@inheritdoc}
	 */
	public function statistics(): array
	{
		$query = $this->database->select(self::TABLE, 'f');
		$query->addExpression('COUNT(*)', 'frames');
		$query->addExpression('COALESCE(SUM([f].[raw_size]), 0)', 'raw');
		$query->addExpression('COALESCE(SUM([f].[stored_size]), 0)', 'stored');
		$query->addExpression('SUM(CASE WHEN [f].[refs] = 0 THEN 1 ELSE 0 END)', 'orphans');

		$row = $query->execute()?->fetchAssoc() ?: [];

		$raw = (int) ($row['raw'] ?? 0);
		$stored = (int) ($row['stored'] ?? 0);

		return [
			'frames' => (int) ($row['frames'] ?? 0),
			'rawBytes' => $raw,
			'storedBytes' => $stored,
			'orphans' => (int) ($row['orphans'] ?? 0),
			'ratio' => $stored > 0 ? $raw / $stored : 1.0,
		];
	}

	/**
	 * The current reference count for a frame.
	 *
	 * @param string $hash
	 *   Content address of the decoded frame.
	 *
	 * @return int
	 *   The count, or 0 when the frame is unknown.
	 */
	private function references(string $hash): int
	{
		return (int) $this->database
			->select(self::TABLE, 'f')
			->fields('f', ['refs'])
			->condition('hash', $hash)
			->execute()
			?->fetchField();
	}

	/**
	 * Builds a record from a database row.
	 *
	 * @param array<string, mixed> $row
	 *   The row.
	 *
	 * @return FrameRecord
	 *   The record.
	 */
	private function toRecord(array $row): FrameRecord
	{
		return new FrameRecord(
			(string) $row['hash'],
			(int) $row['raw_size'],
			(int) $row['stored_size'],
			(string) $row['codec'],
			(string) $row['cipher'],
			$row['dictionary'] === null ? null : (string) $row['dictionary'],
			$row['pack'] === null ? null : (string) $row['pack'],
			(int) $row['offset'],
			(int) $row['refs'],
			(int) $row['created'],
			($row['delta_parent'] ?? null) === null ? null : (string) $row['delta_parent'],
			(int) ($row['delta_depth'] ?? 0),
		);
	}
}
