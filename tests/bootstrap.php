<?php

/**
 * @file
 * PHPUnit bootstrap shared by the Unit, Kernel and Functional suites.
 *
 * A module-only checkout has no Drupal root, so this does four things core's own bootstrap would
 * otherwise do for it:
 *
 * 1. Defines PHPUNIT_COMPOSER_INSTALL, which core's test tooling reads.
 * 2. Registers core's seven test namespaces against `vendor/drupal/core/tests`.
 * 3. Runs `tests/drupal-root.php` so ExtensionDiscovery can find this module, then PSR-4 registers
 *    every contrib package's `Drupal\<name>\` and `Drupal\Tests\<name>\`.
 * 4. Pins locale, encoding and timezone so a test never depends on the host's.
 */

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

$repo = dirname(__DIR__);
$autoload = $repo . '/vendor/autoload.php';

if (!file_exists($autoload)) {
	fwrite(STDERR, "vendor/autoload.php is missing; run composer install\n");
	exit(1);
}

if (!defined('PHPUNIT_COMPOSER_INSTALL')) {
	define('PHPUNIT_COMPOSER_INSTALL', $autoload);
}

/**
 * @var ClassLoader $loader
 */
$loader = require $autoload;

$coreTests = $repo . '/vendor/drupal/core/tests';
foreach (
	[
		'Drupal\\Tests',
		'Drupal\\KernelTests',
		'Drupal\\BuildTests',
		'Drupal\\TestSite',
		'Drupal\\FunctionalTests',
		'Drupal\\FunctionalJavascriptTests',
		'Drupal\\TestTools',
	]
	as $namespace
) {
	$loader->add($namespace, $coreTests);
}

$contrib = require __DIR__ . '/drupal-root.php';
foreach ($contrib as $name => $path) {
	$loader->addPsr4('Drupal\\' . $name . '\\', $path . '/src');
	$loader->addPsr4('Drupal\\Tests\\' . $name . '\\', $path . '/tests/src');
}

// this module's own submodules live in modules/, which no composer package covers
foreach (glob($repo . '/modules/*', GLOB_ONLYDIR) ?: [] as $path) {
	$name = basename($path);

	if (file_exists($path . '/' . $name . '.info.yml')) {
		$loader->addPsr4('Drupal\\' . $name . '\\', $path . '/src');
		$loader->addPsr4('Drupal\\Tests\\' . $name . '\\', $path . '/tests/src');
	}
}

setlocale(LC_ALL, 'C.UTF-8', 'C');
mb_internal_encoding('utf-8');
date_default_timezone_set('UTC');
