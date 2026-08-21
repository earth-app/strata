<?php

declare(strict_types=1);

namespace Drupal\strata\Drush\Commands;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Tree\RefStore;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared option reading, rendering and confirmation for the command suite.
 *
 * Prose goes to standard error and structured results go to standard output. A command that printed
 * its summary to standard output would put that summary inside the document `--format=json`
 * produced, and the document would no longer parse.
 *
 * Byte counts, moments and digests are rendered in one place, so the same commit reads the same way
 * in a listing, a diff and a rollback plan.
 */
trait StrataOutputTrait
{
	/**
	 * Reads an option that carries a value.
	 *
	 * A value option declares `DrushCommands::REQ` as its default, and Drush replaces that with the
	 * string the operator supplied or with NULL before the command body runs. A method called
	 * directly - from a test, a hook, or another command - still sees the sentinel, and both forms
	 * mean that nothing was supplied. Drush only ever passes a string, so an integer sentinel cannot
	 * be confused with a value.
	 *
	 * @param array<string, mixed> $options
	 *   The options the command was called with.
	 * @param string $name
	 *   The option name.
	 *
	 * @return string|null
	 *   The value, or NULL when none was supplied.
	 */
	protected static function value(array $options, string $name): ?string
	{
		$value = $options[$name] ?? null;

		if ($value === null || $value === DrushCommands::REQ || $value === DrushCommands::OPT) {
			return null;
		}

		return (string) $value;
	}

	/**
	 * Reads an option that carries a whole number.
	 *
	 * @param array<string, mixed> $options
	 *   The options the command was called with.
	 * @param string $name
	 *   The option name.
	 * @param int $fallback
	 *   What to return when nothing was supplied.
	 *
	 * @return int
	 *   The value, or the fallback.
	 */
	protected static function number(array $options, string $name, int $fallback): int
	{
		$value = self::value($options, $name);

		return $value === null || !is_numeric($value) ? $fallback : (int) $value;
	}

	/**
	 * Whether a flag was given.
	 *
	 * @param array<string, mixed> $options
	 *   The options the command was called with.
	 * @param string $name
	 *   The option name.
	 *
	 * @return bool
	 *   TRUE when the flag was supplied.
	 */
	protected static function flag(array $options, string $name): bool
	{
		return ($options[$name] ?? false) !== false;
	}

	/**
	 * The ref an option set names.
	 *
	 * @param array<string, mixed> $options
	 *   The options the command was called with.
	 *
	 * @return string
	 *   The ref name, or the main ref when none was supplied.
	 */
	protected static function ref(array $options): string
	{
		return self::value($options, 'ref') ?? RefStore::MAIN;
	}

	/**
	 * The style prose is written to.
	 *
	 * @return SymfonyStyle
	 *   A style over standard error.
	 */
	protected function prose(): SymfonyStyle
	{
		return $this->io()->getErrorStyle();
	}

	/**
	 * Asks before doing something that removes a restore target or overwrites live data.
	 *
	 * Drush answers this itself under `--yes` and `--no`, so a scripted run never blocks. The
	 * default is no, because the question is only ever asked about something irreversible.
	 *
	 * @param string $question
	 *   What is about to happen, phrased as a question.
	 *
	 * @return bool
	 *   TRUE to go ahead.
	 */
	protected function agreed(string $question): bool
	{
		return $this->io()->confirm($question, false);
	}

	/**
	 * States what a pass did, at the severity the outcome deserves.
	 *
	 * A pass that ran and a pass that declined to run both produce a summary worth reading, and the
	 * difference between them is what an operator watching a scheduled job needs to see.
	 *
	 * @param bool $acted
	 *   TRUE when the pass did the thing it was asked to do.
	 * @param string $summary
	 *   The one-line summary to print.
	 */
	protected function announce(bool $acted, string $summary): void
	{
		if ($acted) {
			$this->prose()->success($summary);

			return;
		}

		$this->prose()->warning($summary);
	}

	/**
	 * Renders a byte count at a readable scale.
	 *
	 * @param int $bytes
	 *   The count.
	 *
	 * @return string
	 *   For example "4.0 MiB".
	 */
	protected static function bytes(int $bytes): string
	{
		$units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
		$value = (float) $bytes;
		$unit = 0;

		while (abs($value) >= 1024.0 && $unit < count($units) - 1) {
			$value /= 1024.0;
			$unit++;
		}

		return sprintf($unit === 0 ? '%d %s' : '%.1f %s', $value, $units[$unit]);
	}

	/**
	 * Renders unix microseconds as a timestamp.
	 *
	 * @param int $microtime
	 *   Microseconds since the epoch, or zero for no moment at all.
	 *
	 * @return string
	 *   An ISO-8601 timestamp, or "-".
	 */
	protected static function moment(int $microtime): string
	{
		return $microtime < 1 ? '-' : date('c', intdiv($microtime, 1_000_000));
	}

	/**
	 * Shortens a content address for a table cell.
	 *
	 * Anything that is not a digest is returned whole, so an object key or a refusal reason passed
	 * here is readable rather than truncated to twelve characters of a path.
	 *
	 * @param string|null $digest
	 *   The digest, or NULL.
	 *
	 * @return string
	 *   The first twelve characters of a digest, the value unchanged when it is not one, or "-".
	 */
	protected static function digest(?string $digest): string
	{
		if ($digest === null || $digest === '') {
			return '-';
		}

		return Hash::isValid($digest) ? Hash::abbreviate($digest) : $digest;
	}

	/**
	 * Renders a duration.
	 *
	 * @param float $seconds
	 *   The duration.
	 *
	 * @return string
	 *   For example "1.24s".
	 */
	protected static function duration(float $seconds): string
	{
		return sprintf('%.2fs', $seconds);
	}

	/**
	 * Renders a boolean as a word.
	 *
	 * @param bool $value
	 *   The value.
	 *
	 * @return string
	 *   Either "yes" or "no".
	 */
	protected static function yesNo(bool $value): string
	{
		return $value ? 'yes' : 'no';
	}
}
