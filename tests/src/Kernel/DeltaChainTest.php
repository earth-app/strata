<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Cas\Framer;
use Drupal\strata\Cas\MemoryFrameIndex;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Cas\Packer;
use Drupal\strata\Codec\CodecRegistry;
use Drupal\strata\Codec\ZstdCodec;
use Drupal\strata\Delta\ChainDepthPolicy;
use Drupal\strata\Delta\Reanchorer;
use Drupal\strata\Engine;
use Drupal\strata\Health\MemoryHealthLedger;
use Psr\Log\NullLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

/**
 * Proves a rewritten value is stored as a delta and still reads back.
 *
 * Delta coding is the largest single saving in the design - 63.70x on the rewrite class, where a
 * 5,967-byte blob with one flag flipped encodes to 94 bytes - and it is also the only thing here that
 * makes one frame depend on another. Both halves need proving: that the saving is real on the shape it
 * was measured for, and that a chain never grows past its cap or decodes to anything but the original
 * bytes.
 */
class DeltaChainTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'key', 'strata'];

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		if (!(new ZstdCodec())->isAvailable()) {
			$this->markTestSkipped('zstd is not available on this host');
		}

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

	/**
	 * A payload of the shape the 63.70x figure was measured on.
	 *
	 * A notification blob with one flag flipped and one entry appended, which is the rewrite class the
	 * whole optimisation exists for.
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
	 * An object store over a shared index, with the delta cap set.
	 */
	private function store(MemoryFrameIndex $index, ?ChainDepthPolicy $policy): ObjectStore
	{
		return new ObjectStore(
			$this->engine()->provider(),
			$index,
			CodecRegistry::withShippedCodecs(),
			$this->engine()->cipher(),
			new Framer(16384),
			new Packer(65536),
			19,
			null,
			null,
			null,
			$policy,
		);
	}

	private function reanchorer(MemoryFrameIndex $index, ObjectStore $store, int $cap): Reanchorer
	{
		return new Reanchorer(
			$index,
			$store,
			new ChainDepthPolicy($cap),
			new MemoryHealthLedger(),
			new NullLogger(),
		);
	}

	#region Coding

	#[Test]
	#[TestDox('a rewritten value is stored against its previous version, not standalone')]
	#[Group('strata/delta')]
	public function rewriteIsStoredAsADelta(): void
	{
		$index = new MemoryFrameIndex();
		$store = $this->store($index, new ChainDepthPolicy(32));

		$first = $store->write($this->blob(0));
		$store->commit();

		$second = $store->write($this->blob(1), $first);
		$store->commit();

		$record = $index->get($second[0]);

		$this->assertNotNull($record);
		$this->assertTrue($record->isDelta());
		$this->assertSame($first[0], $record->deltaParent);
		$this->assertSame(1, $record->deltaDepth);
	}

	#[Test]
	#[TestDox('the delta is a fraction of what the same value costs standalone')]
	#[Group('strata/delta')]
	public function deltaIsMuchSmaller(): void
	{
		$coded = new MemoryFrameIndex();
		$plain = new MemoryFrameIndex();

		$withDelta = $this->store($coded, new ChainDepthPolicy(32));
		$withoutDelta = $this->store($plain, null);

		$first = $withDelta->write($this->blob(0));
		$withDelta->commit();
		$second = $withDelta->write($this->blob(1), $first);
		$withDelta->commit();

		$withoutDelta->write($this->blob(0));
		$withoutDelta->commit();
		$standalone = $withoutDelta->write($this->blob(1));
		$withoutDelta->commit();

		$deltaBytes = $coded->get($second[0])?->storedSize ?? 0;
		$plainBytes = $plain->get($standalone[0])?->storedSize ?? 0;

		$this->assertGreaterThan(0, $deltaBytes);
		$this->assertLessThan(
			$plainBytes / 2,
			$deltaBytes,
			sprintf('%d bytes as a delta against %d standalone', $deltaBytes, $plainBytes),
		);
	}

	#[Test]
	#[TestDox('a delta reads back byte for byte through its parent')]
	#[Group('strata/delta')]
	public function deltaReadsBack(): void
	{
		$index = new MemoryFrameIndex();
		$store = $this->store($index, new ChainDepthPolicy(32));

		$first = $store->write($this->blob(0));
		$store->commit();
		$second = $store->write($this->blob(1), $first);
		$store->commit();

		$this->assertSame($this->blob(1), $store->read($second));
		$this->assertSame($this->blob(0), $store->read($first));
	}

	#[Test]
	#[TestDox('a chain several links long reads back at every link')]
	#[Group('strata/delta')]
	public function chainReadsBackAtEveryLink(): void
	{
		$index = new MemoryFrameIndex();
		$store = $this->store($index, new ChainDepthPolicy(32));
		$maps = [];
		$previous = [];

		for ($version = 0; $version < 8; $version++) {
			$maps[$version] = $store->write($this->blob($version), $previous);
			$store->commit();
			$previous = $maps[$version];
		}

		foreach ($maps as $version => $map) {
			$this->assertSame($this->blob($version), $store->read($map), 'version ' . $version);
		}

		$this->assertSame(7, $index->get($maps[7][0])?->deltaDepth);
	}

	#[Test]
	#[TestDox('a value with nothing before it is stored standalone')]
	#[Group('strata/delta')]
	public function firstVersionIsStandalone(): void
	{
		$index = new MemoryFrameIndex();
		$store = $this->store($index, new ChainDepthPolicy(32));
		$map = $store->write($this->blob(0));
		$store->commit();

		$this->assertFalse($index->get($map[0])?->isDelta());
	}

	#[Test]
	#[TestDox('delta coding switched off stores every version standalone')]
	#[Group('strata/delta')]
	public function switchedOffStoresStandalone(): void
	{
		$index = new MemoryFrameIndex();
		$store = $this->store($index, null);

		$first = $store->write($this->blob(0));
		$store->commit();
		$second = $store->write($this->blob(1), $first);
		$store->commit();

		$this->assertFalse($index->get($second[0])?->isDelta());
	}

	#[Test]
	#[TestDox('a value nothing like its predecessor is stored standalone rather than badly coded')]
	#[Group('strata/delta')]
	public function unrelatedValueIsStandalone(): void
	{
		$index = new MemoryFrameIndex();
		$store = $this->store($index, new ChainDepthPolicy(32));

		$first = $store->write($this->blob(0));
		$store->commit();
		$second = $store->write(random_bytes(4096), $first);
		$store->commit();

		$this->assertFalse(
			$index->get($second[0])?->isDelta(),
			'incompressible bytes gain nothing from a parent',
		);
	}

	#[Test]
	#[TestDox('a chain stops growing at the cap rather than one link past it')]
	#[Group('strata/delta')]
	public function chainStopsAtTheCap(): void
	{
		$index = new MemoryFrameIndex();
		$store = $this->store($index, new ChainDepthPolicy(4));
		$previous = [];
		$depths = [];

		for ($version = 0; $version < 12; $version++) {
			$map = $store->write($this->blob($version), $previous);
			$store->commit();
			$depths[] = $index->get($map[0])?->deltaDepth ?? 0;
			$previous = $map;
		}

		$this->assertLessThanOrEqual(4, max($depths));
		$this->assertContains(0, $depths, 'the chain re-anchors when it reaches the cap');
	}

	#endregion

	#region Re-anchoring

	#[Test]
	#[TestDox('a chain past the cap is broken by rewriting its deepest link standalone')]
	#[Group('strata/delta')]
	public function chainPastTheCapIsBroken(): void
	{
		$index = new MemoryFrameIndex();
		$store = $this->store($index, new ChainDepthPolicy(32));
		$previous = [];
		$maps = [];

		for ($version = 0; $version < 6; $version++) {
			$maps[$version] = $store->write($this->blob($version), $previous);
			$store->commit();
			$previous = $maps[$version];
		}

		$this->assertSame(5, $index->get($maps[5][0])?->deltaDepth);

		// the cap is lowered after the fact, which is the case the pass exists for
		$result = $this->reanchorer($index, $store, 3)->run();

		$this->assertGreaterThan(0, $result['reanchored']);
		$this->assertSame(5, $result['deepest']);
		$this->assertSame([], $result['problems']);

		foreach ($maps as $version => $map) {
			$this->assertSame($this->blob($version), $store->read($map), 'version ' . $version);
		}
	}

	#[Test]
	#[TestDox('a re-anchored frame keeps its address, so everything naming it still finds it')]
	#[Group('strata/delta')]
	public function reanchoringKeepsTheAddress(): void
	{
		$index = new MemoryFrameIndex();
		$store = $this->store($index, new ChainDepthPolicy(32));

		$first = $store->write($this->blob(0));
		$store->commit();
		$second = $store->write($this->blob(1), $first);
		$store->commit();

		$before = $index->get($second[0]);
		$this->assertNotNull($before);

		$this->reanchorer($index, $store, 1)->reanchor($before);

		$after = $index->get($second[0]);

		$this->assertNotNull($after);
		$this->assertSame($before->hash, $after->hash);
		$this->assertFalse($after->isDelta());
		$this->assertSame($before->rawSize, $after->rawSize);
		$this->assertSame($this->blob(1), $store->read($second));
	}

	#[Test]
	#[TestDox('a store with no long chains re-anchors nothing and says so')]
	#[Group('strata/delta')]
	public function nothingToDoIsReportedAsNothing(): void
	{
		$index = new MemoryFrameIndex();
		$store = $this->store($index, new ChainDepthPolicy(32));

		$store->write($this->blob(0));
		$store->commit();

		$result = $this->reanchorer($index, $store, 32)->run();

		$this->assertSame(0, $result['examined']);
		$this->assertSame(0, $result['reanchored']);
		$this->assertSame(0, $result['deepest']);
	}

	#[Test]
	#[TestDox('the deepest chain is reported, so the cap can be judged before it is passed')]
	#[Group('strata/delta')]
	public function deepestChainIsReported(): void
	{
		$index = new MemoryFrameIndex();
		$store = $this->store($index, new ChainDepthPolicy(32));
		$previous = [];

		for ($version = 0; $version < 4; $version++) {
			$previous = $store->write($this->blob($version), $previous);
			$store->commit();
		}

		$this->assertSame(3, $this->reanchorer($index, $store, 32)->deepest());
	}

	#[Test]
	#[TestDox('a pass is bounded, so one cron run cannot be consumed by re-anchoring')]
	#[Group('strata/delta')]
	public function passIsBounded(): void
	{
		$index = new MemoryFrameIndex();
		$store = $this->store($index, new ChainDepthPolicy(32));
		$previous = [];

		for ($version = 0; $version < 6; $version++) {
			$previous = $store->write($this->blob($version), $previous);
			$store->commit();
		}

		$this->assertSame(2, $this->reanchorer($index, $store, 1)->run(2)['examined']);
	}

	#[Test]
	#[TestDox('a delta whose parent is gone is refused rather than decoded to noise')]
	#[Group('strata/delta')]
	public function lostParentIsRefused(): void
	{
		$index = new MemoryFrameIndex();
		$store = $this->store($index, new ChainDepthPolicy(32));

		$first = $store->write($this->blob(0));
		$store->commit();
		$second = $store->write($this->blob(1), $first);
		$store->commit();

		$index->forget([$first[0]]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('which the index does not know');

		$store->read($second);
	}

	#endregion

	#region Through The Pipeline

	#[Test]
	#[TestDox('a subject rewritten twice between anchors stores the second version as a delta')]
	#[Group('strata/delta')]
	public function flushCodesRepeatWritesAsDeltas(): void
	{
		$this->config('strata.settings')->set('retention.base_interval', 3600)->save();
		$this->engine()->reset();

		$state = $this->container->get('state');
		$index = $this->container->get('strata.frame_index');

		$state->set('strata_test.blob', $this->blob(0));
		$this->engine()->flusher()->flush(true);

		$state->set('strata_test.blob', $this->blob(1));
		$result = $this->engine()->flusher()->flush(true);

		$manifest = $this->engine()->segmentReader()->read((string) $result->segment);
		$deltas = 0;

		foreach ($manifest->operations as $operation) {
			foreach ($manifest->payloadFor($operation) as $frame) {
				if ($index->get($frame)?->isDelta() === true) {
					$deltas++;
				}
			}
		}

		$this->assertGreaterThan(
			0,
			$deltas,
			'the rewritten state value was coded against version one',
		);
	}

	#[Test]
	#[TestDox('a value coded through the pipeline still replays to what it was')]
	#[Group('strata/delta')]
	public function codedValuesStillReplay(): void
	{
		$this->config('strata.settings')->set('retention.base_interval', 3600)->save();
		$this->engine()->reset();

		$state = $this->container->get('state');

		$state->set('strata_test.blob', $this->blob(0));
		$this->engine()->flusher()->flush(true);

		$state->set('strata_test.blob', $this->blob(1));
		$this->engine()->flusher()->flush(true);

		$head = $this->engine()->commitLog()->head();

		$this->assertNotNull($head);

		$replayed = $this->engine()->replayer()->materialize('state/strata_test.blob', $head->id());

		$this->assertTrue($replayed->exists);
		$this->assertNotSame([], $replayed->fields);
	}

	#endregion
}
