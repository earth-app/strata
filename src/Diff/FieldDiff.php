<?php

declare(strict_types=1);

namespace Drupal\strata\Diff;

use JsonSerializable;

/**
 * What changed in one field, setting or column, between two points in time.
 *
 * A field is either scalar or it is not, and the two want different renderings: a title going from
 * one string to another is a single line, and a body going from one paragraph to another is a run of
 * hunks. Both are carried here so the renderer picks rather than guesses, and `isTextual()` is the
 * question it asks.
 *
 * The rendered values are kept alongside the hunks because a field that was added or removed has
 * only one side, and asking a line-level diff to represent "this did not exist" produces a hunk of
 * every line rather than the one fact that matters.
 *
 * @see SubjectDiff
 * @see DiffHunk
 */
final class FieldDiff implements JsonSerializable
{
	/**
	 * The field exists in both and differs.
	 */
	public const CHANGED = 'changed';

	/**
	 * The field only exists in the later value.
	 */
	public const ADDED = 'added';

	/**
	 * The field only exists in the earlier value.
	 */
	public const REMOVED = 'removed';

	/**
	 * Constructs a field diff.
	 *
	 * @param string $name
	 *   The field, setting or column name.
	 * @param string $status
	 *   One of CHANGED, ADDED or REMOVED.
	 * @param string $before
	 *   The earlier value, rendered.
	 * @param string $after
	 *   The later value, rendered.
	 * @param list<DiffHunk> $hunks
	 *   Line-level hunks, empty for a value that is not worth diffing by line.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $status,
		public readonly string $before = '',
		public readonly string $after = '',
		public readonly array $hunks = [],
	) {}

	/**
	 * Whether this field is worth rendering as a line-by-line diff.
	 *
	 * @return bool
	 *   TRUE when there are hunks to draw.
	 */
	public function isTextual(): bool
	{
		return $this->hunks !== [];
	}

	/**
	 * How many lines differ.
	 *
	 * @return int
	 *   Lines across every hunk that is not a copy.
	 */
	public function changedLines(): int
	{
		$lines = 0;

		foreach ($this->hunks as $hunk) {
			if ($hunk->isChange()) {
				$lines += $hunk->height();
			}
		}

		return $lines;
	}

	/**
	 * A one-line description of the change.
	 *
	 * @param int $length
	 *   Characters of a value to show before truncating.
	 *
	 * @return string
	 *   Something a table cell can hold.
	 */
	public function describe(int $length = 60): string
	{
		return match ($this->status) {
			self::ADDED => sprintf('added: %s', $this->clip($this->after, $length)),
			self::REMOVED => sprintf('removed: %s', $this->clip($this->before, $length)),
			default => sprintf(
				'%s -> %s',
				$this->clip($this->before, $length),
				$this->clip($this->after, $length),
			),
		};
	}

	/**
	 * {@inheritdoc}
	 */
	public function jsonSerialize(): array
	{
		return [
			'name' => $this->name,
			'status' => $this->status,
			'before' => $this->before,
			'after' => $this->after,
			'textual' => $this->isTextual(),
			'changed_lines' => $this->changedLines(),
			'hunks' => $this->hunks,
		];
	}

	/**
	 * Shortens a value for a table cell.
	 *
	 * @param string $value
	 *   The value.
	 * @param int $length
	 *   Characters to keep.
	 *
	 * @return string
	 *   The value, with newlines flattened and an ellipsis when it was cut.
	 */
	private function clip(string $value, int $length): string
	{
		$flat = trim((string) preg_replace('/\s+/u', ' ', $value));

		return mb_strlen($flat) <= $length ? $flat : mb_substr($flat, 0, $length) . '...';
	}
}
