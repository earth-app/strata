<?php

/**
 * @file
 * CLI front end for ShardPlanner: writes the PHPUnit config for one CI shard.
 *
 * Run as `php tests/shard.php --suite=Kernel --index=1 --total=4`, optionally with `--out` to put
 * the config somewhere other than `phpunit.shard.xml`. Passing `--plan` in place of `--index`
 * prints what every shard would take and writes nothing.
 */

declare(strict_types=1);

// this file declares no namespace, so RuntimeException and Throwable below are already the global
// classes; `use` on a non-compound name is a no-op php warns about
use Drupal\Tests\strata\ShardPlanner;

$repoRoot = dirname(__DIR__);
require_once $repoRoot . '/vendor/autoload.php';

$options = getopt('', ['suite:', 'index:', 'total:', 'out::', 'plan']);
$planOnly = isset($options['plan']);

foreach ($planOnly ? ['suite', 'total'] : ['suite', 'index', 'total'] as $required) {
	if (!isset($options[$required])) {
		fwrite(STDERR, sprintf("missing --%s\n", $required));
		exit(1);
	}
}

$suite = (string) $options['suite'];
$total = (int) $options['total'];
$index = (int) ($options['index'] ?? 1);
$out = (string) ($options['out'] ?? $repoRoot . '/phpunit.shard.xml');

if ($total < 1 || $index < 1 || $index > $total) {
	fwrite(STDERR, "--index must be between 1 and --total\n");
	exit(1);
}

try {
	$planner = new ShardPlanner($repoRoot);
	$counts = $planner->inventory($suite);

	if ($counts === []) {
		throw new RuntimeException(sprintf('no tests found in suite %s', $suite));
	}

	$shards = ShardPlanner::pack($counts, $total);

	if ($planOnly) {
		foreach ($shards as $shard => $classes) {
			printf(
				"shard %d/%d: %3d classes %5d tests\n",
				$shard + 1,
				$total,
				count($classes),
				array_sum(array_map(static fn(string $class): int => $counts[$class], $classes)),
			);
		}

		exit(0);
	}

	$classes = $shards[$index - 1];

	if ($classes === []) {
		throw new RuntimeException(
			sprintf(
				'shard %d/%d is empty; suite %s only has %d classes',
				$index,
				$total,
				$suite,
				count($counts),
			),
		);
	}

	$planner->writeConfig($out, $suite, array_map($planner->fileFor(...), $classes));

	printf(
		"shard %d/%d -> %d classes, %d tests, %s\n",
		$index,
		$total,
		count($classes),
		array_sum(array_map(static fn(string $class): int => $counts[$class], $classes)),
		$out,
	);
} catch (Throwable $error) {
	fwrite(STDERR, $error->getMessage() . "\n");
	exit(1);
}
