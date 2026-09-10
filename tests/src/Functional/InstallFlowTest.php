<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Functional;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Extension\ModuleInstallerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Drives the whole install, uninstall and reinstall flow with Views enabled.
 *
 * **Views is the point of this lane.** Every other functional spec installs `strata` and
 * `strata_ui` alone, so `config/optional/views.view.*` never lands and the five page displays it
 * ships are never registered against the router. 1.0.1 shipped one of them - the findings view - on
 * `admin/reports/strata/health`, the same path route `strata_ui.health` answers on. A views page
 * display overrides a module route on the same path, so on a real site the health dashboard was
 * silently replaced by an empty table, and nothing in any lane noticed.
 *
 * **Uninstall is driven for real rather than asserted about.** `hook_uninstall()` runs while the
 * tables still exist and exports three audit tables nothing in the bucket can reproduce; if that
 * hook throws, the uninstall fails halfway and leaves a site that can neither use the module nor
 * remove it.
 *
 * @see \strata_uninstall()
 * @see \strata_update_11104()
 */
#[RunTestsInSeparateProcesses]
class InstallFlowTest extends StrataFunctionalTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * Views is installed before this module so the optional configuration lands, which is the order
	 * a site enabling Strata onto an existing install produces.
	 *
	 * @var list<string>
	 */
	protected static $modules = [
		'system',
		'user',
		'node',
		'field',
		'views',
		'key',
		'strata',
		'strata_ui',
	];

	#region Every Page Answers

	#[Test]
	#[TestDox('every route both modules declare answers with a page rather than an error')]
	#[Group('strata/functional')]
	public function everyDeclaredRouteAnswers(): void
	{
		$this->user($this->everyPermission());

		foreach (['strata', 'strata_ui'] as $module) {
			$routes = $this->routesOf($module);

			// every assertion below is inside the loop, so a moved routing file would otherwise
			// turn the broadest sweep in the suite into one that asserts nothing
			$this->assertNotSame([], $routes, sprintf('%s declares routes', $module));

			foreach ($routes as $name => $route) {
				$path = $this->pathOf($route, $this->placeholders());

				$this->drupalGet($path);

				// 200 and not merely "not 404": this is the only sweep across both modules with every
				// permission granted, and until 1.0.3 a 500 passed it
				$this->assertSame(
					200,
					$this->getSession()->getStatusCode(),
					sprintf('%s answers at %s', $name, $path),
				);
			}
		}
	}

	#[Test]
	#[TestDox('the engine pages still answer when the router outlives the module that owns them')]
	#[Group('strata/functional')]
	public function aStaleRouterDoesNotFatal(): void
	{
		$this->user($this->everyPermission());

		$paths = [];

		foreach ($this->routesOf('strata') as $name => $route) {
			$paths[$name] = $this->pathOf($route, $this->placeholders());
		}

		$this->orphan(['strata', 'strata_ui']);

		foreach ($paths as $name => $path) {
			$this->drupalGet($path);

			// `composer.json` PSR-4 maps `Drupal\strata\`, so these classes load whether or not the
			// module is installed and the request reaches their create(). Anything below 500 is a
			// legitimate answer, including the 200 `strata.settings` keeps because its controller is
			// one of core's own; a 500 is the ServiceNotFoundException a real site reported
			$this->assertLessThan(
				500,
				$this->getSession()->getStatusCode(),
				sprintf('%s at %s refuses cleanly once its services are gone', $name, $path),
			);
		}
	}

	#[Test]
	#[TestDox('a page whose code has gone is named on the status report, with the fix')]
	#[Group('strata/functional')]
	public function aStaleRouteIsReportedOnTheStatusReport(): void
	{
		$this->user(array_merge($this->everyPermission(), ['administer site configuration']));

		// only the UI: a submodule ships no composer package, so its namespace is registered by
		// Drupal alone and its controllers stop existing the moment it leaves the module list. That
		// is the half of the condition this module can still see, because the engine is still here
		$this->orphan(['strata_ui']);

		$this->drupalGet('/admin/reports/status');

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('Strata pages');
		$this->assertSession()->pageTextContains('strata_ui.status');
		$this->assertSession()->pageTextContains('drush cache:rebuild');
	}

	#[Test]
	#[TestDox('the health dashboard renders itself rather than a view that took its path')]
	#[Group('strata/functional')]
	public function theHealthDashboardIsNotShadowedByAView(): void
	{
		$this->user($this->everyPermission());
		$this->drupalGet('/admin/reports/strata/health');

		$this->assertSession()->statusCodeEquals(200);

		// the dashboard's own headings; the view renders a table and none of these
		$this->assertSession()->pageTextContains('Restore Drills');
		$this->assertSession()->pageTextContains('Open Findings');
	}

	#[Test]
	#[TestDox('every shipped view installs on a path of its own')]
	#[Group('strata/functional')]
	public function everyShippedViewInstallsBesideTheReports(): void
	{
		$this->user($this->everyPermission());

		$views = $this->container->get('entity_type.manager')->getStorage('view');

		foreach (['strata_commits', 'strata_frames', 'strata_health', 'strata_operations'] as $id) {
			$this->assertNotNull($views->load($id), sprintf('the %s view installed', $id));
		}

		$this->drupalGet('/admin/reports/strata/findings');

		$this->assertSession()->statusCodeEquals(200);
	}

	#[Test]
	#[TestDox('the section root lists the pages under it instead of answering 404')]
	#[Group('strata/functional')]
	public function bothSectionRootsAnswer(): void
	{
		$this->user($this->everyPermission());

		$this->drupalGet('/admin/reports/strata');
		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('Capture is on');

		$this->drupalGet('/admin/config/system/strata');
		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->linkExists('Storage');
		$this->assertSession()->linkExists('Retention');
	}

	#endregion

	#region Uninstalling

	#[Test]
	#[TestDox('uninstalling the UI leaves the engine and its pages behind')]
	#[Group('strata/functional')]
	public function theUiUninstallsOnItsOwn(): void
	{
		$this->content('Kept');
		$this->flush();

		$this->assertTrue($this->uninstall(['strata_ui']));

		$this->user(['administer strata', 'manage strata storage']);

		$this->drupalGet('/admin/reports/strata/timeline');
		$this->assertSession()->statusCodeEquals(404);

		$this->drupalGet('/admin/config/system/strata/storage');
		$this->assertSession()->statusCodeEquals(200);

		$this->assertNotNull($this->head(), 'the sealed history survived');
	}

	#[Test]
	#[TestDox('uninstalling the engine drops its tables and removes its settings')]
	#[Group('strata/functional')]
	public function theEngineUninstallsCleanly(): void
	{
		$this->content('Removed');
		$this->flush();

		$this->assertTrue($this->uninstall(['strata_ui', 'strata']));

		$schema = $this->container->get('database')->schema();

		foreach (['strata_journal', 'strata_frame', 'strata_commit', 'strata_health'] as $table) {
			$this->assertFalse($schema->tableExists($table), sprintf('%s was dropped', $table));
		}

		$this->assertTrue(
			$this->config('strata.settings')->isNew(),
			'the settings object was removed with the module',
		);
		$this->assertFalse(
			$this->container->get('module_handler')->moduleExists('strata'),
			'the module is gone',
		);
	}

	#[Test]
	#[TestDox('uninstalling exports the three tables nothing in the bucket can rebuild')]
	#[Group('strata/functional')]
	public function uninstallExportsTheAuditTables(): void
	{
		$this->content('Audited');
		$this->flush();

		$this->assertTrue($this->uninstall(['strata_ui', 'strata']));

		$public = $this->container->get('file_system')->realpath('public://');

		$this->assertIsString($public);

		$exports = glob($public . '/strata-uninstall-*/strata_restore_log.json') ?: [];

		$this->assertNotSame([], $exports, 'the restore log was written out before the drop');
		$this->assertIsArray(
			json_decode((string) file_get_contents($exports[0]), true),
			'the export is readable json',
		);
	}

	#[Test]
	#[TestDox('the store on disk is left alone, because an uninstall is not a delete')]
	#[Group('strata/functional')]
	public function uninstallLeavesTheStoreAlone(): void
	{
		$this->content('Preserved');
		$this->flush();

		$before = $this->storedObjects();

		$this->assertNotSame([], $before, 'the flush wrote something');

		$this->assertTrue($this->uninstall(['strata_ui', 'strata']));
		$this->assertSame(
			$before,
			$this->storedObjects(),
			'every object the flush wrote is still in the store',
		);
	}

	#endregion

	#region Reinstalling

	#[Test]
	#[TestDox('reinstalling rebuilds the schema and the settings from scratch')]
	#[Group('strata/functional')]
	public function theModuleReinstalls(): void
	{
		$this->assertTrue($this->uninstall(['strata_ui', 'strata']));
		$this->assertTrue($this->install(['strata', 'strata_ui']));

		$schema = $this->container->get('database')->schema();

		$this->assertTrue($schema->tableExists('strata_journal'));
		$this->assertTrue($schema->tableExists('strata_commit'));
		$this->assertFalse(
			$this->config('strata.settings')->isNew(),
			'the shipped settings landed again',
		);
		$this->assertSame(
			'local',
			$this->config('strata.settings')->get('provider'),
			'and they are the shipped defaults rather than what the last install held',
		);

		$this->user(['view strata timeline']);
		$this->drupalGet('/admin/reports/strata');
		$this->assertSession()->statusCodeEquals(200);
	}

	#endregion

	#region Fixtures

	/**
	 * Every permission any route in either module accepts.
	 *
	 * @return list<string>
	 *   The permissions, each once.
	 */
	private function everyPermission(): array
	{
		$permissions = [];

		foreach (['strata', 'strata_ui'] as $module) {
			foreach ($this->routesOf($module) as $route) {
				foreach ($this->permissionsOf($route) as $permission) {
					$permissions[$permission] = $permission;
				}
			}
		}

		$this->assertNotSame([], $permissions, 'the routes are gated by something');

		return array_values($permissions);
	}

	/**
	 * Leaves both modules' rows in the router table with neither module installed.
	 *
	 * This is the state a real site reported against 1.0.2, and no lane could reach it before: every
	 * test here runs against a site where `core.extension` and the `router` table agree, and that is
	 * the only state in which either reported error is impossible.
	 *
	 * **Not built with the uninstaller, which would prove nothing.** A real uninstall rebuilds the
	 * router, and `user_modules_uninstalled()` strips the module's permissions out of every role -
	 * so every route answers 403 at the access check and no controller is ever resolved. What the
	 * operator did was delete the code, which runs no hook at all: the roles keep the permissions,
	 * the router keeps the rows, and the request reaches a class that is not there.
	 *
	 * So `core.extension` is edited directly and the container is invalidated. That is the one
	 * operation that drops the module's services without asking anything to rebuild the router.
	 *
	 * @param list<string> $modules
	 *   The modules to take out of the module list, dependents first.
	 */
	private function orphan(array $modules): void
	{
		$rows = $this->container
			->get('database')
			->select('router', 'r')
			->fields('r', ['name'])
			->condition('name', 'strata%', 'LIKE')
			->countQuery()
			->execute()
			?->fetchField();

		$this->assertGreaterThan(0, (int) $rows, 'the router held rows to orphan');

		$extension = $this->config('core.extension');
		$installed = (array) $extension->get('module');

		foreach ($modules as $module) {
			unset($installed[$module]);
		}

		$extension->set('module', $installed)->save(true);

		// invalidateContainer() and not drupal_flush_all_caches(): the latter rebuilds the router,
		// which is the very thing a site in this state has not done
		$this->container->get('kernel')->invalidateContainer();

		Cache::invalidateTags(['routes']);
	}

	/**
	 * Uninstalls modules through the real installer.
	 *
	 * @param list<string> $modules
	 *   The modules, dependents first.
	 *
	 * @return bool
	 *   What the installer reported.
	 */
	private function uninstall(array $modules): bool
	{
		$installer = $this->container->get('module_installer');

		$this->assertInstanceOf(ModuleInstallerInterface::class, $installer);

		$done = $installer->uninstall($modules);
		$this->rebuildContainer();

		return $done;
	}

	/**
	 * Installs modules through the real installer.
	 *
	 * @param list<string> $modules
	 *   The modules, dependencies first.
	 *
	 * @return bool
	 *   What the installer reported.
	 */
	private function install(array $modules): bool
	{
		$installer = $this->container->get('module_installer');

		$this->assertInstanceOf(ModuleInstallerInterface::class, $installer);

		$done = $installer->install($modules);
		$this->rebuildContainer();

		return $done;
	}

	/**
	 * Every file the local store holds, relative to its root.
	 *
	 * @return list<string>
	 *   The paths, sorted, so two readings compare.
	 */
	private function storedObjects(): array
	{
		$found = [];
		$length = strlen($this->storeRoot) + 1;

		foreach ($this->walk($this->storeRoot) as $path) {
			$found[] = substr($path, $length);
		}

		sort($found);

		return $found;
	}

	/**
	 * Every file under a directory.
	 *
	 * @param string $directory
	 *   Where to start.
	 *
	 * @return list<string>
	 *   Absolute paths.
	 */
	private function walk(string $directory): array
	{
		if (!is_dir($directory)) {
			return [];
		}

		$found = [];

		foreach (scandir($directory) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$path = $directory . '/' . $entry;
			$found = is_dir($path)
				? array_merge($found, $this->walk($path))
				: array_merge($found, [$path]);
		}

		return $found;
	}

	#endregion
}
