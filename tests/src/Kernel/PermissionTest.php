<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Access\RestoreAccess;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Restore\ReplayResult;
use Drupal\strata\Restore\RestorePlan;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves rollback is gated per realm, and that config rollback is not implied by content rollback.
 */
class PermissionTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'field', 'key', 'strata'];

	/**
	 * {@inheritdoc}
	 *
	 * The super-user policy makes uid 1 bypass every permission check, which would make each of
	 * these assertions pass for the wrong reason. These tests are about the gating itself.
	 */
	protected bool $usesSuperUserAccessPolicy = false;

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->installEntitySchema('user');
		$this->installSchema('user', ['users_data']);
		$this->installConfig(['user']);
	}

	/**
	 * The permissions the module declares, both static and generated.
	 *
	 * @return array<string, mixed>
	 *   Permission name keyed to its definition.
	 */
	private function declared(): array
	{
		return $this->container->get('user.permissions')->getPermissions();
	}

	/**
	 * A user holding exactly the permissions named.
	 *
	 * @param list<string> $permissions
	 *   The permissions to grant.
	 */
	private function userWith(array $permissions): User
	{
		$id = 'strata_test_' . substr(md5(implode(',', $permissions)), 0, 8);
		$role = Role::create(['id' => $id, 'label' => $id]);

		foreach ($permissions as $permission) {
			$role->grantPermission($permission);
		}

		$role->save();

		$user = User::create([
			'name' => $id,
			'mail' => $id . '@example.com',
			'status' => 1,
			'roles' => [$id],
		]);
		$user->save();

		return $user;
	}

	/**
	 * A plan touching one realm.
	 */
	private function planFor(Realm $realm): RestorePlan
	{
		$subject = $realm->value . '/thing:1';

		return new RestorePlan('a-target', 1, [
			$subject => new ReplayResult($subject, true, ['name' => 'x'], 1, 1),
		]);
	}

	private function access(bool $approval = false): RestoreAccess
	{
		return new RestoreAccess($approval);
	}

	#region What Is Declared

	#[Test]
	#[TestDox('every named permission is declared, and the dangerous ones are restricted')]
	#[Group('strata/access')]
	public function namedPermissionsAreDeclared(): void
	{
		$declared = $this->declared();

		foreach (
			[
				'administer strata',
				'view strata timeline',
				'view strata diffs',
				'view strata payloads',
				'view strata cost estimates',
				'view strata health',
				'create strata snapshot',
				'rollback strata content',
				'rollback strata config',
				'rollback strata files',
				'rollback strata database',
				'rollback strata full site',
				'branch strata config',
				'merge strata config',
				'repair strata',
				'quarantine strata',
				'delete strata snapshots',
				'manage strata storage',
			]
			as $permission
		) {
			$this->assertArrayHasKey($permission, $declared, $permission . ' is declared');
		}

		foreach (
			[
				'administer strata',
				'view strata payloads',
				'rollback strata config',
				'rollback strata database',
				'rollback strata full site',
				'merge strata config',
				'delete strata snapshots',
			]
			as $restricted
		) {
			$this->assertTrue(
				(bool) ($declared[$restricted]['restrict access'] ?? false),
				$restricted . ' is marked restricted',
			);
		}
	}

	#[Test]
	#[TestDox('a per-realm rollback permission is generated for every restorable realm')]
	#[Group('strata/access')]
	public function realmPermissionsAreGenerated(): void
	{
		$declared = $this->declared();

		foreach (Realm::cases() as $realm) {
			$permission = RestoreAccess::realmPermission($realm);

			if (!$realm->isRestorable()) {
				$this->assertArrayNotHasKey(
					$permission,
					$declared,
					'ephemeral state is never restored, so no permission claims it can be',
				);

				continue;
			}

			$this->assertArrayHasKey($permission, $declared, $permission . ' is generated');
			$this->assertTrue((bool) ($declared[$permission]['restrict access'] ?? false));
		}
	}

	#[Test]
	#[TestDox('viewing the timeline is not restricted, since it names no stored value')]
	#[Group('strata/access')]
	public function readOnlyPermissionsAreNotRestricted(): void
	{
		$declared = $this->declared();

		foreach (
			[
				'view strata timeline',
				'view strata diffs',
				'view strata health',
				'branch strata config',
			]
			as $permission
		) {
			$this->assertFalse((bool) ($declared[$permission]['restrict access'] ?? false));
		}
	}

	#[Test]
	#[TestDox('branching and merging are separate grants, and merging is the restricted one')]
	#[Group('strata/access')]
	public function branchingDoesNotGrantMerging(): void
	{
		$declared = $this->declared();

		$this->assertArrayHasKey('branch strata config', $declared);
		$this->assertArrayHasKey('merge strata config', $declared);
		$this->assertNotSame(
			$declared['branch strata config'],
			$declared['merge strata config'],
			'forking a branch writes nothing to the site and merging one writes configuration to it',
		);
		$this->assertTrue((bool) ($declared['merge strata config']['restrict access'] ?? false));
		$this->assertFalse((bool) ($declared['branch strata config']['restrict access'] ?? false));

		$account = $this->userWith(['branch strata config']);

		$this->assertTrue($account->hasPermission('branch strata config'));
		$this->assertFalse(
			$account->hasPermission('merge strata config'),
			'merging writes config to the live site, so it is never implied by branching',
		);
		$this->assertFalse($account->hasPermission('rollback strata config'));
	}

	#endregion

	#region Per Realm

	#[Test]
	#[TestDox('content rollback does not grant config rollback')]
	#[Group('strata/access')]
	public function contentDoesNotGrantConfig(): void
	{
		$account = $this->userWith(['rollback strata content']);
		$access = $this->access();

		$this->assertTrue($access->forRealm($account, Realm::ENTITY)->isAllowed());
		$this->assertFalse(
			$access->forRealm($account, Realm::CONFIG)->isAllowed(),
			'config carries roles and text formats, so it is a separate grant',
		);
		$this->assertFalse($access->forRealm($account, Realm::TABLE)->isAllowed());
	}

	#[Test]
	#[TestDox('the per-realm permission grants its realm and nothing else')]
	#[Group('strata/access')]
	public function realmPermissionIsNarrow(): void
	{
		$account = $this->userWith([RestoreAccess::realmPermission(Realm::CONFIG)]);
		$access = $this->access();

		$this->assertTrue($access->forRealm($account, Realm::CONFIG)->isAllowed());
		$this->assertFalse($access->forRealm($account, Realm::ENTITY)->isAllowed());
	}

	#[Test]
	#[TestDox('administering strata grants every realm')]
	#[Group('strata/access')]
	public function administerGrantsEverything(): void
	{
		$account = $this->userWith([RestoreAccess::ADMINISTER]);
		$access = $this->access();

		foreach (Realm::cases() as $realm) {
			if (!$realm->isRestorable()) {
				continue;
			}

			$this->assertTrue(
				$access->forRealm($account, $realm)->isAllowed(),
				$realm->value . ' is granted',
			);
		}
	}

	#[Test]
	#[TestDox('the ephemeral realm is forbidden to everyone, including an administrator')]
	#[Group('strata/access')]
	public function ephemeralIsNeverRestorable(): void
	{
		$account = $this->userWith([RestoreAccess::ADMINISTER]);
		$result = $this->access()->forRealm($account, Realm::EPHEMERAL);

		$this->assertFalse($result->isAllowed());
		$this->assertStringContainsString('never written back', (string) $result->getReason());
	}

	#[Test]
	#[TestDox('an account with nothing granted may restore nothing')]
	#[Group('strata/access')]
	public function nothingGrantedAllowsNothing(): void
	{
		$account = $this->userWith([]);
		$access = $this->access();

		foreach (Realm::cases() as $realm) {
			$this->assertFalse($access->forRealm($account, $realm)->isAllowed());
		}
	}

	#endregion

	#region Whole Plans

	#[Test]
	#[TestDox('a plan spanning two realms needs both, not either')]
	#[Group('strata/access')]
	public function planNeedsEveryRealm(): void
	{
		$plan = new RestorePlan('a-target', 1, [
			'entity/node:1' => new ReplayResult('entity/node:1', true, ['title' => 'x'], 1, 1),
			'config/system.site' => new ReplayResult(
				'config/system.site',
				true,
				['name' => 'y'],
				1,
				1,
			),
		]);

		$partial = $this->userWith(['rollback strata content']);
		$full = $this->userWith(['rollback strata content', 'rollback strata config']);
		$access = $this->access();

		$this->assertSame([Realm::ENTITY, Realm::CONFIG], RestoreAccess::realmsIn($plan));
		$this->assertFalse(
			$access->forPlan($partial, $plan)->isAllowed(),
			'a partly permitted plan is refused rather than narrowed',
		);
		$this->assertTrue($access->forPlan($full, $plan)->isAllowed());
	}

	#[Test]
	#[TestDox('a plan naming no restorable realm is forbidden')]
	#[Group('strata/access')]
	public function emptyPlanIsForbidden(): void
	{
		$account = $this->userWith([RestoreAccess::ADMINISTER]);
		$plan = new RestorePlan('a-target');

		$this->assertFalse($this->access()->forPlan($account, $plan)->isAllowed());
	}

	#endregion

	#region Two-Person Approval

	#[Test]
	#[TestDox('approval is off by default, so a destructive plan needs no second account')]
	#[Group('strata/access')]
	public function approvalIsOffByDefault(): void
	{
		$this->assertFalse($this->access()->needsApproval($this->planFor(Realm::TABLE)));
	}

	#[Test]
	#[TestDox('with approval on, only the destructive realms need a second account')]
	#[Group('strata/access')]
	public function approvalAppliesToDestructiveRealms(): void
	{
		$access = $this->access(true);

		$this->assertTrue($access->needsApproval($this->planFor(Realm::TABLE)));
		$this->assertTrue($access->needsApproval($this->planFor(Realm::SCHEMA)));
		$this->assertFalse($access->needsApproval($this->planFor(Realm::ENTITY)));
		$this->assertFalse($access->needsApproval($this->planFor(Realm::CONFIG)));
	}

	#[Test]
	#[TestDox('the account that requested a restore cannot approve it')]
	#[Group('strata/access')]
	public function requesterCannotApprove(): void
	{
		$requester = $this->userWith(['rollback strata database']);
		$other = $this->userWith(['rollback strata database', 'view strata timeline']);
		$plan = $this->planFor(Realm::TABLE);
		$access = $this->access(true);

		$this->assertFalse(
			$access->toApprove($requester, (int) $requester->id(), $plan)->isAllowed(),
		);
		$this->assertTrue($access->toApprove($other, (int) $requester->id(), $plan)->isAllowed());
	}

	#[Test]
	#[TestDox('an unattended request cannot be approved, because there is no second person')]
	#[Group('strata/access')]
	public function unattendedRequestCannotBeApproved(): void
	{
		$account = $this->userWith(['rollback strata database']);
		$result = $this->access(true)->toApprove($account, null, $this->planFor(Realm::TABLE));

		$this->assertFalse($result->isAllowed());
		$this->assertStringContainsString(
			'nobody to be the second person',
			(string) $result->getReason(),
		);
	}

	#[Test]
	#[TestDox('approval never grants a realm the account does not hold')]
	#[Group('strata/access')]
	public function approvalDoesNotGrantAccess(): void
	{
		$requester = $this->userWith(['rollback strata database']);
		$outsider = $this->userWith(['view strata timeline']);

		$this->assertFalse(
			$this->access(true)
				->toApprove($outsider, (int) $requester->id(), $this->planFor(Realm::TABLE))
				->isAllowed(),
		);
	}

	#[Test]
	#[TestDox('a non-destructive plan is approved by anyone who may restore it')]
	#[Group('strata/access')]
	public function nonDestructivePlanNeedsNoSecondPerson(): void
	{
		$account = $this->userWith(['rollback strata content']);

		$this->assertTrue(
			$this->access(true)
				->toApprove($account, (int) $account->id(), $this->planFor(Realm::ENTITY))
				->isAllowed(),
		);
	}

	#endregion
}
