<?php

declare(strict_types=1);

namespace Drupal\strata\Restore;

use JsonSerializable;

/**
 * What a restore would do, subject by subject, before anything is written.
 *
 * The document the confirm form prints and the audit log keeps. It exists because a rollback is not
 * one decision but one per subject: some can be put back exactly, some only in part, some not at
 * all, and an operator who is shown a single "restore 1,412 items" button is not being told which.
 *
 * A plan is produced without writing anything and is the same plan the apply uses, so what is
 * confirmed is what happens.
 *
 * @see Preflight
 * @see LogicalRestore
 */
final class RestorePlan implements JsonSerializable
{
	/**
	 * Constructs a plan.
	 *
	 * @param string $target
	 *   Commit the restore targets.
	 * @param int $depth
	 *   How many commits a replay covers to reach it, which is what the restore costs to compute.
	 * @param array<string, ReplayResult> $subjects
	 *   Subject path keyed to what a replay produced for it.
	 * @param list<string> $problems
	 *   One line per subject that could not be planned at all.
	 * @param bool $fillDegraded
	 *   Whether the scope opted in to writing partial reconstructions. FALSE is the default and the
	 *   only safe answer without a human saying otherwise.
	 * @param float $seconds
	 *   How long planning took.
	 * @param int $plannedAt
	 *   Unix microseconds the plan was built. What a concurrent edit is measured against: a subject
	 *   written after this moment was written by somebody who had not seen the plan.
	 * @param bool $acceptConflicts
	 *   Whether the scope opted in to overwriting a subject somebody changed while the plan was
	 *   waiting. FALSE by default: overwriting an edit nobody saw is a decision, not a detail.
	 */
	public function __construct(
		public readonly string $target,
		public readonly int $depth = 0,
		public readonly array $subjects = [],
		public readonly array $problems = [],
		public readonly bool $fillDegraded = false,
		public readonly float $seconds = 0.0,
		public readonly int $plannedAt = 0,
		public readonly bool $acceptConflicts = false,
	) {}

	/**
	 * Subjects at one status.
	 *
	 * @param SubjectStatus $status
	 *   The status to filter on.
	 *
	 * @return array<string, ReplayResult>
	 *   Subject path keyed to its result.
	 */
	public function withStatus(SubjectStatus $status): array
	{
		return array_filter(
			$this->subjects,
			static fn(ReplayResult $result): bool => $result->status() === $status,
		);
	}

	/**
	 * How many subjects sit at each status.
	 *
	 * @return array<string, int>
	 *   Status value keyed to its count, with every status present even at zero so a form can
	 *   render the full breakdown without inventing rows.
	 */
	public function counts(): array
	{
		$counts = [];

		foreach (SubjectStatus::cases() as $status) {
			$counts[$status->value] = 0;
		}

		foreach ($this->subjects as $result) {
			$counts[$result->status()->value]++;
		}

		return $counts;
	}

	/**
	 * The subjects an apply would actually write.
	 *
	 * @return array<string, ReplayResult>
	 *   Subject path keyed to its result.
	 */
	public function writable(): array
	{
		return array_filter(
			$this->subjects,
			fn(ReplayResult $result): bool => $this->isWritable($result),
		);
	}

	/**
	 * The subjects an apply would leave alone, and why.
	 *
	 * @return array<string, string>
	 *   Subject path keyed to the reason it is skipped.
	 */
	public function skipped(): array
	{
		$skipped = [];

		foreach ($this->subjects as $subject => $result) {
			$path = (string) $subject;

			if ($this->isWritable($result)) {
				continue;
			}

			$skipped[$path] = match ($result->status()) {
				SubjectStatus::UNRESTORABLE => 'nothing usable survives for it',
				SubjectStatus::DEGRADED => sprintf(
					'only part of it can be reconstructed (%d versions unreadable)',
					count($result->unreadable),
				),
				SubjectStatus::RESTORABLE => 'it is outside the restore scope',
			};
		}

		return $skipped;
	}

	/**
	 * Whether the plan would write anything.
	 *
	 * @return bool
	 *   TRUE when at least one subject is writable.
	 */
	public function isEmpty(): bool
	{
		return $this->writable() === [];
	}

	/**
	 * Whether every subject in the plan can be put back exactly.
	 *
	 * @return bool
	 *   TRUE when nothing is degraded or unrestorable.
	 */
	public function isComplete(): bool
	{
		return $this->problems === [] &&
			$this->counts()[SubjectStatus::DEGRADED->value] === 0 &&
			$this->counts()[SubjectStatus::UNRESTORABLE->value] === 0;
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
			'%d restorable, %d degraded, %d unrestorable across %d commits; %d would be written',
			$counts[SubjectStatus::RESTORABLE->value],
			$counts[SubjectStatus::DEGRADED->value],
			$counts[SubjectStatus::UNRESTORABLE->value],
			$this->depth,
			count($this->writable()),
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
			'target' => $this->target,
			'depth' => $this->depth,
			'counts' => $this->counts(),
			'fillDegraded' => $this->fillDegraded,
			'plannedAt' => $this->plannedAt,
			'acceptConflicts' => $this->acceptConflicts,
			'writable' => array_keys($this->writable()),
			'skipped' => $this->skipped(),
			'problems' => $this->problems,
			'seconds' => round($this->seconds, 4),
		];
	}

	/**
	 * Whether one result would be written.
	 *
	 * @param ReplayResult $result
	 *   The result.
	 *
	 * @return bool
	 *   TRUE when the status allows it, or the scope opted in to partial reconstructions.
	 */
	private function isWritable(ReplayResult $result): bool
	{
		$status = $result->status();

		return $status->isWrittenByDefault() ||
			($this->fillDegraded && $status === SubjectStatus::DEGRADED);
	}
}
