<?php

declare(strict_types=1);

namespace Drupal\strata\Journal;

/**
 * Where a captured mutation lives, and the first half of every journal key.
 *
 * A realm names the subsystem a value was read out of and has to be written back into. It decides
 * which writer a restore hands an op to, and whether a default restore touches the op at all.
 *
 * The case values are stored in the journal and in every serialized op, so a case can gain a label
 * but never a new value.
 *
 * @see JournalOp
 * @see Verb
 */
enum Realm: string
{
	// content entities and their revisions, subject `type:id`
	case ENTITY = 'entity';

	// simple configuration and config entities, subject the config name
	case CONFIG = 'config';

	// the state system, subject the state key
	case STATE = 'state';

	// a key-value collection, subject `collection:key`
	case KEY_VALUE = 'keyvalue';

	// rows in a table no entity owns, subject `table:pk=value`
	case TABLE = 'table';

	// table structure rather than table contents, subject the table name
	case SCHEMA = 'schema';

	// managed and unmanaged files, subject the stream-wrapper uri
	case FILE = 'file';

	// caches, sessions, locks and queues, subject the bin key
	case EPHEMERAL = 'ephemeral';

	// extensions on disk, subject the extension name
	case CODE = 'code';

	/**
	 * Names the realm for an operator-facing table or a log line.
	 *
	 * Never parse this back; Realm::from() reads the case value, which is what the journal stores.
	 *
	 * @return string
	 *   Title Case name of the realm.
	 */
	public function label(): string
	{
		return match ($this) {
			self::ENTITY => 'Entity',
			self::CONFIG => 'Configuration',
			self::STATE => 'State',
			self::KEY_VALUE => 'Key-Value',
			self::TABLE => 'Table',
			self::SCHEMA => 'Schema',
			self::FILE => 'File',
			self::EPHEMERAL => 'Ephemeral',
			self::CODE => 'Code',
		};
	}

	/**
	 * Whether a default restore writes ops in this realm back.
	 *
	 * Ephemeral state is captured so a restore can report what it invalidated, and skipped by
	 * default because the restored data rebuilds it; writing a stale cache entry over a live bin
	 * costs more than a rebuild.
	 *
	 * @return bool
	 *   TRUE for every realm except EPHEMERAL.
	 */
	public function isRestorable(): bool
	{
		return $this !== self::EPHEMERAL;
	}
}
