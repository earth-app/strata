<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Engine;
use Drupal\strata\Flush\Lease;
use Drupal\strata\Journal\JournalInterface;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Tree\CommitIndex;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

/**
 * Proves a captured save reaches storage through the flush pipeline.
 */
class FlushTest extends StrataKernelTestBase
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

		// the local provider writes inside the test site, and encryption is off until a key exists
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

	private function journal(): JournalInterface
	{
		return $this->container->get('strata.journal');
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
		return $this->engine()->provider()->list($prefix, null, 1000)->keys();
	}

	/**
	 * Whether an entity subject is still waiting in the journal.
	 *
	 * Cron writes its own state as it runs - `system.cron_last` for one - and state capture records
	 * that like any other write, so a total pending count is not a statement about the window under
	 * test. This asks about the one operation the test actually created.
	 *
	 * @param string $subject
	 *   The subject key, such as "user:3".
	 *
	 * @return bool
	 *   TRUE when the operation has not been sealed yet.
	 */
	private function isPending(string $subject): bool
	{
		foreach ($this->journal()->read(5000) as $entry) {
			if (
				$entry['operation']->realm === Realm::ENTITY &&
				$entry['operation']->subject === $subject
			) {
				return true;
			}
		}

		return false;
	}

	#region Assembly

	#[Test]
	#[TestDox('the engine builds every part of the pipeline from configuration')]
	#[Group('strata/flush')]
	public function engineAssemblesFromConfiguration(): void
	{
		$engine = $this->engine();

		$this->assertSame('local', $engine->provider()->id());
		$this->assertSame('none', $engine->cipher()->id());
		$this->assertSame(
			$this->container->get('strata.frame_index'),
			$engine->objectStore()->index(),
			'the pipeline shares the index that survives a request',
		);
		$this->assertNull($engine->objectStore()->dictionaryId());
		$this->assertSame(15, $engine->flushPolicy()->maxAge());
		$this->assertSame(4194304, $engine->flushPolicy()->maxBytes());
		$this->assertNotNull($engine->flusher());
		$this->assertNotNull($engine->commitLog());
	}

	#[Test]
	#[TestDox('encryption on with no key refuses rather than storing plaintext')]
	#[Group('strata/flush')]
	public function encryptionWithoutKeyIsRefused(): void
	{
		$this->config('strata.settings')->set('cipher.id', 'xchacha20poly1305')->save();
		$this->engine()->reset();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no key is configured');

		$this->engine()->cipher();
	}

	#[Test]
	#[TestDox('an unregistered provider is named rather than silently falling back')]
	#[Group('strata/flush')]
	public function unknownProviderIsNamed(): void
	{
		$this->config('strata.settings')->set('provider', 'sftp')->save();
		$this->engine()->reset();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('"sftp" is configured but not registered');

		$this->engine()->provider();
	}

	#endregion

	#region The whole path

	#[Test]
	#[TestDox('a saved entity reaches storage as a segment, an anchor and a commit')]
	#[Group('strata/flush')]
	public function savedEntityReachesStorage(): void
	{
		$this->user('flushed');

		$this->assertSame(1, $this->journal()->pending());
		$this->assertSame([], $this->keys(), 'nothing is stored until a flush');

		$result = $this->engine()->flusher()->flush(true);

		$this->assertTrue($result->ran);
		$this->assertSame('forced', $result->reason);
		$this->assertSame(1, $result->captured);
		$this->assertSame(1, $result->stored);
		$this->assertGreaterThan(0, $result->rawBytes);
		$this->assertSame(1, $result->trimmed);

		$this->assertNotEmpty($this->keys('segments/'));
		$this->assertNotEmpty($this->keys('bases/'));
		$this->assertNotEmpty($this->keys('commits/'));
		$this->assertNotEmpty($this->keys('refs/'));
		$this->assertSame([], $this->keys('trees/'), 'no per-flush index is written');

		$head = $this->engine()->commitLog()->head();

		$this->assertNotNull($head);
		$this->assertSame($result->commit, $head->id());
		$this->assertTrue($head->isRoot());
		$this->assertTrue($head->isAnchor(), 'the first commit anchors a replay');
		$this->assertSame(1, $head->operations);
		$this->assertStringContainsString('entity', $head->label);
		// a kernel test saves as the anonymous user, so the commit is attributed to nobody
		$this->assertNull($head->actor);
	}

	#[Test]
	#[TestDox('the index records what a flush actually wrote, not a zero the commit carries')]
	#[Group('strata/flush')]
	public function theIndexRecordsWhatTheFlushWrote(): void
	{
		$this->user('measured');

		$result = $this->engine()->flusher()->flush(true);

		$this->assertTrue($result->ran);

		$row = $this->container
			->get('database')
			->select(CommitIndex::TABLE, 'c')
			->fields('c', ['raw_bytes', 'stored_bytes'])
			->condition('id', (string) $result->commit)
			->execute()
			?->fetchAssoc();

		$this->assertIsArray($row);
		$this->assertGreaterThan(0, (int) $row['raw_bytes']);
		// a commit is addressed by its own json, so it cannot carry its own size; the flush measures
		// it and passes it to the index, and without that every "Stored" column reads 0 B at 1.00x
		$this->assertGreaterThan(
			0,
			(int) $row['stored_bytes'],
			'the flush measured the bytes it sent to the provider',
		);
	}

	#[Test]
	#[TestDox('a frame the store already holds costs the next commit nothing')]
	#[Group('strata/flush')]
	public function aDeduplicatedFlushIsRecordedAsCostingLess(): void
	{
		$this->user('first');
		$first = $this->engine()->flusher()->flush(true);

		$store = $this->engine()->objectStore();
		$before = $store->written();

		$store->write('a payload the store already holds');
		$store->commit();

		$this->assertGreaterThan($before, $store->written(), 'the first write cost something');

		$after = $store->written();

		$store->write('a payload the store already holds');
		$store->commit();

		$this->assertSame($after, $store->written(), 'storing it again wrote nothing');
		$this->assertTrue($first->ran);
	}

	#[Test]
	#[TestDox('a window of one person\'s work is attributed to them')]
	#[Group('strata/flush')]
	public function singleActorIsAttributed(): void
	{
		$editor = $this->user('editor');
		$this->engine()->flusher()->flush(true);

		$this->container->get('current_user')->setAccount($editor);

		$editor->set('name', 'editor-renamed');
		$editor->save();

		$result = $this->engine()->flusher()->flush(true);
		$head = $this->engine()->commitLog()->head();

		$this->assertTrue($result->ran);
		$this->assertSame((int) $editor->id(), $head?->actor);
	}

	#[Test]
	#[TestDox('a window covering two people is attributed to nobody rather than to the last one')]
	#[Group('strata/flush')]
	public function mixedActorsAreAttributedToNobody(): void
	{
		$first = $this->user('first-editor');
		$second = $this->user('second-editor');
		$this->engine()->flusher()->flush(true);

		$this->container->get('current_user')->setAccount($first);
		$first->set('name', 'first-renamed');
		$first->save();

		$this->container->get('current_user')->setAccount($second);
		$second->set('name', 'second-renamed');
		$second->save();

		$this->engine()->flusher()->flush(true);

		$this->assertNull(
			$this->engine()->commitLog()->head()?->actor,
			'a commit covering two people names neither',
		);
	}

	#[Test]
	#[TestDox('the payload a flush stored reads back through the object store')]
	#[Group('strata/flush')]
	public function storedPayloadReadsBack(): void
	{
		$this->user('readable');
		$result = $this->engine()->flusher()->flush(true);

		$manifest = $this->engine()->segmentReader()->read((string) $result->segment);

		$this->assertSame(1, $manifest->count());

		$operation = $manifest->operations[0];
		$payload = $this->engine()->objectStore()->read($manifest->payloadFor($operation));

		/** @var array<string, mixed> $decoded */
		$decoded = json_decode($payload, true);

		$this->assertArrayHasKey('name', $decoded);
		$this->assertSame(Realm::ENTITY, $operation->realm);
	}

	#[Test]
	#[TestDox('the journal is emptied only after the ref has moved')]
	#[Group('strata/flush')]
	public function journalIsTrimmedLast(): void
	{
		$this->user('trimmed');

		$this->assertSame(1, $this->journal()->pending());

		$this->engine()->flusher()->flush(true);

		$this->assertSame(0, $this->journal()->pending());
		$this->assertNotNull($this->engine()->commitLog()->head());
	}

	#[Test]
	#[TestDox('a second flush builds on the first, so history is a chain')]
	#[Group('strata/flush')]
	public function successiveFlushesChain(): void
	{
		$user = $this->user('one');
		$first = $this->engine()->flusher()->flush(true);

		$user->set('name', 'two');
		$user->save();
		$second = $this->engine()->flusher()->flush(true);

		$this->assertNotSame($first->commit, $second->commit);

		$head = $this->engine()->commitLog()->head();

		$this->assertSame($second->commit, $head?->id());
		$this->assertSame($first->commit, $head?->parent);
		$this->assertFalse($head?->isRoot());

		$walked = iterator_to_array($this->engine()->commitLog()->walk((string) $second->commit));
		$this->assertCount(2, $walked);
	}

	#[Test]
	#[TestDox('repeated saves to one subject collapse into a single stored operation')]
	#[Group('strata/flush')]
	public function repeatedSavesCollapse(): void
	{
		$user = $this->user('collapsing');

		for ($i = 0; $i < 4; $i++) {
			$user->set('name', "collapsing-$i");
			$user->save();
		}

		$result = $this->engine()->flusher()->flush(true);

		$this->assertSame(5, $result->captured);
		$this->assertSame(1, $result->stored, 'one subject leaves one operation');
		$this->assertSame(4, $result->collapsed());
	}

	#endregion

	#region Policy and safety

	#[Test]
	#[TestDox('a flush that no bound is due for does not run')]
	#[Group('strata/flush')]
	public function flushWaitsForBound(): void
	{
		$this->user('waiting');

		$result = $this->engine()->flusher()->flush();

		$this->assertFalse($result->ran);
		$this->assertSame('no flush bound reached', $result->skipped);
		$this->assertSame(1, $this->journal()->pending(), 'the window is untouched');
	}

	#[Test]
	#[TestDox('a flush with nothing pending does nothing')]
	#[Group('strata/flush')]
	public function emptyFlushDoesNothing(): void
	{
		$result = $this->engine()->flusher()->flush(true);

		$this->assertFalse($result->ran);
		$this->assertSame('nothing pending', $result->skipped);
		$this->assertSame([], $this->keys());
	}

	#[Test]
	#[TestDox('an operation count over the bound makes a flush due')]
	#[Group('strata/flush')]
	public function operationBoundMakesFlushDue(): void
	{
		$this->config('strata.settings')->set('flush.max_ops', 2)->save();
		$this->engine()->reset();

		$this->assertFalse($this->engine()->flusher()->isDue());

		$user = $this->user('bounded');
		$user->set('name', 'bounded-two');
		$user->save();

		$this->assertTrue($this->engine()->flusher()->isDue());
		$this->assertSame('operations', $this->engine()->flusher()->flush()->reason);
	}

	#[Test]
	#[TestDox('the recovery-point lag reports how far behind the store is')]
	#[Group('strata/flush')]
	public function lagReportsHowFarBehind(): void
	{
		$this->assertSame(0.0, $this->engine()->flusher()->lag());

		$this->user('lagging');

		$this->assertGreaterThanOrEqual(0.0, $this->engine()->flusher()->lag());
		$this->assertLessThan(5.0, $this->engine()->flusher()->lag());

		$this->engine()->flusher()->flush(true);

		$this->assertSame(0.0, $this->engine()->flusher()->lag());
	}

	#[Test]
	#[TestDox('a held lease stops a second flush from sealing the same window')]
	#[Group('strata/flush')]
	public function heldLeaseBlocksSecondFlush(): void
	{
		$this->user('contended');

		$other = new Lease($this->container->get('database'));
		$this->assertTrue($other->acquire(Lease::FLUSH));

		$result = $this->engine()->flusher()->flush(true);

		$this->assertFalse($result->ran);
		$this->assertSame('another flush holds the lease', $result->skipped);
		$this->assertSame(1, $this->journal()->pending(), 'the window survives for the next run');

		$other->release(Lease::FLUSH);
		$this->assertTrue($this->engine()->flusher()->flush(true)->ran);
	}

	#[Test]
	#[TestDox('an expired lease is taken over rather than blocking forever')]
	#[Group('strata/flush')]
	public function expiredLeaseIsTakenOver(): void
	{
		$database = $this->container->get('database');
		$now = 1_755_000_000;

		$abandoned = new Lease($database, static fn(): int => $now);
		$this->assertTrue($abandoned->acquire(Lease::FLUSH, 60));

		$later = new Lease($database, static fn(): int => $now + 3600);

		$this->assertFalse(
			(new Lease($database, static fn(): int => $now))->acquire(Lease::FLUSH),
			'a live lease is not taken over',
		);
		$this->assertTrue($later->acquire(Lease::FLUSH), 'an expired one is');
	}

	#[Test]
	#[TestDox('a holder whose lease was taken over cannot release the new holder on its way out')]
	#[Group('strata/flush')]
	public function staleHolderCannotReleaseTheNewHolder(): void
	{
		$database = $this->container->get('database');
		$now = 1_755_000_000;

		$abandoned = new Lease($database, static fn(): int => $now);
		$abandoned->acquire(Lease::FLUSH, 60);

		$taker = new Lease($database, static fn(): int => $now + 3600);
		$this->assertTrue($taker->acquire(Lease::FLUSH));

		// both instances believe they hold it; only the token in the row decides
		$this->assertTrue($abandoned->holds(Lease::FLUSH));
		$this->assertFalse($abandoned->release(Lease::FLUSH), 'the stale token frees nothing');
		$this->assertTrue($taker->holds(Lease::FLUSH));

		$this->assertFalse(
			(new Lease($database, static fn(): int => $now + 3600))->acquire(Lease::FLUSH),
			'the new holder still has it',
		);
		$this->assertTrue($taker->release(Lease::FLUSH));
	}

	#[Test]
	#[
		TestDox(
			'releasing a lease this instance never took reports nothing rather than deleting one',
		),
	]
	#[Group('strata/flush')]
	public function releasingAnUnheldLeaseReportsNothing(): void
	{
		$database = $this->container->get('database');
		$holder = new Lease($database);
		$holder->acquire(Lease::FLUSH);

		$stranger = new Lease($database);

		$this->assertFalse($stranger->holds(Lease::FLUSH));
		$this->assertFalse($stranger->release(Lease::FLUSH));
		$this->assertTrue($holder->holds(Lease::FLUSH));
	}

	#[Test]
	#[TestDox('collecting removes an expired lease and leaves a live one alone')]
	#[Group('strata/flush')]
	public function collectingRemovesOnlyExpiredLeases(): void
	{
		$database = $this->container->get('database');
		$now = 1_755_000_000;

		(new Lease($database, static fn(): int => $now))->acquire(Lease::FLUSH, 60);
		(new Lease($database, static fn(): int => $now))->acquire(Lease::COMPACTION, 7_200);

		$this->assertSame(0, (new Lease($database, static fn(): int => $now))->collect());
		$this->assertSame(1, (new Lease($database, static fn(): int => $now + 3_600))->collect());

		$this->assertTrue(
			(new Lease($database, static fn(): int => $now + 3_600))->acquire(Lease::FLUSH),
			'the collected lease is free',
		);
		$this->assertFalse(
			(new Lease($database, static fn(): int => $now + 3_600))->acquire(Lease::COMPACTION),
			'the live lease survived the sweep',
		);
	}

	#[Test]
	#[TestDox('a flush that fails leaves the window in place for the next run')]
	#[Group('strata/flush')]
	public function failedFlushKeepsTheWindow(): void
	{
		$this->user('doomed');

		// pointing the store at a path that cannot be created makes every write fail
		$this->config('strata.settings')->set('local_path', '/nonexistent-strata/store')->save();
		$this->engine()->reset();

		$result = $this->engine()->flusher()->flush(true);

		$this->assertFalse($result->ran);
		$this->assertStringStartsWith('failed:', (string) $result->skipped);
		$this->assertSame(1, $this->journal()->pending(), 'nothing was lost');
	}

	#endregion

	#region Cron

	#[Test]
	#[TestDox('cron seals a window that has reached a bound')]
	#[Group('strata/flush')]
	public function cronSealsDueWindow(): void
	{
		$this->config('strata.settings')->set('flush.max_ops', 1)->save();
		$this->engine()->reset();

		$user = $this->user('by-cron');
		$subject = 'user:' . $user->id();

		$this->assertSame([], $this->keys());
		$this->assertTrue($this->isPending($subject));

		$this->container->get('cron')->run();

		$this->assertNotEmpty($this->keys('commits/'), 'cron carried the save to storage');
		$this->assertFalse($this->isPending($subject), 'the window was sealed');
	}

	#[Test]
	#[TestDox('cron leaves a window alone when no bound has been reached')]
	#[Group('strata/flush')]
	public function cronWaitsForBound(): void
	{
		// the code stage appends an operation per file it finds, which is a bound this does not test
		$this->config('strata.settings')->set('capture.code', false)->save();
		$this->engine()->reset();

		$user = $this->user('patient');

		$this->container->get('cron')->run();

		$this->assertTrue($this->isPending('user:' . $user->id()), 'the window is still waiting');
		$this->assertSame([], $this->keys(), 'nothing reached storage');
	}

	#[Test]
	#[TestDox('cron does not fail when the store is unreachable')]
	#[Group('strata/flush')]
	public function cronSurvivesAnUnreachableStore(): void
	{
		$this->config('strata.settings')
			->set('flush.max_ops', 1)
			->set('local_path', '/nonexistent-strata/store')
			->save();
		$this->engine()->reset();

		$user = $this->user('unreachable');

		$this->assertTrue($this->container->get('cron')->run(), 'cron completed');
		$this->assertTrue($this->isPending('user:' . $user->id()), 'the window survives');
	}

	#[Test]
	#[TestDox('cron does nothing at all while capture is off')]
	#[Group('strata/flush')]
	public function cronDoesNothingWhileDisabled(): void
	{
		$this->config('strata.settings')->set('flush.max_ops', 1)->save();
		$this->engine()->reset();
		$this->user('captured');

		$this->config('strata.settings')->set('enabled', false)->save();
		$this->container->get('strata.capture_scope')->reset();

		$this->container->get('cron')->run();

		$this->assertSame(1, $this->journal()->pending());
		$this->assertSame([], $this->keys());
	}

	#[Test]
	#[TestDox('cron survives an engine that cannot be assembled at all')]
	#[Group('strata/flush')]
	public function cronSurvivesAnUnassembledEngine(): void
	{
		// the shipped default: encryption on with no key, so every stage refuses to be built
		$this->config('strata.settings')->set('cipher.id', 'xchacha20poly1305')->save();
		$this->engine()->reset();

		// resolving the hook happens outside Cron's own try/catch, so this used to kill every
		// module's cron and leak the cron lock
		$this->assertTrue($this->container->get('cron')->run(), 'cron completed');
		$this->assertTrue(
			$this->container->get('lock')->lockMayBeAvailable('cron'),
			'the cron lock was released',
		);
	}

	#[Test]
	#[TestDox('cron does not need capture to be on to run at all')]
	#[Group('strata/flush')]
	public function cronRunsWithAnUnconfiguredStore(): void
	{
		$this->config('strata.settings')
			->set('enabled', false)
			->set('cipher.id', 'xchacha20poly1305')
			->set('local_path', '')
			->save();
		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();

		$this->assertTrue($this->container->get('cron')->run(), 'cron completed');
	}

	#endregion
}
