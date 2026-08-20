<?php

declare(strict_types=1);

namespace Drupal\strata\Segment;

use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;

/**
 * Reduces a window of journal ops to the net effect on each subject.
 *
 * One request can touch a node a dozen times: a create, an update per widget, another update from a
 * hook. All twelve ops describe one subject, and a restore only needs the value the subject held at
 * the end of the window. Folding a window before it is flushed keeps a journal proportional to the
 * number of subjects that changed rather than to the number of writes.
 *
 * The fold runs per JournalOp::key(), over ops in sequence order, and applies one rule per pair:
 *
 * - create then update stays a create, carrying the later value.
 * - create then delete drops both ops; the subject never existed outside the window.
 * - create then create is a recreate, carrying the later value.
 * - update then update is one update, over the union of both field lists.
 * - update then delete is a delete.
 * - update then create is an update; the subject was already there when the window opened.
 * - delete then create is an update, for the same reason.
 * - delete then update is an update.
 * - delete then delete is one delete.
 *
 * A survivor keeps the sequence, the microtime and the parentHash of the earliest op in the run it
 * replaces, and the payloadHash, payloadLength, label, actor and requestId of the latest. The
 * sequence keeps ordering against other subjects stable. The parentHash names the state before the
 * window, which is the last version the store received; an intermediate digest names a value that
 * was never written.
 *
 * Two kinds of op never fold, and are emitted verbatim in sequence order:
 *
 * - a rename, a truncate or a ddl, each of which changes the shape or the identity of the subject
 *   instead of its contents. One of these also closes the run before it, so no survivor can carry a
 *   value across it.
 * - anything in the schema or the file realm, where ddl order is load-bearing and one uri can be
 *   written and rewritten with different contents.
 *
 * @see JournalOp
 * @see Verb
 * @see Realm
 */
final class Collapser
{
	/**
	 * Verbs that describe a change to the contents of a subject, and so can fold.
	 */
	public const FOLDABLE_VERBS = [Verb::CREATE, Verb::UPDATE, Verb::DELETE];

	/**
	 * Realms where every op is kept, because order and contents both carry meaning.
	 */
	public const VERBATIM_REALMS = [Realm::SCHEMA, Realm::FILE];

	#region Collapsing

	/**
	 * Folds a window down to the net effect on each subject.
	 *
	 * @param list<JournalOp> $ops
	 *   The window, in any order; the sequence decides which op is earlier.
	 *
	 * @return list<JournalOp>
	 *   The surviving ops by sequence ascending, never more of them than went in. An op that took
	 *   part in no fold is the same instance that went in.
	 */
	public static function collapse(array $ops): array
	{
		if ($ops === []) {
			return [];
		}

		$survivors = [];
		$open = [];

		foreach (self::inSequenceOrder($ops) as $op) {
			$key = $op->key();

			if (!self::isFoldable($op)) {
				// the run so far is finished; its value cannot cross a rename, truncate or ddl
				if (isset($open[$key])) {
					$survivors[] = $open[$key];
					unset($open[$key]);
				}

				$survivors[] = $op;
				continue;
			}

			if (!isset($open[$key])) {
				$open[$key] = $op;
				continue;
			}

			$folded = self::fold($open[$key], $op);
			if ($folded === null) {
				unset($open[$key]);
				continue;
			}

			$open[$key] = $folded;
		}

		foreach ($open as $op) {
			$survivors[] = $op;
		}

		return self::inSequenceOrder($survivors);
	}

	/**
	 * Whether an op takes part in a fold at all.
	 *
	 * @param JournalOp $op
	 *   The op.
	 *
	 * @return bool
	 *   TRUE when the verb is one of FOLDABLE_VERBS and the realm is not one of VERBATIM_REALMS.
	 */
	public static function isFoldable(JournalOp $op): bool
	{
		return in_array($op->verb, self::FOLDABLE_VERBS, true) &&
			!in_array($op->realm, self::VERBATIM_REALMS, true);
	}

	#endregion

	#region Reporting

	/**
	 * What the fold bought, for the flush log and the operator-facing window view.
	 *
	 * @param list<JournalOp> $before
	 *   The window as captured.
	 * @param list<JournalOp> $after
	 *   The window as collapse() returned it.
	 *
	 * @return array{input: int, output: int, dropped: int, ratio: float}
	 *   Counts either side of the fold, how many ops disappeared, and input over output as a
	 *   factor. The ratio is 1.0 when nothing survived, so a caller can print it without a guard.
	 */
	public static function statistics(array $before, array $after): array
	{
		$input = count($before);
		$output = count($after);

		return [
			'input' => $input,
			'output' => $output,
			'dropped' => $input - $output,
			'ratio' => $output === 0 ? 1.0 : (float) ($input / $output),
		];
	}

	#endregion

	#region Folding

	/**
	 * Merges the op a run has produced so far with the next op on the same subject.
	 *
	 * @param JournalOp $earlier
	 *   The survivor of the run so far, which is the first op of the run when there is only one.
	 * @param JournalOp $later
	 *   The next op on the same key.
	 *
	 * @return JournalOp|null
	 *   The single op that replaces both, or NULL when the pair cancels out and neither survives.
	 */
	private static function fold(JournalOp $earlier, JournalOp $later): ?JournalOp
	{
		$verb = self::survivingVerb($earlier->verb, $later->verb);
		if ($verb === null) {
			return null;
		}

		// a run of updates is the only pair where both field lists describe the surviving value
		$fields =
			$earlier->verb === Verb::UPDATE && $later->verb === Verb::UPDATE
				? self::union($earlier->fields, $later->fields)
				: $later->fields;

		return new JournalOp(
			$earlier->sequence,
			$earlier->microtime,
			$earlier->realm,
			$earlier->subject,
			$verb,
			$later->actor,
			$later->requestId,
			$later->payloadHash,
			$earlier->parentHash,
			$later->payloadLength,
			$later->label,
			$fields,
		);
	}

	/**
	 * The verb the net effect of a pair is described by.
	 *
	 * Every pair of foldable verbs has an arm here. The default is unreachable, because collapse()
	 * only folds ops isFoldable() accepted, and returning the later verb is the answer that loses
	 * the least if a verb is ever added to FOLDABLE_VERBS without an arm.
	 *
	 * @param Verb $earlier
	 *   Verb of the survivor of the run so far.
	 * @param Verb $later
	 *   Verb of the next op on the same key.
	 *
	 * @return Verb|null
	 *   The surviving verb, or NULL when the pair cancels out.
	 */
	private static function survivingVerb(Verb $earlier, Verb $later): ?Verb
	{
		return match (true) {
			$earlier === Verb::CREATE && $later === Verb::CREATE => Verb::CREATE,
			$earlier === Verb::CREATE && $later === Verb::UPDATE => Verb::CREATE,
			$earlier === Verb::CREATE && $later === Verb::DELETE => null,
			$earlier === Verb::UPDATE && $later === Verb::CREATE => Verb::UPDATE,
			$earlier === Verb::UPDATE && $later === Verb::UPDATE => Verb::UPDATE,
			$earlier === Verb::UPDATE && $later === Verb::DELETE => Verb::DELETE,
			$earlier === Verb::DELETE && $later === Verb::CREATE => Verb::UPDATE,
			$earlier === Verb::DELETE && $later === Verb::UPDATE => Verb::UPDATE,
			$earlier === Verb::DELETE && $later === Verb::DELETE => Verb::DELETE,
			default => $later,
		};
	}

	/**
	 * Both field lists, first-seen order, no duplicates.
	 *
	 * @param list<string> $left
	 *   Field names from the earlier op.
	 * @param list<string> $right
	 *   Field names from the later op.
	 *
	 * @return list<string>
	 *   The union.
	 */
	private static function union(array $left, array $right): array
	{
		return array_values(array_unique(array_merge($left, $right)));
	}

	/**
	 * Orders ops the way the journal appended them.
	 *
	 * Sorted on sequence, then microtime, and PHP sorts stably, so two ops the journal numbered
	 * identically keep the order they arrived in rather than swapping between runs.
	 *
	 * @param list<JournalOp> $ops
	 *   The ops, in any order.
	 *
	 * @return list<JournalOp>
	 *   The same ops, by sequence ascending.
	 */
	private static function inSequenceOrder(array $ops): array
	{
		$ordered = array_values($ops);

		usort(
			$ordered,
			static fn(JournalOp $left, JournalOp $right): int => [
				$left->sequence,
				$left->microtime,
			] <=> [$right->sequence, $right->microtime],
		);

		return $ordered;
	}

	#endregion
}
