<?php

/**
 * @file
 * Boots the installed site inside the container so the E2E suite runs against real services.
 */

declare(strict_types=1);

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$root = getenv('DRUPAL_ROOT') ?: '/srv/drupal/web';

if (!is_file($root . '/sites/default/settings.php')) {
	fwrite(STDERR, "No installed site at $root. Run `strata-e2e install` first.\n");

	exit(1);
}

$autoloader = require dirname($root) . '/vendor/autoload.php';
$autoloader->addPsr4('Drupal\\Tests\\strata\\', dirname(__DIR__) . '/src');

chdir($root);

$request = Request::create(
	'/',
	'GET',
	[],
	[],
	[],
	[
		'HTTP_HOST' => 'localhost',
		'SCRIPT_NAME' => '/index.php',
		'SCRIPT_FILENAME' => $root . '/index.php',
	],
);

$kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod', false, $root);
$kernel->boot();
$kernel->preHandle($request);
