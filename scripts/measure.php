<?php

/**
 * @file
 * Prints what the store holds, what it cost, and what it would cost on each provider.
 *
 * Run through `./startup.sh measure`.
 *
 * Every figure here is read from the store or the local index. Nothing is modelled: the price is the
 * measured byte count and the measured request count put through the same price table the estimator
 * uses, so the number on this page and the number on the estimate page are comparable.
 */

declare(strict_types=1);

use Drupal\strata\Estimate\PriceTable;
use Drupal\strata\Storage\ProviderStats;

require_once __DIR__ . '/lib.php';

$engine = strata_engine();
$statistics = $engine->frameIndex()->statistics();
$head = strata_head_commit();

strata_head('the store');
strata_row('provider', $engine->provider()->id());
strata_row('site', $engine->site()->id());
strata_row('head', $head === '' ? 'nothing sealed yet' : substr($head, 0, 16));
strata_row('commits indexed', (string) $engine->commitIndex()->count());
strata_row('pending operations', (string) $engine->journal()->pending());

strata_head('objects, by prefix');

$objects = 0;
$bytes = 0;

foreach (['frames/', 'packs/', 'segments/', 'bases/', 'commits/', 'refs/', 'dicts/'] as $prefix) {
	$page = $engine->provider()->list($prefix, null, 5000);
	$objects += count($page);
	$bytes += $page->bytes();

	strata_row($prefix, sprintf('%5d objects, %s', count($page), strata_bytes($page->bytes())));
}

strata_row('total', sprintf('%5d objects, %s', $objects, strata_bytes($bytes)));

strata_head('compression and deduplication');
strata_row('frames indexed', (string) $statistics['frames']);
strata_row('captured', strata_bytes((int) $statistics['rawBytes']));
strata_row('stored', strata_bytes((int) $statistics['storedBytes']));
strata_row('ratio', sprintf('%.2fx', (float) $statistics['ratio']));
strata_row('collectable frames', (string) $statistics['orphans']);

strata_head('requests, this process and on the record');

$window = $engine->providerStats();

strata_row('class A this process', (string) $window->classA());
strata_row('class B this process', (string) $window->classB());
strata_row('bytes moved this process', strata_bytes($window->bytes()));

$recorded = $engine->providerStatStore()->total();

strata_row('class A recorded', (string) $recorded['classA']);
strata_row('class B recorded', (string) $recorded['classB']);
strata_row('failures recorded', (string) $recorded['failures']);
strata_row('seconds waiting on the store', sprintf('%.2f', $recorded['seconds']));

foreach ($engine->providerStatStore()->byOperation() as $operation => $figures) {
	strata_row(
		'  ' . $operation,
		sprintf(
			'%5d requests, %s, slowest %.0f ms',
			$figures['requests'],
			strata_bytes($figures['bytes']),
			$figures['slowest'] * 1000,
		),
	);
}

strata_head('what a month of this would cost');

// a month of the traffic recorded so far, projected from what one day of it actually cost
$writes = max(1, $recorded['classA'] > 0 ? $recorded['classA'] : $window->classA());
$reads = max(0, $recorded['classB'] > 0 ? $recorded['classB'] : $window->classB());
$stored = (int) $statistics['storedBytes'];

foreach ([PriceTable::r2(), PriceTable::s3()] as $prices) {
	strata_row(
		$prices->label,
		sprintf(
			'%s stored, %d writes/mo -> $%.4f',
			strata_bytes($stored),
			$writes * 30,
			$prices->monthlyCost($stored, $writes * 30, $reads * 30),
		),
	);
}

strata_row('free tier absorbs it', PriceTable::r2()->hasFreeTier() ? 'r2: yes, up to 10 GB' : 'no');

strata_head('health');

$ledger = Drupal::service('strata.health_ledger');
$summary = $ledger->summary();

if ($summary === []) {
	strata_row('open findings', 'none');
} else {
	foreach ($summary as $row) {
		strata_row(
			(string) $row['code'],
			sprintf(
				'%d scopes, severity %d, rung %s',
				$row['scopes'],
				$row['severity'],
				$row['rung'],
			),
		);
	}
}

strata_head('billed classes, for reference');
strata_row('class A, billed as writes', implode(', ', ProviderStats::CLASS_A));
strata_row('class B, billed as reads', implode(', ', ProviderStats::CLASS_B));
