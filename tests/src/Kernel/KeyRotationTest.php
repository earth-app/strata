<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Cas\DatabaseFrameIndex;
use Drupal\strata\Cas\Framer;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Cas\Packer;
use Drupal\strata\Codec\CodecRegistry;
use Drupal\strata\Compaction\Recompressor;
use Drupal\strata\Crypto\CipherInterface;
use Drupal\strata\Crypto\KeyProviderInterface;
use Drupal\strata\Crypto\KeyRing;
use Drupal\strata\Crypto\KeyRotation;
use Drupal\strata\Crypto\RotatingCipher;
use Drupal\strata\Crypto\StaticKeyProvider;
use Drupal\strata\Crypto\XChaCha20Poly1305Cipher;
use Drupal\strata\Health\HealthLedgerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;

/**
 * Proves a key can be changed on a store that already holds history.
 *
 * There must be no window in which the store cannot be read.
 *
 * The unit lane proves the ring tries the right keys in the right order. This one proves the pass
 * that ends a rotation actually ends it, against real objects on disk and the real index: frames
 * sealed under the old key are re-sealed under the new one, the store reports when that is finished,
 * and nothing has to be rewritten for the site to keep working in the meantime.
 *
 * The property that would make a rotation dangerous is a moved address. A frame is addressed by its
 * decoded bytes, so re-sealing must write the same address; if it did not, every segment, tree and
 * delta parent naming that frame would have to be rewritten too, and a rotation would become a
 * history rewrite. A test asserts the addresses before and after are the same set.
 */
class KeyRotationTest extends StrataKernelTestBase
{
	/**
	 * Payloads written under the old key, large enough to be worth compressing.
	 */
	private const PAYLOADS = 6;

	/**
	 * The index both stores share, so a re-sealed frame is found where the pass left it.
	 */
	private ?DatabaseFrameIndex $index = null;

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->index = new DatabaseFrameIndex($this->container->get('database'));
	}

	/**
	 * {@inheritdoc}
	 */
	protected function tearDown(): void
	{
		$this->index = null;

		parent::tearDown();
	}

	#region Measuring

	#[Test]
	#[TestDox('a store sealed under a retired key reports itself as mid-rotation')]
	#[Group('strata/crypto')]
	public function aStoreUnderTheOldKeyReportsItself(): void
	{
		$this->writeUnder($this->cipherFor('old'));

		$measured = $this->rotation()->measure();

		$this->assertTrue($measured['rotating']);
		$this->assertSame(2, $measured['keys']);
		$this->assertSame(self::PAYLOADS, $measured['sampled']);
		$this->assertSame(self::PAYLOADS, $measured['stale'], 'every frame predates the new key');
		$this->assertSame(0, $measured['unreadable']);
		$this->assertFalse($measured['complete'], 'the retired key is still load-bearing');
	}

	#[Test]
	#[TestDox('a frame the tree points at is examined, not just a collectable one')]
	#[Group('strata/crypto')]
	public function aReferencedFrameIsExamined(): void
	{
		$written = $this->writeUnder($this->cipherFor('old'));

		foreach ($this->addressesOf($written) as $hash) {
			$this->index()->reference($hash);
		}

		$this->assertSame([], $this->index()->orphans(1000), 'nothing here is collectable');
		$this->assertSame(
			self::PAYLOADS,
			$this->rotation()->measure()['stale'],
			'a rotation is not a reachability question; every frame is sealed under a key',
		);
	}

	#[Test]
	#[TestDox('a store with one key on the ring is not rotating and has nothing to sample')]
	#[Group('strata/crypto')]
	public function aSingleKeyStoreIsNotRotating(): void
	{
		$this->writeUnder($this->cipherFor('only'));

		$single = new RotatingCipher(new KeyRing($this->key('only')));
		$rotation = new KeyRotation(
			$single,
			$this->storeUnder($single),
			$this->recompressor($single),
			$this->index(),
			$this->ledger(),
			new NullLogger(),
		);

		$measured = $rotation->measure();

		$this->assertFalse($measured['rotating']);
		$this->assertSame(1, $measured['keys']);
		$this->assertSame(0, $measured['sampled'], 'nothing is sampled when nothing can be stale');
		$this->assertTrue($measured['complete']);
		$this->assertSame(
			['examined' => 0, 'resealed' => 0, 'skipped' => 0, 'unreadable' => 0, 'problems' => []],
			$rotation->run(),
			'a pass with no retired key does no work rather than rewriting the store',
		);
	}

	#endregion

	#region Re-sealing

	#[Test]
	#[TestDox('a pass re-seals every frame and then reports the retired key as removable')]
	#[Group('strata/crypto')]
	public function aPassFinishesTheRotation(): void
	{
		$this->writeUnder($this->cipherFor('old'));

		$rotation = $this->rotation();
		$result = $rotation->run();

		$this->assertSame(self::PAYLOADS, $result['resealed']);
		$this->assertSame(0, $result['skipped']);
		$this->assertSame(0, $result['unreadable']);
		$this->assertSame([], $result['problems']);

		$after = $rotation->measure();

		$this->assertSame(0, $after['stale']);
		$this->assertTrue($after['complete'], 'nothing needs the retired key any more');
	}

	#[Test]
	#[TestDox('a re-sealed frame opens under the active key alone')]
	#[Group('strata/crypto')]
	public function aResealedFrameNeedsOnlyTheActiveKey(): void
	{
		$written = $this->writeUnder($this->cipherFor('old'));

		$this->rotation()->run();

		$active = $this->storeUnder($this->cipherFor('active'));

		foreach ($written as $payload => $map) {
			$this->assertSame(
				(string) $payload,
				$active->read($map),
				'the new key alone reads what the old one sealed',
			);
		}
	}

	#[Test]
	#[TestDox('re-sealing keeps every address, so nothing naming a frame has to be rewritten')]
	#[Group('strata/crypto')]
	public function addressesSurviveTheRotation(): void
	{
		$this->writeUnder($this->cipherFor('old'));

		$before = $this->addresses();

		$this->rotation()->run();

		$this->assertSame($before, $this->addresses(), 'the address is over the decoded bytes');
	}

	#[Test]
	#[TestDox('a second pass finds nothing left to do')]
	#[Group('strata/crypto')]
	public function aSecondPassIsIdempotent(): void
	{
		$this->writeUnder($this->cipherFor('old'));

		$rotation = $this->rotation();
		$rotation->run();
		$second = $rotation->run();

		$this->assertSame(0, $second['resealed']);
		$this->assertSame(self::PAYLOADS, $second['skipped'], 'already current, so left alone');
	}

	#[Test]
	#[TestDox('a budget bounds what is read, and one object is the smallest unit re-sealed')]
	#[Group('strata/crypto')]
	public function aBudgetBoundsWhatIsRead(): void
	{
		$this->writeUnder($this->cipherFor('old'), 'first batch');
		$this->writeUnder($this->cipherFor('old'), 'second batch');

		$rotation = $this->rotation();
		$result = $rotation->run(1);

		$this->assertSame(1, $result['examined'], 'the budget stopped the walk after one frame');
		$this->assertSame(
			self::PAYLOADS,
			$result['resealed'],
			'that frame was in a pack, and a pack is rewritten whole',
		);
	}

	#[Test]
	#[TestDox('an interrupted rotation is still a rotation and says so')]
	#[Group('strata/crypto')]
	public function anInterruptedRotationIsIncomplete(): void
	{
		$this->writeUnder($this->cipherFor('old'), 'first batch');
		$this->writeUnder($this->cipherFor('old'), 'second batch');

		$rotation = $this->rotation();
		$rotation->run(1);

		$this->assertFalse(
			$rotation->measure()['complete'],
			'the second pack still opens under the retired key',
		);
		$this->assertTrue($rotation->measure()['stale'] > 0);

		$rotation->run();

		$this->assertTrue($rotation->measure()['complete'], 'a second pass finishes it');
	}

	#endregion

	#region Mid-Flush

	#[Test]
	#[TestDox('a segment whose frames need two keys is recorded in the ledger')]
	#[Group('strata/crypto')]
	public function aSplitSegmentIsRecorded(): void
	{
		$old = $this->writeUnder($this->cipherFor('old'));
		$new = $this->writeUnder($this->cipherFor('active'), 'written after the rotation began');

		$frames = array_merge($this->addressesOf($old), $this->addressesOf($new));

		$this->assertTrue($this->rotation()->checkSegment('segments/0/1/1.seg', $frames));
		$this->assertNotSame([], $this->ledger()->open());

		$codes = array_map(
			static fn(object $finding): string => $finding->code,
			$this->ledger()->open(),
		);

		$this->assertContains('key.rotated_mid_flight', $codes);
	}

	#[Test]
	#[TestDox('a segment sealed wholly under one key records nothing')]
	#[Group('strata/crypto')]
	public function aWhollyOldSegmentIsNotRecorded(): void
	{
		$written = $this->writeUnder($this->cipherFor('old'));

		$this->assertFalse(
			$this->rotation()->checkSegment('segments/0/1/1.seg', $this->addressesOf($written)),
		);
		$this->assertSame([], $this->ledger()->open());
	}

	#endregion

	#region Fixtures

	/**
	 * A distinct key, deterministic per label so a failure is reproducible.
	 *
	 * @param string $label
	 *   Anything; the same label always gives the same key.
	 *
	 * @return KeyProviderInterface
	 *   The provider.
	 */
	private function key(string $label): KeyProviderInterface
	{
		return new StaticKeyProvider(hash('sha256', $label, true));
	}

	/**
	 * A plain cipher holding one key.
	 *
	 * @param string $label
	 *   The key label.
	 *
	 * @return CipherInterface
	 *   The cipher.
	 */
	private function cipherFor(string $label): CipherInterface
	{
		return new XChaCha20Poly1305Cipher($this->key($label)->key());
	}

	/**
	 * The index the stores share.
	 *
	 * @return DatabaseFrameIndex
	 *   The index.
	 */
	private function index(): DatabaseFrameIndex
	{
		return $this->index ??= new DatabaseFrameIndex($this->container->get('database'));
	}

	/**
	 * The ledger a mid-flush rotation is recorded in.
	 *
	 * @return HealthLedgerInterface
	 *   The ledger.
	 */
	private function ledger(): HealthLedgerInterface
	{
		return $this->container->get('strata.health_ledger');
	}

	/**
	 * A store over the provider under test, sealing with one cipher.
	 *
	 * At the smallest pack target the store allows, so a re-seal writes a new pack holding the frame
	 * it re-sealed and the assertions below are about the frame rather than about batching.
	 *
	 * @param CipherInterface $cipher
	 *   What seals and opens the frames.
	 *
	 * @return ObjectStore
	 *   The store.
	 */
	private function storeUnder(CipherInterface $cipher): ObjectStore
	{
		return new ObjectStore(
			$this->provider(),
			$this->index(),
			CodecRegistry::withShippedCodecs(),
			$cipher,
			new Framer(16384),
			new Packer(65536),
			19,
		);
	}

	/**
	 * A rotation from key `old` to key `active`.
	 *
	 * @return KeyRotation
	 *   The pass.
	 */
	private function rotation(): KeyRotation
	{
		$cipher = new RotatingCipher(new KeyRing($this->key('active'), [$this->key('old')]));

		return new KeyRotation(
			$cipher,
			$this->storeUnder($cipher),
			$this->recompressor($cipher),
			$this->index(),
			$this->ledger(),
			new NullLogger(),
		);
	}

	/**
	 * A recompressor that seals with one cipher.
	 *
	 * @param CipherInterface $cipher
	 *   What seals the rewritten object.
	 *
	 * @return Recompressor
	 *   The recompressor.
	 */
	private function recompressor(CipherInterface $cipher): Recompressor
	{
		return new Recompressor(
			$this->provider(),
			$this->index(),
			$this->storeUnder($cipher),
			CodecRegistry::withShippedCodecs(),
			$cipher,
			new Packer(65536),
			19,
		);
	}

	/**
	 * Writes the fixture payloads under one cipher.
	 *
	 * @param CipherInterface $cipher
	 *   What seals them.
	 * @param string $prefix
	 *   Distinguishes one batch from another.
	 *
	 * @return array<string, list<string>>
	 *   The payload keyed to the frame map that reads it back.
	 */
	private function writeUnder(CipherInterface $cipher, string $prefix = 'sealed'): array
	{
		$store = $this->storeUnder($cipher);
		$written = [];

		for ($at = 0; $at < self::PAYLOADS; $at++) {
			$payload = sprintf('%s payload %d %s', $prefix, $at, str_repeat('the same words ', 40));
			$written[$payload] = $store->write($payload);
		}

		$store->commit();

		return $written;
	}

	/**
	 * Every frame address the index holds.
	 *
	 * @return list<string>
	 *   Addresses, sorted so the comparison is order-independent.
	 */
	private function addresses(): array
	{
		$hashes = [];

		foreach ($this->index()->orphans(1000) as $record) {
			$hashes[] = $record->hash;
		}

		sort($hashes);

		return $hashes;
	}

	/**
	 * The frame addresses a batch of writes produced.
	 *
	 * @param array<string, list<string>> $written
	 *   What writeUnder() returned.
	 *
	 * @return list<string>
	 *   Addresses, deduplicated.
	 */
	private function addressesOf(array $written): array
	{
		$hashes = [];

		foreach ($written as $map) {
			foreach ($map as $hash) {
				$hashes[] = $hash;
			}
		}

		return array_values(array_unique($hashes));
	}

	#endregion
}
