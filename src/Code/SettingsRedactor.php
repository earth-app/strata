<?php

declare(strict_types=1);

namespace Drupal\strata\Code;

/**
 * Strips the secrets out of `settings.php` before it is stored.
 *
 * `settings.php` is worth backing up: it carries the trusted host patterns, the config sync
 * directory, the reverse-proxy setup and every override a site depends on. It also carries the
 * database password, the hash salt and whatever API keys the site put there, and those must not
 * leave the server - a bucket someone can read is a bucket someone can read.
 *
 * So the file is stored with every assignment to a known-secret key replaced, and the replacement is
 * a marker rather than an empty string. A restore that wrote an empty password would produce a site
 * that cannot connect to its own database and a stack trace that says nothing about why; a marker
 * says exactly what happened and exactly what has to be filled in.
 *
 * **The redaction is by key name, and that is a decision with a limit.** A secret in a key nobody
 * anticipated is not caught. The list below is what Drupal and the common contributed modules
 * actually use, and it errs wide: redacting a value that turns out not to be secret costs an operator
 * one line to re-enter, while missing one puts a credential in an object store.
 *
 * @see CodeScanner
 */
final class SettingsRedactor
{
	/**
	 * What a redacted value is replaced with.
	 *
	 * Deliberately not empty and deliberately not valid: a restore has to fail loudly rather than
	 * come up with a blank password and an inscrutable error.
	 */
	public const MARKER = 'STRATA_REDACTED_SET_THIS_BEFORE_USE';

	/**
	 * Key fragments whose values are treated as secret, lowercased.
	 *
	 * Matched as substrings of the assignment target, so `$databases['default']['default']['password']`
	 * is caught by `password`, `$settings['hash_salt']` by `hash_salt`, and the `'password' =>` line
	 * inside an array literal by the same fragment. The literal form is the one Drupal's own
	 * `settings.php` ships, so a rule that only matched variable paths would leave the database
	 * password in cleartext on every real site.
	 */
	public const SECRETS = [
		'password',
		'passwd',
		'hash_salt',
		'secret',
		'private_key',
		'api_key',
		'apikey',
		'access_key',
		'secret_key',
		'token',
		'credential',
		'salt',
		'dsn',
		'sentry',
		'stripe',
		'twilio',
		'mailgun',
		'sendgrid',
		'smtp_pass',
		'aws_secret',
	];

	/**
	 * Redacts a settings file's contents.
	 *
	 * Works line by line rather than by parsing PHP. A parser would understand the file better, and
	 * would also mean running the site's own configuration through an evaluator to back it up, which
	 * is a worse trade than being conservative about lines.
	 *
	 * @param string $contents
	 *   The file's contents.
	 *
	 * @return string
	 *   The contents with secret assignments replaced.
	 */
	public static function redact(string $contents): string
	{
		$lines = preg_split('/(\r\n|\n|\r)/', $contents, -1, PREG_SPLIT_DELIM_CAPTURE);

		if ($lines === false) {
			// a file that will not split is stored redacted whole rather than stored raw
			return self::MARKER;
		}

		$out = '';

		foreach ($lines as $line) {
			$out .= self::redactLine($line);
		}

		return $out;
	}

	/**
	 * Whether a line assigns something this treats as secret.
	 *
	 * @param string $line
	 *   One line.
	 *
	 * @return bool
	 *   TRUE when the assignment target names a secret.
	 */
	public static function isSecret(string $line): bool
	{
		$assignment = self::assignmentIn($line);

		if ($assignment === null) {
			return false;
		}

		$target = strtolower(substr($line, 0, $assignment[0]));

		// a variable path, or a quoted key inside an array literal; a comparison is neither
		$assigns =
			str_contains($target, '$') ||
			preg_match('/[\'"][a-z0-9_.\-]+[\'"]\s*$/', $target) === 1;

		if (!$assigns) {
			return false;
		}

		foreach (self::SECRETS as $secret) {
			if (str_contains($target, $secret)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Where a line assigns, and with which operator.
	 *
	 * `=>` is looked for first: inside an array literal it is the assignment, and reading its `=` as
	 * one would rewrite the line into something that no longer parses.
	 *
	 * @param string $line
	 *   One line.
	 *
	 * @return array{int, string}|null
	 *   The offset and the operator, or NULL when the line assigns nothing.
	 */
	private static function assignmentIn(string $line): ?array
	{
		$arrow = strpos($line, '=>');

		if ($arrow !== false) {
			return [$arrow, '=>'];
		}

		$equals = strpos($line, '=');

		return $equals === false ? null : [$equals, '='];
	}

	/**
	 * How many assignments in a file would be redacted.
	 *
	 * Reported alongside a capture so an operator can see the file was handled rather than trusting
	 * that it was.
	 *
	 * @param string $contents
	 *   The file's contents.
	 *
	 * @return int
	 *   The count.
	 */
	public static function count(string $contents): int
	{
		$found = 0;

		foreach (preg_split('/\r\n|\n|\r/', $contents) ?: [] as $line) {
			if (self::isSecret($line)) {
				$found++;
			}
		}

		return $found;
	}

	/**
	 * Replaces the value in one line, keeping everything around it.
	 *
	 * @param string $line
	 *   One line.
	 *
	 * @return string
	 *   The line, redacted when it assigns a secret.
	 */
	private static function redactLine(string $line): string
	{
		$assignment = self::assignmentIn($line);

		if ($assignment === null || !self::isSecret($line)) {
			return $line;
		}

		[$at, $operator] = $assignment;
		$rest = substr($line, $at);

		// the statement terminator is kept, so the file still parses; inside an array that is a comma
		$tail = match (true) {
			str_contains($rest, ';') => ';',
			str_contains($rest, ',') => ',',
			default => '',
		};

		return substr($line, 0, $at) . $operator . " '" . self::MARKER . "'" . $tail;
	}
}
