<?php

/**
 * @file
 * Generates traffic that looks like a site somebody uses, and seals it.
 *
 * Run through `./startup.sh traffic [--scale=small|medium|large|huge|<n>] [--rewrites=<n>]`.
 *
 * Three write shapes, in the proportion a real site produces them: accounts and nodes created,
 * existing rows edited, and state churn on every request. The edit is what dominates a live site and
 * it is also what delta coding exists for, so the ratio this prints is the interesting figure.
 */

declare(strict_types=1);

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

require_once __DIR__ . '/lib.php';

$scale = strata_scale(strata_arg($extra, 0, 'small'));
$rewrites = max(0, (int) strata_arg($extra, 1, '2'));
$flushEvery = max(5, intdiv($scale, 10));
$started = microtime(true);

strata_head(sprintf('generating traffic: %d subjects, %d rewrites each', $scale, $rewrites));

$before = strata_engine()->frameIndex()->statistics();
$hasNodes = Drupal::moduleHandler()->moduleExists('node');

if ($hasNodes && NodeType::load('page') === null) {
	NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
}

#region Creating

$users = [];
$nodes = [];
$stamp = date('His');

for ($at = 0; $at < $scale; $at++) {
	$users[] = User::create([
		'name' => sprintf('sim-%s-%d', $stamp, $at),
		'mail' => sprintf('sim-%s-%d@example.com', $stamp, $at),
		'status' => 1,
	]);
	$users[$at]->save();

	if ($hasNodes) {
		$node = Node::create([
			'type' => 'page',
			'title' => sprintf('Simulated page %s %d', $stamp, $at),
			'uid' => $users[$at]->id(),
		]);
		$node->save();
		$nodes[] = $node;
	}

	if ($at % $flushEvery === $flushEvery - 1) {
		strata_flush();
	}
}

strata_flush();

$created =
	(int) strata_engine()->frameIndex()->statistics()['rawBytes'] - (int) $before['rawBytes'];

strata_row('subjects created', (string) count($users));
strata_row('captured by creation', strata_bytes($created));

#endregion

#region Rewriting

$rewriteFrom = (int) strata_engine()->frameIndex()->statistics()['storedBytes'];

for ($round = 0; $round < $rewrites; $round++) {
	foreach ($users as $index => $user) {
		// distinct per subject, because an identical value on every row is one frame for all of them
		$user->set('mail', sprintf('sim-%s-%d-r%d@example.com', $stamp, $index, $round));
		$user->save();
	}

	foreach ($nodes as $index => $node) {
		$node->set('title', sprintf('Simulated page %s %d, revision %d', $stamp, $index, $round));
		$node->save();
	}

	Drupal::state()->set('strata_sim.round', sprintf('%s-%d', $stamp, $round));
	strata_flush();
}

$rewriteCost = (int) strata_engine()->frameIndex()->statistics()['storedBytes'] - $rewriteFrom;

strata_row('rewrites performed', (string) ($rewrites * (count($users) + count($nodes))));
strata_row('stored by rewriting', strata_bytes($rewriteCost));

#endregion

$after = strata_engine()->frameIndex()->statistics();

strata_head('what the traffic cost');
strata_row('frames', (string) $after['frames']);
strata_row('captured', strata_bytes((int) $after['rawBytes']));
strata_row('stored', strata_bytes((int) $after['storedBytes']));
strata_row('ratio', sprintf('%.2fx', (float) $after['ratio']));
strata_row('commits', (string) strata_engine()->commitIndex()->count());
strata_row('billed writes this run', (string) strata_engine()->providerStats()->classA());
strata_row('took', sprintf('%.1f s', microtime(true) - $started));
