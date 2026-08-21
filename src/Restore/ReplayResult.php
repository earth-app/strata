<?php

declare(strict_types=1);

namespace Drupal\strata\Restore;

use JsonSerializable;

/**
 * One subject as it stood at a moment in history.
 *
 * A replay produces a field set rather than an entity, because writing it back is a separate
 * decision with its own permissions and its own failure modes. The result also carries what it
 * could NOT read, so a caller can tell a complete reconstruction from a partial one without
 * guessing from the field count.
 *
 * @see Replayer
 * @see Preflight
 */
final class ReplayResult implements JsonSerializable
{
	/**
	 * Constructs a result.
	 *
	 * @param string $subject
	 *   The subject path, such as "entity/node:42".
	 * @param bool $exists
	 *   FALSE when the last operation in range deleted the subject, so restoring it means removing
	 *   whatever is there now rather than writing fields.
	 * @param array<string, mixed> $fields
	 *   Field name keyed to its value at the target, merged oldest change first.
	 * @param int $versions
	 *   How many stored changes were applied.
	 * @param int $depth
	 *   How many commits the walk covered.
	 * @param list<string> $unreadable
	 *   One line per version that could not be read, naming the commit and the reason.
	 */
	public function __construct(
		public readonly string $subject,
		public readonly bool $exists = true,
		public readonly array $fields = [],
		public readonly int $versions = 0,
		public readonly int $depth = 0,
		public readonly array $unreadable = [],
	) {}

	/**
	 * A result for a subject history does not mention.
	 *
	 * @param string $subject
	 *   The subject path.
	 * @param int $depth
	 *   How many commits were searched.
	 *
	 * @return self
	 *   An empty result.
	 */
	public static function absent(string $subject, int $depth = 0): self
	{
		return new self($subject, false, [], 0, $depth);
	}

	/**
	 * Whether every version the walk needed was readable.
	 *
	 * @return bool
	 *   TRUE when nothing was missed.
	 */
	public function isComplete(): bool
	{
		return $this->unreadable === [];
	}

	/**
	 * Whether the walk found any stored change for this subject.
	 *
	 * @return bool
	 *   TRUE when at least one version was applied or one was found and could not be read.
	 */
	public function wasFound(): bool
	{
		return $this->versions > 0 || $this->unreadable !== [];
	}

	/**
	 * How completely this subject can be put back.
	 *
	 * @return SubjectStatus
	 *   The classification a preflight reports.
	 */
	public function status(): SubjectStatus
	{
		if (!$this->wasFound()) {
			return SubjectStatus::UNRESTORABLE;
		}
		if ($this->isComplete()) {
			return SubjectStatus::RESTORABLE;
		}

		// something read, something did not: a partial field set, which is not a value to write
		return $this->versions > 0 ? SubjectStatus::DEGRADED : SubjectStatus::UNRESTORABLE;
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
			'subject' => $this->subject,
			'exists' => $this->exists,
			'fields' => array_keys($this->fields),
			'versions' => $this->versions,
			'depth' => $this->depth,
			'status' => $this->status()->value,
			'unreadable' => $this->unreadable,
		];
	}
}
