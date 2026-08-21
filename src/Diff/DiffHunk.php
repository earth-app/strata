<?php

declare(strict_types=1);

namespace Drupal\strata\Diff;

use JsonSerializable;

/**
 * One run of lines that a diff says are the same, added, removed or changed.
 *
 * Structured rather than a formatted string, because the operator-facing table needs a class per
 * line and a formatted diff would have to be parsed back to get one. Core's `DiffFormatter` produces
 * classic diff text, which is the right output for a patch and the wrong one for a table with two
 * columns.
 *
 * Line numbers are 1-based and are the numbers in the value the lines came from, so a hunk deep in a
 * long field reports where it is rather than where it is in the hunk.
 *
 * @see FieldDiff
 */
final class DiffHunk implements JsonSerializable
{
	/**
	 * Lines present in both values.
	 */
	public const COPY = 'copy';

	/**
	 * Lines only in the later value.
	 */
	public const ADD = 'add';

	/**
	 * Lines only in the earlier value.
	 */
	public const DELETE = 'delete';

	/**
	 * Lines that differ between the two values.
	 */
	public const CHANGE = 'change';

	/**
	 * Constructs a hunk.
	 *
	 * @param string $type
	 *   One of COPY, ADD, DELETE or CHANGE.
	 * @param list<string> $before
	 *   Lines as they were.
	 * @param list<string> $after
	 *   Lines as they became.
	 * @param int $beforeLine
	 *   The line the earlier run starts at, counting from one.
	 * @param int $afterLine
	 *   The line the later run starts at, counting from one.
	 */
	public function __construct(
		public readonly string $type,
		public readonly array $before = [],
		public readonly array $after = [],
		public readonly int $beforeLine = 0,
		public readonly int $afterLine = 0,
	) {}

	/**
	 * Whether this hunk represents a difference.
	 *
	 * @return bool
	 *   FALSE for a copied run.
	 */
	public function isChange(): bool
	{
		return $this->type !== self::COPY;
	}

	/**
	 * How many lines the hunk covers.
	 *
	 * @return int
	 *   The larger of the two runs.
	 */
	public function height(): int
	{
		return max(count($this->before), count($this->after));
	}

	/**
	 * {@inheritdoc}
	 */
	public function jsonSerialize(): array
	{
		return [
			'type' => $this->type,
			'before' => $this->before,
			'after' => $this->after,
			'before_line' => $this->beforeLine,
			'after_line' => $this->afterLine,
		];
	}
}
