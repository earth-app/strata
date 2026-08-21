<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Functional;

use Drupal\strata\Journal\Realm;
use Drupal\strata\Restore\SubjectStatus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves the operator flow: change something, seal it, then put it back through the browser.
 *
 * **The manifest has to be above the button.** A confirmation that said "this will restore 1,240
 * subjects" without saying which of them cannot be read would be asking somebody to approve
 * something nobody had checked, and the only place that ordering exists is the rendered page.
 *
 * **A restore never deletes what postdates it.** The form says so, and a subject that did not exist
 * at the target is simply not in the plan - so the node created after the restore point is still
 * there when the restore finishes. That is the claim most worth a test, because the failure mode is
 * data loss during a recovery.
 *
 * Only the entity realm is captured here. A table-realm restore replaces whole table contents, which
 * is a different operation with a different permission, and mixing it in would make this spec about
 * two things.
 */
#[RunTestsInSeparateProcesses]
class RollbackFlowTest extends StrataFunctionalTestBase
{
	/**
	 * The title the node is rolled back to.
	 */
	public const ORIGINAL = 'Original Title';

	/**
	 * The title the node is changed to after the restore point.
	 */
	public const CHANGED = 'Changed Title';

	/**
	 * A node created after the restore point, which the restore must leave alone.
	 */
	public const LATER = 'Created After The Restore Point';

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->configure($this->entityRealmOnly());
		$this->user(['rollback strata content', 'view strata timeline']);
	}

	/**
	 * Capture narrowed to the one realm this flow is about.
	 *
	 * @return array<string, bool>
	 *   Setting keys keyed to whether that realm is captured.
	 */
	private function entityRealmOnly(): array
	{
		$overrides = ['capture.statements' => false];

		foreach (Realm::cases() as $realm) {
			$overrides['capture.' . $realm->value] = $realm === Realm::ENTITY;
		}

		return $overrides;
	}

	/**
	 * Builds the history the rollback targets and returns the commit to roll back to.
	 *
	 * @return string
	 *   The commit id of the moment the node still had its original title.
	 */
	private function historyToRollBackTo(): string
	{
		$node = $this->content(self::ORIGINAL);

		$this->assertTrue($this->flush()->ran, 'the original title was sealed');

		$target = $this->head();

		$this->assertNotNull($target, 'a commit was sealed');

		$node->set('title', self::CHANGED);
		$node->save();
		$this->content(self::LATER);

		$this->assertTrue($this->flush()->ran, 'the change was sealed');
		$this->assertNotSame($target, $this->head(), 'the history is a chain of two commits');

		return $target;
	}

	#region The Confirmation

	#[Test]
	#[TestDox('the confirm form prints the manifest and the status split above the button')]
	#[Group('strata/functional')]
	public function confirmFormPrintsTheManifestAboveTheButton(): void
	{
		$target = $this->historyToRollBackTo();

		$this->drupalGet('/admin/reports/strata/rollback/' . $target);
		$this->assertSession()->statusCodeEquals(200);

		$this->assertSession()->pageTextContains('What Would Happen');
		$this->assertSession()->pageTextContains('Replaying to this point walks');
		$this->assertSession()->pageTextContains(
			'Later content is never deleted, and access fields are never written.',
		);
		$this->assertSession()->pageTextContains('A snapshot is captured first, always.');

		foreach (SubjectStatus::cases() as $status) {
			$this->assertSession()->pageTextContains($status->label());
		}

		$this->assertSession()->buttonExists('Roll Back');

		$html = $this->getSession()->getPage()->getContent();
		$manifest = strpos($html, 'What Would Happen');
		$button = strpos($html, 'value="Roll Back"');

		$this->assertIsInt($manifest, 'the manifest is on the page');
		$this->assertIsInt($button, 'the confirm button is on the page');
		$this->assertLessThan($button, $manifest, 'the manifest is printed above the button');
	}

	#[Test]
	#[TestDox('a commit no store holds is named rather than confirmed')]
	#[Group('strata/functional')]
	public function anAbsentCommitIsNamedRatherThanConfirmed(): void
	{
		$this->drupalGet('/admin/reports/strata/rollback/' . self::ABSENT_COMMIT);

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('That commit could not be planned against.');
		$this->assertSession()->buttonNotExists('Roll Back');
	}

	#endregion

	#region The Restore

	#[Test]
	#[TestDox('submitting the confirmation puts the earlier value back')]
	#[Group('strata/functional')]
	public function submittingTheConfirmationRestoresTheEarlierValue(): void
	{
		$target = $this->historyToRollBackTo();

		$this->assertNotFalse(
			$this->drupalGetNodeByTitle(self::CHANGED, true),
			'the change is live before the rollback',
		);

		$this->drupalGet('/admin/reports/strata/rollback/' . $target);
		$this->submitForm([], 'Roll Back');

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextNotContains('The rollback was refused');
		$this->assertSession()->addressEquals('/admin/reports/strata/timeline');

		$this->assertNotFalse(
			$this->drupalGetNodeByTitle(self::ORIGINAL, true),
			'the earlier title is back',
		);
	}

	#[Test]
	#[TestDox('content created after the restore point is still there afterwards')]
	#[Group('strata/functional')]
	public function laterContentSurvivesTheRestore(): void
	{
		$target = $this->historyToRollBackTo();
		$later = $this->drupalGetNodeByTitle(self::LATER, true);

		$this->assertNotFalse($later, 'the later node exists before the rollback');

		$this->drupalGet('/admin/reports/strata/rollback/' . $target);
		$this->submitForm([], 'Roll Back');

		$survivor = $this->drupalGetNodeByTitle(self::LATER, true);

		$this->assertNotFalse($survivor, 'a restore never deletes what postdates it');
		$this->assertSame((int) $later->id(), (int) $survivor->id());
	}

	#endregion
}
