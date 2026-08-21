<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Engine;
use Drupal\strata\File\FileMap;
use Drupal\strata\File\MediaStore;
use Drupal\strata\Journal\Realm;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

/**
 * Proves a file version costs the blocks it changed, and restores byte for byte.
 *
 * The two claims that make the file realm worth having, and the two that would be easiest to believe
 * without checking. A 2 MiB in-place edit on a 256 MiB file was measured at 0.81% of the file; the
 * shape of that is asserted here at a size a test can afford. And a reassembled file has to be
 * identical, because a video that is almost right is not a restored video.
 */
class FileCaptureTest extends StrataKernelTestBase
{
	/**
	 * Block size the fixtures are split at.
	 */
	private const BLOCK = 4_096;

	/**
	 * A throwaway directory the fixture files live in.
	 */
	private string $files = '';

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->files = $this->siteDirectory . '/strata-files';

		if (!is_dir($this->files)) {
			mkdir($this->files, 0777, true);
		}

		$this->config('strata.settings')
			->set('enabled', true)
			->set('provider', 'local')
			->set('local_path', $this->storeRoot)
			->set('cipher.id', 'none')
			->set('capture.file', true)
			->set('file.block_size', self::BLOCK)
			->save();

		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();
	}

	private function engine(): Engine
	{
		return $this->container->get('strata.engine');
	}

	private function store(): MediaStore
	{
		return $this->engine()->mediaStore();
	}

	/**
	 * Deterministic content, so a block boundary means the same thing on every run.
	 */
	private function content(int $length, int $seed = 1): string
	{
		$out = '';
		$state = $seed;

		while (strlen($out) < $length) {
			$state = ($state * 1103515245 + 12345) & 0x7fffffff;
			$out .= pack('N', $state);
		}

		return substr($out, 0, $length);
	}

	/**
	 * Writes a fixture file and returns its path.
	 */
	private function write(string $name, string $contents): string
	{
		$path = $this->files . '/' . $name;

		file_put_contents($path, $contents);

		return $path;
	}

	/**
	 * Object keys the store holds under a prefix.
	 *
	 * @return list<string>
	 *   The keys.
	 */
	private function keys(string $prefix): array
	{
		return $this->engine()->provider()->list($prefix, null, 5000)->keys();
	}

	#region Capturing

	#[Test]
	#[TestDox('a first capture stores every block and a map that names them')]
	#[Group('strata/file')]
	public function firstCaptureStoresTheFile(): void
	{
		$path = $this->write('video.bin', $this->content(self::BLOCK * 16));
		$report = $this->engine()->fileCapture()->capture($path);

		$this->assertTrue($report->ran, $report->skipped);
		$this->assertTrue($report->firstCapture);
		$this->assertSame(16, $report->blocks);
		$this->assertSame(16, $report->uploaded);
		$this->assertSame(0, $report->shared);
		$this->assertCount(16, $this->keys(MediaStore::PREFIX . '/'));
		$this->assertCount(1, $this->keys(FileMap::PREFIX . '/'));
	}

	#[Test]
	#[TestDox('an in-place edit uploads the blocks it touched and nothing else')]
	#[Group('strata/file')]
	public function inPlaceEditUploadsAlmostNothing(): void
	{
		$original = $this->content(self::BLOCK * 64);
		$path = $this->write('video.bin', $original);
		$capture = $this->engine()->fileCapture();

		$capture->capture($path);

		$blocksAfterFirst = count($this->keys(MediaStore::PREFIX . '/'));

		file_put_contents(
			$path,
			substr_replace($original, $this->content(64, 9), self::BLOCK * 20, 64),
		);

		$report = $capture->capture($path);

		$this->assertTrue($report->ran, $report->skipped);
		$this->assertFalse($report->firstCapture);
		$this->assertSame(1, $report->uploaded, 'one block changed');
		$this->assertSame(63, $report->shared);
		$this->assertLessThan(
			0.03,
			$report->uploadShare(),
			sprintf('%.4f of the file was uploaded', $report->uploadShare()),
		);
		$this->assertCount($blocksAfterFirst + 1, $this->keys(MediaStore::PREFIX . '/'));
	}

	#[Test]
	#[TestDox('an unchanged file is skipped by name rather than stored again')]
	#[Group('strata/file')]
	public function unchangedFileIsSkipped(): void
	{
		$path = $this->write('video.bin', $this->content(self::BLOCK * 8));
		$capture = $this->engine()->fileCapture();

		$capture->capture($path);
		$report = $capture->capture($path);

		$this->assertFalse($report->ran);
		$this->assertSame('the file is unchanged', $report->skipped);
	}

	#[Test]
	#[TestDox('two files with identical content share their blocks')]
	#[Group('strata/file')]
	public function identicalFilesShareBlocks(): void
	{
		$contents = $this->content(self::BLOCK * 8);
		$capture = $this->engine()->fileCapture();

		$capture->capture($this->write('one.bin', $contents));

		$blocks = count($this->keys(MediaStore::PREFIX . '/'));
		$report = $capture->capture($this->write('two.bin', $contents));

		$this->assertTrue($report->ran);
		$this->assertSame(0, $report->uploaded, 'every block was already there');
		$this->assertSame(8, $report->shared);
		$this->assertCount($blocks, $this->keys(MediaStore::PREFIX . '/'));
		$this->assertCount(2, $this->keys(FileMap::PREFIX . '/'), 'each file gets its own map');
	}

	#[Test]
	#[TestDox('the file realm switched off captures nothing and says so')]
	#[Group('strata/file')]
	public function realmCanBeSwitchedOff(): void
	{
		$this->config('strata.settings')->set('capture.file', false)->save();
		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();

		$path = $this->write('video.bin', $this->content(self::BLOCK * 4));
		$report = $this->engine()->fileCapture()->capture($path);

		$this->assertFalse($report->ran);
		$this->assertSame('the file realm is not captured', $report->skipped);
		$this->assertSame([], $this->keys(MediaStore::PREFIX . '/'));
	}

	#[Test]
	#[TestDox('a file that is not there is skipped rather than throwing')]
	#[Group('strata/file')]
	public function missingFileIsSkipped(): void
	{
		$report = $this->engine()
			->fileCapture()
			->capture($this->files . '/absent.bin');

		$this->assertFalse($report->ran);
		$this->assertSame('the file is not readable', $report->skipped);
	}

	#endregion

	#region The Journal

	#[Test]
	#[
		TestDox(
			'the journal carries the map, not the file, so a large upload stays out of the window',
		),
	]
	#[Group('strata/file')]
	public function journalCarriesTheMapNotTheBytes(): void
	{
		$path = $this->write('video.bin', $this->content(self::BLOCK * 64));

		$this->engine()->fileCapture()->capture($path);

		$entries = [];

		foreach ($this->container->get('strata.journal')->read(100) as $entry) {
			if ($entry['operation']->realm === Realm::FILE) {
				$entries[] = $entry;
			}
		}

		$this->assertCount(1, $entries);
		$this->assertSame($path, $entries[0]['operation']->subject);
		$this->assertLessThan(
			1024,
			strlen((string) $entries[0]['payload']),
			'the operation names the map rather than carrying a quarter of a megabyte',
		);
	}

	#[Test]
	#[TestDox('a first capture is a create and a later version is an update')]
	#[Group('strata/file')]
	public function verbsFollowTheVersion(): void
	{
		$path = $this->write('video.bin', $this->content(self::BLOCK * 8));
		$capture = $this->engine()->fileCapture();

		$capture->capture($path);
		file_put_contents($path, $this->content(self::BLOCK * 8, 9));
		$capture->capture($path);

		$verbs = [];

		foreach ($this->container->get('strata.journal')->read(100) as $entry) {
			if ($entry['operation']->realm === Realm::FILE) {
				$verbs[] = $entry['operation']->verb->value;
			}
		}

		$this->assertSame(['create', 'update'], $verbs);
	}

	#endregion

	#region Restoring

	#[Test]
	#[TestDox('a stored file reassembles byte for byte')]
	#[Group('strata/file')]
	public function fileReassemblesExactly(): void
	{
		$contents = $this->content(self::BLOCK * 16 + 137);
		$path = $this->write('video.bin', $contents);
		$stored = $this->store()->store($path);
		$destination = $this->files . '/restored.bin';

		$written = $this->store()->reassemble($stored['map'], $destination);

		$this->assertSame(strlen($contents), $written);
		$this->assertSame($contents, (string) file_get_contents($destination));
	}

	#[Test]
	#[TestDox('an older version still reassembles after a newer one is stored')]
	#[Group('strata/file')]
	public function olderVersionStillReassembles(): void
	{
		$original = $this->content(self::BLOCK * 16);
		$path = $this->write('video.bin', $original);
		$first = $this->store()->store($path);

		$edited = substr_replace($original, $this->content(64, 9), self::BLOCK * 4, 64);
		file_put_contents($path, $edited);
		$second = $this->store()->store($path);

		$this->store()->reassemble($first['map'], $this->files . '/v1.bin');
		$this->store()->reassemble($second['map'], $this->files . '/v2.bin');

		$this->assertSame($original, (string) file_get_contents($this->files . '/v1.bin'));
		$this->assertSame($edited, (string) file_get_contents($this->files . '/v2.bin'));
	}

	#[Test]
	#[TestDox('a missing block is reported rather than reassembled around')]
	#[Group('strata/file')]
	public function missingBlockIsReported(): void
	{
		$path = $this->write('video.bin', $this->content(self::BLOCK * 8));
		$stored = $this->store()->store($path);
		$store = $this->engine()->mediaStore();

		$this->engine()
			->provider()
			->delete([MediaStore::key($stored['map']->blocks[3])]);

		$this->assertSame([$stored['map']->blocks[3]], $store->missing($stored['map']));

		$destination = $this->files . '/broken.bin';

		try {
			$store->reassemble($stored['map'], $destination);
			$this->fail('a missing block should not reassemble');
		} catch (RuntimeException $error) {
			$this->assertStringContainsString('Could not reassemble', $error->getMessage());
		}

		$this->assertFileDoesNotExist(
			$destination,
			'a half-written file is removed rather than left looking like a restore',
		);
	}

	#[Test]
	#[TestDox('a block whose bytes were altered is refused rather than returned')]
	#[Group('strata/file')]
	public function alteredBlockIsRefused(): void
	{
		$path = $this->write('video.bin', $this->content(self::BLOCK * 4));
		$stored = $this->store()->store($path);
		$hash = $stored['map']->blocks[1];

		$this->engine()->provider()->put(MediaStore::key($hash), 'something else entirely');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('does not match its address');

		$this->engine()->mediaStore()->block($hash);
	}

	#[Test]
	#[TestDox('a map reads back from the store it was written to')]
	#[Group('strata/file')]
	public function mapReadsBack(): void
	{
		$path = $this->write('video.bin', $this->content(self::BLOCK * 4));
		$stored = $this->store()->store($path);
		$read = $this->engine()->mediaStore()->map($stored['key']);

		$this->assertSame($stored['map']->address(), $read->address());
		$this->assertSame($stored['map']->blocks, $read->blocks);
	}

	#endregion

	#region Shifts

	#[Test]
	#[TestDox('an insertion raises the shift finding rather than quietly storing the file again')]
	#[Group('strata/file')]
	public function insertionRaisesTheFinding(): void
	{
		$original = $this->content(self::BLOCK * 64);
		$path = $this->write('log.bin', $original);
		$capture = $this->engine()->fileCapture();

		$capture->capture($path);
		file_put_contents($path, $this->content(self::BLOCK, 9) . $original);

		$report = $capture->capture($path);

		$this->assertTrue($report->shifted);
		$this->assertGreaterThan(0.5, $report->changedRatio);
		$this->assertStringContainsString('shifting rather than being edited', $report->summary());

		$codes = array_map(
			static fn($finding): string => $finding->code,
			$this->container->get('strata.health_ledger')->open(),
		);

		$this->assertContains('file.shift_detected', $codes);
	}

	#[Test]
	#[TestDox('an edit raises nothing, so the finding means what it says')]
	#[Group('strata/file')]
	public function editRaisesNothing(): void
	{
		$original = $this->content(self::BLOCK * 64);
		$path = $this->write('video.bin', $original);
		$capture = $this->engine()->fileCapture();

		$capture->capture($path);
		file_put_contents(
			$path,
			substr_replace($original, $this->content(64, 9), self::BLOCK * 20, 64),
		);

		$report = $capture->capture($path);

		$this->assertFalse($report->shifted);
		$this->assertSame([], $this->container->get('strata.health_ledger')->open());
	}

	#endregion

	#region Measuring

	#[Test]
	#[TestDox('a measurement prices a capture without storing anything')]
	#[Group('strata/file')]
	public function measurementStoresNothing(): void
	{
		$path = $this->write('video.bin', $this->content(self::BLOCK * 16));
		$measured = $this->engine()->fileCapture()->measure($path);

		$this->assertSame(16, $measured['blocks']);
		$this->assertSame(16, $measured['new']);
		$this->assertFalse($measured['shifted']);
		$this->assertGreaterThan(0.0, $measured['seconds']);
		$this->assertSame([], $this->keys(MediaStore::PREFIX . '/'));
	}

	#endregion
}
