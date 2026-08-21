<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Cas;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Cas\PackIndex;
use Drupal\strata\Cas\Packer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(PackIndex::class)]
class PackIndexTest extends TestCase
{
	#region Round Trip

	#[Test]
	#[TestDox('a directory survives a round trip with every field intact')]
	#[Group('strata/cas')]
	public function roundTrip(): void
	{
		$parent = Hash::of('parent');
		$entries = [
			[
				'hash' => Hash::of('one'),
				'offset' => 0,
				'length' => 11,
				'raw' => 40,
				'codec' => 'zstd',
				'cipher' => 'xchacha20poly1305',
			],
			[
				'hash' => Hash::of('two'),
				'offset' => 11,
				'length' => 22,
				'raw' => 90,
				'codec' => 'gzip',
				'cipher' => 'none',
				'dictionary' => 'entity/3',
				'parent' => $parent,
				'depth' => 4,
			],
		];

		$decoded = PackIndex::decode('packs/x', 'payload' . PackIndex::encode($entries));

		$this->assertCount(2, $decoded);
		$this->assertSame(Hash::of('one'), $decoded[0]['hash']);
		$this->assertSame(0, $decoded[0]['offset']);
		$this->assertSame(11, $decoded[0]['length']);
		$this->assertSame(40, $decoded[0]['raw']);
		$this->assertSame('zstd', $decoded[0]['codec']);
		$this->assertSame('xchacha20poly1305', $decoded[0]['cipher']);
		$this->assertNull($decoded[0]['dictionary']);
		$this->assertNull($decoded[0]['parent']);
		$this->assertSame(0, $decoded[0]['depth']);

		$this->assertSame('entity/3', $decoded[1]['dictionary']);
		$this->assertSame($parent, $decoded[1]['parent']);
		$this->assertSame(4, $decoded[1]['depth']);
	}

	#[Test]
	#[TestDox('an empty directory encodes and decodes to nothing')]
	#[Group('strata/cas')]
	public function emptyDirectory(): void
	{
		$this->assertSame([], PackIndex::decode('packs/x', PackIndex::encode([])));
	}

	#[Test]
	#[TestDox('the same entries always produce the same bytes, so packs deduplicate')]
	#[Group('strata/cas')]
	public function encodingIsDeterministic(): void
	{
		$entries = [
			[
				'hash' => Hash::of('one'),
				'offset' => 0,
				'length' => 3,
				'raw' => 3,
				'codec' => 'none',
				'cipher' => 'none',
			],
		];

		$this->assertSame(PackIndex::encode($entries), PackIndex::encode($entries));
	}

	#[Test]
	#[TestDox('an entry with no valid content address is refused')]
	#[Group('strata/cas')]
	public function refusesEntryWithoutHash(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('valid content address');

		PackIndex::encode([['hash' => 'not-a-digest', 'offset' => 0, 'length' => 1]]);
	}

	#endregion

	#region Tail

	#[Test]
	#[TestDox('the tail is a fixed width, so a ranged read can find the directory')]
	#[Group('strata/cas')]
	public function tailIsFixedWidth(): void
	{
		$encoded = PackIndex::encode([]);

		$this->assertSame(
			strlen($encoded) - PackIndex::TAIL_BYTES,
			PackIndex::trailerLength('packs/x', substr($encoded, -PackIndex::TAIL_BYTES)),
		);
	}

	#[Test]
	#[TestDox('a tail of the wrong size is refused')]
	#[Group('strata/cas')]
	public function refusesShortTail(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('too short to carry a frame directory');

		PackIndex::trailerLength('packs/x', 'short');
	}

	#[Test]
	#[TestDox('a pack from another format is named rather than parsed')]
	#[Group('strata/cas')]
	public function refusesForeignMagic(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('STRATA-PAK-1');

		PackIndex::trailerLength('packs/x', 'STRATA-PAK-90000000000');
	}

	#[Test]
	#[TestDox('a tail whose length is not a number is refused')]
	#[Group('strata/cas')]
	public function refusesNonNumericLength(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('does not say how long');

		PackIndex::trailerLength('packs/x', PackIndex::MAGIC . 'abcdefghij');
	}

	#endregion

	#region Corruption

	#[Test]
	#[TestDox('a directory longer than the object it sits in is refused')]
	#[Group('strata/cas')]
	public function refusesOversizedDirectory(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('too short to hold');

		PackIndex::decode('packs/x', PackIndex::MAGIC . '0000009999');
	}

	#[Test]
	#[TestDox('a directory that is not json is refused rather than read as empty')]
	#[Group('strata/cas')]
	public function refusesUnparseableDirectory(): void
	{
		$body = 'not json at all';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('does not parse');

		PackIndex::decode('packs/x', $body . PackIndex::MAGIC . sprintf('%010d', strlen($body)));
	}

	#[Test]
	#[TestDox('a directory from a later format version is named rather than guessed at')]
	#[Group('strata/cas')]
	public function refusesLaterVersion(): void
	{
		$body = (string) json_encode(['v' => 2, 'e' => []]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('version 2');

		PackIndex::decode('packs/x', $body . PackIndex::MAGIC . sprintf('%010d', strlen($body)));
	}

	#endregion

	#region Through The Packer

	#[Test]
	#[TestDox('a pack written by the packer describes its own frames')]
	#[Group('strata/cas')]
	public function packCarriesItsOwnDirectory(): void
	{
		$packer = new Packer();
		$frames = ['first frame', 'second frame is longer', 'third'];

		foreach ($frames as $frame) {
			$packer->add(Hash::of($frame), $frame, [
				'raw' => strlen($frame) * 2,
				'codec' => 'none',
				'cipher' => 'none',
			]);
		}

		$pack = $packer->flush();
		$this->assertNotNull($pack);

		$directory = PackIndex::decode('packs/x', $pack['bytes']);
		$this->assertCount(3, $directory);

		foreach ($directory as $i => $entry) {
			$this->assertSame(Hash::of($frames[$i]), $entry['hash']);
			$this->assertSame(strlen($frames[$i]) * 2, $entry['raw']);
			$this->assertSame('none', $entry['codec']);
			$this->assertSame(
				$frames[$i],
				Packer::extract($pack['bytes'], $entry['offset'], $entry['length']),
			);
		}
	}

	#endregion
}
