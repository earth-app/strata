<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Branch\Branch;
use Drupal\strata\Branch\MergeOutcome;
use Drupal\strata\Branch\MergeStrategy;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Engine;
use Drupal\strata\Journal\JournalInterface;
use Drupal\strata\Tree\Commit;
use Drupal\user\Entity\User;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

/**
 * Proves a configuration branch diverges, merges back, and refuses what it cannot carry.
 *
 * The lane exists because none of this can be checked without real objects in a real store. A branch
 * is a ref plus a metadata object, a merge base is a walk over the local commit index, the values on
 * either side come out of a replay, and the write goes through the ordinary logical restore into a
 * live configuration factory. Every one of those is a seam between two subsystems, and the mistakes
 * worth catching live in the seams rather than in the arithmetic.
 *
 * Three claims get the most attention. That two edits to different keys of one object both survive,
 * because that is the whole reason a whole-value merge is worth having. That two edits to the same key
 * stop the merge and name the key, because the alternative is silently discarding somebody's work. And
 * that a commit written before merging existed still serializes to exactly the same bytes, because a
 * commit is addressed by those bytes and changing them would orphan every commit in every store.
 */
class BranchTest extends StrataKernelTestBase
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
		$this->journal()->trim(PHP_INT_MAX);
	}

	#region Branches

	#[Test]
	#[TestDox('a branch is a ref under heads, and it starts where it was cut from')]
	#[Group('strata/branch')]
	public function aBranchIsARefUnderHeads(): void
	{
		$fork = $this->site(['name' => 'Base']);
		$branch = $this->engine()->merger()->branch('release-12');

		$this->assertSame($fork, $branch->forkedFrom);
		$this->assertSame($fork, $branch->tip);
		$this->assertTrue($branch->isUnchanged());
		$this->assertSame($fork, $this->engine()->refStore()->read('heads/release-12'));
		$this->assertSame(
			$fork,
			$this->engine()->refStore()->read(),
			'cutting a branch leaves the trunk exactly where it was',
		);
	}

	#[Test]
	#[TestDox('a branch that already exists is refused rather than moved')]
	#[Group('strata/branch')]
	public function anExistingBranchIsRefused(): void
	{
		$this->site(['name' => 'Base']);
		$this->engine()->merger()->branch('release-12');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('exists already');

		$this->engine()->merger()->branch('release-12');
	}

	#[Test]
	#[TestDox('the trunk cannot be created and cannot be deleted')]
	#[Group('strata/branch')]
	public function theTrunkIsNotDeletable(): void
	{
		$this->site(['name' => 'Base']);

		try {
			$this->engine()->branchStore()->create('main', Hash::of('x'));
			$this->fail('creating the trunk is refused');
		} catch (RuntimeException $error) {
			$this->assertStringContainsString('the trunk', $error->getMessage());
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('unreachable');

		$this->engine()->merger()->remove('main');
	}

	#[Test]
	#[TestDox('deleting a branch removes the name and leaves its commits in the store')]
	#[Group('strata/branch')]
	public function deletingABranchKeepsItsCommits(): void
	{
		$this->site(['name' => 'Base']);
		$this->engine()->merger()->branch('release-12');
		$tip = $this->onBranch('release-12', ['slogan' => 'Go outside']);

		$this->assertTrue($this->engine()->merger()->remove('release-12'));
		$this->assertNull($this->engine()->refStore()->read('heads/release-12'));
		$this->assertNull($this->engine()->branchIndex()->get('release-12'));
		$this->assertTrue(
			$this->engine()->commitLog()->exists($tip),
			'the ref is a name; a prune decides about the commits',
		);
	}

	#[Test]
	#[TestDox('the branch index is derived, so a reindex rebuilds it from the bucket')]
	#[Group('strata/branch')]
	public function theBranchIndexIsRebuiltByAReindex(): void
	{
		$fork = $this->site(['name' => 'Base']);
		$this->engine()->merger()->branch('release-12');

		$this->assertNotNull($this->engine()->branchIndex()->get('release-12'));

		$this->engine()->branchIndex()->clear();

		$this->assertSame(0, $this->engine()->branchIndex()->count());

		$report = $this->engine()->reindexer()->reindex();
		$row = $this->engine()->branchIndex()->get('release-12');

		$this->assertGreaterThan(0, $report->branches);
		$this->assertNotNull($row);
		$this->assertSame($fork, (string) $row['forked_from']);
		$this->assertSame($fork, (string) $row['tip']);
	}

	#[Test]
	#[TestDox('a branch listing names the trunk alongside everything cut from it')]
	#[Group('strata/branch')]
	public function theListingIncludesTheTrunk(): void
	{
		$this->site(['name' => 'Base']);
		$this->engine()->merger()->branch('release-12');

		$branches = $this->engine()->merger()->list();

		$this->assertArrayHasKey(Branch::TRUNK, $branches);
		$this->assertArrayHasKey('release-12', $branches);
		$this->assertTrue($branches[Branch::TRUNK]->isTrunk());
	}

	#endregion

	#region Merging

	#[Test]
	#[TestDox('two edits to different keys of one object merge cleanly onto the live site')]
	#[Group('strata/branch')]
	public function differentKeysMergeCleanly(): void
	{
		$this->site(['name' => 'Base', 'slogan' => 'Base slogan']);
		$this->engine()->merger()->branch('release-12');

		$this->onBranch('release-12', ['name' => 'Base', 'slogan' => 'Go outside']);
		$this->site(['name' => 'Earth App', 'slogan' => 'Base slogan']);

		$plan = $this->engine()->merger()->plan('release-12');

		$this->assertSame([], $plan->problems, implode('; ', $plan->problems));
		$this->assertArrayHasKey('system.site', $plan->entries);
		$this->assertSame(MergeOutcome::MERGED, $plan->entries['system.site']->outcome);
		$this->assertTrue($plan->isApplicable());

		$result = $this->engine()->merger()->apply($plan, true);

		$this->assertTrue($result->isComplete(), (string) $result->refused);
		$this->assertSame(['system.site'], $result->written);
		$this->assertSame('Earth App', $this->config('system.site')->get('name'));
		$this->assertSame('Go outside', $this->config('system.site')->get('slogan'));
		$this->assertNotNull($result->snapshot, 'the snapshot is forced and cannot be turned off');
	}

	#[Test]
	#[TestDox('a merge writes a commit carrying both the trunk tip and the branch tip')]
	#[Group('strata/branch')]
	public function aMergeCommitCarriesTwoParents(): void
	{
		$this->site(['name' => 'Base', 'slogan' => 'Base slogan']);
		$this->engine()->merger()->branch('release-12');

		$theirs = $this->onBranch('release-12', ['name' => 'Base', 'slogan' => 'Go outside']);
		$this->site(['name' => 'Earth App', 'slogan' => 'Base slogan']);

		$result = $this->engine()->merger()->merge('release-12');
		$head = $this->engine()->commitLog()->head();

		$this->assertNotNull($result->commit);
		$this->assertSame($result->commit, $head?->id());
		$this->assertTrue($head?->isMerge());
		$this->assertSame($theirs, $head?->merge, 'the second parent is the branch tip');
		$this->assertNotSame($theirs, $head?->parent, 'the first parent is the trunk');
		$this->assertCount(2, $head?->parents() ?? []);

		$row = $this->engine()->commitIndex()->get((string) $result->commit);

		$this->assertSame($theirs, (string) ($row['merge_parent'] ?? ''));
	}

	#[Test]
	#[TestDox('a merge commit makes the branch reachable from the trunk')]
	#[Group('strata/branch')]
	public function aMergeCommitJoinsTheTwoHistories(): void
	{
		$this->site(['name' => 'Base', 'slogan' => 'Base slogan']);
		$this->engine()->merger()->branch('release-12');

		$theirs = $this->onBranch('release-12', ['name' => 'Base', 'slogan' => 'Go outside']);
		$this->site(['name' => 'Earth App', 'slogan' => 'Base slogan']);
		$this->engine()->merger()->merge('release-12');

		$again = $this->engine()->merger()->plan('release-12');

		$this->assertSame($theirs, $again->base, 'the branch is now behind the trunk');
		$this->assertTrue($again->isEmpty());
		$this->assertSame(
			'the branch holds nothing the target does not already have',
			$again->refusal(),
		);
	}

	#[Test]
	#[TestDox('a branch nothing was flushed onto has nothing to merge')]
	#[Group('strata/branch')]
	public function anUntouchedBranchMergesNothing(): void
	{
		$this->site(['name' => 'Base']);
		$this->engine()->merger()->branch('release-12');

		$plan = $this->engine()->merger()->plan('release-12');

		$this->assertTrue($plan->isEmpty());
		$this->assertFalse($plan->isApplicable());
		$this->assertTrue($this->engine()->merger()->apply($plan, true)->wasRefused());
	}

	#[Test]
	#[TestDox('merging a branch that does not exist refuses and names it')]
	#[Group('strata/branch')]
	public function anUnknownBranchRefuses(): void
	{
		$this->site(['name' => 'Base']);

		$plan = $this->engine()->merger()->plan('nowhere');

		$this->assertFalse($plan->isApplicable());
		$this->assertStringContainsString('nowhere', (string) $plan->refusal());
	}

	#endregion

	#region Limits

	#[Test]
	#[TestDox('a walk limit of one refuses to compare rather than reading the whole history')]
	#[Group('strata/branch')]
	public function theWalkLimitIsConfigured(): void
	{
		$this->site(['name' => 'Base', 'slogan' => 'Base slogan']);
		$this->engine()->merger()->branch('release-12');

		// two commits on the branch, so a limit of one is passed rather than exactly reached
		$this->onBranch('release-12', ['name' => 'Base', 'slogan' => 'Go outside']);
		$this->onBranch('release-12', ['name' => 'Base', 'slogan' => 'Go outside today']);
		$this->site(['name' => 'Earth App', 'slogan' => 'Base slogan']);

		$this->config('strata.settings')->set('merge.walk_limit', 1)->save();
		$this->engine()->reset();

		$plan = $this->engine()->merger()->plan('release-12');

		$this->assertFalse($plan->isApplicable());
		$this->assertStringContainsString(
			'More than 1 commits separate',
			(string) $plan->refusal(),
		);
	}

	#[Test]
	#[TestDox('a base ceiling of one refuses to look for a base rather than walking on')]
	#[Group('strata/branch')]
	public function theBaseCeilingIsConfigured(): void
	{
		$this->site(['name' => 'Base', 'slogan' => 'Base slogan']);
		$this->engine()->merger()->branch('release-12');

		$theirs = $this->onBranch('release-12', ['name' => 'Base', 'slogan' => 'Go outside']);
		$this->site(['name' => 'Earth App', 'slogan' => 'Base slogan']);
		$ours = (string) $this->head();

		$this->config('strata.settings')->set('merge.base_ceiling', 1)->save();
		$this->engine()->reset();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('is longer than 1 commits');

		$this->engine()->mergeBase()->find($ours, $theirs);
	}

	#endregion

	#region Conflicts

	#[Test]
	#[
		TestDox(
			'the same key changed on both sides refuses the merge and names the object and the key',
		),
	]
	#[Group('strata/branch')]
	public function theSameKeyOnBothSidesRefuses(): void
	{
		$this->site(['name' => 'Base']);
		$this->engine()->merger()->branch('release-12');

		$this->onBranch('release-12', ['name' => 'Branch Name']);
		$this->site(['name' => 'Trunk Name']);

		$plan = $this->engine()->merger()->plan('release-12');
		$entry = $plan->entries['system.site'] ?? null;

		$this->assertNotNull($entry);
		$this->assertSame(MergeOutcome::CONFLICT, $entry->outcome);
		$this->assertTrue($entry->isBlocking());
		$this->assertArrayHasKey('name', $entry->conflicts);
		$this->assertSame('Trunk Name', $entry->conflicts['name']['ours']);
		$this->assertSame('Branch Name', $entry->conflicts['name']['theirs']);

		$refusal = (string) $plan->refusal();

		$this->assertStringContainsString('system.site', $refusal);
		$this->assertStringContainsString('name', $refusal);
		$this->assertFalse($plan->isApplicable());

		$result = $this->engine()->merger()->apply($plan, true);

		$this->assertTrue($result->wasRefused());
		$this->assertSame(
			'Trunk Name',
			$this->config('system.site')->get('name'),
			'a refused merge writes nothing at all',
		);
	}

	#[Test]
	#[TestDox('naming a strategy resolves the collision and writes the side it named')]
	#[Group('strata/branch')]
	public function aNamedStrategyResolvesTheCollision(): void
	{
		$this->site(['name' => 'Base']);
		$this->engine()->merger()->branch('release-12');

		$this->onBranch('release-12', ['name' => 'Branch Name']);
		$this->site(['name' => 'Trunk Name']);

		$result = $this->engine()->merger()->merge('release-12', MergeStrategy::THEIRS);

		$this->assertTrue($result->isComplete(), (string) $result->refused);
		$this->assertSame('Branch Name', $this->config('system.site')->get('name'));
		$this->assertSame(
			MergeOutcome::CONFLICT,
			$result->plan->entries['system.site']->outcome,
			'a resolved conflict is still reported as one',
		);
	}

	#[Test]
	#[TestDox('a dry run prints the same plan and writes nothing')]
	#[Group('strata/branch')]
	public function aDryRunWritesNothing(): void
	{
		$this->site(['name' => 'Base', 'slogan' => 'Base slogan']);
		$this->engine()->merger()->branch('release-12');

		$this->onBranch('release-12', ['name' => 'Base', 'slogan' => 'Go outside']);
		$this->site(['name' => 'Earth App', 'slogan' => 'Base slogan']);

		$head = $this->engine()->refStore()->read();
		$result = $this->engine()->merger()->merge('release-12', MergeStrategy::REFUSE, false);

		$this->assertFalse($result->applied);
		$this->assertNull($result->commit);
		$this->assertSame(['system.site'], $result->written, 'the plan says what it would write');
		$this->assertSame('Base slogan', $this->config('system.site')->get('slogan'));
		$this->assertSame($head, $this->engine()->refStore()->read(), 'the trunk did not move');
	}

	#endregion

	#region Realms

	#[Test]
	#[TestDox('a branch that received a non-config change refuses the merge and names the realm')]
	#[Group('strata/branch')]
	public function aNonConfigChangeOnABranchIsRefusedByName(): void
	{
		$this->site(['name' => 'Base']);
		$this->engine()->merger()->branch('release-12');

		User::create(['name' => 'grace', 'mail' => 'grace@example.com', 'status' => 1])->save();
		$this->engine()->flusher()->flush(true, 'heads/release-12');

		$plan = $this->engine()->merger()->plan('release-12');
		$refusal = (string) $plan->refusal();

		$this->assertNotSame([], $plan->problems);
		$this->assertStringContainsString('entity', $refusal);
		$this->assertStringContainsString('entity/user:', $refusal);
		$this->assertStringContainsString('captured as deltas', $refusal);
		$this->assertFalse($plan->isApplicable());
		$this->assertTrue($this->engine()->merger()->apply($plan, true)->wasRefused());
	}

	#[Test]
	#[TestDox('a non-config change on the trunk is ordinary and does not block a merge')]
	#[Group('strata/branch')]
	public function aNonConfigChangeOnTheTrunkIsFine(): void
	{
		$this->site(['name' => 'Base', 'slogan' => 'Base slogan']);
		$this->engine()->merger()->branch('release-12');

		$this->onBranch('release-12', ['name' => 'Base', 'slogan' => 'Go outside']);

		User::create(['name' => 'ada', 'mail' => 'ada@example.com', 'status' => 1])->save();
		$this->site(['name' => 'Base', 'slogan' => 'Base slogan']);

		$plan = $this->engine()->merger()->plan('release-12');

		$this->assertSame([], $plan->problems, implode('; ', $plan->problems));
		$this->assertTrue($plan->isApplicable());
	}

	#endregion

	#region Commit Compatibility

	#[Test]
	#[TestDox('a single-parent commit serializes to the bytes it always did, with no merge key')]
	#[Group('strata/branch')]
	public function aSingleParentCommitRoundTripsUnchanged(): void
	{
		$index = Hash::of('anchor');
		$parent = Hash::of('parent');
		$commit = new Commit($index, $parent, 1_755_000_000_000_000, 'first capture', 7, 3, 90, 30);

		// the exact document this commit produced before a second parent existed
		$expected = (string) json_encode([
			'index' => $index,
			'parent' => $parent,
			'microtime' => 1_755_000_000_000_000,
			'label' => 'first capture',
			'actor' => 7,
			'operations' => 3,
			'rawBytes' => 90,
			'storedBytes' => 30,
			'level' => 0,
			'base' => false,
			'chain' => 0,
			'anchoredAt' => 0,
			'metadata' => [],
		]);

		$this->assertSame($expected, (string) json_encode($commit));
		$this->assertSame(Hash::of($expected), $commit->id());
		$this->assertArrayNotHasKey('merge', $commit->jsonSerialize());
		$this->assertNull($commit->merge);
		$this->assertFalse($commit->isMerge());
		$this->assertSame([$parent], $commit->parents());
		$this->assertEquals($commit, Commit::fromArray($commit->jsonSerialize()));
	}

	#[Test]
	#[TestDox('a merge commit round-trips through the store and keeps both parents')]
	#[Group('strata/branch')]
	public function aMergeCommitRoundTripsThroughTheStore(): void
	{
		$log = $this->engine()->commitLog();
		$first = $log->append(new Commit(Hash::of('anchor'), null, 1_000, 'root'));
		$second = $log->append(new Commit(Hash::of('anchor'), $first, 2_000, 'trunk'));
		$side = $log->write(new Commit(Hash::of('anchor'), $first, 2_500, 'branch'));

		$merge = new Commit(
			Hash::of('anchor'),
			$second,
			3_000,
			'merged release-12',
			null,
			0,
			0,
			0,
			0,
			false,
			0,
			0,
			[],
			$side,
		);
		$id = $log->append($merge);

		$log->flushCache();
		$read = $log->read($id);

		$this->assertEquals($merge, $read);
		$this->assertSame($side, $read->merge);
		$this->assertSame([$second, $side], $read->parents());
		$this->assertArrayHasKey('merge', $read->jsonSerialize());
	}

	#[Test]
	#[TestDox('a commit naming a second parent but no first is refused, since it joins nothing')]
	#[Group('strata/branch')]
	public function aRootCannotBeAMergeCommit(): void
	{
		$side = Hash::of('side');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot be the root');

		new Commit(Hash::of('anchor'), null, 1_000, '', null, 0, 0, 0, 0, false, 0, 0, [], $side);
	}

	#[Test]
	#[TestDox('a merged branch stays reachable after its ref is deleted, so a prune keeps it')]
	#[Group('strata/branch')]
	public function aMergedBranchSurvivesItsRef(): void
	{
		$this->site(['name' => 'Base', 'slogan' => 'Base slogan']);
		$this->engine()->merger()->branch('release-12');

		$theirs = $this->onBranch('release-12', ['name' => 'Base', 'slogan' => 'Go outside']);
		$this->site(['name' => 'Earth App', 'slogan' => 'Base slogan']);
		$this->engine()->merger()->merge('release-12');
		$this->engine()->merger()->remove('release-12');

		$reachability = $this->engine()->reachability();

		$this->assertTrue($reachability->isComplete(), implode('; ', $reachability->unreadable()));
		$this->assertNotNull(
			$reachability->commitReason($theirs),
			'the trunk names the branch tip as a second parent, so it is not collectable',
		);
	}

	#[Test]
	#[TestDox('an unmerged branch stops holding its commits the moment its ref is deleted')]
	#[Group('strata/branch')]
	public function anUnmergedBranchStopsHoldingItsCommits(): void
	{
		$this->site(['name' => 'Base', 'slogan' => 'Base slogan']);
		$this->engine()->merger()->branch('release-12');

		$theirs = $this->onBranch('release-12', ['name' => 'Base', 'slogan' => 'Go outside']);

		$this->assertNotNull(
			$this->engine()->reachability()->commitReason($theirs),
			'its own ref reaches it while the branch exists',
		);

		$this->engine()->merger()->remove('release-12');

		$this->assertNull(
			$this->engine()->reachability()->commitReason($theirs),
			'nothing names it once the ref is gone, which is what makes a merge commit worth writing',
		);
	}

	#[Test]
	#[TestDox('a reindex counts the segments a merged branch contributed')]
	#[Group('strata/branch')]
	public function aReindexCountsTheMergedBranch(): void
	{
		$this->site(['name' => 'Base', 'slogan' => 'Base slogan']);
		$this->engine()->merger()->branch('release-12');

		$this->onBranch('release-12', ['name' => 'Base', 'slogan' => 'Go outside']);
		$this->site(['name' => 'Earth App', 'slogan' => 'Base slogan']);
		$this->engine()->merger()->merge('release-12');
		$this->engine()->merger()->remove('release-12');

		$frames = $this->container->get('strata.frame_index');
		$before = [];

		foreach ($this->engine()->segmentReader()->all() as $manifest) {
			foreach ($manifest->frames() as $hash) {
				$before[$hash] = $frames->get($hash)?->references;
			}
		}

		$this->assertNotEmpty($before);

		$frames->clear();
		$this->container->get('strata.commit_index')->clear();
		$this->engine()->reindexer()->reindex();

		foreach ($before as $hash => $references) {
			$this->assertSame(
				$references,
				$frames->get((string) $hash)?->references,
				sprintf('frame %s came back with the count the flush attributed', $hash),
			);
		}

		$this->assertSame(0, $frames->statistics()['orphans']);
	}

	#[Test]
	#[TestDox('a verify names a merge parent that has gone rather than ignoring the link')]
	#[Group('strata/branch')]
	public function averifyNoticesAMissingMergeParent(): void
	{
		$this->site(['name' => 'Base', 'slogan' => 'Base slogan']);
		$this->engine()->merger()->branch('release-12');

		$theirs = $this->onBranch('release-12', ['name' => 'Base', 'slogan' => 'Go outside']);
		$this->site(['name' => 'Earth App', 'slogan' => 'Base slogan']);
		$this->engine()->merger()->merge('release-12');

		$this->assertTrue($this->engine()->verifier()->verify()->isClean());

		$this->engine()
			->provider()
			->delete([Hash::key($theirs, 'commits')]);
		$this->engine()->commitLog()->flushCache();

		$report = $this->engine()->verifier()->verify();

		$this->assertFalse($report->isClean());
		$this->assertArrayHasKey('commit.parent_missing', $report->byCode());
	}

	#[Test]
	#[TestDox('an archive of a merged history carries the branch side as well as the trunk')]
	#[Group('strata/branch')]
	public function anArchiveCarriesTheMergedBranch(): void
	{
		$this->site(['name' => 'Base', 'slogan' => 'Base slogan']);
		$this->engine()->merger()->branch('release-12');

		$theirs = $this->onBranch('release-12', ['name' => 'Base', 'slogan' => 'Go outside']);
		$this->site(['name' => 'Earth App', 'slogan' => 'Base slogan']);
		$this->engine()->merger()->merge('release-12');

		$closure = $this->engine()->archiveExporter()->closure((string) $this->head());

		$this->assertSame([], $closure['problems'], implode('; ', $closure['problems']));
		$this->assertContains(
			$theirs,
			$closure['commits'],
			'a merge names the branch tip, so an archive without it is not self-contained',
		);
	}

	#endregion

	#region Fixtures

	private function engine(): Engine
	{
		return $this->container->get('strata.engine');
	}

	private function journal(): JournalInterface
	{
		return $this->container->get('strata.journal');
	}

	private function head(): ?string
	{
		return $this->engine()->refStore()->read();
	}

	/**
	 * Writes `system.site` and seals it onto the trunk.
	 *
	 * @param array<string, string> $values
	 *   Key keyed to its value.
	 *
	 * @return string
	 *   The commit the trunk now points at.
	 */
	private function site(array $values): string
	{
		$config = $this->config('system.site');

		foreach ($values as $key => $value) {
			$config->set($key, $value);
		}

		$config->save();
		$this->engine()->flusher()->flush(true);

		return (string) $this->engine()->refStore()->read();
	}

	/**
	 * Writes `system.site` and seals it onto a branch instead of the trunk.
	 *
	 * There is no working tree, so a value written for a branch is also the value the live site holds
	 * until something puts it back. Every caller here writes the trunk's value again afterwards, which
	 * is what makes the two lines of history actually diverge.
	 *
	 * @param string $name
	 *   The branch name.
	 * @param array<string, string> $values
	 *   Key keyed to its value.
	 *
	 * @return string
	 *   The commit the branch now points at.
	 */
	private function onBranch(string $name, array $values): string
	{
		$config = $this->config('system.site');

		foreach ($values as $key => $value) {
			$config->set($key, $value);
		}

		$config->save();
		$this->engine()->flusher()->flush(true, Branch::refFor($name));

		return (string) $this->engine()->refStore()->read(Branch::refFor($name));
	}

	#endregion
}
