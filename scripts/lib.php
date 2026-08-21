<?php

/**
 * @file
 * Shared helpers for the demo scripts `startup.sh` runs.
 *
 * These files run in the global namespace through `drush php:script`, so `Drupal` and `DOMDocument`
 * are already their own short names and importing them is a no-op PHP warns about. Everything under
 * a namespace is imported as usual.
 */

declare(strict_types=1);

use Drupal\strata\Engine;

/**
 * The assembled engine.
 *
 * @return Engine
 *   The engine.
 */
function strata_engine(): Engine
{
	return Drupal::service('strata.engine');
}

/**
 * Prints a heading.
 *
 * @param string $text
 *   The heading.
 */
function strata_head(string $text): void
{
	print "\n" . strtoupper($text) . "\n" . str_repeat('=', max(8, strlen($text))) . "\n";
}

/**
 * Prints one labelled figure.
 *
 * @param string $label
 *   The label.
 * @param string $value
 *   The figure.
 */
function strata_row(string $label, string $value): void
{
	printf("  %-34s %s\n", $label, $value);
}

/**
 * Bytes as something readable.
 *
 * @param int|float $bytes
 *   The count.
 *
 * @return string
 *   The formatted size.
 */
function strata_bytes(int|float $bytes): string
{
	$units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
	$at = 0;
	$size = (float) $bytes;

	while ($size >= 1024 && $at < count($units) - 1) {
		$size /= 1024;
		$at++;
	}

	return $at === 0 ? sprintf('%d B', (int) $size) : sprintf('%.2f %s', $size, $units[$at]);
}

/**
 * One positional argument, or a default.
 *
 * @param array<int, string> $extra
 *   What `drush php:script` passed after the script name.
 * @param int $at
 *   Which argument.
 * @param string $default
 *   Used when the argument is absent or empty.
 *
 * @return string
 *   The value.
 */
function strata_arg(array $extra, int $at, string $default = ''): string
{
	$value = trim((string) ($extra[$at] ?? ''));

	return $value === '' ? $default : $value;
}

/**
 * A scale name or a raw count, as a count.
 *
 * @param string $scale
 *   One of `small`, `medium`, `large`, `huge`, or a number.
 *
 * @return int
 *   How many subjects to work with.
 */
function strata_scale(string $scale): int
{
	if (ctype_digit($scale)) {
		return max(1, (int) $scale);
	}

	return match ($scale) {
		'small' => 25,
		'medium' => 200,
		'large' => 1000,
		'huge' => 5000,
		default => 25,
	};
}

/**
 * Seals whatever is captured and prints what the flush did.
 *
 * @return bool
 *   TRUE when a commit was written.
 */
function strata_flush(): bool
{
	$result = strata_engine()->flusher()->flush(true);

	if (!$result->ran) {
		strata_row('flush', $result->summary());

		return false;
	}

	strata_row('flush', sprintf('%s -> %s', $result->summary(), substr($result->commit, 0, 12)));

	return true;
}

/**
 * Every object key the store holds under a prefix.
 *
 * @param string $prefix
 *   The prefix.
 *
 * @return array<int, string>
 *   The keys.
 */
function strata_keys(string $prefix = ''): array
{
	return strata_engine()->provider()->list($prefix, null, 5000)->keys();
}

/**
 * The commit the ref points at.
 *
 * @return string
 *   The id, or an empty string when nothing has been sealed.
 */
function strata_head_commit(): string
{
	return (string) (strata_engine()->commitIndex()->newest()['id'] ?? '');
}
