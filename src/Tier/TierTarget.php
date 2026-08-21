<?php

declare(strict_types=1);

namespace Drupal\strata\Tier;

use Drupal\strata\Storage\Capabilities;
use Drupal\strata\Storage\ProviderStatStore;
use Drupal\strata\Storage\TierProviderFactoryInterface;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Which bucket one tier writes to.
 *
 * A tier is not a new kind of store. It is an existing provider pointed at a different location, so
 * everything a provider already reports - its capabilities, its reachability, its request counts -
 * keeps working unchanged. Only two things vary between tiers of the same provider in practice, and
 * both are here: the bucket or directory, and the storage class objects land in.
 *
 * The name is what a per-tier request count is filed under in ProviderStatStore, so it has to be
 * unique, short and safe in a database column. It is normalised the same way a site id is, for the
 * same reason.
 *
 * @see Tier
 * @see TierProviderFactoryInterface
 */
final class TierTarget implements JsonSerializable
{
	/**
	 * Longest name a target may carry.
	 *
	 * The ProviderStatStore provider column is 64 characters and the name is written into it, so a
	 * longer name would be truncated there and two tiers could collide on one row.
	 */
	public const MAX_NAME = 48;

	/**
	 * Constructs a target.
	 *
	 * @param string $name
	 *   Short unique token such as "near" or "year", used as the label and as the per-tier key in
	 *   ProviderStatStore.
	 * @param string $provider
	 *   Registered provider id the tier is built from, such as "local" or "s3".
	 * @param string $location
	 *   Bucket name for an object store, or directory for the local provider. An empty string uses
	 *   whatever that provider is already configured with, which is what a single-tier site wants.
	 * @param string $storageClass
	 *   Storage class objects in this tier are written with, or an empty string for the endpoint
	 *   default. Honoured only where Capabilities::$storageClasses is true; a provider that cannot
	 *   store one refuses the write rather than dropping it.
	 *
	 * @throws InvalidArgumentException
	 *   When the name is empty, longer than TierTarget::MAX_NAME, or normalises to nothing, or when
	 *   the provider id is empty.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $provider,
		public readonly string $location = '',
		public readonly string $storageClass = '',
	) {
		if (self::normalise($name) !== $name || $name === '') {
			throw new InvalidArgumentException(
				sprintf(
					'A tier name must be lowercase letters, digits, hyphens or underscores, got "%s"',
					$name,
				),
			);
		}
		if (strlen($name) > self::MAX_NAME) {
			throw new InvalidArgumentException(
				sprintf(
					'Tier name "%s" is %d characters; the maximum is %d',
					$name,
					strlen($name),
					self::MAX_NAME,
				),
			);
		}
		if (trim($provider) === '') {
			throw new InvalidArgumentException(
				sprintf('Tier "%s" names no storage provider', $name),
			);
		}
	}

	/**
	 * The provider and location together, as one comparable token.
	 *
	 * Two tiers pointing at the same bucket would make a move a no-op and a replica a claim about
	 * durability that is not true, so TierMap refuses a ladder where this repeats.
	 *
	 * @return string
	 *   Something such as "s3:strata-cold".
	 */
	public function address(): string
	{
		return $this->provider . ':' . $this->location;
	}

	/**
	 * Whether this target overrides where its provider writes.
	 *
	 * @return bool
	 *   TRUE when a location is set, so the provider has to be built for this tier rather than
	 *   shared with the site's single configured one.
	 */
	public function isRelocated(): bool
	{
		return $this->location !== '';
	}

	/**
	 * Builds a target from one settings row.
	 *
	 * @param array<string, mixed> $row
	 *   One entry of the `tiers.levels` setting.
	 * @param int $index
	 *   Position on the ladder, used to name a row that did not name itself.
	 *
	 * @return self
	 *   The target.
	 *
	 * @throws InvalidArgumentException
	 *   When the row names no provider.
	 */
	public static function fromSettings(array $row, int $index): self
	{
		$name = self::normalise((string) ($row['name'] ?? ''));

		return new self(
			$name === '' ? 'tier' . $index : $name,
			trim((string) ($row['provider'] ?? '')),
			trim((string) ($row['location'] ?? '')),
			trim((string) ($row['storage_class'] ?? '')),
		);
	}

	/**
	 * Reduces a configured name to something safe in a database column and a report.
	 *
	 * @param string $name
	 *   The configured name.
	 *
	 * @return string
	 *   Lowercase, with everything but letters, digits, hyphens and underscores removed.
	 */
	public static function normalise(string $name): string
	{
		return (string) preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($name)));
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, string>
	 *   The target as a plain array for a report table.
	 */
	public function jsonSerialize(): array
	{
		return [
			'name' => $this->name,
			'provider' => $this->provider,
			'location' => $this->location,
			'storageClass' => $this->storageClass,
		];
	}
}
