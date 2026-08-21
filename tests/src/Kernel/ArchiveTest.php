<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\Component\FileSystem\FileSystem;
use Drupal\Core\Archiver\ArchiveTar;
use Drupal\strata\Archive\ArchiveImporter;
use Drupal\strata\Archive\ArchiveManifest;
use Drupal\strata\Engine;
use Drupal\strata\Site\SiteScopedProvider;
use Drupal\strata\Storage\Plugin\Strata\Storage\LocalStorage;
use Drupal\strata\Tree\RefStore;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Proves an archive is a complete, verified copy of a span of history.
 *
 * The round trip is the test that matters: an export followed by an import into a second store has
 * to leave that store holding the same bytes under the same keys, because that is what makes an
 * archive restorable somewhere other than where it was written.
 *
 * The tamper case covers the importer's one refusal. A content-addressed key whose bytes no longer
 * hash to it is rejected and recorded, and the rest of the archive still lands.
 */
class ArchiveTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'field', 'key', 'strata'];

	/**
	 * Where an import writes, so a round trip has somewhere to land.
	 */
	private string $targetRoot = '';

	/**
	 * Archives written outside the test site, removed when the test finishes.
	 *
	 * @var list<string>
	 */
	private array $realPaths = [];

	/**
	 * {@inheritdoc}
	 */
	protected function tearDown(): void
	{
		foreach ($this->realPaths as $path) {
			if (is_file($path)) {
				unlink($path);
			}
		}

		$this->realPaths = [];

		parent::tearDown();
	}

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->installEntitySchema('user');
		$this->installSchema('user', ['users_data']);

		$this->targetRoot = $this->siteDirectory . '/strata-import';

		$this->config('strata.settings')
			->set('enabled', true)
			->set('provider', 'local')
			->set('local_path', $this->storeRoot)
			->set('cipher.id', 'none')
			->save();

		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();
	}

	private function engine(): Engine
	{
		return $this->container->get('strata.engine');
	}

	private function target(): LocalStorage
	{
		return new LocalStorage($this->targetRoot);
	}

	/**
	 * The target store as an import would see it, namespaced by site the way the live one is.
	 *
	 * @return SiteScopedProvider
	 *   The provider.
	 */
	private function targetProvider(): SiteScopedProvider
	{
		return new SiteScopedProvider($this->target(), $this->container->get('strata.site'));
	}

	private function importer(): ArchiveImporter
	{
		return new ArchiveImporter($this->targetProvider(), new NullLogger());
	}

	private function archivePath(string $name = 'history.tar'): string
	{
		return $this->siteDirectory . '/' . $name;
	}

	/**
	 * A path on the real filesystem, for an archive gzip cannot write through a stream wrapper.
	 *
	 * @param string $name
	 *   The file name.
	 *
	 * @return string
	 *   The path.
	 */
	private function realPath(string $name): string
	{
		$path = FileSystem::getOsTemporaryDirectory() . '/strata-archive-test-' . $name;

		$this->realPaths[] = $path;

		return $path;
	}

	/**
	 * Saves a user and seals the window, so the store gains a real commit.
	 *
	 * @param string $name
	 *   The account name.
	 *
	 * @return string
	 *   The commit the flush sealed.
	 */
	private function commit(string $name): string
	{
		$user = User::create(['name' => $name, 'mail' => $name . '@example.com', 'status' => 1]);
		$user->save();

		$result = $this->engine()->flusher()->flush(true);

		$this->assertTrue($result->ran, 'the flush sealed a commit');

		return (string) $result->commit;
	}

	/**
	 * Object keys a store holds.
	 *
	 * @param LocalStorage $store
	 *   The store.
	 *
	 * @return list<string>
	 *   The keys, sorted.
	 */
	private function keysOf(LocalStorage $store): array
	{
		$keys = $store->list('', null, 1000)->keys();

		sort($keys);

		return $keys;
	}

	#region Export

	#[Test]
	#[TestDox('an export names the commits it walked and reports itself complete')]
	#[Group('strata/archive')]
	public function exportNamesTheCommits(): void
	{
		$first = $this->commit('archived-one');
		$second = $this->commit('archived-two');
		$path = $this->archivePath();

		$manifest = $this->engine()->archiveExporter()->export($path);

		$this->assertFileExists($path);
		$this->assertGreaterThan(0, filesize($path));
		$this->assertSame([$first, $second], $manifest->commits, 'oldest first');
		$this->assertSame($second, $manifest->head);
		$this->assertTrue($manifest->isComplete(), $manifest->summary());
		$this->assertNotEmpty($manifest->frames);
		$this->assertGreaterThan(0, $manifest->bytes);
		$this->assertSame(count($manifest->objects), $manifest->count());
	}

	#[Test]
	#[TestDox('an export with no history refuses rather than writing an empty archive')]
	#[Group('strata/archive')]
	public function exportOfNoHistoryIsRefused(): void
	{
		$path = $this->archivePath('empty.tar');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no history to export');

		$this->engine()->archiveExporter()->export($path);
	}

	#[Test]
	#[TestDox('a limit bounds the walk to the newest commits')]
	#[Group('strata/archive')]
	public function exportHonoursALimit(): void
	{
		$this->commit('limited-one');
		$second = $this->commit('limited-two');

		$manifest = $this->engine()->archiveExporter()->export($this->archivePath(), null, 1);

		$this->assertSame([$second], $manifest->commits);
	}

	#endregion

	#region Round trip

	#[Test]
	#[TestDox('an import into a second store reproduces every object byte for byte')]
	#[Group('strata/archive')]
	public function roundTripReproducesEveryObject(): void
	{
		$this->commit('round-one');
		$this->commit('round-two');

		$path = $this->archivePath();
		$exported = $this->engine()->archiveExporter()->export($path);
		$imported = $this->importer()->import($path, true);

		$this->assertTrue($imported->isComplete(), $imported->summary());
		$this->assertSame($exported->objects, $imported->objects);

		$source = $this->provider();
		$target = $this->target();

		$this->assertNotEmpty($this->keysOf($source));
		$this->assertSame(
			$this->keysOf($source),
			$this->keysOf($target),
			'the archive carried every object the store held',
		);

		foreach ($this->keysOf($source) as $key) {
			$this->assertSame($source->get($key), $target->get($key), $key);
		}
	}

	#[Test]
	#[TestDox('an import reports the archive head without moving the local ref')]
	#[Group('strata/archive')]
	public function importReportsTheHeadItCarried(): void
	{
		$head = $this->commit('head-bearing');
		$path = $this->archivePath();

		$this->engine()->archiveExporter()->export($path);

		$imported = $this->importer()->import($path, true);

		$this->assertSame($head, $imported->head);
		$this->assertSame(
			$this->engine()
				->provider()
				->get('refs/' . RefStore::MAIN),
			$this->targetProvider()->get('refs/' . RefStore::MAIN),
		);
	}

	#[Test]
	#[TestDox('a gzipped archive round trips the same way an uncompressed one does')]
	#[Group('strata/archive')]
	public function gzippedArchiveRoundTrips(): void
	{
		$this->commit('compressed');
		$path = $this->realPath('history.tar.gz');

		$exported = $this->engine()->archiveExporter()->export($path);
		$imported = $this->importer()->import($path, true);

		$this->assertFileExists($path);
		$this->assertTrue($imported->isComplete(), $imported->summary());
		$this->assertSame($exported->objects, $imported->objects);
	}

	#endregion

	#region Reading without writing

	#[Test]
	#[TestDox('inspect() reads the manifest and leaves the target store empty')]
	#[Group('strata/archive')]
	public function inspectWritesNothing(): void
	{
		$commit = $this->commit('inspected');
		$path = $this->archivePath();

		$this->engine()->archiveExporter()->export($path);

		$manifest = $this->importer()->inspect($path);

		$this->assertSame([$commit], $manifest->commits);
		$this->assertNotEmpty($manifest->objects);
		$this->assertSame([], $this->keysOf($this->target()), 'nothing was written');
	}

	#[Test]
	#[TestDox('a dry-run import reports what it would write and writes none of it')]
	#[Group('strata/archive')]
	public function dryRunImportWritesNothing(): void
	{
		$this->commit('dry-run');
		$path = $this->archivePath();

		$exported = $this->engine()->archiveExporter()->export($path);
		$planned = $this->importer()->import($path);

		$this->assertSame($exported->objects, $planned->objects);
		$this->assertGreaterThan(0, $planned->bytes);
		$this->assertTrue($planned->isComplete());
		$this->assertSame([], $this->keysOf($this->target()));
	}

	#endregion

	#region Refusals

	#[Test]
	#[TestDox('a tampered object is refused and every other object still imports')]
	#[Group('strata/archive')]
	public function tamperedObjectIsRefused(): void
	{
		$this->commit('tampered');
		$path = $this->archivePath();

		$exported = $this->engine()->archiveExporter()->export($path);
		$target = $this->pickCommitKey($exported);
		$rewritten = $this->archivePath('tampered.tar');

		$this->rewrite($path, $rewritten, $target, 'not the bytes this key names');

		$imported = $this->importer()->import($rewritten, true);

		$this->assertFalse($imported->isComplete());
		$this->assertCount(1, $imported->problems);
		$this->assertStringContainsString($target, $imported->problems[0]);
		$this->assertStringContainsString('was refused', $imported->problems[0]);
		$this->assertNotContains($target, $imported->objects);
		$this->assertFalse($this->target()->exists($target));

		foreach ($exported->objects as $key) {
			if ($key === $target) {
				continue;
			}

			$this->assertContains($key, $imported->objects, $key);
		}
	}

	#[Test]
	#[TestDox('a ref is written as given, since it is named by position rather than by digest')]
	#[Group('strata/archive')]
	public function aRewrittenRefIsNotVerified(): void
	{
		$this->commit('ref-rewritten');
		$path = $this->archivePath();

		$this->engine()->archiveExporter()->export($path);

		$rewritten = $this->archivePath('ref.tar');
		$this->rewrite($path, $rewritten, 'refs/' . RefStore::MAIN, str_repeat('f', 64));

		$imported = $this->importer()->import($rewritten, true);

		$this->assertTrue($imported->isComplete(), $imported->summary());
		$this->assertSame(
			str_repeat('f', 64),
			$this->targetProvider()->get('refs/' . RefStore::MAIN),
		);
	}

	#[Test]
	#[TestDox('an archive carrying no manifest is refused')]
	#[Group('strata/archive')]
	public function anArchiveWithNoManifestIsRefused(): void
	{
		$path = $this->archivePath('bare.tar');
		$archive = new ArchiveTar($path);

		$archive->addString('frames/aa/bb', 'some bytes');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('carries no ' . ArchiveManifest::FILE);

		$this->importer()->inspect($path);
	}

	#[Test]
	#[TestDox('an archive from a later format is refused rather than misread')]
	#[Group('strata/archive')]
	public function aLaterFormatIsRefused(): void
	{
		$path = $this->archivePath('future.tar');
		$archive = new ArchiveTar($path);

		$archive->addString(
			ArchiveManifest::FILE,
			(string) json_encode([
				'version' => ArchiveManifest::VERSION + 1,
				'site' => 'elsewhere',
				'objects' => [],
			]),
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('this release reads ' . ArchiveManifest::VERSION);

		$this->importer()->inspect($path);
	}

	#[Test]
	#[TestDox('an archive whose manifest is not json is refused with the reason')]
	#[Group('strata/archive')]
	public function anUnreadableManifestIsRefused(): void
	{
		$path = $this->archivePath('broken.tar');
		$archive = new ArchiveTar($path);

		$archive->addString(ArchiveManifest::FILE, '{not json at all');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('manifest could not be read');

		$this->importer()->inspect($path);
	}

	#[Test]
	#[TestDox('a file that is not an archive at all is refused')]
	#[Group('strata/archive')]
	public function aMissingFileIsRefused(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('cannot be read');

		$this->importer()->inspect($this->archivePath('never-written.tar'));
	}

	#[Test]
	#[TestDox('an object named in the manifest but absent from the tar is recorded')]
	#[Group('strata/archive')]
	public function anAbsentObjectIsRecorded(): void
	{
		$path = $this->archivePath('short.tar');
		$archive = new ArchiveTar($path);
		$manifest = new ArchiveManifest('elsewhere', 1_700_000_000, [], [], [], ['refs/main']);

		$archive->addString(ArchiveManifest::FILE, (string) json_encode($manifest));

		$imported = $this->importer()->import($path, true);

		$this->assertFalse($imported->isComplete());
		$this->assertStringContainsString('not in the archive', $imported->problems[0]);
		$this->assertSame([], $imported->objects);
	}

	#endregion

	/**
	 * A content-addressed commit key the archive carries.
	 *
	 * @param ArchiveManifest $manifest
	 *   The exported manifest.
	 *
	 * @return string
	 *   The key.
	 */
	private function pickCommitKey(ArchiveManifest $manifest): string
	{
		foreach ($manifest->objects as $key) {
			if (str_starts_with($key, 'commits/')) {
				return $key;
			}
		}

		$this->fail('the archive carried no commit object');
	}

	/**
	 * Copies an archive with one entry's bytes replaced.
	 *
	 * @param string $from
	 *   The archive to copy.
	 * @param string $to
	 *   Where to write the copy.
	 * @param string $key
	 *   The entry to replace.
	 * @param string $body
	 *   The bytes to put there instead.
	 */
	private function rewrite(string $from, string $to, string $key, string $body): void
	{
		$source = new ArchiveTar($from);
		$rewritten = new ArchiveTar($to);
		$replaced = false;

		/** @var list<array<string, mixed>> $entries */
		$entries = $source->listContent();

		foreach ($entries as $entry) {
			$name = (string) $entry['filename'];
			$bytes = $source->extractInString($name);

			if (!is_string($bytes)) {
				continue;
			}
			if ($name === $key) {
				$bytes = $body;
				$replaced = true;
			}

			$rewritten->addString($name, $bytes);
		}

		$this->assertTrue($replaced, sprintf('%s was in the archive to rewrite', $key));
	}
}
