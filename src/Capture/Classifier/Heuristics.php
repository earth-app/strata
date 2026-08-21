<?php

declare(strict_types=1);

namespace Drupal\strata\Capture\Classifier;

/**
 * Guesses what a key is for, from what it is called.
 *
 * Drupal's ephemeral keyspace is named by convention rather than by contract, and the conventions
 * are strong enough to classify most of it: anything under `cache` is a cache, anything under
 * `lock` is a lock, `queue` holds work, `flood` holds a security counter. So a first pass over a
 * discovered keyspace can place the great majority of it without asking anyone.
 *
 * Every rule here is a GUESS and is recorded as one. A rule that matches produces a classification
 * with `Heuristics::SOURCE` attached, and a human decision always outranks it - see
 * ClassificationRegistry, which never overwrites a human's answer with a rule's. That distinction is
 * what makes it safe to add rules: the worst a wrong rule can do is be corrected, not silently
 * override a decision someone already made.
 *
 * The rules are ordered and the first match wins, so a specific pattern must sit above the general
 * one it would otherwise be swallowed by.
 *
 * @see Classification
 * @see ClassificationRegistry
 */
final class Heuristics
{
	/**
	 * Recorded against any classification a rule produced.
	 */
	public const SOURCE = 'heuristic';

	/**
	 * Recorded against a classification a person chose.
	 */
	public const HUMAN = 'human';

	/**
	 * Glob patterns keyed to what they mean, most specific first.
	 *
	 * @var array<string, Classification>
	 */
	public const RULES = [
		// expirable stores come first: their names contain the names of the persistent ones, so a
		// broader rule below would otherwise swallow them and mark a cache authoritative
		'key_value_expire*' => Classification::DERIVABLE,
		'keyvalue.expirable*' => Classification::DERIVABLE,
		'*expirable*' => Classification::DERIVABLE,
		'*_expire*' => Classification::DERIVABLE,
		'*.expire' => Classification::DERIVABLE,
		'tempstore*' => Classification::DERIVABLE,

		// work nobody else has a record of: losing a queue loses the work in it
		'queue*' => Classification::AUTHORITATIVE,
		'*.queue' => Classification::AUTHORITATIVE,
		'reliable_queue*' => Classification::AUTHORITATIVE,

		// a security counter; rebuilding it as empty hands an attacker their attempts back
		'flood*' => Classification::AUTHORITATIVE,

		// someone is logged in with this
		'session*' => Classification::AUTHORITATIVE,
		'php_session*' => Classification::AUTHORITATIVE,

		// a semaphore mid-operation; restoring a stale one deadlocks whatever waits on it
		'semaphore*' => Classification::AUTHORITATIVE,

		// key-value collections are persistent by name, and are captured in their own realm
		'key_value*' => Classification::AUTHORITATIVE,
		'keyvalue*' => Classification::AUTHORITATIVE,

		// a lock is only meaningful while its holder is running, and its holder is not
		'lock*' => Classification::DERIVABLE,

		// every cache bin rebuilds itself on demand, by definition
		'cache*' => Classification::DERIVABLE,
		'*_cache' => Classification::DERIVABLE,
		'cachetags*' => Classification::DERIVABLE,
		'render*' => Classification::DERIVABLE,
		'page*' => Classification::DERIVABLE,
		'dynamic_page_cache*' => Classification::DERIVABLE,
		'discovery*' => Classification::DERIVABLE,
		'bootstrap*' => Classification::DERIVABLE,
		'config*' => Classification::DERIVABLE,
		'container*' => Classification::DERIVABLE,
		'menu*' => Classification::DERIVABLE,
		'entity*' => Classification::DERIVABLE,
		'data*' => Classification::DERIVABLE,
		'default*' => Classification::DERIVABLE,
		'jsonapi*' => Classification::DERIVABLE,
		'library*' => Classification::DERIVABLE,
		'toolbar*' => Classification::DERIVABLE,
	];

	/**
	 * Classifies one key.
	 *
	 * @param string $key
	 *   The key or namespace, such as "cache_render" or "queue:aggregator_feeds".
	 *
	 * @return Classification
	 *   What the rules make of it, or Classification::UNCLASSIFIED when nothing matched.
	 */
	public static function classify(string $key): Classification
	{
		$normalised = self::normalise($key);

		if ($normalised === '') {
			return Classification::UNCLASSIFIED;
		}

		foreach (self::RULES as $pattern => $classification) {
			if (self::matches($pattern, $normalised)) {
				return $classification;
			}
		}

		return Classification::UNCLASSIFIED;
	}

	/**
	 * The pattern that classified a key, for the audit trail.
	 *
	 * A registry stores rules by pattern rather than by key, so a thousand cache keys under one bin
	 * become one row rather than a thousand. This is how the row is named.
	 *
	 * @param string $key
	 *   The key or namespace.
	 *
	 * @return string
	 *   The matching pattern, or the key's own namespace with a wildcard when nothing matched.
	 */
	public static function patternFor(string $key): string
	{
		$normalised = self::normalise($key);

		foreach (array_keys(self::RULES) as $pattern) {
			if (self::matches($pattern, $normalised)) {
				return $pattern;
			}
		}

		return self::namespaceOf($normalised) . '*';
	}

	/**
	 * The leading namespace of a key.
	 *
	 * Keys arrive as `bin:key`, `bin.key` or `bin_key` depending on who wrote them, so all three
	 * separators are treated as the boundary. A key with no separator is its own namespace.
	 *
	 * @param string $key
	 *   The key.
	 *
	 * @return string
	 *   The namespace, without a trailing separator.
	 */
	public static function namespaceOf(string $key): string
	{
		$normalised = self::normalise($key);
		$at = strcspn($normalised, ':.');

		return $at === strlen($normalised) ? $normalised : substr($normalised, 0, $at);
	}

	/**
	 * Whether a glob pattern matches a key.
	 *
	 * `fnmatch()` is not used: it is unavailable on some Windows builds of PHP and its behaviour
	 * around separators differs by platform, which would make a classification depend on the host.
	 *
	 * @param string $pattern
	 *   A pattern using `*` as the only wildcard.
	 * @param string $key
	 *   The key to test.
	 *
	 * @return bool
	 *   TRUE when the pattern matches.
	 */
	public static function matches(string $pattern, string $key): bool
	{
		$expression =
			'/^' . str_replace('\*', '.*', preg_quote(self::normalise($pattern), '/')) . '$/';

		return preg_match($expression, self::normalise($key)) === 1;
	}

	/**
	 * Lowercases and trims a key so matching does not depend on how it was written.
	 *
	 * @param string $key
	 *   The key.
	 *
	 * @return string
	 *   The normalised form.
	 */
	private static function normalise(string $key): string
	{
		return strtolower(trim($key));
	}
}
