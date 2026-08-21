<?php

declare(strict_types=1);

namespace Drupal\strata\Access;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Storage\StorageProviderManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The permissions that cannot be listed in YAML because they depend on the site.
 *
 * Two sets are generated. Per-realm rollback, because a site that has switched file capture off has
 * no use for a permission to roll files back, and offering one implies it does something. And
 * per-provider storage management, because a site with three buckets configured needs to be able to
 * let someone manage one of them without handing them all three.
 *
 * @see RestoreAccess
 */
final class StrataPermissions implements ContainerInjectionInterface
{
	use StringTranslationTrait;

	/**
	 * Constructs the generator.
	 *
	 * @param StorageProviderManager|null $providers
	 *   Providers contributed by submodules, or NULL when none are.
	 */
	public function __construct(private readonly ?StorageProviderManager $providers = null) {}

	/**
	 * {@inheritdoc}
	 */
	public static function create(ContainerInterface $container): self
	{
		return new self(
			$container->get(
				'strata.storage_providers',
				ContainerInterface::NULL_ON_INVALID_REFERENCE,
			),
		);
	}

	/**
	 * Every generated permission.
	 *
	 * @return array<string, array<string, mixed>>
	 *   Permission name keyed to its definition.
	 */
	public function permissions(): array
	{
		return array_merge($this->realmPermissions(), $this->providerPermissions());
	}

	/**
	 * One rollback permission per realm.
	 *
	 * Ephemeral state is skipped: it is never written back by a restore, so a permission to roll it
	 * back would describe an operation that does not exist.
	 *
	 * @return array<string, array<string, mixed>>
	 *   Permission name keyed to its definition.
	 */
	private function realmPermissions(): array
	{
		$permissions = [];

		foreach (Realm::cases() as $realm) {
			if (!$realm->isRestorable()) {
				continue;
			}

			$permissions[sprintf('rollback strata realm %s', $realm->value)] = [
				'title' => $this->t('Roll Back the @realm Realm', ['@realm' => $realm->label()]),
				'description' => $this->t(
					'Restore only @realm subjects, without granting any other realm.',
					['@realm' => $realm->label()],
				),
				'restrict access' => true,
			];
		}

		return $permissions;
	}

	/**
	 * One management permission per configured storage provider.
	 *
	 * @return array<string, array<string, mixed>>
	 *   Permission name keyed to its definition.
	 */
	private function providerPermissions(): array
	{
		if ($this->providers === null) {
			return [];
		}

		$permissions = [];

		foreach ($this->providers->ids() as $id) {
			$permissions[sprintf('manage strata provider %s', $id)] = [
				'title' => $this->t('Manage the @provider Provider', ['@provider' => $id]),
				'description' => $this->t('Change the credentials and bucket for @provider only.', [
					'@provider' => $id,
				]),
				'restrict access' => true,
			];
		}

		return $permissions;
	}
}
