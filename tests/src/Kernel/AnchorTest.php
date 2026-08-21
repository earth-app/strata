<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Engine;
use Drupal\strata\Tree\BaseManifest;
use Drupal\strata\Tree\SubjectIndex;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves a flush costs a fixed number of objects and the index belongs to an anchor.
 *
 * The claim the cost model rests on. Writing an index of every subject on every flush was measured
 * at 3,989,289 bytes to record a 201-byte change across 50,000 subjects, because the cost scaled with
 * how many subjects the site had rather than with how many changed. These tests assert the shape that
 * replaced it: four objects per flush whatever the site holds, an anchor on its own interval, and a
 * replay that starts from the anchor rather than from the root of history.
 */
class AnchorTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'field', 'key', 'strata'];

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->installEntitySchema('user');
		$this->installSchema('user', ['users_data']);

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

	private function subjects(): SubjectIndex
	{
		return $this->engine()->subjectIndex();
	}

	private function user(string $name): User
	{
		$user = User::create(['name' => $name, 'mail' => $name . '@example.com', 'status' => 1]);
		$user->save();

		return $user;
	}

	/**
	 * Object keys the store holds under a prefix.
	 *
	 * @return list<string>
	 *   The keys.
	 */
	private function keys(string $prefix = ''): array
	{
		return $this->engine()->provider()->list($prefix, null, 5000)->keys();
	}

	/**
	 * Sets the anchor interval and rebuilds the engine around it.
	 */
	private function withInterval(int $seconds, ?int $fullEvery = null): void
	{
		$settings = $this->config('strata.settings')->set('retention.base_interval', $seconds);

		if ($fullEvery !== null) {
			$settings->set('retention.base_full_every', $fullEvery);
		}

		$settings->save();
		$this->engine()->reset();
	}

	#region Cost Per Flush

	#[Test]
	#[TestDox('a flush between two anchors writes no index at all')]
	#[Group('strata/anchor')]
	public function flushBetweenAnchorsWritesNoIndex(): void
	{
		// an hour between anchors, so only the first flush writes one
		$this->withInterval(3600);

		$this->user('first');
		$this->engine()->flusher()->flush(true);

		$anchorsAfterFirst = $this->keys('bases/');

		$this->assertCount(1, $anchorsAfterFirst, 'the first flush has to anchor');

		$this->user('second');
		$this->engine()->flusher()->flush(true);

		$this->assertSame(
			$anchorsAfterFirst,
			$this->keys('bases/'),
			'the second flush anchors nothing',
		);
		$this->assertCount(2, $this->keys('commits/'));
		$this->assertCount(2, $this->keys('segments/'));
	}

	#[Test]
	#[TestDox('the objects one flush writes do not grow with how many subjects the site holds')]
	#[Group('strata/anchor')]
	public function flushCostIsIndependentOfSiteSize(): void
	{
		$this->withInterval(3600);

		for ($i = 0; $i < 12; $i++) {
			$this->user('bulk-' . $i);
		}

		$this->engine()->flusher()->flush(true);

		$before = count($this->keys());

		$this->user('one-more');
		$this->engine()->flusher()->flush(true);

		$written = count($this->keys()) - $before;

		// a pack, a segment manifest and a commit; the ref is rewritten in place
		$this->assertLessThanOrEqual(4, $written);
		$this->assertGreaterThanOrEqual(2, $written);
	}

	#[Test]
	#[TestDox('a commit between two anchors inherits the anchor its parent named')]
	#[Group('strata/anchor')]
	public function commitsInheritTheAnchor(): void
	{
		$this->withInterval(3600);

		$this->user('anchored');
		$this->engine()->flusher()->flush(true);
		$first = $this->engine()->commitLog()->head();

		$this->user('inheriting');
		$this->engine()->flusher()->flush(true);
		$second = $this->engine()->commitLog()->head();

		$this->assertNotNull($first);
		$this->assertNotNull($second);
		$this->assertTrue($first->base);
		$this->assertFalse($second->base);
		$this->assertSame($first->index, $second->index);
		$this->assertSame($first->chain, $second->chain);
		$this->assertSame($first->anchoredAt, $second->anchoredAt);
	}

	#endregion

	#region Anchoring

	#[Test]
	#[TestDox('an interval of zero anchors every flush, which is what an export wants')]
	#[Group('strata/anchor')]
	public function zeroIntervalAnchorsEveryFlush(): void
	{
		$this->withInterval(0, 4);

		$this->user('one');
		$this->engine()->flusher()->flush(true);
		$this->user('two');
		$this->engine()->flusher()->flush(true);

		$head = $this->engine()->commitLog()->head();

		$this->assertCount(2, $this->keys('bases/'));
		$this->assertNotNull($head);
		$this->assertTrue($head->base);
		$this->assertSame(2, $head->chain, 'the second anchor is a delta over the first');
		$this->assertSame(0, $this->engine()->commitLog()->replayPath($head->id())['depth']);
	}

	#[Test]
	#[TestDox('the first anchor is full and the ones after it are deltas')]
	#[Group('strata/anchor')]
	public function firstAnchorIsFullAndTheRestAreDeltas(): void
	{
		$this->withInterval(0, 4);
		$reader = $this->engine()->baseReader();

		$this->user('one');
		$this->engine()->flusher()->flush(true);
		$first = $this->engine()->commitLog()->head();

		$this->user('two');
		$this->engine()->flusher()->flush(true);
		$second = $this->engine()->commitLog()->head();

		$this->assertNotNull($first);
		$this->assertNotNull($second);
		$this->assertTrue($reader->read($first->index)->full);
		$this->assertFalse($reader->read($second->index)->full);
		$this->assertSame($first->index, $reader->read($second->index)->parent);
	}

	#[Test]
	#[TestDox('the chain is capped, so a full anchor is written again before it grows')]
	#[Group('strata/anchor')]
	public function chainIsCappedByAFullAnchor(): void
	{
		$this->withInterval(0, 2);
		$reader = $this->engine()->baseReader();
		$chains = [];

		for ($i = 0; $i < 5; $i++) {
			$this->user('capped-' . $i);
			$this->engine()->flusher()->flush(true);
			$head = $this->engine()->commitLog()->head();

			$this->assertNotNull($head);

			$chains[] = $head->chain;

			$this->assertSame($head->chain, $reader->depth($head->index));
		}

		$this->assertSame([1, 2, 1, 2, 1], $chains);
	}

	#[Test]
	#[TestDox('a full anchor lists every subject the site has ever held')]
	#[Group('strata/anchor')]
	public function fullAnchorCarriesTheWholeIndex(): void
	{
		$this->withInterval(0, 2);
		$reader = $this->engine()->baseReader();

		$this->user('kept-one');
		$this->engine()->flusher()->flush(true);
		$this->user('kept-two');
		$this->engine()->flusher()->flush(true);
		$this->user('kept-three');
		$this->engine()->flusher()->flush(true);

		$head = $this->engine()->commitLog()->head();

		$this->assertNotNull($head);

		$manifest = $reader->read($head->index);

		$this->assertTrue($manifest->full, 'the third anchor is full at a cap of two');
		$this->assertCount(3, $manifest->entries, 'it carries the subjects the deltas introduced');
		$this->assertSame(1, $reader->depth($head->index));
	}

	#endregion

	#region The Subject Index

	#[Test]
	#[
		TestDox(
			'a subject stays in the index after an anchor names it, so the next delta has a parent',
		),
	]
	#[Group('strata/anchor')]
	public function subjectsSurviveTheAnchorThatNamedThem(): void
	{
		$this->withInterval(3600);

		$anchored = $this->user('anchored');
		$this->engine()->flusher()->flush(true);

		$subject = 'entity/user:' . $anchored->id();

		$this->assertSame(1, $this->subjects()->count(), 'the anchor did not empty the index');
		$this->assertNotSame([], $this->subjects()->frames($subject));

		$this->user('later');
		$this->engine()->flusher()->flush(true);

		$this->assertSame(2, $this->subjects()->count());
	}

	#[Test]
	#[TestDox('an anchor names only what changed since the anchor before it')]
	#[Group('strata/anchor')]
	public function anchorNamesOnlyWhatChanged(): void
	{
		$this->withInterval(0, 8);

		$this->user('first');
		$this->engine()->flusher()->flush(true);
		$first = $this->engine()->commitLog()->head();

		$this->user('second');
		$this->engine()->flusher()->flush(true);
		$second = $this->engine()->commitLog()->head();

		$this->assertNotNull($first);
		$this->assertNotNull($second);
		$this->assertSame(2, $this->subjects()->count(), 'both subjects are still indexed');
		$this->assertSame(
			1,
			$this->engine()->baseReader()->read($second->index)->count(),
			'the second anchor names one subject, not both',
		);
	}

	#[Test]
	#[TestDox('a subject written twice holds one row, not two')]
	#[Group('strata/anchor')]
	public function repeatedWritesCollapseInTheIndex(): void
	{
		$this->withInterval(3600);

		$subject = $this->user('rewritten');
		$this->engine()->flusher()->flush(true);

		$subject->set('name', 'rewritten-again')->save();
		$this->engine()->flusher()->flush(true);

		$this->assertSame(1, $this->subjects()->count());
	}

	#[Test]
	#[TestDox('a deletion is carried into the anchor rather than leaving the old entry standing')]
	#[Group('strata/anchor')]
	public function deletionsReachTheAnchor(): void
	{
		$this->withInterval(0, 8);

		$doomed = $this->user('doomed');
		$subject = 'entity/user:' . $doomed->id();
		$this->engine()->flusher()->flush(true);

		$doomed->delete();
		$this->engine()->flusher()->flush(true);

		$head = $this->engine()->commitLog()->head();

		$this->assertNotNull($head);

		$reader = $this->engine()->baseReader();

		$this->assertContains($subject, $reader->read($head->index)->deletions());
		$this->assertArrayNotHasKey($subject, $reader->resolve($head->index));
	}

	#endregion

	#region Replay

	#[Test]
	#[TestDox('a replay starts at the anchor rather than walking to the root of history')]
	#[Group('strata/anchor')]
	public function replayStartsAtTheAnchor(): void
	{
		$this->withInterval(0, 16);

		$subject = $this->user('replayed');
		$this->engine()->flusher()->flush(true);

		$this->withInterval(3600);

		for ($i = 0; $i < 3; $i++) {
			$subject->set('name', 'replayed-' . $i)->save();
			$this->engine()->flusher()->flush(true);
		}

		$head = $this->engine()->commitLog()->head();

		$this->assertNotNull($head);

		$path = $this->engine()->commitLog()->replayPath($head->id());

		$this->assertSame(3, $path['depth'], 'three commits since the anchor');

		$replayed = $this->engine()
			->replayer()
			->materialize('entity/user:' . $subject->id(), $head->id());

		$this->assertSame('replayed-2', $replayed->fields['name'][0]['value'] ?? null);
	}

	#[Test]
	#[TestDox('the subjects a commit covers include one created after its anchor')]
	#[Group('strata/anchor')]
	public function subjectsIncludeWhatFollowedTheAnchor(): void
	{
		$this->withInterval(0, 16);

		$anchored = $this->user('in-the-anchor');
		$this->engine()->flusher()->flush(true);

		$this->withInterval(3600);

		$later = $this->user('after-the-anchor');
		$this->engine()->flusher()->flush(true);

		$head = $this->engine()->commitLog()->head();

		$this->assertNotNull($head);

		$subjects = $this->engine()->replayer()->subjectsAt($head->id());

		$this->assertContains('entity/user:' . $anchored->id(), $subjects);
		$this->assertContains('entity/user:' . $later->id(), $subjects);
	}

	#[Test]
	#[TestDox('a subject deleted after the anchor is not offered as a restore target')]
	#[Group('strata/anchor')]
	public function subjectsDropWhatWasDeletedAfterTheAnchor(): void
	{
		$this->withInterval(0, 16);

		$doomed = $this->user('deleted-later');
		$subject = 'entity/user:' . $doomed->id();
		$this->engine()->flusher()->flush(true);

		$this->withInterval(3600);

		$doomed->delete();
		$this->engine()->flusher()->flush(true);

		$head = $this->engine()->commitLog()->head();

		$this->assertNotNull($head);
		$this->assertNotContains($subject, $this->engine()->replayer()->subjectsAt($head->id()));
	}

	#endregion

	#region Verification

	#[Test]
	#[TestDox('a verify pass reads the anchor chain and reports it clean')]
	#[Group('strata/anchor')]
	public function verifyReadsTheChain(): void
	{
		$this->withInterval(0, 3);

		for ($i = 0; $i < 3; $i++) {
			$this->user('verified-' . $i);
			$this->engine()->flusher()->flush(true);
		}

		$report = $this->engine()->verifier()->verify();

		$this->assertTrue($report->isClean(), $report->summary());
		$this->assertGreaterThanOrEqual(3, $report->trees);
	}

	#[Test]
	#[TestDox('a deleted anchor is reported as missing rather than passing quietly')]
	#[Group('strata/anchor')]
	public function deletedAnchorIsReported(): void
	{
		$this->withInterval(0, 8);

		$this->user('anchored');
		$this->engine()->flusher()->flush(true);

		$head = $this->engine()->commitLog()->head();

		$this->assertNotNull($head);

		$this->engine()
			->provider()
			->delete([$this->engine()->baseWriter()->key($head->index)]);

		$report = $this->engine()->verifier()->verify();

		$this->assertFalse($report->isClean());
		$this->assertContains(
			'anchor.missing',
			array_map(static fn($finding): string => $finding->code, $report->findings),
		);
	}

	#[Test]
	#[TestDox('an anchor a ref reaches keeps its frames alive against a prune')]
	#[Group('strata/anchor')]
	public function anchorKeepsItsFramesAlive(): void
	{
		$this->withInterval(0, 8);

		$this->user('reachable');
		$this->engine()->flusher()->flush(true);

		$head = $this->engine()->commitLog()->head();
		$reachability = $this->engine()->reachability();

		$this->assertNotNull($head);
		$this->assertTrue($reachability->isComplete());
		$this->assertNotNull($reachability->treeReason($head->index));

		foreach ($this->engine()->baseReader()->frames($head->index) as $frame) {
			$this->assertNotNull($reachability->frameReason($frame));
		}
	}

	#[Test]
	#[TestDox('the anchor key sits under the shared prefix, since it describes one site')]
	#[Group('strata/anchor')]
	public function anchorsAreSiteScoped(): void
	{
		$this->withInterval(0, 8);

		$this->user('scoped');
		$this->engine()->flusher()->flush(true);

		$this->assertNotSame([], $this->keys(BaseManifest::PREFIX . '/'));
	}

	#endregion
}
