<?php

declare(strict_types=1);

namespace Drupal\strata\Journal;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use RuntimeException;

/**
 * A journal backed by a database table.
 *
 * The default, and the one the test lane runs against, because it needs nothing beyond the database
 * Drupal already has. A Redis-backed journal is faster on a busy site but is optional; this one is
 * always available, which is what a capture path needs.
 *
 * The append is a single INSERT with no read before it. The sequence comes from the table's serial
 * column, so two concurrent requests get a total order without either of them locking anything.
 *
 * @see JournalInterface
 */
final class DatabaseJournal implements JournalInterface
{
	/**
	 * The table operations are appended to.
	 */
	public const TABLE = 'strata_journal';

	/**
	 * Constructs a journal.
	 *
	 * @param Connection $database
	 *   The database to append to.
	 */
	public function __construct(private readonly Connection $database) {}

	/**
	 * {@inheritdoc}
	 */
	public function append(JournalOp $operation, ?string $payload = null): JournalOp
	{
		try {
			$sequence = $this->database
				->insert(self::TABLE)
				->fields([
					'microtime' => $operation->microtime,
					'realm' => $operation->realm->value,
					'subject' => $operation->subject,
					'verb' => $operation->verb->value,
					'actor' => $operation->actor,
					'request_id' => $operation->requestId,
					'payload_hash' => $operation->payloadHash,
					'parent_hash' => $operation->parentHash,
					'payload_length' => $operation->payloadLength,
					'label' => $operation->label,
					'fields' => (string) json_encode($operation->fields),
					'payload' => $payload,
				])
				->execute();
		} catch (DatabaseExceptionWrapper $e) {
			throw new RuntimeException(
				sprintf('Cannot append %s to the journal: %s', $operation->key(), $e->getMessage()),
				0,
				$e,
			);
		}

		return $operation->withSequence((int) $sequence);
	}

	/**
	 * {@inheritdoc}
	 */
	public function read(int $limit = 5000): array
	{
		if ($limit < 1) {
			return [];
		}

		$rows = $this->database
			->select(self::TABLE, 'j')
			->fields('j')
			->orderBy('sequence')
			->range(0, $limit)
			->execute()
			?->fetchAll();

		$entries = [];

		foreach ($rows ?? [] as $row) {
			/** @var list<string> $fields */
			$fields = json_decode((string) ($row->fields ?? '[]'), true) ?: [];

			$entries[] = [
				'operation' => new JournalOp(
					(int) $row->sequence,
					(int) $row->microtime,
					Realm::from((string) $row->realm),
					(string) $row->subject,
					Verb::from((string) $row->verb),
					$row->actor === null ? null : (int) $row->actor,
					$row->request_id === null ? null : (string) $row->request_id,
					$row->payload_hash === null ? null : (string) $row->payload_hash,
					$row->parent_hash === null ? null : (string) $row->parent_hash,
					(int) $row->payload_length,
					(string) $row->label,
					$fields,
				),
				'payload' => $row->payload === null ? null : (string) $row->payload,
			];
		}

		return $entries;
	}

	/**
	 * {@inheritdoc}
	 */
	public function trim(int $throughSequence): int
	{
		return (int) $this->database
			->delete(self::TABLE)
			->condition('sequence', $throughSequence, '<=')
			->execute();
	}

	/**
	 * {@inheritdoc}
	 */
	public function pending(): int
	{
		return (int) $this->database
			->select(self::TABLE, 'j')
			->countQuery()
			->execute()
			?->fetchField();
	}

	/**
	 * {@inheritdoc}
	 */
	public function pendingBytes(): int
	{
		$query = $this->database->select(self::TABLE, 'j');
		$query->addExpression('SUM([j].[payload_length])', 'total');

		return (int) $query->execute()?->fetchField();
	}

	/**
	 * {@inheritdoc}
	 */
	public function oldest(): ?int
	{
		$query = $this->database->select(self::TABLE, 'j');
		$query->addExpression('MIN([j].[microtime])', 'oldest');

		$value = $query->execute()?->fetchField();

		return $value === null || $value === false ? null : (int) $value;
	}

	/**
	 * {@inheritdoc}
	 */
	public function clear(): int
	{
		return (int) $this->database->delete(self::TABLE)->execute();
	}
}
