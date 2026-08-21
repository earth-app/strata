<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Compaction;

use Drupal\strata\Cas\FrameRecord;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Cas\MemoryFrameIndex;
use Drupal\strata\Compaction\Reachability;
use Drupal\strata\Storage\Plugin\Strata\Storage\LocalStorage;
use Drupal\strata\Tree\Commit;
use Drupal\strata\Tree\CommitLog;
use Drupal\strata\Tree\RefStore;
use Drupal\strata\Tree\BaseManifest;
use Drupal\strata\Tree\BaseReader;
use Drupal\strata\Tree\BaseWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Reachability::class)]
class ReachabilityTest extends TestCase
{
	/**
	 * Where the store under test lives.
	 */
	private string $root = '';

	/**
	 * The provider under test.
	 */
	private LocalStorage $provider;

	/**
	 * The index under test.
	 */
	private MemoryFrameIndex $index;

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->root = sys_get_temp_dir() . '/strata-reach-' . bin2hex(random_bytes(6));
		$this->provider = new LocalStorage($this->root);
		$this->index = new MemoryFrameIndex();
	}

	/**
	 * {@inheritdoc}
	 */
	protected function tearDown(): void
	{
		$this->removeDirectory($this->root);

		parent::tearDown();
	}

	private function removeDirectory(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}

		foreach (scandir($path) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$child = $path . '/' . $entry;
			is_dir($child) ? $this->removeDirectory($child) : @unlink($child);
		}

		@rmdir($path);
	}

	private function reachability(): Reachability
	{
		$refs = new RefStore($this->provider);

		return new Reachability(
			$this->index,
			new CommitLog($this->provider, $refs),
			$refs,
			new BaseReader($this->provider),
		);
	}

	/**
	 * Records a frame the index knows about, without storing its bytes.
	 *
	 * Reachability reads the index and the trees, never the frame objects, so the bytes are not
	 * needed to exercise it.
	 */
	private function frame(
		string $content,
		int $references = 0,
		?string $parent = null,
		int $depth = 0,
		?string $dictionary = null,
	): string {
		$hash = Hash::of($content);

		$this->index->record(
			new FrameRecord(
				$hash,
				strlen($content),
				strlen($content),
				'none',
				'none',
				$dictionary,
				null,
				0,
				$references,
				0,
				$parent,
				$depth,
			),
		);

		return $hash;
	}

	/**
	 * Writes a commit whose tree names one subject per frame map given.
	 *
	 * @param array<string, list<string>> $subjects
	 *   Subject path keyed to its frame map.
	 * @param string|null $parent
	 *   The commit this one builds on, or NULL for the root of history.
	 * @param bool $anchor
	 *   Whether a replay can stop at this commit.
	 *
	 * @return string
	 *   The commit id.
	 */
	private function commit(array $subjects, ?string $parent = null, bool $anchor = true): string
	{
		$anchors = new BaseWriter($this->provider);
		$shaped = [];

		foreach ($subjects as $path => $frames) {
			$shaped[$path] = ['frames' => $frames, 'size' => 100];
		}

		$log = new CommitLog($this->provider, new RefStore($this->provider));

		return $log->append(
			new Commit(
				$anchors->full($shaped, 1_700_000_000_000_000),
				$parent,
				1_700_000_000_000_000,
				'test',
				null,
				count($shaped),
				100,
				100,
				0,
				$anchor,
			),
		);
	}

	#region Class One: Commits And Bases

	#[Test]
	#[TestDox('a frame under a tree a ref reaches is live however few references it has')]
	#[Group('strata/compaction')]
	public function reachableFrameIsLive(): void
	{
		$frame = $this->frame('reachable', 0);
		$this->commit(['entity/user:1' => [$frame]]);

		$reachability = $this->reachability();

		$this->assertSame('a reachable commit needs it', $reachability->frameReason($frame));
		$this->assertFalse($reachability->isFrameCollectable($frame));
	}

	#[Test]
	#[TestDox('a frame nothing reaches and nothing references is collectable')]
	#[Group('strata/compaction')]
	public function unreachableUnreferencedFrameIsCollectable(): void
	{
		$frame = $this->frame('orphan', 0);

		$this->assertNull($this->reachability()->frameReason($frame));
		$this->assertTrue($this->reachability()->isFrameCollectable($frame));
	}

	#[Test]
	#[TestDox('a frame with references left is held even when no commit reaches it')]
	#[Group('strata/compaction')]
	public function referencedFrameIsHeld(): void
	{
		$frame = $this->frame('referenced', 3);

		$this->assertSame(
			'3 references still point at it',
			$this->reachability()->frameReason($frame),
		);
	}

	#[Test]
	#[TestDox('the walk reports what it reached, so a receipt can say how much it checked')]
	#[Group('strata/compaction')]
	public function walkReportsWhatItReached(): void
	{
		$first = $this->frame('one');
		$second = $this->frame('two');
		$root = $this->commit(['entity/user:1' => [$first]]);
		$this->commit(['entity/user:2' => [$second]], $root, false);

		$statistics = $this->reachability()->statistics();

		$this->assertSame(2, $statistics['commits']);
		$this->assertSame(2, $statistics['frames']);
		$this->assertGreaterThan(0, $statistics['trees']);
		$this->assertSame(0, $statistics['unreadable']);
	}

	#[Test]
	#[TestDox('a commit a ref reaches is live and one nothing points at is not')]
	#[Group('strata/compaction')]
	public function commitReachability(): void
	{
		$id = $this->commit(['entity/user:1' => [$this->frame('c')]]);
		$reachability = $this->reachability();

		$this->assertSame('a ref reaches it', $reachability->commitReason($id));
		$this->assertNull($reachability->commitReason(Hash::of('never written')));
	}

	#[Test]
	#[TestDox('a tree node a reachable commit points at is live')]
	#[Group('strata/compaction')]
	public function treeReachability(): void
	{
		$this->commit(['entity/user:1' => [$this->frame('t')]]);
		$reachability = $this->reachability();
		$head = (new CommitLog($this->provider, new RefStore($this->provider)))->head();

		$this->assertNotNull($head);
		$this->assertSame(
			'a reachable commit points at it',
			$reachability->treeReason($head->index),
		);
		$this->assertNull($reachability->treeReason(Hash::of('never written')));
	}

	#endregion

	#region Class Two: Dictionaries

	#[Test]
	#[TestDox('a dictionary a live frame was compressed against survives')]
	#[Group('strata/compaction')]
	public function liveDictionarySurvives(): void
	{
		$frame = $this->frame('compressed', 0, null, 0, 'entity/3');
		$this->commit(['entity/user:1' => [$frame]]);

		$this->assertSame(
			'a live frame was compressed against it',
			$this->reachability()->dictionaryReason('entity/3'),
		);
	}

	#[Test]
	#[TestDox('a dictionary a referenced frame needs survives even outside reachable history')]
	#[Group('strata/compaction')]
	public function referencedDictionarySurvives(): void
	{
		$this->frame('unreachable but referenced', 2, null, 0, 'config/1');

		$this->assertSame(
			'1 referenced frames were compressed against it',
			$this->reachability()->dictionaryReason('config/1'),
		);
	}

	#[Test]
	#[TestDox('a dictionary nothing was compressed against is collectable')]
	#[Group('strata/compaction')]
	public function unusedDictionaryIsCollectable(): void
	{
		$this->frame('no dictionary', 5);

		$this->assertNull($this->reachability()->dictionaryReason('table/9'));
	}

	#[Test]
	#[TestDox('a dictionary an orphaned frame needs is not held by that frame alone')]
	#[Group('strata/compaction')]
	public function orphanedFrameDoesNotHoldItsDictionary(): void
	{
		$this->frame('orphan with a dictionary', 0, null, 0, 'state/2');

		$this->assertNull($this->reachability()->dictionaryReason('state/2'));
	}

	#endregion

	#region Class Three: Delta Parents

	#[Test]
	#[
		TestDox(
			'a delta parent nothing else references survives because a live frame decodes against it',
		),
	]
	#[Group('strata/compaction')]
	public function deltaParentOfLiveFrameSurvives(): void
	{
		$parent = $this->frame('version one', 0);
		$child = $this->frame('version two', 0, $parent, 1);
		$this->commit(['entity/user:1' => [$child]]);

		$reachability = $this->reachability();

		$this->assertSame('a reachable commit needs it', $reachability->frameReason($parent));
		$this->assertFalse(
			$reachability->isFrameCollectable($parent),
			'collecting the parent would make the child decode to garbage',
		);
	}

	#[Test]
	#[TestDox('a whole delta chain is held by one live frame at the end of it')]
	#[Group('strata/compaction')]
	public function wholeChainIsHeld(): void
	{
		$chain = [];
		$parent = null;

		for ($depth = 0; $depth < 8; $depth++) {
			$parent = $this->frame('version ' . $depth, 0, $parent, $depth === 0 ? 0 : $depth);
			$chain[] = $parent;
		}

		$this->commit(['entity/user:1' => [$chain[7]]]);
		$reachability = $this->reachability();

		foreach ($chain as $depth => $frame) {
			$this->assertFalse(
				$reachability->isFrameCollectable($frame),
				sprintf('link %d of the chain is held', $depth),
			);
		}
	}

	#[Test]
	#[TestDox('a delta parent whose child is also dead is collectable, chain and all')]
	#[Group('strata/compaction')]
	public function deadChainIsCollectable(): void
	{
		$parent = $this->frame('dead one', 0);
		$child = $this->frame('dead two', 0, $parent, 1);

		$reachability = $this->reachability();

		$this->assertTrue($reachability->isFrameCollectable($parent));
		$this->assertTrue($reachability->isFrameCollectable($child));
	}

	#[Test]
	#[TestDox('a delta parent is held by a child that is merely referenced, not reachable')]
	#[Group('strata/compaction')]
	public function referencedChildHoldsItsParent(): void
	{
		$parent = $this->frame('referenced parent', 0);
		$child = $this->frame('referenced child', 1, $parent, 1);

		$this->assertSame(
			sprintf('delta frame %s decodes against it', Hash::abbreviate($child)),
			$this->reachability()->frameReason($parent),
		);
	}

	#[Test]
	#[TestDox('a parent with more dependents than the limit is held without enumerating them')]
	#[Group('strata/compaction')]
	public function widelyDependedOnParentIsHeld(): void
	{
		$parent = $this->frame('popular parent', 0);

		for ($i = 0; $i < Reachability::DEPENDENT_LIMIT + 5; $i++) {
			$this->frame('child ' . $i, 0, $parent, 1);
		}

		$this->assertSame(
			sprintf('at least %d delta frames decode against it', Reachability::DEPENDENT_LIMIT),
			$this->reachability()->frameReason($parent),
		);
	}

	#endregion

	#region Refusing To Guess

	#[Test]
	#[TestDox('a walk that cannot read an anchor is incomplete, so a prune must refuse')]
	#[Group('strata/compaction')]
	public function unreadableTreeMakesTheWalkIncomplete(): void
	{
		$this->commit(['entity/user:1' => [$this->frame('readable')]]);

		$head = (new CommitLog($this->provider, new RefStore($this->provider)))->head();
		$this->assertNotNull($head);
		$this->provider->delete([Hash::key($head->index, BaseManifest::PREFIX)]);

		$reachability = $this->reachability();

		$this->assertFalse($reachability->isComplete());
		$this->assertCount(1, $reachability->unreadable());
		$this->assertStringContainsString(
			Hash::abbreviate($head->index),
			$reachability->unreadable()[0],
		);
	}

	#[Test]
	#[TestDox('a walk that cannot read a commit is incomplete')]
	#[Group('strata/compaction')]
	public function unreadableCommitMakesTheWalkIncomplete(): void
	{
		$id = $this->commit(['entity/user:1' => [$this->frame('readable')]]);
		$this->provider->delete([Hash::key($id, CommitLog::PREFIX)]);

		$reachability = $this->reachability();

		$this->assertFalse($reachability->isComplete());
		$this->assertStringContainsString('commit', $reachability->unreadable()[0]);
	}

	#[Test]
	#[TestDox('a store with no refs is a complete walk that reaches nothing')]
	#[Group('strata/compaction')]
	public function emptyStoreWalksCleanly(): void
	{
		$reachability = $this->reachability();

		$this->assertTrue($reachability->isComplete());
		$this->assertSame(0, $reachability->statistics()['commits']);
	}

	#endregion

	#region Caching

	#[Test]
	#[TestDox('the walk runs once and is reused, and a refresh makes it run again')]
	#[Group('strata/compaction')]
	public function walkIsCachedUntilRefreshed(): void
	{
		$first = $this->frame('before');
		$root = $this->commit(['entity/user:1' => [$first]]);

		$reachability = $this->reachability();
		$this->assertSame(1, $reachability->statistics()['commits']);

		$second = $this->frame('after');
		$this->commit(['entity/user:2' => [$second]], $root, false);

		$this->assertSame(1, $reachability->statistics()['commits'], 'the snapshot is reused');

		$reachability->refresh();

		$this->assertSame(2, $reachability->statistics()['commits']);
	}

	#endregion

	#region Candidates

	#[Test]
	#[TestDox('collectable frames are the orphans that survive all three classes')]
	#[Group('strata/compaction')]
	public function collectableFramesPassAllThree(): void
	{
		$reachable = $this->frame('reachable', 0);
		$parent = $this->frame('held parent', 0);
		$this->frame('held child', 1, $parent, 1);
		$dead = $this->frame('genuinely dead', 0);
		$this->commit(['entity/user:1' => [$reachable]]);

		$collectable = array_map(
			static fn(FrameRecord $record): string => $record->hash,
			$this->reachability()->collectableFrames(),
		);

		$this->assertContains($dead, $collectable);
		$this->assertNotContains($reachable, $collectable);
		$this->assertNotContains($parent, $collectable);
	}

	#[Test]
	#[TestDox('the candidate list is bounded, so a prune fits in a cron window')]
	#[Group('strata/compaction')]
	public function candidateListIsBounded(): void
	{
		for ($i = 0; $i < 20; $i++) {
			$this->frame('dead ' . $i, 0);
		}

		$this->assertCount(5, $this->reachability()->collectableFrames(5));
	}

	#endregion
}
