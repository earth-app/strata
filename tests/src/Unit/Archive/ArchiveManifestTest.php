<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Archive;

use Drupal\strata\Archive\ArchiveManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Proves the manifest answers whether an archive is self-contained, and survives a round trip.
 *
 * A manifest is written by one release and read by another, so the round trip is checked field by
 * field rather than by the summary line. A version above the one this release writes is carried
 * through unchanged: the refusal belongs to the importer, which is the code that would act on the
 * bytes.
 */
#[CoversClass(ArchiveManifest::class)]
class ArchiveManifestTest extends TestCase
{
	#region Completeness

	#[Test]
	#[TestDox('an archive with nothing recorded as missing is complete')]
	#[Group('strata/archive')]
	public function nothingMissingIsComplete(): void
	{
		$manifest = new ArchiveManifest('site-a', 1_700_000_000, ['c1'], ['f1'], [], ['o1']);

		$this->assertTrue($manifest->isComplete());
	}

	#[Test]
	#[TestDox('one problem makes the archive incomplete for the rest of its life')]
	#[Group('strata/archive')]
	public function oneProblemIsEnough(): void
	{
		$manifest = new ArchiveManifest(
			'site-a',
			1_700_000_000,
			['c1'],
			['f1'],
			[],
			['o1'],
			1_024,
			['frame bddd813c could not be read'],
		);

		$this->assertFalse($manifest->isComplete());
		$this->assertCount(1, $manifest->problems);
	}

	#[Test]
	#[TestDox('count() is the object count, not the commit count')]
	#[Group('strata/archive')]
	public function countIsTheObjectCount(): void
	{
		$manifest = new ArchiveManifest(
			'site-a',
			1_700_000_000,
			['c1', 'c2'],
			['f1'],
			[],
			['o1', 'o2', 'o3'],
		);

		$this->assertSame(3, $manifest->count());
		$this->assertSame(0, (new ArchiveManifest('site-a', 0))->count());
	}

	#endregion

	#region Summary

	#[Test]
	#[TestDox('summary() names every count and the size')]
	#[Group('strata/archive')]
	public function summaryNamesTheCounts(): void
	{
		$manifest = new ArchiveManifest(
			'site-a',
			1_700_000_000,
			['c1', 'c2'],
			['f1', 'f2', 'f3'],
			['d1'],
			['o1', 'o2', 'o3', 'o4'],
			2_048,
		);

		$this->assertSame(
			'2 commits, 3 frames, 1 dictionaries, 4 objects, 2.0 KiB',
			$manifest->summary(),
		);
	}

	#[Test]
	#[TestDox('summary() says incomplete and how many problems there were')]
	#[Group('strata/archive')]
	public function summarySaysIncomplete(): void
	{
		$manifest = new ArchiveManifest('site-a', 1_700_000_000, ['c1'], [], [], [], 512, [
			'one',
			'two',
		]);

		$this->assertStringContainsString('(incomplete: 2 problems)', $manifest->summary());
	}

	/**
	 * @return array<string, array{int, string}>
	 */
	public static function byteProvider(): array
	{
		return [
			'nothing' => [0, '0 B'],
			'under a kibibyte' => [512, '512 B'],
			'one kibibyte' => [1_024, '1.0 KiB'],
			'a mebibyte' => [4_194_304, '4.0 MiB'],
			'a gibibyte' => [1_073_741_824, '1.0 GiB'],
			'a tebibyte' => [1_099_511_627_776, '1.0 TiB'],
			'past the largest unit' => [1_125_899_906_842_624, '1024.0 TiB'],
		];
	}

	#[Test]
	#[TestDox('summary() renders $_dataName in the largest readable unit')]
	#[Group('strata/archive')]
	#[DataProvider('byteProvider')]
	public function summaryRendersBytesReadably(int $bytes, string $expected): void
	{
		$manifest = new ArchiveManifest('site-a', 0, [], [], [], [], $bytes);

		$this->assertStringEndsWith($expected, $manifest->summary());
	}

	#endregion

	#region Round trip

	/**
	 * @return array<string, array{ArchiveManifest}>
	 */
	public static function roundTripProvider(): array
	{
		return [
			'every field set' => [
				new ArchiveManifest(
					'site-a',
					1_700_000_000,
					['c1', 'c2'],
					['f1', 'f2'],
					['d1'],
					['commits/c1', 'frames/f1'],
					4_096,
					['one problem'],
					'c2',
					ArchiveManifest::VERSION,
				),
			],
			'nothing but a site and a stamp' => [new ArchiveManifest('site-a', 1_700_000_000)],
			'no head exported' => [
				new ArchiveManifest('site-a', 1_700_000_000, ['c1'], [], [], [], 0, [], null),
			],
			'a head and no commits' => [
				new ArchiveManifest('site-a', 1_700_000_000, [], [], [], [], 0, [], 'c9'),
			],
			'a later manifest version' => [
				new ArchiveManifest(
					'site-a',
					1_700_000_000,
					[],
					[],
					[],
					[],
					0,
					[],
					null,
					ArchiveManifest::VERSION + 5,
				),
			],
		];
	}

	#[Test]
	#[TestDox('fromArray(jsonSerialize()) is identity for $_dataName')]
	#[Group('strata/archive')]
	#[DataProvider('roundTripProvider')]
	public function roundTripsLosslessly(ArchiveManifest $manifest): void
	{
		$this->assertEquals($manifest, ArchiveManifest::fromArray($manifest->jsonSerialize()));
	}

	#[Test]
	#[TestDox('a manifest survives a trip through the json it is stored as')]
	#[Group('strata/archive')]
	public function roundTripsThroughJson(): void
	{
		$manifest = new ArchiveManifest(
			'site-a',
			1_700_000_000,
			['c1'],
			['f1'],
			['d1'],
			['commits/c1'],
			99,
			['broken'],
			'c1',
		);
		$decoded = json_decode((string) json_encode($manifest), true);

		$this->assertIsArray($decoded);
		$this->assertEquals($manifest, ArchiveManifest::fromArray($decoded));
	}

	#[Test]
	#[TestDox('jsonSerialize() emits the keys an importer reads')]
	#[Group('strata/archive')]
	public function jsonSerializeEmitsTheExpectedKeys(): void
	{
		$manifest = new ArchiveManifest('site-a', 1_700_000_000, ['c1'], [], [], [], 0, [], 'c1');

		$this->assertSame(
			[
				'version' => ArchiveManifest::VERSION,
				'site' => 'site-a',
				'created_at' => 1_700_000_000,
				'head' => 'c1',
				'commits' => ['c1'],
				'frames' => [],
				'dictionaries' => [],
				'objects' => [],
				'bytes' => 0,
				'problems' => [],
			],
			$manifest->jsonSerialize(),
		);
	}

	#endregion

	#region Reading a foreign manifest

	#[Test]
	#[TestDox('fromArray() of an empty array is an empty manifest at this version')]
	#[Group('strata/archive')]
	public function fromArrayOfNothing(): void
	{
		$manifest = ArchiveManifest::fromArray([]);

		$this->assertSame('', $manifest->site);
		$this->assertSame(0, $manifest->createdAt);
		$this->assertSame([], $manifest->commits);
		$this->assertSame([], $manifest->frames);
		$this->assertSame([], $manifest->dictionaries);
		$this->assertSame([], $manifest->objects);
		$this->assertSame(0, $manifest->bytes);
		$this->assertSame([], $manifest->problems);
		$this->assertNull($manifest->head);
		$this->assertSame(ArchiveManifest::VERSION, $manifest->version);
		$this->assertTrue($manifest->isComplete());
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function nonListProvider(): array
	{
		return [
			'a string' => ['c1'],
			'an integer' => [42],
			'a boolean' => [true],
			'null' => [null],
			'a float' => [1.5],
		];
	}

	#[Test]
	#[TestDox('a commits value that is $_dataName is read as no commits at all')]
	#[Group('strata/archive')]
	#[DataProvider('nonListProvider')]
	public function nonListValuesBecomeEmptyLists(mixed $value): void
	{
		$manifest = ArchiveManifest::fromArray([
			'commits' => $value,
			'frames' => $value,
			'dictionaries' => $value,
			'objects' => $value,
			'problems' => $value,
		]);

		$this->assertSame([], $manifest->commits);
		$this->assertSame([], $manifest->frames);
		$this->assertSame([], $manifest->dictionaries);
		$this->assertSame([], $manifest->objects);
		$this->assertSame([], $manifest->problems);
	}

	#[Test]
	#[TestDox('a keyed map is read as a list, since json decodes a sparse array that way')]
	#[Group('strata/archive')]
	public function keyedMapBecomesAList(): void
	{
		$manifest = ArchiveManifest::fromArray(['commits' => ['2' => 'c3', '0' => 'c1']]);

		$this->assertSame(['c3', 'c1'], $manifest->commits);
	}

	#[Test]
	#[TestDox('non-string entries are coerced rather than dropped')]
	#[Group('strata/archive')]
	public function entriesAreCoercedToStrings(): void
	{
		$manifest = ArchiveManifest::fromArray(['commits' => [1, 2.5, true]]);

		$this->assertSame(['1', '2.5', '1'], $manifest->commits);
	}

	#[Test]
	#[TestDox('a version above this release survives fromArray(), so the importer can refuse it')]
	#[Group('strata/archive')]
	public function aLaterVersionSurvives(): void
	{
		$manifest = ArchiveManifest::fromArray([
			'version' => ArchiveManifest::VERSION + 1,
			'site' => 'site-a',
		]);

		$this->assertSame(ArchiveManifest::VERSION + 1, $manifest->version);
		$this->assertGreaterThan(ArchiveManifest::VERSION, $manifest->version);
		$this->assertSame('site-a', $manifest->site);
	}

	#[Test]
	#[TestDox('a head of null stays null rather than becoming an empty string')]
	#[Group('strata/archive')]
	public function nullHeadStaysNull(): void
	{
		$this->assertNull(ArchiveManifest::fromArray(['head' => null])->head);
		$this->assertSame('', ArchiveManifest::fromArray(['head' => ''])->head);
	}

	#[Test]
	#[TestDox('the manifest file name is stable, since an archive is read by name')]
	#[Group('strata/archive')]
	public function manifestFileNameIsStable(): void
	{
		$this->assertSame('strata-manifest.json', ArchiveManifest::FILE);
		$this->assertSame(1, ArchiveManifest::VERSION);
	}

	#endregion
}
