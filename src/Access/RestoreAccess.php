<?php

declare(strict_types=1);

namespace Drupal\strata\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Restore\RestorePlan;

/**
 * Decides whether an account may run a particular restore.
 *
 * Rollback is not one permission. Restoring one node and replacing every table are different
 * operations with different blast radii, and a site that lets an editor undo their own mistake should
 * not thereby let them replace the users table. So the check is per REALM, and a plan spanning three
 * realms needs all three.
 *
 * **Config rollback is treated as privilege escalation.** Configuration includes `user.role.*` and
 * `filter.format.*`, so anyone who can restore config can restore a state where they had more
 * permissions than they do now. That is not a reason to forbid it - it is a legitimate recovery
 * operation - but it is a reason the permission is marked restricted and is never implied by content
 * rollback.
 *
 * **Two-person approval is opt-in and applies to the destructive scopes.** When it is on, the account
 * that requested a restore cannot be the account that approves it. That is checked here rather than in
 * a form, so a drush command and a REST call get the same rule.
 *
 * @see StrataPermissions
 */
final class RestoreAccess
{
	/**
	 * The permission that grants everything.
	 */
	public const ADMINISTER = 'administer strata';

	/**
	 * Realms whose restore is destructive enough to be eligible for two-person approval.
	 *
	 * A table replacement removes rows and a schema change alters structure; neither is undoable by
	 * anything short of the pre-restore snapshot.
	 */
	public const REQUIRES_APPROVAL = [Realm::TABLE, Realm::SCHEMA];

	/**
	 * Constructs a checker.
	 *
	 * @param bool $twoPersonApproval
	 *   Whether a destructive restore needs a second account to approve it.
	 */
	public function __construct(private readonly bool $twoPersonApproval = false) {}

	/**
	 * Whether an account may restore one realm.
	 *
	 * @param AccountInterface $account
	 *   The account.
	 * @param Realm $realm
	 *   The realm.
	 *
	 * @return AccessResultInterface
	 *   The result, cached per permission so a page rendering many restore links does not re-check.
	 */
	public function forRealm(AccountInterface $account, Realm $realm): AccessResultInterface
	{
		if (!$realm->isRestorable()) {
			return AccessResult::forbidden(
				sprintf('The %s realm is never written back by a restore.', $realm->value),
			);
		}

		return AccessResult::allowedIfHasPermissions(
			$account,
			[self::ADMINISTER, self::realmPermission($realm), self::legacyPermission($realm)],
			'OR',
		);
	}

	/**
	 * Whether an account may apply a whole plan.
	 *
	 * Every realm the plan touches has to be granted. A plan the account can only partly restore is
	 * refused rather than silently narrowed: restoring half of what was confirmed leaves the site in
	 * a state nobody chose.
	 *
	 * @param AccountInterface $account
	 *   The account.
	 * @param RestorePlan $plan
	 *   The plan.
	 *
	 * @return AccessResultInterface
	 *   The result.
	 */
	public function forPlan(AccountInterface $account, RestorePlan $plan): AccessResultInterface
	{
		$realms = self::realmsIn($plan);

		if ($realms === []) {
			return AccessResult::forbidden('The plan names no restorable realm.');
		}

		$result = AccessResult::allowed();

		foreach ($realms as $realm) {
			$result = $result->andIf($this->forRealm($account, $realm));
		}

		return $result;
	}

	/**
	 * Whether a plan needs a second account to approve it.
	 *
	 * @param RestorePlan $plan
	 *   The plan.
	 *
	 * @return bool
	 *   TRUE when approval is on and the plan touches a destructive realm.
	 */
	public function needsApproval(RestorePlan $plan): bool
	{
		if (!$this->twoPersonApproval) {
			return false;
		}

		foreach (self::realmsIn($plan) as $realm) {
			if (in_array($realm, self::REQUIRES_APPROVAL, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether one account may approve a restore another requested.
	 *
	 * @param AccountInterface $approver
	 *   The account approving.
	 * @param int|null $requestedBy
	 *   The account that requested it, or NULL for an unattended request.
	 * @param RestorePlan $plan
	 *   The plan.
	 *
	 * @return AccessResultInterface
	 *   The result.
	 */
	public function toApprove(
		AccountInterface $approver,
		?int $requestedBy,
		RestorePlan $plan,
	): AccessResultInterface {
		$permitted = $this->forPlan($approver, $plan);

		if (!$permitted->isAllowed()) {
			return $permitted;
		}
		if (!$this->needsApproval($plan)) {
			return AccessResult::allowed();
		}
		if ($requestedBy === null) {
			return AccessResult::forbidden(
				'An unattended request cannot be approved, because there is nobody to be the second ' .
					'person.',
			);
		}

		// the point of two-person approval is that the two are not the same person
		return $requestedBy === (int) $approver->id()
			? AccessResult::forbidden('The account that requested a restore cannot approve it.')
			: AccessResult::allowed();
	}

	/**
	 * The per-realm permission name.
	 *
	 * @param Realm $realm
	 *   The realm.
	 *
	 * @return string
	 *   The permission.
	 */
	public static function realmPermission(Realm $realm): string
	{
		return sprintf('rollback strata realm %s', $realm->value);
	}

	/**
	 * The broader permission a realm also answers to.
	 *
	 * The named permissions in `strata.permissions.yml` are what an administrator recognises -
	 * "roll back content", "roll back configuration" - and the generated per-realm ones are what a
	 * fine-grained site uses. Either grants the realm.
	 *
	 * @param Realm $realm
	 *   The realm.
	 *
	 * @return string
	 *   The permission.
	 */
	public static function legacyPermission(Realm $realm): string
	{
		return match ($realm) {
			Realm::ENTITY => 'rollback strata content',
			Realm::CONFIG => 'rollback strata config',
			Realm::FILE => 'rollback strata files',
			Realm::TABLE, Realm::SCHEMA => 'rollback strata database',
			default => 'rollback strata full site',
		};
	}

	/**
	 * The realms a plan's subjects fall into.
	 *
	 * @param RestorePlan $plan
	 *   The plan.
	 *
	 * @return list<Realm>
	 *   The realms, each once.
	 */
	public static function realmsIn(RestorePlan $plan): array
	{
		$realms = [];

		foreach (array_keys($plan->subjects) as $subject) {
			$at = strpos((string) $subject, '/');

			if ($at === false) {
				continue;
			}

			$realm = Realm::tryFrom(substr((string) $subject, 0, $at));

			if ($realm !== null && $realm->isRestorable()) {
				$realms[$realm->value] = $realm;
			}
		}

		return array_values($realms);
	}
}
