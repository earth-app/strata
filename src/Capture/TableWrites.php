<?php

declare(strict_types=1);

namespace Drupal\strata\Capture;

use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;
use JsonSerializable;

/**
 * What one table saw during one request.
 *
 * The statement tap folds many statements per table into one of these, so a request writing four
 * hundred rows to one table produces one operation rather than four hundred.
 *
 * The verb it keeps is the most consequential one seen, not the last. A window holding an insert
 * and a truncate is described by the truncate, because that is what a reader has to notice, and a
 * structural change outranks every content change for the same reason.
 *
 * @see EventSubscriber\StatementCaptureSubscriber
 */
final class TableWrites implements JsonSerializable
{
	/**
	 * Constructs a record of one table's writes.
	 *
	 * @param Realm $realm
	 *   Realm::TABLE for row changes, Realm::SCHEMA once anything structural has been seen.
	 * @param Verb $verb
	 *   The most consequential verb seen.
	 * @param int $statements
	 *   How many statements were folded in.
	 * @param array<string, int> $keywords
	 *   Leading keyword keyed to how many statements began with it.
	 * @param string $sample
	 *   The shape of the statement that set the current verb, safe to show in a timeline.
	 */
	private function __construct(
		public readonly Realm $realm,
		public readonly Verb $verb,
		public readonly int $statements,
		public readonly array $keywords,
		public readonly string $sample,
	) {}

	/**
	 * Starts a record from the first statement seen for a table.
	 *
	 * @param SqlStatement $statement
	 *   The classified statement.
	 * @param string $sample
	 *   Its shape.
	 *
	 * @return self
	 *   The record.
	 */
	public static function from(SqlStatement $statement, string $sample): self
	{
		return new self(
			$statement->realm,
			$statement->verb,
			1,
			[$statement->keyword => 1],
			$sample,
		);
	}

	/**
	 * Folds another statement in.
	 *
	 * @param SqlStatement $statement
	 *   The classified statement.
	 * @param string $sample
	 *   Its shape, kept only when it becomes the described statement.
	 *
	 * @return self
	 *   A new record.
	 */
	public function fold(SqlStatement $statement, string $sample): self
	{
		$keywords = $this->keywords;
		$keywords[$statement->keyword] = ($keywords[$statement->keyword] ?? 0) + 1;

		if (!$this->outrankedBy($statement)) {
			return new self(
				$this->realm,
				$this->verb,
				$this->statements + 1,
				$keywords,
				$this->sample,
			);
		}

		return new self(
			$statement->realm,
			$statement->verb,
			$this->statements + 1,
			$keywords,
			$sample,
		);
	}

	/**
	 * Whether a statement is more consequential than what is recorded.
	 *
	 * @param SqlStatement $statement
	 *   The new statement.
	 *
	 * @return bool
	 *   TRUE when it should replace the recorded realm and verb.
	 */
	public function outrankedBy(SqlStatement $statement): bool
	{
		if ($statement->realm === Realm::SCHEMA && $this->realm !== Realm::SCHEMA) {
			return true;
		}
		if ($statement->realm !== Realm::SCHEMA && $this->realm === Realm::SCHEMA) {
			return false;
		}

		return $statement->verb->isDestructive() && !$this->verb->isDestructive();
	}

	/**
	 * The keywords seen, as the field names an operation records.
	 *
	 * A statement has no fields, so the keywords are what a diff viewer can usefully list: they say
	 * whether the window inserted, updated, deleted or restructured.
	 *
	 * @return list<string>
	 *   The keywords, in first-seen order.
	 */
	public function fieldNames(): array
	{
		return array_map('strval', array_keys($this->keywords));
	}

	/**
	 * The label the timeline shows.
	 *
	 * @param string $table
	 *   The table these writes were against.
	 *
	 * @return string
	 *   Something such as "3 writes to node_field_data".
	 */
	public function label(string $table): string
	{
		return sprintf(
			'%d %s to %s',
			$this->statements,
			$this->realm === Realm::SCHEMA ? 'schema changes' : 'writes',
			$table,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The record as the payload an operation carries.
	 */
	public function jsonSerialize(): array
	{
		return [
			'statements' => $this->statements,
			'keywords' => $this->keywords,
			'sample' => $this->sample,
		];
	}
}
