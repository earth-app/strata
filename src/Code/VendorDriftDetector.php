<?php

declare(strict_types=1);

namespace Drupal\strata\Code;

use Drupal\strata\Cas\Hash;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Notices when `vendor/` stops matching the lockfile that is supposed to reproduce it.
 *
 * Referencing `composer.lock` instead of storing 241 MB of dependencies rests on one assumption:
 * that running `composer install` against that lock reproduces the tree. That assumption is true right up
 * until someone patches a dependency in place, and then the backup is quietly missing the only copy
 * of a change the site depends on.
 *
 * So the lock is stored WITH a fingerprint of the tree it produced, and the fingerprint is checked.
 * When it moves without the lock moving, `code.vendor_drift` fires and the files that drifted are
 * captured as bytes - only those files, so the cost is the patch rather than the tree.
 *
 * **The fingerprint is a bounded sample, and that is stated rather than implied.** Hashing 241 MB on
 * every cron would cost more than the realm it protects, so a fixed number of files is sampled in a
 * deterministic order. A patch to a file outside the sample is not seen by the fingerprint; what
 * catches that is `composer.lock` itself changing, or an operator running a full check. The sample
 * size is the dial between cost and confidence.
 *
 * @see LockfileReference
 * @see CodeScanner
 */
final class VendorDriftDetector
{
	/**
	 * Code raised when the tree and the lock disagree.
	 */
	public const DRIFT = 'code.vendor_drift';

	/**
	 * How many files the fingerprint covers by default.
	 *
	 * A full walk of a Drupal `vendor/` is tens of thousands of files. At this sample a check costs
	 * a few hundred stat calls and reads, which fits inside a cron window.
	 */
	public const DEFAULT_SAMPLE = 400;

	/**
	 * Files whose content is hashed rather than just measured.
	 *
	 * A size and a timestamp catch a replaced file; only content catches a patch that kept the size
	 * the same, which is exactly what a one-line fix does.
	 */
	public const HASHED_EXTENSIONS = ['php', 'inc', 'module', 'yml', 'twig'];

	/**
	 * Constructs a detector.
	 *
	 * @param string $root
	 *   The project root holding both `composer.lock` and `vendor/`.
	 * @param int $sampleSize
	 *   How many files the fingerprint covers.
	 */
	public function __construct(
		private readonly string $root,
		private readonly int $sampleSize = self::DEFAULT_SAMPLE,
	) {}

	/**
	 * Whether there is a `vendor/` to check.
	 *
	 * @return bool
	 *   FALSE on a site installed without composer, where there is nothing to drift.
	 */
	public function isApplicable(): bool
	{
		return is_dir($this->vendor()) && is_file($this->lockPath());
	}

	/**
	 * A fingerprint of the current tree.
	 *
	 * @return string
	 *   The fingerprint, or an empty string when there is no tree to fingerprint.
	 */
	public function fingerprint(): string
	{
		if (!$this->isApplicable()) {
			return '';
		}

		$entries = [];

		foreach ($this->sample() as $path => $file) {
			$entries[$path] = $this->describe($path, $file);
		}

		ksort($entries);

		return Hash::of((string) json_encode($entries));
	}

	/**
	 * Compares the tree against a fingerprint taken earlier.
	 *
	 * @param string $expected
	 *   The stored fingerprint.
	 * @param string $lockDigest
	 *   The stored digest of `composer.lock`.
	 *
	 * @return array{drifted: bool, reason: string, lockChanged: bool, fingerprint: string}
	 *   Whether the tree drifted, why, whether the lock itself moved, and the current fingerprint.
	 */
	public function compare(string $expected, string $lockDigest): array
	{
		$fingerprint = $this->fingerprint();
		$lock = LockfileReference::read($this->root, 'composer.lock');
		$lockChanged = $lock !== null && !Hash::equals($lock->digest, $lockDigest);

		if (!$this->isApplicable()) {
			return [
				'drifted' => false,
				'reason' => 'there is no composer-managed vendor tree to check',
				'lockChanged' => false,
				'fingerprint' => '',
			];
		}
		if (Hash::equals($fingerprint, $expected)) {
			return [
				'drifted' => false,
				'reason' => 'the tree matches the fingerprint taken with the lock',
				'lockChanged' => $lockChanged,
				'fingerprint' => $fingerprint,
			];
		}

		// a moved lock explains a moved tree: that is a deploy, not a patch
		if ($lockChanged) {
			return [
				'drifted' => false,
				'reason' => 'the lock changed, so the tree was expected to change with it',
				'lockChanged' => true,
				'fingerprint' => $fingerprint,
			];
		}

		return [
			'drifted' => true,
			'reason' =>
				'the tree changed while the lock did not, so a dependency was patched in place',
			'lockChanged' => false,
			'fingerprint' => $fingerprint,
		];
	}

	/**
	 * The files whose content differs from what the fingerprint recorded.
	 *
	 * Called only after drift is detected, and only over the sample, so the answer is what to
	 * capture rather than a complete account of the tree.
	 *
	 * @param array<string, string> $expected
	 *   Path keyed to the description recorded earlier.
	 *
	 * @return list<string>
	 *   Paths relative to the project root.
	 */
	public function drifted(array $expected): array
	{
		$drifted = [];

		foreach ($this->sample() as $path => $file) {
			$current = $this->describe($path, $file);

			if (($expected[$path] ?? null) !== $current) {
				$drifted[] = $path;
			}
		}

		// a file the fingerprint knew about and the tree no longer has is drift too
		foreach (array_keys($expected) as $path) {
			if (!is_file($this->root . '/' . $path)) {
				$drifted[] = (string) $path;
			}
		}

		return array_values(array_unique($drifted));
	}

	/**
	 * The per-file descriptions the fingerprint is built from.
	 *
	 * Stored alongside the fingerprint so VendorDriftDetector::drifted() can say which files moved
	 * rather than only that something did.
	 *
	 * @return array<string, string>
	 *   Path keyed to its description.
	 */
	public function descriptions(): array
	{
		$entries = [];

		foreach ($this->sample() as $path => $file) {
			$entries[$path] = $this->describe($path, $file);
		}

		ksort($entries);

		return $entries;
	}

	/**
	 * How many files the fingerprint covers.
	 *
	 * @return int
	 *   The sample size.
	 */
	public function sampleSize(): int
	{
		return $this->sampleSize;
	}

	/**
	 * The sampled files, in a deterministic order.
	 *
	 * Sorted by path and truncated, so two runs on the same tree sample the same files and a
	 * difference means the tree moved rather than the iteration order did.
	 *
	 * @return array<string, SplFileInfo>
	 *   Path relative to the project root, keyed to the file.
	 */
	private function sample(): array
	{
		$paths = [];

		foreach ($this->walk() as $file) {
			$path = $this->relative($file->getPathname());

			if ($path !== '') {
				$paths[$path] = $file;
			}
		}

		ksort($paths);

		return array_slice($paths, 0, max(1, $this->sampleSize), true);
	}

	/**
	 * A short description of one file, for the fingerprint.
	 *
	 * @param string $path
	 *   Path relative to the project root.
	 * @param SplFileInfo $file
	 *   The file.
	 *
	 * @return string
	 *   Its size and, for source files, a digest of its content.
	 */
	private function describe(string $path, SplFileInfo $file): string
	{
		$extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

		if (!in_array($extension, self::HASHED_EXTENSIONS, true)) {
			return (string) $file->getSize();
		}

		$contents = @file_get_contents($file->getPathname());

		return $contents === false
			? (string) $file->getSize()
			: Hash::abbreviate(Hash::of($contents), 16);
	}

	/**
	 * Walks the vendor tree.
	 *
	 * @return Generator<int, SplFileInfo>
	 *   The files found.
	 */
	private function walk(): Generator
	{
		if (!is_dir($this->vendor())) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->vendor(), RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::LEAVES_ONLY,
		);

		foreach ($iterator as $file) {
			if ($file instanceof SplFileInfo && $file->isFile()) {
				yield $file;
			}
		}
	}

	/**
	 * The vendor directory.
	 *
	 * @return string
	 *   Its absolute path.
	 */
	private function vendor(): string
	{
		return rtrim($this->root, '/') . '/vendor';
	}

	/**
	 * The lockfile path.
	 *
	 * @return string
	 *   Its absolute path.
	 */
	private function lockPath(): string
	{
		return rtrim($this->root, '/') . '/composer.lock';
	}

	/**
	 * A path relative to the project root.
	 *
	 * @param string $absolute
	 *   The absolute path.
	 *
	 * @return string
	 *   The relative path, or an empty string when it is not under the root.
	 */
	private function relative(string $absolute): string
	{
		$root = rtrim($this->root, '/') . '/';

		return str_starts_with($absolute, $root) ? substr($absolute, strlen($root)) : '';
	}
}
