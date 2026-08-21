<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Cas\Framer;
use Drupal\strata\Cas\MemoryFrameIndex;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Cas\Packer;
use Drupal\strata\Codec\CodecRegistry;
use Drupal\strata\Codec\Dictionary\DictionaryPass;
use Drupal\strata\Codec\Dictionary\DictionaryRef;
use Drupal\strata\Codec\Dictionary\DictionaryStore;
use Drupal\strata\Codec\ZstdCodec;
use Drupal\strata\Engine;
use Drupal\strata\Journal\Realm;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

/**
 * Proves a dictionary survives being retrained, which is the only way it can be dangerous.
 *
 * A dictionary is the one object whose loss cannot be worked around: a frame compressed against one
 * does not open without it, at all. So the interesting cases are not whether training works but what
 * happens to the frames already written when a new version arrives, and what happens when a version
 * goes missing.
 */
class DictionaryTest extends StrataKernelTestBase
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

	private function dictionaries(): DictionaryStore
	{
		return $this->engine()->dictionaries();
	}

	private function pass(): DictionaryPass
	{
		return $this->engine()->dictionaryPass();
	}

	private function requireZstd(): void
	{
		if (!(new ZstdCodec())->isAvailable()) {
			$this->markTestSkipped('zstd is not available on this host');
		}
	}

	/**
	 * Payloads a realm might write, similar enough to be worth a dictionary.
	 *
	 * @return list<string>
	 *   The payloads.
	 */
	private function samples(int $count = 200): array
	{
		$samples = [];

		for ($i = 0; $i < $count; $i++) {
			$samples[] = (string) json_encode([
				'entity_type' => 'user',
				'uid' => [['value' => $i]],
				'name' => [['value' => 'account-' . $i]],
				'status' => [['value' => 1]],
				'roles' => [['target_id' => 'editor']],
				'preferences' => ['theme' => 'claro', 'timezone' => 'UTC', 'digest' => 'weekly'],
			]);
		}

		return $samples;
	}

	#region The Store

	#[Test]
	#[TestDox('a stored dictionary reads back byte for byte through its reference')]
	#[Group('strata/codec')]
	public function storedDictionaryReadsBack(): void
	{
		$bytes = str_repeat('dictionary sample ', 200);
		$ref = $this->dictionaries()->store('entity', $bytes, DictionaryRef::RAW, 4.2, 200);

		$this->assertSame('entity:1', $ref->id());
		$this->assertSame(strlen($bytes), $ref->bytes);
		$this->assertSame($bytes, $this->dictionaries()->get($ref->id()));
		$this->assertSame($ref->id(), $this->dictionaries()->latest('entity')?->id());
	}

	#[Test]
	#[TestDox('retraining adds a version and leaves the one before it in place')]
	#[Group('strata/codec')]
	public function retrainingAddsAVersion(): void
	{
		$store = $this->dictionaries();
		$first = $store->store('entity', 'first dictionary bytes');
		$second = $store->store('entity', 'second dictionary bytes');

		$this->assertSame('entity:1', $first->id());
		$this->assertSame('entity:2', $second->id());
		$this->assertSame(2, $store->versions('entity'));
		$this->assertSame('entity:2', $store->latest('entity')?->id());
		$this->assertSame('first dictionary bytes', $store->get('entity:1'));
	}

	#[Test]
	#[TestDox('each realm versions independently')]
	#[Group('strata/codec')]
	public function realmsVersionIndependently(): void
	{
		$store = $this->dictionaries();

		$store->store('entity', 'entity bytes');
		$store->store('entity', 'entity bytes two');
		$store->store('config', 'config bytes');

		$this->assertSame(2, $store->versions('entity'));
		$this->assertSame(1, $store->versions('config'));
		$this->assertSame('config:1', $store->latest('config')?->id());
		$this->assertCount(3, $store->all());
	}

	#[Test]
	#[TestDox('an empty dictionary is refused, since it would decode nothing')]
	#[Group('strata/codec')]
	public function emptyDictionaryIsRefused(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('would decode nothing');

		$this->dictionaries()->store('entity', '');
	}

	#[Test]
	#[TestDox('a dictionary whose bytes were altered is refused rather than handed back')]
	#[Group('strata/codec')]
	public function alteredDictionaryIsRefused(): void
	{
		$store = $this->dictionaries();
		$ref = $store->store('entity', 'the real dictionary');

		$this->engine()->provider()->put($ref->key(), 'something else entirely');
		$store->flushCache();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('does not match the address');

		$store->get($ref->id());
	}

	#[Test]
	#[TestDox('a dictionary that is indexed but gone is reported rather than read as nothing')]
	#[Group('strata/codec')]
	public function missingDictionaryIsReported(): void
	{
		$store = $this->dictionaries();
		$ref = $store->store('entity', 'the real dictionary');

		$this->engine()
			->provider()
			->delete([$ref->key()]);
		$store->flushCache();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('could not be read');

		$store->get($ref->id());
	}

	#[Test]
	#[TestDox('an id nothing is indexed under reads as absent, not as an error')]
	#[Group('strata/codec')]
	public function unknownIdIsAbsent(): void
	{
		$this->assertNull($this->dictionaries()->get('entity:9'));
		$this->assertNull($this->dictionaries()->ref('entity:9'));
		$this->assertNull($this->dictionaries()->latest('entity'));
	}

	#endregion

	#region Frames Across Versions

	#[Test]
	#[TestDox('a frame written against an older version still opens after a retrain')]
	#[Group('strata/codec')]
	public function framesSurviveARetrain(): void
	{
		$this->requireZstd();

		$store = $this->dictionaries();
		$old = $store->store('entity', str_repeat('version one sample ', 300));
		$payload = str_repeat('version one sample data ', 40);

		$index = new MemoryFrameIndex();
		$writer = $this->objectStoreWith($index, $old->id(), (string) $store->get($old->id()));
		$map = $writer->write($payload);
		$writer->commit();

		$new = $store->store('entity', str_repeat('version two sample ', 300));

		// a store configured with the new dictionary must still open a frame that names the old one
		$reader = $this->objectStoreWith($index, $new->id(), (string) $store->get($new->id()));

		$this->assertSame('entity:2', $new->id());
		$this->assertSame($payload, $reader->read($map));
	}

	#[Test]
	#[
		TestDox(
			'a frame naming a dictionary nothing holds is refused rather than decoded to garbage',
		),
	]
	#[Group('strata/codec')]
	public function frameNamingALostDictionaryIsRefused(): void
	{
		$this->requireZstd();

		$store = $this->dictionaries();
		$ref = $store->store('entity', str_repeat('sample ', 400));
		$index = new MemoryFrameIndex();
		$writer = $this->objectStoreWith($index, $ref->id(), (string) $store->get($ref->id()));
		$map = $writer->write(str_repeat('sample data ', 40));
		$writer->commit();

		$store->forget($ref->id());
		$store->flushCache();

		$reader = $this->objectStoreWith($index, null, null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('which is not available here');

		$reader->read($map);
	}

	#[Test]
	#[TestDox('a frame written with no dictionary opens on a store that has one')]
	#[Group('strata/codec')]
	public function undictionariedFramesAreUnaffected(): void
	{
		$this->requireZstd();

		$index = new MemoryFrameIndex();
		$plain = $this->objectStoreWith($index, null, null);
		$payload = str_repeat('no dictionary here ', 40);
		$map = $plain->write($payload);
		$plain->commit();

		$ref = $this->dictionaries()->store('entity', str_repeat('sample ', 400));
		$reader = $this->objectStoreWith(
			$index,
			$ref->id(),
			(string) $this->dictionaries()->get($ref->id()),
		);

		$this->assertSame($payload, $reader->read($map));
	}

	#endregion

	#region The Pass

	#[Test]
	#[TestDox('a realm with too little history is skipped by name rather than trained badly')]
	#[Group('strata/codec')]
	public function thinRealmIsSkipped(): void
	{
		$result = $this->pass()->trainRealm('entity', array_slice($this->samples(), 0, 4));

		$this->assertFalse($result['stored']);
		$this->assertStringContainsString('fewest worth training', $result['reason']);
		$this->assertNull($this->dictionaries()->latest('entity'));
	}

	#[Test]
	#[TestDox('a pass stores a dictionary and records what it measured')]
	#[Group('strata/codec')]
	public function passStoresAMeasuredDictionary(): void
	{
		$this->requireZstd();

		$result = $this->pass()->trainRealm('entity', $this->samples());

		$this->assertTrue($result['stored'], $result['reason']);
		$this->assertGreaterThan(1.0, $result['ratio']);

		$ref = $this->dictionaries()->latest('entity');

		$this->assertNotNull($ref);
		$this->assertEqualsWithDelta($result['ratio'], $ref->ratio, 0.01);
		$this->assertContains($ref->source, [DictionaryRef::RAW, DictionaryRef::TRAINED]);
	}

	#[Test]
	#[TestDox('a second pass over the same shape does not add a version for nothing')]
	#[Group('strata/codec')]
	public function unchangedShapeDoesNotRetrain(): void
	{
		$this->requireZstd();

		$this->pass()->trainRealm('entity', $this->samples());
		$second = $this->pass()->trainRealm('entity', $this->samples());

		$this->assertFalse($second['stored']);
		$this->assertStringContainsString('already gets', $second['reason']);
		$this->assertSame(1, $this->dictionaries()->versions('entity'));
	}

	#[Test]
	#[TestDox('samples are collected from the segments a flush wrote')]
	#[Group('strata/codec')]
	public function samplesComeFromTheStore(): void
	{
		for ($i = 0; $i < 5; $i++) {
			User::create([
				'name' => 'sampled-' . $i,
				'mail' => "s$i@example.com",
				'status' => 1,
			])->save();
		}

		$this->engine()->flusher()->flush(true);

		$collected = $this->pass()->collect();

		$this->assertArrayHasKey(Realm::ENTITY->value, $collected);
		$this->assertGreaterThanOrEqual(5, count($collected[Realm::ENTITY->value]));
		$this->assertStringContainsString(
			'sampled-0',
			implode('', $collected[Realm::ENTITY->value]),
		);
	}

	#[Test]
	#[TestDox('a pass over an empty store collects nothing rather than failing')]
	#[Group('strata/codec')]
	public function emptyStoreCollectsNothing(): void
	{
		$this->assertSame([], $this->pass()->collect());
		$this->assertSame([], $this->pass()->run());
	}

	#[Test]
	#[
		TestDox(
			'the active dictionary is the entity realm\'s newest, and only when it is switched on',
		),
	]
	#[Group('strata/codec')]
	public function activeDictionaryFollowsSettings(): void
	{
		$this->requireZstd();

		$this->assertNull($this->engine()->activeDictionary(), 'nothing is trained yet');

		$this->pass()->trainRealm(Realm::ENTITY->value, $this->samples());
		$this->engine()->reset();

		$this->assertSame('entity:1', $this->engine()->activeDictionary()?->id());

		$this->config('strata.settings')->set('codec.dictionary', false)->save();
		$this->engine()->reset();

		$this->assertNull($this->engine()->activeDictionary());
	}

	#[Test]
	#[TestDox('a flush after training records the dictionary on the frames it writes')]
	#[Group('strata/codec')]
	public function flushRecordsTheDictionary(): void
	{
		$this->requireZstd();

		$this->pass()->trainRealm(Realm::ENTITY->value, $this->samples());
		$this->engine()->reset();

		User::create(['name' => 'compressed', 'mail' => 'c@example.com', 'status' => 1])->save();

		$result = $this->engine()->flusher()->flush(true);
		$manifest = $this->engine()->segmentReader()->read((string) $result->segment);
		$frames = $manifest->payloadFor($manifest->operations[0]);

		$this->assertNotSame([], $frames);

		$record = $this->container->get('strata.frame_index')->get($frames[0]);

		$this->assertNotNull($record);
		$this->assertSame('entity:1', $record->dictionary);
		$this->assertNotNull($this->engine()->objectStore()->frame($frames[0]));
	}

	#endregion

	/**
	 * An object store over a shared index, configured with one dictionary or none.
	 *
	 * @param MemoryFrameIndex $index
	 *   The index shared between the writer and the reader, standing in for what survives a request.
	 * @param string|null $id
	 *   The dictionary id to compress with, or NULL for none.
	 * @param string|null $bytes
	 *   The dictionary bytes, or NULL for none.
	 *
	 * @return ObjectStore
	 *   The store.
	 */
	private function objectStoreWith(
		MemoryFrameIndex $index,
		?string $id,
		?string $bytes,
	): ObjectStore {
		return new ObjectStore(
			$this->engine()->provider(),
			$index,
			CodecRegistry::withShippedCodecs(),
			$this->engine()->cipher(),
			new Framer(16384),
			new Packer(65536),
			19,
			$bytes,
			$id,
			$this->dictionaries(),
		);
	}
}
