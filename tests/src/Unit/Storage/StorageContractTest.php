<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Storage;

use Drupal\strata\Storage\ByteRange;
use Drupal\strata\Storage\Plugin\Strata\Storage\LocalStorage;
use Drupal\strata\Storage\Plugin\Strata\Storage\NullStorage;
use Drupal\strata\Storage\StorageProviderInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(LocalStorage::class)]
#[CoversClass(NullStorage::class)]
class StorageContractTest extends TestCase
{
	#region Fixtures

	/**
	 * Roots created during a test, removed afterwards.
	 *
	 * @var list<string>
	 */
	private array $roots = [];

	protected function tearDown(): void
	{
		foreach ($this->roots as $root) {
			$this->removeTree($root);
		}

		$this->roots = [];

		parent::tearDown();
	}

	private function local(): LocalStorage
	{
		$root = sys_get_temp_dir() . '/strata-test-' . bin2hex(random_bytes(8));
		$this->roots[] = $root;

		return new LocalStorage($root);
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

	/**
	 * Builds a provider by kind, tracking anything that needs cleaning up.
	 *
	 * A data provider must be static and so cannot register a temporary directory for teardown.
	 * The provider therefore names a kind and the test body builds it.
	 *
	 * @param string $kind
	 *   Either "local" or "null".
	 *
	 * @return StorageProviderInterface
	 *   A fresh provider.
	 */
	private function store(string $kind): StorageProviderInterface
	{
		return $kind === 'null' ? new NullStorage() : $this->local();
	}

	/**
	 * Every provider that can satisfy the whole contract, including reads.
	 *
	 * @return array<string, array{string}>
	 */
	public static function readableProvider(): array
	{
		return ['the local store' => ['local']];
	}

	/**
	 * Every provider, including the write-only dry-run store.
	 *
	 * @return array<string, array{string}>
	 */
	public static function everyProvider(): array
	{
		return ['the local store' => ['local'], 'the dry-run store' => ['null']];
	}

	#endregion

	#region Identity

	#[Test]
	#[TestDox('every provider reports a stable id, a label and a coherent capability set')]
	#[Group('strata/storage')]
	public function providersReportCoherentIdentity(): void
	{
		foreach ([$this->local(), new NullStorage()] as $provider) {
			$this->assertNotSame('', $provider->id());
			$this->assertSame(strtolower($provider->id()), $provider->id());
			$this->assertNotSame('', $provider->label());

			$capabilities = $provider->capabilities();
			$this->assertGreaterThan(0, $capabilities->maxObjectSize());
			$this->assertGreaterThanOrEqual(1, $capabilities->deleteBatchSize());

			// a reason is present exactly when the provider cannot be reached
			$this->assertSame($provider->isReachable(), $provider->unreachableReason() === null);
		}
	}

	#[Test]
	#[TestDox('an unwritable root is reported rather than discovered at flush time')]
	#[Group('strata/storage')]
	public function unwritableRootIsReported(): void
	{
		$store = new LocalStorage('/nonexistent-parent-strata/store');

		$this->assertFalse($store->isReachable());
		$this->assertStringContainsString('does not exist', (string) $store->unreachableReason());
	}

	#[Test]
	#[TestDox('an empty root is refused at construction')]
	#[Group('strata/storage')]
	public function emptyRootIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('needs a root directory');

		new LocalStorage('   ');
	}

	#endregion

	#region Round trips

	#[Test]
	#[TestDox('an object written to $_dataName reads back byte for byte')]
	#[Group('strata/storage')]
	#[DataProvider('readableProvider')]
	public function objectsRoundTrip(string $kind): void
	{
		$store = $this->store($kind);
		$payload = random_bytes(64 * 1024 + 7);
		$result = $store->put('frames/ab/cd/object', $payload);

		$this->assertSame('frames/ab/cd/object', $result->key);
		$this->assertSame(strlen($payload), $result->size);
		$this->assertSame($payload, $store->get('frames/ab/cd/object'));
	}

	#[Test]
	#[TestDox('an empty object is stored and read back as empty')]
	#[Group('strata/storage')]
	public function emptyObjectRoundTrips(): void
	{
		$store = $this->local();
		$store->put('empty', '');

		$this->assertTrue($store->exists('empty'));
		$this->assertSame('', $store->get('empty'));
		$this->assertSame(0, $store->head('empty')?->size);
	}

	#[Test]
	#[TestDox('a stream body is stored and reads back identically')]
	#[Group('strata/storage')]
	public function streamBodyRoundTrips(): void
	{
		$store = $this->local();
		$payload = random_bytes(3 * 1024 * 1024 + 11);

		$stream = fopen('php://temp', 'r+b');
		fwrite($stream, $payload);
		rewind($stream);

		$result = $store->put('big', $stream);
		fclose($stream);

		$this->assertSame(strlen($payload), $result->size);
		$this->assertSame($payload, $store->get('big'));
	}

	#[Test]
	#[TestDox('stream() hands back a readable handle positioned at the start')]
	#[Group('strata/storage')]
	public function streamReturnsReadableHandle(): void
	{
		$store = $this->local();
		$store->put('streamed', 'the whole payload');

		$handle = $store->stream('streamed');
		$this->assertIsResource($handle);
		$this->assertSame('the whole payload', stream_get_contents($handle));
		fclose($handle);
	}

	#[Test]
	#[TestDox('overwriting a key replaces it rather than appending')]
	#[Group('strata/storage')]
	public function overwriteReplaces(): void
	{
		$store = $this->local();
		$store->put('key', 'first value that is longer');
		$store->put('key', 'second');

		$this->assertSame('second', $store->get('key'));
		$this->assertSame(6, $store->head('key')?->size);
	}

	#endregion

	#region Ranges

	#[Test]
	#[TestDox('a byte range returns exactly the requested window')]
	#[Group('strata/storage')]
	public function rangeReturnsExactWindow(): void
	{
		$store = $this->local();
		$payload = '0123456789abcdef';
		$store->put('ranged', $payload);

		$this->assertSame('0123', $store->get('ranged', new ByteRange(0, 4)));
		$this->assertSame('456789', $store->get('ranged', new ByteRange(4, 6)));
		$this->assertSame('f', $store->get('ranged', new ByteRange(15, 1)));
	}

	#[Test]
	#[TestDox('a range running past the end raises instead of returning a short read')]
	#[Group('strata/storage')]
	public function rangePastEndRaises(): void
	{
		$store = $this->local();
		$store->put('short', '0123456789');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('returned 5 bytes for a 20 byte range');

		$store->get('short', new ByteRange(5, 20));
	}

	/**
	 * @return array<string, array{int, int, string}>
	 */
	public static function badRangeProvider(): array
	{
		return [
			'negative offset' => [-1, 4, 'offset cannot be negative'],
			'zero length' => [0, 0, 'must be at least one byte'],
			'negative length' => [0, -4, 'must be at least one byte'],
		];
	}

	#[Test]
	#[TestDox('a range with $_dataName is refused at construction')]
	#[Group('strata/storage')]
	#[DataProvider('badRangeProvider')]
	public function badRangesAreRefused(int $offset, int $length, string $message): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		new ByteRange($offset, $length);
	}

	#[Test]
	#[TestDox('a range renders as an inclusive http header')]
	#[Group('strata/storage')]
	public function rangeRendersAsHeader(): void
	{
		$range = new ByteRange(0, 16384);

		$this->assertSame(16383, $range->end());
		$this->assertSame('bytes=0-16383', $range->toHeader());
	}

	#endregion

	#region Absence

	#[Test]
	#[TestDox('heading an absent object returns null rather than raising')]
	#[Group('strata/storage')]
	#[DataProvider('everyProvider')]
	public function headingAbsentObjectReturnsNull(string $kind): void
	{
		$store = $this->store($kind);

		$this->assertNull($store->head('never/written'));
		$this->assertFalse($store->exists('never/written'));
	}

	#[Test]
	#[TestDox('reading an absent object names the key')]
	#[Group('strata/storage')]
	public function readingAbsentObjectNamesKey(): void
	{
		$store = $this->local();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Object missing/thing is not present');

		$store->get('missing/thing');
	}

	#[Test]
	#[TestDox('deleting an absent key is not an error, so a retried prune is idempotent')]
	#[Group('strata/storage')]
	#[DataProvider('everyProvider')]
	public function deletingAbsentKeyIsIdempotent(string $kind): void
	{
		$store = $this->store($kind);
		$store->put('present', 'x');

		$this->assertSame(1, $store->delete(['present', 'absent', 'also-absent']));
		$this->assertSame(0, $store->delete(['present']));
	}

	#endregion

	#region Conditional writes

	#[Test]
	#[TestDox('a conditional write refuses to overwrite an existing key')]
	#[Group('strata/storage')]
	public function conditionalWriteRefusesOverwrite(): void
	{
		$store = $this->local();
		$store->put('ref', 'first');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('conditional on its absence');

		$store->put('ref', 'second', ['ifNoneMatch' => true]);
	}

	#[Test]
	#[TestDox('a conditional write succeeds when the key is absent')]
	#[Group('strata/storage')]
	public function conditionalWriteSucceedsWhenAbsent(): void
	{
		$store = $this->local();
		$result = $store->put('fresh', 'value', ['ifNoneMatch' => true]);

		$this->assertSame(5, $result->size);
		$this->assertSame('value', $store->get('fresh'));
	}

	#endregion

	#region Listing

	#[Test]
	#[TestDox('a listing pages through every object using its cursor')]
	#[Group('strata/storage')]
	#[DataProvider('everyProvider')]
	public function listingPagesThroughEverything(string $kind): void
	{
		$store = $this->store($kind);

		for ($i = 0; $i < 25; $i++) {
			$store->put(sprintf('packs/%02d.pack', $i), str_repeat('x', $i + 1));
		}

		$seen = [];
		$cursor = null;
		$pages = 0;

		do {
			$page = $store->list('packs/', $cursor, 7);
			$seen = [...$seen, ...$page->keys()];
			$cursor = $page->cursor;
			$pages++;
			$this->assertLessThan(20, $pages, 'the listing did not terminate');
		} while ($page->hasMore());

		$this->assertCount(25, $seen);
		$this->assertSame($seen, array_unique($seen), 'no object may appear on two pages');
		$this->assertGreaterThan(1, $pages);
	}

	#[Test]
	#[TestDox('a prefix restricts the listing to matching keys')]
	#[Group('strata/storage')]
	#[DataProvider('everyProvider')]
	public function prefixRestrictsListing(string $kind): void
	{
		$store = $this->store($kind);
		$store->put('frames/a', 'x');
		$store->put('frames/b', 'x');
		$store->put('packs/a', 'x');

		$this->assertCount(2, $store->list('frames/'));
		$this->assertCount(1, $store->list('packs/'));
		$this->assertCount(3, $store->list(''));
	}

	#[Test]
	#[TestDox('a delimiter groups keys into common prefixes')]
	#[Group('strata/storage')]
	public function delimiterGroupsKeys(): void
	{
		$store = $this->local();
		$store->put('frames/aa/one', 'x');
		$store->put('frames/bb/two', 'x');
		$store->put('top', 'x');

		$page = $store->list('', null, 100, '/');

		$this->assertSame(['frames/'], $page->prefixes);
		$this->assertSame(['top'], $page->keys());
	}

	#[Test]
	#[TestDox('a listing reports its own byte total')]
	#[Group('strata/storage')]
	public function listingReportsBytes(): void
	{
		$store = $this->local();
		$store->put('a', str_repeat('x', 10));
		$store->put('b', str_repeat('x', 32));

		$this->assertSame(42, $store->list('')->bytes());
	}

	#[Test]
	#[TestDox('a listing of an empty store is empty and has no more pages')]
	#[Group('strata/storage')]
	#[DataProvider('everyProvider')]
	public function emptyListingIsEmpty(string $kind): void
	{
		$page = $this->store($kind)->list('');

		$this->assertCount(0, $page);
		$this->assertFalse($page->hasMore());
		$this->assertSame([], $page->keys());
		$this->assertSame(0, $page->bytes());
	}

	#[Test]
	#[TestDox('a listing limit below one is refused')]
	#[Group('strata/storage')]
	public function badListingLimitIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('at least one');

		$this->local()->list('', null, 0);
	}

	#endregion

	#region Key safety

	/**
	 * @return array<string, array{string}>
	 */
	public static function unsafeKeyProvider(): array
	{
		return [
			'parent traversal' => ['../escape'],
			'interior traversal' => ['frames/../../escape'],
			'current directory' => ['frames/./thing'],
			'empty segment' => ['frames//thing'],
			'empty key' => [''],
			'only slashes' => ['///'],
		];
	}

	#[Test]
	#[TestDox('a key containing $_dataName is refused instead of escaping the root')]
	#[Group('strata/storage')]
	#[DataProvider('unsafeKeyProvider')]
	public function unsafeKeysAreRefused(string $key): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->local()->put($key, 'payload');
	}

	#[Test]
	#[TestDox('a leading slash is stripped rather than treated as an absolute path')]
	#[Group('strata/storage')]
	public function leadingSlashIsStripped(): void
	{
		$store = $this->local();
		$store->put('/frames/thing', 'payload');

		$this->assertTrue($store->exists('frames/thing'));
		$this->assertSame('payload', $store->get('/frames/thing'));
		$this->assertStringStartsWith($store->root(), $store->root() . '/frames/thing');
	}

	#[Test]
	#[TestDox('a partially written object never becomes visible to a listing')]
	#[Group('strata/storage')]
	public function temporaryFilesAreNotListed(): void
	{
		$store = $this->local();
		$store->put('real', 'payload');

		// a crashed write leaves a temporary file behind; it must not read as an object
		file_put_contents($store->root() . '/orphan.strata-deadbeef.tmp', 'half');

		$this->assertSame(['real'], $store->list('')->keys());
		$this->assertFalse($store->exists('orphan.strata-deadbeef.tmp'));
		$this->assertNull($store->head('orphan.strata-deadbeef.tmp'));
	}

	#[Test]
	#[TestDox('writing to the reserved in-progress suffix is refused')]
	#[Group('strata/storage')]
	public function reservedSuffixCannotBeWritten(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('reserved suffix');

		$this->local()->put('frames/thing.tmp', 'payload');
	}

	#endregion

	#region Dry run

	#[Test]
	#[TestDox('the dry-run store counts what a real store would have been asked to hold')]
	#[Group('strata/storage')]
	public function dryRunCountsWhatWouldBeStored(): void
	{
		$store = new NullStorage();
		$store->put('a', str_repeat('x', 100));
		$store->put('b', str_repeat('x', 250));
		$store->put('a', str_repeat('x', 400));

		$statistics = $store->statistics();

		$this->assertSame(2, $statistics['objects']);
		$this->assertSame(650, $statistics['bytes'], 'a rewrite replaces rather than adds');
		$this->assertSame(3, $statistics['writes']);
		$this->assertSame(400, $statistics['largest']);
	}

	#[Test]
	#[TestDox('the dry-run store refuses reads instead of returning nothing')]
	#[Group('strata/storage')]
	public function dryRunRefusesReads(): void
	{
		$store = new NullStorage();
		$store->put('written', 'payload');

		$this->assertTrue($store->exists('written'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('holds no content');

		$store->get('written');
	}

	#[Test]
	#[TestDox('the dry-run store consumes a stream body so its size is still counted')]
	#[Group('strata/storage')]
	public function dryRunConsumesStreams(): void
	{
		$store = new NullStorage();
		$stream = fopen('php://temp', 'r+b');
		fwrite($stream, str_repeat('y', 4096));
		rewind($stream);

		$this->assertSame(4096, $store->put('streamed', $stream)->size);
		fclose($stream);
	}

	#[Test]
	#[TestDox('resetting the dry-run store forgets everything')]
	#[Group('strata/storage')]
	public function dryRunResets(): void
	{
		$store = new NullStorage();
		$store->put('a', 'payload');
		$store->delete(['a']);
		$store->reset();

		$this->assertSame(
			['objects' => 0, 'bytes' => 0, 'writes' => 0, 'deletes' => 0, 'largest' => 0],
			$store->statistics(),
		);
	}

	#endregion
}
