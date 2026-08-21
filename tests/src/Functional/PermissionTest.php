<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Functional;

use Drupal\strata\Access\RestoreAccess;
use Drupal\strata\Journal\Realm;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves every Strata route is gated, and that a narrow grant stays narrow.
 *
 * **The route list is read from the YAML rather than written out here**, so a route added without a
 * permission fails this spec and a route added with one is covered by it. That is the only way a
 * permission spec keeps up with a module that has four routing files.
 *
 * The escalation cases are the ones a reviewer cares about. Every rollback permission is marked
 * restricted because configuration includes `user.role.*`, so an account that can restore
 * configuration can restore a state in which it had more permissions than it has now. Content
 * rollback must therefore never imply it, and the check is per realm rather than per route - a plan
 * spanning three realms needs all three.
 *
 * @see RestoreAccess
 */
#[RunTestsInSeparateProcesses]
class PermissionTest extends StrataFunctionalTestBase
{
	/**
	 * The routing files this module and its submodules declare.
	 */
	public const PROVIDERS = ['strata', 'strata_ui', 'strata_s3', 'strata_notify'];

	/**
	 * The permission that sees history and nothing else.
	 */
	public const TIMELINE = 'view strata timeline';

	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = [
		'system',
		'user',
		'node',
		'field',
		'key',
		'strata',
		'strata_ui',
		'strata_s3',
		'strata_notify',
	];

	/**
	 * Every route any of this module's routing files declares.
	 *
	 * @return array<string, array<string, mixed>>
	 *   Route name keyed to its definition.
	 */
	private function everyRoute(): array
	{
		$routes = [];

		foreach (self::PROVIDERS as $module) {
			foreach ($this->routesOf($module) as $name => $route) {
				$routes[$name] = $route;
			}
		}

		return $routes;
	}

	/**
	 * The status code the current session got for a path.
	 *
	 * @param string $path
	 *   The path to request.
	 *
	 * @return int
	 *   The status code.
	 */
	private function statusOf(string $path): int
	{
		$this->drupalGet($path);

		return $this->getSession()->getStatusCode();
	}

	#region Every Route

	#[Test]
	#[TestDox('every route this module declares is gated by a permission')]
	#[Group('strata/functional')]
	public function everyRouteDeclaresAPermission(): void
	{
		$routes = $this->everyRoute();

		$this->assertNotSame([], $routes, 'the routing files declare routes');

		foreach ($routes as $name => $route) {
			$this->assertNotSame(
				[],
				$this->permissionsOf($route),
				sprintf('%s declares a _permission requirement', $name),
			);
		}
	}

	#[Test]
	#[TestDox('anonymous is refused every route rather than shown an empty page')]
	#[Group('strata/functional')]
	public function anonymousIsRefusedEveryRoute(): void
	{
		foreach ($this->everyRoute() as $name => $route) {
			$path = $this->pathOf($route, $this->placeholders());

			$this->assertSame(
				403,
				$this->statusOf($path),
				sprintf('%s refused an anonymous request at %s', $name, $path),
			);
		}
	}

	#[Test]
	#[TestDox('each route is reached by the first permission it declares and by nothing less')]
	#[Group('strata/functional')]
	public function eachRouteIsReachedByItsOwnPermission(): void
	{
		foreach ($this->everyRoute() as $name => $route) {
			$permission = $this->permissionsOf($route)[0];
			$path = $this->pathOf($route, $this->placeholders());

			$this->user([$permission]);

			$this->assertSame(
				200,
				$this->statusOf($path),
				sprintf('%s answered a holder of "%s" at %s', $name, $permission, $path),
			);
		}
	}

	#endregion

	#region Escalation

	#[Test]
	#[TestDox('seeing the timeline reaches no route that changes or destroys anything')]
	#[Group('strata/functional')]
	public function timelineViewerReachesNothingDestructive(): void
	{
		$this->user([self::TIMELINE]);

		foreach ($this->everyRoute() as $name => $route) {
			$permitted = $this->permissionsOf($route);
			$path = $this->pathOf($route, $this->placeholders());
			$expected = in_array(self::TIMELINE, $permitted, true) ? 200 : 403;

			$this->assertSame(
				$expected,
				$this->statusOf($path),
				sprintf('%s answered a timeline viewer at %s', $name, $path),
			);
		}
	}

	#[Test]
	#[TestDox('a timeline viewer reaches no rollback, repair or quarantine route')]
	#[Group('strata/functional')]
	public function timelineViewerReachesNoRestoreRoute(): void
	{
		$this->user([self::TIMELINE]);

		$gated = [
			'/admin/reports/strata/rollback/' . self::ABSENT_COMMIT,
			'/admin/reports/strata/quarantine/' . self::ABSENT_COMMIT,
			'/admin/reports/strata/repair/' . self::ABSENT_CODE,
			'/admin/config/system/strata/prune',
		];

		foreach ($gated as $path) {
			$this->assertSame(403, $this->statusOf($path), $path . ' is refused');
		}
	}

	#[Test]
	#[TestDox('rolling back content does not grant rolling back configuration')]
	#[Group('strata/functional')]
	public function contentRollbackDoesNotGrantConfigRollback(): void
	{
		$commit = $this->historySpanningTwoRealms();
		$path = '/admin/reports/strata/rollback/' . $commit;

		$this->user(['rollback strata content']);
		$this->drupalGet($path);

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('This plan touches realms you may not roll back');
		$this->assertSession()->buttonNotExists('Roll Back');

		$this->user(['administer strata']);
		$this->drupalGet($path);

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextNotContains(
			'This plan touches realms you may not roll back',
		);
		$this->assertSession()->buttonExists('Roll Back');
	}

	#[Test]
	#[TestDox('a rollback grant for one realm reaches the route and is refused by the plan')]
	#[Group('strata/functional')]
	public function aRollbackGrantForOneRealmRefusesAWiderPlan(): void
	{
		$commit = $this->historySpanningTwoRealms();

		$this->user([RestoreAccess::legacyPermission(Realm::FILE)]);
		$this->drupalGet('/admin/reports/strata/rollback/' . $commit);

		// any rollback permission reaches the route; the plan decides, because which realms it
		// touches is not knowable until it exists
		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('This plan touches realms you may not roll back');
		$this->assertSession()->buttonNotExists('Roll Back');
	}

	#endregion

	/**
	 * Seals a commit whose restore plan names more than one realm.
	 *
	 * @return string
	 *   The commit id.
	 */
	private function historySpanningTwoRealms(): string
	{
		$this->content('Two Realms');
		$this->config('system.site')->set('slogan', 'captured by strata')->save();
		$this->flush();

		$commit = $this->head();

		$this->assertNotNull($commit, 'a commit was sealed');

		$plan = $this->engine()->preflight()->plan($commit);
		$realms = RestoreAccess::realmsIn($plan);

		$this->assertContains(Realm::ENTITY, $realms, 'the plan names the entity realm');
		$this->assertContains(Realm::CONFIG, $realms, 'the plan names the config realm');

		return $commit;
	}
}
