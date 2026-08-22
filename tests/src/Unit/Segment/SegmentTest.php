<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Segment;

use Drupal\strata\Cas\Framer;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Cas\MemoryFrameIndex;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Cas\Packer;
use Drupal\strata\Codec\CodecRegistry;
use Drupal\strata\Crypto\CipherInterface;
use Drupal\strata\Crypto\StaticKeyProvider;
use Drupal\strata\Crypto\XChaCha20Poly1305Cipher;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;
use Drupal\strata\Segment\SegmentBuilder;
use Drupal\strata\Segment\SegmentManifest;
use Drupal\strata\Segment\SegmentReader;
use Drupal\strata\Segment\SegmentWriter;
use Drupal\strata\Storage\Plugin\Strata\Storage\LocalStorage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SegmentManifest::class)]
#[CoversClass(SegmentBuilder::class)]
#[CoversClass(SegmentWriter::class)]
#[CoversClass(SegmentReader::class)]
class SegmentTest extends TestCase
{
	#region Fixtures

	/**
	 * Roots created during a test, removed afterwards.
	 *
	 * @var list<string>
	 */
	private array $roots = [];

	/**
	 * The provider the segment under test is written to.
	 */
	private ?LocalStorage $provider = null;

	/**
	 * The cipher the segment under test is sealed with.
	 */
	private ?CipherInterface $cipher = null;

	/**
	 * Sequence counter for the operation factory.
	 */
	private int $sequence = 0;

	/**
	 * Microtime counter for the operation factory.
	 */
	private int $microtime = 1_755_000_000_000_000;

	protected function tearDown(): void
	{
		foreach ($this->roots as $root) {
			$this->removeTree($root);
		}

		$this->roots = [];
		$this->provider = null;
		$this->cipher = null;
		$this->sequence = 0;
		$this->microtime = 1_755_000_000_000_000;

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

	private function provider(): LocalStorage
	{
		if ($this->provider === null) {
			$root = sys_get_temp_dir() . '/strata-seg-' . bin2hex(random_bytes(8));
			$this->roots[] = $root;
			$this->provider = new LocalStorage($root);
		}

		return $this->provider;
	}

	private function cipher(): CipherInterface
	{
		return $this->cipher ??= new XChaCha20Poly1305Cipher(StaticKeyProvider::generate()->key());
	}

	private function store(): ObjectStore
	{
		return new ObjectStore(
			$this->provider(),
			new MemoryFrameIndex(),
			CodecRegistry::withShippedCodecs(),
			$this->cipher(),
			new Framer(4096),
			new Packer(Packer::MIN_TARGET),
			19,
		);
	}

	private function writer(): SegmentWriter
	{
		return new SegmentWriter(
			$this->provider(),
			CodecRegistry::withShippedCodecs(),
			$this->cipher(),
			19,
		);
	}

	private function reader(): SegmentReader
	{
		return new SegmentReader(
			$this->provider(),
			CodecRegistry::withShippedCodecs(),
			$this->cipher(),
		);
	}

	private function op(
		Realm $realm,
		string $subject,
		Verb $verb,
		?string $payload = null,
	): JournalOp {
		return new JournalOp(
			++$this->sequence,
			($this->microtime += 1_000),
			$realm,
			$subject,
			$verb,
			1,
			'req-1',
			$payload === null ? null : Hash::of($payload),
			null,
			$payload === null ? 0 : strlen($payload),
			$subject . ' ' . $verb->value,
		);
	}

	#endregion

	#region Building

	#[Test]
	#[TestDox('an empty builder produces no manifest')]
	#[Group('strata/segment')]
	public function emptyBuilderProducesNothing(): void
	{
		$builder = new SegmentBuilder($this->store());

		$this->assertTrue($builder->isEmpty());
		$this->assertSame(0, $builder->count());
		$this->assertNull($builder->build());
	}

	#[Test]
	#[TestDox('a negative compaction level is refused')]
	#[Group('strata/segment')]
	public function negativeLevelIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('compaction level cannot be negative');

		new SegmentBuilder($this->store(), -1);
	}

	#[Test]
	#[TestDox('repeated writes to one subject collapse to a single operation')]
	#[Group('strata/segment')]
	public function repeatedWritesCollapse(): void
	{
		$store = $this->store();
		$builder = new SegmentBuilder($store);

		$builder->add($this->op(Realm::ENTITY, 'node:7', Verb::CREATE), '{"title":"first"}');
		$builder->add($this->op(Realm::ENTITY, 'node:7', Verb::UPDATE), '{"title":"second"}');
		$builder->add($this->op(Realm::ENTITY, 'node:7', Verb::UPDATE), '{"title":"third"}');
		$builder->add($this->op(Realm::CONFIG, 'system.site', Verb::UPDATE), '{"name":"Strata"}');

		$this->assertSame(4, $builder->count());

		$manifest = $builder->build();
		$this->assertNotNull($manifest);

		$this->assertSame(2, $manifest->count());
		$this->assertSame(4, $manifest->collapsedFrom);
		$this->assertSame(2, $manifest->collapsed());

		// the survivor keeps the earliest verb and sequence but the latest payload
		$survivor = $manifest->operations[0];
		$this->assertSame('node:7', $survivor->subject);
		$this->assertSame(Verb::CREATE, $survivor->verb);
		$this->assertSame(1, $survivor->sequence);
		$this->assertSame('{"title":"third"}', $store->read($manifest->payloadFor($survivor)));
	}

	#[Test]
	#[TestDox('an operation with no payload carries no frame map')]
	#[Group('strata/segment')]
	public function operationWithoutPayloadHasNoFrames(): void
	{
		$builder = new SegmentBuilder($this->store());
		$builder->add($this->op(Realm::ENTITY, 'node:11', Verb::DELETE));

		$manifest = $builder->build();
		$this->assertNotNull($manifest);

		$this->assertSame([], $manifest->payloadFor($manifest->operations[0]));
		$this->assertSame([], $manifest->frames());
		$this->assertSame(0, $manifest->rawBytes);
	}

	#[Test]
	#[TestDox('building clears the builder so the next window starts empty')]
	#[Group('strata/segment')]
	public function buildingClearsTheBuilder(): void
	{
		$builder = new SegmentBuilder($this->store());
		$builder->add($this->op(Realm::ENTITY, 'node:1', Verb::UPDATE), 'payload');

		$this->assertNotNull($builder->build());
		$this->assertTrue($builder->isEmpty());
		$this->assertNull($builder->build());
	}

	#[Test]
	#[TestDox('resetting discards everything without storing it')]
	#[Group('strata/segment')]
	public function resetDiscardsEverything(): void
	{
		$builder = new SegmentBuilder($this->store());
		$builder->add($this->op(Realm::ENTITY, 'node:1', Verb::UPDATE), 'payload');
		$builder->reset();

		$this->assertTrue($builder->isEmpty());
		$this->assertNull($builder->build());
	}

	#[Test]
	#[TestDox('two operations sharing a payload share its frames')]
	#[Group('strata/segment')]
	public function sharedPayloadsShareFrames(): void
	{
		$builder = new SegmentBuilder($this->store());
		$payload = str_repeat('the same value ', 40);

		$builder->add($this->op(Realm::ENTITY, 'node:1', Verb::UPDATE), $payload);
		$builder->add($this->op(Realm::ENTITY, 'node:2', Verb::UPDATE), $payload);

		$manifest = $builder->build();
		$this->assertNotNull($manifest);

		$this->assertSame(2, $manifest->count());
		$this->assertCount(1, $manifest->frames(), 'identical payloads reference one frame');
	}

	#endregion

	#region Manifests

	#[Test]
	#[TestDox('a manifest reports its range, duration and per-realm summary')]
	#[Group('strata/segment')]
	public function manifestReportsItsShape(): void
	{
		$builder = new SegmentBuilder($this->store());

		for ($i = 0; $i < 3; $i++) {
			$builder->add($this->op(Realm::ENTITY, "node:$i", Verb::UPDATE), "payload $i");
		}
		$builder->add($this->op(Realm::CONFIG, 'system.site', Verb::UPDATE), 'config payload');

		$manifest = $builder->build();
		$this->assertNotNull($manifest);

		$this->assertSame(1, $manifest->firstSequence);
		$this->assertSame(4, $manifest->lastSequence);
		$this->assertEqualsWithDelta(0.003, $manifest->duration(), 0.0001);
		$this->assertSame('3 entity, 1 config', $manifest->summary());
		$this->assertCount(3, $manifest->byRealm()[Realm::ENTITY->value]);
		$this->assertCount(1, $manifest->byRealm()[Realm::CONFIG->value]);
		$this->assertSame(SegmentManifest::VERSION, $manifest->version);
	}

	#[Test]
	#[TestDox('an empty manifest summarises as having no operations')]
	#[Group('strata/segment')]
	public function emptyManifestSummarises(): void
	{
		$manifest = new SegmentManifest(0, 0, 0, 0, 0, []);

		$this->assertTrue($manifest->isEmpty());
		$this->assertSame(0, $manifest->count());
		$this->assertSame('no operations', $manifest->summary());
		$this->assertSame(0.0, $manifest->duration());
	}

	/**
	 * @return array<string, array{array<int, mixed>, string}>
	 */
	public static function incoherentManifestProvider(): array
	{
		return [
			'negative level' => [[-1, 0, 0, 0, 0, []], 'count cannot be negative'],
			'negative raw bytes' => [[0, 0, 0, 0, 0, [], [], 0, -1], 'count cannot be negative'],
			'zero version' => [[0, 0, 0, 0, 0, [], [], 0, 0, 0], 'count cannot be negative'],
		];
	}

	#[Test]
	#[TestDox('a manifest with $_dataName is refused')]
	#[Group('strata/segment')]
	#[DataProvider('incoherentManifestProvider')]
	public function incoherentManifestsAreRefused(array $arguments, string $message): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		new SegmentManifest(...$arguments);
	}

	#[Test]
	#[TestDox('an inverted sequence range is refused')]
	#[Group('strata/segment')]
	public function invertedSequenceRangeIsRefused(): void
	{
		$op = $this->op(Realm::ENTITY, 'node:1', Verb::UPDATE);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot end at sequence');

		new SegmentManifest(0, 10, 5, 0, 0, [$op]);
	}

	#[Test]
	#[TestDox('an inverted time range is refused')]
	#[Group('strata/segment')]
	public function invertedTimeRangeIsRefused(): void
	{
		$op = $this->op(Realm::ENTITY, 'node:1', Verb::UPDATE);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot end before it begins');

		new SegmentManifest(0, 1, 2, 100, 50, [$op]);
	}

	#[Test]
	#[TestDox('a payload naming an operation the manifest does not carry is refused')]
	#[Group('strata/segment')]
	public function orphanPayloadIsRefused(): void
	{
		$op = $this->op(Realm::ENTITY, 'node:1', Verb::UPDATE);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('which this segment does not carry');

		new SegmentManifest(0, 1, 1, 1, 1, [$op], ['entity:node:999' => [Hash::of('f')]]);
	}

	#[Test]
	#[TestDox('a payload naming a frame that is not a digest is refused')]
	#[Group('strata/segment')]
	public function badFrameInPayloadIsRefused(): void
	{
		$op = $this->op(Realm::ENTITY, 'node:1', Verb::UPDATE);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('not a valid digest');

		new SegmentManifest(0, 1, 1, 1, 1, [$op], [$op->key() => ['not-a-hash']]);
	}

	#[Test]
	#[TestDox('a manifest round-trips through its serialized form')]
	#[Group('strata/segment')]
	public function manifestRoundTrips(): void
	{
		$builder = new SegmentBuilder($this->store(), 2);
		$builder->add($this->op(Realm::ENTITY, 'node:1', Verb::UPDATE), 'payload one');
		$builder->add($this->op(Realm::CONFIG, 'system.site', Verb::UPDATE), 'payload two');

		$manifest = $builder->build();
		$this->assertNotNull($manifest);

		$rebuilt = SegmentManifest::fromArray($manifest->jsonSerialize());

		$this->assertEquals($manifest, $rebuilt);
		$this->assertSame(2, $rebuilt->level);
	}

	#[Test]
	#[TestDox('a manifest from a newer release is refused rather than guessed at')]
	#[Group('strata/segment')]
	public function newerVersionIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot be read by this release');

		SegmentManifest::fromArray(['version' => SegmentManifest::VERSION + 1]);
	}

	#[Test]
	#[TestDox('a manifest with no version at all is refused')]
	#[Group('strata/segment')]
	public function missingVersionIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot be read by this release');

		SegmentManifest::fromArray(['level' => 0]);
	}

	#endregion

	#region Writing and reading

	#[Test]
	#[TestDox('a segment round-trips through the store')]
	#[Group('strata/segment')]
	public function segmentRoundTrips(): void
	{
		$store = $this->store();
		$builder = new SegmentBuilder($store);
		$builder->add($this->op(Realm::ENTITY, 'node:7', Verb::UPDATE), '{"title":"seven"}');
		$builder->add($this->op(Realm::CONFIG, 'system.site', Verb::UPDATE), '{"name":"Strata"}');

		$manifest = $builder->build();
		$this->assertNotNull($manifest);

		$key = $this->writer()->write($manifest);
		$back = $this->reader()->read($key);

		$this->assertSame($manifest->count(), $back->count());
		$this->assertSame($manifest->summary(), $back->summary());
		$this->assertSame($manifest->rawBytes, $back->rawBytes);
		$this->assertEquals($manifest->operations, $back->operations);
		$this->assertSame(
			'{"title":"seven"}',
			$store->read($back->payloadFor($back->operations[0])),
		);
	}

	#[Test]
	#[TestDox('an empty segment is not written')]
	#[Group('strata/segment')]
	public function emptySegmentIsNotWritten(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('An empty segment is not written');

		$this->writer()->write(new SegmentManifest(0, 0, 0, 0, 0, []));
	}

	#[Test]
	#[TestDox('a segment carrying one operation round-trips, and its range is that one operation')]
	#[Group('strata/segment')]
	public function oneOperationSegmentRoundTrips(): void
	{
		$builder = new SegmentBuilder($this->store());
		$builder->add($this->op(Realm::CONFIG, 'system.site', Verb::UPDATE), '{"name":"one"}');

		$manifest = $builder->build();
		$this->assertNotNull($manifest);

		$back = $this->reader()->read($this->writer()->write($manifest));

		$this->assertSame(1, $back->count());
		$this->assertSame($back->firstSequence, $back->lastSequence);
		$this->assertSame($back->firstMicrotime, $back->lastMicrotime);
		$this->assertSame(0.0, $back->duration());
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function extremeSubjectProvider(): array
	{
		return [
			'a null byte' => ["node:4\x002"],
			'a newline' => ["node:4\n2"],
			'an emoji' => ['node:' . "\u{1F600}"],
			'a right-to-left override' => ['node:' . "\u{202E}" . 'txt.exe'],
			'a four-byte character' => ['node:' . "\u{2A6B2}"],
			'four kibibytes of ascii' => ['node:' . str_repeat('x', 4091)],
		];
	}

	#[Test]
	#[TestDox('a segment naming a subject holding $_dataName round-trips byte for byte')]
	#[Group('strata/segment')]
	#[DataProvider('extremeSubjectProvider')]
	public function extremeSubjectsSurviveTheStore(string $subject): void
	{
		$store = $this->store();
		$builder = new SegmentBuilder($store);
		$builder->add($this->op(Realm::ENTITY, $subject, Verb::UPDATE), '{"a":1}');

		$manifest = $builder->build();
		$this->assertNotNull($manifest);

		$back = $this->reader()->read($this->writer()->write($manifest));

		$this->assertSame($subject, $back->operations[0]->subject);
		$this->assertSame('{"a":1}', $store->read($back->payloadFor($back->operations[0])));
	}

	#[Test]
	#[TestDox('the object key carries the level, the epoch second and the first sequence')]
	#[Group('strata/segment')]
	public function keyCarriesLevelSecondAndSequence(): void
	{
		$builder = new SegmentBuilder($this->store(), 3);
		$builder->add($this->op(Realm::ENTITY, 'node:1', Verb::UPDATE), 'payload');

		$manifest = $builder->build();
		$this->assertNotNull($manifest);

		$key = $this->writer()->write($manifest);

		$this->assertStringStartsWith(SegmentWriter::levelPrefix(3), $key);
		$this->assertStringStartsWith(SegmentWriter::secondPrefix(3, 1_755_000_000), $key);
		$this->assertStringEndsWith('.seg', $key);
		$this->assertSame(1_755_000_000, SegmentReader::secondOf($key));
	}

	#[Test]
	#[TestDox('a key that is not shaped like a segment key has no second')]
	#[Group('strata/segment')]
	public function nonSegmentKeyHasNoSecond(): void
	{
		$this->assertNull(SegmentReader::secondOf('frames/ab/cd/deadbeef'));
		$this->assertNull(SegmentReader::secondOf('segments/nonsense'));
	}

	#[Test]
	#[TestDox('reading a segment that is not present names the key')]
	#[Group('strata/segment')]
	public function absentSegmentNamesKey(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('segments/0/1/2.seg is not present');

		$this->reader()->read('segments/0/1/2.seg');
	}

	#[Test]
	#[TestDox('a segment relocated to another key fails to open')]
	#[Group('strata/segment')]
	public function relocatedSegmentFailsToOpen(): void
	{
		$builder = new SegmentBuilder($this->store());
		$builder->add($this->op(Realm::ENTITY, 'node:1', Verb::UPDATE), 'payload');

		$manifest = $builder->build();
		$this->assertNotNull($manifest);

		$key = $this->writer()->write($manifest);
		$moved = str_replace('/0/', '/9/', $key);
		$this->provider()->put($moved, $this->provider()->get($key));

		$this->expectException(RuntimeException::class);

		$this->reader()->read($moved);
	}

	#[Test]
	#[TestDox('an object with no readable header is reported as truncated')]
	#[Group('strata/segment')]
	public function headerlessObjectIsReportedTruncated(): void
	{
		$this->provider()->put('segments/0/1/broken.seg', 'no newlines here at all');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no readable header');

		$this->reader()->read('segments/0/1/broken.seg');
	}

	#[Test]
	#[TestDox('an object marked with an unknown format is refused by name')]
	#[Group('strata/segment')]
	public function unknownFormatIsRefused(): void
	{
		$this->provider()->put(
			'segments/0/1/future.seg',
			"STRATA-SEG-99\nzstd\nxchacha20poly1305\n" . Hash::of('x') . "\nbody",
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('this release reads "' . SegmentWriter::MAGIC . '"');

		$this->reader()->read('segments/0/1/future.seg');
	}

	#[Test]
	#[TestDox('an object whose header carries no valid digest is refused')]
	#[Group('strata/segment')]
	public function headerWithoutDigestIsRefused(): void
	{
		$this->provider()->put(
			'segments/0/1/nodigest.seg',
			SegmentWriter::MAGIC . "\nzstd\nxchacha20poly1305\nnot-a-digest\nbody",
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('carries no valid digest');

		$this->reader()->read('segments/0/1/nodigest.seg');
	}

	#endregion

	#region Listing

	#[Test]
	#[TestDox('segments at a level list in capture order')]
	#[Group('strata/segment')]
	public function segmentsListInCaptureOrder(): void
	{
		$writer = $this->writer();
		$keys = [];

		for ($i = 0; $i < 5; $i++) {
			$builder = new SegmentBuilder($this->store());
			$builder->add($this->op(Realm::ENTITY, "node:$i", Verb::UPDATE), "payload $i");
			$manifest = $builder->build();
			$this->assertNotNull($manifest);
			$keys[] = $writer->write($manifest);
		}

		$listed = iterator_to_array($this->reader()->keys(0), false);

		$this->assertSame($keys, $listed, 'the sequence prefix keeps a listing in capture order');
	}

	#[Test]
	#[TestDox('a level with no segments lists as empty')]
	#[Group('strata/segment')]
	public function emptyLevelListsEmpty(): void
	{
		$this->assertSame([], iterator_to_array($this->reader()->keys(7), false));
		$this->assertSame([], $this->reader()->keysBetween(0, PHP_INT_MAX, 7));
	}

	#[Test]
	#[TestDox('a time range selects only the segments that fall inside it')]
	#[Group('strata/segment')]
	public function timeRangeSelectsSegments(): void
	{
		$writer = $this->writer();

		// three segments, one per second
		for ($i = 0; $i < 3; $i++) {
			$this->microtime = (1_755_000_000 + $i) * 1_000_000;
			$builder = new SegmentBuilder($this->store());
			$builder->add($this->op(Realm::ENTITY, "node:$i", Verb::UPDATE), "payload $i");
			$manifest = $builder->build();
			$this->assertNotNull($manifest);
			$writer->write($manifest);
		}

		$reader = $this->reader();

		$this->assertCount(3, $reader->keysBetween(1_755_000_000, 1_755_000_010, 0));
		$this->assertCount(1, $reader->keysBetween(1_755_000_000, 1_755_000_000, 0));
		$this->assertCount(2, $reader->keysBetween(1_755_000_001, 1_755_000_009, 0));
		$this->assertCount(0, $reader->keysBetween(1_755_000_100, 1_755_000_200, 0));
	}

	#[Test]
	#[TestDox('reading every segment at a level yields each manifest')]
	#[Group('strata/segment')]
	public function readingAllYieldsEveryManifest(): void
	{
		$writer = $this->writer();

		for ($i = 0; $i < 3; $i++) {
			$builder = new SegmentBuilder($this->store());
			$builder->add($this->op(Realm::ENTITY, "node:$i", Verb::UPDATE), "payload $i");
			$manifest = $builder->build();
			$this->assertNotNull($manifest);
			$writer->write($manifest);
		}

		$all = iterator_to_array($this->reader()->all(0));

		$this->assertCount(3, $all);
		foreach ($all as $key => $manifest) {
			$this->assertStringStartsWith(SegmentWriter::levelPrefix(0), $key);
			$this->assertSame(1, $manifest->count());
		}
	}

	#[Test]
	#[TestDox('levels are separate namespaces, so a rollup does not list with its inputs')]
	#[Group('strata/segment')]
	public function levelsAreSeparate(): void
	{
		$writer = $this->writer();

		foreach ([0, 0, 1] as $level) {
			$builder = new SegmentBuilder($this->store(), $level);
			$builder->add($this->op(Realm::ENTITY, 'node:1', Verb::UPDATE), 'payload');
			$manifest = $builder->build();
			$this->assertNotNull($manifest);
			$writer->write($manifest);
		}

		$this->assertCount(2, iterator_to_array($this->reader()->keys(0), false));
		$this->assertCount(1, iterator_to_array($this->reader()->keys(1), false));
	}

	#endregion
}
