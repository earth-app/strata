<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Capture\EntityDelta;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves the delta reduction against real entities saved through a real entity manager.
 */
class EntityDeltaTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'field', 'key', 'strata'];

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->installEntitySchema('user');
	}

	/**
	 * A saved user, with uid 0 and 1 already burned.
	 */
	private function user(string $name): User
	{
		$user = User::create(['name' => $name, 'mail' => $name . '@example.com', 'status' => 1]);
		$user->save();

		return $user;
	}

	#region Creates

	#[Test]
	#[TestDox('a create reports every field it has, since there is nothing to compare against')]
	#[Group('strata/capture')]
	public function createReportsEveryField(): void
	{
		$user = User::create(['name' => 'fresh', 'mail' => 'fresh@example.com']);

		$changed = EntityDelta::changedFields($user, null);

		$this->assertContains('name', $changed);
		$this->assertContains('mail', $changed);
		$this->assertTrue(EntityDelta::isMeaningful($user, null));
		$this->assertFalse(EntityDelta::isAccessTouch($user, null));
	}

	#[Test]
	#[TestDox('a create never reports a field that only describes the save')]
	#[Group('strata/capture')]
	public function createExcludesSaveMetadata(): void
	{
		$changed = EntityDelta::changedFields($this->user('meta'), null);

		foreach (EntityDelta::IGNORED as $ignored) {
			$this->assertNotContains($ignored, $changed);
		}
	}

	#endregion

	#region Updates

	#[Test]
	#[TestDox('a title change reports that field alone')]
	#[Group('strata/capture')]
	public function singleFieldChangeReportsOneField(): void
	{
		$user = $this->user('before');
		$original = clone $user;

		$user->set('name', 'after');

		$this->assertSame(['name'], EntityDelta::changedFields($user, $original));
		$this->assertSame(
			['name' => [['value' => 'after']]],
			EntityDelta::payload($user, $original),
		);
	}

	#[Test]
	#[TestDox('two field changes report both')]
	#[Group('strata/capture')]
	public function twoFieldChangesReportBoth(): void
	{
		$user = $this->user('two');
		$original = clone $user;

		$user->set('name', 'renamed');
		$user->set('mail', 'renamed@example.com');

		$changed = EntityDelta::changedFields($user, $original);
		sort($changed);

		$this->assertSame(['mail', 'name'], $changed);
		$this->assertCount(2, EntityDelta::payload($user, $original));
	}

	#[Test]
	#[TestDox('a save that changed nothing is not meaningful')]
	#[Group('strata/capture')]
	public function unchangedSaveIsNotMeaningful(): void
	{
		$user = $this->user('same');
		$original = clone $user;

		$this->assertSame([], EntityDelta::changedFields($user, $original));
		$this->assertFalse(EntityDelta::isMeaningful($user, $original));
		$this->assertSame([], EntityDelta::payload($user, $original));
	}

	#[Test]
	#[TestDox('a save that moved only the changed timestamp is not meaningful')]
	#[Group('strata/capture')]
	public function changedTimestampAloneIsNotMeaningful(): void
	{
		$user = $this->user('touched');
		$original = clone $user;

		$user->set('changed', (int) $user->get('changed')->value + 3600);

		$this->assertSame([], EntityDelta::changedFields($user, $original));
		$this->assertFalse(EntityDelta::isMeaningful($user, $original));
	}

	#[Test]
	#[TestDox('the payload carries only the changed fields, not the whole entity')]
	#[Group('strata/capture')]
	public function payloadIsSmallerThanTheEntity(): void
	{
		$user = $this->user('small');
		$original = clone $user;

		$user->set('name', 'renamed');

		$delta = (string) json_encode(EntityDelta::payload($user, $original));
		$whole = (string) json_encode($user->toArray());

		$this->assertLessThan(strlen($whole) / 3, strlen($delta));
	}

	#endregion

	#region Access churn

	#[Test]
	#[TestDox('a save that moved only the access timestamps is recognised as churn')]
	#[Group('strata/capture')]
	public function accessTouchIsRecognised(): void
	{
		$user = $this->user('churn');
		$original = clone $user;

		$user->set('access', (int) $user->get('access')->value + 180);
		$user->set('login', (int) $user->get('login')->value + 180);

		$this->assertTrue(EntityDelta::isAccessTouch($user, $original));
	}

	#[Test]
	#[TestDox('the access fields never appear in a user delta')]
	#[Group('strata/capture')]
	public function accessFieldsStayOutOfDeltas(): void
	{
		$user = $this->user('excluded');
		$original = clone $user;

		$user->set('name', 'renamed');
		$user->set('access', (int) $user->get('access')->value + 180);
		$user->set('login', (int) $user->get('login')->value + 180);

		$changed = EntityDelta::changedFields($user, $original);

		$this->assertSame(['name'], $changed);

		foreach (EntityDelta::ACCESS_FIELDS as $field) {
			$this->assertArrayNotHasKey($field, EntityDelta::payload($user, $original));
		}
	}

	#[Test]
	#[TestDox('a real change alongside an access touch is not churn')]
	#[Group('strata/capture')]
	public function realChangeAlongsideAccessIsNotChurn(): void
	{
		$user = $this->user('mixed');
		$original = clone $user;

		$user->set('name', 'renamed');
		$user->set('access', (int) $user->get('access')->value + 180);

		$this->assertFalse(EntityDelta::isAccessTouch($user, $original));
		$this->assertTrue(EntityDelta::isMeaningful($user, $original));
	}

	#[Test]
	#[TestDox('access churn is only recognised on a user, not on another entity type')]
	#[Group('strata/capture')]
	public function accessChurnIsUserOnly(): void
	{
		$user = $this->user('only');
		$original = clone $user;
		$user->set('access', (int) $user->get('access')->value + 180);

		$this->assertTrue(EntityDelta::isAccessTouch($user, $original));
		$this->assertFalse(
			EntityDelta::isAccessTouch($user, null),
			'a create is never an access touch',
		);
	}

	#endregion
}
