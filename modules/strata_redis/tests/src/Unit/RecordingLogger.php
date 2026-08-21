<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_redis\Unit;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A logger that keeps what it was told.
 *
 * Redis being absent is the normal case rather than a fault, and the difference between the two is
 * whether anything was logged, so several tests here assert on the log rather than on a return value.
 */
class RecordingLogger extends AbstractLogger
{
	/**
	 * Everything logged, oldest first.
	 *
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	public array $records = [];

	/**
	 * {@inheritdoc}
	 */
	public function log(mixed $level, string|Stringable $message, array $context = []): void
	{
		$this->records[] = [
			'level' => is_scalar($level) ? (string) $level : get_debug_type($level),
			'message' => (string) $message,
			'context' => $context,
		];
	}

	/**
	 * Everything logged at one level, with each record's context flattened onto its message.
	 *
	 * The messages carry placeholders and the values sit in the context, so a test asserting on what
	 * an operator reads has to see both halves.
	 *
	 * @param string $level
	 *   The level, such as "info" or "warning".
	 *
	 * @return string
	 *   One line per record, oldest first.
	 */
	public function at(string $level): string
	{
		$lines = [];

		foreach ($this->records as $record) {
			if ($record['level'] !== $level) {
				continue;
			}

			$line = $record['message'];

			foreach ($record['context'] as $name => $value) {
				$line .= sprintf(
					' %s=%s',
					$name,
					is_scalar($value) ? (string) $value : get_debug_type($value),
				);
			}

			$lines[] = $line;
		}

		return implode("\n", $lines);
	}
}
