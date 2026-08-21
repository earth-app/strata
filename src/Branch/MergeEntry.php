<?php

declare(strict_types=1);

namespace Drupal\strata\Branch;

use JsonSerializable;

/**
 * What a merge decided about one configuration object, and what it would write.
 *
 * The unit the manifest is made of. It carries the outcome, the merged value, and - for a conflict -
 * both of the values that collided, so the page an operator approves shows the disagreement rather
 * than a count of them.
 *
 * @see ThreeWayMerge
 * @see MergePlan
 */
final class MergeEntry implements JsonSerializable
{
	/**
	 * Constructs an entry.
	 *
	 * @param string $name
	 *   The configuration object name, such as `system.site`.
	 * @param MergeOutcome $outcome
	 *   What the merge decided.
	 * @param array<string, mixed> $value
	 *   The merged data, which is what a write would set. Empty when the merge removes the object.
	 * @param bool $exists
	 *   FALSE when the merge removes the object rather than writing data.
	 * @param array<string, array{ours: mixed, theirs: mixed}> $conflicts
	 *   Dotted key path keyed to the two values that collided, empty unless the outcome is a conflict
	 *   or a strategy resolved one.
	 * @param list<string> $fromTheirs
	 *   Dotted key paths the branch contributed, which is what makes a merged object legible.
	 * @param bool $resolved
	 *   TRUE when a strategy decided the conflicting keys, so the entry can be written after all.
	 */
	public function __construct(
		public readonly string $name,
		public readonly MergeOutcome $outcome,
		public readonly array $value = [],
		public readonly bool $exists = true,
		public readonly array $conflicts = [],
		public readonly array $fromTheirs = [],
		public readonly bool $resolved = false,
	) {}

	/**
	 * The subject path this object is captured under.
	 *
	 * @return string
	 *   Something such as `config/system.site`.
	 */
	public function subject(): string
	{
		return Branch::REALM->value . '/' . $this->name;
	}

	/**
	 * Whether applying the merge writes this object.
	 *
	 * @return bool
	 *   TRUE when the outcome writes, or a strategy resolved a conflict into something to write.
	 */
	public function isWritten(): bool
	{
		if ($this->outcome->isConflict()) {
			return $this->resolved;
		}

		return $this->outcome->isWritten();
	}

	/**
	 * Whether this entry stops the plan being applied.
	 *
	 * @return bool
	 *   TRUE for a conflict no strategy resolved.
	 */
	public function isBlocking(): bool
	{
		return $this->outcome->isConflict() && !$this->resolved;
	}

	/**
	 * One line naming what the merge would do to this object.
	 *
	 * @return string
	 *   The description.
	 */
	public function describe(): string
	{
		return match (true) {
			$this->isBlocking() => sprintf(
				'%s: both sides changed %s',
				$this->name,
				implode(', ', array_slice(array_keys($this->conflicts), 0, 5)),
			),
			$this->outcome->isConflict() => sprintf(
				'%s: %d conflicting keys resolved by the chosen strategy',
				$this->name,
				count($this->conflicts),
			),
			$this->outcome === MergeOutcome::MERGED => sprintf(
				'%s: %d keys taken from the branch, the rest kept',
				$this->name,
				count($this->fromTheirs),
			),
			!$this->exists => sprintf('%s: removed on the branch', $this->name),
			default => sprintf('%s: %s', $this->name, $this->outcome->label()),
		};
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The entry as a plain array. The merged value is left out and only its keys are named, because
	 *   an audit row is not a place to copy the site's configuration into.
	 */
	public function jsonSerialize(): array
	{
		return [
			'name' => $this->name,
			'outcome' => $this->outcome->value,
			'exists' => $this->exists,
			'keys' => array_keys($this->value),
			'conflicts' => array_keys($this->conflicts),
			'fromTheirs' => $this->fromTheirs,
			'resolved' => $this->resolved,
			'written' => $this->isWritten(),
		];
	}
}
