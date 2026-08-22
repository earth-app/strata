<?php

declare(strict_types=1);

namespace Drupal\Tests\strata;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Splits a PHPUnit test suite across parallel CI jobs.
 *
 * The Kernel suite boots a Drupal container per test and takes roughly forty minutes on one core,
 * which is the longest thing in this repository by an order of magnitude. Paratest brings that down
 * on one machine; this brings it down across machines, and the two compose.
 *
 * **The inventory comes from `phpunit --list-tests`, not from a hand-written list.** Data-provider
 * rows are therefore counted exactly and the plan maintains itself as tests are added, which is the
 * only way a shard split stays balanced in a suite that grows every commit.
 *
 * **The plan is a pure function of the inventory, so no shard coordinates with any other.** Classes
 * are bin-packed heaviest-first onto the lightest shard, with the class name breaking ties, so every
 * job independently derives the same assignment and the slowest shard lands within a few tests of
 * the average.
 *
 * Classes rather than methods are the unit, because a Drupal test class shares one `setUp()` and
 * splitting it would pay for the container boot twice. Paratest's `--functional` mode does the
 * method-level split inside a shard, where the processes share a warm opcode cache.
 */
final class ShardPlanner
{
	/**
	 * The namespace prefix every test class in this repository carries.
	 */
	private const TEST_NAMESPACE = 'Drupal\\Tests\\';

	/**
	 * Where this module's own tests live, relative to the repository root.
	 */
	private const OWN_ROOT = 'tests/src/';

	/**
	 * Constructs a planner.
	 *
	 * @param string $repoRoot
	 *   Absolute path to the repository root.
	 */
	public function __construct(private readonly string $repoRoot) {}

	#region Inventory

	/**
	 * Counts the tests each class in a suite contributes.
	 *
	 * @param string $suite
	 *   The testsuite name from `phpunit.xml.dist`.
	 *
	 * @return array<string, int>
	 *   Fully qualified class name keyed to how many tests it runs.
	 */
	public function inventory(string $suite): array
	{
		$command = sprintf(
			'%s --configuration %s --testsuite %s --list-tests',
			escapeshellarg($this->repoRoot . '/vendor/bin/phpunit'),
			escapeshellarg($this->repoRoot . '/phpunit.xml.dist'),
			escapeshellarg($suite),
		);

		$lines = [];
		$status = 0;
		exec($command . ' 2>/dev/null', $lines, $status);

		if ($status !== 0) {
			throw new RuntimeException(
				sprintf('phpunit --list-tests failed for suite %s (exit %d)', $suite, $status),
			);
		}

		return self::countByClass($lines);
	}

	/**
	 * Parses `phpunit --list-tests` output into per-class test counts.
	 *
	 * @param list<string> $lines
	 *   The command's output, one line each.
	 *
	 * @return array<string, int>
	 *   Class name keyed to test count.
	 */
	public static function countByClass(array $lines): array
	{
		$counts = [];

		foreach ($lines as $line) {
			if (!str_starts_with($line, ' - ')) {
				continue;
			}

			$separator = strpos($line, '::', 3);

			if ($separator === false) {
				continue;
			}

			$class = substr($line, 3, $separator - 3);
			$counts[$class] = ($counts[$class] ?? 0) + 1;
		}

		return $counts;
	}

	#endregion

	#region Packing

	/**
	 * Assigns classes to shards, heaviest first onto the lightest shard.
	 *
	 * @param array<string, int> $counts
	 *   Class name keyed to test count.
	 * @param int $total
	 *   How many shards to fill.
	 *
	 * @return list<list<string>>
	 *   One class list per shard, in shard order.
	 */
	public static function pack(array $counts, int $total): array
	{
		if ($total < 1) {
			throw new RuntimeException('shard count must be at least 1');
		}

		// heaviest first, with the class name breaking ties so every job packs identically
		uksort($counts, static fn(string $a, string $b) => [$counts[$b], $a] <=> [$counts[$a], $b]);

		$shards = array_fill(0, $total, []);
		$weights = array_fill(0, $total, 0);

		foreach ($counts as $class => $count) {
			$target = (int) array_keys($weights, min($weights))[0];
			$shards[$target][] = $class;
			$weights[$target] += $count;
		}

		return $shards;
	}

	#endregion

	#region Writing

	/**
	 * Resolves a test class to its repository-relative file path.
	 *
	 * Two roots exist: this module's own tests under `tests/src/`, and a submodule's under
	 * `modules/<name>/tests/src/`. The first segment after the test namespace is the extension name,
	 * which is what tells the two apart.
	 *
	 * @param string $class
	 *   The fully qualified class name.
	 *
	 * @return string
	 *   The path, relative to the repository root.
	 */
	public function fileFor(string $class): string
	{
		if (!str_starts_with($class, self::TEST_NAMESPACE)) {
			throw new RuntimeException(sprintf('unexpected test namespace: %s', $class));
		}

		$path = substr($class, strlen(self::TEST_NAMESPACE));
		$extension = strstr($path, '\\', true);

		if ($extension === false) {
			throw new RuntimeException(sprintf('cannot read an extension name from %s', $class));
		}

		$within = str_replace('\\', '/', substr($path, strlen($extension) + 1)) . '.php';
		$relative =
			$extension === 'strata'
				? self::OWN_ROOT . $within
				: sprintf('modules/%s/tests/src/%s', $extension, $within);

		if (!is_file($this->repoRoot . '/' . $relative)) {
			throw new RuntimeException(
				sprintf('cannot resolve %s to a file (tried %s)', $class, $relative),
			);
		}

		return $relative;
	}

	/**
	 * Clones `phpunit.xml.dist` with its suites replaced by an explicit file list.
	 *
	 * Written next to the dist config rather than into a temporary directory, so every relative path
	 * inside it - the bootstrap, the cache directory, the coverage source - keeps resolving.
	 *
	 * @param string $out
	 *   Where to write the configuration.
	 * @param string $suite
	 *   The suite name to give the generated testsuite.
	 * @param list<string> $files
	 *   Repository-relative test file paths.
	 */
	public function writeConfig(string $out, string $suite, array $files): void
	{
		$document = new DOMDocument();
		$document->preserveWhiteSpace = false;
		$document->formatOutput = true;

		if (!@$document->load($this->repoRoot . '/phpunit.xml.dist')) {
			throw new RuntimeException('could not read phpunit.xml.dist');
		}

		$root = $document->documentElement;

		if ($root === null) {
			throw new RuntimeException('phpunit.xml.dist has no root element');
		}

		foreach (iterator_to_array($root->getElementsByTagName('testsuites')) as $existing) {
			$root->removeChild($existing);
		}

		$suites = $document->createElement('testsuites');
		$element = $document->createElement('testsuite');
		$element->setAttribute('name', $suite);

		foreach ($files as $file) {
			$element->appendChild($document->createElement('file', $file));
		}

		$suites->appendChild($element);
		$source = $root->getElementsByTagName('source')->item(0);

		if ($source instanceof DOMElement) {
			$root->insertBefore($suites, $source);
		} else {
			$root->appendChild($suites);
		}

		if (@$document->save($out) === false) {
			throw new RuntimeException(sprintf('could not write %s', $out));
		}
	}

	#endregion
}
