<?php

declare(strict_types=1);

namespace Drupal\strata\Restore;

use JsonSerializable;

/**
 * What a restore actually did, against what it planned to.
 *
 * Separate from the plan because the two disagree in exactly the cases that matter. A subject that
 * planned as restorable and then failed to write - a validation constraint, a missing bundle, a
 * field that no longer exists - is the interesting half of a restore, and folding it back into the
 * plan would hide it.
 *
 * @see RestorePlan
 * @see RestoreAudit
 */
final class RestoreResult implements JsonSerializable
{
	/**
	 * Constructs a result.
	 *
	 * @param string $target
	 *   Commit the restore targeted.
	 * @param string|null $snapshot
	 *   Commit taken before the restore, so it can be undone, or NULL when none was taken.
	 * @param list<string> $restored
	 *   Subject paths written back.
	 * @param array<string, string> $skipped
	 *   Subject path keyed to why it was left alone.
	 * @param array<string, string> $failed
	 *   Subject path keyed to why writing it did not work.
	 * @param string|null $refused
	 *   Why the restore did not start, or NULL when it ran.
	 * @param float $seconds
	 *   How long it took.
	 */
	public function __construct(
		public readonly string $target,
		public readonly ?string $snapshot = null,
		public readonly array $restored = [],
		public readonly array $skipped = [],
		public readonly array $failed = [],
		public readonly ?string $refused = null,
		public readonly float $seconds = 0.0,
	) {}

	/**
	 * A result for a restore that declined to start.
	 *
	 * @param string $target
	 *   Commit it would have targeted.
	 * @param string $reason
	 *   Why it refused.
	 *
	 * @return self
	 *   The result.
	 */
	public static function refuse(string $target, string $reason): self
	{
		return new self($target, null, [], [], [], $reason);
	}

	/**
	 * Whether the restore declined to start.
	 *
	 * @return bool
	 *   TRUE when it refused.
	 */
	public function wasRefused(): bool
	{
		return $this->refused !== null;
	}

	/**
	 * The outcome an audit row records.
	 *
	 * A restore with any failed subject is FAILED even when most of it worked, because a partly
	 * applied rollback is a state the operator has to know about and "succeeded with notes" is not
	 * a state anyone reads.
	 *
	 * @return string
	 *   One of the RestoreAudit outcome constants.
	 */
	public function outcome(): string
	{
		if ($this->wasRefused()) {
			return RestoreAudit::REFUSED;
		}

		return $this->failed === [] ? RestoreAudit::SUCCEEDED : RestoreAudit::FAILED;
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
			return sprintf('restore refused: %s', (string) $this->refused);
		}

		$summary = sprintf(
			'restored %d subjects, skipped %d, in %.2fs',
			count($this->restored),
			count($this->skipped),
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
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The result as a plain array, which is the form the audit log keeps.
	 */
	public function jsonSerialize(): array
	{
		return [
			'target' => $this->target,
			'snapshot' => $this->snapshot,
			'outcome' => $this->outcome(),
			'restored' => $this->restored,
			'skipped' => $this->skipped,
			'failed' => $this->failed,
			'refused' => $this->refused,
			'seconds' => round($this->seconds, 4),
		];
	}
}
