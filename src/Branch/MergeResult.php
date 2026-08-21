<?php

declare(strict_types=1);

namespace Drupal\strata\Branch;

use Drupal\strata\Restore\RestoreResult;
use JsonSerializable;

/**
 * What a merge actually did, against what it planned to.
 *
 * Separate from the plan for the reason a restore result is: the two disagree in exactly the cases
 * worth reading. An object that planned as writable and then failed to save - a config schema
 * constraint, a dependency that is no longer installed - is the interesting half, and folding it back
 * into the plan would hide it.
 *
 * Two commits are named. The snapshot is the state of the site before anything was written, which is
 * what makes the merge undoable; the merge commit is the two-parent commit that puts the branch's
 * history behind the target's, which is what stops a prune collecting it.
 *
 * @see MergePlan
 * @see Merger
 */
final class MergeResult implements JsonSerializable
{
	/**
	 * Constructs a result.
	 *
	 * @param MergePlan $plan
	 *   The plan that was applied.
	 * @param bool $applied
	 *   FALSE for a dry run, which builds the plan and writes nothing.
	 * @param string|null $snapshot
	 *   Commit sealed before anything was written, so the merge can be undone.
	 * @param string|null $commit
	 *   The two-parent merge commit, or NULL when nothing was written.
	 * @param list<string> $written
	 *   Configuration object names saved back to the site.
	 * @param array<string, string> $failed
	 *   Object name keyed to why saving it did not work.
	 * @param string|null $refused
	 *   Why the merge did not start, or NULL when it ran.
	 * @param float $seconds
	 *   How long it took.
	 */
	public function __construct(
		public readonly MergePlan $plan,
		public readonly bool $applied = false,
		public readonly ?string $snapshot = null,
		public readonly ?string $commit = null,
		public readonly array $written = [],
		public readonly array $failed = [],
		public readonly ?string $refused = null,
		public readonly float $seconds = 0.0,
	) {}

	/**
	 * A result for a merge that declined to start.
	 *
	 * @param MergePlan $plan
	 *   The plan it would have applied.
	 * @param string $reason
	 *   Why it refused.
	 *
	 * @return self
	 *   The result.
	 */
	public static function refuse(MergePlan $plan, string $reason): self
	{
		return new self($plan, false, null, null, [], [], $reason);
	}

	/**
	 * A result for a merge that was only planned.
	 *
	 * @param MergePlan $plan
	 *   The plan.
	 *
	 * @return self
	 *   The result.
	 */
	public static function dryRun(MergePlan $plan): self
	{
		return new self(
			$plan,
			false,
			null,
			null,
			array_keys($plan->writable()),
			[],
			null,
			$plan->seconds,
		);
	}

	/**
	 * Whether the merge declined to start.
	 *
	 * @return bool
	 *   TRUE when it refused.
	 */
	public function wasRefused(): bool
	{
		return $this->refused !== null;
	}

	/**
	 * Whether everything the plan named was written.
	 *
	 * @return bool
	 *   TRUE when the merge ran and nothing failed.
	 */
	public function isComplete(): bool
	{
		return !$this->wasRefused() && $this->failed === [];
	}

	/**
	 * A one-line summary for a log entry or a command's output.
	 *
	 * @return string
	 *   The summary.
	 */
	public function summary(): string
	{
		if ($this->wasRefused()) {
			return sprintf('merge refused: %s', (string) $this->refused);
		}
		if (!$this->applied) {
			return sprintf('%s; nothing was written', $this->plan->summary());
		}

		$summary = sprintf(
			'merged %s into %s: %d objects written in %.2fs',
			$this->plan->branch,
			$this->plan->target,
			count($this->written),
			$this->seconds,
		);

		if ($this->failed !== []) {
			$summary .= sprintf('; %d failed to write', count($this->failed));
		}
		if ($this->snapshot !== null) {
			$summary .= sprintf('; undo with %s', substr($this->snapshot, 0, 12));
		}

		return $summary;
	}

	/**
	 * A result built from what a logical restore reported.
	 *
	 * @param MergePlan $plan
	 *   The plan that was applied.
	 * @param RestoreResult $restore
	 *   What the restore wrote.
	 * @param string|null $commit
	 *   The merge commit, or NULL when none was written.
	 * @param float $seconds
	 *   How long the whole merge took.
	 *
	 * @return self
	 *   The result, with each subject path shortened back to the configuration object name.
	 */
	public static function fromRestore(
		MergePlan $plan,
		RestoreResult $restore,
		?string $commit,
		float $seconds,
	): self {
		if ($restore->wasRefused()) {
			return self::refuse($plan, (string) $restore->refused);
		}

		$written = [];
		$failed = [];

		foreach ($restore->restored as $subject) {
			$written[] = self::objectName($subject);
		}

		foreach ($restore->failed as $subject => $reason) {
			$failed[self::objectName((string) $subject)] = $reason;
		}

		return new self(
			$plan,
			true,
			$restore->snapshot,
			$commit,
			$written,
			$failed,
			null,
			$seconds,
		);
	}

	/**
	 * The configuration object name a subject path carries.
	 *
	 * @param string $subject
	 *   The subject path, such as `config/system.site`.
	 *
	 * @return string
	 *   The object name, or the path unchanged when it names no realm.
	 */
	private static function objectName(string $subject): string
	{
		$prefix = Branch::REALM->value . '/';

		return str_starts_with($subject, $prefix) ? substr($subject, strlen($prefix)) : $subject;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The result as a plain array.
	 */
	public function jsonSerialize(): array
	{
		return [
			'branch' => $this->plan->branch,
			'target' => $this->plan->target,
			'applied' => $this->applied,
			'snapshot' => $this->snapshot,
			'commit' => $this->commit,
			'written' => $this->written,
			'failed' => $this->failed,
			'refused' => $this->refused,
			'complete' => $this->isComplete(),
			'seconds' => round($this->seconds, 4),
			'plan' => $this->plan->jsonSerialize(),
		];
	}
}
