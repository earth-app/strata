<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Cas;

use Drupal\strata\Cas\FrameRecord;
use Drupal\strata\Cas\Framer;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Cas\MemoryFrameIndex;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Cas\Packer;
use Drupal\strata\Codec\CodecRegistry;
use Drupal\strata\Crypto\CipherInterface;
use Drupal\strata\Crypto\NullCipher;
use Drupal\strata\Crypto\StaticKeyProvider;
use Drupal\strata\Crypto\XChaCha20Poly1305Cipher;
use Drupal\strata\Storage\Plugin\Strata\Storage\LocalStorage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ObjectStore::class)]
class ObjectStoreTest extends TestCase
{
	#region Fixtures

	/**
	 * Roots created during a test, removed afterwards.
	 *
	 * @var list<string>
	 */
	private array $roots = [];

	/**
	 * The provider the store under test writes to.
	 */
	private ?LocalStorage $provider = null;

	/**
	 * The index the store under test records into.
	 */
	private ?MemoryFrameIndex $index = null;

	protected function tearDown(): void
	{
		foreach ($this->roots as $root) {
			$this->removeTree($root);
		}

		$this->roots = [];
		$this->provider = null;
		$this->index = null;

		parent::tearDown();
	}

	private function removeTree(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}

		foreach (scandir($path) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$child = $path . '/' . $entry;
			is_dir($child) ? $this->removeTree($child) : @unlink($child);
		}

		@rmdir($path);
	}

	private function store(
		int $frameSize = 16384,
		int $packTarget = 65536,
		?CipherInterface $cipher = null,
		?string $dictionary = null,
		?string $dictionaryId = null,
	): ObjectStore {
		$root = sys_get_temp_dir() . '/strata-store-' . bin2hex(random_bytes(8));
		$this->roots[] = $root;

		$this->provider = new LocalStorage($root);
		$this->index = new MemoryFrameIndex();

		return new ObjectStore(
			$this->provider,
			$this->index,
			CodecRegistry::withShippedCodecs(),
			$cipher ?? new XChaCha20Poly1305Cipher(StaticKeyProvider::generate()->key()),
			new Framer($frameSize),
			new Packer($packTarget),
			19,
			$dictionary,
			$dictionaryId,
		);
	}

	/**
	 * A compressible value of roughly a given size.
	 */
	private function value(int $approximateBytes): string
	{
		$value = '';
		$i = 0;

		while (strlen($value) < $approximateBytes) {
			$value .=
				(string) json_encode([
					'nid' => [['value' => $i]],
					'title' => [['value' => "Node title $i"]],
					'body' => [['value' => str_repeat('lorem ipsum dolor sit amet ', 20)]],
				]) . "\n";
			$i++;
		}

		return $value;
	}

	#endregion

	#region Round trips

	#[Test]
	#[TestDox('a value written to the store reads back byte for byte')]
	#[Group('strata/cas')]
	public function valueRoundTrips(): void
	{
		$store = $this->store();
		$value = $this->value(80_000);

		$map = $store->write($value);
		$store->commit();

		$this->assertNotEmpty($map);
		$this->assertSame($value, $store->read($map));
	}

	#[Test]
	#[TestDox('an empty value produces an empty map and writes nothing')]
	#[Group('strata/cas')]
	public function emptyValueWritesNothing(): void
	{
		$store = $this->store();

		$this->assertSame([], $store->write(''));
		$this->assertSame(0, $store->commit());
		$this->assertCount(0, (array) $this->provider?->list('')->objects);
		$this->assertSame('', $store->read([]));
	}

	#[Test]
	#[TestDox('incompressible content round-trips too')]
	#[Group('strata/cas')]
	public function incompressibleContentRoundTrips(): void
	{
		$store = $this->store();
		$value = random_bytes(70_000);

		$map = $store->write($value);
		$store->commit();

		$this->assertSame($value, $store->read($map));
	}

	#[Test]
	#[TestDox('the store works with encryption switched off')]
	#[Group('strata/cas')]
	public function worksWithoutEncryption(): void
	{
		$store = $this->store(cipher: new NullCipher());
		$value = $this->value(40_000);

		$map = $store->write($value);
		$store->commit();

		$this->assertSame($value, $store->read($map));
	}

	#endregion

	#region Deduplication

	#[Test]
	#[TestDox('writing the same value twice adds no objects and only raises reference counts')]
	#[Group('strata/cas')]
	public function rewritingDeduplicates(): void
	{
		$store = $this->store();
		$value = $this->value(60_000);

		$first = $store->write($value);
		$store->commit();

		$objectsAfterFirst = count((array) $this->provider?->list('')->objects);
		$framesAfterFirst = $this->index?->statistics()['frames'];

		$second = $store->write($value);
		$store->commit();

		$this->assertSame($first, $second, 'the same content produces the same map');
		$this->assertCount($objectsAfterFirst, (array) $this->provider?->list('')->objects);
		$this->assertSame($framesAfterFirst, $this->index?->statistics()['frames']);

		foreach ($first as $hash) {
			$this->assertSame(2, $this->index?->get($hash)?->references);
		}
	}

	#[Test]
	#[TestDox('two values sharing a leading frame share that frame in the store')]
	#[Group('strata/cas')]
	public function sharedContentSharesFrames(): void
	{
		$store = $this->store(frameSize: 1024, packTarget: Packer::MIN_TARGET);

		// four DISTINCT 1 KiB frames, so a reference count reflects reuse rather than repetition
		$shared = '';
		for ($i = 0; $i < 4; $i++) {
			$shared .= str_pad("block $i ", 1024, chr(ord('a') + $i));
		}

		$firstMap = $store->write($shared . str_pad('tail one', 1024, '1'));
		$secondMap = $store->write($shared . str_pad('tail two', 1024, '2'));
		$store->commit();

		$this->assertCount(5, $firstMap);
		$this->assertSame(array_slice($firstMap, 0, 4), array_slice($secondMap, 0, 4));
		$this->assertSame(4, count(array_unique(array_slice($firstMap, 0, 4))));
		$this->assertNotSame($firstMap[4], $secondMap[4]);

		foreach (array_slice($firstMap, 0, 4) as $hash) {
			$this->assertSame(
				2,
				$this->index?->get($hash)?->references,
				'shared frames are reused',
			);
		}

		$this->assertSame(1, $this->index?->get($firstMap[4])?->references);
		$this->assertSame(1, $this->index?->get($secondMap[4])?->references);
	}

	#endregion

	#region Packing

	#[Test]
	#[TestDox('small frames are batched into one object rather than one object each')]
	#[Group('strata/cas')]
	public function smallFramesArePacked(): void
	{
		$store = $this->store(frameSize: 1024, packTarget: Packer::MIN_TARGET);
		$map = $store->write($this->value(20_000));
		$store->commit();

		$objects = (array) $this->provider?->list('')->objects;

		$this->assertGreaterThan(5, count($map), 'the fixture must produce several frames');
		$this->assertLessThan(count($map), count($objects), 'frames must share objects');

		foreach ($map as $hash) {
			$this->assertTrue($this->index?->get($hash)?->isPacked());
		}
	}

	#[Test]
	#[TestDox('a frame at or above the pack target is stored as its own object')]
	#[Group('strata/cas')]
	public function largeFrameStoredAlone(): void
	{
		// incompressible content at a frame size above the pack target cannot be batched
		$store = $this->store(frameSize: 131_072, packTarget: Packer::MIN_TARGET);
		$map = $store->write(random_bytes(131_072));
		$store->commit();

		$this->assertCount(1, $map);
		$this->assertFalse($this->index?->get($map[0])?->isPacked());
		$this->assertSame(
			[Hash::key($map[0], ObjectStore::FRAME_PREFIX)],
			$this->provider?->list('')->keys(),
		);
	}

	#[Test]
	#[TestDox('a packed frame reads back through a ranged fetch of just its own bytes')]
	#[Group('strata/cas')]
	public function packedFrameReadsBackByRange(): void
	{
		$store = $this->store(frameSize: 1024, packTarget: Packer::MIN_TARGET);
		$value = $this->value(20_000);
		$map = $store->write($value);
		$store->commit();

		$middle = $map[intdiv(count($map), 2)];
		$record = $this->index?->get($middle);

		$this->assertNotNull($record);
		$this->assertTrue($record->isPacked());
		$this->assertGreaterThan(0, $record->offset);

		// reading one frame must not require the whole value
		$frame = $store->frame($middle);
		$this->assertNotNull($frame);
		$this->assertSame($middle, Hash::of($frame));
	}

	#[Test]
	#[TestDox('commit flushes a pack that has not reached its target')]
	#[Group('strata/cas')]
	public function commitFlushesPartialPack(): void
	{
		$store = $this->store(frameSize: 1024, packTarget: Packer::MAX_TARGET);
		$map = $store->write($this->value(4_000));

		$this->assertCount(
			0,
			(array) $this->provider?->list('')->objects,
			'nothing is written yet',
		);

		$this->assertSame(1, $store->commit());
		$this->assertCount(1, (array) $this->provider?->list('')->objects);
		$this->assertSame($this->value(4_000), $store->read($map));
	}

	#endregion

	#region Failure modes

	#[Test]
	#[TestDox('reading a frame the index does not know returns null')]
	#[Group('strata/cas')]
	public function unknownFrameReturnsNull(): void
	{
		$this->assertNull($this->store()->frame(Hash::of('never written')));
	}

	#[Test]
	#[TestDox('a map naming an unknown frame names it rather than returning short content')]
	#[Group('strata/cas')]
	public function unknownFrameInMapRaises(): void
	{
		$store = $this->store();
		$missing = Hash::of('never written');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(Hash::abbreviate($missing));

		$store->read([$missing]);
	}

	#[Test]
	#[TestDox('a tampered pack fails authentication rather than decoding to noise')]
	#[Group('strata/cas')]
	public function tamperedPackFailsAuthentication(): void
	{
		$store = $this->store(frameSize: 1024, packTarget: Packer::MIN_TARGET);
		$map = $store->write($this->value(8_000));
		$store->commit();

		$key = $this->provider?->list('')->keys()[0];
		$this->assertNotNull($key);

		$bytes = (string) $this->provider?->get($key);
		$bytes[10] = chr(ord($bytes[10]) ^ 0xff);
		$this->provider?->put($key, $bytes);

		$this->expectException(RuntimeException::class);

		$store->read($map);
	}

	#[Test]
	#[TestDox('a dictionary supplied without an id is refused at construction')]
	#[Group('strata/cas')]
	public function dictionaryWithoutIdIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('must be given an id');

		$this->store(dictionary: 'some dictionary bytes');
	}

	#[Test]
	#[TestDox('a frame whose dictionary nothing can supply refuses to decode')]
	#[Group('strata/cas')]
	public function wrongDictionaryIsRefused(): void
	{
		$registry = CodecRegistry::withShippedCodecs();

		if ($registry->dictionaryWriter() === null) {
			$this->markTestSkipped('no per-frame dictionary-capable codec on this host');
		}

		$dictionary = str_repeat('lorem ipsum dolor sit amet ', 400);
		$key = StaticKeyProvider::generate()->key();
		$root = sys_get_temp_dir() . '/strata-dict-' . bin2hex(random_bytes(8));
		$this->roots[] = $root;

		$provider = new LocalStorage($root);
		$index = new MemoryFrameIndex();

		$written = new ObjectStore(
			$provider,
			$index,
			$registry,
			new XChaCha20Poly1305Cipher($key),
			new Framer(16384),
			new Packer(Packer::MIN_TARGET),
			19,
			$dictionary,
			'dict-v1',
		);

		$map = $written->write($this->value(20_000));
		$written->commit();

		// the same store with a different dictionary loaded and no store to fetch the old one from
		$reopened = new ObjectStore(
			$provider,
			$index,
			$registry,
			new XChaCha20Poly1305Cipher($key),
			new Framer(16384),
			new Packer(Packer::MIN_TARGET),
			19,
			$dictionary,
			'dict-v2',
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('is not available here');

		$reopened->read($map);
	}

	#endregion

	#region Accounting

	#[Test]
	#[TestDox('the index reports the compression the store actually achieved')]
	#[Group('strata/cas')]
	public function indexReportsCompression(): void
	{
		$store = $this->store(frameSize: 16384, packTarget: Packer::MIN_TARGET);
		$value = $this->value(100_000);
		$store->write($value);
		$store->commit();

		$statistics = (array) $this->index?->statistics();

		$this->assertGreaterThan(0, $statistics['frames']);
		$this->assertSame(strlen($value), $statistics['rawBytes']);
		$this->assertLessThan($statistics['rawBytes'], $statistics['storedBytes']);
		$this->assertGreaterThan(1.0, $statistics['ratio']);
		$this->assertSame(0, $statistics['orphans']);
	}

	#[Test]
	#[TestDox('page() lists every frame, referenced or not, and pages stably')]
	#[Group('strata/cas')]
	public function pageListsEveryFrame(): void
	{
		$store = $this->store(frameSize: 1024, packTarget: Packer::MIN_TARGET);
		$store->write($this->value(8_000));
		$store->commit();

		$index = $this->index;

		$this->assertNotNull($index);

		$all = $index->page(1000);

		$this->assertSame($index->statistics()['frames'], count($all), 'nothing is left out');
		$this->assertSame([], $index->orphans(1000), 'and none of them is collectable');

		$hashes = array_map(static fn(FrameRecord $record): string => $record->hash, $all);
		$first = $index->page(2);
		$rest = $index->page(1000, 2);

		$this->assertSame(array_slice($hashes, 0, 2), array_column($first, 'hash'));
		$this->assertSame(array_slice($hashes, 2), array_column($rest, 'hash'));
		$this->assertSame([], $index->page(10, count($all)), 'past the end is empty, not a repeat');
	}

	#[Test]
	#[TestDox('every frame record names the codec, cipher and location that wrote it')]
	#[Group('strata/cas')]
	public function recordsNameTheirProvenance(): void
	{
		$store = $this->store(frameSize: 1024, packTarget: Packer::MIN_TARGET);
		$map = $store->write($this->value(8_000));
		$store->commit();

		foreach ($map as $hash) {
			$record = $this->index?->get($hash);

			$this->assertNotNull($record);
			$this->assertSame($hash, $record->hash);
			$this->assertNotSame('', $record->codec);
			$this->assertSame('xchacha20poly1305', $record->cipher);
			$this->assertGreaterThan(0, $record->rawSize);
			$this->assertGreaterThan(0, $record->storedSize);
			$this->assertNotNull($record->pack);
		}
	}

	#endregion
}
