<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Branch;

use Drupal\strata\Branch\Branch;
use Drupal\strata\Branch\MergeBase;
use Drupal\strata\Branch\MergeOutcome;
use Drupal\strata\Branch\MergeStrategy;
use Drupal\strata\Branch\ThreeWayMerge;
use Drupal\strata\Cas\Hash;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves the offline half of branching: what a name may be, and what a three-way merge decides.
 *
 * Both are pure functions of their inputs, so they belong here rather than in a lane that boots
 * Drupal. The merge is the part worth the coverage: it decides whether a live configuration object
 * gets overwritten, and every one of its five outcomes has a different consequence for the site.
 *
 * The nested cases matter as much as the flat ones. Drupal configuration nests, so "two people
 * edited different settings" and "two people edited the same setting" are usually two keys inside the
 * same map rather than two top-level keys, and a merge that only compared top-level keys would report
 * a conflict for every object either side touched.
 */
#[CoversClass(Branch::class)]
#[CoversClass(MergeBase::class)]
#[CoversClass(ThreeWayMerge::class)]
#[CoversClass(MergeStrategy::class)]
#[CoversClass(MergeOutcome::class)]
class BranchTest extends TestCase
{
	#region Merge Bases

	#[Test]
	#[TestDox('the merge base of a straight chain is the older of the two commits')]
	#[Group('strata/branch')]
	public function chainBaseIsTheOlderCommit(): void
	{
		// d <- c <- b <- a
		$base = $this->base(['a' => [], 'b' => ['a'], 'c' => ['b'], 'd' => ['c']]);

		$this->assertSame($this->id('b'), $base->find($this->id('d'), $this->id('b')));
		$this->assertSame($this->id('d'), $base->find($this->id('d'), $this->id('d')));
		$this->assertTrue($base->contains($this->id('a'), $this->id('d')));
		$this->assertFalse($base->contains($this->id('d'), $this->id('a')));
	}

	#[Test]
	#[TestDox('the merge base of a fork is the commit both sides were cut from')]
	#[Group('strata/branch')]
	public function forkBaseIsTheForkPoint(): void
	{
		// trunk: a <- b <- c, branch: a <- x <- y
		$base = $this->base([
			'a' => [],
			'b' => ['a'],
			'c' => ['b'],
			'x' => ['a'],
			'y' => ['x'],
		]);

		$this->assertSame($this->id('a'), $base->find($this->id('c'), $this->id('y')));
		$this->assertFalse($base->contains($this->id('y'), $this->id('c')));
	}

	#[Test]
	#[TestDox('a diamond resolves to the nearest common ancestor, not the oldest one')]
	#[Group('strata/branch')]
	public function diamondResolvesToTheNearestAncestor(): void
	{
		// root <- a; a <- b and a <- c; m merges b and c; then n and z hang off m and c
		$base = $this->base([
			'root' => [],
			'a' => ['root'],
			'b' => ['a'],
			'c' => ['a'],
			'm' => ['b', 'c'],
			'n' => ['m'],
			'z' => ['c'],
		]);

		$this->assertSame(
			$this->id('c'),
			$base->find($this->id('n'), $this->id('z')),
			'c is reachable from both and is nearer than a',
		);
		$this->assertSame($this->id('a'), $base->find($this->id('b'), $this->id('c')));
		$this->assertTrue(
			$base->contains($this->id('c'), $this->id('m')),
			'a merge commit reaches its second parent as well as its first',
		);
	}

	#[Test]
	#[
		TestDox(
			'a branch the trunk already merged has itself as its base, so there is nothing to bring',
		),
	]
	#[Group('strata/branch')]
	public function analreadyMergedBranchIsItsOwnBase(): void
	{
		$base = $this->base([
			'a' => [],
			'b' => ['a'],
			'x' => ['a'],
			'm' => ['b', 'x'],
		]);

		$this->assertSame($this->id('x'), $base->find($this->id('m'), $this->id('x')));
	}

	#[Test]
	#[TestDox('two histories with different roots share no base rather than inventing one')]
	#[Group('strata/branch')]
	public function unrelatedHistoriesShareNoBase(): void
	{
		$base = $this->base(['a' => [], 'b' => ['a'], 'p' => [], 'q' => ['p']]);

		$this->assertNull($base->find($this->id('b'), $this->id('q')));
	}

	#[Test]
	#[TestDox('a commit the index does not hold ends that line of the walk rather than raising')]
	#[Group('strata/branch')]
	public function aPrunedAncestorEndsTheWalk(): void
	{
		// b names a parent nothing indexes, which is what a pruned history looks like
		$base = $this->base(['b' => [$this->id('gone')], 'q' => []]);

		$this->assertNull($base->find($this->id('b'), $this->id('q')));
		$this->assertTrue($base->contains($this->id('b'), $this->id('b')));
	}

	#[Test]
	#[TestDox('a chain that is its own ancestor is refused rather than walked round')]
	#[Group('strata/branch')]
	public function aCorruptChainIsRefused(): void
	{
		$base = $this->base(['a' => ['c'], 'b' => ['a'], 'c' => ['b']]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('their own ancestors');

		$base->find($this->id('a'), $this->id('b'));
	}

	#[Test]
	#[TestDox('a history longer than the ceiling is refused, naming the ceiling it passed')]
	#[Group('strata/branch')]
	public function aWalkPastTheCeilingIsRefused(): void
	{
		$chain = ['c0' => []];

		for ($i = 1; $i <= 20; $i++) {
			$chain['c' . $i] = ['c' . ($i - 1)];
		}

		$base = $this->base($chain, 5);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('longer than 5 commits');

		$base->find($this->id('c20'), $this->id('c0'));
	}

	#[Test]
	#[TestDox('a merge base needs two real commit ids')]
	#[Group('strata/branch')]
	public function aMergeBaseNeedsCommitIds(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('two valid commit ids');

		$this->base(['a' => []])->find('nope', $this->id('a'));
	}

	#endregion

	#region Names

	/**
	 * @return array<string, array{string}>
	 */
	public static function usableNameProvider(): array
	{
		$names = ['main', 'release-12', 'feature_x', 'team/release/12', 'a', 'x9'];

		return array_combine($names, array_map(static fn(string $n): array => [$n], $names));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function unusableNameProvider(): array
	{
		return [
			'empty' => [''],
			'uppercase' => ['Release-12'],
			'a space' => ['release 12'],
			'a dot' => ['release.12'],
			'traversal' => ['../escape'],
			'a current directory segment' => ['team/./release'],
			'an empty segment' => ['team//release'],
			'a leading slash' => ['/release'],
			'a trailing slash' => ['release/'],
			'a leading dash' => ['-release'],
			'too long' => [str_repeat('a', 129)],
		];
	}

	#[Test]
	#[TestDox('a branch named $_dataName is accepted')]
	#[Group('strata/branch')]
	#[DataProvider('usableNameProvider')]
	public function usableNamesAreAccepted(string $name): void
	{
		$this->assertTrue(Branch::isValidName($name));
		$this->assertSame('heads/' . $name, Branch::refFor($name));
		$this->assertSame($name, Branch::nameFor('heads/' . $name));
	}

	#[Test]
	#[TestDox('a branch named with $_dataName is refused')]
	#[Group('strata/branch')]
	#[DataProvider('unusableNameProvider')]
	public function unusableNamesAreRefused(string $name): void
	{
		$this->assertFalse(Branch::isValidName($name));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('is not a usable branch name');

		Branch::refFor($name);
	}

	#[Test]
	#[TestDox('a ref outside heads is not a branch name')]
	#[Group('strata/branch')]
	public function onlyHeadsAreBranches(): void
	{
		$this->assertNull(Branch::nameFor('tags/v1'));
		$this->assertNull(Branch::nameFor('heads/Release'), 'the name rules still apply');
		$this->assertSame('main', Branch::nameFor('heads/main'));
	}

	#[Test]
	#[TestDox('a branch knows its ref, whether it is the trunk and whether it has moved')]
	#[Group('strata/branch')]
	public function branchDescribesItself(): void
	{
		$fork = Hash::of('fork');
		$tip = Hash::of('tip');
		$branch = new Branch('release-12', $fork, $fork, 7, 1_000);

		$this->assertSame('heads/release-12', $branch->ref());
		$this->assertFalse($branch->isTrunk());
		$this->assertTrue($branch->isUnchanged());
		$this->assertFalse($branch->at($tip)->isUnchanged());
		$this->assertTrue((new Branch('main', $fork, $fork))->isTrunk());
	}

	#[Test]
	#[TestDox('a branch round-trips through its stored metadata, with the ref supplying the tip')]
	#[Group('strata/branch')]
	public function branchRoundTrips(): void
	{
		$fork = Hash::of('fork');
		$tip = Hash::of('tip');
		$branch = new Branch('release-12', $fork, $tip, 7, 1_000);

		$decoded = Branch::fromArray($branch->jsonSerialize(), $tip);

		$this->assertEquals($branch, $decoded);
		$this->assertArrayNotHasKey(
			'tip',
			$branch->jsonSerialize(),
			'the ref holds the tip, so the metadata must not hold a second copy of it',
		);
	}

	#[Test]
	#[TestDox('a branch forking from something that is not a commit id is refused')]
	#[Group('strata/branch')]
	public function branchRefusesANonCommitFork(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('valid commit id');

		new Branch('release-12', 'nope', Hash::of('tip'));
	}

	#endregion

	#region Outcomes

	#[Test]
	#[TestDox('an object neither side touched is unchanged and is not written')]
	#[Group('strata/branch')]
	public function untouchedObjectIsUnchanged(): void
	{
		$data = ['name' => 'Earth', 'page' => ['front' => '/node']];
		$entry = $this->merge($data, $data, $data);

		$this->assertSame(MergeOutcome::UNCHANGED, $entry->outcome);
		$this->assertFalse($entry->isWritten());
		$this->assertFalse($entry->isBlocking());
	}

	#[Test]
	#[TestDox('an object only the trunk changed is kept and is not written')]
	#[Group('strata/branch')]
	public function trunkOnlyChangeIsKept(): void
	{
		$entry = $this->merge(['name' => 'Earth'], ['name' => 'Earth App'], ['name' => 'Earth']);

		$this->assertSame(MergeOutcome::OURS, $entry->outcome);
		$this->assertFalse($entry->isWritten());
		$this->assertSame(['name' => 'Earth App'], $entry->value);
	}

	#[Test]
	#[TestDox('an object only the branch changed is taken whole and is written')]
	#[Group('strata/branch')]
	public function branchOnlyChangeIsTaken(): void
	{
		$entry = $this->merge(['name' => 'Earth'], ['name' => 'Earth'], ['name' => 'Earth App']);

		$this->assertSame(MergeOutcome::THEIRS, $entry->outcome);
		$this->assertTrue($entry->isWritten());
		$this->assertSame(['name' => 'Earth App'], $entry->value);
		$this->assertSame(['name'], $entry->fromTheirs);
	}

	#[Test]
	#[TestDox('two edits to different keys of one object merge rather than collide')]
	#[Group('strata/branch')]
	public function differentKeysMerge(): void
	{
		$entry = $this->merge(
			['name' => 'Earth', 'slogan' => '', 'mail' => 'a@example.com'],
			['name' => 'Earth App', 'slogan' => '', 'mail' => 'a@example.com'],
			['name' => 'Earth', 'slogan' => 'Go outside', 'mail' => 'a@example.com'],
		);

		$this->assertSame(MergeOutcome::MERGED, $entry->outcome);
		$this->assertTrue($entry->isWritten());
		$this->assertSame(
			['name' => 'Earth App', 'slogan' => 'Go outside', 'mail' => 'a@example.com'],
			$entry->value,
			'both edits survive and the untouched key keeps its value',
		);
		$this->assertSame(['slogan'], $entry->fromTheirs);
	}

	#[Test]
	#[TestDox('two edits to the same key collide and neither value is chosen')]
	#[Group('strata/branch')]
	public function sameKeyCollides(): void
	{
		$entry = $this->merge(['name' => 'Earth'], ['name' => 'Trunk'], ['name' => 'Branch']);

		$this->assertSame(MergeOutcome::CONFLICT, $entry->outcome);
		$this->assertTrue($entry->isBlocking());
		$this->assertFalse($entry->isWritten());
		$this->assertSame(['ours' => 'Trunk', 'theirs' => 'Branch'], $entry->conflicts['name']);
		$this->assertStringContainsString('name', $entry->describe());
	}

	#[Test]
	#[TestDox('both sides making the same edit is agreement rather than a collision')]
	#[Group('strata/branch')]
	public function identicalEditsAgree(): void
	{
		$entry = $this->merge(['name' => 'Earth'], ['name' => 'Same'], ['name' => 'Same']);

		$this->assertSame(MergeOutcome::UNCHANGED, $entry->outcome);
		$this->assertSame([], $entry->conflicts);
	}

	#endregion

	#region Nesting

	#[Test]
	#[TestDox('two edits to different keys of the same nested map merge key by key')]
	#[Group('strata/branch')]
	public function nestedDifferentKeysMerge(): void
	{
		$entry = $this->merge(
			['page' => ['front' => '/node', '403' => '', '404' => '']],
			['page' => ['front' => '/home', '403' => '', '404' => '']],
			['page' => ['front' => '/node', '403' => '', '404' => '/missing']],
		);

		$this->assertSame(MergeOutcome::MERGED, $entry->outcome);
		$this->assertSame(
			['front' => '/home', '403' => '', '404' => '/missing'],
			$entry->value['page'],
		);
		$this->assertSame(['page.404'], $entry->fromTheirs);
	}

	#[Test]
	#[TestDox('a collision deep in a nested map is named by its dotted path')]
	#[Group('strata/branch')]
	public function nestedCollisionNamesItsPath(): void
	{
		$entry = $this->merge(
			['filters' => ['html' => ['settings' => ['allowed' => '<a>']]]],
			['filters' => ['html' => ['settings' => ['allowed' => '<a><em>']]]],
			['filters' => ['html' => ['settings' => ['allowed' => '<a><strong>']]]],
		);

		$this->assertSame(MergeOutcome::CONFLICT, $entry->outcome);
		$this->assertArrayHasKey('filters.html.settings.allowed', $entry->conflicts);
	}

	#[Test]
	#[TestDox('a list both sides changed is one value in disagreement, never spliced together')]
	#[Group('strata/branch')]
	public function listsAreNeverSpliced(): void
	{
		$entry = $this->merge(
			['roles' => ['anonymous']],
			['roles' => ['anonymous', 'editor']],
			['roles' => ['anonymous', 'reviewer']],
		);

		$this->assertSame(MergeOutcome::CONFLICT, $entry->outcome);
		$this->assertSame(
			['ours' => ['anonymous', 'editor'], 'theirs' => ['anonymous', 'reviewer']],
			$entry->conflicts['roles'],
		);
	}

	#[Test]
	#[TestDox('a key reordered but not changed is the same configuration')]
	#[Group('strata/branch')]
	public function keyOrderIsNotAChange(): void
	{
		$entry = $this->merge(
			['page' => ['front' => '/node', '404' => '']],
			['page' => ['404' => '', 'front' => '/node']],
			['page' => ['front' => '/node', '404' => '/missing']],
		);

		$this->assertSame(MergeOutcome::THEIRS, $entry->outcome, 'reordering is not an edit');
	}

	#[Test]
	#[TestDox('a key added on one side and a key removed on the other both survive')]
	#[Group('strata/branch')]
	public function additionAndRemovalBothApply(): void
	{
		$entry = $this->merge(['a' => 1, 'b' => 2], ['a' => 1, 'b' => 2, 'c' => 3], ['a' => 1]);

		$this->assertSame(MergeOutcome::MERGED, $entry->outcome);
		$this->assertSame(['a' => 1, 'c' => 3], $entry->value, 'b was removed on the branch');
		$this->assertSame(['b'], $entry->fromTheirs);
	}

	#[Test]
	#[TestDox('null, false, zero and the empty string stay four different settings')]
	#[Group('strata/branch')]
	public function falsyValuesAreDistinct(): void
	{
		$entry = $this->merge(['x' => null], ['x' => null], ['x' => false]);

		$this->assertSame(MergeOutcome::THEIRS, $entry->outcome);
		$this->assertFalse($entry->value['x']);
		$this->assertSame(
			MergeOutcome::UNCHANGED,
			$this->merge(['x' => 0], ['x' => 0], ['x' => 0])->outcome,
		);
		$this->assertSame(
			MergeOutcome::CONFLICT,
			$this->merge(['x' => 0], ['x' => ''], ['x' => false])->outcome,
		);
	}

	#endregion

	#region Existence

	#[Test]
	#[TestDox('an object created only on the branch is taken whole')]
	#[Group('strata/branch')]
	public function branchOnlyCreationIsTaken(): void
	{
		$entry = $this->merge(null, null, ['name' => 'New']);

		$this->assertSame(MergeOutcome::THEIRS, $entry->outcome);
		$this->assertTrue($entry->exists);
		$this->assertSame(['name' => 'New'], $entry->value);
	}

	#[Test]
	#[TestDox('an object removed only on the branch is removed by the merge')]
	#[Group('strata/branch')]
	public function branchOnlyRemovalIsApplied(): void
	{
		$entry = $this->merge(['name' => 'Old'], ['name' => 'Old'], null);

		$this->assertSame(MergeOutcome::THEIRS, $entry->outcome);
		$this->assertFalse($entry->exists);
		$this->assertTrue($entry->isWritten());
		$this->assertStringContainsString('removed on the branch', $entry->describe());
	}

	#[Test]
	#[TestDox('an object one side removed and the other changed is a conflict about existence')]
	#[Group('strata/branch')]
	public function removeAgainstEditIsAConflict(): void
	{
		$entry = $this->merge(['name' => 'Old'], ['name' => 'Changed'], null);

		$this->assertSame(MergeOutcome::CONFLICT, $entry->outcome);
		$this->assertTrue($entry->isBlocking());
		$this->assertArrayHasKey('', $entry->conflicts, 'the whole object is what disagrees');
	}

	#[Test]
	#[TestDox('an object both sides created differently is a conflict, not a silent overwrite')]
	#[Group('strata/branch')]
	public function competingCreationsCollide(): void
	{
		$entry = $this->merge(null, ['name' => 'Trunk'], ['name' => 'Branch']);

		$this->assertSame(MergeOutcome::CONFLICT, $entry->outcome);
		$this->assertTrue($entry->isBlocking());
	}

	#[Test]
	#[TestDox('an object both sides removed needs nothing written')]
	#[Group('strata/branch')]
	public function bothRemovedIsUnchanged(): void
	{
		$entry = $this->merge(['name' => 'Old'], null, null);

		$this->assertSame(MergeOutcome::UNCHANGED, $entry->outcome);
		$this->assertFalse($entry->exists);
		$this->assertFalse($entry->isWritten());
	}

	#endregion

	#region Strategies

	#[Test]
	#[
		TestDox(
			'refuse is the default and an unknown strategy name refuses rather than picking a side',
		),
	]
	#[Group('strata/branch')]
	public function unknownStrategyRefuses(): void
	{
		$this->assertSame(MergeStrategy::REFUSE, MergeStrategy::named(null));
		$this->assertSame(MergeStrategy::REFUSE, MergeStrategy::named(''));
		$this->assertSame(MergeStrategy::REFUSE, MergeStrategy::named('thiers'));
		$this->assertSame(MergeStrategy::THEIRS, MergeStrategy::named(' THEIRS '));
		$this->assertTrue(MergeStrategy::REFUSE->isRefusal());
	}

	#[Test]
	#[
		TestDox(
			'the ours strategy keeps the trunk value and still takes the keys that did not collide',
		),
	]
	#[Group('strata/branch')]
	public function oursResolvesOnlyTheCollision(): void
	{
		$entry = $this->merge(
			['name' => 'Earth', 'slogan' => ''],
			['name' => 'Trunk', 'slogan' => ''],
			['name' => 'Branch', 'slogan' => 'Go outside'],
			MergeStrategy::OURS,
		);

		$this->assertSame(MergeOutcome::CONFLICT, $entry->outcome);
		$this->assertFalse($entry->isBlocking());
		$this->assertTrue($entry->isWritten());
		$this->assertSame(['name' => 'Trunk', 'slogan' => 'Go outside'], $entry->value);
	}

	#[Test]
	#[TestDox('the theirs strategy takes the branch value for the keys that collided')]
	#[Group('strata/branch')]
	public function theirsResolvesTheCollision(): void
	{
		$entry = $this->merge(
			['name' => 'Earth', 'slogan' => ''],
			['name' => 'Trunk', 'slogan' => ''],
			['name' => 'Branch', 'slogan' => 'Go outside'],
			MergeStrategy::THEIRS,
		);

		$this->assertFalse($entry->isBlocking());
		$this->assertSame(['name' => 'Branch', 'slogan' => 'Go outside'], $entry->value);
		$this->assertContains('name', $entry->fromTheirs);
	}

	#[Test]
	#[TestDox('a strategy resolves an existence conflict to the side it names')]
	#[Group('strata/branch')]
	public function strategyResolvesExistence(): void
	{
		$kept = $this->merge(['a' => 1], ['a' => 2], null, MergeStrategy::OURS);
		$taken = $this->merge(['a' => 1], ['a' => 2], null, MergeStrategy::THEIRS);

		$this->assertTrue($kept->exists);
		$this->assertSame(['a' => 2], $kept->value);
		$this->assertFalse($taken->exists, 'taking the branch means taking the removal');
		$this->assertFalse($taken->isBlocking());
	}

	#[Test]
	#[TestDox('a resolved conflict still says it was one, so the manifest never hides it')]
	#[Group('strata/branch')]
	public function aResolvedConflictIsStillReported(): void
	{
		$entry = $this->merge(
			['name' => 'Earth'],
			['name' => 'Trunk'],
			['name' => 'Branch'],
			MergeStrategy::THEIRS,
		);

		$this->assertSame(MergeOutcome::CONFLICT, $entry->outcome);
		$this->assertTrue($entry->resolved);
		$this->assertNotSame([], $entry->conflicts);
		$this->assertStringContainsString('resolved', $entry->describe());
	}

	#endregion

	/**
	 * Merges one object across three sides.
	 *
	 * @param array<string, mixed>|null $base
	 *   Its data at the merge base.
	 * @param array<string, mixed>|null $ours
	 *   Its data at the trunk tip.
	 * @param array<string, mixed>|null $theirs
	 *   Its data at the branch tip.
	 * @param MergeStrategy $strategy
	 *   What to do with a key both sides changed.
	 */
	private function merge(
		?array $base,
		?array $ours,
		?array $theirs,
		MergeStrategy $strategy = MergeStrategy::REFUSE,
	): object {
		return (new ThreeWayMerge())->merge('system.site', $base, $ours, $theirs, $strategy);
	}

	/**
	 * A merge base over a synthetic chain named by short labels.
	 *
	 * @param array<string, list<string>> $chain
	 *   Short label keyed to the short labels of its parents.
	 * @param int $ceiling
	 *   How many commits either walk may read.
	 */
	private function base(array $chain, int $ceiling = MergeBase::DEFAULT_CEILING): MergeBase
	{
		$parents = [];

		foreach ($chain as $label => $links) {
			$parents[$this->id((string) $label)] = array_map(
				fn(string $link): string => Hash::isValid($link) ? $link : $this->id($link),
				$links,
			);
		}

		return new MergeBase(static fn(string $id): ?array => $parents[$id] ?? null, $ceiling);
	}

	/**
	 * The commit address a short label stands for.
	 *
	 * @param string $label
	 *   The label.
	 */
	private function id(string $label): string
	{
		return Hash::of('commit ' . $label);
	}
}
