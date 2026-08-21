<?php

declare(strict_types=1);

namespace Drupal\strata\File;

use Drupal\strata\Storage\Capabilities;

/**
 * Which storage class a file block is written to.
 *
 * Files are around 98% of the stored bytes on a site that captures them and get almost none of the
 * compression benefit, so they are the one realm where the storage class is worth choosing rather than
 * taking the default. A block is written once and read only during a restore or a verify, which is
 * exactly the access pattern infrequent-access tiers are priced for: cheaper per byte-month, dearer per
 * read, and a restore is rare enough for that to be the right side of the trade.
 *
 * **Everything else stays in the standard class.** Frames, segments, anchors and commits are read by
 * every verify pass and every replay; putting those in an infrequent tier would make the operations a
 * backup performs routinely into the expensive ones.
 *
 * A provider that does not offer classes gets no class, and the write proceeds. The class is a hint,
 * not a requirement, and a store that ignores it is still correct.
 *
 * @see MediaStore
 */
final class StorageClassPolicy
{
	/**
	 * The class blocks are written to when the provider supports one.
	 *
	 * The S3 and R2 spelling. A provider using a different name takes it from configuration.
	 */
	public const INFREQUENT = 'STANDARD_IA';

	/**
	 * The class everything a backup reads routinely stays in.
	 */
	public const STANDARD = 'STANDARD';

	/**
	 * Constructs a policy.
	 *
	 * @param bool $tierBlocks
	 *   Whether file blocks go to the infrequent class at all.
	 * @param string $blockClass
	 *   The class name to send for a block, so an endpoint spelling it differently still works.
	 */
	public function __construct(
		private readonly bool $tierBlocks = true,
		private readonly string $blockClass = self::INFREQUENT,
	) {}

	/**
	 * The class one block should be written to.
	 *
	 * @param Capabilities $capabilities
	 *   What the provider supports.
	 *
	 * @return string|null
	 *   The class name, or NULL to let the provider use its default, which is also what a provider
	 *   with no class support gets.
	 */
	public function forBlock(Capabilities $capabilities): ?string
	{
		if (!$this->tierBlocks || !$capabilities->storageClasses || $this->blockClass === '') {
			return null;
		}

		return $this->blockClass;
	}

	/**
	 * The put options a block is written with.
	 *
	 * @param Capabilities $capabilities
	 *   What the provider supports.
	 *
	 * @return array<string, string>
	 *   Options to hand the provider, empty when there is nothing to say.
	 */
	public function blockOptions(Capabilities $capabilities): array
	{
		$class = $this->forBlock($capabilities);

		return $class === null ? [] : ['storageClass' => $class];
	}

	/**
	 * Whether blocks are being tiered at all.
	 *
	 * @return bool
	 *   TRUE when tiering is on.
	 */
	public function tiersBlocks(): bool
	{
		return $this->tierBlocks;
	}

	/**
	 * A policy from the module's settings.
	 *
	 * @param bool|null $tierBlocks
	 *   Whether to tier, or NULL for the default.
	 * @param string|null $blockClass
	 *   The class name, or NULL for the default.
	 *
	 * @return StorageClassPolicy
	 *   The policy.
	 */
	public static function fromSettings(?bool $tierBlocks, ?string $blockClass = null): self
	{
		return new self(
			$tierBlocks ?? true,
			$blockClass === null || trim($blockClass) === '' ? self::INFREQUENT : trim($blockClass),
		);
	}
}
