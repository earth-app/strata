<?php

/**
 * @file
 * Runs the repair ladder as far as it is allowed to go on its own, and says what is left.
 *
 * Run through `./startup.sh heal [--apply]`.
 *
 * The four lower rungs rebuild derived state and can run unattended. The two upper ones remove a
 * restore target or block a restore, so they are printed as what a human would have to decide rather
 * than performed. Without `--apply` nothing is written at all and the output is the plan.
 */

declare(strict_types=1);

use Drupal\strata\Health\Finding;
use Drupal\strata\Health\RepairLadder;

require_once __DIR__ . '/lib.php';

$apply = strata_arg($extra, 0, 'plan') === 'apply';
$engine = strata_engine();
$ledger = Drupal::service('strata.health_ledger');

strata_head($apply ? 'healing' : 'what healing would do');

$report = $engine->verifier()->verify();

strata_row('verify', $report->summary());
strata_row('clean', $report->isClean() ? 'yes' : 'no');

$open = $ledger->summary();

if ($open === []) {
	strata_row('open findings', 'none, so there is nothing to heal');

	return;
}

strata_head('open findings, and the rung each sits at');

foreach ($open as $row) {
	$code = (string) $row['code'];
	$rung = (string) $row['rung'];

	strata_row(
		$code,
		sprintf(
			'%d scopes, severity %s, rung %s (%s)',
			$row['scopes'],
			Finding::severities()[(int) $row['severity']] ?? 'UNKNOWN',
			$rung,
			RepairLadder::isAutomatic($rung) ? 'a pass may run it' : 'a human decides',
		),
	);
}

strata_head('the ladder');
strata_row('rungs, lowest first', implode(' -> ', RepairLadder::RUNGS));
strata_row('highest a pass may run', RepairLadder::AUTOMATIC_CEILING);

if (!$apply) {
	strata_head('next');
	strata_row('run it', './startup.sh heal --apply');
	strata_row('or one code at a time', 'ddev drush strata:heal <code> --apply');

	return;
}

#region Applying

strata_head('running what may be run');

foreach ($open as $row) {
	$code = (string) $row['code'];
	$rung = (string) $row['rung'];

	if (!RepairLadder::isAutomatic($rung)) {
		strata_row($code, sprintf('left at %s, which is a human decision', $rung));

		continue;
	}

	if ($rung === 'reindex') {
		strata_row($code, $engine->reindexer()->reindex()->summary());

		continue;
	}
	if ($rung === 'refetch' || $rung === 'rebuild') {
		strata_row($code, $engine->verifier()->verify()->summary());

		continue;
	}

	strata_row($code, sprintf('%s only watches, so nothing was done', $rung));
}

// a rebuild from the bucket is the one pass that fixes an index rather than reporting on it
strata_head('rebuilding the local indexes from the bucket');

$reindex = $engine->reindexer()->reindex();

strata_row('reindex', $reindex->summary());
strata_row('clean', $reindex->isClean() ? 'yes' : 'no');

strata_head('after');

$after = $engine->verifier()->verify();

strata_row('verify', $after->summary());
strata_row('clean', $after->isClean() ? 'yes' : 'no');

foreach ($after->byCode() as $code => $count) {
	strata_row((string) $code, sprintf('%d scopes still open', $count));
}

#endregion
