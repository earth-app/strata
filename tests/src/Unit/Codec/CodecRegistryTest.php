<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Codec;

use Drupal\strata\Codec\BrotliCodec;
use Drupal\strata\Codec\CodecRegistry;
use Drupal\strata\Codec\CompressionCodecInterface;
use Drupal\strata\Codec\GzipCodec;
use Drupal\strata\Codec\NoneCodec;
use Drupal\strata\Codec\ZstdCodec;
use Drupal\strata\Codec\ZstdPipeCodec;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CodecRegistry::class)]
class CodecRegistryTest extends TestCase
{
	#region Fakes

	/**
	 * A codec whose availability, id and dictionary support the test dictates.
	 */
	private function fake(
		string $id,
		bool $available = true,
		bool $dictionary = false,
		string $reason = 'fake is switched off',
	): CompressionCodecInterface {
		return new class ($id, $available, $dictionary, $reason) implements
			CompressionCodecInterface
		{
			public function __construct(
				private readonly string $codecId,
				private readonly bool $available,
				private readonly bool $dictionary,
				private readonly string $reason,
			) {}

			public function id(): string
			{
				return $this->codecId;
			}

			public function isAvailable(): bool
			{
				return $this->available;
			}

			public function unavailableReason(): ?string
			{
				return $this->available ? null : $this->reason;
			}

			public function supportsDictionary(): bool
			{
				return $this->available && $this->dictionary;
			}

			public function levels(): array
			{
				return ['min' => 1, 'max' => 9, 'default' => 5, 'fast' => 1, 'dense' => 9];
			}

			public function compress(
				string $data,
				?int $level = null,
				?string $dictionary = null,
			): string {
				return strrev($data);
			}

			public function decompress(string $data, ?string $dictionary = null): string
			{
				return strrev($data);
			}
		};
	}

	#endregion

	#region Selection

	#[Test]
	#[TestDox('the shipped registry always has a writer, because none is always available')]
	#[Group('strata/codec')]
	public function shippedRegistryAlwaysProvidesWriter(): void
	{
		$registry = CodecRegistry::withShippedCodecs();

		$this->assertInstanceOf(CompressionCodecInterface::class, $registry->writer());
		$this->assertContains('none', $registry->available());
		$this->assertContains('gzip', $registry->available());
	}

	#[Test]
	#[TestDox('a shelling codec never wins the flush path')]
	#[Group('strata/codec')]
	public function shellingCodecNeverWinsTheFlushPath(): void
	{
		$registry = CodecRegistry::withShippedCodecs();

		$this->assertNotInstanceOf(ZstdPipeCodec::class, $registry->writer());
		$this->assertNotInstanceOf(ZstdPipeCodec::class, $registry->dictionaryWriter());
	}

	#[Test]
	#[TestDox('a shelling codec is still used for bulk work and for reading')]
	#[Group('strata/codec')]
	public function shellingCodecServesBulkAndReads(): void
	{
		$registry = CodecRegistry::withShippedCodecs();

		if (!(new ZstdPipeCodec())->isAvailable()) {
			$this->markTestSkipped('the zstd binary is not on PATH');
		}

		$this->assertTrue($registry->canRead('zstd'));
		$this->assertSame('zstd', $registry->reader('zstd')->id());
		$this->assertSame('zstd', $registry->bulkWriter()->id());
	}

	#[Test]
	#[TestDox('write preference puts dictionary support above raw ratio')]
	#[Group('strata/codec')]
	public function dictionarySupportOutranksRatio(): void
	{
		$registry = (new CodecRegistry())
			->register($this->fake('gzip', true, false))
			->register($this->fake('zstd', true, true));

		$this->assertSame('zstd', $registry->writer()->id());
		$this->assertNotNull($registry->dictionaryWriter());
		$this->assertSame('zstd', $registry->dictionaryWriter()?->id());
	}

	#[Test]
	#[
		TestDox(
			'dictionaryWriter() is null rather than falling back to a codec that cannot take one',
		),
	]
	#[Group('strata/codec')]
	public function dictionaryWriterIsNullWhenNoneCan(): void
	{
		$registry = (new CodecRegistry())
			->register($this->fake('gzip', true, false))
			->register($this->fake('none', true, false));

		$this->assertNull($registry->dictionaryWriter());
		$this->assertSame('gzip', $registry->writer()->id());
	}

	#[Test]
	#[TestDox('an unavailable codec is never selected to write')]
	#[Group('strata/codec')]
	public function unavailableCodecIsNeverSelected(): void
	{
		$registry = (new CodecRegistry())
			->register($this->fake('zstd', false, true, 'ext-zstd is not loaded'))
			->register($this->fake('gzip', true, false));

		$this->assertSame('gzip', $registry->writer()->id());
		$this->assertSame(['gzip'], $registry->available());
		$this->assertSame(['zstd' => ['ext-zstd is not loaded']], $registry->unavailable());
	}

	#[Test]
	#[TestDox('an add-on codec under an unranked id still beats having no writer')]
	#[Group('strata/codec')]
	public function unrankedCodecStillWins(): void
	{
		$registry = (new CodecRegistry())->register($this->fake('lz4', true, false));

		$this->assertSame('lz4', $registry->writer()->id());
		$this->assertSame(['lz4'], $registry->available());
	}

	#[Test]
	#[TestDox('an empty registry says so rather than returning nothing')]
	#[Group('strata/codec')]
	public function emptyRegistryRaises(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No compression codec is registered');

		(new CodecRegistry())->writer();
	}

	#endregion

	#region Reading

	#[Test]
	#[TestDox('reading falls through to the second implementation of the same id')]
	#[Group('strata/codec')]
	public function readingFallsThroughToTheSecondImplementation(): void
	{
		$extension = $this->fake('zstd', false, true, 'ext-zstd is not loaded');
		$binary = $this->fake('zstd', true, true);

		$registry = (new CodecRegistry())->register($extension)->register($binary, false);

		$this->assertTrue($registry->canRead('zstd'));
		$this->assertSame($binary, $registry->reader('zstd'));
	}

	#[Test]
	#[TestDox('an unreadable id names every reason its implementations gave')]
	#[Group('strata/codec')]
	public function unreadableIdNamesEveryReason(): void
	{
		$registry = (new CodecRegistry())
			->register($this->fake('zstd', false, true, 'ext-zstd is not loaded'))
			->register(
				$this->fake('zstd', false, true, 'the zstd binary was not found on PATH'),
				false,
			);

		$this->assertFalse($registry->canRead('zstd'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('ext-zstd is not loaded; the zstd binary was not found');

		$registry->reader('zstd');
	}

	#[Test]
	#[TestDox('an entirely unknown id is reported as not installed rather than as broken')]
	#[Group('strata/codec')]
	public function unknownIdIsReportedAsNotInstalled(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No codec is registered for "lzma"');

		CodecRegistry::withShippedCodecs()->reader('lzma');
	}

	#[Test]
	#[TestDox('get() returns an unavailable codec so its reason can be shown')]
	#[Group('strata/codec')]
	public function getReturnsUnavailableCodecs(): void
	{
		$registry = CodecRegistry::withShippedCodecs();

		$this->assertInstanceOf(ZstdCodec::class, $registry->get('zstd'));
		$this->assertInstanceOf(BrotliCodec::class, $registry->get('brotli'));
		$this->assertInstanceOf(GzipCodec::class, $registry->get('gzip'));
		$this->assertInstanceOf(NoneCodec::class, $registry->get('none'));
	}

	#[Test]
	#[TestDox('get() refuses an id nothing is registered under')]
	#[Group('strata/codec')]
	public function getRefusesUnknownId(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('No codec is registered as "lzma"');

		CodecRegistry::withShippedCodecs()->get('lzma');
	}

	#[Test]
	#[TestDox('a frame written by any available codec reads back through the registry')]
	#[Group('strata/codec')]
	public function anyWrittenFrameReadsBack(): void
	{
		$registry = CodecRegistry::withShippedCodecs();
		$payload = str_repeat('registry round trip ', 200);

		foreach ($registry->available() as $id) {
			$writer = $registry->get($id);
			$frame = $writer->compress($payload);

			$this->assertSame($payload, $registry->reader($id)->decompress($frame), $id);
		}
	}

	#endregion
}
