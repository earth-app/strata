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
 * The Functional suite needs more than discovery: `BrowserTestBase` installs a real site into this
 * root and then drives it over HTTP from a second process. So the scaffold files a root normally
 * gets from `drupal/core-composer-scaffold` are copied in as well - `index.php`, `update.php`,
 * `.ht.router.php` and `sites/default/default.settings.php` - along with the writable directories
 * the installer and the test runner expect. Copying them costs four `file_exists` checks on a Unit
 * run and is what makes the browser lane runnable at all.
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

// core's own index.php is Symfony-Runtime based and expects the scaffold plugin to rewrite its
// paths, so the front controller is written here rather than copied
$front = $vendorDrupal . '/index.php';
if (!file_exists($front)) {
	file_put_contents(
		$front,
		<<<'PHP'
		<?php

		/**
		 * @file
		 * Front controller for the synthesised root the Functional suite installs into.
		 */

		use Drupal\Core\DrupalKernel;
		use Symfony\Component\HttpFoundation\Request;

		$autoloader = require __DIR__ . '/autoload.php';

		$kernel = new DrupalKernel('prod', $autoloader);
		$request = Request::createFromGlobals();
		$response = $kernel->handle($request);
		$response->send();
		$kernel->terminate($request, $response);

		PHP
		,
	);
}

// what core-composer-scaffold would place in a real root; the installer and the router need them
$scaffold = $vendorDrupal . '/core/assets/scaffold/files';
foreach (
	[
		'ht.router.php' => '.ht.router.php',
		'default.settings.php' => 'sites/default/default.settings.php',
		'default.services.yml' => 'sites/default/default.services.yml',
		'example.sites.php' => 'sites/example.sites.php',
	]
	as $from => $to
) {
	$destination = $vendorDrupal . '/' . $to;

	if (file_exists($destination) || !file_exists($scaffold . '/' . $from)) {
		continue;
	}

	if (!is_dir(dirname($destination))) {
		mkdir(dirname($destination), 0777, true);
	}

	copy($scaffold . '/' . $from, $destination);
}

// the installer writes settings.php into sites/default, and the runner works under sites/simpletest
foreach (['sites/default', 'sites/default/files', 'sites/simpletest'] as $writable) {
	$path = $vendorDrupal . '/' . $writable;

	if (!is_dir($path)) {
		mkdir($path, 0777, true);
	}

	chmod($path, 0777);
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
