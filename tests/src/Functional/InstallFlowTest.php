<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Functional;

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
	#[TestDox('every route both modules declare answers rather than 404ing')]
	#[Group('strata/functional')]
	public function everyDeclaredRouteAnswers(): void
	{
		$this->user($this->everyPermission());

		foreach (['strata', 'strata_ui'] as $module) {
			foreach ($this->routesOf($module) as $name => $route) {
				$path = $this->pathOf($route, $this->placeholders());

				$this->drupalGet($path);

				$this->assertNotSame(
					404,
					$this->getSession()->getStatusCode(),
					sprintf('%s answers at %s', $name, $path),
				);
			}
		}
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

		return array_values($permissions);
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
