<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Crypto;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Crypto\CipherInterface;
use Drupal\strata\Crypto\KeyProviderInterface;
use Drupal\strata\Crypto\NullCipher;
use Drupal\strata\Crypto\StaticKeyProvider;
use Drupal\strata\Crypto\XChaCha20Poly1305Cipher;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(XChaCha20Poly1305Cipher::class)]
#[CoversClass(NullCipher::class)]
#[CoversClass(StaticKeyProvider::class)]
class CipherTest extends TestCase
{
	#region Fixtures

	private function cipher(): XChaCha20Poly1305Cipher
	{
		return new XChaCha20Poly1305Cipher(StaticKeyProvider::generate()->key());
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function payloadProvider(): array
	{
		return [
			'empty' => [''],
			'one byte' => ['x'],
			'text' => [str_repeat('strata frame payload ', 40)],
			'binary with nulls' => ["\0\1\2" . random_bytes(512) . "\0"],
			'large' => [random_bytes(256 * 1024)],
		];
	}

	#endregion

	#region Determinism, which is what preserves dedup

	#[Test]
	#[TestDox('sealing the same plaintext twice produces identical bytes, so dedup survives')]
	#[Group('strata/crypto')]
	public function sealingIsDeterministic(): void
	{
		$cipher = $this->cipher();
		$plain = str_repeat('dedup me ', 500);

		$this->assertSame($cipher->seal($plain, 'aad'), $cipher->seal($plain, 'aad'));
	}

	#[Test]
	#[
		TestDox(
			'two cipher instances on one key seal identically, so dedup survives a request boundary',
		),
	]
	#[Group('strata/crypto')]
	public function determinismSurvivesAcrossInstances(): void
	{
		$key = StaticKeyProvider::generate()->key();
		$plain = str_repeat('across requests ', 200);

		$this->assertSame(
			(new XChaCha20Poly1305Cipher($key))->seal($plain),
			(new XChaCha20Poly1305Cipher($key))->seal($plain),
		);
	}

	#[Test]
	#[TestDox('different plaintexts get different nonces, so a nonce is never reused')]
	#[Group('strata/crypto')]
	public function differentPlaintextsGetDifferentNonces(): void
	{
		$cipher = $this->cipher();
		$nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

		$nonces = [];
		for ($i = 0; $i < 200; $i++) {
			$nonces[] = substr($cipher->seal('payload variant ' . $i), 0, $nonceLength);
		}

		$this->assertCount(
			200,
			array_unique($nonces),
			'every distinct plaintext needs its own nonce',
		);
	}

	#[Test]
	#[TestDox('a one-bit plaintext change changes the nonce completely')]
	#[Group('strata/crypto')]
	public function oneBitChangeChangesTheNonce(): void
	{
		$cipher = $this->cipher();
		$nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

		$a = substr($cipher->seal('payload'), 0, $nonceLength);
		$b = substr($cipher->seal('payloae'), 0, $nonceLength);

		$this->assertNotSame($a, $b);
	}

	#[Test]
	#[TestDox('two sites with different keys share no ciphertext, so nothing leaks between them')]
	#[Group('strata/crypto')]
	public function differentKeysShareNoCiphertext(): void
	{
		$plain = str_repeat('the same content on two sites ', 100);

		$this->assertNotSame($this->cipher()->seal($plain), $this->cipher()->seal($plain));
	}

	#endregion

	#region Round trips

	#[Test]
	#[TestDox('$_dataName round-trips through seal and open exactly')]
	#[Group('strata/crypto')]
	#[DataProvider('payloadProvider')]
	public function roundTripsExactly(string $payload): void
	{
		$cipher = $this->cipher();
		$aad = Hash::of($payload);

		$this->assertSame($payload, $cipher->open($cipher->seal($payload, $aad), $aad));
	}

	#[Test]
	#[TestDox('an empty payload seals to an empty frame rather than to a nonce and tag')]
	#[Group('strata/crypto')]
	public function emptyPayloadStaysEmpty(): void
	{
		$cipher = $this->cipher();

		$this->assertSame('', $cipher->seal(''));
		$this->assertSame('', $cipher->open(''));
	}

	#[Test]
	#[TestDox('the null cipher round-trips $_dataName and changes nothing')]
	#[Group('strata/crypto')]
	#[DataProvider('payloadProvider')]
	public function nullCipherChangesNothing(string $payload): void
	{
		$cipher = new NullCipher();

		$this->assertSame($payload, $cipher->seal($payload, 'aad'));
		$this->assertSame($payload, $cipher->open($payload, 'aad'));
	}

	#[Test]
	#[TestDox('every cipher reports a stable id and consistent availability')]
	#[Group('strata/crypto')]
	public function ciphersReportConsistentMetadata(): void
	{
		foreach ([$this->cipher(), new NullCipher()] as $cipher) {
			$this->assertInstanceOf(CipherInterface::class, $cipher);
			$this->assertSame(strtolower($cipher->id()), $cipher->id());
			$this->assertSame($cipher->isAvailable(), $cipher->unavailableReason() === null);
		}

		$this->assertSame('xchacha20poly1305', $this->cipher()->id());
		$this->assertSame('none', (new NullCipher())->id());
	}

	#endregion

	#region Tamper detection

	#[Test]
	#[TestDox('a flipped bit anywhere in a sealed frame is refused')]
	#[Group('strata/crypto')]
	public function flippedBitIsRefused(): void
	{
		$cipher = $this->cipher();
		$sealed = $cipher->seal(str_repeat('tamper ', 100), 'aad');

		$positions = [
			0,
			12,
			SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES,
			strlen($sealed) - 1,
		];

		foreach ($positions as $position) {
			$corrupted = $sealed;
			$corrupted[$position] = chr(ord($corrupted[$position]) ^ 0x01);

			try {
				$cipher->open($corrupted, 'aad');
				$this->fail(sprintf('a flipped bit at offset %d was accepted', $position));
			} catch (RuntimeException $e) {
				$this->assertStringContainsString('failed authentication', $e->getMessage());
			}
		}
	}

	#[Test]
	#[TestDox('the wrong associated data is refused, so a relocated frame cannot open')]
	#[Group('strata/crypto')]
	public function wrongAssociatedDataIsRefused(): void
	{
		$cipher = $this->cipher();
		$sealed = $cipher->seal('content', Hash::of('content'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('failed authentication');

		$cipher->open($sealed, Hash::of('somewhere else'));
	}

	#[Test]
	#[TestDox('a frame sealed under another key is refused rather than returning noise')]
	#[Group('strata/crypto')]
	public function wrongKeyIsRefused(): void
	{
		$sealed = $this->cipher()->seal('content', 'aad');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('failed authentication');

		$this->cipher()->open($sealed, 'aad');
	}

	#[Test]
	#[TestDox('a truncated frame is named as truncated rather than failing authentication')]
	#[Group('strata/crypto')]
	public function truncatedFrameIsNamed(): void
	{
		$cipher = $this->cipher();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('so it is truncated');

		$cipher->open('short');
	}

	#endregion

	#region Keys

	/**
	 * @return array<string, array{int}>
	 */
	public static function badKeyLengthProvider(): array
	{
		return ['empty' => [0], 'one short' => [31], 'one long' => [33], 'far too short' => [8]];
	}

	#[Test]
	#[TestDox('a key of the wrong length ($_dataName) is refused rather than stretched')]
	#[Group('strata/crypto')]
	#[DataProvider('badKeyLengthProvider')]
	public function badKeyLengthIsRefused(int $length): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('must be exactly 32 bytes');

		new XChaCha20Poly1305Cipher(str_repeat("\0", $length));
	}

	#[Test]
	#[TestDox('the key provider refuses a wrong-length key too')]
	#[Group('strata/crypto')]
	public function providerRefusesBadKeyLength(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('must be exactly 32 bytes');

		new StaticKeyProvider('too short');
	}

	#[Test]
	#[TestDox('generate() produces a usable distinct key every time')]
	#[Group('strata/crypto')]
	public function generateProducesDistinctKeys(): void
	{
		$first = StaticKeyProvider::generate();
		$second = StaticKeyProvider::generate();

		$this->assertSame(KeyProviderInterface::KEY_BYTES, strlen($first->key()));
		$this->assertNotSame($first->key(), $second->key());
		$this->assertTrue($first->hasKey());
	}

	#[Test]
	#[TestDox('fromHex() round-trips a key and rejects malformed hex')]
	#[Group('strata/crypto')]
	public function fromHexRoundTripsAndValidates(): void
	{
		$key = StaticKeyProvider::generate()->key();

		$this->assertSame($key, StaticKeyProvider::fromHex(bin2hex($key))->key());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('must be exactly 64 hex characters');

		StaticKeyProvider::fromHex('zz');
	}

	#[Test]
	#[TestDox('a fingerprint is stable, short, and not the key')]
	#[Group('strata/crypto')]
	public function fingerprintIsStableAndNotTheKey(): void
	{
		$provider = StaticKeyProvider::generate();
		$fingerprint = $provider->fingerprint();

		$this->assertSame($fingerprint, $provider->fingerprint());
		$this->assertSame(16, strlen($fingerprint));
		$this->assertTrue(ctype_xdigit($fingerprint));
		$this->assertStringNotContainsString(bin2hex($provider->key()), $fingerprint);
		$this->assertNotSame($fingerprint, StaticKeyProvider::generate()->fingerprint());
	}

	#endregion
}
