<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Cas;

use Drupal\strata\Cas\Hash;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Hash::class)]
class HashTest extends TestCase
{
	#region Vectors

	/**
	 * RFC 7693 BLAKE2b-256 vectors, confirmed against the running sodium extension.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function vectorProvider(): array
	{
		return [
			'empty string' => [
				'',
				'0e5751c026e543b2e8ab2eb06099daa1d1e5df47778f7787faab45cdf12fe3a8',
			],
			'abc' => ['abc', 'bddd813c634239723171ef3fee98579b94964e3bb1cb3e427262c8c068d52319'],
			'pangram' => [
				'The quick brown fox jumps over the lazy dog',
				'01718cec35cd3d796dd00020e0bfecb473ad23457d063b75eff29c0ffa2e58a9',
			],
		];
	}

	#[Test]
	#[TestDox('of() matches the published blake2b-256 vector for $_dataName')]
	#[Group('strata/cas')]
	#[DataProvider('vectorProvider')]
	public function ofMatchesVectors(string $input, string $expected): void
	{
		$this->assertSame($expected, Hash::of($input));
	}

	#[Test]
	#[TestDox('of() digests a long repeated input to the extension vector')]
	#[Group('strata/cas')]
	public function ofDigestsLongInput(): void
	{
		$this->assertSame(
			'e00b0ddbf1e2cdaf5c898e1a5e8826ea3a2c339bcf2a478da2e5fca9ff126672',
			Hash::of(str_repeat('a', 1000)),
		);
	}

	#[Test]
	#[TestDox('raw() returns exactly BYTES bytes and of() is its hex')]
	#[Group('strata/cas')]
	public function rawIsTheBinaryFormOfOf(): void
	{
		$raw = Hash::raw('strata');

		$this->assertSame(Hash::BYTES, strlen($raw));
		$this->assertSame(Hash::of('strata'), bin2hex($raw));
	}

	#[Test]
	#[TestDox('a key changes the digest and is honoured by raw()')]
	#[Group('strata/cas')]
	public function keyedHashingDiffersFromUnkeyed(): void
	{
		$key = str_pad('strata', 32, "\0");

		$this->assertNotSame(Hash::of('abc'), Hash::of('abc', $key));
		$this->assertSame(
			'3bbf3efc21af82cd086e14845b8b5855275b836855491f3ea281e336564b6ba9',
			Hash::of('abc', $key),
		);
	}

	#[Test]
	#[TestDox('a key over the sodium maximum is refused rather than truncated')]
	#[Group('strata/cas')]
	public function overlongKeyIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Hash key is');

		Hash::of('abc', str_repeat('k', SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MAX + 1));
	}

	#endregion

	#region Streams

	#[Test]
	#[TestDox('ofStream() over a whole stream equals of() over the same bytes')]
	#[Group('strata/cas')]
	public function ofStreamMatchesOf(): void
	{
		$payload = random_bytes(3 * 1048576 + 17);
		$stream = fopen('php://temp', 'r+b');
		fwrite($stream, $payload);
		rewind($stream);

		$this->assertSame(Hash::of($payload), Hash::ofStream($stream));

		fclose($stream);
	}

	#[Test]
	#[TestDox('ofStream() honours a byte limit and leaves the pointer where it stopped')]
	#[Group('strata/cas')]
	public function ofStreamHonoursLimit(): void
	{
		$payload = str_repeat('strata', 5000);
		$stream = fopen('php://temp', 'r+b');
		fwrite($stream, $payload);
		rewind($stream);

		$this->assertSame(Hash::of(substr($payload, 0, 128)), Hash::ofStream($stream, 128));
		$this->assertSame(128, ftell($stream));

		fclose($stream);
	}

	#[Test]
	#[TestDox('ofStream() resumes from the current position')]
	#[Group('strata/cas')]
	public function ofStreamResumesFromPosition(): void
	{
		$payload = str_repeat('ab', 4096);
		$stream = fopen('php://temp', 'r+b');
		fwrite($stream, $payload);
		fseek($stream, 100);

		$this->assertSame(Hash::of(substr($payload, 100)), Hash::ofStream($stream));

		fclose($stream);
	}

	#[Test]
	#[TestDox('ofStream() on an empty stream equals the empty-string digest')]
	#[Group('strata/cas')]
	public function ofStreamOnEmptyStream(): void
	{
		$stream = fopen('php://temp', 'r+b');

		$this->assertSame(Hash::of(''), Hash::ofStream($stream));

		fclose($stream);
	}

	#[Test]
	#[TestDox('ofStream() refuses a non-stream argument')]
	#[Group('strata/cas')]
	public function ofStreamRefusesNonStream(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('needs an open stream resource');

		/** @phpstan-ignore-next-line intentionally wrong type */
		Hash::ofStream('not a stream');
	}

	#[Test]
	#[TestDox('ofStream() refuses a negative limit')]
	#[Group('strata/cas')]
	public function ofStreamRefusesNegativeLimit(): void
	{
		$stream = fopen('php://temp', 'r+b');

		try {
			$this->expectException(InvalidArgumentException::class);
			$this->expectExceptionMessage('limit cannot be negative');

			Hash::ofStream($stream, -1);
		} finally {
			fclose($stream);
		}
	}

	#[Test]
	#[TestDox('ofFile() equals of() over the file contents')]
	#[Group('strata/cas')]
	public function ofFileMatchesOf(): void
	{
		$path = tempnam(sys_get_temp_dir(), 'strata-hash-');
		$payload = random_bytes(65_536 + 3);
		file_put_contents($path, $payload);

		try {
			$this->assertSame(Hash::of($payload), Hash::ofFile($path));
		} finally {
			unlink($path);
		}
	}

	#[Test]
	#[TestDox('ofFile() raises rather than returning a digest of nothing')]
	#[Group('strata/cas')]
	public function ofFileRaisesOnMissingFile(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot open');

		Hash::ofFile('/nonexistent/strata/' . bin2hex(random_bytes(8)));
	}

	#endregion

	#region Comparison

	#[Test]
	#[TestDox('equals() accepts hex and raw on either side')]
	#[Group('strata/cas')]
	public function equalsAcceptsBothRepresentations(): void
	{
		$hex = Hash::of('strata');
		$raw = Hash::raw('strata');

		$this->assertTrue(Hash::equals($hex, $hex));
		$this->assertTrue(Hash::equals($raw, $raw));
		$this->assertTrue(Hash::equals($hex, $raw));
		$this->assertTrue(Hash::equals($raw, $hex));
		$this->assertTrue(Hash::equals(strtoupper($hex), $raw));
	}

	#[Test]
	#[TestDox('equals() is false for a different digest and for malformed input')]
	#[Group('strata/cas')]
	public function equalsRejectsMismatchAndGarbage(): void
	{
		$this->assertFalse(Hash::equals(Hash::of('a'), Hash::of('b')));
		$this->assertFalse(Hash::equals(Hash::of('a'), 'truncated'));
		$this->assertFalse(Hash::equals('', Hash::of('a')));
		$this->assertFalse(Hash::equals('zz' . substr(Hash::of('a'), 2), Hash::of('a')));
	}

	#endregion

	#region Keys and display

	#[Test]
	#[TestDox('shard() splits into SHARD_DEPTH two-character buckets')]
	#[Group('strata/cas')]
	public function shardSplitsIntoBuckets(): void
	{
		$this->assertSame('bd/dd', Hash::shard(Hash::of('abc')));
		$this->assertSame(Hash::SHARD_DEPTH, count(explode('/', Hash::shard(Hash::of('abc')))));
	}

	#[Test]
	#[TestDox('key() composes prefix, shard and digest')]
	#[Group('strata/cas')]
	public function keyComposesTheObjectPath(): void
	{
		$hex = Hash::of('abc');

		$this->assertSame('frames/bd/dd/' . $hex, Hash::key($hex, 'frames'));
		$this->assertSame('frames/bd/dd/' . $hex, Hash::key($hex, '/frames/'));
		$this->assertSame('bd/dd/' . $hex, Hash::key($hex, ''));
	}

	#[Test]
	#[TestDox('abbreviate() keeps the requested prefix length')]
	#[Group('strata/cas')]
	public function abbreviateKeepsPrefix(): void
	{
		$hex = Hash::of('abc');

		$this->assertSame(substr($hex, 0, 12), Hash::abbreviate($hex));
		$this->assertSame(substr($hex, 0, 7), Hash::abbreviate($hex, 7));
		$this->assertSame($hex, Hash::abbreviate($hex, Hash::HEX_LENGTH));
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function badAbbreviationProvider(): array
	{
		return ['too short' => [3], 'zero' => [0], 'negative' => [-1], 'too long' => [65]];
	}

	#[Test]
	#[TestDox('abbreviate() refuses length $_dataName')]
	#[Group('strata/cas')]
	#[DataProvider('badAbbreviationProvider')]
	public function abbreviateRefusesBadLength(int $length): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Abbreviation length must be between');

		Hash::abbreviate(Hash::of('abc'), $length);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function invalidDigestProvider(): array
	{
		return [
			'empty' => [''],
			'too short' => [str_repeat('a', 63)],
			'too long' => [str_repeat('a', 65)],
			'non hex' => [str_repeat('z', 64)],
			'uppercase' => [str_repeat('A', 64)],
			'raw bytes' => [random_bytes(32)],
		];
	}

	#[Test]
	#[TestDox('isValid() rejects $_dataName')]
	#[Group('strata/cas')]
	#[DataProvider('invalidDigestProvider')]
	public function isValidRejectsMalformed(string $candidate): void
	{
		$this->assertFalse(Hash::isValid($candidate));
	}

	#[Test]
	#[TestDox('isValid() accepts a real digest')]
	#[Group('strata/cas')]
	public function isValidAcceptsRealDigest(): void
	{
		$this->assertTrue(Hash::isValid(Hash::of('abc')));
	}

	#[Test]
	#[TestDox('shard() refuses a malformed digest rather than slicing garbage')]
	#[Group('strata/cas')]
	public function shardRefusesMalformed(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('lowercase hex characters');

		Hash::shard('nope');
	}

	#[Test]
	#[TestDox('shard() names an empty digest explicitly in the error')]
	#[Group('strata/cas')]
	public function shardNamesEmptyInput(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('an empty string');

		Hash::shard('');
	}

	#[Test]
	#[TestDox('ALGORITHM, BYTES and HEX_LENGTH stay consistent with each other')]
	#[Group('strata/cas')]
	public function constantsAreConsistent(): void
	{
		$this->assertSame('blake2b-256', Hash::ALGORITHM);
		$this->assertSame(32, Hash::BYTES);
		$this->assertSame(Hash::BYTES * 2, Hash::HEX_LENGTH);
		$this->assertSame(Hash::BYTES, strlen(Hash::raw('x')));
		$this->assertSame(Hash::HEX_LENGTH, strlen(Hash::of('x')));
	}

	#endregion
}
