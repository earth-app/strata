<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Cas\FrameIndexInterface;
use Drupal\strata\Engine;
use Drupal\strata\Tree\CommitIndex;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves the local index is derived state that the bucket can reproduce.
 *
 * This is the lane that makes an uninstall safe. Dropping the tables has to be recoverable, or the
 * module cannot pass review with a clean uninstall and a site that removes it loses every restore
 * point it paid to store.
 */
class ReindexTest extends StrataKernelTestBase
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

	private function frames(): FrameIndexInterface
	{
		return $this->container->get('strata.frame_index');
	}

	private function commits(): CommitIndex
	{
		return $this->container->get('strata.commit_index');
	}

	private function user(string $name): User
	{
		$user = User::create(['name' => $name, 'mail' => $name . '@example.com', 'status' => 1]);
		$user->save();

		return $user;
	}

	private function flush(string $name): void
	{
		$this->user($name);
		$this->engine()->flusher()->flush(true);
	}

	/**
	 * Every object key in the store, with its bytes, so a byte-for-byte comparison is possible.
	 *
	 * @return array<string, string>
	 *   Object key keyed to its content.
	 */
	private function bucket(): array
	{
		$provider = $this->engine()->provider();
		$objects = [];

		foreach ($provider->list('', null, 5000)->keys() as $key) {
			$objects[$key] = $provider->get($key);
		}

		ksort($objects);

		return $objects;
	}

	#region The Invariant

	#[Test]
	#[TestDox('dropping the local tables leaves the bucket byte-identical')]
	#[Group('strata/reindex')]
	public function droppingTheIndexDoesNotTouchTheBucket(): void
	{
		$this->flush('kept-one');
		$this->flush('kept-two');

		$before = $this->bucket();
		$this->assertNotEmpty($before);

		$this->frames()->clear();
		$this->commits()->clear();

		$this->assertSame($before, $this->bucket());
		$this->assertSame(0, $this->commits()->count());
		$this->assertSame(0, $this->frames()->statistics()['frames']);
	}

	#[Test]
	#[TestDox('a reindex after the tables are dropped reproduces the commit log')]
	#[Group('strata/reindex')]
	public function reindexReproducesTheCommitLog(): void
	{
		$this->flush('reproduced-one');
		$this->flush('reproduced-two');
		$this->flush('reproduced-three');

		$expected = $this->commits()->ids();
		$this->assertCount(3, $expected);

		$this->frames()->clear();
		$this->commits()->clear();

		$report = $this->engine()->reindexer()->reindex();

		$this->assertTrue($report->isClean(), implode('; ', $report->problems));
		$this->assertSame(3, $report->commits);
		$this->assertSame($expected, $this->commits()->ids());
	}

	#[Test]
	#[TestDox('a reindexed store verifies clean, so the rebuilt index can read every frame')]
	#[Group('strata/reindex')]
	public function reindexedStoreVerifiesClean(): void
	{
		$this->flush('verifiable-one');
		$this->flush('verifiable-two');

		$this->frames()->clear();
		$this->commits()->clear();
		$this->engine()->reindexer()->reindex();

		$report = $this->engine()->verifier()->verify();

		$this->assertTrue($report->isClean(), implode(', ', array_keys($report->byCode())));
		$this->assertSame(2, $report->commits);
		$this->assertGreaterThan(0, $report->frames);
		$this->assertGreaterThan(0, $report->bytes);
	}

	#[Test]
	#[TestDox('the rebuilt frame records carry the codec, cipher and length the objects recorded')]
	#[Group('strata/reindex')]
	public function rebuiltRecordsMatchTheOriginals(): void
	{
		$this->flush('faithful');

		$before = [];
		foreach ($this->engine()->segmentReader()->all() as $manifest) {
			foreach ($manifest->frames() as $hash) {
				$before[$hash] = $this->frames()->get($hash)?->jsonSerialize();
			}
		}

		$this->assertNotEmpty($before);

		$this->frames()->clear();
		$this->commits()->clear();
		$this->engine()->reindexer()->reindex();

		foreach ($before as $hash => $original) {
			$rebuilt = $this->frames()->get((string) $hash)?->jsonSerialize();

			$this->assertNotNull($rebuilt, sprintf('frame %s came back', $hash));
			$this->assertSame($original['rawSize'], $rebuilt['rawSize']);
			$this->assertSame($original['storedSize'], $rebuilt['storedSize']);
			$this->assertSame($original['codec'], $rebuilt['codec']);
			$this->assertSame($original['cipher'], $rebuilt['cipher']);
			$this->assertSame($original['dictionary'], $rebuilt['dictionary']);
			$this->assertSame($original['pack'], $rebuilt['pack']);
			$this->assertSame($original['offset'], $rebuilt['offset']);
		}
	}

	#[Test]
	#[TestDox('reference counts come back matching what the flush attributed')]
	#[Group('strata/reindex')]
	public function referenceCountsComeBack(): void
	{
		$this->flush('counted-one');
		$this->flush('counted-two');

		$before = [];
		foreach ($this->engine()->segmentReader()->all() as $manifest) {
			foreach ($manifest->frames() as $hash) {
				$before[$hash] = $this->frames()->get($hash)?->references;
			}
		}

		$this->frames()->clear();
		$this->commits()->clear();
		$this->engine()->reindexer()->reindex();

		foreach ($before as $hash => $references) {
			$this->assertSame(
				$references,
				$this->frames()->get((string) $hash)?->references,
				sprintf('frame %s came back with the same reference count', $hash),
			);
		}

		$this->assertSame(0, $this->frames()->statistics()['orphans']);
	}

	#endregion

	#region Idempotence

	#[Test]
	#[TestDox('reindexing twice produces the same index, not doubled counts')]
	#[Group('strata/reindex')]
	public function reindexIsIdempotent(): void
	{
		$this->flush('twice');

		$first = $this->engine()->reindexer()->reindex();
		$statistics = $this->frames()->statistics();
		$ids = $this->commits()->ids();

		$second = $this->engine()->reindexer()->reindex();

		$this->assertSame($first->frames, $second->frames);
		$this->assertSame($first->commits, $second->commits);
		$this->assertSame($first->references, $second->references);
		$this->assertSame($statistics, $this->frames()->statistics());
		$this->assertSame($ids, $this->commits()->ids());
	}

	#[Test]
	#[TestDox('a reindex over an empty bucket writes nothing and reports nothing wrong')]
	#[Group('strata/reindex')]
	public function reindexOverEmptyBucket(): void
	{
		$report = $this->engine()->reindexer()->reindex();

		$this->assertTrue($report->isClean());
		$this->assertSame(0, $report->commits);
		$this->assertSame(0, $report->frames);
		$this->assertSame(0, $report->packs);
		$this->assertStringContainsString('0 commits', $report->summary());
	}

	#[Test]
	#[TestDox('a fresh reindex drops a row for an object that has gone away')]
	#[Group('strata/reindex')]
	public function freshReindexForgetsDeletedObjects(): void
	{
		$this->flush('stale');

		$packs = $this->engine()->provider()->list('packs/', null, 100)->keys();
		$this->assertNotEmpty($packs);

		$before = $this->frames()->statistics()['frames'];
		$this->engine()
			->provider()
			->delete([$packs[0]]);

		$report = $this->engine()->reindexer()->reindex();

		$this->assertGreaterThan(0, $before);
		$this->assertSame(0, $report->packs);
		$this->assertLessThan($before, $this->frames()->statistics()['frames']);
	}

	#endregion

	#region A Lost Ref

	#[Test]
	#[TestDox('a rebuild recovers every commit but does not decide which one is current')]
	#[Group('strata/reindex')]
	public function aRebuildDoesNotAdoptAHeadOnItsOwn(): void
	{
		$this->flush('before-the-ref-went');
		$this->flush('after-the-ref-went');

		foreach ($this->engine()->provider()->list('refs/', null, 100)->keys() as $key) {
			$this->engine()
				->provider()
				->delete([$key]);
		}

		$report = $this->engine()->reindexer()->reindex();

		$this->assertGreaterThan(0, $report->commits, 'the commits are still in the bucket');
		$this->assertNull(
			$this->engine()->refStore()->read(),
			'a ref is a restore target, so a rebuild leaves it alone rather than choosing one',
		);
	}

	#[Test]
	#[TestDox('a store whose ref was deleted verifies as damaged rather than as empty')]
	#[Group('strata/reindex')]
	public function aLostRefIsNotAnEmptyStore(): void
	{
		$this->flush('had-a-ref');

		foreach ($this->engine()->provider()->list('refs/', null, 100)->keys() as $key) {
			$this->engine()
				->provider()
				->delete([$key]);
		}

		$report = $this->engine()->verifier()->verify();

		$this->assertFalse($report->isClean());
		$this->assertSame(
			['ref.missing'],
			array_keys($report->byCode()),
			'the local index still names commits, which is what tells the two apart',
		);
	}

	#[Test]
	#[TestDox('adopting the newest recovered commit makes a headless store walkable again')]
	#[Group('strata/reindex')]
	public function adoptingARecoveredHeadRestoresTheHistory(): void
	{
		$this->flush('first');
		$this->flush('second');

		$head = (string) $this->engine()->refStore()->read();

		$this->assertNotSame('', $head);

		foreach ($this->engine()->provider()->list('refs/', null, 100)->keys() as $key) {
			$this->engine()
				->provider()
				->delete([$key]);
		}

		$this->engine()->reindexer()->reindex();

		$newest = (array) $this->engine()->commitIndex()->newest();

		$this->assertSame(
			$head,
			(string) $newest['id'],
			'the newest recovered commit is the old head',
		);

		$this->engine()->refStore()->write((string) $newest['id']);

		$this->assertSame($head, $this->engine()->refStore()->read());
		$this->assertTrue(
			$this->engine()->verifier()->verify()->isClean(),
			'a history with its head back walks and reads as it did before',
		);
	}

	#endregion

	#region What It Refuses To Guess

	#[Test]
	#[TestDox('a pack with no frame directory is skipped and named rather than half-read')]
	#[Group('strata/reindex')]
	public function packWithoutDirectoryIsSkipped(): void
	{
		$this->flush('directoryless');

		$packs = $this->engine()->provider()->list('packs/', null, 100)->keys();
		$this->engine()->provider()->put($packs[0], 'frames with no directory at the end');

		$report = $this->engine()->reindexer()->reindex();

		$this->assertFalse($report->isClean());
		$this->assertSame(1, $report->skipped);
		$this->assertStringContainsString($packs[0], $report->problems[0]);
		$this->assertStringContainsString('STRATA-PAK-1', $report->problems[0]);
	}

	#[Test]
	#[TestDox('a commit object that does not hash to its key is skipped, not indexed as itself')]
	#[Group('strata/reindex')]
	public function corruptCommitIsSkipped(): void
	{
		$this->flush('corrupt-commit');

		$keys = $this->engine()->provider()->list('commits/', null, 100)->keys();
		$this->assertNotEmpty($keys);
		$this->engine()->provider()->put($keys[0], '{"tree":"nonsense"}');
		$this->engine()->commitLog()->flushCache();

		$report = $this->engine()->reindexer()->reindex();

		$this->assertFalse($report->isClean());
		$this->assertSame(0, $report->commits);
		$this->assertStringContainsString('does not match its id', implode(' ', $report->problems));
	}

	#endregion
}
