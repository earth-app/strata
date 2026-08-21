<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\File;

use Drupal\strata\Cas\Hash;
use Drupal\strata\File\BlockSplitter;
use Drupal\strata\File\FileMap;
use Drupal\strata\File\FileMapDiff;
use Drupal\strata\File\ShiftDetector;
use Drupal\strata\File\StorageClassPolicy;
use Drupal\strata\Health\Tripwire\FileShiftDetected;
use Drupal\strata\Storage\Capabilities;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(BlockSplitter::class)]
#[CoversClass(FileMap::class)]
#[CoversClass(FileMapDiff::class)]
#[CoversClass(ShiftDetector::class)]
#[CoversClass(StorageClassPolicy::class)]
#[CoversClass(FileShiftDetected::class)]
class FileRealmTest extends TestCase
{
	/**
	 * Block size used throughout, small enough to keep the fixtures cheap.
	 */
	private const BLOCK = 4_096;

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

	private function splitter(): BlockSplitter
	{
		return new BlockSplitter(self::BLOCK);
	}

	private function map(string $contents, string $path = 'sites/default/files/thing.bin'): FileMap
	{
		return $this->splitter()->mapString($path, $contents);
	}

	#region Splitting

	/**
	 * @return array<string, array{int}>
	 */
	public static function badSizeProvider(): array
	{
		return [
			'zero' => [0],
			'negative' => [-1],
			'below the floor' => [BlockSplitter::MIN_SIZE - 1],
			'above the ceiling' => [BlockSplitter::MAX_SIZE + 1],
		];
	}

	#[Test]
	#[TestDox('a block size of $_dataName is refused')]
	#[Group('strata/file')]
	#[DataProvider('badSizeProvider')]
	public function refusesBadBlockSize(int $size): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A block size must be between');

		new BlockSplitter($size);
	}

	#[Test]
	#[TestDox('a file splits into blocks of the configured size, with a short one at the end')]
	#[Group('strata/file')]
	public function fileSplitsIntoBlocks(): void
	{
		$contents = $this->content(self::BLOCK * 3 + 100);
		$map = $this->map($contents);

		$this->assertSame(4, $map->count());
		$this->assertSame(strlen($contents), $map->length);
		$this->assertSame(self::BLOCK, $map->blockSize);
		$this->assertSame(['offset' => 0, 'length' => self::BLOCK], $map->positionOf(0));
		$this->assertSame(['offset' => self::BLOCK * 3, 'length' => 100], $map->positionOf(3));
		$this->assertNull($map->positionOf(4));
	}

	#[Test]
	#[TestDox('an empty file has no blocks, which is not the same as having absent ones')]
	#[Group('strata/file')]
	public function emptyFileHasNoBlocks(): void
	{
		$map = $this->map('');

		$this->assertTrue($map->isEmpty());
		$this->assertSame(0, $map->count());
		$this->assertSame(0, $this->splitter()->count(0));
	}

	#[Test]
	#[TestDox('identical content produces identical blocks, so two copies of a file share them')]
	#[Group('strata/file')]
	public function identicalContentSharesBlocks(): void
	{
		$contents = $this->content(self::BLOCK * 4);
		$first = $this->map($contents, 'a.bin');
		$second = $this->map($contents, 'b.bin');

		$this->assertSame($first->blocks, $second->blocks);
		$this->assertNotSame($first->key(), $second->key(), 'the maps are per file');
	}

	#[Test]
	#[TestDox('a repeated run of content collapses to one distinct block')]
	#[Group('strata/file')]
	public function repeatedContentCollapses(): void
	{
		$map = $this->map(str_repeat("\0", self::BLOCK * 8));

		$this->assertSame(8, $map->count());
		$this->assertCount(1, $map->distinct(), 'a padded file stores one block');
		$this->assertSame(self::BLOCK, $map->storedCeiling());
	}

	#[Test]
	#[TestDox('a stream and a string split the same way')]
	#[Group('strata/file')]
	public function streamAndStringAgree(): void
	{
		$contents = $this->content(self::BLOCK * 2 + 7);
		$handle = fopen('php://memory', 'r+');

		$this->assertNotFalse($handle);

		fwrite($handle, $contents);
		rewind($handle);

		$hashes = [];

		foreach ($this->splitter()->split($handle) as $block) {
			$hashes[] = $block['hash'];
		}

		fclose($handle);

		$this->assertSame($this->map($contents)->blocks, $hashes);
	}

	#[Test]
	#[TestDox('splitting something that is not a stream is refused')]
	#[Group('strata/file')]
	public function refusesNonStream(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('needs an open stream');

		iterator_to_array($this->splitter()->split('not a stream'));
	}

	#endregion

	#region Maps

	#[Test]
	#[TestDox('a map round trips through its stored form')]
	#[Group('strata/file')]
	public function mapRoundTrips(): void
	{
		$map = $this->map($this->content(self::BLOCK * 2));
		$decoded = FileMap::decode($map->encode());

		$this->assertSame($map->path, $decoded->path);
		$this->assertSame($map->blocks, $decoded->blocks);
		$this->assertSame($map->length, $decoded->length);
		$this->assertSame($map->blockSize, $decoded->blockSize);
		$this->assertSame($map->address(), $decoded->address());
	}

	#[Test]
	#[TestDox('a map with the wrong number of blocks for its length is refused')]
	#[Group('strata/file')]
	public function inconsistentMapIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('blocks, not');

		new FileMap('a.bin', [Hash::of('one')], self::BLOCK * 4, self::BLOCK);
	}

	#[Test]
	#[TestDox('a map whose blocks are not digests is refused')]
	#[Group('strata/file')]
	public function nonDigestBlocksAreRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('valid digest');

		new FileMap('a.bin', ['not-a-digest'], 10, self::BLOCK);
	}

	#[Test]
	#[TestDox('a map with no path is refused, since a version has to belong to a file')]
	#[Group('strata/file')]
	public function pathlessMapIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('must name its file');

		new FileMap('', [], 0);
	}

	#[Test]
	#[TestDox('a payload that is not a map is refused rather than half read')]
	#[Group('strata/file')]
	public function foreignPayloadIsRefused(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Not a file map');

		FileMap::decode('{"v":99}');
	}

	#[Test]
	#[TestDox('the same blocks in a different order are a different version')]
	#[Group('strata/file')]
	public function orderIsPartOfTheIdentity(): void
	{
		$one = Hash::of('one');
		$two = Hash::of('two');

		$forward = new FileMap('a.bin', [$one, $two], self::BLOCK * 2, self::BLOCK);
		$reversed = new FileMap('a.bin', [$two, $one], self::BLOCK * 2, self::BLOCK);

		$this->assertNotSame($forward->address(), $reversed->address());
	}

	#endregion

	#region What It Costs

	#[Test]
	#[TestDox('an in-place edit stores only the blocks it touched')]
	#[Group('strata/file')]
	public function inPlaceEditStoresOnlyWhatChanged(): void
	{
		$original = $this->content(self::BLOCK * 64);
		$edited = substr_replace($original, $this->content(64, 9), self::BLOCK * 20, 64);

		$diff = FileMapDiff::between($this->map($original), $this->map($edited));

		$this->assertSame(strlen($original), strlen($edited));
		$this->assertCount(1, $diff->changedPositions, 'one block moved');
		$this->assertCount(1, $diff->newBlocks, 'one block to upload');
		$this->assertLessThan(
			0.03,
			$diff->uploadShare(),
			sprintf('%.4f of the file', $diff->uploadShare()),
		);
	}

	#[Test]
	#[TestDox('an insertion moves almost the whole file, which is what fixed blocks cannot follow')]
	#[Group('strata/file')]
	public function insertionMovesTheWholeFile(): void
	{
		$original = $this->content(self::BLOCK * 64);
		$shifted =
			substr($original, 0, self::BLOCK * 20) .
			$this->content(self::BLOCK, 9) .
			substr($original, self::BLOCK * 20);

		$diff = FileMapDiff::between($this->map($original), $this->map($shifted));

		$this->assertGreaterThan(
			0.5,
			$diff->changedRatio(),
			sprintf('%.4f of the positions moved', $diff->changedRatio()),
		);
	}

	#[Test]
	#[TestDox('an unchanged file costs nothing at all')]
	#[Group('strata/file')]
	public function unchangedFileCostsNothing(): void
	{
		$contents = $this->content(self::BLOCK * 8);
		$diff = FileMapDiff::between($this->map($contents), $this->map($contents));

		$this->assertTrue($diff->isUnchanged());
		$this->assertSame(0, $diff->uploadCeiling());
		$this->assertSame(0.0, $diff->changedRatio());
		$this->assertStringContainsString('unchanged', $diff->summary());
	}

	#[Test]
	#[TestDox('a first capture stores the whole file and says that is what it is')]
	#[Group('strata/file')]
	public function firstCaptureStoresEverything(): void
	{
		$diff = FileMapDiff::between(null, $this->map($this->content(self::BLOCK * 4)));

		$this->assertTrue($diff->isFirstCapture());
		$this->assertCount(4, $diff->newBlocks);
		$this->assertSame(1.0, $diff->changedRatio());
	}

	#[Test]
	#[TestDox('a truncated file reports the blocks that fell off the end')]
	#[Group('strata/file')]
	public function truncationReportsDroppedBlocks(): void
	{
		$original = $this->content(self::BLOCK * 8);
		$diff = FileMapDiff::between(
			$this->map($original),
			$this->map(substr($original, 0, self::BLOCK * 4)),
		);

		$this->assertCount(4, $diff->droppedBlocks);
		$this->assertSame([], $diff->newBlocks, 'nothing new to upload');
		$this->assertSame(0.0, $diff->changedRatio(), 'the blocks that remain did not move');
	}

	#[Test]
	#[
		TestDox(
			'comparing versions split at different block sizes is refused rather than reported wrong',
		),
	]
	#[Group('strata/file')]
	public function mismatchedBlockSizesAreRefused(): void
	{
		$contents = $this->content(self::BLOCK * 4);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('cannot be compared');

		FileMapDiff::between(
			$this->map($contents),
			(new BlockSplitter(self::BLOCK * 2))->mapString(
				'sites/default/files/thing.bin',
				$contents,
			),
		);
	}

	#endregion

	#region Shift Detection

	#[Test]
	#[TestDox('an in-place edit is not a shift')]
	#[Group('strata/file')]
	public function editIsNotAShift(): void
	{
		$original = $this->content(self::BLOCK * 64);
		$edited = substr_replace($original, $this->content(64, 9), self::BLOCK * 20, 64);
		$diff = FileMapDiff::between($this->map($original), $this->map($edited));

		$this->assertFalse((new ShiftDetector())->hasShifted($diff));
		$this->assertSame([], (new ShiftDetector())->options($diff));
	}

	#[Test]
	#[TestDox('an insertion is a shift, and the options name what each one costs')]
	#[Group('strata/file')]
	public function insertionIsAShift(): void
	{
		$original = $this->content(self::BLOCK * 64);
		$shifted = $this->content(self::BLOCK, 9) . $original;
		$diff = FileMapDiff::between($this->map($original), $this->map($shifted));
		$detector = new ShiftDetector();

		$this->assertTrue($detector->hasShifted($diff));

		$options = $detector->options($diff);

		$this->assertCount(3, $options);
		$this->assertStringContainsString('4.3 MB/s', implode(' ', $options));
	}

	#[Test]
	#[TestDox('a first capture is never a shift, since nothing moved')]
	#[Group('strata/file')]
	public function firstCaptureIsNotAShift(): void
	{
		$diff = FileMapDiff::between(null, $this->map($this->content(self::BLOCK * 64)));

		$this->assertFalse((new ShiftDetector())->hasShifted($diff));
	}

	#[Test]
	#[TestDox('a file too small to judge is never a shift, whatever its ratio')]
	#[Group('strata/file')]
	public function smallFileIsNeverAShift(): void
	{
		$diff = FileMapDiff::between(
			$this->map($this->content(self::BLOCK * 2)),
			$this->map($this->content(self::BLOCK * 2, 9)),
		);

		$this->assertSame(1.0, $diff->changedRatio());
		$this->assertFalse((new ShiftDetector())->hasShifted($diff));
	}

	#[Test]
	#[TestDox('a threshold outside a fraction is refused')]
	#[Group('strata/file')]
	public function badThresholdIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('fraction above zero');

		new ShiftDetector(1.5);
	}

	#[Test]
	#[TestDox('a configured threshold outside a fraction falls back to the measured default')]
	#[Group('strata/file')]
	public function badConfiguredThresholdFallsBack(): void
	{
		$this->assertSame(
			ShiftDetector::DEFAULT_THRESHOLD,
			ShiftDetector::fromSettings(0.0)->threshold(),
		);
		$this->assertSame(
			ShiftDetector::DEFAULT_THRESHOLD,
			ShiftDetector::fromSettings(null)->threshold(),
		);
		$this->assertSame(0.5, ShiftDetector::fromSettings(0.5)->threshold());
	}

	#[Test]
	#[TestDox('a shift raises a warning naming the ratio and what it costs per version')]
	#[Group('strata/file')]
	public function shiftRaisesAWarning(): void
	{
		$original = $this->content(self::BLOCK * 64);
		$shifted = $this->content(self::BLOCK, 9) . $original;
		$diff = FileMapDiff::between($this->map($original), $this->map($shifted));
		$detector = new ShiftDetector();

		$finding = (new FileShiftDetected())->check($detector->observe($diff));

		$this->assertNotNull($finding);
		$this->assertSame('file.shift_detected', $finding->code);
		$this->assertStringContainsString('shifting rather than being edited', $finding->context);
	}

	#[Test]
	#[TestDox('an edit raises nothing')]
	#[Group('strata/file')]
	public function editRaisesNothing(): void
	{
		$original = $this->content(self::BLOCK * 64);
		$edited = substr_replace($original, $this->content(64, 9), self::BLOCK * 20, 64);
		$diff = FileMapDiff::between($this->map($original), $this->map($edited));

		$this->assertNull((new FileShiftDetected())->check((new ShiftDetector())->observe($diff)));
	}

	#endregion

	#region Storage Class

	#[Test]
	#[TestDox('a block goes to the infrequent class when the provider offers one')]
	#[Group('strata/file')]
	public function blocksAreTiered(): void
	{
		$policy = new StorageClassPolicy();
		$supported = new Capabilities(storageClasses: true);

		$this->assertSame(StorageClassPolicy::INFREQUENT, $policy->forBlock($supported));
		$this->assertSame(
			['storageClass' => StorageClassPolicy::INFREQUENT],
			$policy->blockOptions($supported),
		);
	}

	#[Test]
	#[TestDox('a provider with no classes gets no class, and the write still happens')]
	#[Group('strata/file')]
	public function providerWithoutClassesGetsNone(): void
	{
		$policy = new StorageClassPolicy();
		$plain = new Capabilities(storageClasses: false);

		$this->assertNull($policy->forBlock($plain));
		$this->assertSame([], $policy->blockOptions($plain));
	}

	#[Test]
	#[TestDox('tiering can be switched off, and then nothing is tiered')]
	#[Group('strata/file')]
	public function tieringCanBeSwitchedOff(): void
	{
		$policy = StorageClassPolicy::fromSettings(false);

		$this->assertFalse($policy->tiersBlocks());
		$this->assertNull($policy->forBlock(new Capabilities(storageClasses: true)));
	}

	#[Test]
	#[TestDox('an endpoint spelling the class differently gets the name it uses')]
	#[Group('strata/file')]
	public function classNameIsConfigurable(): void
	{
		$policy = StorageClassPolicy::fromSettings(true, 'COLD');

		$this->assertSame('COLD', $policy->forBlock(new Capabilities(storageClasses: true)));
		$this->assertSame(
			StorageClassPolicy::INFREQUENT,
			StorageClassPolicy::fromSettings(true, '  ')->forBlock(
				new Capabilities(storageClasses: true),
			),
		);
	}

	#endregion

	#region Cost Reporting

	#[Test]
	#[
		TestDox(
			'the measured throughput is reported, so a first capture can be priced before it runs',
		),
	]
	#[Group('strata/file')]
	public function throughputIsReported(): void
	{
		$this->assertSame(645_000_000, BlockSplitter::MEASURED_THROUGHPUT);
		$this->assertEqualsWithDelta(
			0.4,
			BlockSplitter::secondsFor(256 * 1_048_576),
			0.05,
			'a 256 MiB file hashes in about 0.4 seconds',
		);
		$this->assertSame(0.0, BlockSplitter::secondsFor(-1));
	}

	#endregion
}
