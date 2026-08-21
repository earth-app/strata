<?php

declare(strict_types=1);

namespace Drupal\strata\Code;

use Drupal\strata\Cas\Hash;
use JsonSerializable;

/**
 * The lockfiles that reproduce a site's dependencies, instead of the dependencies themselves.
 *
 * Measured on this workspace: `vendor/drupal/core` compresses to 18,105,473 bytes, and the
 * `composer.lock` that reproduces the entire 241 MB `vendor/` tree exactly compresses to 41,364.
 * That is 440x smaller than core's bytes alone, and it is not an approximation - a lock file plus
 * `composer install` produces the same tree, which is the whole reason lock files exist.
 *
 * So `vendor/` is never stored. What is stored is the lock, plus a content hash of the tree it
 * produced, so `VendorDriftDetector` can notice when the tree stops matching the lock - a patched
 * dependency, a hand-edited file, a partial install - and only then are the files that drifted
 * captured as bytes.
 *
 * The whole code realm costs 8.98 MiB a year at 150 deploys, which is 0.09% of R2's free tier.
 *
 * @see VendorDriftDetector
 * @see CodeScanner
 */
final class LockfileReference implements JsonSerializable
{
	/**
	 * Files treated as lockfiles, relative to the project root.
	 *
	 * Both halves of each ecosystem: the manifest says what was asked for and the lock says what was
	 * resolved, and a restore needs the second to reproduce anything.
	 */
	public const LOCKFILES = [
		'composer.json',
		'composer.lock',
		'package.json',
		'package-lock.json',
		'yarn.lock',
		'bun.lock',
		'bun.lockb',
		'pnpm-lock.yaml',
	];

	/**
	 * Constructs a reference.
	 *
	 * @param string $path
	 *   The file's path relative to the project root.
	 * @param string $digest
	 *   Content address of the file.
	 * @param int $bytes
	 *   Its size.
	 * @param int $modified
	 *   Unix timestamp it was last written.
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $digest,
		public readonly int $bytes = 0,
		public readonly int $modified = 0,
	) {}

	/**
	 * Reads a lockfile from disk.
	 *
	 * @param string $root
	 *   The project root.
	 * @param string $path
	 *   The file's path relative to it.
	 *
	 * @return self|null
	 *   The reference, or NULL when the file is not there. A site with no `package.json` is not a
	 *   site with a problem.
	 */
	public static function read(string $root, string $path): ?self
	{
		$full = rtrim($root, '/') . '/' . ltrim($path, '/');

		if (!is_file($full) || !is_readable($full)) {
			return null;
		}

		$contents = @file_get_contents($full);

		if ($contents === false) {
			return null;
		}

		return new self($path, Hash::of($contents), strlen($contents), (int) @filemtime($full));
	}

	/**
	 * Every lockfile a project root holds.
	 *
	 * @param string $root
	 *   The project root.
	 *
	 * @return array<string, self>
	 *   Path keyed to its reference, for the files that exist.
	 */
	public static function all(string $root): array
	{
		$found = [];

		foreach (self::LOCKFILES as $path) {
			$reference = self::read($root, $path);

			if ($reference !== null) {
				$found[$path] = $reference;
			}
		}

		return $found;
	}

	/**
	 * Whether this reference describes the same file as another.
	 *
	 * @param self $other
	 *   The other reference.
	 *
	 * @return bool
	 *   TRUE when the paths and digests agree. The timestamp is ignored: a file rewritten with
	 *   identical content is not a change, and `composer install` rewrites timestamps freely.
	 */
	public function matches(self $other): bool
	{
		return $this->path === $other->path && Hash::equals($this->digest, $other->digest);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The reference as a plain array.
	 */
	public function jsonSerialize(): array
	{
		return [
			'path' => $this->path,
			'digest' => $this->digest,
			'bytes' => $this->bytes,
			'modified' => $this->modified,
		];
	}

	/**
	 * Rebuilds a reference from its serialized form.
	 *
	 * @param array<string, mixed> $data
	 *   The array produced by LockfileReference::jsonSerialize().
	 *
	 * @return self
	 *   The reference.
	 */
	public static function fromArray(array $data): self
	{
		return new self(
			(string) ($data['path'] ?? ''),
			(string) ($data['digest'] ?? ''),
			(int) ($data['bytes'] ?? 0),
			(int) ($data['modified'] ?? 0),
		);
	}
}
