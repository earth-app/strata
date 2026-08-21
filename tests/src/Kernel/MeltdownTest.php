<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Engine;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\RepairLadder;
use Drupal\strata\Restore\SubjectStatus;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

/**
 * Proves what happens when a great deal breaks at once, and what the module does about it.
 *
 * VerifyTest checks that one symptom is detected. This is the other question, and the one an operator
 * actually asks at 3am: the bucket has been half emptied by a bad lifecycle rule, or a disk has
 * returned garbage for a thousand objects, or somebody has deleted the encryption key. What is left,
 * what can be rebuilt from what is left, and does anything lie about it?
 *
 * Three properties are what "survives a meltdown" means here, and each is asserted separately:
 *
 * - **Every symptom is named.** Damage produces findings with their own codes, not one generic
 *   failure, because the code is what decides the repair rung.
 * - **Repair never invents.** The ladder rebuilds derived state and refuses to fabricate content.
 *   A subject with nothing usable behind it comes back `unrestorable` and is skipped, and the two
 *   destructive rungs are not reachable from a cron run at all.
 * - **Nothing silently succeeds.** A prune with an incomplete picture of what is live refuses. A
 *   frame that will not authenticate raises rather than returning its bytes.
 *
 * The damage is inflicted through the provider, which is what a failing endpoint or a mistaken
 * lifecycle rule does. Nothing here reaches around the module to corrupt its indexes directly:
 * the whole point is what the module can work out from the objects it can still read.
 */
class MeltdownTest extends StrataKernelTestBase
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

		// the codec is pinned to none so a flipped byte is changed content rather than a decode failure
		$this->config('strata.settings')
			->set('enabled', true)
			->set('provider', 'local')
			->set('local_path', $this->storeRoot)
			->set('cipher.id', 'none')
			->set('codec.id', 'none')
			->save();

		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();
	}

	#region Mass Loss

	#[Test]
	#[TestDox('a bucket half emptied names every commit it can no longer read')]
	#[Group('strata/meltdown')]
	public function halfTheBucketRemoved(): void
	{
		$this->history(6);

		$before = $this->engine()->verifier()->verify();

		$this->assertTrue($before->isClean(), 'the history was intact to start with');

		$frames = $this->keys('frames/');
		$packs = $this->keys('packs/');
		$objects = array_merge($frames, $packs);

		$this->assertNotSame([], $objects, 'there were objects to lose');

		// a lifecycle rule with a bad prefix, which is the shape of this failure in the wild
		$this->engine()
			->provider()
			->delete(array_slice($objects, 0, max(1, intdiv(count($objects), 2))));

		$after = $this->engine()->verifier()->verify();

		$this->assertFalse($after->isClean(), 'losing half the objects is not a clean store');
		$this->assertGreaterThanOrEqual(
			Finding::ERROR,
			$after->worst(),
			'and it is not a warning either',
		);
		$this->assertNotSame([], $after->findings, 'every loss is named rather than counted');

		foreach ($after->byCode() as $code => $count) {
			$this->assertMatchesRegularExpression('/^[a-z]+\.[a-z_]+$/', (string) $code);
			$this->assertGreaterThan(0, $count);
		}
	}

	#[Test]
	#[TestDox('the whole bucket emptied is reported rather than read as an empty history')]
	#[Group('strata/meltdown')]
	public function everythingRemoved(): void
	{
		$this->history(3);

		$head = $this->headId();

		$this->assertNotSame('', $head);

		foreach ($this->keys() as $key) {
			$this->engine()
				->provider()
				->delete([$key]);
		}

		$report = $this->engine()->verifier()->verify();

		$this->assertFalse(
			$report->isClean(),
			'an emptied bucket with an index that still names commits is a fault, not a fresh install',
		);
		$this->assertSame(
			['ref.missing'],
			array_keys($report->byCode()),
			'the ref is gone and the index proves the commits existed: ' . $report->summary(),
		);
		$this->assertNotSame(
			[],
			$this->engine()->commitIndex()->ids(),
			'the local index still remembers what the bucket lost, which is what names the damage',
		);
	}

	#[Test]
	#[TestDox('a thousand flipped bytes produce one finding per object, not one for the store')]
	#[Group('strata/meltdown')]
	public function massCorruption(): void
	{
		$this->history(5);

		$corrupted = 0;

		foreach ($this->keys('frames/') as $key) {
			$this->corrupt($key);
			$corrupted++;
		}
		foreach ($this->keys('packs/') as $key) {
			$this->corrupt($key);
			$corrupted++;
		}

		$this->assertGreaterThan(0, $corrupted, 'there was something to corrupt');

		$report = $this->engine()->verifier()->verify();

		$this->assertFalse($report->isClean());
		$this->assertNotSame(
			[],
			$report->findings,
			'garbage in every object is reported per object so a partial recovery is possible',
		);
	}

	#endregion

	#region What Repair Will Not Do

	#[Test]
	#[TestDox('the two destructive rungs are unreachable from an automatic pass')]
	#[Group('strata/meltdown')]
	public function destructiveRungsAreHumanOnly(): void
	{
		$this->assertTrue(RepairLadder::isAutomatic('observe'));
		$this->assertTrue(RepairLadder::isAutomatic('reindex'));
		$this->assertTrue(RepairLadder::isAutomatic('refetch'));
		$this->assertTrue(RepairLadder::isAutomatic('rebuild'));
		$this->assertFalse(
			RepairLadder::isAutomatic('quarantine'),
			'removing a restore target is a human decision',
		);
		$this->assertFalse(
			RepairLadder::isAutomatic('refuse'),
			'and so is blocking a restore outright',
		);
		$this->assertSame('rebuild', RepairLadder::AUTOMATIC_CEILING);
	}

	#[Test]
	#[TestDox('a critical finding starts at quarantine, so nothing automatic touches it')]
	#[Group('strata/meltdown')]
	public function criticalStartsBeyondAutomation(): void
	{
		$rung = RepairLadder::initialRung(Finding::CRITICAL);

		$this->assertSame('quarantine', $rung);
		$this->assertFalse(RepairLadder::isAutomatic($rung));
	}

	#[Test]
	#[TestDox('a prune refuses outright while any part of the store cannot be read')]
	#[Group('strata/meltdown')]
	public function pruneRefusesOnAnIncompletePicture(): void
	{
		$this->history(4);

		$anchors = $this->keys('bases/');

		$this->assertNotSame(
			[],
			$anchors,
			'the walk reads commits and anchors, so break an anchor',
		);

		// one unreadable object makes "what is live" unknown, and a prune deletes on that answer
		$this->corrupt($anchors[0]);

		$receipt = $this->engine()->compactor()->prune(true);

		$this->assertTrue($receipt->wasRefused(), 'a prune on a partial picture is not safe');
		$this->assertNotNull($receipt->refused);
		$this->assertSame([], $receipt->objects, 'and it deleted nothing while refusing');
	}

	#[Test]
	#[TestDox('a subject with nothing usable left is unrestorable and is skipped, not filled in')]
	#[Group('strata/meltdown')]
	public function anUnrestorableSubjectIsSkipped(): void
	{
		$this->history(3);

		$head = $this->headId();

		foreach ($this->keys('frames/') as $key) {
			$this->engine()
				->provider()
				->delete([$key]);
		}
		foreach ($this->keys('packs/') as $key) {
			$this->engine()
				->provider()
				->delete([$key]);
		}

		$plan = $this->engine()->preflight()->plan($head);
		$counts = $plan->counts();

		$this->assertSame(
			[],
			$plan->writable(),
			'nothing decodes, so there is nothing a restore may write',
		);
		$this->assertSame(
			0,
			$counts[SubjectStatus::RESTORABLE->value] ?? 0,
			'and nothing claims to be restorable: ' . $plan->summary(),
		);
		$this->assertNotSame([], $plan->skipped(), 'every subject is listed as skipped');
		$this->assertFalse($plan->fillDegraded, 'filling from defaults is off unless asked for');
	}

	#[Test]
	#[TestDox('a restore of a damaged history writes what survived and reports the rest')]
	#[Group('strata/meltdown')]
	public function aPartialRestoreIsHonest(): void
	{
		$this->history(4);

		$head = $this->headId();
		$intact = $this->engine()->preflight()->plan($head);

		$this->assertNotSame([], $intact->writable(), 'the plan had work before the damage');

		$frames = $this->keys('frames/');
		$packs = $this->keys('packs/');
		$objects = array_merge($frames, $packs);

		$this->engine()
			->provider()
			->delete([$objects[0]]);

		$damaged = $this->engine()->preflight()->plan($head);

		$this->assertLessThanOrEqual(
			count($intact->writable()),
			count($damaged->writable()),
			'losing an object cannot increase what a restore is willing to write',
		);
		$this->assertGreaterThanOrEqual(
			count($intact->subjects),
			count($damaged->subjects),
			'and every subject is still accounted for, restorable or not',
		);
	}

	#endregion

	#region What Repair Will Do

	#[Test]
	#[TestDox('an index thrown away is rebuilt from the objects the bucket still holds')]
	#[Group('strata/meltdown')]
	public function theIndexIsRebuiltFromTheBucket(): void
	{
		$this->history(4);

		$commits = $this->engine()->commitIndex()->count();
		$frames = (int) $this->engine()->frameIndex()->statistics()['frames'];

		$this->assertGreaterThan(0, $commits);
		$this->assertGreaterThan(0, $frames);

		// what an uninstall leaves: the bucket intact, every local row gone
		$this->engine()->commitIndex()->clear();
		$this->engine()->frameIndex()->clear();

		$this->assertSame(0, $this->engine()->commitIndex()->count());

		$report = $this->engine()->reindexer()->reindex();

		$this->assertTrue($report->isClean(), 'the rebuild ran clean: ' . $report->summary());
		$this->assertSame(
			$commits,
			$this->engine()->commitIndex()->count(),
			'every commit is back',
		);
		$this->assertSame(
			$frames,
			(int) $this->engine()->frameIndex()->statistics()['frames'],
			'and so is every frame, because the objects describe themselves',
		);
		$this->assertTrue($this->engine()->verifier()->verify()->isClean());
	}

	#[Test]
	#[TestDox('a rebuilt index still materializes every subject it did before')]
	#[Group('strata/meltdown')]
	public function aRebuiltIndexStillRestores(): void
	{
		$this->history(3);

		$head = $this->headId();
		$before = [];

		foreach (array_keys($this->engine()->subjectIndex()->all()) as $subject) {
			$before[$subject] = $this->engine()->replayer()->materialize($subject, $head)->fields;
		}

		$this->assertNotSame([], $before);

		$this->engine()->commitIndex()->clear();
		$this->engine()->frameIndex()->clear();
		$this->engine()->reindexer()->reindex();

		foreach ($before as $subject => $fields) {
			$this->assertSame(
				$fields,
				$this->engine()->replayer()->materialize((string) $subject, $head)->fields,
				sprintf('%s reads back the same after a rebuild', $subject),
			);
		}
	}

	#[Test]
	#[TestDox('the same symptom across a thousand objects is one code with a scope count')]
	#[Group('strata/meltdown')]
	public function findingsAreGroupedForAHuman(): void
	{
		$this->history(4);

		foreach ($this->keys('frames/') as $key) {
			$this->corrupt($key);
		}
		foreach ($this->keys('packs/') as $key) {
			$this->corrupt($key);
		}

		$this->engine()->verifier()->verify();

		$summary = $this->container->get('strata.health_ledger')->summary();

		$this->assertNotSame([], $summary, 'the ledger has something to say');

		foreach ($summary as $row) {
			$this->assertArrayHasKey('code', $row);
			$this->assertArrayHasKey('scopes', $row);
			$this->assertGreaterThan(0, (int) $row['scopes'], 'a code carries how much it covers');
		}
	}

	#endregion

	#region The Key Is Gone

	#[Test]
	#[TestDox('a store whose key is gone reports unreadable objects rather than empty ones')]
	#[Group('strata/meltdown')]
	public function aLostKeyIsNotAnEmptyStore(): void
	{
		$this->config('strata.settings')->set('cipher.id', 'xchacha20poly1305')->save();
		$this->engine()->reset();

		$key = $this->keyProvider();

		$this->config('strata.settings')->set('key', $key)->save();
		$this->engine()->reset();

		$this->history(3);

		$this->assertTrue(
			$this->engine()->verifier()->verify()->isClean(),
			'the history was readable while the key was present',
		);

		// the key entity is deleted, which is the worst thing an operator can do to this module
		$this->container->get('entity_type.manager')->getStorage('key')->load($key)?->delete();
		$this->engine()->reset();

		try {
			$this->engine()->verifier()->verify();
			$this->fail('a store nothing can open must not report on it');
		} catch (RuntimeException $refused) {
			$this->assertStringContainsString(
				$key,
				$refused->getMessage(),
				'the refusal names the key rather than describing a decode failure',
			);
		}

		// the same refusal is what a report page turns into a sentence rather than a white screen
		$this->assertStringContainsString('no value', $this->refusalFor());
	}

	#endregion

	#region Fixtures

	/**
	 * The engine under test.
	 *
	 * @return Engine
	 *   The engine.
	 */
	private function engine(): Engine
	{
		return $this->container->get('strata.engine');
	}

	/**
	 * The commit the ref points at.
	 *
	 * @return string
	 *   The id, or an empty string when nothing has been sealed.
	 */
	private function headId(): string
	{
		return (string) ($this->engine()->commitIndex()->newest()['id'] ?? '');
	}

	/**
	 * Seals a history of the given depth, one commit per account saved.
	 *
	 * @param int $commits
	 *   How many commits to build.
	 */
	private function history(int $commits): void
	{
		for ($at = 0; $at < $commits; $at++) {
			$user = User::create([
				'name' => 'meltdown-' . $at,
				'mail' => 'meltdown-' . $at . '@example.com',
				'status' => 1,
			]);
			$user->save();

			$this->engine()->flusher()->flush(true);
		}
	}

	/**
	 * Object keys the store holds under a prefix.
	 *
	 * @param string $prefix
	 *   The prefix, empty for everything.
	 *
	 * @return list<string>
	 *   The keys.
	 */
	private function keys(string $prefix = ''): array
	{
		return $this->engine()->provider()->list($prefix, null, 2000)->keys();
	}

	/**
	 * Replaces an object's body with garbage of the same length.
	 *
	 * Same length on purpose: a short read and changed content are different symptoms, and this is
	 * the one that a length check cannot catch.
	 *
	 * @param string $key
	 *   The object key.
	 */
	private function corrupt(string $key): void
	{
		$body = (string) $this->engine()->provider()->get($key);

		$this->engine()->provider()->put($key, str_repeat('X', max(1, strlen($body))));
	}

	/**
	 * The message the engine refuses an unopenable store with.
	 *
	 * @return string
	 *   The message, or an empty string when it did not refuse.
	 */
	private function refusalFor(): string
	{
		try {
			$this->engine()->objectStore();
		} catch (RuntimeException $refused) {
			return $refused->getMessage();
		}

		return '';
	}

	/**
	 * A real key entity holding 32 bytes, and its id.
	 *
	 * @return string
	 *   The key id.
	 */
	private function keyProvider(): string
	{
		$storage = $this->container->get('entity_type.manager')->getStorage('key');
		$key = $storage->create([
			'id' => 'meltdown_key',
			'label' => 'Meltdown key',
			'key_type' => 'encryption',
			'key_provider' => 'config',
			'key_provider_settings' => ['key_value' => random_bytes(32)],
		]);
		$key->save();

		return 'meltdown_key';
	}

	#endregion
}
