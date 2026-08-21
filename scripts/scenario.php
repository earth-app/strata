<?php

/**
 * @file
 * The whole story end to end, so the module can be watched doing its job and surviving not doing it.
 *
 * Run through `./startup.sh scenario [--scale=small|medium|large]`.
 *
 * Six acts, each one a script that can also be run on its own:
 *
 * 1. traffic     a site is used, and history is sealed as it goes
 * 2. measure     what that cost, and what it would cost for a month
 * 3. rollback    what going back one commit would restore, without doing it
 * 4. meltdown    a great deal is broken at once
 * 5. heal        what can be rebuilt is rebuilt, and what cannot is named
 * 6. measure     the same figures again, so the damage and the repair are visible as numbers
 *
 * Nothing is written back to the site: the rollback act stops at the plan. Run
 * `./startup.sh rollback --apply` for the other half.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$scale = strata_arg($extra, 0, 'small');
$here = __DIR__;

/**
 * Runs one act by including its script with arguments of our choosing.
 *
 * @param string $script
 *   The file name inside this directory.
 * @param array<int, string> $arguments
 *   What the script should see as `$extra`.
 */
$act = static function (string $script, array $arguments) use ($here): void {
	// each script reads its arguments from $extra, exactly as drush php:script hands them over
	$extra = $arguments;

	print "\n\n";
	print str_repeat('#', 78) . "\n";
	printf("# %s %s\n", $script, implode(' ', $arguments));
	print str_repeat('#', 78) . "\n";

	require $here . '/' . $script;

	// one act's arguments must not leak into the next, which shares this scope
	unset($extra);
};

$act('traffic.php', [$scale, '2']);
$act('measure.php', []);
$act('rollback.php', ['HEAD~1', 'plan']);
$act('meltdown.php', ['everything', '40']);
$act('heal.php', ['apply']);
$act('measure.php', []);

strata_head('the story so far');
strata_row('traffic generated and sealed', 'act 1');
strata_row('cost measured', 'act 2');
strata_row('a rollback planned, not applied', 'act 3');
strata_row('the store broken on purpose', 'act 4');
strata_row('rebuilt as far as it can be', 'act 5');
strata_row('measured again', 'act 6');

strata_head('to go further');
strata_row('apply a rollback', './startup.sh rollback --apply');
strata_row('probe the controls', './startup.sh attack');
strata_row('start over from nothing', './startup.sh --fresh');
