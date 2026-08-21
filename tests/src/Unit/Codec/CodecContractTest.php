<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Codec;

use Drupal\strata\Codec\CompressionCodecInterface;
use Drupal\strata\Codec\GzipCodec;
use Drupal\strata\Codec\NoneCodec;
use Drupal\strata\Codec\ZstdCodec;
use Drupal\strata\Codec\ZstdPipeCodec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoneCodec::class)]
#[CoversClass(GzipCodec::class)]
#[CoversClass(ZstdCodec::class)]
#[CoversClass(ZstdPipeCodec::class)]
class CodecContractTest extends TestCase
{
	#region Contract

	/**
	 * Every codec, whether or not this host can run it.
	 *
	 * @return array<string, array{CompressionCodecInterface}>
	 */
	public static function codecProvider(): array
	{
		return [
			'none' => [new NoneCodec()],
			'gzip' => [new GzipCodec()],
			'zstd extension' => [new ZstdCodec()],
			'zstd binary' => [new ZstdPipeCodec()],
		];
	}

	/**
	 * Payload shapes a frame realistically takes.
	 *
	 * @return array<string, array{string}>
	 */
	public static function payloadProvider(): array
	{
		return [
			'empty' => [''],
			'one byte' => ['x'],
			'json entity image' => [
				(string) json_encode([
					'nid' => [['value' => 42]],
					'title' => [['value' => 'A node title']],
					'body' => [['value' => str_repeat('lorem ipsum dolor ', 40)]],
				]),
			],
			'highly repetitive' => [str_repeat('strata', 4000)],
			'incompressible' => [random_bytes(16384)],
			'binary with nulls' => ["\0\1\2" . random_bytes(1024) . "\0"],
		];
	}

	#[Test]
	#[TestDox('$_dataName reports a consistent availability, reason and level range')]
	#[Group('strata/codec')]
	#[DataProvider('codecProvider')]
	public function metadataIsConsistent(CompressionCodecInterface $codec): void
	{
		$this->assertNotSame('', $codec->id());
		$this->assertSame(strtolower($codec->id()), $codec->id());

		// a reason is present exactly when the codec cannot run
		$this->assertSame($codec->isAvailable(), $codec->unavailableReason() === null);

		$levels = $codec->levels();
		$this->assertLessThanOrEqual($levels['max'], $levels['min']);
		$this->assertGreaterThanOrEqual($levels['min'], $levels['default']);
		$this->assertLessThanOrEqual($levels['max'], $levels['default']);
		$this->assertGreaterThanOrEqual($levels['min'], $levels['fast']);
		$this->assertLessThanOrEqual($levels['max'], $levels['dense']);
		$this->assertLessThanOrEqual($levels['dense'], $levels['fast']);
	}

	#[Test]
	#[TestDox('an unavailable codec never claims dictionary support')]
	#[Group('strata/codec')]
	#[DataProvider('codecProvider')]
	public function unavailableCodecClaimsNoDictionary(CompressionCodecInterface $codec): void
	{
		if ($codec->isAvailable()) {
			$this->assertTrue(true);

			return;
		}

		$this->assertFalse($codec->supportsDictionary());
	}

	#[Test]
	#[TestDox('an empty payload compresses to an empty frame in $_dataName')]
	#[Group('strata/codec')]
	#[DataProvider('codecProvider')]
	public function emptyPayloadStaysEmpty(CompressionCodecInterface $codec): void
	{
		// true even when unavailable: an absent payload must never become a non-empty frame
		$this->assertSame('', $codec->compress(''));
		$this->assertSame('', $codec->decompress(''));
	}

	#endregion

	#region Round trips

	#[Test]
	#[TestDox('gzip round-trips $_dataName exactly')]
	#[Group('strata/codec')]
	#[DataProvider('payloadProvider')]
	public function gzipRoundTrips(string $payload): void
	{
		$codec = new GzipCodec();

		$this->assertTrue($codec->isAvailable(), 'ext-zlib is required by Drupal core');
		$this->assertSame($payload, $codec->decompress($codec->compress($payload)));
	}

	#[Test]
	#[TestDox('none round-trips $_dataName exactly and changes nothing')]
	#[Group('strata/codec')]
	#[DataProvider('payloadProvider')]
	public function noneRoundTrips(string $payload): void
	{
		$codec = new NoneCodec();

		$this->assertSame($payload, $codec->compress($payload));
		$this->assertSame($payload, $codec->decompress($payload));
	}

	#[Test]
	#[TestDox('gzip round-trips at every level in its range')]
	#[Group('strata/codec')]
	public function gzipRoundTripsAtEveryLevel(): void
	{
		$codec = new GzipCodec();
		$payload = str_repeat('strata rollback ', 500);
		$levels = $codec->levels();

		for ($level = $levels['min']; $level <= $levels['max']; $level++) {
			$this->assertSame($payload, $codec->decompress($codec->compress($payload, $level)));
		}
	}

	#[Test]
	#[TestDox('gzip clamps a level outside its range instead of failing')]
	#[Group('strata/codec')]
	public function gzipClampsOutOfRangeLevel(): void
	{
		$codec = new GzipCodec();
		$payload = str_repeat('clamp me ', 200);

		$this->assertSame($payload, $codec->decompress($codec->compress($payload, -50)));
		$this->assertSame($payload, $codec->decompress($codec->compress($payload, 9999)));
	}

	#[Test]
	#[TestDox('gzip actually shrinks repetitive content')]
	#[Group('strata/codec')]
	public function gzipShrinksRepetitiveContent(): void
	{
		$payload = str_repeat('strata', 4000);
		$compressed = (new GzipCodec())->compress($payload, 9);

		$this->assertLessThan(strlen($payload) / 10, strlen($compressed));
	}

	#endregion

	#region Corruption

	#[Test]
	#[TestDox('gzip raises on a corrupted frame rather than returning partial output')]
	#[Group('strata/codec')]
	public function gzipRaisesOnCorruption(): void
	{
		$codec = new GzipCodec();

		$this->expectExceptionMessage('gzip decompression failed');

		$codec->decompress('this is not zlib data at all, not even close');
	}

	#[Test]
	#[TestDox('gzip raises when a valid frame is truncated')]
	#[Group('strata/codec')]
	public function gzipRaisesOnTruncation(): void
	{
		$codec = new GzipCodec();
		$compressed = $codec->compress(str_repeat('truncate me ', 500), 9);

		$this->expectExceptionMessage('gzip decompression failed');

		$codec->decompress(substr($compressed, 0, intdiv(strlen($compressed), 2)));
	}

	#endregion

	#region Zstd binary

	#[Test]
	#[TestDox('the zstd binary codec round-trips $_dataName exactly')]
	#[Group('strata/codec')]
	#[DataProvider('payloadProvider')]
	public function zstdPipeRoundTrips(string $payload): void
	{
		$codec = new ZstdPipeCodec();

		if (!$codec->isAvailable()) {
			$this->markTestSkipped(
				'the zstd binary is not on PATH: ' . $codec->unavailableReason(),
			);
		}

		$this->assertSame($payload, $codec->decompress($codec->compress($payload)));
	}

	#[Test]
	#[TestDox('a batch of 50 buffers costs one process, not fifty')]
	#[Group('strata/codec')]
	public function batchSpawnsOneProcess(): void
	{
		$codec = new ZstdPipeCodec();

		if (!$codec->isAvailable()) {
			$this->markTestSkipped('the zstd binary is not on PATH');
		}

		$buffers = [];
		for ($i = 0; $i < 50; $i++) {
			$buffers[] = str_repeat("frame $i payload ", 100);
		}

		$compressed = $codec->compressBatch($buffers, 19);
		$this->assertSame(1, $codec->spawnCount(), 'compressing 50 buffers must cost one spawn');

		$this->assertSame($buffers, $codec->decompressBatch($compressed));
		$this->assertSame(2, $codec->spawnCount(), 'decompressing 50 buffers must cost one more');
	}

	#[Test]
	#[TestDox('an empty batch does no work and spawns nothing')]
	#[Group('strata/codec')]
	public function emptyBatchSpawnsNothing(): void
	{
		$codec = new ZstdPipeCodec();

		$this->assertSame([], $codec->compressBatch([]));
		$this->assertSame([], $codec->decompressBatch([]));
		$this->assertSame(0, $codec->spawnCount());
	}

	#[Test]
	#[TestDox('a dictionary changes the bytes and is required to read them back')]
	#[Group('strata/codec')]
	public function dictionaryIsHonouredAndRequired(): void
	{
		$codec = new ZstdPipeCodec();

		if (!$codec->isAvailable()) {
			$this->markTestSkipped('the zstd binary is not on PATH');
		}

		$dictionary = str_repeat('strata rollback commit frame segment ', 300);
		$payload = 'strata rollback commit frame segment strata rollback commit';

		$plain = $codec->compress($payload, 19);
		$withDictionary = $codec->compress($payload, 19, $dictionary);

		$this->assertNotSame($plain, $withDictionary);
		$this->assertSame($payload, $codec->decompress($withDictionary, $dictionary));
	}

	#[Test]
	#[TestDox('a missing binary is reported rather than fataling')]
	#[Group('strata/codec')]
	public function missingBinaryIsReported(): void
	{
		$codec = new ZstdPipeCodec('/nonexistent/strata/zstd');

		$this->assertFalse($codec->isAvailable());
		$this->assertSame('the zstd binary was not found on PATH', $codec->unavailableReason());

		$this->expectExceptionMessage('zstd binary codec is unavailable');

		$codec->compress('anything');
	}

	#[Test]
	#[TestDox('the binary and the extension share one codec id, so either can read the other')]
	#[Group('strata/codec')]
	public function bothZstdCodecsShareAnId(): void
	{
		$this->assertSame((new ZstdCodec())->id(), (new ZstdPipeCodec())->id());
	}

	#endregion

	#region Zstd extension

	#[Test]
	#[TestDox('the zstd extension codec reports itself unavailable when ext-zstd is absent')]
	#[Group('strata/codec')]
	public function zstdExtensionReportsAvailability(): void
	{
		$codec = new ZstdCodec();

		if (function_exists('zstd_compress')) {
			$this->assertTrue($codec->isAvailable());
			$this->assertNull($codec->unavailableReason());

			return;
		}

		$this->assertFalse($codec->isAvailable());
		$this->assertSame('ext-zstd is not loaded', $codec->unavailableReason());

		$this->expectExceptionMessage('zstd codec is unavailable');
		$codec->compress('anything');
	}

	#[Test]
	#[TestDox('the zstd extension codec round-trips when the extension is present')]
	#[Group('strata/codec')]
	public function zstdExtensionRoundTrips(): void
	{
		$codec = new ZstdCodec();

		if (!$codec->isAvailable()) {
			$this->markTestSkipped('ext-zstd is not loaded');
		}

		$payload = str_repeat('strata extension path ', 500);
		$this->assertSame($payload, $codec->decompress($codec->compress($payload, 19)));
	}

	#endregion
}
