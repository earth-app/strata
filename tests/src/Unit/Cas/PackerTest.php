<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Cas;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Cas\Packer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Packer::class)]
class PackerTest extends TestCase
{
	#region Construction

	/**
	 * @return array<string, array{int}>
	 */
	public static function badTargetProvider(): array
	{
		return [
			'zero' => [0],
			'negative' => [-1],
			'below minimum' => [Packer::MIN_TARGET - 1],
			'above maximum' => [Packer::MAX_TARGET + 1],
		];
	}

	#[Test]
	#[TestDox('a pack target of $_dataName is refused')]
	#[Group('strata/cas')]
	#[DataProvider('badTargetProvider')]
	public function refusesBadTarget(int $target): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('pack target must be between');

		new Packer($target);
	}

	#[Test]
	#[TestDox('the boundary targets are accepted and a fresh packer is empty')]
	#[Group('strata/cas')]
	public function acceptsBoundaryTargets(): void
	{
		$this->assertSame(Packer::MIN_TARGET, (new Packer(Packer::MIN_TARGET))->target());
		$this->assertSame(Packer::MAX_TARGET, (new Packer(Packer::MAX_TARGET))->target());

		$packer = new Packer();
		$this->assertSame(Packer::DEFAULT_TARGET, $packer->target());
		$this->assertSame(0, $packer->pending());
		$this->assertSame(0, $packer->buffered());
		$this->assertFalse($packer->isFull());
		$this->assertNull($packer->flush());
	}

	#endregion

	#region Buffering

	#[Test]
	#[TestDox('frames accumulate until the target is reached')]
	#[Group('strata/cas')]
	public function framesAccumulateUntilFull(): void
	{
		$packer = new Packer(Packer::MIN_TARGET);
		$frame = str_repeat('x', 16384);

		for ($i = 0; $i < 3; $i++) {
			$packer->add(Hash::of("frame $i"), $frame);
			$this->assertFalse($packer->isFull(), 'three 16 KiB frames stay under 64 KiB');
		}

		$this->assertSame(3, $packer->pending());
		$this->assertSame(49152, $packer->buffered());

		$packer->add(Hash::of('frame 3'), $frame);
		$this->assertTrue($packer->isFull());
	}

	#[Test]
	#[TestDox('a frame at or above the target belongs in its own object')]
	#[Group('strata/cas')]
	public function largeFrameGoesAlone(): void
	{
		$packer = new Packer(Packer::MIN_TARGET);

		$this->assertFalse($packer->shouldStoreAlone(Packer::MIN_TARGET - 1));
		$this->assertTrue($packer->shouldStoreAlone(Packer::MIN_TARGET));
		$this->assertTrue($packer->shouldStoreAlone(Packer::MIN_TARGET * 2));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('belongs in its own object');

		$packer->add(Hash::of('big'), str_repeat('x', Packer::MIN_TARGET));
	}

	#[Test]
	#[TestDox('a frame with a malformed address is refused')]
	#[Group('strata/cas')]
	public function refusesMalformedAddress(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('valid content address');

		(new Packer())->add('not-a-hash', 'payload');
	}

	#[Test]
	#[TestDox('an empty frame is refused rather than packed as nothing')]
	#[Group('strata/cas')]
	public function refusesEmptyFrame(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('empty frame is not stored');

		(new Packer())->add(Hash::of(''), '');
	}

	#[Test]
	#[TestDox('discarding clears the buffer without producing a pack')]
	#[Group('strata/cas')]
	public function discardClearsBuffer(): void
	{
		$packer = new Packer();
		$packer->add(Hash::of('a'), 'payload a');
		$packer->discard();

		$this->assertSame(0, $packer->pending());
		$this->assertSame(0, $packer->buffered());
		$this->assertNull($packer->flush());
	}

	#endregion

	#region Flushing

	#[Test]
	#[TestDox('a flushed pack concatenates its frames and records where each one starts')]
	#[Group('strata/cas')]
	public function flushRecordsOffsets(): void
	{
		$packer = new Packer();
		$frames = ['first frame', 'second frame is longer', 'third'];
		$hashes = [];

		foreach ($frames as $frame) {
			$hash = Hash::of($frame);
			$hashes[] = $hash;
			$packer->add($hash, $frame);
		}

		$pack = $packer->flush();
		$this->assertNotNull($pack);

		$this->assertStringStartsWith(implode('', $frames), $pack['bytes']);
		$this->assertSame(Hash::of($pack['bytes']), $pack['id']);
		$this->assertCount(3, $pack['entries']);

		$offset = 0;
		foreach ($pack['entries'] as $i => $entry) {
			$this->assertSame($hashes[$i], $entry['hash']);
			$this->assertSame($offset, $entry['offset']);
			$this->assertSame(strlen($frames[$i]), $entry['length']);
			$this->assertSame(
				$frames[$i],
				Packer::extract($pack['bytes'], $entry['offset'], $entry['length']),
			);

			$offset += strlen($frames[$i]);
		}
	}

	#[Test]
	#[TestDox('flushing empties the buffer, so a second flush yields nothing')]
	#[Group('strata/cas')]
	public function flushEmptiesBuffer(): void
	{
		$packer = new Packer();
		$packer->add(Hash::of('a'), 'payload');

		$this->assertNotNull($packer->flush());
		$this->assertNull($packer->flush());
		$this->assertSame(0, $packer->pending());
	}

	#[Test]
	#[TestDox('identical content produces an identical pack id, so whole packs deduplicate')]
	#[Group('strata/cas')]
	public function identicalContentProducesIdenticalPackId(): void
	{
		$build = static function (): array {
			$packer = new Packer();
			foreach (['one', 'two', 'three'] as $frame) {
				$packer->add(Hash::of($frame), $frame);
			}

			return (array) $packer->flush();
		};

		$this->assertSame($build()['id'], $build()['id']);
	}

	#endregion

	#region Extraction

	#[Test]
	#[TestDox('extracting a range outside the pack raises instead of returning short bytes')]
	#[Group('strata/cas')]
	public function extractRefusesOutOfRange(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('does not fit in a 5 byte pack');

		Packer::extract('short', 3, 10);
	}

	/**
	 * @return array<string, array{int, int}>
	 */
	public static function badExtractProvider(): array
	{
		return [
			'negative offset' => [-1, 2],
			'zero length' => [0, 0],
			'negative length' => [0, -2],
			'runs past the end' => [8, 4],
		];
	}

	#[Test]
	#[TestDox('extracting with $_dataName raises')]
	#[Group('strata/cas')]
	#[DataProvider('badExtractProvider')]
	public function extractRefusesBadArguments(int $offset, int $length): void
	{
		$this->expectException(RuntimeException::class);

		Packer::extract('0123456789', $offset, $length);
	}

	#endregion
}
