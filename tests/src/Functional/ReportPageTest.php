<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves every page the UI declares answers, on an empty store and on a populated one.
 *
 * **The empty run is the one that matters.** A fresh install is the first thing an operator sees, and
 * a report that divides by a total, indexes the newest commit or reads the head's parent works on a
 * busy site and fatals on an empty one. Nothing in the kernel lane reaches that, because a kernel
 * test calls a controller method rather than rendering a page through a theme.
 *
 * The route list is read from `strata_ui.routing.yml` rather than written out here, so a page added
 * later is driven by this spec without it being edited.
 */
#[RunTestsInSeparateProcesses]
class ReportPageTest extends StrataFunctionalTestBase
{
	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->user($this->everyPermission());
	}

	/**
	 * Every permission any UI route accepts.
	 *
	 * Taken from the routing file so a route added with a new permission is still reachable here, and
	 * a route whose permission was renamed fails rather than silently falling back to a 403.
	 *
	 * @return list<string>
	 *   The permissions, each once.
	 */
	private function everyPermission(): array
	{
		$permissions = [];

		foreach ($this->routesOf('strata_ui') as $route) {
			foreach ($this->permissionsOf($route) as $permission) {
				$permissions[$permission] = $permission;
			}
		}

		$this->assertNotSame([], $permissions, 'the routes are gated by something');

		return array_values($permissions);
	}

	/**
	 * Requests every UI route and asserts each one answered.
	 *
	 * @param array<string, string> $parameters
	 *   Placeholder name keyed to its value.
	 */
	private function driveEveryPage(array $parameters): void
	{
		$routes = $this->routesOf('strata_ui');

		$this->assertNotSame([], $routes, 'the UI declares routes');

		foreach ($routes as $name => $route) {
			$path = $this->pathOf($route, $parameters);

			$this->assertStringNotContainsString(
				'{',
				$path,
				sprintf('%s has a value for every placeholder in its path', $name),
			);

			$this->drupalGet($path);

			$this->assertSame(
				200,
				$this->getSession()->getStatusCode(),
				sprintf('%s answered at %s', $name, $path),
			);
		}
	}

	#region Both Stores

	#[Test]
	#[TestDox('every report page answers on a store that holds nothing at all')]
	#[Group('strata/functional')]
	public function everyPageAnswersOnAnEmptyStore(): void
	{
		$this->assertNull($this->head(), 'nothing has been sealed');

		$this->driveEveryPage($this->placeholders());
	}

	#[Test]
	#[TestDox('every report page answers once a history of two commits exists')]
	#[Group('strata/functional')]
	public function everyPageAnswersOnAPopulatedStore(): void
	{
		$node = $this->content('First Revision');
		$this->flush();

		$node->set('title', 'Second Revision');
		$node->save();
		$this->flush();

		$head = $this->engine()->commitLog()->head();

		$this->assertNotNull($head, 'a commit was sealed');
		$this->assertNotNull($head->parent, 'the history is long enough to have a pair');

		$this->driveEveryPage($this->placeholders($head->id(), (string) $head->parent));
	}

	#endregion

	#region What a Fresh Install Says

	#[Test]
	#[TestDox('the timeline says nothing has been captured rather than drawing an empty chart')]
	#[Group('strata/functional')]
	public function timelineNamesAnEmptyWindow(): void
	{
		$this->drupalGet('/admin/reports/strata/timeline');

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('Nothing has been captured in this window.');
	}

	#[Test]
	#[TestDox('the diff says there is not yet a pair to compare rather than reading a null parent')]
	#[Group('strata/functional')]
	public function diffNamesAHistoryTooShortToCompare(): void
	{
		$this->drupalGet('/admin/reports/strata/diff');

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('There are not yet two commits to compare.');
	}

	#[Test]
	#[TestDox('the health dashboard says nothing is open and no drill has run')]
	#[Group('strata/functional')]
	public function healthNamesACleanLedger(): void
	{
		$this->drupalGet('/admin/reports/strata/health');

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('Nothing is open.');
		$this->assertSession()->pageTextContains('Nothing has proved a restore works here yet');
	}

	#[Test]
	#[TestDox('the storage explorer explains itself when encryption is on and no key is chosen')]
	#[Group('strata/functional')]
	public function explorerExplainsAnUnassembledEngine(): void
	{
		// the shipped default, which is what a site has before anybody chooses a key
		$this->configure(['cipher.id' => 'xchacha20poly1305', 'key' => '']);

		$this->drupalGet('/admin/config/system/strata/explorer');

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('This report is unavailable');
		$this->assertSession()->linkExists('Strata Storage Settings');
	}

	#endregion

	#region Once There Is Something to Show

	#[Test]
	#[TestDox('the timeline counts the commits a flush sealed')]
	#[Group('strata/functional')]
	public function timelineCountsSealedCommits(): void
	{
		$this->content('Counted');
		$result = $this->flush();

		$this->assertTrue($result->ran, 'the window was sealed');

		$this->drupalGet('/admin/reports/strata/timeline');

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('Commits');
		$this->assertSession()->pageTextNotContains('Nothing has been captured in this window.');
	}

	#[Test]
	#[TestDox('the storage explorer reports what the store holds after a flush')]
	#[Group('strata/functional')]
	public function explorerReportsAPopulatedStore(): void
	{
		$this->content('Explored');
		$this->flush();

		$this->drupalGet('/admin/config/system/strata/explorer');

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextNotContains('This report is unavailable');
	}

	#endregion
}
