<?php

/**
 * @file
 * Makes this module-only checkout look like a Drupal root to Drupal's own discovery.
 *
 * `vendor/drupal/` already contains `core/`, so it only needs a `modules/` directory and an
 * `autoload.php` beside core for ExtensionDiscovery and DRUPAL_ROOT resolution to work. This
 * script creates both, symlinks this repository and every contrib package that ships a root
 * `*.info.yml` into that modules directory, and returns the contrib map.
 *
 * It is idempotent, safe to run standalone (`php tests/drupal-root.php`), and self-heals after a
 * `composer install` wipes `vendor/`. Both `tests/bootstrap.php` and `phpstan.neon` depend on it.
 *
 * @return array<string, string>
 *   Extension machine name keyed to the absolute path of the package that provides it.
 */

declare(strict_types=1);

$repo = dirname(__DIR__);
$vendorDrupal = $repo . '/vendor/drupal';

if (!is_dir($vendorDrupal . '/core')) {
	fwrite(STDERR, "drupal/core is not installed; run composer install first\n");
	return [];
}

// core resolves the app root from its own __DIR__, so autoload.php must sit beside it
$shim = $vendorDrupal . '/autoload.php';
if (!file_exists($shim)) {
	file_put_contents($shim, "<?php return require __DIR__ . '/../autoload.php';\n");
}

$modules = $vendorDrupal . '/modules';
if (!is_dir($modules)) {
	mkdir($modules, 0777, true);
}

$link = static function (string $name, string $target) use ($modules): void {
	$path = $modules . '/' . $name;
	if (is_link($path)) {
		if (readlink($path) === $target) {
			return;
		}
		unlink($path);
	}
	if (!file_exists($path)) {
		symlink($target, $path);
	}
};

$contrib = [];
$link('strata', $repo);

foreach (glob($vendorDrupal . '/*', GLOB_ONLYDIR) ?: [] as $package) {
	$name = basename($package);
	if ($name === 'core' || $name === 'modules') {
		continue;
	}
	if (!file_exists($package . '/' . $name . '.info.yml')) {
		continue;
	}
	$contrib[$name] = $package;
	$link($name, $package);
}

return $contrib;
