<?php

declare(strict_types=1);

namespace Drupal\strata\Capture;

use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;

/**
 * Classifies one SQL statement as a write, and says what it wrote to.
 *
 * The database service is a factory returning a driver subclass chosen in `settings.php`, so it
 * cannot be decorated and statement events are the only driver-agnostic write tap there is. That
 * makes this class the thing standing between every query a site runs and the journal, which fixes
 * two properties it has to have.
 *
 * **The verb guard comes first and is the cheapest thing here.** A site doing 10 million statements
 * a day runs this on all of them, and all but a few per cent are reads. SqlStatement::isWrite()
 * reads one keyword and answers, so a SELECT costs a substring compare and nothing else. Only a
 * statement that passes gets parsed.
 *
 * **A statement it cannot classify returns NULL rather than a guess.** A wrong table name would
 * attribute a change to the wrong subject, which is worse than not recording it: the reconciler's
 * watermark notices an unrecorded change, and nothing notices one recorded against the wrong table.
 *
 * The query string arrives after table prefixing and brace substitution, so table names here are
 * the real ones the database sees.
 *
 * @see EventSubscriber\StatementCaptureSubscriber
 * @see Reconciler
 */
final class SqlStatement
{
	/**
	 * Leading keywords that mean a statement changes something.
	 *
	 * The guard is a lookup against this, so adding a dialect's write keyword is one line here.
	 */
	public const WRITE_KEYWORDS = [
		'INSERT' => true,
		'UPDATE' => true,
		'DELETE' => true,
		'REPLACE' => true,
		'TRUNCATE' => true,
		'MERGE' => true,
		'UPSERT' => true,
		'CREATE' => true,
		'ALTER' => true,
		'DROP' => true,
		'RENAME' => true,
	];

	/**
	 * Longest keyword the guard has to read, so it never scans further than this.
	 */
	private const MAX_KEYWORD = 9;

	/**
	 * Words that may sit between a write keyword and the table it acts on.
	 *
	 * MySQL's priority hints and SQLite's `INSERT OR <conflict>` clauses, which appear in different
	 * orders and combinations, so they are consumed until none matches.
	 */
	private const MODIFIERS = [
		'LOW_PRIORITY',
		'HIGH_PRIORITY',
		'DELAYED',
		'IGNORE',
		'OR',
		'ROLLBACK',
		'ABORT',
		'FAIL',
		'REPLACE',
	];

	/**
	 * Characters that quote an identifier in one dialect or another.
	 */
	private const QUOTES = '`"[]';

	/**
	 * Constructs a classified statement.
	 *
	 * @param Verb $verb
	 *   What the statement does.
	 * @param Realm $realm
	 *   Realm::TABLE for a statement changing rows, Realm::SCHEMA for one changing structure.
	 * @param string $table
	 *   The table it acts on, unquoted and unqualified.
	 * @param string $keyword
	 *   The leading keyword, kept for the timeline.
	 */
	private function __construct(
		public readonly Verb $verb,
		public readonly Realm $realm,
		public readonly string $table,
		public readonly string $keyword,
	) {}

	#region The Guard

	/**
	 * Whether a statement changes anything.
	 *
	 * The hot path. Reads at most SqlStatement::MAX_KEYWORD characters past any leading whitespace or
	 * comment and compares one uppercased word.
	 *
	 * @param string $sql
	 *   The statement.
	 *
	 * @return bool
	 *   TRUE when the leading keyword is one that writes.
	 */
	public static function isWrite(string $sql): bool
	{
		$offset = self::skipBlanks($sql, 0);
		$length = strspn($sql, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', $offset);

		if ($length < 1 || $length > self::MAX_KEYWORD) {
			return false;
		}

		return isset(self::WRITE_KEYWORDS[strtoupper(substr($sql, $offset, $length))]);
	}

	#endregion

	#region Parsing

	/**
	 * Classifies a statement, or declines to.
	 *
	 * @param string $sql
	 *   The statement.
	 *
	 * @return self|null
	 *   The classification, or NULL when the statement is a read or its target cannot be read out of
	 *   it with certainty.
	 */
	public static function parse(string $sql): ?self
	{
		$offset = self::skipBlanks($sql, 0);
		$keyword = self::word($sql, $offset);

		if ($keyword === null || !isset(self::WRITE_KEYWORDS[$keyword[0]])) {
			return null;
		}

		[$name, $offset] = $keyword;

		return match ($name) {
			'INSERT', 'REPLACE', 'MERGE', 'UPSERT' => self::rows(
				$sql,
				$offset,
				Verb::CREATE,
				$name,
				['INTO'],
			),
			'UPDATE' => self::rows($sql, $offset, Verb::UPDATE, $name, []),
			'DELETE' => self::rows($sql, $offset, Verb::DELETE, $name, ['FROM']),
			'TRUNCATE' => self::rows($sql, $offset, Verb::TRUNCATE, $name, ['TABLE']),
			'CREATE', 'ALTER', 'DROP', 'RENAME' => self::structure($sql, $offset, $name),
		};
	}

	/**
	 * Classifies a statement that changes rows.
	 *
	 * @param string $sql
	 *   The statement.
	 * @param int $offset
	 *   Where to read from, just past the leading keyword.
	 * @param Verb $verb
	 *   The verb this keyword means.
	 * @param string $keyword
	 *   The leading keyword.
	 * @param list<string> $skip
	 *   Words that may sit between the keyword and the table name, each optional.
	 *
	 * @return self|null
	 *   The classification, or NULL when no table name follows.
	 */
	private static function rows(
		string $sql,
		int $offset,
		Verb $verb,
		string $keyword,
		array $skip,
	): ?self {
		// modifiers and conflict clauses sit between the keyword and the table in several dialects,
		// in orders that differ by dialect, so they are consumed until none matches rather than in
		// a fixed sequence
		$allowed = array_merge($skip, self::MODIFIERS);

		while (true) {
			$before = $offset;

			foreach ($allowed as $optional) {
				$offset = self::consume($sql, $offset, $optional);
			}

			if ($offset === $before) {
				break;
			}
		}

		$table = self::identifier($sql, $offset);

		return $table === null ? null : new self($verb, Realm::TABLE, $table, $keyword);
	}

	/**
	 * Classifies a statement that changes structure.
	 *
	 * An index is attributed to the table it sits on, because that is the thing whose shape changed
	 * and the thing a restore would have to rebuild. `DROP INDEX` without an `ON` clause names no
	 * table, so it is declined rather than attributed to the index's own name.
	 *
	 * @param string $sql
	 *   The statement.
	 * @param int $offset
	 *   Where to read from, just past the leading keyword.
	 * @param string $keyword
	 *   The leading keyword.
	 *
	 * @return self|null
	 *   The classification, or NULL when the statement changes something that is not a table.
	 */
	private static function structure(string $sql, int $offset, string $keyword): ?self
	{
		foreach (['TEMPORARY', 'TEMP', 'UNIQUE', 'FULLTEXT', 'SPATIAL'] as $noise) {
			$offset = self::consume($sql, $offset, $noise);
		}

		$object = self::word($sql, $offset);

		if ($object === null) {
			return null;
		}

		[$kind, $offset] = $object;

		if ($kind === 'INDEX') {
			return self::index($sql, $offset, $keyword);
		}
		if ($kind !== 'TABLE') {
			// a view, a sequence, a trigger: structure Strata does not track
			return null;
		}

		$offset = self::consume($sql, $offset, 'IF');
		$offset = self::consume($sql, $offset, 'NOT');
		$offset = self::consume($sql, $offset, 'EXISTS');

		$table = self::identifier($sql, $offset);

		return $table === null ? null : new self(Verb::DDL, Realm::SCHEMA, $table, $keyword);
	}

	/**
	 * Classifies an index statement by the table it names.
	 *
	 * @param string $sql
	 *   The statement.
	 * @param int $offset
	 *   Where to read from, just past the INDEX keyword.
	 * @param string $keyword
	 *   The leading keyword.
	 *
	 * @return self|null
	 *   The classification, or NULL when no table is named.
	 */
	private static function index(string $sql, int $offset, string $keyword): ?self
	{
		$offset = self::consume($sql, $offset, 'IF');
		$offset = self::consume($sql, $offset, 'NOT');
		$offset = self::consume($sql, $offset, 'EXISTS');

		// the index's own name, which is not what changed
		if (self::identifier($sql, $offset) === null) {
			return null;
		}

		$offset = self::past($sql, $offset);
		$offset = self::consume($sql, $offset, 'ON');
		$table = self::identifier($sql, $offset);

		return $table === null ? null : new self(Verb::DDL, Realm::SCHEMA, $table, $keyword);
	}

	#endregion

	#region Reading

	/**
	 * Skips whitespace and comments.
	 *
	 * @param string $sql
	 *   The statement.
	 * @param int $offset
	 *   Where to start.
	 *
	 * @return int
	 *   The first offset holding something other than blank space or a comment.
	 */
	private static function skipBlanks(string $sql, int $offset): int
	{
		while (true) {
			$offset += strspn($sql, " \t\r\n(", $offset);

			if (substr($sql, $offset, 2) === '--') {
				$end = strpos($sql, "\n", $offset);

				if ($end === false) {
					return strlen($sql);
				}

				$offset = $end + 1;

				continue;
			}
			if (substr($sql, $offset, 2) === '/*') {
				$end = strpos($sql, '*/', $offset);

				if ($end === false) {
					return strlen($sql);
				}

				$offset = $end + 2;

				continue;
			}

			return $offset;
		}
	}

	/**
	 * Reads the next bare word, uppercased.
	 *
	 * @param string $sql
	 *   The statement.
	 * @param int $offset
	 *   Where to read from.
	 *
	 * @return array{0: string, 1: int}|null
	 *   The word and the offset just past it, or NULL when no word follows.
	 */
	private static function word(string $sql, int $offset): ?array
	{
		$offset = self::skipBlanks($sql, $offset);
		$length = strspn($sql, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_', $offset);

		if ($length < 1) {
			return null;
		}

		return [strtoupper(substr($sql, $offset, $length)), $offset + $length];
	}

	/**
	 * Skips the next word when it is the one expected.
	 *
	 * @param string $sql
	 *   The statement.
	 * @param int $offset
	 *   Where to read from.
	 * @param string $expected
	 *   The uppercased word to skip.
	 *
	 * @return int
	 *   The offset past the word, or the offset unchanged when the next word is something else.
	 */
	private static function consume(string $sql, int $offset, string $expected): int
	{
		$word = self::word($sql, $offset);

		return $word !== null && $word[0] === $expected ? $word[1] : $offset;
	}

	/**
	 * The offset just past the next identifier.
	 *
	 * @param string $sql
	 *   The statement.
	 * @param int $offset
	 *   Where to read from.
	 *
	 * @return int
	 *   The offset past it, or the offset unchanged when no identifier follows.
	 */
	private static function past(string $sql, int $offset): int
	{
		$start = self::skipBlanks($sql, $offset);
		$length = strspn(
			$sql,
			'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$.' . self::QUOTES,
			$start,
		);

		return $length < 1 ? $offset : $start + $length;
	}

	/**
	 * Reads the next identifier, unquoted and stripped of any schema qualifier.
	 *
	 * @param string $sql
	 *   The statement.
	 * @param int $offset
	 *   Where to read from.
	 *
	 * @return string|null
	 *   The identifier, or NULL when what follows is not one.
	 */
	private static function identifier(string $sql, int $offset): ?string
	{
		$offset = self::skipBlanks($sql, $offset);
		$length = strspn(
			$sql,
			'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$.' . self::QUOTES,
			$offset,
		);

		if ($length < 1) {
			return null;
		}

		$raw = substr($sql, $offset, $length);
		$at = strrpos($raw, '.');
		$name = trim($at === false ? $raw : substr($raw, $at + 1), self::QUOTES);

		// a name that is only punctuation, or a placeholder, is not a table
		if ($name === '' || str_starts_with($name, ':')) {
			return null;
		}

		return $name;
	}

	#endregion

	/**
	 * A statement reduced to its shape, safe to show in a timeline.
	 *
	 * Drupal's query builders bind values as placeholders, so a statement normally carries none. A
	 * module that interpolated a value into the SQL itself would put it in the string, and that string
	 * would then sit in the journal, the commit label and the diff viewer. So single-quoted literals
	 * and digit runs are replaced before anything keeps the text: the query stays recognisable, and no
	 * value rides along with it.
	 *
	 * @param string $sql
	 *   The statement.
	 * @param int $limit
	 *   Most characters to keep.
	 *
	 * @return string
	 *   The shape, whitespace collapsed and truncated.
	 */
	public static function shape(string $sql, int $limit = 200): string
	{
		// single quotes are the only string literal in standard SQL; double quotes are identifiers
		$shape = (string) preg_replace("/'(?:[^']|'')*'/", '?', $sql);
		$shape = (string) preg_replace('/\b\d+\b/', '?', $shape);
		$shape = trim((string) preg_replace('/\s+/', ' ', $shape));

		return strlen($shape) <= $limit ? $shape : substr($shape, 0, $limit - 3) . '...';
	}

	/**
	 * The subject key this statement is recorded under.
	 *
	 * A statement is attributed to its TABLE rather than to a row, because a statement says which rows
	 * it touched only in its WHERE clause and reading that would mean evaluating it. One subject per
	 * table keeps the tree bounded by the schema rather than by traffic, and the reconciler is what
	 * turns a dirty table into per-row detail that can actually be restored.
	 *
	 * @return string
	 *   The subject key.
	 */
	public function subject(): string
	{
		return $this->table;
	}

	/**
	 * Whether this statement changed structure rather than contents.
	 *
	 * @return bool
	 *   TRUE for DDL.
	 */
	public function isStructural(): bool
	{
		return $this->realm === Realm::SCHEMA;
	}
}
