<?php

declare(strict_types=1);

namespace Drupal\strata\Journal;

/**
 * What a captured mutation did to its subject.
 *
 * The three value verbs - create, update and delete - describe a change to the contents of one
 * subject, which is what makes a run of them foldable into a net effect. Rename, truncate and ddl
 * change the shape or the identity of the subject itself, so each one stays its own op.
 *
 * The case values are stored in the journal and in every serialized op, so a case can gain a label
 * but never a new value.
 *
 * @see JournalOp
 * @see Realm
 */
enum Verb: string
{
	// the subject did not exist and now does
	case CREATE = 'create';

	// the subject existed and its value changed
	case UPDATE = 'update';

	// the subject existed and now does not
	case DELETE = 'delete';

	// the subject kept its value under a new identity
	case RENAME = 'rename';

	// every row of the subject was removed in one statement
	case TRUNCATE = 'truncate';

	// the structure of the subject changed
	case DDL = 'ddl';

	/**
	 * Names the verb for an operator-facing table or a log line.
	 *
	 * Never parse this back; Verb::from() reads the case value, which is what the journal stores.
	 *
	 * @return string
	 *   Title Case name of the verb.
	 */
	public function label(): string
	{
		return match ($this) {
			self::CREATE => 'Create',
			self::UPDATE => 'Update',
			self::DELETE => 'Delete',
			self::RENAME => 'Rename',
			self::TRUNCATE => 'Truncate',
			self::DDL => 'DDL',
		};
	}

	/**
	 * Whether the mutation removed data that only a restore brings back.
	 *
	 * Read by the UI to mark a window that needs a confirmation, and by the engine to keep a
	 * destructive op out of an automatic prune.
	 *
	 * @return bool
	 *   TRUE for DELETE, TRUNCATE and DDL.
	 */
	public function isDestructive(): bool
	{
		return match ($this) {
			self::DELETE, self::TRUNCATE, self::DDL => true,
			self::CREATE, self::UPDATE, self::RENAME => false,
		};
	}

	/**
	 * Whether the mutation took the subject out of existence.
	 *
	 * Narrower than a destructive verb, and the difference matters to what a commit covers. A
	 * truncated table still exists with nothing in it and is still a restore target; a deleted node
	 * is gone and listing it would offer a restore of nothing.
	 *
	 * @return bool
	 *   TRUE for DELETE only.
	 */
	public function removesSubject(): bool
	{
		return $this === self::DELETE;
	}
}
