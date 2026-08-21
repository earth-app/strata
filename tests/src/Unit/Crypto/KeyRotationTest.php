<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Crypto;

use Drupal\strata\Crypto\AuthenticationFailure;
use Drupal\strata\Crypto\KeyProviderInterface;
use Drupal\strata\Crypto\KeyRing;
use Drupal\strata\Crypto\RotatingCipher;
use Drupal\strata\Crypto\StaticKeyProvider;
use Drupal\strata\Crypto\XChaCha20Poly1305Cipher;
use Drupal\strata\Health\Tripwire\KeyRotatedMidFlight;
use Drupal\strata\Health\Finding;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Proves a key can be rotated without the store becoming unreadable at any point.
 *
 * The property that matters is that a rotation is reversible until it is finished: while a retired key
 * is on the ring every frame opens, whichever generation sealed it, and nothing has to be rewritten
 * for the site to keep working. What must never happen is a frame that opened before the rotation and
 * does not open after it.
 *
 * The lane also pins the limitation the ring cannot escape. A wrong key and a corrupt byte both fail
 * the Poly1305 tag, so "no key opened this" cannot be reported as a key problem, and a test asserts
 * the failure says so rather than blaming the key.
 */
#[CoversClass(KeyRing::class)]
#[CoversClass(RotatingCipher::class)]
#[CoversClass(KeyRotatedMidFlight::class)]
class KeyRotationTest extends TestCase
{
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

	#region Ring

	#[Test]
	#[TestDox('a ring with no retired key is not rotating and holds one key')]
	#[Group('strata/crypto')]
	public function aFreshRingIsNotRotating(): void
	{
		$ring = new KeyRing($this->key('active'));

		$this->assertFalse($ring->isRotating());
		$this->assertSame(1, $ring->count());
		$this->assertCount(1, $ring->all());
		$this->assertSame([], $ring->retired());
	}

	#[Test]
	#[TestDox('the active key is always tried first, so the common case costs one open')]
	#[Group('strata/crypto')]
	public function theActiveKeyIsFirst(): void
	{
		$active = $this->key('active');
		$ring = new KeyRing($active, [$this->key('old'), $this->key('older')]);

		$this->assertSame($active, $ring->all()[0]);
		$this->assertSame(3, $ring->count());
		$this->assertTrue($ring->isRotating());
	}

	/**
	 * A provider that holds nothing, which is what a deleted key looks like to the contract.
	 *
	 * StaticKeyProvider refuses a key that is not 32 bytes, so an emptied one cannot be built; the
	 * interface still permits `hasKey()` to be false and the ring has to cope with that.
	 *
	 * @return KeyProviderInterface
	 *   The provider.
	 */
	private function emptyKey(): KeyProviderInterface
	{
		return new class implements KeyProviderInterface {
			/**
			 * {@inheritdoc}
			 */
			public function key(): string
			{
				return '';
			}

			/**
			 * {@inheritdoc}
			 */
			public function hasKey(): bool
			{
				return false;
			}

			/**
			 * {@inheritdoc}
			 */
			public function fingerprint(): string
			{
				return '';
			}
		};
	}

	#[Test]
	#[TestDox('a ring refuses an active key that holds nothing')]
	#[Group('strata/crypto')]
	public function anEmptyActiveKeyIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new KeyRing($this->emptyKey());
	}

	#[Test]
	#[TestDox('a ring refuses more retired keys than it will try')]
	#[Group('strata/crypto')]
	public function tooManyRetiredKeysAreRefused(): void
	{
		$retired = [];

		for ($at = 0; $at <= KeyRing::MAX_RETIRED; $at++) {
			$retired[] = $this->key('old-' . $at);
		}

		$this->expectException(InvalidArgumentException::class);

		new KeyRing($this->key('active'), $retired);
	}

	#[Test]
	#[TestDox('a retired key that has been deleted is dropped rather than counted')]
	#[Group('strata/crypto')]
	public function anEmptyRetiredKeyIsDropped(): void
	{
		$ring = new KeyRing($this->key('active'), [$this->emptyKey(), $this->key('old')]);

		$this->assertSame(2, $ring->count(), 'only the key that holds a value is on the ring');
		$this->assertTrue($ring->isRotating());
	}

	#[Test]
	#[TestDox('isActive() tells the active fingerprint from a retired one')]
	#[Group('strata/crypto')]
	public function isActiveComparesFingerprints(): void
	{
		$active = $this->key('active');
		$old = $this->key('old');
		$ring = new KeyRing($active, [$old]);

		$this->assertTrue($ring->isActive($active->fingerprint()));
		$this->assertFalse($ring->isActive($old->fingerprint()));
		$this->assertCount(2, $ring->fingerprints());
		$this->assertNotSame($active->fingerprint(), $old->fingerprint());
	}

	#endregion

	#region Rotation

	#[Test]
	#[TestDox('a frame sealed before the rotation still opens after it')]
	#[Group('strata/crypto')]
	public function anOldFrameStillOpens(): void
	{
		$old = $this->key('old');
		$sealed = (new XChaCha20Poly1305Cipher($old->key()))->seal('the original payload');

		$rotated = new RotatingCipher(new KeyRing($this->key('active'), [$old]));

		$this->assertSame('the original payload', $rotated->open($sealed));
		$this->assertSame($old->fingerprint(), $rotated->openedWith());
		$this->assertFalse(
			$rotated->openedWithActiveKey(),
			'the frame predates the rotation and would be re-sealed by finishing it',
		);
	}

	#[Test]
	#[TestDox('sealing always uses the active key, so a rotation can finish')]
	#[Group('strata/crypto')]
	public function sealingUsesTheActiveKey(): void
	{
		$active = $this->key('active');
		$rotated = new RotatingCipher(new KeyRing($active, [$this->key('old')]));
		$sealed = $rotated->seal('written during the rotation');

		$this->assertSame(
			'written during the rotation',
			(new XChaCha20Poly1305Cipher($active->key()))->open($sealed),
			'the active key alone opens what was written during the rotation',
		);

		$rotated->open($sealed);

		$this->assertTrue($rotated->openedWithActiveKey());
	}

	#[Test]
	#[TestDox('a value no key on the ring opens blames neither the key nor the bytes')]
	#[Group('strata/crypto')]
	public function anUnopenableValueDoesNotGuess(): void
	{
		$stranger = (new XChaCha20Poly1305Cipher($this->key('stranger')->key()))->seal('secret');
		$rotated = new RotatingCipher(new KeyRing($this->key('active'), [$this->key('old')]));

		try {
			$rotated->open($stranger);
			$this->fail('a value sealed under an unconfigured key must not open');
		} catch (AuthenticationFailure $failure) {
			$this->assertStringContainsString('not configured', $failure->getMessage());
			$this->assertStringContainsString('bytes have changed', $failure->getMessage());
		}

		$this->assertNull($rotated->openedWith(), 'a failed open remembers no key');
		$this->assertFalse($rotated->openedWithActiveKey());
	}

	#[Test]
	#[TestDox('the associated data still binds, so a frame cannot be moved between addresses')]
	#[Group('strata/crypto')]
	public function associatedDataStillBinds(): void
	{
		$old = $this->key('old');
		$sealed = (new XChaCha20Poly1305Cipher($old->key()))->seal('payload', 'frames/aa');
		$rotated = new RotatingCipher(new KeyRing($this->key('active'), [$old]));

		$this->assertSame('payload', $rotated->open($sealed, 'frames/aa'));

		$this->expectException(AuthenticationFailure::class);

		$rotated->open($sealed, 'frames/bb');
	}

	#[Test]
	#[TestDox('the cipher id names the algorithm, not the key, so frames record the same thing')]
	#[Group('strata/crypto')]
	public function theCipherIdIsStable(): void
	{
		$plain = new XChaCha20Poly1305Cipher($this->key('active')->key());
		$rotated = new RotatingCipher(new KeyRing($this->key('active'), [$this->key('old')]));

		$this->assertSame($plain->id(), $rotated->id());
	}

	#endregion

	#region Mid-Flush

	#[Test]
	#[TestDox('a segment whose frames need two keys is reported as a mid-flush rotation')]
	#[Group('strata/crypto')]
	public function aSplitSegmentIsReported(): void
	{
		$finding = (new KeyRotatedMidFlight())->check([
			'segment' => 'segments/0/1700000000/1.seg',
			'fingerprints' => ['aaaa', 'bbbb', 'aaaa'],
		]);

		$this->assertInstanceOf(Finding::class, $finding);
		$this->assertSame('key.rotated_mid_flight', $finding->code);
		$this->assertSame(Finding::WARN, $finding->severity);
		$this->assertStringContainsString('2 keys', $finding->context);
	}

	#[Test]
	#[TestDox('a segment sealed entirely under one retired key is not the symptom')]
	#[Group('strata/crypto')]
	public function aWhollyOldSegmentIsNotReported(): void
	{
		$this->assertNull(
			(new KeyRotatedMidFlight())->check([
				'segment' => 'segments/0/1700000000/1.seg',
				'fingerprints' => ['aaaa', 'aaaa'],
			]),
			'every byte written before a rotation is under one old key, which the ring handles',
		);
	}

	#[Test]
	#[TestDox('an observation with no segment or no fingerprints reports nothing')]
	#[Group('strata/crypto')]
	public function anIncompleteObservationIsIgnored(): void
	{
		$wire = new KeyRotatedMidFlight();

		$this->assertNull($wire->check([]));
		$this->assertNull($wire->check(['segment' => 'a']));
		$this->assertNull($wire->check(['fingerprints' => ['a', 'b']]));
		$this->assertNull($wire->check(['segment' => 'a', 'fingerprints' => 'not a list']));
	}

	#endregion
}
