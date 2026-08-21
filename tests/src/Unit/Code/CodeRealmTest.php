<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Code;

use Drupal\strata\Code\CodeReport;
use Drupal\strata\Code\CodeScanner;
use Drupal\strata\Code\LockfileReference;
use Drupal\strata\Code\SettingsRedactor;
use Drupal\strata\Code\VendorDriftDetector;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\Tripwire\VendorDrift;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(SettingsRedactor::class)]
#[CoversClass(CodeScanner::class)]
#[CoversClass(LockfileReference::class)]
#[CoversClass(VendorDriftDetector::class)]
#[CoversClass(VendorDrift::class)]
#[CoversClass(CodeReport::class)]
class CodeRealmTest extends TestCase
{
	/**
	 * A throwaway project root.
	 */
	private string $root = '';

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->root = sys_get_temp_dir() . '/strata-code-' . bin2hex(random_bytes(6));
		mkdir($this->root, 0777, true);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function tearDown(): void
	{
		$this->remove($this->root);

		parent::tearDown();
	}

	private function remove(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}

		foreach (scandir($path) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$child = $path . '/' . $entry;

			// a symlink is unlinked, never followed, or a fixture loop would delete what it points at
			if (is_link($child) || !is_dir($child)) {
				@unlink($child);

				continue;
			}

			$this->remove($child);
		}

		@rmdir($path);
	}

	/**
	 * Writes a file under the root, creating its directories.
	 */
	private function write(string $relative, string $contents): void
	{
		$full = $this->root . '/' . $relative;
		$directory = dirname($full);

		if (!is_dir($directory)) {
			mkdir($directory, 0777, true);
		}

		file_put_contents($full, $contents);
	}

	#region Redaction

	/**
	 * @return array<string, array{string}>
	 */
	public static function secretProvider(): array
	{
		return [
			'a database password' => ["\$databases['default']['default']['password'] = 'hunter2';"],
			'a hash salt' => ["\$settings['hash_salt'] = 'abc123';"],
			'an api key' => ["\$settings['acme_api_key'] = 'live_xyz';"],
			'a secret key' => ["\$config['thing']['secret_key'] = 'sk_live';"],
			'a token' => ["\$settings['github_token'] = 'ghp_abc';"],
			'an aws secret' => ["\$settings['aws_secret_access_key'] = 'wJal';"],
		];
	}

	#[Test]
	#[TestDox('$_dataName is replaced with a marker rather than blanked')]
	#[Group('strata/code')]
	#[DataProvider('secretProvider')]
	public function secretsAreRedacted(string $line): void
	{
		$redacted = SettingsRedactor::redact($line);

		$this->assertTrue(SettingsRedactor::isSecret($line));
		$this->assertStringContainsString(SettingsRedactor::MARKER, $redacted);
		$this->assertStringEndsWith(';', $redacted);
		$this->assertSame(1, SettingsRedactor::count($line));
	}

	#[Test]
	#[TestDox('the marker is neither empty nor valid, so a restore fails loudly')]
	#[Group('strata/code')]
	public function markerIsNotUsable(): void
	{
		$this->assertNotSame('', SettingsRedactor::MARKER);
		$this->assertStringContainsString('REDACTED', SettingsRedactor::MARKER);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function keptProvider(): array
	{
		return [
			'a trusted host pattern' => [
				"\$settings['trusted_host_patterns'] = ['^example\\.com\$'];",
			],
			'a config sync directory' => [
				"\$settings['config_sync_directory'] = '../config/sync';",
			],
			'a username inside an array literal' => ["  'username' => 'dbuser',"],
			'a database name inside an array literal' => ["  'database' => 'drupal',"],
			'a comment mentioning a password' => ['// set the password here'],
			'a comparison rather than an assignment' => ["if (\$x == 'password') {"],
			'a plain database name' => [
				"\$databases['default']['default']['database'] = 'drupal';",
			],
			'a blank line' => [''],
		];
	}

	#[Test]
	#[TestDox('$_dataName is kept as it is')]
	#[Group('strata/code')]
	#[DataProvider('keptProvider')]
	public function nonSecretsAreKept(string $line): void
	{
		$this->assertFalse(SettingsRedactor::isSecret($line));
		$this->assertSame($line, SettingsRedactor::redact($line));
	}

	#[Test]
	#[TestDox('a secret inside an array literal is redacted, which is the form settings.php ships')]
	#[Group('strata/code')]
	public function aSecretInsideAnArrayLiteralIsRedacted(): void
	{
		$contents = implode("\n", [
			'<?php',
			"\$databases['default']['default'] = [",
			"  'password' => 'hunter2',",
			"  'username' => 'dbuser',",
			"  'database' => 'drupal',",
			'];',
		]);

		$redacted = SettingsRedactor::redact($contents);

		$this->assertStringNotContainsString('hunter2', $redacted);
		$this->assertStringContainsString(
			"'password' => '" . SettingsRedactor::MARKER . "',",
			$redacted,
			'the arrow and the trailing comma are kept, so the array still parses',
		);
		$this->assertStringContainsString("'username' => 'dbuser',", $redacted);
		$this->assertStringContainsString("'database' => 'drupal',", $redacted);
		$this->assertSame(1, SettingsRedactor::count($contents));
	}

	#[Test]
	#[TestDox('redaction keeps the rest of the file byte for byte, line endings included')]
	#[Group('strata/code')]
	public function redactionPreservesTheFile(): void
	{
		$contents = "<?php\r\n\$settings['hash_salt'] = 'secret';\r\n\$settings['keep'] = 1;\r\n";
		$redacted = SettingsRedactor::redact($contents);

		$this->assertStringContainsString("<?php\r\n", $redacted);
		$this->assertStringContainsString("\$settings['keep'] = 1;", $redacted);
		$this->assertStringNotContainsString('secret', $redacted);
		$this->assertSame(1, SettingsRedactor::count($contents));
	}

	#endregion

	#region Scanning

	#[Test]
	#[TestDox('a scan finds the site\'s own code and skips what a tool regenerates')]
	#[Group('strata/code')]
	public function scanFindsOwnCode(): void
	{
		$this->write('modules/custom/acme/acme.module', '<?php // acme');
		$this->write('modules/custom/acme/src/Thing.php', '<?php class Thing {}');
		$this->write('themes/custom/acme_theme/acme_theme.info.yml', 'name: Acme');
		$this->write('vendor/drupal/core/lib/Core.php', '<?php // not ours');
		$this->write('modules/custom/acme/node_modules/dep/index.js', 'nope');

		$found = [];

		foreach ((new CodeScanner($this->root))->scan() as $path => $file) {
			$found[$path] = $file['bytes'];
		}

		$this->assertArrayHasKey('modules/custom/acme/acme.module', $found);
		$this->assertArrayHasKey('modules/custom/acme/src/Thing.php', $found);
		$this->assertArrayHasKey('themes/custom/acme_theme/acme_theme.info.yml', $found);
		$this->assertArrayNotHasKey('vendor/drupal/core/lib/Core.php', $found);
		$this->assertArrayNotHasKey('modules/custom/acme/node_modules/dep/index.js', $found);
	}

	#[Test]
	#[TestDox('a scan redacts settings.php and reports how many secrets it replaced')]
	#[Group('strata/code')]
	public function scanRedactsSettings(): void
	{
		$this->write(
			'sites/default/settings.php',
			"<?php\n\$settings['hash_salt'] = 'secret';\n\$settings['keep'] = 1;\n",
		);

		foreach ((new CodeScanner($this->root))->scan() as $path => $file) {
			if ($path !== 'sites/default/settings.php') {
				continue;
			}

			$this->assertSame(1, $file['redacted']);
			$this->assertStringNotContainsString('secret', $file['contents']);
			$this->assertStringContainsString("\$settings['keep'] = 1;", $file['contents']);

			return;
		}

		$this->fail('settings.php was not scanned');
	}

	#[Test]
	#[TestDox('a file above the size ceiling is left to the file realm')]
	#[Group('strata/code')]
	public function oversizedFilesAreSkipped(): void
	{
		$this->write('modules/custom/acme/small.php', '<?php // small');
		$this->write(
			'modules/custom/acme/huge.php',
			'<?php ' . str_repeat('x', CodeScanner::MAX_BYTES + 1),
		);

		$found = array_keys(iterator_to_array((new CodeScanner($this->root))->scan()));

		$this->assertContains('modules/custom/acme/small.php', $found);
		$this->assertNotContains('modules/custom/acme/huge.php', $found);
	}

	#[Test]
	#[TestDox('an excluded directory is pruned rather than walked and discarded at the leaf')]
	#[Group('strata/code')]
	public function excludedDirectoriesArePruned(): void
	{
		$this->write('modules/custom/acme/acme.module', '<?php // acme');
		$this->write('modules/custom/acme/vendor/pkg/src/A.php', '<?php class A {}');

		// a loop inside the pruned directory: filtering at the leaf would descend into it forever
		if (!@symlink($this->root, $this->root . '/modules/custom/acme/vendor/pkg/loop')) {
			$this->markTestSkipped('the filesystem does not support symlinks');
		}

		$found = array_keys(iterator_to_array((new CodeScanner($this->root))->scan()));

		$this->assertSame(['modules/custom/acme/acme.module'], $found);
	}

	#[Test]
	#[TestDox('a symlink pointing back at an ancestor is walked once rather than forever')]
	#[Group('strata/code')]
	public function symlinkLoopsTerminate(): void
	{
		$this->write('modules/custom/acme/acme.module', '<?php // acme');

		if (!@symlink($this->root . '/modules', $this->root . '/modules/custom/acme/self')) {
			$this->markTestSkipped('the filesystem does not support symlinks');
		}

		$found = array_keys(iterator_to_array((new CodeScanner($this->root))->scan()));

		$this->assertSame(['modules/custom/acme/acme.module'], $found);
	}

	#[Test]
	#[TestDox('a symlinked module is followed, since a site\'s own code is often a checkout')]
	#[Group('strata/code')]
	public function symlinkedModulesAreFollowed(): void
	{
		$checkout = $this->root . '-checkout';

		mkdir($checkout . '/src', 0777, true);
		file_put_contents($checkout . '/acme.module', '<?php // acme');
		file_put_contents($checkout . '/src/Thing.php', '<?php class Thing {}');
		mkdir($this->root . '/modules/custom', 0777, true);

		if (!@symlink($checkout, $this->root . '/modules/custom/acme')) {
			$this->remove($checkout);
			$this->markTestSkipped('the filesystem does not support symlinks');
		}

		$found = array_keys(iterator_to_array((new CodeScanner($this->root))->scan()));

		$this->remove($checkout);

		$this->assertContains('modules/custom/acme/acme.module', $found);
		$this->assertContains('modules/custom/acme/src/Thing.php', $found);
	}

	#[Test]
	#[TestDox('a walk that reaches the file bound stops and says the realm is short')]
	#[Group('strata/code')]
	public function fileBoundIsReportedRatherThanSilent(): void
	{
		$this->write('modules/custom/acme/a.php', '<?php // a');
		$this->write('modules/custom/acme/b.php', '<?php // b');
		$this->write('modules/custom/acme/c.php', '<?php // c');

		$scanner = new CodeScanner($this->root, 2);
		$measured = $scanner->measure();

		$this->assertSame(2, $measured['files']);
		$this->assertTrue($measured['truncated']);
		$this->assertTrue($scanner->wasTruncated());

		$whole = new CodeScanner($this->root);

		$this->assertSame(3, $whole->measure()['files']);
		$this->assertFalse($whole->wasTruncated());
	}

	#[Test]
	#[TestDox('a bound below one is raised rather than yielding an empty code realm')]
	#[Group('strata/code')]
	public function boundBelowOneIsRaised(): void
	{
		$this->write('modules/custom/acme/a.php', '<?php // a');

		$scanner = new CodeScanner($this->root, 0);

		$this->assertSame(1, $scanner->maxFiles());
		$this->assertSame(1, $scanner->measure()['files']);
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function codePathProvider(): array
	{
		return [
			'a module file' => ['modules/custom/acme/acme.module', true],
			'a php class' => ['modules/custom/acme/src/Thing.php', true],
			'a yml file' => ['modules/custom/acme/acme.info.yml', true],
			'a twig template' => ['themes/custom/t/templates/page.html.twig', true],
			'an htaccess' => ['sites/default/.htaccess', true],
			'a composer lock' => ['composer.lock', true],
			'something under vendor' => ['vendor/pkg/src/A.php', false],
			'something under node_modules' => ['node_modules/pkg/index.js', false],
			'something under a build directory' => ['modules/custom/a/dist/bundle.js', false],
			'an uploaded file' => ['sites/default/files/image.png', false],
			'a binary asset' => ['modules/custom/acme/logo.png', false],
			'a font' => ['themes/custom/t/fonts/a.woff2', false],
		];
	}

	#[Test]
	#[TestDox('$_dataName is decided correctly')]
	#[Group('strata/code')]
	#[DataProvider('codePathProvider')]
	public function pathScope(string $path, bool $expected): void
	{
		$this->assertSame($expected, CodeScanner::isCode($path));
	}

	#[Test]
	#[TestDox('the digest changes when any owned file changes, and not otherwise')]
	#[Group('strata/code')]
	public function digestTracksContent(): void
	{
		$this->write('modules/custom/acme/acme.module', '<?php // one');
		$scanner = new CodeScanner($this->root);
		$first = $scanner->digest();

		$this->assertSame(
			$first,
			(new CodeScanner($this->root))->digest(),
			'stable when unchanged',
		);

		$this->write('modules/custom/acme/acme.module', '<?php // two');

		$this->assertNotSame($first, (new CodeScanner($this->root))->digest());
	}

	#[Test]
	#[TestDox('a measurement reports files, bytes and redactions without storing anything')]
	#[Group('strata/code')]
	public function measurement(): void
	{
		$this->write('modules/custom/acme/acme.module', '<?php // acme');
		$this->write('sites/default/settings.php', "<?php\n\$settings['hash_salt'] = 'x';\n");

		$measured = (new CodeScanner($this->root))->measure();

		$this->assertSame(2, $measured['files']);
		$this->assertGreaterThan(0, $measured['bytes']);
		$this->assertSame(1, $measured['redacted']);
	}

	#endregion

	#region Lockfiles

	#[Test]
	#[TestDox('a lockfile is referenced by digest rather than by its tree')]
	#[Group('strata/code')]
	public function lockfileReference(): void
	{
		$this->write('composer.lock', '{"packages":[]}');

		$reference = LockfileReference::read($this->root, 'composer.lock');

		$this->assertNotNull($reference);
		$this->assertSame('composer.lock', $reference->path);
		$this->assertSame(15, $reference->bytes);
		$this->assertNotSame('', $reference->digest);
	}

	#[Test]
	#[TestDox('a lockfile that is not there is absent rather than an error')]
	#[Group('strata/code')]
	public function missingLockfileIsAbsent(): void
	{
		$this->assertNull(LockfileReference::read($this->root, 'composer.lock'));
		$this->assertSame([], LockfileReference::all($this->root));
	}

	#[Test]
	#[TestDox('every lockfile a root holds is found')]
	#[Group('strata/code')]
	public function allLockfiles(): void
	{
		$this->write('composer.json', '{}');
		$this->write('composer.lock', '{}');
		$this->write('package.json', '{}');

		$found = LockfileReference::all($this->root);

		$this->assertArrayHasKey('composer.lock', $found);
		$this->assertArrayHasKey('package.json', $found);
		$this->assertArrayNotHasKey('yarn.lock', $found);
	}

	#[Test]
	#[TestDox('a rewritten lockfile with identical content is not a change')]
	#[Group('strata/code')]
	public function identicalContentIsNotAChange(): void
	{
		$this->write('composer.lock', '{"a":1}');
		$first = LockfileReference::read($this->root, 'composer.lock');

		touch($this->root . '/composer.lock', time() + 100);
		$second = LockfileReference::read($this->root, 'composer.lock');

		$this->assertNotNull($first);
		$this->assertNotNull($second);
		$this->assertTrue($first->matches($second), 'a touched file is not a changed file');
	}

	#[Test]
	#[TestDox('a lockfile reference round trips')]
	#[Group('strata/code')]
	public function referenceRoundTrips(): void
	{
		$this->write('composer.lock', '{"a":1}');
		$reference = LockfileReference::read($this->root, 'composer.lock');

		$this->assertNotNull($reference);

		$restored = LockfileReference::fromArray($reference->jsonSerialize());

		$this->assertSame($reference->path, $restored->path);
		$this->assertSame($reference->digest, $restored->digest);
		$this->assertSame($reference->bytes, $restored->bytes);
	}

	#endregion

	#region Vendor Drift

	#[Test]
	#[TestDox('a site with no composer tree has nothing to drift')]
	#[Group('strata/code')]
	public function noVendorTree(): void
	{
		$detector = new VendorDriftDetector($this->root);

		$this->assertFalse($detector->isApplicable());
		$this->assertSame('', $detector->fingerprint());

		$result = $detector->compare('anything', 'anything');

		$this->assertFalse($result['drifted']);
		$this->assertStringContainsString('no composer-managed vendor tree', $result['reason']);
	}

	#[Test]
	#[TestDox('an unchanged tree matches its fingerprint')]
	#[Group('strata/code')]
	public function unchangedTreeMatches(): void
	{
		$this->write('composer.lock', '{"a":1}');
		$this->write('vendor/pkg/src/A.php', '<?php class A {}');

		$detector = new VendorDriftDetector($this->root);
		$lock = LockfileReference::read($this->root, 'composer.lock');
		$fingerprint = $detector->fingerprint();

		$this->assertNotSame('', $fingerprint);
		$this->assertTrue($detector->isApplicable());

		$result = $detector->compare($fingerprint, (string) $lock?->digest);

		$this->assertFalse($result['drifted']);
		$this->assertFalse($result['lockChanged']);
	}

	#[Test]
	#[TestDox('a dependency patched in place with the lock unchanged is drift')]
	#[Group('strata/code')]
	public function patchedDependencyIsDrift(): void
	{
		$this->write('composer.lock', '{"a":1}');
		$this->write('vendor/pkg/src/A.php', '<?php class A {}');

		$detector = new VendorDriftDetector($this->root);
		$lock = LockfileReference::read($this->root, 'composer.lock');
		$fingerprint = $detector->fingerprint();
		$descriptions = $detector->descriptions();

		// the same length, so only a content hash can see this
		$this->write('vendor/pkg/src/A.php', '<?php class B {}');

		$result = $detector->compare($fingerprint, (string) $lock?->digest);

		$this->assertTrue($result['drifted']);
		$this->assertFalse($result['lockChanged']);
		$this->assertStringContainsString('patched in place', $result['reason']);
		$this->assertSame(['vendor/pkg/src/A.php'], $detector->drifted($descriptions));
	}

	#[Test]
	#[TestDox('a changed tree with a changed lock is a deploy, not drift')]
	#[Group('strata/code')]
	public function deployIsNotDrift(): void
	{
		$this->write('composer.lock', '{"a":1}');
		$this->write('vendor/pkg/src/A.php', '<?php class A {}');

		$detector = new VendorDriftDetector($this->root);
		$fingerprint = $detector->fingerprint();

		$this->write('composer.lock', '{"a":2}');
		$this->write('vendor/pkg/src/A.php', '<?php class C {}');

		$result = $detector->compare($fingerprint, 'the-old-lock-digest');

		$this->assertFalse($result['drifted']);
		$this->assertTrue($result['lockChanged']);
		$this->assertStringContainsString('expected to change with it', $result['reason']);
	}

	#[Test]
	#[TestDox('a vendor file that disappeared counts as drift')]
	#[Group('strata/code')]
	public function deletedVendorFileIsDrift(): void
	{
		$this->write('composer.lock', '{"a":1}');
		$this->write('vendor/pkg/src/A.php', '<?php class A {}');

		$detector = new VendorDriftDetector($this->root);
		$descriptions = $detector->descriptions();

		unlink($this->root . '/vendor/pkg/src/A.php');

		$this->assertContains('vendor/pkg/src/A.php', $detector->drifted($descriptions));
	}

	#[Test]
	#[TestDox('the fingerprint samples a bounded number of files, in a deterministic order')]
	#[Group('strata/code')]
	public function fingerprintIsBoundedAndStable(): void
	{
		$this->write('composer.lock', '{"a":1}');

		for ($i = 0; $i < 20; $i++) {
			$this->write(sprintf('vendor/pkg/src/File%02d.php', $i), '<?php // ' . $i);
		}

		$detector = new VendorDriftDetector($this->root, 5);

		$this->assertSame(5, $detector->sampleSize());
		$this->assertCount(5, $detector->descriptions());
		$this->assertSame($detector->fingerprint(), $detector->fingerprint());
		$this->assertSame(
			array_keys($detector->descriptions()),
			array_keys($detector->descriptions()),
		);
	}

	#[Test]
	#[TestDox('drift raises an error finding naming how many files differ')]
	#[Group('strata/code')]
	public function driftRaisesAFinding(): void
	{
		$finding = (new VendorDrift())->check([
			'vendor_drifted' => true,
			'drift_reason' => 'the tree changed while the lock did not',
			'drifted_files' => 3,
		]);

		$this->assertNotNull($finding);
		$this->assertSame('code.vendor_drift', $finding->code);
		$this->assertSame(Finding::ERROR, $finding->severity);
		$this->assertStringContainsString('3 files differ', $finding->context);
	}

	#[Test]
	#[TestDox('no drift raises nothing')]
	#[Group('strata/code')]
	public function noDriftIsQuiet(): void
	{
		$this->assertNull((new VendorDrift())->check(['vendor_drifted' => false]));
		$this->assertNull((new VendorDrift())->check([]));
	}

	#endregion

	#region Reports

	#[Test]
	#[TestDox('a skipped pass says why rather than reporting zeroes')]
	#[Group('strata/code')]
	public function skippedReport(): void
	{
		$report = CodeReport::skipped('the code and the vendor tree are unchanged');

		$this->assertFalse($report->ran);
		$this->assertTrue($report->isClean());
		$this->assertStringContainsString('skipped', $report->summary());
		$this->assertStringContainsString('unchanged', $report->summary());
	}

	#[Test]
	#[TestDox('a report names the redactions and the patched files it captured')]
	#[Group('strata/code')]
	public function fullReport(): void
	{
		$report = new CodeReport(true, 74, 3_010_560, 2, 3, 5, 'a dependency was patched');

		$this->assertTrue($report->ran);
		$this->assertTrue($report->foundDrift());
		$this->assertStringContainsString('74 code files', $report->summary());
		$this->assertStringContainsString('2.9 MiB', $report->summary());
		$this->assertStringContainsString('2 secrets redacted', $report->summary());
		$this->assertStringContainsString('5 patched vendor files', $report->summary());
	}

	#endregion
}
