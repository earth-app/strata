<?php

declare(strict_types=1);

namespace Drupal\strata\Branch;

use JsonSerializable;

/**
 * What a merge would do, object by object, before anything is written.
 *
 * The document the confirm form prints and the command's manifest. It exists for the same reason a
 * restore plan does: a merge is not one decision but one per configuration object, and a
 * confirmation that said "merge 41 objects" without saying which of them collide is asking somebody
 * to approve something nobody has looked at.
 *
 * **A plan with an unresolved conflict cannot be applied.** Not "is discouraged from being applied":
 * MergePlan::isApplicable() is FALSE and Merger refuses. The way past a conflict is to name a
 * strategy, which is a decision with a name attached rather than a default that happened.
 *
 * **A problem is also blocking.** The problems are things a branch cannot carry - a change in a realm
 * that is captured as deltas, a merge base that could not be found - and every one of them means the
 * plan does not describe the whole of what the branch holds.
 *
 * @see Merger
 * @see MergeEntry
 * @see MergeStrategy
 */
final class MergePlan implements JsonSerializable
{
	/**
	 * Constructs a plan.
	 *
	 * @param string $branch
	 *   Name of the branch being merged in.
	 * @param string $target
	 *   Ref name being merged into, such as `heads/main`.
	 * @param string|null $base
	 *   The merge base, or NULL when none was found.
	 * @param string|null $ours
	 *   The target's tip, or NULL when the target ref does not exist.
	 * @param string|null $theirs
	 *   The branch's tip, or NULL when the branch does not exist.
	 * @param MergeStrategy $strategy
	 *   What the plan does with a key both sides changed.
	 * @param array<string, MergeEntry> $entries
	 *   Configuration object name keyed to what the merge decided, covering only the objects one side
	 *   or the other actually touched.
	 * @param list<string> $problems
	 *   One line per thing that stops the merge, each naming what it is about.
	 * @param int $unchanged
	 *   Objects both sides left in the same state, counted rather than listed.
	 * @param int $plannedAt
	 *   Unix microseconds the plan was built.
	 * @param float $seconds
	 *   How long planning took.
	 */
	public function __construct(
		public readonly string $branch,
		public readonly string $target,
		public readonly ?string $base = null,
		public readonly ?string $ours = null,
		public readonly ?string $theirs = null,
		public readonly MergeStrategy $strategy = MergeStrategy::REFUSE,
		public readonly array $entries = [],
		public readonly array $problems = [],
		public readonly int $unchanged = 0,
		public readonly int $plannedAt = 0,
		public readonly float $seconds = 0.0,
	) {}

	/**
	 * A plan that could not be built.
	 *
	 * @param string $branch
	 *   Name of the branch.
	 * @param string $target
	 *   Ref name being merged into.
	 * @param string $problem
	 *   Why it could not be built.
	 * @param MergeStrategy $strategy
	 *   The strategy that was asked for.
	 *
	 * @return self
	 *   The plan.
	 */
	public static function refuse(
		string $branch,
		string $target,
		string $problem,
		MergeStrategy $strategy = MergeStrategy::REFUSE,
	): self {
		return new self($branch, $target, null, null, null, $strategy, [], [$problem]);
	}

	/**
	 * The objects the merge would write back to the site.
	 *
	 * @return array<string, MergeEntry>
	 *   Object name keyed to its entry.
	 */
	public function writable(): array
	{
		return array_filter($this->entries, static fn(MergeEntry $e): bool => $e->isWritten());
	}

	/**
	 * The objects both sides changed in the same place.
	 *
	 * @return array<string, MergeEntry>
	 *   Object name keyed to its entry, resolved conflicts included.
	 */
	public function conflicts(): array
	{
		return array_filter(
			$this->entries,
			static fn(MergeEntry $e): bool => $e->outcome->isConflict(),
		);
	}

	/**
	 * The conflicts nothing has decided.
	 *
	 * @return array<string, MergeEntry>
	 *   Object name keyed to its entry.
	 */
	public function unresolved(): array
	{
		return array_filter($this->entries, static fn(MergeEntry $e): bool => $e->isBlocking());
	}

	/**
	 * How many objects fall at each outcome.
	 *
	 * @return array<string, int>
	 *   Outcome value keyed to its count, with every outcome present even at zero so a form can render
	 *   the full breakdown without inventing rows.
	 */
	public function counts(): array
	{
		$counts = [];

		foreach (MergeOutcome::cases() as $outcome) {
			$counts[$outcome->value] = 0;
		}

		$counts[MergeOutcome::UNCHANGED->value] = $this->unchanged;

		foreach ($this->entries as $entry) {
			$counts[$entry->outcome->value]++;
		}

		return $counts;
	}

	/**
	 * Whether the merge can be applied as it stands.
	 *
	 * @return bool
	 *   TRUE when nothing is blocking and there is at least one object to write.
	 */
	public function isApplicable(): bool
	{
		return $this->problems === [] && $this->unresolved() === [] && $this->writable() !== [];
	}

	/**
	 * Whether the branch has anything the target does not already hold.
	 *
	 * @return bool
	 *   TRUE when nothing would be written and nothing is blocking.
	 */
	public function isEmpty(): bool
	{
		return $this->writable() === [] && $this->unresolved() === [];
	}

	/**
	 * Why the merge cannot be applied.
	 *
	 * @return string|null
	 *   One sentence, or NULL when it can.
	 */
	public function refusal(): ?string
	{
		if ($this->problems !== []) {
			return implode('; ', $this->problems);
		}
		if ($this->unresolved() !== []) {
			return sprintf(
				'%d configuration objects were changed on both sides and no strategy was named: %s',
				count($this->unresolved()),
				implode(
					'; ',
					array_map(
						static fn(MergeEntry $e): string => $e->describe(),
						array_slice($this->unresolved(), 0, 5),
					),
				),
			);
		}
		if ($this->writable() === []) {
			return 'the branch holds nothing the target does not already have';
		}

		return null;
	}

	/**
	 * A one-line summary for a log entry or a command's output.
	 *
	 * @return string
	 *   The summary.
	 */
	public function summary(): string
	{
		$counts = $this->counts();

		return sprintf(
			'merging %s into %s: %d to write, %d conflicting, %d unchanged, %d kept',
			$this->branch,
			$this->target,
			count($this->writable()),
			count($this->conflicts()),
			$counts[MergeOutcome::UNCHANGED->value],
			$counts[MergeOutcome::OURS->value],
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The plan as a plain array, which is the form the audit log keeps.
	 */
	public function jsonSerialize(): array
	{
		return [
			'branch' => $this->branch,
			'target' => $this->target,
			'base' => $this->base,
			'ours' => $this->ours,
			'theirs' => $this->theirs,
			'strategy' => $this->strategy->value,
			'counts' => $this->counts(),
			'entries' => array_map(
				static fn(MergeEntry $e): array => $e->jsonSerialize(),
				$this->entries,
			),
			'problems' => $this->problems,
			'applicable' => $this->isApplicable(),
			'plannedAt' => $this->plannedAt,
			'seconds' => round($this->seconds, 4),
		];
	}
}
