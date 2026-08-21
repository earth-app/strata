<?php

declare(strict_types=1);

namespace Drupal\strata\Tree;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Storage\StorageProviderInterface;
use RuntimeException;
use Throwable;

/**
 * Reads an anchor back, and follows the chain until the index is complete.
 *
 * Resolving walks from an anchor to the full one behind it, then applies the deltas forward so a
 * newer entry wins and a recorded deletion removes the subject. Applying them the other way round
 * would let an old value overwrite a new one, which is the kind of mistake that only shows up as a
 * restore quietly writing last week's field.
 *
 * The chain is bounded by the full-anchor policy, and a chain that reaches its limit without finding
 * a full anchor is reported rather than walked forever.
 *
 * @see BaseManifest
 * @see BaseWriter
 */
final class BaseReader
{
	/**
	 * Longest chain a resolve will follow.
	 *
	 * A bound, not a policy. `BasePolicy` decides how often a full anchor is written and keeps chains
	 * far shorter than this; reaching it means the chain is broken or a full anchor was pruned, and
	 * both are worth reporting rather than looping over.
	 */
	public const CHAIN_LIMIT = 4096;

	/**
	 * Anchors already read, keyed by address.
	 *
	 * @var array<string, BaseManifest>
	 */
	private array $cache = [];

	/**
	 * Constructs a reader.
	 *
	 * @param StorageProviderInterface $provider
	 *   Where anchors are stored.
	 */
	public function __construct(private readonly StorageProviderInterface $provider) {}

	/**
	 * Reads one anchor.
	 *
	 * @param string $address
	 *   Its content address.
	 *
	 * @return BaseManifest
	 *   The anchor.
	 *
	 * @throws RuntimeException
	 *   When the anchor is absent or unreadable.
	 */
	public function read(string $address): BaseManifest
	{
		if (isset($this->cache[$address])) {
			return $this->cache[$address];
		}

		try {
			$payload = $this->provider->get(Hash::key($address, BaseManifest::PREFIX));
		} catch (Throwable $error) {
			throw new RuntimeException(
				sprintf(
					'Base anchor %s could not be read: %s',
					Hash::abbreviate($address),
					$error->getMessage(),
				),
				0,
				$error,
			);
		}

		return $this->cache[$address] = BaseManifest::decode($payload);
	}

	/**
	 * Whether an anchor is present.
	 *
	 * @param string $address
	 *   Its content address.
	 *
	 * @return bool
	 *   TRUE when the object exists.
	 */
	public function exists(string $address): bool
	{
		return isset($this->cache[$address]) ||
			$this->provider->exists(Hash::key($address, BaseManifest::PREFIX));
	}

	/**
	 * The chain from an anchor back to the full one behind it, newest first.
	 *
	 * @param string $address
	 *   Address of the newest anchor.
	 *
	 * @return list<string>
	 *   Addresses, ending with the full anchor.
	 *
	 * @throws RuntimeException
	 *   When an anchor in the chain is missing, or the chain does not end.
	 */
	public function chain(string $address): array
	{
		$chain = [];
		$at = $address;

		for ($step = 0; $step < self::CHAIN_LIMIT; $step++) {
			$manifest = $this->read($at);
			$chain[] = $at;

			if ($manifest->full || $manifest->parent === null) {
				return $chain;
			}

			$at = $manifest->parent;
		}

		throw new RuntimeException(
			sprintf(
				'The anchor chain from %s passed %d links without reaching a full anchor',
				Hash::abbreviate($address),
				self::CHAIN_LIMIT,
			),
		);
	}

	/**
	 * How many anchors a resolve would read.
	 *
	 * @param string $address
	 *   Address of the newest anchor.
	 *
	 * @return int
	 *   The chain length, which is what the restore estimate shows as the cost of an anchor.
	 *
	 * @throws RuntimeException
	 *   When an anchor in the chain is missing.
	 */
	public function depth(string $address): int
	{
		return count($this->chain($address));
	}

	/**
	 * The complete index an anchor describes.
	 *
	 * @param string $address
	 *   Address of the anchor.
	 *
	 * @return array<string, array{frames: list<string>, size: int}>
	 *   Subject path keyed to the frames that reconstruct it and its decoded size.
	 *
	 * @throws RuntimeException
	 *   When an anchor in the chain is missing or unreadable.
	 */
	public function resolve(string $address): array
	{
		$index = [];

		// oldest first, so a newer entry replaces an older one and a deletion removes it
		foreach (array_reverse($this->chain($address)) as $link) {
			foreach ($this->read($link)->entries as $subject => $entry) {
				if ($entry === null) {
					unset($index[(string) $subject]);

					continue;
				}

				$index[(string) $subject] = $entry;
			}
		}

		return $index;
	}

	/**
	 * The subjects an anchor names, without their frame maps.
	 *
	 * @param string $address
	 *   Address of the anchor.
	 *
	 * @return list<string>
	 *   Subject paths.
	 *
	 * @throws RuntimeException
	 *   When an anchor in the chain is missing or unreadable.
	 */
	public function subjects(string $address): array
	{
		return array_keys($this->resolve($address));
	}

	/**
	 * Every frame address the chain references.
	 *
	 * A reachability walk needs the whole chain rather than the resolved index, because a frame named
	 * by an entry a later anchor replaced is still referenced by the anchor that named it, and an
	 * anchor is a restore target.
	 *
	 * @param string $address
	 *   Address of the newest anchor.
	 *
	 * @return list<string>
	 *   Frame addresses, deduplicated.
	 *
	 * @throws RuntimeException
	 *   When an anchor in the chain is missing or unreadable.
	 */
	public function frames(string $address): array
	{
		$frames = [];

		foreach ($this->chain($address) as $link) {
			foreach ($this->read($link)->frames() as $frame) {
				$frames[$frame] = true;
			}
		}

		return array_keys($frames);
	}

	/**
	 * Forgets what has been read.
	 *
	 * Called by a long-running command between batches, so a resolve over a large index does not hold
	 * every anchor it touched.
	 */
	public function flushCache(): void
	{
		$this->cache = [];
	}
}
