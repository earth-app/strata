<?php

declare(strict_types=1);

namespace Drupal\strata\Diff;

use Drupal\Component\Diff\Diff;
use Drupal\Component\Diff\Engine\DiffOpAdd;
use Drupal\Component\Diff\Engine\DiffOpChange;
use Drupal\Component\Diff\Engine\DiffOpCopy;
use Drupal\Component\Diff\Engine\DiffOpDelete;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Restore\ReplayResult;
use Drupal\strata\Restore\Replayer;
use Throwable;

/**
 * Names what changed between two commits, field by field.
 *
 * Two commits are compared by replaying each subject at both and comparing the reconstructed field
 * maps. That is the only comparison that means anything: a segment holds the operations that landed
 * in one window, so reading the segments between two commits would say which subjects were touched
 * and not what they were before or after.
 *
 * **Only subjects that exist at one of the two commits are examined.** The union of the two subject
 * sets is walked, so a subject created between them shows as added and one deleted shows as removed,
 * while the thousands that did not change cost nothing.
 *
 * **The work is bounded.** Each subject costs two replays, and a replay walks from an anchor forward,
 * so a diff across a year of history over a hundred thousand subjects is not a page load. The subject
 * count is capped and the report says when it was cut, rather than silently showing the first
 * hundred as if they were all of them.
 *
 * Line-level hunks come from core's own diff engine. Values are compared as text only when both
 * sides look like text and at least one of them is more than a line, because running a line differ
 * over two short strings produces a hunk that says less than showing the strings side by side.
 *
 * @see SubjectDiff
 * @see FieldDiff
 * @see Diff
 */
final class DiffBuilder
{
	/**
	 * Subjects one diff examines.
	 */
	public const MAX_SUBJECTS = 500;

	/**
	 * Characters of a value beyond which it is not diffed by line.
	 *
	 * A field holding a serialized blob is text by the letter of the check and unreadable as a diff,
	 * and the line differ is quadratic in the worst case.
	 */
	public const MAX_TEXT = 65_536;

	/**
	 * Lines a value must exceed before a line-level diff is worth drawing.
	 */
	public const MIN_LINES = 2;

	/**
	 * Constructs a builder.
	 *
	 * @param Replayer $replayer
	 *   Reconstructs each subject at each commit.
	 * @param EntityTypeManagerInterface $entityTypeManager
	 *   Resolves an entity subject to a human-readable label.
	 */
	public function __construct(
		private readonly Replayer $replayer,
		private readonly EntityTypeManagerInterface $entityTypeManager,
	) {}

	/**
	 * Everything that changed between two commits.
	 *
	 * @param string $fromCommit
	 *   The earlier commit.
	 * @param string $toCommit
	 *   The later commit.
	 * @param int $limit
	 *   Subjects to examine.
	 * @param Realm|null $realm
	 *   Only subjects in this realm, or NULL for every realm.
	 *
	 * @return array{diffs: list<SubjectDiff>, examined: int, truncated: bool}
	 *   The subjects that changed, how many were examined, and whether the walk was cut short.
	 */
	public function between(
		string $fromCommit,
		string $toCommit,
		int $limit = self::MAX_SUBJECTS,
		?Realm $realm = null,
	): array {
		$before = $this->subjectsAt($fromCommit);
		$after = $this->subjectsAt($toCommit);
		$subjects = array_values(array_unique(array_merge($before, $after)));

		sort($subjects);

		if ($realm !== null) {
			$prefix = $realm->value . '/';
			$subjects = array_values(
				array_filter(
					$subjects,
					static fn(string $subject): bool => str_starts_with($subject, $prefix),
				),
			);
		}

		$cap = max(1, $limit);
		$truncated = count($subjects) > $cap;
		$examined = array_slice($subjects, 0, $cap);
		$diffs = [];

		foreach ($examined as $subject) {
			$diff = $this->subject($subject, $fromCommit, $toCommit);

			if ($diff !== null) {
				$diffs[] = $diff;
			}
		}

		return ['diffs' => $diffs, 'examined' => count($examined), 'truncated' => $truncated];
	}

	/**
	 * What changed about one subject.
	 *
	 * @param string $subject
	 *   Subject path.
	 * @param string $fromCommit
	 *   The earlier commit.
	 * @param string $toCommit
	 *   The later commit.
	 *
	 * @return SubjectDiff|null
	 *   The diff, or NULL when nothing changed.
	 */
	public function subject(string $subject, string $fromCommit, string $toCommit): ?SubjectDiff
	{
		$realm = $this->realmOf($subject);

		try {
			$before = $this->replayer->materialize($subject, $fromCommit);
			$after = $this->replayer->materialize($subject, $toCommit);
		} catch (Throwable $e) {
			return new SubjectDiff(
				$subject,
				$realm,
				SubjectDiff::UNREADABLE,
				[],
				$this->label($subject),
				$e->getMessage(),
			);
		}

		if (!$before->isComplete() || !$after->isComplete()) {
			return new SubjectDiff(
				$subject,
				$realm,
				SubjectDiff::UNREADABLE,
				[],
				$this->label($subject),
				'a frame in one of the two versions would not decode',
			);
		}

		$status = $this->status($before, $after);

		if ($status === null) {
			return null;
		}

		return new SubjectDiff(
			$subject,
			$realm,
			$status,
			$this->fields($before, $after),
			$this->label($subject),
		);
	}

	/**
	 * Hunks between two text values.
	 *
	 * @param string $before
	 *   The earlier value.
	 * @param string $after
	 *   The later value.
	 *
	 * @return list<DiffHunk>
	 *   The hunks, in order.
	 */
	public function hunks(string $before, string $after): array
	{
		$diff = new Diff(explode("\n", $before), explode("\n", $after));
		$hunks = [];
		$beforeLine = 1;
		$afterLine = 1;

		foreach ($diff->getEdits() as $edit) {
			$original = is_array($edit->orig) ? array_map('strval', $edit->orig) : [];
			$closing = is_array($edit->closing) ? array_map('strval', $edit->closing) : [];

			$hunks[] = new DiffHunk(
				match (true) {
					$edit instanceof DiffOpCopy => DiffHunk::COPY,
					$edit instanceof DiffOpAdd => DiffHunk::ADD,
					$edit instanceof DiffOpDelete => DiffHunk::DELETE,
					$edit instanceof DiffOpChange => DiffHunk::CHANGE,
					default => DiffHunk::CHANGE,
				},
				$original,
				$closing,
				$beforeLine,
				$afterLine,
			);

			$beforeLine += count($original);
			$afterLine += count($closing);
		}

		return $hunks;
	}

	#region Fields

	/**
	 * What changed between two reconstructed field maps.
	 *
	 * @param ReplayResult $before
	 *   The earlier version.
	 * @param ReplayResult $after
	 *   The later version.
	 *
	 * @return list<FieldDiff>
	 *   One entry per field that differs.
	 */
	private function fields(ReplayResult $before, ReplayResult $after): array
	{
		$names = array_values(
			array_unique(array_merge(array_keys($before->fields), array_keys($after->fields))),
		);

		sort($names);

		$diffs = [];

		foreach ($names as $name) {
			$hadBefore = $before->exists && array_key_exists($name, $before->fields);
			$hadAfter = $after->exists && array_key_exists($name, $after->fields);
			$earlier = $hadBefore ? $this->render($before->fields[$name]) : '';
			$later = $hadAfter ? $this->render($after->fields[$name]) : '';

			if ($hadBefore && $hadAfter && $earlier === $later) {
				continue;
			}

			$status = match (true) {
				!$hadBefore => FieldDiff::ADDED,
				!$hadAfter => FieldDiff::REMOVED,
				default => FieldDiff::CHANGED,
			};

			$diffs[] = new FieldDiff(
				(string) $name,
				$status,
				$earlier,
				$later,
				$status === FieldDiff::CHANGED && $this->isDiffable($earlier, $later)
					? $this->hunks($earlier, $later)
					: [],
			);
		}

		return $diffs;
	}

	/**
	 * Whether two values are worth a line-level diff.
	 *
	 * @param string $before
	 *   The earlier value.
	 * @param string $after
	 *   The later value.
	 *
	 * @return bool
	 *   TRUE when both are text of a reasonable size and one of them spans lines.
	 */
	private function isDiffable(string $before, string $after): bool
	{
		if (strlen($before) > self::MAX_TEXT || strlen($after) > self::MAX_TEXT) {
			return false;
		}

		$lines = max(substr_count($before, "\n"), substr_count($after, "\n")) + 1;

		return $lines >= self::MIN_LINES;
	}

	/**
	 * Renders a stored value as the text a diff compares.
	 *
	 * @param mixed $value
	 *   The value out of a replay.
	 *
	 * @return string
	 *   The rendered value.
	 */
	private function render(mixed $value): string
	{
		if (is_string($value)) {
			return $value;
		}
		if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
			return (string) json_encode($value);
		}

		$encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

		return $encoded === false ? serialize($value) : $encoded;
	}

	#endregion

	/**
	 * Whether and how a subject changed.
	 *
	 * @param ReplayResult $before
	 *   The earlier version.
	 * @param ReplayResult $after
	 *   The later version.
	 *
	 * @return string|null
	 *   A SubjectDiff status, or NULL when nothing changed.
	 */
	private function status(ReplayResult $before, ReplayResult $after): ?string
	{
		if (!$before->exists && !$after->exists) {
			return null;
		}
		if (!$before->exists) {
			return SubjectDiff::ADDED;
		}
		if (!$after->exists) {
			return SubjectDiff::REMOVED;
		}

		return $this->fields($before, $after) === [] ? null : SubjectDiff::CHANGED;
	}

	/**
	 * The subjects that exist at a commit.
	 *
	 * @param string $commit
	 *   The commit.
	 *
	 * @return list<string>
	 *   Subject paths, empty when the commit cannot be read.
	 */
	private function subjectsAt(string $commit): array
	{
		try {
			return $this->replayer->subjectsAt($commit);
		} catch (Throwable) {
			return [];
		}
	}

	/**
	 * The realm a subject path names.
	 *
	 * @param string $subject
	 *   Subject path.
	 *
	 * @return Realm|null
	 *   The realm, or NULL when the path names one this release does not know.
	 */
	private function realmOf(string $subject): ?Realm
	{
		$at = strpos($subject, '/');

		return $at === false ? null : Realm::tryFrom(substr($subject, 0, $at));
	}

	/**
	 * A human-readable name for a subject.
	 *
	 * The label comes from the LIVE entity, because a diff is read by someone who wants to know which
	 * article changed and the stored field map holds ids rather than titles. A subject that no longer
	 * exists has no label, which is correct: there is nothing to name it after.
	 *
	 * @param string $subject
	 *   Subject path.
	 *
	 * @return string
	 *   The label, or an empty string when none is available.
	 */
	private function label(string $subject): string
	{
		if (!str_starts_with($subject, Realm::ENTITY->value . '/')) {
			return '';
		}

		$parts = explode('/', substr($subject, strlen(Realm::ENTITY->value) + 1));

		if (count($parts) < 2) {
			return '';
		}

		try {
			$entity = $this->entityTypeManager->getStorage($parts[0])->load($parts[1]);
		} catch (Throwable) {
			return '';
		}

		return $entity === null ? '' : (string) $entity->label();
	}
}
