<?php

declare(strict_types=1);

namespace Drupal\strata\Tree;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Storage\StorageProviderInterface;
use RuntimeException;
use Throwable;

/**
 * Writes the anchors a replay starts from.
 *
 * One object per anchor, content-addressed, chained to the anchor before it. A delta anchor names
 * only the subjects that changed since its parent; a full one names every subject and ends the chain
 * so a resolve is bounded.
 *
 * @see BaseManifest
 * @see BaseReader
 * @see BasePolicy
 */
final class BaseWriter
{
	/**
	 * Constructs a writer.
	 *
	 * @param StorageProviderInterface $provider
	 *   Where anchors are stored.
	 */
	public function __construct(private readonly StorageProviderInterface $provider) {}

	/**
	 * Writes one anchor.
	 *
	 * @param BaseManifest $manifest
	 *   The anchor.
	 *
	 * @return string
	 *   Its content address.
	 *
	 * @throws RuntimeException
	 *   When the anchor cannot be written.
	 */
	public function write(BaseManifest $manifest): string
	{
		$address = $manifest->address();
		$key = $this->key($address);

		try {
			// content-addressed, so an anchor already present is byte-identical and needs no write
			if ($this->provider->exists($key)) {
				return $address;
			}

			$this->provider->put($key, $manifest->encode());
		} catch (Throwable $error) {
			throw new RuntimeException(
				sprintf(
					'Could not write base anchor %s: %s',
					Hash::abbreviate($address),
					$error->getMessage(),
				),
				0,
				$error,
			);
		}

		return $address;
	}

	/**
	 * Writes a delta anchor over an existing one.
	 *
	 * @param array<string, array{frames: list<string>, size: int}|null> $changed
	 *   Subject path keyed to its new frame map, or NULL for a subject that was deleted.
	 * @param string|null $parent
	 *   Address of the previous anchor, or NULL when this is the first one, in which case it is
	 *   written as a full anchor because there is nothing behind it to change.
	 * @param int $microtime
	 *   Unix microseconds.
	 *
	 * @return string
	 *   The new anchor's address.
	 *
	 * @throws RuntimeException
	 *   When the anchor cannot be written.
	 */
	public function delta(array $changed, ?string $parent, int $microtime): string
	{
		if ($parent === null) {
			return $this->full($this->withoutDeletions($changed), $microtime);
		}

		return $this->write(new BaseManifest($changed, $parent, false, $microtime));
	}

	/**
	 * Writes a full anchor, which ends the chain.
	 *
	 * @param array<string, array{frames: list<string>, size: int}> $index
	 *   Every subject the site holds.
	 * @param int $microtime
	 *   Unix microseconds.
	 *
	 * @return string
	 *   The new anchor's address.
	 *
	 * @throws RuntimeException
	 *   When the anchor cannot be written.
	 */
	public function full(array $index, int $microtime): string
	{
		return $this->write(new BaseManifest($index, null, true, $microtime));
	}

	/**
	 * The object key an anchor lives at.
	 *
	 * @param string $address
	 *   The anchor's content address.
	 *
	 * @return string
	 *   The key.
	 */
	public function key(string $address): string
	{
		return Hash::key($address, BaseManifest::PREFIX);
	}

	/**
	 * Drops the deletions from a change set.
	 *
	 * A full anchor lists what exists. A subject recorded as deleted exists in a delta only to
	 * cancel its parent's entry, and a full anchor has no parent to cancel.
	 *
	 * @param array<string, array{frames: list<string>, size: int}|null> $changed
	 *   The change set.
	 *
	 * @return array<string, array{frames: list<string>, size: int}>
	 *   The surviving subjects.
	 */
	private function withoutDeletions(array $changed): array
	{
		$kept = [];

		foreach ($changed as $subject => $entry) {
			if ($entry !== null) {
				$kept[(string) $subject] = $entry;
			}
		}

		return $kept;
	}
}
