<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Cas;

use Drupal\strata\Cas\Framer;
use Drupal\strata\Cas\Hash;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Framer::class)]
class FramerTest extends TestCase
{
	#region Construction

	/**
	 * @return array<string, array{int}>
	 */
	public static function badSizeProvider(): array
	{
		return [
			'zero' => [0],
			'negative' => [-1],
			'below minimum' => [Framer::MIN_SIZE - 1],
			'above maximum' => [Framer::MAX_SIZE + 1],
		];
	}

	#[Test]
	#[TestDox('a frame size of $_dataName is refused')]
	#[Group('strata/cas')]
	#[DataProvider('badSizeProvider')]
	public function refusesBadSize(int $size): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Frame size must be between');

		new Framer($size);
	}

	#[Test]
	#[TestDox('the boundary sizes are accepted')]
	#[Group('strata/cas')]
	public function acceptsBoundarySizes(): void
	{
		$this->assertSame(Framer::MIN_SIZE, (new Framer(Framer::MIN_SIZE))->size());
		$this->assertSame(Framer::MAX_SIZE, (new Framer(Framer::MAX_SIZE))->size());
		$this->assertSame(Framer::DEFAULT_SIZE, (new Framer())->size());
	}

	#endregion

	#region Splitting

	#[Test]
	#[TestDox('an empty payload yields no frames at all')]
	#[Group('strata/cas')]
	public function emptyPayloadYieldsNothing(): void
	{
		$framer = new Framer(Framer::MIN_SIZE);

		$this->assertSame([], iterator_to_array($framer->split('')));
		$this->assertSame([], $framer->map(''));
		$this->assertSame(0, $framer->count(0));
	}

	#[Test]
	#[TestDox('a payload shorter than one frame yields one short frame')]
	#[Group('strata/cas')]
	public function shortPayloadYieldsOneFrame(): void
	{
		$framer = new Framer(Framer::MIN_SIZE);
		$frames = iterator_to_array($framer->split('strata'));

		$this->assertCount(1, $frames);
		$this->assertSame('strata', $frames[0]);
		$this->assertSame(1, $framer->count(6));
	}

	#[Test]
	#[TestDox('the last frame is short rather than padded, so appending never rewrites a frame')]
	#[Group('strata/cas')]
	public function lastFrameIsShortNotPadded(): void
	{
		$framer = new Framer(Framer::MIN_SIZE);
		$payload = str_repeat('x', Framer::MIN_SIZE * 2 + 7);
		$frames = iterator_to_array($framer->split($payload));

		$this->assertCount(3, $frames);
		$this->assertSame(Framer::MIN_SIZE, strlen($frames[0]));
		$this->assertSame(Framer::MIN_SIZE, strlen($frames[1]));
		$this->assertSame(7, strlen($frames[2]));

		// appending must leave the leading frames byte-identical
		$grown = iterator_to_array($framer->split($payload . 'more'));
		$this->assertSame($frames[0], $grown[0]);
		$this->assertSame($frames[1], $grown[1]);
	}

	#[Test]
	#[TestDox('an exact multiple of the frame size produces no trailing empty frame')]
	#[Group('strata/cas')]
	public function exactMultipleHasNoTrailingFrame(): void
	{
		$framer = new Framer(Framer::MIN_SIZE);
		$frames = iterator_to_array($framer->split(str_repeat('y', Framer::MIN_SIZE * 3)));

		$this->assertCount(3, $frames);
		$this->assertSame(3, $framer->count(Framer::MIN_SIZE * 3));
	}

	/**
	 * @return array<string, array{int, int, int}>
	 */
	public static function countProvider(): array
	{
		return [
			'empty' => [1024, 0, 0],
			'one byte' => [1024, 1, 1],
			'one short of a frame' => [1024, 1023, 1],
			'exactly one frame' => [1024, 1024, 1],
			'one over a frame' => [1024, 1025, 2],
			'many frames' => [1024, 1024 * 9 + 1, 10],
		];
	}

	#[Test]
	#[TestDox('count() is exact for $_dataName')]
	#[Group('strata/cas')]
	#[DataProvider('countProvider')]
	public function countIsExact(int $size, int $length, int $expected): void
	{
		$this->assertSame($expected, (new Framer($size))->count($length));
	}

	#[Test]
	#[TestDox('count() refuses a negative length')]
	#[Group('strata/cas')]
	public function countRefusesNegativeLength(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot be negative');

		(new Framer())->count(-1);
	}

	#[Test]
	#[TestDox('splitting then concatenating is the identity')]
	#[Group('strata/cas')]
	public function splitIsLossless(): void
	{
		$framer = new Framer(Framer::MIN_SIZE);
		$payload = random_bytes(Framer::MIN_SIZE * 5 + 137);

		$this->assertSame($payload, implode('', iterator_to_array($framer->split($payload))));
	}

	#endregion

	#region Streams

	#[Test]
	#[TestDox('splitStream() produces the same frames as split()')]
	#[Group('strata/cas')]
	public function splitStreamMatchesSplit(): void
	{
		$framer = new Framer(Framer::MIN_SIZE);
		$payload = random_bytes(Framer::MIN_SIZE * 4 + 91);

		$stream = fopen('php://temp', 'r+b');
		fwrite($stream, $payload);
		rewind($stream);

		$this->assertSame(
			iterator_to_array($framer->split($payload)),
			iterator_to_array($framer->splitStream($stream)),
		);

		fclose($stream);
	}

	#[Test]
	#[TestDox('splitStream() on an empty stream yields nothing')]
	#[Group('strata/cas')]
	public function splitStreamOnEmptyStream(): void
	{
		$stream = fopen('php://temp', 'r+b');

		$this->assertSame([], iterator_to_array((new Framer())->splitStream($stream)));

		fclose($stream);
	}

	#[Test]
	#[
		TestDox(
			'splitStream() reassembles a short read into a full frame instead of ending it early',
		),
	]
	#[Group('strata/cas')]
	public function splitStreamAbsorbsShortReads(): void
	{
		$framer = new Framer(Framer::MIN_SIZE);
		$payload = str_repeat('z', Framer::MIN_SIZE * 2);

		// a pipe hands back short reads, which is the case a naive single fread() gets wrong
		$descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
		$process = proc_open('cat', $descriptors, $pipes);
		$this->assertIsResource($process);

		fwrite($pipes[0], $payload);
		fclose($pipes[0]);

		$frames = iterator_to_array($framer->splitStream($pipes[1]));

		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$this->assertCount(2, $frames);
		$this->assertSame(Framer::MIN_SIZE, strlen($frames[0]));
		$this->assertSame(Framer::MIN_SIZE, strlen($frames[1]));
	}

	#[Test]
	#[TestDox('splitStream() refuses a non-stream argument')]
	#[Group('strata/cas')]
	public function splitStreamRefusesNonStream(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('needs an open stream resource');

		/** @phpstan-ignore-next-line intentionally wrong type */
		iterator_to_array((new Framer())->splitStream('nope'));
	}

	#endregion

	#region Maps and reassembly

	#[Test]
	#[TestDox('frames() reports the digest, bytes and offset of each frame')]
	#[Group('strata/cas')]
	public function framesReportOffsets(): void
	{
		$framer = new Framer(Framer::MIN_SIZE);
		$payload = str_repeat('a', Framer::MIN_SIZE) . 'tail';
		$frames = iterator_to_array($framer->frames($payload));

		$this->assertCount(2, $frames);
		$this->assertSame(0, $frames[0]['offset']);
		$this->assertSame(Framer::MIN_SIZE, $frames[1]['offset']);
		$this->assertSame('tail', $frames[1]['bytes']);
		$this->assertSame(Hash::of('tail'), $frames[1]['hash']);
	}

	#[Test]
	#[TestDox('map() is the ordered digest list and repeats a digest for repeated content')]
	#[Group('strata/cas')]
	public function mapIsOrderedAndDedupable(): void
	{
		$framer = new Framer(Framer::MIN_SIZE);
		$block = str_repeat('q', Framer::MIN_SIZE);
		$map = $framer->map($block . $block . 'x');

		$this->assertCount(3, $map);
		$this->assertSame($map[0], $map[1]);
		$this->assertNotSame($map[0], $map[2]);
		$this->assertSame(Hash::of($block), $map[0]);
	}

	#[Test]
	#[TestDox('reassemble() rebuilds the exact payload from its map')]
	#[Group('strata/cas')]
	public function reassembleRebuildsPayload(): void
	{
		$framer = new Framer(Framer::MIN_SIZE);
		$payload = random_bytes(Framer::MIN_SIZE * 3 + 11);

		$store = [];
		foreach ($framer->frames($payload) as $frame) {
			$store[$frame['hash']] = $frame['bytes'];
		}

		$fetch = static fn(string $hash): ?string => $store[$hash] ?? null;

		$this->assertSame($payload, $framer->reassemble($framer->map($payload), $fetch));
	}

	#[Test]
	#[TestDox('reassemble() names the missing frame rather than returning a short payload')]
	#[Group('strata/cas')]
	public function reassembleNamesMissingFrame(): void
	{
		$framer = new Framer(Framer::MIN_SIZE);
		$payload = str_repeat('m', Framer::MIN_SIZE * 2);
		$map = $framer->map($payload);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Frame 0 (' . Hash::abbreviate($map[0]) . ') is missing');

		$framer->reassemble($map, static fn(string $hash): ?string => null);
	}

	#[Test]
	#[TestDox('reassemble() raises on a substituted frame instead of returning plausible content')]
	#[Group('strata/cas')]
	public function reassembleRaisesOnSubstitutedFrame(): void
	{
		$framer = new Framer(Framer::MIN_SIZE);
		$payload = str_repeat('n', Framer::MIN_SIZE * 2);
		$map = $framer->map($payload);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('does not match its digest');

		$framer->reassemble($map, static fn(string $hash): ?string => 'tampered');
	}

	#[Test]
	#[TestDox('reassemble() of an empty map is an empty payload')]
	#[Group('strata/cas')]
	public function reassembleOfEmptyMap(): void
	{
		$this->assertSame('', (new Framer())->reassemble([], static fn(): ?string => null));
	}

	#endregion
}
