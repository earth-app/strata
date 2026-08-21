<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Tree;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Storage\Plugin\Strata\Storage\LocalStorage;
use Drupal\strata\Tree\Commit;
use Drupal\strata\Tree\CommitLog;
use Drupal\strata\Tree\RefStore;
use Drupal\strata\Tree\BaseManifest;
use Drupal\strata\Tree\BaseReader;
use Drupal\strata\Tree\BaseWriter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CommitLog::class)]
#[CoversClass(RefStore::class)]
#[CoversClass(BaseManifest::class)]
#[CoversClass(BaseWriter::class)]
#[CoversClass(BaseReader::class)]
class CommitLogTest extends TestCase
{
	#region Fixtures

	/**
	 * Roots created during a test, removed afterwards.
	 *
	 * @var list<string>
	 */
	private array $roots = [];

	/**
	 * The provider the log under test writes to.
	 */
	private ?LocalStorage $provider = null;

	protected function tearDown(): void
	{
		foreach ($this->roots as $root) {
			$this->removeTree($root);
		}

		$this->roots = [];
		$this->provider = null;

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
			$root = sys_get_temp_dir() . '/strata-log-' . bin2hex(random_bytes(8));
			$this->roots[] = $root;
			$this->provider = new LocalStorage($root);
		}

		return $this->provider;
	}

	private function log(): CommitLog
	{
		return new CommitLog($this->provider(), new RefStore($this->provider()));
	}

	/**
	 * A subject set of a given size, spread over two realms.
	 *
	 * @return array<string, array{frames: list<string>, size: int}>
	 */
	private function subjects(int $count): array
	{
		$subjects = [];

		for ($i = 0; $i < $count; $i++) {
			$realm = $i % 5 === 0 ? 'config' : 'entity';
			$subjects[$realm . '/subject:' . $i] = [
				'frames' => [Hash::of("frame for $i")],
				'size' => 100 + $i,
			];
		}

		return $subjects;
	}

	#endregion

	#region Refs

	#[Test]
	#[TestDox('a ref that has never been written reads as null')]
	#[Group('strata/tree')]
	public function absentRefReadsAsNull(): void
	{
		$this->assertNull((new RefStore($this->provider()))->read());
		$this->assertNull($this->log()->head());
	}

	#[Test]
	#[TestDox('a ref round-trips a commit id')]
	#[Group('strata/tree')]
	public function refRoundTrips(): void
	{
		$refs = new RefStore($this->provider());
		$id = Hash::of('a commit');

		$refs->write($id);

		$this->assertSame($id, $refs->read());
	}

	#[Test]
	#[TestDox('a ref pointing at something that is not a commit id raises')]
	#[Group('strata/tree')]
	public function corruptRefRaises(): void
	{
		$this->provider()->put(RefStore::PREFIX . '/heads/main', "not a commit\n");

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('does not hold a valid commit id');

		(new RefStore($this->provider()))->read();
	}

	#[Test]
	#[TestDox('writing a ref that is not a commit id is refused')]
	#[Group('strata/tree')]
	public function refRefusesNonCommitId(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('must point at a valid commit id');

		(new RefStore($this->provider()))->write('nope');
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function unsafeRefNameProvider(): array
	{
		return [
			'empty' => [''],
			'only slashes' => ['///'],
			'traversal' => ['heads/../escape'],
			'current directory' => ['heads/./main'],
			'empty segment' => ['heads//main'],
		];
	}

	#[Test]
	#[TestDox('a ref named with $_dataName is refused')]
	#[Group('strata/tree')]
	#[DataProvider('unsafeRefNameProvider')]
	public function unsafeRefNamesAreRefused(string $name): void
	{
		$this->expectException(InvalidArgumentException::class);

		(new RefStore($this->provider()))->write(Hash::of('commit'), $name);
	}

	#[Test]
	#[TestDox('compareAndSet advances a ref only from the value the caller expected')]
	#[Group('strata/tree')]
	public function compareAndSetGuardsTheTip(): void
	{
		$refs = new RefStore($this->provider());
		$first = Hash::of('first');
		$second = Hash::of('second');

		$this->assertTrue($refs->compareAndSet(null, $first), 'creating from nothing succeeds');
		$this->assertFalse($refs->compareAndSet(null, $second), 'creating again must fail');
		$this->assertFalse($refs->compareAndSet(Hash::of('wrong'), $second));
		$this->assertSame($first, $refs->read());

		$this->assertTrue($refs->compareAndSet($first, $second));
		$this->assertSame($second, $refs->read());
	}

	#[Test]
	#[TestDox('refs can be listed and deleted without touching the commits they name')]
	#[Group('strata/tree')]
	public function refsAreListedAndDeleted(): void
	{
		$refs = new RefStore($this->provider());
		$refs->write(Hash::of('a'), RefStore::MAIN);
		$refs->write(Hash::of('b'), 'heads/drill');

		$all = $refs->all();
		$this->assertCount(2, $all);
		$this->assertSame(Hash::of('a'), $all[RefStore::MAIN]);
		$this->assertSame(Hash::of('b'), $all['heads/drill']);

		$this->assertTrue($refs->delete('heads/drill'));
		$this->assertFalse($refs->delete('heads/drill'));
		$this->assertCount(1, $refs->all());
	}

	#endregion

	#region Appending

	#[Test]
	#[TestDox('appending a root commit creates the ref and points it at the commit')]
	#[Group('strata/tree')]
	public function appendingRootCommitCreatesRef(): void
	{
		$log = $this->log();
		$commit = new Commit(Hash::of('anchor'), null, 1_000, 'initial');

		$id = $log->append($commit);

		$this->assertSame($commit->id(), $id);
		$this->assertTrue($log->exists($id));
		$this->assertEquals($commit, $log->head());
	}

	#[Test]
	#[TestDox('a chain of commits appends in order and walks back newest first')]
	#[Group('strata/tree')]
	public function chainAppendsAndWalksBack(): void
	{
		$log = $this->log();
		$ids = [];
		$parent = null;

		for ($i = 0; $i < 5; $i++) {
			$commit = new Commit(Hash::of("tree $i"), $parent, 1_000 + $i, "commit $i");
			$parent = $log->append($commit);
			$ids[] = $parent;
		}

		$walked = array_keys(iterator_to_array($log->walk((string) $parent)));

		$this->assertSame(array_reverse($ids), $walked);
		$this->assertCount(3, iterator_to_array($log->walk((string) $parent, 3)));
	}

	#[Test]
	#[TestDox('a commit built on a stale head is refused rather than losing the commits between')]
	#[Group('strata/tree')]
	public function staleParentIsRefused(): void
	{
		$log = $this->log();
		$first = $log->append(new Commit(Hash::of('anchor one'), null, 1_000));
		$log->append(new Commit(Hash::of('anchor two'), $first, 2_000));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('points at');

		$log->append(new Commit(Hash::of('anchor three'), $first, 3_000));
	}

	#[Test]
	#[TestDox('a root commit appended onto an existing ref is refused')]
	#[Group('strata/tree')]
	public function rootOntoExistingRefIsRefused(): void
	{
		$log = $this->log();
		$log->append(new Commit(Hash::of('anchor one'), null, 1_000));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('builds on no parent');

		$log->append(new Commit(Hash::of('anchor two'), null, 2_000));
	}

	#[Test]
	#[TestDox('writing a commit without advancing a ref leaves the ref alone')]
	#[Group('strata/tree')]
	public function writeDoesNotAdvanceRef(): void
	{
		$log = $this->log();
		$id = $log->write(new Commit(Hash::of('anchor'), null, 1_000));

		$this->assertTrue($log->exists($id));
		$this->assertNull($log->head());
	}

	#[Test]
	#[TestDox('writing the same commit twice stores one object')]
	#[Group('strata/tree')]
	public function writingSameCommitTwiceStoresOne(): void
	{
		$log = $this->log();
		$commit = new Commit(Hash::of('anchor'), null, 1_000);

		$this->assertSame($log->write($commit), $log->write($commit));
		$this->assertCount(1, $this->provider()->list(CommitLog::PREFIX . '/'));
	}

	#endregion

	#region Reading

	#[Test]
	#[TestDox('reading a commit id that is not a digest is refused')]
	#[Group('strata/tree')]
	public function readRefusesBadId(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('must be a valid digest');

		$this->log()->read('nope');
	}

	#[Test]
	#[TestDox('a commit whose bytes were altered fails its address check')]
	#[Group('strata/tree')]
	public function alteredCommitFailsAddressCheck(): void
	{
		$log = $this->log();
		$id = $log->append(new Commit(Hash::of('anchor'), null, 1_000, 'original'));

		$log->flushCache();
		$this->provider()->put(
			Hash::key($id, CommitLog::PREFIX),
			'{"tree":"' . Hash::of('other') . '"}',
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('does not match its id');

		$log->read($id);
	}

	#[Test]
	#[TestDox('the commit in force at a moment is the newest one at or before it')]
	#[Group('strata/tree')]
	public function pointInTimeResolvesToNewestAtOrBefore(): void
	{
		$log = $this->log();
		$parent = null;
		$times = [1_000, 2_000, 3_000];

		foreach ($times as $i => $time) {
			$parent = $log->append(new Commit(Hash::of("tree $i"), $parent, $time));
		}

		$this->assertSame(1_000, $log->at(1_500)?->microtime);
		$this->assertSame(2_000, $log->at(2_000)?->microtime);
		$this->assertSame(3_000, $log->at(9_999)?->microtime);
		$this->assertNull($log->at(999), 'history does not reach that far back');
	}

	#[Test]
	#[TestDox('a point in time on an unwritten ref resolves to null')]
	#[Group('strata/tree')]
	public function pointInTimeOnEmptyHistoryIsNull(): void
	{
		$this->assertNull($this->log()->at(1_000));
	}

	#endregion

	#region Replay paths

	#[Test]
	#[TestDox('a replay path stops at the nearest base anchor and counts the commits to apply')]
	#[Group('strata/tree')]
	public function replayPathStopsAtNearestAnchor(): void
	{
		$log = $this->log();
		$root = $log->append((new Commit(Hash::of('anchor 0'), null, 1_000))->asBase());

		$parent = $root;
		for ($i = 1; $i <= 4; $i++) {
			$parent = $log->append(new Commit(Hash::of("tree $i"), $parent, 1_000 + $i));
		}

		$anchor = $log->append((new Commit(Hash::of('anchor 5'), $parent, 6_000))->asBase());

		$parent = $anchor;
		for ($i = 6; $i <= 8; $i++) {
			$parent = $log->append(new Commit(Hash::of("tree $i"), $parent, 1_000 + $i));
		}

		$path = $log->replayPath((string) $parent);

		$this->assertSame($anchor, $path['anchor'], 'the nearer anchor wins');
		$this->assertSame(3, $path['depth']);
		$this->assertCount(3, $path['path']);

		// the path is oldest first, so a replay applies it in order
		$this->assertSame(1_006, $log->read($path['path'][0])->microtime);
		$this->assertSame(1_008, $log->read($path['path'][2])->microtime);
	}

	#[Test]
	#[TestDox('a replay path at an anchor itself has depth zero')]
	#[Group('strata/tree')]
	public function replayPathAtAnchorIsEmpty(): void
	{
		$log = $this->log();
		$root = $log->append(new Commit(Hash::of('anchor'), null, 1_000));

		$path = $log->replayPath($root);

		$this->assertSame($root, $path['anchor']);
		$this->assertSame(0, $path['depth']);
		$this->assertSame([], $path['path']);
	}

	#endregion

	#region Anchors

	#[Test]
	#[TestDox('a full anchor lists every subject and ends the chain')]
	#[Group('strata/tree')]
	public function fullAnchorListsEverything(): void
	{
		$writer = new BaseWriter($this->provider());
		$reader = new BaseReader($this->provider());
		$subjects = $this->subjects(20);

		$address = $writer->full($subjects, 1_000);
		$manifest = $reader->read($address);

		$this->assertTrue(Hash::isValid($address));
		$this->assertTrue($manifest->full);
		$this->assertNull($manifest->parent);
		$this->assertSame(20, $manifest->count());
		$this->assertSame($subjects, $reader->resolve($address));
		$this->assertSame(1, $reader->depth($address));
	}

	#[Test]
	#[TestDox('a delta anchor stores only what changed, whatever the site holds')]
	#[Group('strata/tree')]
	public function deltaAnchorStoresOnlyTheChange(): void
	{
		$writer = new BaseWriter($this->provider());
		$reader = new BaseReader($this->provider());
		$first = $writer->full($this->subjects(50), 1_000);

		$changed = [
			'entity/subject:3' => ['frames' => [Hash::of('new 3')], 'size' => 999],
			'entity/subject:7' => ['frames' => [Hash::of('new 7')], 'size' => 888],
		];
		$second = $writer->delta($changed, $first, 2_000);

		$this->assertSame(
			2,
			$reader->read($second)->count(),
			'the anchor is the change, not the site',
		);
		$this->assertLessThan(
			strlen($reader->read($first)->encode()) / 10,
			strlen($reader->read($second)->encode()),
			'a delta anchor is a fraction of a full one',
		);

		$resolved = $reader->resolve($second);

		$this->assertCount(50, $resolved);
		$this->assertSame($changed['entity/subject:3'], $resolved['entity/subject:3']);
		$this->assertSame(
			$this->subjects(50)['entity/subject:4'],
			$resolved['entity/subject:4'],
			'a subject nothing touched keeps the value the chain behind it gave it',
		);
	}

	#[Test]
	#[TestDox('a newer entry wins over the one the chain behind it holds')]
	#[Group('strata/tree')]
	public function newerEntriesWin(): void
	{
		$writer = new BaseWriter($this->provider());
		$reader = new BaseReader($this->provider());
		$one = ['frames' => [Hash::of('one')], 'size' => 1];
		$two = ['frames' => [Hash::of('two')], 'size' => 2];
		$three = ['frames' => [Hash::of('three')], 'size' => 3];

		$first = $writer->full(['entity/a' => $one], 1_000);
		$second = $writer->delta(['entity/a' => $two], $first, 2_000);
		$third = $writer->delta(['entity/a' => $three], $second, 3_000);

		$this->assertSame($three, $reader->resolve($third)['entity/a']);
		$this->assertSame($two, $reader->resolve($second)['entity/a']);
		$this->assertSame($one, $reader->resolve($first)['entity/a']);
	}

	#[Test]
	#[TestDox('a recorded deletion removes the subject rather than leaving the old entry standing')]
	#[Group('strata/tree')]
	public function deletionsAreRecorded(): void
	{
		$writer = new BaseWriter($this->provider());
		$reader = new BaseReader($this->provider());
		$first = $writer->full($this->subjects(10), 1_000);
		$second = $writer->delta(['entity/subject:1' => null], $first, 2_000);

		$resolved = $reader->resolve($second);

		$this->assertCount(9, $resolved);
		$this->assertArrayNotHasKey('entity/subject:1', $resolved);
		$this->assertSame(['entity/subject:1'], $reader->read($second)->deletions());
	}

	#[Test]
	#[TestDox('a delta with nothing behind it is written full, since there is nothing to change')]
	#[Group('strata/tree')]
	public function firstAnchorIsAlwaysFull(): void
	{
		$writer = new BaseWriter($this->provider());
		$reader = new BaseReader($this->provider());
		$address = $writer->delta(
			['entity/a' => ['frames' => [Hash::of('a')], 'size' => 1], 'entity/b' => null],
			null,
			1_000,
		);

		$manifest = $reader->read($address);

		$this->assertTrue($manifest->full);
		$this->assertSame(
			[],
			$manifest->deletions(),
			'a full anchor has no parent entry to cancel',
		);
		$this->assertCount(1, $manifest->entries);
	}

	#[Test]
	#[TestDox('a full anchor that also names a parent is refused')]
	#[Group('strata/tree')]
	public function fullAnchorCannotHaveAParent(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot also change another one');

		new BaseManifest([], Hash::of('parent'), true, 1_000);
	}

	#[Test]
	#[TestDox('the chain stops at the full anchor rather than walking every anchor ever written')]
	#[Group('strata/tree')]
	public function chainStopsAtTheFullAnchor(): void
	{
		$writer = new BaseWriter($this->provider());
		$reader = new BaseReader($this->provider());
		$at = $writer->full(['entity/a' => ['frames' => [Hash::of('a')], 'size' => 1]], 1_000);

		for ($i = 0; $i < 5; $i++) {
			$at = $writer->delta(
				['entity/b' . $i => ['frames' => [Hash::of('b' . $i)], 'size' => 1]],
				$at,
				2_000 + $i,
			);
		}

		$fresh = $writer->full(['entity/c' => ['frames' => [Hash::of('c')], 'size' => 1]], 9_000);

		$this->assertSame(6, $reader->depth($at));
		$this->assertSame(1, $reader->depth($fresh), 'a full anchor resolves in one read');
		$this->assertCount(6, $reader->resolve($at));
	}

	#[Test]
	#[TestDox('every frame the chain names is collected, not only the surviving entries')]
	#[Group('strata/tree')]
	public function chainFramesIncludeReplacedEntries(): void
	{
		$writer = new BaseWriter($this->provider());
		$reader = new BaseReader($this->provider());
		$old = Hash::of('old');
		$new = Hash::of('new');

		$first = $writer->full(['entity/a' => ['frames' => [$old], 'size' => 1]], 1_000);
		$second = $writer->delta(['entity/a' => ['frames' => [$new], 'size' => 1]], $first, 2_000);

		$frames = $reader->frames($second);

		sort($frames);
		$expected = [$new, $old];
		sort($expected);

		$this->assertSame($expected, $frames, 'the older anchor is still a restore target');
		$this->assertSame([$new], $reader->read($second)->frames());
	}

	#[Test]
	#[TestDox('an anchor missing from the chain is reported rather than resolved around')]
	#[Group('strata/tree')]
	public function missingAnchorIsReported(): void
	{
		$writer = new BaseWriter($this->provider());
		$reader = new BaseReader($this->provider());
		$first = $writer->full(['entity/a' => ['frames' => [Hash::of('a')], 'size' => 1]], 1_000);
		$second = $writer->delta(['entity/b' => null], $first, 2_000);

		$this->provider()->delete([$writer->key($first)]);
		$reader->flushCache();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('could not be read');

		$reader->resolve($second);
	}

	#[Test]
	#[TestDox('an anchor whose bytes were altered fails to decode rather than half loading')]
	#[Group('strata/tree')]
	public function alteredAnchorFailsToDecode(): void
	{
		$writer = new BaseWriter($this->provider());
		$reader = new BaseReader($this->provider());
		$address = $writer->full($this->subjects(5), 1_000);

		$this->provider()->put($writer->key($address), '{"v":99}');
		$reader->flushCache();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Not a base manifest');

		$reader->read($address);
	}

	#[Test]
	#[TestDox('the same anchor written twice writes one object')]
	#[Group('strata/tree')]
	public function identicalAnchorsWriteOneObject(): void
	{
		$writer = new BaseWriter($this->provider());
		$subjects = $this->subjects(15);

		$first = $writer->full($subjects, 1_000);
		$objects = count($this->provider()->list(BaseManifest::PREFIX . '/')->objects);
		$second = $writer->full($subjects, 1_000);

		$this->assertSame($first, $second);
		$this->assertCount($objects, $this->provider()->list(BaseManifest::PREFIX . '/')->objects);
	}

	#[Test]
	#[TestDox('an anchor round trips through its stored form')]
	#[Group('strata/tree')]
	public function anchorRoundTrips(): void
	{
		$manifest = new BaseManifest(
			['entity/a' => ['frames' => [Hash::of('a')], 'size' => 7], 'entity/b' => null],
			Hash::of('parent'),
			false,
			4_242,
		);

		$decoded = BaseManifest::decode($manifest->encode());

		$this->assertSame($manifest->entries, $decoded->entries);
		$this->assertSame($manifest->parent, $decoded->parent);
		$this->assertSame($manifest->microtime, $decoded->microtime);
		$this->assertSame($manifest->address(), $decoded->address());
		$this->assertSame(7, $manifest->size());
	}

	#endregion
}
