<?php

declare(strict_types=1);

namespace Drupal\strata\Code;

use Drupal\strata\Cas\Hash;
use Generator;
use SplFileInfo;

/**
 * Walks the code a site actually owns.
 *
 * A site's own modules, themes and profiles are what nobody else has a copy of. Measured on a real
 * module, 66 PHP files and 8 metadata files came to 3,010,560 bytes raw and 762,344 compressed, and
 * with delta coding across 150 deploys a year the whole realm costs 8.98 MiB annually. That is
 * cheap enough that not capturing it would be the odd choice.
 *
 * **`vendor/` is never walked.** It is reproducible from `composer.lock`, which is 440x smaller than
 * core's compressed bytes alone, so it is referenced rather than stored - see LockfileReference. The
 * same goes for `node_modules`, build output and anything else a tool regenerates.
 *
 * **`settings.php` is walked but redacted**, because it carries both the configuration a restore
 * needs and the credentials that must not leave the server.
 *
 * The walk is bounded by extension and by size. A module that ships a 200 MB fixture is not code,
 * and treating it as code would put it in the same realm as the thing whose cost model says 8.98 MiB
 * a year.
 *
 * @see SettingsRedactor
 * @see LockfileReference
 * @see VendorDriftDetector
 */
final class CodeScanner
{
	/**
	 * Directories under the project root that hold a site's own code.
	 */
	public const ROOTS = [
		'modules/custom',
		'modules',
		'themes/custom',
		'themes',
		'profiles/custom',
		'profiles',
		'sites/default',
	];

	/**
	 * Path fragments never walked, whatever they contain.
	 *
	 * Everything a tool regenerates, plus everything a version control system or an editor leaves
	 * behind. `vendor` and `node_modules` are the expensive ones and are why this list exists.
	 */
	public const EXCLUDED = [
		'/vendor/',
		'/node_modules/',
		'/.git/',
		'/.svn/',
		'/dist/',
		'/build/',
		'/coverage/',
		'/.cache/',
		'/.idea/',
		'/.vscode/',
		'/files/',
		'/php_storage/',
	];

	/**
	 * Extensions treated as code.
	 *
	 * A closed list rather than an exclusion list: a new kind of binary asset appearing in a module
	 * should default to being left out of the code realm, not swept into it.
	 */
	public const EXTENSIONS = [
		'php',
		'module',
		'install',
		'inc',
		'theme',
		'profile',
		'engine',
		'yml',
		'yaml',
		'twig',
		'js',
		'css',
		'json',
		'md',
		'txt',
		'htaccess',
		'sh',
		'lock',
	];

	/**
	 * Largest file walked as code.
	 *
	 * A code file above this is a fixture, a minified bundle or a mistake, and the file realm is
	 * where large content belongs.
	 */
	public const MAX_BYTES = 2_097_152;

	/**
	 * Most files one scan yields.
	 *
	 * The walk follows symlinks, because a site's own module is very often a symlink into a separate
	 * checkout, and a checkout carries directories this scanner has no business reading. Excluded
	 * directories are pruned and symlink loops are refused, so this bound is not what stops a runaway
	 * walk - it is what stops one pass appending an operation per file over a tree nobody expected to
	 * be there. A scan that reaches it says so rather than returning quietly short.
	 */
	public const MAX_FILES = 20_000;

	/**
	 * Files that are code but carry secrets, relative to the project root.
	 */
	public const REDACTED = ['sites/default/settings.php', 'sites/default/settings.local.php'];

	/**
	 * Whether the last scan stopped at the file bound.
	 */
	private bool $truncated = false;

	/**
	 * Most files this scanner yields.
	 */
	private readonly int $maxFiles;

	/**
	 * Constructs a scanner.
	 *
	 * @param string $root
	 *   The project root, which is the directory holding `composer.json` and the docroot.
	 * @param int $maxFiles
	 *   Most files one scan yields. Anything below one is raised to one, since a scanner that yields
	 *   nothing would report an empty code realm rather than a misconfigured bound.
	 */
	public function __construct(private readonly string $root, int $maxFiles = self::MAX_FILES)
	{
		$this->maxFiles = max(1, $maxFiles);
	}

	/**
	 * Every code file the site owns, with its content.
	 *
	 * A generator, so a scan over a few thousand files does not hold all of them in memory at once.
	 *
	 * @return Generator<string, array{digest: string, bytes: int, redacted: int, contents: string}>
	 *   Path relative to the root, keyed to its digest, size, how many assignments were redacted, and
	 *   the content as it will be stored.
	 */
	public function scan(): Generator
	{
		$seen = [];
		$yielded = 0;
		$this->truncated = false;

		foreach (self::ROOTS as $relative) {
			$directory = $this->path($relative);

			if (!is_dir($directory)) {
				continue;
			}

			foreach ($this->walk($directory) as $file) {
				$path = $this->relative($file->getPathname());

				if ($path === '' || isset($seen[$path])) {
					continue;
				}

				$seen[$path] = true;
				$read = $this->read($path, $file);

				if ($read === null) {
					continue;
				}
				if ($yielded >= $this->maxFiles) {
					$this->truncated = true;

					return;
				}

				$yielded++;

				yield $path => $read;
			}
		}
	}

	/**
	 * Whether the last scan stopped at the file bound rather than running out of files.
	 *
	 * Meaningful only after a scan has been consumed, since a generator does nothing until it is
	 * iterated.
	 *
	 * @return bool
	 *   TRUE when files were left unread.
	 */
	public function wasTruncated(): bool
	{
		return $this->truncated;
	}

	/**
	 * A digest over everything the scan would store.
	 *
	 * One number that changes when any of the site's own code changes, which is what a deploy
	 * detector compares. Computed from the per-file digests in a sorted order so it does not depend
	 * on the order the filesystem hands files back.
	 *
	 * @return string
	 *   The digest, or the digest of an empty string when the site owns no code.
	 */
	public function digest(): string
	{
		$digests = [];

		foreach ($this->scan() as $path => $file) {
			$digests[$path] = $file['digest'];
		}

		ksort($digests);

		// a path is bytes on posix, and a bare cast would digest every such tree to Hash::of('')
		return Hash::ofData($digests);
	}

	/**
	 * What the scan would cost, without storing anything.
	 *
	 * @return array{files: int, bytes: int, redacted: int, truncated: bool}
	 *   How many files, how many bytes, how many secret assignments were replaced, and whether the
	 *   walk stopped at the file bound.
	 */
	public function measure(): array
	{
		$files = 0;
		$bytes = 0;
		$redacted = 0;

		foreach ($this->scan() as $file) {
			$files++;
			$bytes += $file['bytes'];
			$redacted += $file['redacted'];
		}

		return [
			'files' => $files,
			'bytes' => $bytes,
			'redacted' => $redacted,
			'truncated' => $this->truncated,
		];
	}

	/**
	 * The lockfiles that stand in for `vendor/`.
	 *
	 * @return array<string, LockfileReference>
	 *   Path keyed to its reference.
	 */
	public function lockfiles(): array
	{
		return LockfileReference::all($this->root);
	}

	/**
	 * The project root this scanner walks.
	 *
	 * @return string
	 *   The root.
	 */
	public function root(): string
	{
		return $this->root;
	}

	/**
	 * The file bound this scanner walks under.
	 *
	 * @return int
	 *   Most files one scan yields.
	 */
	public function maxFiles(): int
	{
		return $this->maxFiles;
	}

	/**
	 * Whether a path is one of the files stored with its secrets replaced.
	 *
	 * @param string $path
	 *   Path relative to the root.
	 *
	 * @return bool
	 *   TRUE when the file is redacted before storage.
	 */
	public static function isRedacted(string $path): bool
	{
		return in_array($path, self::REDACTED, true);
	}

	/**
	 * Whether a path is walked at all.
	 *
	 * @param string $path
	 *   Path relative to the root.
	 *
	 * @return bool
	 *   TRUE when the path is in scope and has a code extension.
	 */
	public static function isCode(string $path): bool
	{
		foreach (self::EXCLUDED as $fragment) {
			if (str_contains('/' . trim($path, '/') . '/', $fragment)) {
				return false;
			}
		}

		$name = basename($path);
		$extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

		// a dotfile such as .htaccess has no extension by pathinfo's reckoning
		if ($extension === '' && str_starts_with($name, '.')) {
			$extension = strtolower(ltrim($name, '.'));
		}

		return in_array($extension, self::EXTENSIONS, true);
	}

	/**
	 * What identifies a directory for the loop guard.
	 *
	 * The real path, which is what makes two routes to the same directory one entry and is therefore
	 * what catches a symlink pointing at an ancestor. `realpath()` returns FALSE for a path on a
	 * stream wrapper, and a test root under `vfs://` is exactly that, so the path itself stands in.
	 * Nothing is lost: a stream wrapper has no symlinks to loop through.
	 *
	 * @param string $directory
	 *   The path as it was reached.
	 *
	 * @return string
	 *   The identity.
	 */
	private static function identity(string $directory): string
	{
		return realpath($directory) ?: $directory;
	}

	/**
	 * Whether a directory name is one the walk never descends into.
	 *
	 * @param string $name
	 *   The directory's own name, not its path.
	 *
	 * @return bool
	 *   TRUE when the directory is pruned.
	 */
	public static function isExcludedDirectory(string $name): bool
	{
		return in_array('/' . $name . '/', self::EXCLUDED, true);
	}

	/**
	 * Reads one file, redacting it when it holds secrets.
	 *
	 * @param string $path
	 *   Path relative to the root.
	 * @param SplFileInfo $file
	 *   The file.
	 *
	 * @return array{digest: string, bytes: int, redacted: int, contents: string}|null
	 *   What to store, or NULL when the file is out of scope, too large or unreadable.
	 */
	private function read(string $path, SplFileInfo $file): ?array
	{
		if (!self::isCode($path) || $file->getSize() > self::MAX_BYTES) {
			return null;
		}

		$contents = @file_get_contents($file->getPathname());

		if ($contents === false) {
			return null;
		}

		$redacted = 0;

		if (self::isRedacted($path)) {
			$redacted = SettingsRedactor::count($contents);
			$contents = SettingsRedactor::redact($contents);
		}

		return [
			'digest' => Hash::of($contents),
			'bytes' => strlen($contents),
			'redacted' => $redacted,
			'contents' => $contents,
		];
	}

	/**
	 * Walks a directory, pruning what is excluded before descending into it.
	 *
	 * Pruning at the directory rather than filtering at the file is the whole point. Deciding per
	 * file means `vendor/` is still walked - 241 MB and tens of thousands of entries - to discard
	 * every one of them, and the docblock claim that it is never walked would be false.
	 *
	 * Symlinks are followed, because a site's own module is very often a symlink into a separate
	 * checkout and refusing to follow one would silently leave that module out of the backup. A
	 * followed symlink can point at an ancestor, so a directory whose real path has already been
	 * entered is refused; without that the walk does not terminate.
	 *
	 * @param string $directory
	 *   Absolute path.
	 *
	 * @return Generator<int, SplFileInfo>
	 *   The files found.
	 */
	private function walk(string $directory): Generator
	{
		$entered = [self::identity($directory) => true];

		yield from $this->descend($directory, $entered);
	}

	/**
	 * Yields the files under one directory, recursing into the ones in scope.
	 *
	 * @param string $directory
	 *   Absolute path, keeping whatever symlink it was reached through so the path a file is stored
	 *   under stays the path the site sees.
	 * @param array<string, bool> $entered
	 *   Real paths already descended into, by reference so one loop guard covers the whole walk.
	 *
	 * @return Generator<int, SplFileInfo>
	 *   The files found.
	 */
	private function descend(string $directory, array &$entered): Generator
	{
		$entries = @scandir($directory);

		if ($entries === false) {
			return;
		}

		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$path = $directory . '/' . $entry;

			if (is_dir($path)) {
				if (self::isExcludedDirectory($entry)) {
					continue;
				}

				$identity = self::identity($path);

				if (isset($entered[$identity])) {
					continue;
				}

				$entered[$identity] = true;

				yield from $this->descend($path, $entered);

				continue;
			}

			if (is_file($path)) {
				yield new SplFileInfo($path);
			}
		}
	}

	/**
	 * An absolute path under the root.
	 *
	 * @param string $relative
	 *   Path relative to the root.
	 *
	 * @return string
	 *   The absolute path.
	 */
	private function path(string $relative): string
	{
		return rtrim($this->root, '/') . '/' . ltrim($relative, '/');
	}

	/**
	 * A path relative to the root.
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
