<?php

/**
 * @file
 * Breaks the store on purpose, so what happens next can be watched.
 *
 * Run through `./startup.sh meltdown [--kind=<kind>] [--share=<0-100>]`.
 *
 * Damage is done through the storage provider, which is what a failing endpoint, a mistaken lifecycle
 * rule or a bad disk does. Nothing here reaches into the local index: the whole question is what the
 * module can work out from the objects it can still read, and corrupting its own bookkeeping would
 * answer a different one.
 *
 * Kinds:
 * - `delete`      remove a share of the frames and packs, as a lifecycle rule with a bad prefix does
 * - `corrupt`     replace a share of object bodies with garbage of the same length
 * - `truncate`    cut a share of objects short, as an interrupted upload leaves them
 * - `anchor`      corrupt a base anchor, which is what makes a reachability walk incomplete
 * - `ref`         delete the ref, which is what makes a whole history unreachable
 * - `dictionary`  remove the dictionaries, which every frame that used one names
 * - `everything`  all of the above
 *
 * Nothing here is reversible. Point it at the throwaway site `startup.sh` builds and nothing else.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$kind = strata_arg($extra, 0, 'corrupt');
$share = max(1, min(100, (int) strata_arg($extra, 1, '50')));
$engine = strata_engine();

strata_head(sprintf('inflicting %s on %d%% of what it applies to', $kind, $share));

if (strata_head_commit() === '') {
	strata_row('nothing to break', 'the store is empty; run traffic first');

	return;
}

$clean = $engine->verifier()->verify();

strata_row('before', $clean->isClean() ? 'the store verified clean' : $clean->summary());

/**
 * A share of a list, at least one entry when the list is not empty.
 *
 * @param array<int, string> $keys
 *   The candidates.
 * @param int $share
 *   Percentage to take.
 *
 * @return array<int, string>
 *   The selection.
 */
$portion = static function (array $keys, int $share): array {
	if ($keys === []) {
		return [];
	}

	return array_slice($keys, 0, max(1, (int) round((count($keys) * $share) / 100)));
};

$applied = [];

#region Kinds

if ($kind === 'delete' || $kind === 'everything') {
	$targets = $portion(array_merge(strata_keys('frames/'), strata_keys('packs/')), $share);

	foreach ($targets as $key) {
		$engine->provider()->delete([$key]);
	}

	$applied[] = sprintf('deleted %d frame and pack objects', count($targets));
}

if ($kind === 'corrupt' || $kind === 'everything') {
	$targets = $portion(array_merge(strata_keys('frames/'), strata_keys('packs/')), $share);

	foreach ($targets as $key) {
		$body = (string) $engine->provider()->get($key);
		$engine->provider()->put($key, str_repeat('X', max(1, strlen($body))));
	}

	$applied[] = sprintf('replaced %d object bodies with garbage', count($targets));
}

if ($kind === 'truncate' || $kind === 'everything') {
	$targets = $portion(strata_keys('segments/'), $share);

	foreach ($targets as $key) {
		$body = (string) $engine->provider()->get($key);
		$engine->provider()->put($key, substr($body, 0, max(1, intdiv(strlen($body), 3))));
	}

	$applied[] = sprintf('cut %d segments short', count($targets));
}

if ($kind === 'anchor' || $kind === 'everything') {
	$targets = $portion(strata_keys('bases/'), 100);

	foreach ($targets as $key) {
		$body = (string) $engine->provider()->get($key);
		$engine->provider()->put($key, str_repeat('X', max(1, strlen($body))));
	}

	$applied[] = sprintf('corrupted %d base anchors', count($targets));
}

if ($kind === 'dictionary' || $kind === 'everything') {
	$targets = strata_keys('dicts/');

	foreach ($targets as $key) {
		$engine->provider()->delete([$key]);
	}

	$applied[] = sprintf('removed %d dictionaries', count($targets));
}

if ($kind === 'ref' || $kind === 'everything') {
	foreach (strata_keys('refs/') as $key) {
		$engine->provider()->delete([$key]);
	}

	$applied[] = 'deleted the ref, so nothing points at the history any more';
}

#endregion

if ($applied === []) {
	strata_row('nothing happened', sprintf('"%s" is not a kind this knows', $kind));

	return;
}

foreach ($applied as $line) {
	strata_row('damage', $line);
}

strata_head('what the store says about itself now');

$report = $engine->verifier()->verify();

strata_row('clean', $report->isClean() ? 'yes' : 'no');
strata_row('summary', $report->summary());
strata_row('walked to the root', $report->complete ? 'yes' : 'no');

foreach ($report->byCode() as $code => $count) {
	strata_row((string) $code, sprintf('%d scopes', $count));
}

strata_head('what a restore would be willing to write');

$head = strata_head_commit();

if ($head === '') {
	strata_row('plan', 'there is no head to plan against, which is itself the finding');

	return;
}

$plan = $engine->preflight()->plan($head);

strata_row('subjects', (string) count($plan->subjects));
strata_row('would write', (string) count($plan->writable()));
strata_row('would skip', (string) count($plan->skipped()));
strata_row('plan', $plan->summary());

strata_head('next');
strata_row('see the ladder', './startup.sh heal');
strata_row('try a rollback anyway', './startup.sh rollback');
