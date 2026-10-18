<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\E2E;

use Drupal;
use Drupal\node\Entity\Node;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Health\Finding;
use Drupal\strata\Restore\RestoreResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves a rollback puts back exactly what was there, and moves nothing it was not asked to move.
 *
 * Precision is measured two ways at once. The thing restored must hash exactly as it did at the
 * commit, and everything else must hash exactly as it did before the restore ran. Both digests come
 * from live Drupal data through `Oracle`, never from what Strata stored, so a bug cannot hide in a
 * place the oracle also looks. The first case runs against every backend in the lane, since a
 * rollback that is precise on one object store and off by a byte on another is a rollback nobody can
 * trust.
 */
class RollbackPrecisionTest extends E2ETestBase
{
	/**
	 * @return array<string, array{string}>
	 */
	public static function providers(): array
	{
		$cases = [];

		foreach (Lane::providers() as $provider) {
			$cases[$provider] = [$provider];
		}

		return $cases;
	}

	#[Test]
	#[TestDox('a field comes back byte for byte on $provider and nothing around it moves')]
	#[Group('strata/e2e')]
	#[DataProvider('providers')]
	public function aFieldRestoresExactlyAndNothingElseMoves(string $provider): void
	{
		$this->configure($provider);

		$target = $this->article('Original title', 'The body as first written.');
		$bystander = $this->article('Bystander', 'Never touched by the restore.');
		$first = $this->checkpoint($target, $bystander);

		$this->rewrite($target, 'The body after the first edit.', 'Edited title');
		$this->rewrite($bystander, 'The bystander moved on its own.');
		$this->seal();
		$this->rewrite($target, 'The body after the second edit.');
		$this->seal();

		$before = Oracle::node($bystander->id());
		$result = $this->restore($first['commit'], ['entity/node:' . $target->id()]);

		$this->assertRestored($result, 1);
		$this->assertSame(
			$first['target'],
			Oracle::node($target->id()),
			'the target is byte exact',
		);
		$this->assertSame($before, Oracle::node($bystander->id()), 'the bystander was not touched');
		$this->assertNotSame($first['bystander'], Oracle::node($bystander->id()));
	}

	#[Test]
	#[TestDox('restoring to every commit in turn, out of order, reproduces each state exactly')]
	#[Group('strata/e2e')]
	public function everyCommitInHistoryRestoresExactly(): void
	{
		$this->configure('s3');

		$node = $this->article('History', 'version 0');
		$states = [];

		for ($version = 0; $version < 8; $version++) {
			if ($version > 0) {
				$this->rewrite($node, 'version ' . $version . ' ' . str_repeat('x', $version * 40));
			}

			$this->seal();
			$states[$this->head()] = Oracle::node($node->id());
		}

		$commits = array_keys($states);
		$order = [5, 0, 7, 2, 6, 1, 4, 3];

		foreach ($order as $index) {
			$result = $this->restore($commits[$index], ['entity/node:' . $node->id()]);

			$this->assertRestored($result, 1);
			$this->assertSame(
				$states[$commits[$index]],
				Oracle::node($node->id()),
				sprintf('commit %d of %d', $index + 1, count($commits)),
			);
		}
	}

	#[Test]
	#[TestDox('a deleted node comes back with the same id and content')]
	#[Group('strata/e2e')]
	public function aDeletedNodeIsRecreated(): void
	{
		$this->configure('s3');

		$node = $this->article('Soon gone', 'Content that must survive deletion.');
		$id = (int) $node->id();
		$first = $this->checkpoint($node);

		$node->delete();
		$this->seal();

		$this->assertSame('absent', Oracle::node($id));

		$result = $this->restore($first['commit'], ['entity/node:' . $id]);

		$this->assertRestored($result, 1);
		$this->assertSame($first['target'], Oracle::node($id));
	}

	#[Test]
	#[TestDox('a quarantined commit refuses to be restored to and the site is left as it was')]
	#[Group('strata/e2e')]
	public function aQuarantinedCommitIsNotRestoredTo(): void
	{
		$this->configure('s3');

		$node = $this->article('Held back', 'Body at the held commit.');
		$held = $this->checkpoint($node);

		$node->set('body', ['value' => 'Body after the held commit.', 'format' => 'plain_text']);
		$node->save();
		$this->seal();

		$before = Oracle::node((int) $node->id());
		$ledger = $this->engine()->ledger();
		$ledger->record(
			new Finding('commit.quarantined', Finding::ERROR, $held['commit'], 'suspect import'),
		);
		$ledger->setRung('commit.quarantined', 'quarantine');

		$result = $this->restore($held['commit'], ['entity/node:' . $node->id()]);

		$this->assertTrue($result->wasRefused(), 'a quarantined target is refused');
		$this->assertSame($before, Oracle::node((int) $node->id()), 'nothing was written');

		$ledger->setRung('commit.quarantined', 'observe');
		$result = $this->restore($held['commit'], ['entity/node:' . $node->id()]);

		$this->assertRestored($result, 1);
		$this->assertSame($held['target'], Oracle::node((int) $node->id()));
	}

	#[Test]
	#[TestDox('a restore drill over a real site, code included, matches what the site holds')]
	#[Group('strata/e2e')]
	public function aDrillProvesTheSite(): void
	{
		$this->configure('s3', ['code.root' => '/srv/drupal']);

		$this->history(2, 3);
		$this->engine()->codeCapture()->capture();
		$this->seal();

		$report = $this->engine()
			->drillRunner()
			->run(null, -1);

		$this->assertSame([], $report->unreadable, $report->summary());
		$this->assertSame([], $report->differed, $report->summary());
		$this->assertGreaterThan(0, count($report->matched), $report->summary());
		$this->assertSame('pass', $report->verdict(), $report->summary());
	}

	#[Test]
	#[TestDox('a whole-site restore never deletes content created after the restore point')]
	#[Group('strata/e2e')]
	public function contentCreatedLaterSurvivesARestore(): void
	{
		$this->configure('s3');

		$early = $this->article('Early', 'Present at the restore point.');
		$first = $this->checkpoint($early);

		$this->rewrite($early, 'Edited after the restore point.');
		$late = $this->article('Late', 'Created after the restore point.');
		$this->seal();
		$lateState = Oracle::node($late->id());

		$result = $this->engine()->logicalRestore()->restoreAll($first['commit'], true);

		$this->assertFalse($result->wasRefused(), (string) $result->refused);
		$this->assertSame($first['target'], Oracle::node($early->id()));
		$this->assertSame($lateState, Oracle::node($late->id()), 'the later node is untouched');
	}

	#[Test]
	#[TestDox('configuration, state and key-value entries restore to exactly their earlier values')]
	#[Group('strata/e2e')]
	public function configStateAndKeyValueRestoreExactly(): void
	{
		$this->configure('s3');

		$config = Drupal::configFactory()->getEditable('system.site');
		$config->set('name', 'Before')->set('slogan', 'First slogan')->save();
		Drupal::state()->set('e2e.flag', ['mode' => 'first', 'n' => 1]);
		Drupal::keyValue('e2e_entries')->set('entry', ['value' => 'first']);

		$this->seal();
		$first = $this->head();
		$digests = [
			'config' => Oracle::config('system.site'),
			'state' => Oracle::state('e2e.flag'),
			'keyvalue' => Oracle::keyValue('e2e_entries', 'entry'),
		];

		$config->set('name', 'After')->set('slogan', 'Second slogan')->save();
		Drupal::state()->set('e2e.flag', ['mode' => 'second', 'n' => 2]);
		Drupal::keyValue('e2e_entries')->set('entry', ['value' => 'second']);
		$this->seal();

		$this->assertNotSame($digests['config'], Oracle::config('system.site'));

		$result = $this->restore($first, [
			'config/system.site',
			'state/e2e.flag',
			'keyvalue/e2e_entries:entry',
		]);

		$this->assertRestored($result, 3);
		$this->assertSame($digests['config'], Oracle::config('system.site'));
		$this->assertSame($digests['state'], Oracle::state('e2e.flag'));
		$this->assertSame($digests['keyvalue'], Oracle::keyValue('e2e_entries', 'entry'));
	}

	#[Test]
	#[TestDox('a rollback is undone by restoring the snapshot it took first')]
	#[Group('strata/e2e')]
	public function aRollbackCanBeRolledBack(): void
	{
		$this->configure('s3');

		$node = $this->article('Undo me', 'first');
		$first = $this->checkpoint($node);

		$this->rewrite($node, 'second');
		$this->seal();
		$beforeRollback = Oracle::node($node->id());

		$rollback = $this->restore($first['commit'], ['entity/node:' . $node->id()]);

		$this->assertRestored($rollback, 1);
		$this->assertSame($first['target'], Oracle::node($node->id()));
		$this->assertNotNull($rollback->snapshot, 'a snapshot is always taken first');

		$undo = $this->restore((string) $rollback->snapshot, ['entity/node:' . $node->id()]);

		$this->assertRestored($undo, 1);
		$this->assertSame($beforeRollback, Oracle::node($node->id()));
	}

	#[Test]
	#[TestDox('a dry run writes nothing and takes no snapshot')]
	#[Group('strata/e2e')]
	public function aDryRunWritesNothing(): void
	{
		$this->configure('s3');

		$node = $this->article('Dry', 'first');
		$first = $this->checkpoint($node);

		$this->rewrite($node, 'second');
		$this->seal();

		$commits = $this->engine()->commitIndex()->count();
		$tables = Oracle::tables(['node', 'users', 'path']);
		$digest = Oracle::node($node->id());
		$result = $this->restore($first['commit'], ['entity/node:' . $node->id()], false);

		$this->assertSame([], $result->failed);
		$this->assertNull($result->snapshot);
		$this->assertSame($digest, Oracle::node($node->id()));
		$this->assertSame($tables, Oracle::tables(['node', 'users', 'path']));
		$this->assertSame($commits, $this->engine()->commitIndex()->count());
	}

	#[Test]
	#[TestDox('restoring the same commit twice leaves the same bytes as restoring it once')]
	#[Group('strata/e2e')]
	public function aRestoreIsIdempotent(): void
	{
		$this->configure('s3');

		$node = $this->article('Twice', 'first');
		$first = $this->checkpoint($node);

		$this->rewrite($node, 'second');
		$this->seal();

		$this->restore($first['commit'], ['entity/node:' . $node->id()]);
		$once = Oracle::node($node->id());
		$this->restore($first['commit'], ['entity/node:' . $node->id()]);

		$this->assertSame($first['target'], $once);
		$this->assertSame($once, Oracle::node($node->id()));
	}

	#[Test]
	#[TestDox('a subject edited after the plan was made is skipped, not overwritten')]
	#[Group('strata/e2e')]
	public function aConcurrentEditStopsThatSubject(): void
	{
		$this->configure('s3');

		$node = $this->article('Contested', 'first');
		$first = $this->checkpoint($node);

		$this->rewrite($node, 'second');
		$this->seal();

		$plan = $this->engine()
			->preflight()
			->planSubjects($first['commit'], ['entity/node:' . $node->id()]);

		usleep(1_100_000);
		$this->rewrite($node, 'edited by somebody while the plan waited');
		$this->settle();

		$result = $this->engine()
			->logicalRestore()
			->apply($plan, 'entity/node:' . $node->id());

		$this->assertSame([], $result->restored);
		$this->assertArrayHasKey('entity/node:' . $node->id(), $result->skipped);
		$this->assertSame(
			'edited by somebody while the plan waited',
			Node::load($node->id())?->get('body')->value,
		);
	}

	#[Test]
	#[TestDox('a subject whose frames are gone is skipped and no default is written in its place')]
	#[Group('strata/e2e')]
	public function aDegradedSubjectIsNotInvented(): void
	{
		$this->configure('s3');

		$lost = $this->article('Lost frames', 'content that will lose its bytes');
		$this->seal();

		$lostFrames = $this->engine()
			->subjectIndex()
			->frames('entity/node:' . $lost->id());

		$kept = $this->article('Kept frames', 'content that stays readable');
		$first = $this->checkpoint($lost, $kept);

		$this->rewrite($lost, 'moved on');
		$this->rewrite($kept, 'moved on');
		$this->seal();

		$this->assertNotSame([], $lostFrames);
		$this->damageFrames($lostFrames);

		$lostNow = Oracle::node($lost->id());
		$result = $this->restore($first['commit'], [
			'entity/node:' . $lost->id(),
			'entity/node:' . $kept->id(),
		]);

		$this->assertSame(
			$first['bystander'],
			Oracle::node($kept->id()),
			'the intact one is restored',
		);
		$this->assertSame(
			$lostNow,
			Oracle::node($lost->id()),
			'nothing was written for the lost one',
		);
		$this->assertNotContains('entity/node:' . $lost->id(), $result->restored);
	}

	#[Test]
	#[TestDox('a restore through drush writes the same bytes the API does')]
	#[Group('strata/e2e')]
	public function drushRestoreMatchesTheApi(): void
	{
		$this->configure('s3');

		$node = $this->article('Through drush', 'first');
		$first = $this->checkpoint($node);

		$this->rewrite($node, 'second');
		$this->seal();

		$plan = $this->drush([
			'strata:restore',
			$first['commit'],
			'entity/node:' . $node->id(),
			'--dry-run',
		]);

		$this->assertStringContainsString('Restorable   : 1', $plan);
		$this->assertNotSame($first['target'], Oracle::node($node->id()));

		$this->drush(['strata:restore', $first['commit'], 'entity/node:' . $node->id(), '--yes']);

		$this->assertSame($first['target'], Oracle::node($node->id()));
	}

	/**
	 * Seals a commit and records what the given nodes hold at it.
	 *
	 * @param Node $target
	 *   The node a test restores.
	 * @param Node|null $bystander
	 *   A node it must leave alone.
	 *
	 * @return array{commit: string, target: string, bystander: string}
	 *   The commit and each node's digest at it.
	 */
	private function checkpoint(Node $target, ?Node $bystander = null): array
	{
		$result = $this->seal();

		$this->assertTrue($result->ran, $result->summary());

		return [
			'commit' => $this->head(),
			'target' => Oracle::node($target->id()),
			'bystander' => $bystander === null ? '' : Oracle::node($bystander->id()),
		];
	}

	/**
	 * Edits a node.
	 *
	 * @param Node $node
	 *   The node.
	 * @param string $body
	 *   The new body.
	 * @param string|null $title
	 *   A new title, or NULL to leave it.
	 */
	private function rewrite(Node $node, string $body, ?string $title = null): void
	{
		$node->set('body', ['value' => $body, 'format' => 'plain_text']);

		if ($title !== null) {
			$node->setTitle($title);
		}

		$node->save();
	}

	/**
	 * Restores subjects with the logical restore.
	 *
	 * @param string $commit
	 *   The commit to restore to.
	 * @param list<string> $subjects
	 *   Subject paths.
	 * @param bool $apply
	 *   FALSE for a dry run.
	 *
	 * @return RestoreResult
	 *   The result.
	 */
	private function restore(string $commit, array $subjects, bool $apply = true): RestoreResult
	{
		$this->settle();

		return $this->engine()->logicalRestore()->restore($commit, $subjects, $apply);
	}

	/**
	 * Asserts a restore wrote a number of subjects and failed on none.
	 *
	 * @param RestoreResult $result
	 *   The result.
	 * @param int $count
	 *   How many subjects should have been written.
	 */
	private function assertRestored(RestoreResult $result, int $count): void
	{
		$this->assertFalse($result->wasRefused(), (string) $result->refused);
		$this->assertSame([], $result->failed, json_encode($result->failed) ?: '');
		$this->assertCount(
			$count,
			$result->restored,
			$result->summary() . ' ' . json_encode($result->skipped),
		);
	}

	/**
	 * Removes the stored bytes of frames, whichever pack or object they sit in.
	 *
	 * @param list<string> $frames
	 *   Frame digests.
	 */
	private function damageFrames(array $frames): void
	{
		$index = $this->engine()->frameIndex();
		$keys = [];

		foreach ($frames as $frame) {
			$record = $index->get($frame);

			if ($record === null) {
				continue;
			}

			$keys[] = $record->isPacked()
				? Hash::key((string) $record->pack, ObjectStore::PACK_PREFIX)
				: Hash::key($frame, ObjectStore::FRAME_PREFIX);
		}

		Fault::delete($this->provider(), array_values(array_unique($keys)));
	}
}
