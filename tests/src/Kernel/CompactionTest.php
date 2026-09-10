<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Cas\FrameIndexInterface;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Compaction\Recompressor;
use Drupal\strata\Engine;
use Drupal\strata\Event\PruneEvent;
use Drupal\strata\Event\StrataEvents;
use Drupal\strata\Tree\BaseManifest;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves compaction makes storage cheaper without making history less restorable.
 */
class CompactionTest extends StrataKernelTestBase
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

		// the flush writes at level 1 so compaction has something to gain by rewriting at 19
		$this->config('strata.settings')
			->set('enabled', true)
			->set('provider', 'local')
			->set('local_path', $this->storeRoot)
			->set('cipher.id', 'none')
			->set('codec.flush_level', 1)
			->set('codec.compaction_level', 19)
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
	 * @return list<string>
	 *   Object keys under a prefix.
	 */
	private function keys(string $prefix): array
	{
		return $this->engine()->provider()->list($prefix, null, 1000)->keys();
	}

	/**
	 * Deterministic prose-bearing content, which is what the dense setting actually wins on.
	 *
	 * Measured on this generator at 16 KiB frames: zstd level 1 reaches 4.44x and level 19 reaches
	 * 5.40x, so the rewrite is 17.8% smaller and clears the 5% threshold with room to spare. A
	 * corpus of near-identical short records does NOT clear it - level 19's larger window costs a
	 * few bytes of coder overhead there and the pack is correctly left alone - so a fixture built
	 * from one entity delta would prove nothing about recompression either way.
	 *
	 * @param int $bytes
	 *   Roughly how much content to produce.
	 *
	 * @return string
	 *   The content.
	 */
	private function corpus(int $bytes = 131072): string
	{
		mt_srand(7);

		$words = explode(
			' ',
			'the site content editor published node body field value user profile notification ' .
				'friend request activity event location prompt article page comment revision ' .
				'taxonomy term reference entity storage handler manager service plugin',
		);

		$corpus = '';

		while (strlen($corpus) < $bytes) {
			$sentence = [];

			for ($i = 0, $length = mt_rand(20, 90); $i < $length; $i++) {
				$sentence[] = $words[mt_rand(0, count($words) - 1)];
			}

			$corpus .=
				(string) json_encode([
					'nid' => mt_rand(1, 99999),
					'title' => implode(' ', array_slice($sentence, 0, 6)),
					'body' => implode(' ', $sentence),
					'changed' => mt_rand(1600000000, 1700000000),
				]) . "\n";
		}

		return $corpus;
	}

	/**
	 * A notification blob with one flag flipped and one entry appended.
	 *
	 * The rewrite class delta coding exists for, so a second version really is stored as a delta
	 * rather than standalone.
	 *
	 * @param int $version
	 *   Which version to build.
	 *
	 * @return string
	 *   The blob.
	 */
	private function blob(int $version): string
	{
		$entries = [];

		for ($i = 0; $i < 40 + $version; $i++) {
			$entries[] = [
				'id' => $i,
				'type' => 'mention',
				'actor' => 100 + ($i % 17),
				'created' => 1_750_000_000 + $i * 61,
				'read' => $i < 20 + $version,
				'subject' => 'A notification about something that happened at position ' . $i,
			];
		}

		return (string) json_encode(['uid' => 42, 'entries' => $entries]);
	}

	/**
	 * Writes the corpus through the configured store at the flush level.
	 *
	 * @return list<string>
	 *   The frame map.
	 */
	private function writeCorpus(): array
	{
		$store = $this->engine()->objectStore();
		$map = $store->write($this->corpus());
		$store->commit();

		return $map;
	}

	#region Recompression

	#[Test]
	#[TestDox('the dense setting is measurably smaller than the flush setting on real content')]
	#[Group('strata/compaction')]
	public function denseSettingWins(): void
	{
		$this->writeCorpus();

		$packs = $this->keys('packs/');
		$this->assertNotEmpty($packs);

		$result = $this->engine()->recompressor()->recompressPack($packs[0]);

		$this->assertTrue($result['rewritten'], 'level 19 beat level 1 on this corpus');
		$this->assertGreaterThan(1, $result['frames']);
		$this->assertLessThan($result['before'], $result['after']);
		$this->assertGreaterThan(
			0.1,
			1.0 - $result['after'] / $result['before'],
			'the measured gain on this generator is 17.8%, so anything under 10% is a regression',
		);
	}

	#[Test]
	#[TestDox('a rewritten pack keeps every frame readable at the same content address')]
	#[Group('strata/compaction')]
	public function rewrittenPackStaysReadable(): void
	{
		$map = $this->writeCorpus();
		$before = [];

		foreach ($map as $hash) {
			$before[$hash] = $this->engine()->objectStore()->frame($hash);
		}

		$this->assertNotEmpty($before);

		$packs = $this->keys('packs/');
		$this->assertNotEmpty($packs);

		foreach ($packs as $pack) {
			$this->engine()->recompressor()->recompressPack($pack);
		}

		foreach ($before as $hash => $plain) {
			$after = $this->engine()->objectStore()->frame((string) $hash);

			$this->assertSame($plain, $after, 'the frame reads back byte for byte');
			$this->assertSame((string) $hash, Hash::of((string) $after));
		}
	}

	#[Test]
	#[TestDox('a delta frame is re-encoded against its parent rather than the realm dictionary')]
	#[Group('strata/compaction')]
	public function aDeltaSurvivesRecompression(): void
	{
		$this->config('strata.settings')->set('delta.enabled', true)->save();
		$this->engine()->reset();

		$store = $this->engine()->objectStore();
		$first = $this->blob(0);
		$second = $this->blob(1);

		$parent = $store->write($first);
		// the parent has to be readable before the child can be coded against it
		$store->commit();

		$child = $store->write($second, $parent);
		$store->commit();

		$record = $this->frames()->get($child[0]);

		$this->assertNotNull($record);
		$this->assertSame(
			$parent[0],
			$record->deltaParent,
			'the second version was coded against the first, which is the case under test',
		);

		foreach ($this->keys('packs/') as $pack) {
			$this->engine()->recompressor()->recompressPack($pack, true);
		}

		$this->assertSame($first, $this->engine()->objectStore()->frame($parent[0]));
		$this->assertSame($second, $this->engine()->objectStore()->frame($child[0]));

		$rewritten = $this->frames()->get($child[0]);

		$this->assertNotNull($rewritten);
		$this->assertSame($parent[0], $rewritten->deltaParent, 'the chain is kept');
		$this->assertNull($rewritten->dictionary, 'a delta carries no dictionary of its own');

		$parentRecord = $this->frames()->get($parent[0]);

		$this->assertNotNull($parentRecord);
		$this->assertLessThan(
			(int) ($parentRecord->storedSize / 2),
			$rewritten->storedSize,
			'a delta re-encoded standalone costs about what its parent costs, which is the symptom',
		);
	}

	#[Test]
	#[TestDox('a rewrite repoints the index at the new pack rather than leaving it stale')]
	#[Group('strata/compaction')]
	public function rewriteRepointsTheIndex(): void
	{
		$hash = $this->writeCorpus()[0];
		$original = $this->frames()->get($hash);

		$this->assertNotNull($original);
		$this->assertNotNull($original->pack);

		$packs = $this->keys('packs/');
		$result = $this->engine()->recompressor()->recompressPack($packs[0]);
		$rewritten = $this->frames()->get($hash);

		$this->assertTrue($result['rewritten']);
		$this->assertNotNull($rewritten);
		$this->assertNotSame($original->pack, $rewritten->pack, 'the row moved to the new pack');
		$this->assertSame($original->references, $rewritten->references, 'the count is kept');
		$this->assertSame($original->rawSize, $rewritten->rawSize);
		$this->assertLessThan($original->storedSize, $rewritten->storedSize);
	}

	#[Test]
	#[TestDox('a pack already at the dense setting is left exactly as it was')]
	#[Group('strata/compaction')]
	public function alreadyDensePackIsLeftAlone(): void
	{
		$this->writeCorpus();

		$packs = $this->keys('packs/');
		$first = $this->engine()->recompressor()->recompressPack($packs[0]);
		$this->assertTrue($first['rewritten'], 'the first pass had something to gain');

		$dense = array_values(array_diff($this->keys('packs/'), $packs));
		$this->assertNotEmpty($dense);

		$second = $this->engine()->recompressor()->recompressPack($dense[0]);

		$this->assertFalse($second['rewritten'], 'nothing left to gain, nothing written');
		$this->assertSame(0, $second['saved']);
	}

	#[Test]
	#[TestDox('a pack with no directory is reported rather than half rewritten')]
	#[Group('strata/compaction')]
	public function unreadablePackIsReported(): void
	{
		$this->flush('unreadable');

		$packs = $this->keys('packs/');
		$this->engine()->provider()->put($packs[0], str_repeat('no directory here. ', 8));

		$result = $this->engine()->recompressor()->recompressPacks($packs);

		$this->assertSame(0, $result['packs']);
		$this->assertCount(1, $result['problems']);
		$this->assertStringContainsString($packs[0], $result['problems'][0]);
		$this->assertStringContainsString('STRATA-PAK-1', $result['problems'][0]);
	}

	#[Test]
	#[TestDox('a batch stops at its byte budget rather than running to the end')]
	#[Group('strata/compaction')]
	public function batchRespectsItsBudget(): void
	{
		$this->flush('budget-one');
		$this->flush('budget-two');
		$this->flush('budget-three');

		$packs = $this->keys('packs/');
		$this->assertGreaterThan(1, count($packs));

		$result = $this->engine()->recompressor()->recompressPacks($packs, 1);

		$this->assertLessThan(
			count($packs),
			$result['packs'] + $result['skipped'],
			'the pass stopped before reaching the last pack',
		);
	}

	#[Test]
	#[TestDox('the minimum gain is a real threshold rather than a rounding allowance')]
	#[Group('strata/compaction')]
	public function minimumGainIsMeaningful(): void
	{
		$this->assertGreaterThan(0.0, Recompressor::MIN_GAIN);
		$this->assertLessThan(1.0, Recompressor::MIN_GAIN);
	}

	#endregion

	#region The Pass

	#[Test]
	#[TestDox('a compaction pass reports what it densified and prunes nothing unasked')]
	#[Group('strata/compaction')]
	public function passDensifiesAndDoesNotPrune(): void
	{
		$this->flush('pass-one');
		$this->flush('pass-two');

		$report = $this->engine()->compactor()->compact();

		$this->assertTrue($report->isClean(), implode('; ', $report->problems));
		$this->assertNull($report->prune, 'a scheduled pass does not remove restore targets');
		$this->assertGreaterThanOrEqual(0, $report->saved());
		$this->assertGreaterThanOrEqual(1.0, $report->gain());
		$this->assertStringContainsString('recompressed', $report->summary());
	}

	#[Test]
	#[TestDox('a compacted store still verifies clean')]
	#[Group('strata/compaction')]
	public function compactedStoreVerifies(): void
	{
		$this->flush('verifiable-one');
		$this->flush('verifiable-two');

		$this->engine()->compactor()->compact();

		$report = $this->engine()->verifier()->verify();

		$this->assertTrue($report->isClean(), implode(', ', array_keys($report->byCode())));
		$this->assertSame(2, $report->commits);
		$this->assertGreaterThan(0, $report->bytes);
	}

	#[Test]
	#[TestDox('a second compactor is refused the lease rather than overlapping the first')]
	#[Group('strata/compaction')]
	public function leaseKeepsPassesApart(): void
	{
		$this->flush('leased');
		$this->container->get('strata.lease')->acquire('compaction');

		$report = $this->engine()->compactor()->compact();

		$this->assertFalse($report->isClean());
		$this->assertNotNull($report->prune);
		$this->assertTrue($report->prune->wasRefused());
		$this->assertStringContainsString('holds the lease', (string) $report->prune->refused);
	}

	#endregion

	#region Pruning

	#[Test]
	#[TestDox('a prune over reachable history removes nothing and says what held everything')]
	#[Group('strata/compaction')]
	public function pruneOverLiveHistoryRemovesNothing(): void
	{
		$this->flush('live-one');
		$this->flush('live-two');

		$receipt = $this->engine()->compactor()->prune(true);

		$this->assertFalse($receipt->wasRefused());
		$this->assertTrue($receipt->isEmpty());
		$this->assertSame('nothing to prune', $receipt->summary());
		$this->assertNotEmpty($this->keys('packs/'), 'the bucket is untouched');
	}

	#[Test]
	#[TestDox('a prune that refused announces itself, since a refusal is what needs a person')]
	#[Group('strata/compaction')]
	public function aRefusedPruneIsAnnounced(): void
	{
		// StrataEvents::PRUNE_APPLIED had no producer until 1.0.3, so a site subscribed to it heard
		// nothing about the operation that destroys restore points
		$heard = [];

		$this->container
			->get('event_dispatcher')
			->addListener(StrataEvents::PRUNE_APPLIED, static function (PruneEvent $event) use (
				&$heard,
			): void {
				$heard[] = $event;
			});

		$this->flush('announced');

		$head = $this->engine()->commitLog()->head();
		$this->assertNotNull($head);
		$this->engine()
			->provider()
			->delete([Hash::key($head->index, BaseManifest::PREFIX)]);

		$receipt = $this->engine()->compactor()->prune(true);

		$this->assertTrue($receipt->wasRefused());
		$this->assertCount(1, $heard);
		$this->assertTrue($heard[0]->receipt->wasRefused());
	}

	#[Test]
	#[TestDox('a dry run that found nothing announces nothing')]
	#[Group('strata/compaction')]
	public function aQuietDryRunIsNotAnnounced(): void
	{
		$heard = 0;

		$this->container
			->get('event_dispatcher')
			->addListener(StrataEvents::PRUNE_APPLIED, static function () use (&$heard): void {
				$heard++;
			});

		$this->flush('quiet');

		$this->assertTrue($this->engine()->compactor()->prune(false)->isEmpty());
		$this->assertSame(0, $heard, 'a dry run that removed nothing is not news');
	}

	#[Test]
	#[TestDox('a prune refuses outright when the reachability walk could not read something')]
	#[Group('strata/compaction')]
	public function pruneRefusesOnAnIncompleteWalk(): void
	{
		$this->flush('incomplete');

		$head = $this->engine()->commitLog()->head();
		$this->assertNotNull($head);
		$this->engine()
			->provider()
			->delete([Hash::key($head->index, BaseManifest::PREFIX)]);

		$receipt = $this->engine()->compactor()->prune(true);

		$this->assertTrue($receipt->wasRefused());
		$this->assertStringContainsString('what is live is unknown', (string) $receipt->refused);
		$this->assertSame([], $receipt->frames);
		$this->assertNotEmpty($this->keys('packs/'), 'a refusal deletes nothing');
	}

	#[Test]
	#[TestDox('a dry run reports the same numbers as an apply and deletes nothing')]
	#[Group('strata/compaction')]
	public function dryRunMatchesApply(): void
	{
		$this->flush('dry');
		$this->makeOrphan();

		$dry = $this->engine()->compactor()->prune(false);
		$objects = $this->keys('');

		$this->assertFalse($dry->applied);
		$this->assertNotEmpty($dry->frames);
		$this->assertSame($objects, $this->keys(''), 'a dry run writes nothing');

		$applied = $this->engine()->compactor()->prune(true);

		$this->assertTrue($applied->applied);
		$this->assertSame($dry->frames, $applied->frames);
		$this->assertSame($dry->objects, $applied->objects);
		$this->assertSame($dry->bytes, $applied->bytes);
	}

	#[Test]
	#[TestDox('an applied prune removes the object and forgets the index row')]
	#[Group('strata/compaction')]
	public function appliedPruneRemovesTheObject(): void
	{
		$this->flush('collected');
		$orphan = $this->makeOrphan();

		$receipt = $this->engine()->compactor()->prune(true);

		$this->assertContains($orphan, $receipt->frames);
		$this->assertNull($this->frames()->get($orphan), 'the index row is gone');
		$this->assertFalse(
			$this->engine()->provider()->exists($receipt->objects[0]),
			'the object is gone',
		);
	}

	#[Test]
	#[TestDox('a pack holding one live frame survives, and the receipt says which frame held it')]
	#[Group('strata/compaction')]
	public function packWithLiveFrameSurvives(): void
	{
		$this->flush('mixed');

		// dereferencing a live frame leaves it an orphan the walk still reaches through the tree
		$hash = $this->engine()->segmentReader()->read($this->keys('segments/')[0])->frames()[0];
		$this->frames()->dereference($hash, 10);

		$receipt = $this->engine()->compactor()->prune(true);

		$this->assertTrue($receipt->isEmpty());
		$this->assertNotEmpty($receipt->kept);
		$this->assertStringContainsString('a reachable commit needs it', $receipt->kept[0]);
		$this->assertNotNull($this->frames()->get($hash));
	}

	#[Test]
	#[TestDox('a prune after a compaction collects the pack the rewrite left behind')]
	#[Group('strata/compaction')]
	public function pruneCollectsTheOldPack(): void
	{
		$this->writeCorpus();

		$before = $this->keys('packs/');
		$result = $this->engine()->recompressor()->recompressPacks($before);

		$this->assertGreaterThan(0, $result['packs']);
		$this->assertGreaterThan(count($before), count($this->keys('packs/')));

		$receipt = $this->engine()->compactor()->prune(true);

		$this->assertFalse($receipt->wasRefused());
		$this->assertContains($before[0], $receipt->objects);
		$this->assertFalse(
			$this->engine()->provider()->exists($before[0]),
			'the pack the rewrite superseded is gone',
		);

		foreach ($this->writeCorpus() as $hash) {
			$this->assertNotNull(
				$this->engine()->objectStore()->frame($hash),
				'every frame still reads back from its new home',
			);
		}
	}

	#[Test]
	#[TestDox('a pack the index does not know about is left alone rather than assumed dead')]
	#[Group('strata/compaction')]
	public function unknownPackIsNotSwept(): void
	{
		$this->writeCorpus();

		$packs = $this->keys('packs/');
		$this->frames()->clear();

		$receipt = $this->engine()->compactor()->prune(true);

		$this->assertFalse($receipt->wasRefused());
		$this->assertSame([], $receipt->objects);
		$this->assertTrue(
			$this->engine()->provider()->exists($packs[0]),
			'a stale index is not evidence that an object is garbage',
		);
	}

	#endregion

	/**
	 * Writes a frame nothing references, so a prune has a genuine candidate.
	 *
	 * @return string
	 *   The orphan's content address.
	 */
	private function makeOrphan(): string
	{
		$store = $this->engine()->objectStore();
		$map = $store->write('an orphaned payload no tree names');
		$store->commit();

		foreach ($map as $hash) {
			$this->frames()->dereference($hash, 10);
		}

		$this->assertNotEmpty($map);
		$this->assertNotNull($this->frames()->get($map[0]));
		$this->assertTrue(
			$this->engine()
				->provider()
				->exists(
					Hash::key(
						(string) $this->frames()->get($map[0])?->pack,
						ObjectStore::PACK_PREFIX,
					),
				),
		);

		return $map[0];
	}
}
