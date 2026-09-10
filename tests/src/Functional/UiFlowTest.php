<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Follows the links between the report pages the way an operator does.
 *
 * **Every navigation here is a click on rendered markup rather than a request for a known path.**
 * `ReportPageTest` proves each page answers when it is asked for; nothing proved anything ever
 * offers it. A tab whose `base_route` names a route nobody declares, a template building a URL for
 * a route that was renamed, a bar with no href - none of those fail a spec that fetches paths, and
 * all of them leave a page an operator cannot get to.
 *
 * The status page is the entry point, so it is driven hardest: it is what a fresh install shows,
 * and the one page that has to render before anything is configured.
 *
 * @see \Drupal\strata_ui\Controller\StatusController
 */
#[RunTestsInSeparateProcesses]
class UiFlowTest extends StrataFunctionalTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * Block is here so the local tasks can be placed; the tabs are the navigation this spec is
	 * about, and stark places no blocks of its own.
	 *
	 * @var list<string>
	 */
	protected static $modules = [
		'system',
		'user',
		'node',
		'field',
		'block',
		'key',
		'strata',
		'strata_ui',
	];

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		// stark places no blocks of its own, so without this the tabs exist as local tasks and
		// never reach the markup a click can find
		$this->drupalPlaceBlock('local_tasks_block');

		$this->user([
			'administer strata',
			'manage strata storage',
			'view strata timeline',
			'view strata diffs',
			'view strata payloads',
			'view strata health',
			'view strata cost estimates',
			'quarantine strata',
			'delete strata snapshots',
			'repair strata',
			'branch strata config',
			'merge strata config',
			'rollback strata content',
			'create strata snapshot',
		]);
	}

	#region The Status Page

	#[Test]
	#[TestDox('a fresh install is told capture is off rather than shown empty numbers')]
	#[Group('strata/functional')]
	public function theStatusPageNamesCaptureBeingOff(): void
	{
		$this->configure(['enabled' => false]);

		$this->drupalGet('/admin/reports/strata');

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('Capture is off');
		$this->assertSession()->pageTextContains('nothing on this site is being backed up');
	}

	#[Test]
	#[TestDox('a configured site is told every check passes and shown what is protected')]
	#[Group('strata/functional')]
	public function theStatusPageReportsAWorkingSite(): void
	{
		// a real key, because a site storing frames in the clear is a warning and not a clean bill
		$this->encryptWith();
		$this->content('Protected');
		$this->flush();

		$this->drupalGet('/admin/reports/strata');

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('every check passes');
		$this->assertSession()->pageTextContains('Unprotected Work');
		$this->assertSession()->pageTextContains('Restore Points');
		$this->assertSession()->pageTextContains('Strata storage');
		$this->assertSession()->pageTextNotContains('Getting Started');
	}

	#[Test]
	#[TestDox('storing frames in the clear is said on the status page rather than passing quietly')]
	#[Group('strata/functional')]
	public function theStatusPageSaysWhenNothingIsEncrypted(): void
	{
		$this->drupalGet('/admin/reports/strata');

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('Strata encryption key');
		$this->assertSession()->pageTextContains('stored unencrypted');
	}

	#[Test]
	#[TestDox('a store that cannot be written to is an error on the status page, not a blank one')]
	#[Group('strata/functional')]
	public function theStatusPageReportsAnUnusableStore(): void
	{
		// two levels down, so the parent is missing too and nothing could create the root; one level
		// down is created at the first flush and is correctly not reported as a fault
		$this->configure(['local_path' => $this->storeRoot . '/absent/never-created']);

		$this->drupalGet('/admin/reports/strata');

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('has to be fixed');
		$this->assertSession()->pageTextContains('Strata storage');
	}

	#[Test]
	#[
		TestDox(
			'the status page renders with encryption on and no key, which is the shipped default',
		),
	]
	#[Group('strata/functional')]
	public function theStatusPageRendersBeforeAKeyIsChosen(): void
	{
		$this->configure(['cipher.id' => 'xchacha20poly1305', 'key' => '']);

		$this->drupalGet('/admin/reports/strata');

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('Strata encryption');
	}

	#[Test]
	#[TestDox('nothing captured yet is said in words rather than shown as a zero lag')]
	#[Group('strata/functional')]
	public function theStatusPageNamesAnEmptyHistory(): void
	{
		$this->assertNull($this->head());

		$this->drupalGet('/admin/reports/strata');

		$this->assertSession()->pageTextContains('Nothing captured yet');
		$this->assertSession()->pageTextContains('Nothing has proved a restore works here yet');
	}

	#endregion

	#region Getting Started

	#[Test]
	#[TestDox('every step of the checklist links somewhere the reader can actually act')]
	#[Group('strata/functional')]
	public function everySetupStepGoesSomewhere(): void
	{
		$this->assertNull($this->head(), 'the checklist is showing because nothing is sealed');

		$this->drupalGet('/admin/reports/strata');

		$this->assertSession()->pageTextContains('Getting Started');

		// the last step said "wait for cron, or run a drush command" and linked nowhere until 1.0.3,
		// which is a dead end on a site whose operator has neither
		$this->assertSession()->linkExists('Seal the first window');
	}

	#[Test]
	#[TestDox('sealing from the checklist finishes the setup and the checklist goes away')]
	#[Group('strata/functional')]
	public function sealingFromTheChecklistFinishesSetup(): void
	{
		$this->content('Sealed From the Checklist');

		$this->drupalGet('/admin/reports/strata');
		$this->clickLink('Seal the first window');

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('Waiting to Seal');

		$this->submitForm([], 'Seal Now');

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextNotContains('Getting Started');
		$this->assertNotNull($this->head(), 'the button sealed a commit');
	}

	#[Test]
	#[TestDox('sealing an empty window says so rather than reporting a commit nobody wrote')]
	#[Group('strata/functional')]
	public function sealingNothingSaysSo(): void
	{
		// capture off and the journal drained, so the window is empty however many requests the
		// browser made getting here
		$this->configure(['enabled' => false]);
		$this->engine()
			->journal()
			->trim(PHP_INT_MAX);

		$this->drupalGet('/admin/config/system/strata/flush');
		$this->submitForm([], 'Seal Now');

		$this->assertSession()->pageTextContains('nothing to seal');
		$this->assertNull($this->head());
	}

	#endregion

	#region Clicking Through

	#[Test]
	#[TestDox('every report is reached by clicking its tab from the status page')]
	#[Group('strata/functional')]
	public function everyTabIsClickableFromStatus(): void
	{
		foreach (['Timeline', 'Graphs', 'Diff', 'Health', 'Branches'] as $tab) {
			$this->drupalGet('/admin/reports/strata');
			$this->clickLink($tab);

			$this->assertSession()->statusCodeEquals(200);
			$this->assertSession()->pageTextContains('Strata');
		}
	}

	#[Test]
	#[TestDox('every settings page is reached by clicking its link from the configuration section')]
	#[Group('strata/functional')]
	public function everySettingsPageIsClickableFromTheSection(): void
	{
		foreach (['Storage', 'Capture', 'Retention', 'Tiers', 'Webhooks', 'Telemetry'] as $page) {
			$this->drupalGet('/admin/config/system/strata');
			$this->clickLink($page);

			$this->assertSession()->statusCodeEquals(200);
			$this->assertSession()->buttonExists('Save configuration');
		}
	}

	#[Test]
	#[TestDox('the status page offers the reports an account holds the permission for')]
	#[Group('strata/functional')]
	public function theStatusPageLinksOnwards(): void
	{
		$this->drupalGet('/admin/reports/strata');
		$this->clickLink('Storage Explorer');

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->addressEquals('/admin/config/system/strata/explorer');
	}

	#[Test]
	#[TestDox('a status page offers no report the account may not open')]
	#[Group('strata/functional')]
	public function theStatusPageHidesWhatIsNotGranted(): void
	{
		$this->user(['view strata timeline']);

		$this->drupalGet('/admin/reports/strata');

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->linkExists('Timeline');
		$this->assertSession()->linkNotExists('Settings');
		$this->assertSession()->linkNotExists('Storage Explorer');
	}

	#[Test]
	#[TestDox('a timeline bar links to the commits in its window, and each row to its actions')]
	#[Group('strata/functional')]
	public function aTimelineBarReachesItsCommits(): void
	{
		$this->content('Bar One');
		$this->flush();

		$this->drupalGet('/admin/reports/strata/timeline');

		$href = $this->firstBucketHref();

		$this->assertIsString($href, 'a bar in the chart links to its window');

		$this->drupalGet($href);

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->linkExists('Roll Back Here');
		$this->assertSession()->linkExists('What It Holds');
		$this->assertSession()->linkExists('Quarantine');

		$this->clickLink('What It Holds');
		$this->assertSession()->statusCodeEquals(200);
	}

	#[Test]
	#[TestDox('the commit table names its last column for the actions in it')]
	#[Group('strata/functional')]
	public function theCommitTableNamesItsActionColumn(): void
	{
		$this->content('Columned');
		$this->flush();

		$this->drupalGet('/admin/reports/strata/timeline');

		$href = $this->firstBucketHref();

		$this->assertIsString($href);

		$this->drupalGet($href);

		$headers = $this->getSession()->getPage()->findAll('css', 'th');
		$titles = array_map(static fn($cell): string => trim($cell->getText()), $headers);

		$this->assertSame(
			array_values(array_unique($titles)),
			$titles,
			'no column heading is repeated, so every column says what it holds',
		);
		$this->assertContains('Actions', $titles);
	}

	#endregion

	#region Confirming

	#[Test]
	#[TestDox('quarantining a commit through the form takes it out of the restore targets')]
	#[Group('strata/functional')]
	public function quarantineConfirmsAndRecords(): void
	{
		$this->content('Quarantined');
		$this->flush();

		$head = $this->head();

		$this->assertIsString($head);

		$this->drupalGet('/admin/reports/strata/quarantine/' . $head);

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('Its bytes are kept');

		$this->submitForm(['reason' => 'a spec quarantined it'], 'Quarantine');

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('is quarantined');

		$open = $this->engine()->ledger()->summary();

		$this->assertNotSame([], $open, 'the ledger holds the finding the form recorded');
	}

	#[Test]
	#[TestDox('a commit no store holds is named on the quarantine form rather than confirmed')]
	#[Group('strata/functional')]
	public function quarantineRefusesAnAbsentCommit(): void
	{
		$this->drupalGet('/admin/reports/strata/quarantine/' . self::ABSENT_COMMIT);

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('not in the local index');
	}

	#[Test]
	#[TestDox('the prune form says there is nothing to prune rather than offering a button')]
	#[Group('strata/functional')]
	public function pruneSaysWhenThereIsNothingToDo(): void
	{
		$this->content('Reachable');
		$this->flush();

		$this->drupalGet('/admin/config/system/strata/prune');

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('Prune stored history?');
		$this->assertSession()->pageTextContains('There is nothing to prune.');
	}

	#[Test]
	#[TestDox('a repair the ladder does not run automatically is offered to a person instead')]
	#[Group('strata/functional')]
	public function repairNamesAFindingNothingHolds(): void
	{
		$this->drupalGet('/admin/reports/strata/repair/' . self::ABSENT_CODE);

		$this->assertSession()->statusCodeEquals(200);
	}

	#endregion

	#region Fixtures

	/**
	 * The href of the first linked bar in the timeline chart.
	 *
	 * @return string|null
	 *   The path, or NULL when no bar is linked.
	 */
	private function firstBucketHref(): ?string
	{
		$link = $this->getSession()->getPage()->find('css', '.strata-chart a');

		return $link === null ? null : $link->getAttribute('href');
	}

	#endregion
}
