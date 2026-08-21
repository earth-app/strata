<?php

/**
 * @file
 * Rolls the site back and shows what changed on the way.
 *
 * Run through `./startup.sh rollback [--to=<commit|HEAD~n>] [--apply]`.
 *
 * Without `--apply` this is the confirm form as text: the plan, the manifest, and the
 * restorable/degraded/unrestorable split, with nothing written. That is the interesting output, since
 * it is what an operator reads before deciding.
 *
 * A restore never deletes content that postdates the restore point, and never writes a subject it
 * could only partly reconstruct unless it is told to. Both are visible below rather than asserted.
 */

declare(strict_types=1);

use Drupal\strata\Restore\SubjectStatus;

require_once __DIR__ . '/lib.php';

$target = strata_arg($extra, 0, 'HEAD~1');
$apply = strata_arg($extra, 1, 'plan') === 'apply';
$engine = strata_engine();
$ids = $engine->commitIndex()->ids();

strata_head($apply ? 'rolling back' : 'what a rollback would do');

if ($ids === []) {
	strata_row('nothing to roll back to', 'the store is empty; run traffic first');

	return;
}

#region Resolving The Target

$resolved = $target;

if (preg_match('/^HEAD(~(\d+))?$/', $target, $matches) === 1) {
	$back = (int) ($matches[2] ?? 0);
	$walk = strata_head_commit();

	for ($at = 0; $at < $back && $walk !== ''; $at++) {
		$row = $engine->commitIndex()->get($walk);
		$walk = (string) ($row['parent'] ?? '');
	}

	$resolved = $walk;
}

if ($resolved === '' || $engine->commitIndex()->get($resolved) === null) {
	strata_row('no such commit', sprintf('%s does not resolve in this history', $target));
	strata_row('commits available', (string) count($ids));

	return;
}

$row = (array) $engine->commitIndex()->get($resolved);

strata_row('target', sprintf('%s (%s)', substr($resolved, 0, 16), $target));
strata_row('sealed at', date('Y-m-d H:i:s', (int) ($row['microtime'] / 1_000_000)));
strata_row('operations in it', (string) $row['operations']);
strata_row('head', substr(strata_head_commit(), 0, 16));

#endregion

#region The Plan

strata_head('the plan');

$plan = $engine->preflight()->plan($resolved);
$counts = $plan->counts();

strata_row('subjects covered', (string) count($plan->subjects));
strata_row('replay depth', (string) $plan->depth);

foreach (SubjectStatus::cases() as $status) {
	strata_row($status->label(), (string) ($counts[$status->value] ?? 0));
}

strata_row('would write', (string) count($plan->writable()));
strata_row('would skip', (string) count($plan->skipped()));
strata_row('fill degraded values', $plan->fillDegraded ? 'yes' : 'no, which is the default');

foreach ($plan->problems as $problem) {
	strata_row('problem', $problem);
}

strata_head('a sample of the manifest');

$shown = 0;

foreach ($plan->subjects as $subject) {
	if ($shown++ >= 12) {
		break;
	}

	strata_row(
		$subject->subject,
		sprintf(
			'%d versions, depth %d%s',
			$subject->versions,
			$subject->depth,
			$subject->isComplete() ? '' : sprintf(', %d unreadable', count($subject->unreadable)),
		),
	);
}

foreach (array_slice($plan->skipped(), 0, 8, true) as $subject => $why) {
	strata_row('skipping ' . $subject, $why);
}

if (!$apply) {
	strata_head('next');
	strata_row('do it', sprintf('./startup.sh rollback --to=%s --apply', $target));
	strata_row('or through drush', sprintf('ddev drush strata:rollback %s', $resolved));

	return;
}

#endregion

#region Applying

strata_head('applying');

$result = $engine->logicalRestore()->restoreAll($resolved, true);

strata_row('written', (string) count($result->restored));
strata_row('skipped', (string) count($result->skipped));
strata_row('failed', (string) count($result->failed));
strata_row('refused', $result->refused ?? 'no');
strata_row('summary', $result->summary());
strata_row('took', sprintf('%.2f s', $result->seconds));

foreach (array_slice($result->failed, 0, 10, true) as $subject => $why) {
	strata_row('failed ' . (string) $subject, (string) $why);
}

strata_head('afterwards');

$verify = $engine->verifier()->verify();

strata_row('history still verifies', $verify->isClean() ? 'yes' : 'no');
strata_row('head', substr(strata_head_commit(), 0, 16));
strata_row(
	'pre-rollback snapshot',
	$result->snapshot === null ? 'none was taken' : substr($result->snapshot, 0, 16),
);

#endregion
