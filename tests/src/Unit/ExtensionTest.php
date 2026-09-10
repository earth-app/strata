<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit;

use Drupal\Component\Serialization\Yaml;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Proves the metadata a site reads before any of this code runs is coherent.
 *
 * **Everything here is a pure function of files on disk, and every one of these assertions is a bug
 * that shipped.** 1.0.1 declared `drupal:key` in `strata.info.yml` and carried `drupal/key` in
 * `require-dev`, so `composer require earth-app/strata` produced a module Drupal refused to install
 * with "missing its dependency module key" - and because the module never installed, every route it
 * declares answered 404. In the same release `views.view.strata_health` claimed
 * `admin/reports/strata/health`, the path route `strata_ui.health` answers on; a views page display
 * overrides a module route on the same path, so the health dashboard was silently replaced by an
 * empty table.
 *
 * Neither needed a database, a browser or a booted Drupal to catch. Both are read here in
 * milliseconds, on every commit.
 */
class ExtensionTest extends TestCase
{
	#region Descriptions

	#[Test]
	#[TestDox('every module ships a name, a description and a package')]
	#[Group('strata/extension')]
	public function everyModuleDescribesItself(): void
	{
		foreach ($this->extensions() as $name => $info) {
			$this->assertArrayHasKey('name', $info, sprintf('%s declares a name', $name));
			$this->assertArrayHasKey(
				'description',
				$info,
				sprintf('%s declares a description, which is what the Extend page lists', $name),
			);
			$this->assertNotSame(
				'',
				trim((string) $info['description']),
				sprintf('%s has a description that says something', $name),
			);
			$this->assertSame(
				'Strata',
				$info['package'] ?? '',
				sprintf('%s is grouped with the rest', $name),
			);
		}
	}

	#[Test]
	#[TestDox('a module shipping a settings form names it, so the extend page can link to it')]
	#[Group('strata/extension')]
	public function everySettingsFormIsLinkedFromExtend(): void
	{
		foreach ($this->extensions() as $name => $info) {
			$forms = glob($this->directoryOf($name) . '/src/Form/*SettingsForm.php') ?: [];

			if ($forms === []) {
				continue;
			}

			$this->assertArrayHasKey(
				'configure',
				$info,
				sprintf('%s ships a settings form and declares a configure route', $name),
			);
			$this->assertArrayHasKey(
				(string) $info['configure'],
				$this->routes(),
				sprintf('%s names a configure route that exists', $name),
			);
		}
	}

	#endregion

	#region Dependencies

	#[Test]
	#[TestDox('a contrib module the root module depends on is a composer requirement')]
	#[Group('strata/extension')]
	public function theRootModuleRequiresWhatItDependsOn(): void
	{
		$require = array_keys($this->composer()['require'] ?? []);

		foreach ($this->contribDependenciesOf('strata') as $project) {
			$this->assertContains(
				'drupal/' . $project,
				$require,
				sprintf(
					'strata depends on the %s module, so drupal/%s belongs in composer require; ' .
						'anywhere else it is absent from a real install and the module cannot be ' .
						'enabled at all',
					$project,
					$project,
				),
			);
		}
	}

	#[Test]
	#[TestDox('a contrib module only a submodule depends on is at least suggested')]
	#[Group('strata/extension')]
	public function everySubmoduleDependencyIsNamedInComposer(): void
	{
		$composer = $this->composer();
		$require = array_keys($composer['require'] ?? []);
		$suggest = array_keys($composer['suggest'] ?? []);

		foreach (array_keys($this->extensions()) as $name) {
			if ($name === 'strata') {
				continue;
			}

			foreach ($this->contribDependenciesOf($name) as $project) {
				// an optional submodule must not drag its provider into every install, so a suggest
				// is correct here; what is not correct is the package going unnamed altogether
				$this->assertContains(
					'drupal/' . $project,
					array_merge($require, $suggest),
					sprintf(
						'%s depends on the %s module, so drupal/%s belongs in composer require ' .
							'or suggest; named nowhere, nothing tells a site to install it',
						$name,
						$project,
						$project,
					),
				);
			}
		}
	}

	#[Test]
	#[TestDox('nothing needed to install the root module hides in require-dev')]
	#[Group('strata/extension')]
	public function noRootDependencyHidesInRequireDev(): void
	{
		$development = array_keys($this->composer()['require-dev'] ?? []);

		foreach ($this->contribDependenciesOf('strata') as $project) {
			$this->assertNotContains(
				'drupal/' . $project,
				$development,
				sprintf(
					'drupal/%s is needed to install, so it cannot be a dev dependency',
					$project,
				),
			);
		}
	}

	#endregion

	#region Paths

	#[Test]
	#[TestDox('no two extensions claim the same path')]
	#[Group('strata/extension')]
	public function everyDeclaredPathIsUnique(): void
	{
		$seen = [];

		foreach ($this->paths() as $owner => $path) {
			$this->assertArrayNotHasKey(
				$path,
				$seen,
				sprintf(
					'%s and %s both claim %s; whichever the router resolves last wins and the ' .
						'other page becomes unreachable',
					$owner,
					$seen[$path] ?? '',
					$path,
				),
			);

			$seen[$path] = $owner;
		}
	}

	#[Test]
	#[TestDox('no views page display sits on a path a controller already answers')]
	#[Group('strata/extension')]
	public function noViewShadowsAController(): void
	{
		$routed = array_values($this->routes());

		foreach ($this->viewPaths() as $view => $path) {
			$this->assertNotContains(
				$path,
				$routed,
				sprintf(
					'%s claims %s, which a route already declares; a views page display ' .
						'overrides a module route on the same path',
					$view,
					$path,
				),
			);
		}
	}

	#[Test]
	#[TestDox('the section a report page hangs under is itself a page rather than a 404')]
	#[Group('strata/extension')]
	public function everySectionRootAnswers(): void
	{
		$paths = array_values($this->routes());

		foreach (['/admin/reports/strata', '/admin/config/system/strata'] as $section) {
			$children = array_filter(
				$paths,
				static fn(string $path): bool => str_starts_with($path, $section . '/'),
			);

			$this->assertNotSame([], $children, sprintf('%s has pages under it', $section));
			$this->assertContains(
				$section,
				$paths,
				sprintf(
					'%s has pages under it, so typing it or clicking a breadcrumb must not 404',
					$section,
				),
			);
		}
	}

	#endregion

	#region Callables

	#[Test]
	#[TestDox('every route names a controller or a form that exists and can be called')]
	#[Group('strata/extension')]
	public function everyRoutedCallableExists(): void
	{
		foreach ($this->callables() as $route => $callable) {
			[$class, $method] = $callable;

			$this->assertTrue(
				class_exists($class),
				sprintf('%s names %s, which no file defines', $route, $class),
			);

			if ($method === null) {
				$this->assertTrue(
					is_subclass_of($class, 'Drupal\Core\Form\FormInterface'),
					sprintf('%s names %s as a form, so it has to be one', $route, $class),
				);

				continue;
			}

			$this->assertTrue(
				method_exists($class, $method),
				sprintf(
					'%s calls %s::%s(), which the class does not declare',
					$route,
					$class,
					$method,
				),
			);
		}
	}

	#[Test]
	#[TestDox('no module routes to a class another module in this repository owns')]
	#[Group('strata/extension')]
	public function noRouteReachesAcrossExtensions(): void
	{
		$owners = array_keys($this->extensions());

		foreach ($this->callables() as $route => $callable) {
			$module = strtok($route, '.');
			$namespace = $this->extensionOf((string) $callable[0], $owners);

			// a core class is fair game; system's menu block controller is what a section root uses
			if ($namespace === null || $namespace === $module) {
				continue;
			}

			$this->fail(
				sprintf(
					'%s is declared by %s and calls into %s; a route only exists while its own ' .
						'module is enabled, so the class it names has to ship with that module or ' .
						'the page answers "not callable" the moment the other one is turned off',
					$route,
					(string) $module,
					$namespace,
				),
			);
		}
	}

	#endregion

	#region Reachability

	#[Test]
	#[TestDox('every permission this module declares is read by a route or by code')]
	#[Group('strata/extension')]
	public function everyDeclaredPermissionIsChecked(): void
	{
		$readers = $this->sources() . $this->metadata();

		foreach (array_keys($this->permissions()) as $permission) {
			$this->assertStringContainsString(
				(string) $permission,
				$readers,
				sprintf(
					'%s is declared and nothing reads it; a permission no route requires and no ' .
						'access check names describes an authorisation this module never performs',
					$permission,
				),
			);
		}
	}

	#[Test]
	#[TestDox('every event the notifier can announce is announced by something')]
	#[Group('strata/extension')]
	public function everyEventHasAProducer(): void
	{
		$notifier = (string) file_get_contents($this->root() . '/src/Event/Notifier.php');

		preg_match_all('/public function ([a-z][a-zA-Z]*)\(/', $notifier, $matches);

		$methods = array_values(array_diff($matches[1], ['__construct']));

		$this->assertNotSame([], $methods, 'the notifier announces something');

		// Notifier dispatches through its own private fire(), so concatenating it into the haystack
		// cannot make one of its public methods look called
		$sources = $this->sources();

		foreach ($methods as $method) {
			$this->assertStringContainsString(
				'>' . $method . '(',
				$sources,
				sprintf(
					'Notifier::%s() has no caller, so the event it fires can never reach a webhook ' .
						'subscription or a notification however either is configured',
					$method,
				),
			);
		}
	}

	#endregion

	#region Links

	#[Test]
	#[TestDox('every menu and task link points at a route this repository declares')]
	#[Group('strata/extension')]
	public function everyLinkResolves(): void
	{
		$routes = $this->routes();

		foreach ($this->links() as $file => $definitions) {
			foreach ($definitions as $id => $link) {
				$route = (string) ($link['route_name'] ?? '');

				$this->assertArrayHasKey(
					$route,
					$routes,
					sprintf('%s in %s names a route that exists', $id, basename($file)),
				);

				$base = (string) ($link['base_route'] ?? '');

				if ($base !== '') {
					$this->assertArrayHasKey(
						$base,
						$routes,
						sprintf('%s in %s bases on a route that exists', $id, basename($file)),
					);
				}
			}
		}
	}

	#[Test]
	#[TestDox('every page without a placeholder is linked from a tab, a menu or another page')]
	#[Group('strata/extension')]
	public function everyPageIsReachable(): void
	{
		$linked = [];

		foreach ($this->links() as $definitions) {
			foreach ($definitions as $link) {
				$linked[(string) ($link['route_name'] ?? '')] = true;
			}
		}

		$sources = $this->sources();

		foreach ($this->routes() as $name => $path) {
			// a page taking a placeholder is reached from the page that lists the things it takes
			if (str_contains($path, '{')) {
				continue;
			}

			$this->assertTrue(
				isset($linked[$name]) || str_contains($sources, "'" . $name . "'"),
				sprintf(
					'%s answers at %s, and no link declaration or page builds a URL to it',
					$name,
					$path,
				),
			);
		}
	}

	#endregion

	#region Reading

	/**
	 * The repository root.
	 *
	 * @return string
	 *   The absolute path.
	 */
	private function root(): string
	{
		return dirname(__DIR__, 3);
	}

	/**
	 * Where one extension lives.
	 *
	 * @param string $name
	 *   The extension name.
	 *
	 * @return string
	 *   The absolute path.
	 */
	private function directoryOf(string $name): string
	{
		return $name === 'strata' ? $this->root() : $this->root() . '/modules/' . $name;
	}

	/**
	 * Every extension this repository ships.
	 *
	 * @return array<string, array<string, mixed>>
	 *   Extension name keyed to its decoded info file.
	 */
	private function extensions(): array
	{
		$extensions = [];

		foreach (
			array_merge(
				[$this->root() . '/strata.info.yml'],
				glob($this->root() . '/modules/*/*.info.yml') ?: [],
			)
			as $file
		) {
			$name = basename($file, '.info.yml');
			$decoded = Yaml::decode((string) file_get_contents($file));

			$this->assertIsArray($decoded, sprintf('%s decodes to a mapping', $name));

			$extensions[$name] = $decoded;
		}

		$this->assertArrayHasKey('strata', $extensions);
		$this->assertArrayHasKey('strata_ui', $extensions);

		return $extensions;
	}

	/**
	 * Every route every extension declares.
	 *
	 * @return array<string, string>
	 *   Route name keyed to its path.
	 */
	private function routes(): array
	{
		$routes = [];

		foreach (array_keys($this->extensions()) as $name) {
			$file = $this->directoryOf($name) . '/' . $name . '.routing.yml';

			if (!file_exists($file)) {
				continue;
			}

			foreach ((array) Yaml::decode((string) file_get_contents($file)) as $route => $data) {
				$routes[(string) $route] = (string) ($data['path'] ?? '');
			}
		}

		$this->assertNotSame([], $routes, 'this repository declares routes');

		return $routes;
	}

	/**
	 * The class every route hands the request to.
	 *
	 * A `_controller` is a class and a method; a `_form` is a class Drupal instantiates and drives
	 * itself. Both are read verbatim from the routing file, because the string in that file is what
	 * `ControllerResolver` and `ClassResolver` are handed at runtime - a route naming a class that
	 * moved answers `The controller for URI "..." is not callable` with nothing else logged.
	 *
	 * @return array<string, array{0: string, 1: string|null}>
	 *   Route name keyed to the class and, for a controller, the method it calls.
	 */
	private function callables(): array
	{
		$callables = [];

		foreach ($this->routeDefinitions() as $name => $route) {
			$defaults = (array) ($route['defaults'] ?? []);
			$controller = (string) ($defaults['_controller'] ?? '');
			$form = (string) ($defaults['_form'] ?? '');

			if ($controller !== '') {
				[$class, $method] = array_pad(explode('::', $controller, 2), 2, '');

				$this->assertNotSame(
					'',
					$method,
					sprintf('%s names a controller as class::method', $name),
				);

				$callables[$name] = [$class, $method];

				continue;
			}
			if ($form !== '') {
				$callables[$name] = [$form, null];
			}
		}

		$this->assertNotSame([], $callables, 'this repository routes to something');

		return $callables;
	}

	/**
	 * Which extension in this repository owns a class, if any.
	 *
	 * @param string $class
	 *   A fully qualified class name.
	 * @param list<string> $owners
	 *   Every extension name this repository ships.
	 *
	 * @return string|null
	 *   The extension name, or NULL when the class comes from core or a contributed module.
	 */
	private function extensionOf(string $class, array $owners): ?string
	{
		foreach ($owners as $name) {
			if (str_starts_with($class, 'Drupal\\' . $name . '\\')) {
				return $name;
			}
		}

		return null;
	}

	/**
	 * Every route every extension declares, whole.
	 *
	 * @return array<string, array<string, mixed>>
	 *   Route name keyed to its definition.
	 */
	private function routeDefinitions(): array
	{
		$routes = [];

		foreach (array_keys($this->extensions()) as $name) {
			$file = $this->directoryOf($name) . '/' . $name . '.routing.yml';

			if (!file_exists($file)) {
				continue;
			}

			foreach ((array) Yaml::decode((string) file_get_contents($file)) as $route => $data) {
				$routes[(string) $route] = is_array($data) ? $data : [];
			}
		}

		return $routes;
	}

	/**
	 * Every path anything in this repository answers on, keyed by what claims it.
	 *
	 * Route paths and views page paths are compared in the same shape: a route path leads with a
	 * slash and a views path does not, so the views ones are normalised rather than compared raw.
	 *
	 * @return array<string, string>
	 *   Owner keyed to path. The owner is a route name or a view id.
	 */
	private function paths(): array
	{
		return array_merge($this->routes(), $this->viewPaths());
	}

	/**
	 * Every path a shipped view's page display answers on.
	 *
	 * @return array<string, string>
	 *   View id keyed to path, leading slash included so it compares against a route path.
	 */
	private function viewPaths(): array
	{
		$paths = [];

		foreach (glob($this->root() . '/config/optional/views.view.*.yml') ?: [] as $file) {
			$view = Yaml::decode((string) file_get_contents($file));

			if (!is_array($view)) {
				continue;
			}

			foreach ((array) ($view['display'] ?? []) as $id => $display) {
				$path = (string) ($display['display_options']['path'] ?? '');

				if ($path === '') {
					continue;
				}

				$paths[$view['id'] . ':' . $id] = '/' . ltrim($path, '/');
			}
		}

		return $paths;
	}

	/**
	 * Every menu and task link this repository declares.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 *   File path keyed to its decoded definitions.
	 */
	private function links(): array
	{
		$links = [];

		foreach (array_keys($this->extensions()) as $name) {
			foreach (['links.menu', 'links.task', 'links.action'] as $kind) {
				$file = $this->directoryOf($name) . '/' . $name . '.' . $kind . '.yml';

				if (!file_exists($file)) {
					continue;
				}

				$decoded = Yaml::decode((string) file_get_contents($file));
				$links[$file] = is_array($decoded) ? $decoded : [];
			}
		}

		$this->assertNotSame([], $links, 'this repository declares links');

		return $links;
	}

	/**
	 * The contrib projects one extension declares a dependency on.
	 *
	 * Core's own modules ship with `drupal/core` and this module's own submodules ship with this
	 * package, so neither needs anything in composer.json.
	 *
	 * **Decided by whether core ships the module, never by the project part of the dependency
	 * string.** `Dependency::createFromString()` discards that part, so `drupal:key` and `key:key`
	 * install identically - and reading it made this check blind to the first spelling, which is how
	 * a missing `drupal/key` reached 1.0.1 and stayed until 1.0.3. Reverting the info file to that
	 * spelling has to fail this test, not empty it.
	 *
	 * @param string $name
	 *   The extension name.
	 *
	 * @return list<string>
	 *   Project names, each once.
	 */
	private function contribDependenciesOf(string $name): array
	{
		$projects = [];

		foreach ((array) ($this->extensions()[$name]['dependencies'] ?? []) as $dependency) {
			$parts = explode(':', (string) $dependency, 2);
			$module = $parts[1] ?? $parts[0];

			if ($module === 'strata' || $this->isCoreModule($module)) {
				continue;
			}

			$projects[$module] = $module;
		}

		return array_values($projects);
	}

	/**
	 * Whether a module ships with Drupal core.
	 *
	 * @param string $module
	 *   The module name, with any project prefix already stripped.
	 *
	 * @return bool
	 *   TRUE when core owns it.
	 */
	private function isCoreModule(string $module): bool
	{
		$core = $this->root() . '/vendor/drupal/core/modules';

		// with no synthesised root there is nothing to compare against, and reading everything as
		// contrib fails loudly rather than passing blind
		return is_dir($core) && is_dir($core . '/' . $module);
	}

	/**
	 * Every permission this repository declares as a literal.
	 *
	 * The generated per-realm rollback permissions are not here, because they come from
	 * `StrataPermissions::permissions()` and are read by the same class that makes them.
	 *
	 * @return array<string, array<string, mixed>>
	 *   Permission name keyed to its definition.
	 */
	private function permissions(): array
	{
		$declared = (array) Yaml::decode(
			(string) file_get_contents($this->root() . '/strata.permissions.yml'),
		);

		unset($declared['permission_callbacks']);

		$this->assertNotSame([], $declared, 'this repository declares permissions');

		return $declared;
	}

	/**
	 * Every routing, links, services and install file, concatenated.
	 *
	 * Where a permission is named as a requirement rather than in PHP.
	 *
	 * @return string
	 *   The metadata.
	 */
	private function metadata(): string
	{
		$text = (string) file_get_contents($this->root() . '/strata.install');

		foreach (array_keys($this->extensions()) as $name) {
			foreach (['routing', 'links.menu', 'links.task', 'links.action', 'services'] as $kind) {
				$file = $this->directoryOf($name) . '/' . $name . '.' . $kind . '.yml';

				if (file_exists($file)) {
					$text .= (string) file_get_contents($file);
				}
			}
		}

		return $text;
	}

	/**
	 * Every line of PHP and Twig this repository ships, concatenated.
	 *
	 * Read so a route can be shown to be referenced from somewhere - a controller building a URL,
	 * a template linking to it - rather than only from a link declaration.
	 *
	 * @return string
	 *   The sources.
	 */
	private function sources(): string
	{
		$text = '';

		foreach (array_keys($this->extensions()) as $name) {
			foreach (['/src', '/templates'] as $directory) {
				$path = $this->directoryOf($name) . $directory;

				if (!is_dir($path)) {
					continue;
				}

				$found = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
				);

				foreach ($found as $file) {
					if ($file->isFile()) {
						$text .= (string) file_get_contents($file->getPathname());
					}
				}
			}
		}

		return $text;
	}

	/**
	 * The package definition.
	 *
	 * @return array<string, mixed>
	 *   The decoded composer.json.
	 */
	private function composer(): array
	{
		$decoded = json_decode(
			(string) file_get_contents($this->root() . '/composer.json'),
			true,
			512,
			JSON_THROW_ON_ERROR,
		);

		$this->assertIsArray($decoded);

		return $decoded;
	}

	#endregion
}
