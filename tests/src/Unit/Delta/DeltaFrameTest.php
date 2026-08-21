<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Delta;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Delta\DeltaFrame;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeltaFrame::class)]
class DeltaFrameTest extends TestCase
{
	#region Invariants

	#[Test]
	#[TestDox('an anchor has no parent, depth zero, and reports itself as an anchor')]
	#[Group('strata/delta')]
	public function anchorIsCoherent(): void
	{
		$frame = new DeltaFrame(DeltaFrame::MODE_ANCHOR, 'zstd', 'bytes', null, 0, 100);

		$this->assertTrue($frame->isAnchor());
		$this->assertNull($frame->parent);
		$this->assertSame(0, $frame->depth);
		$this->assertSame(5, $frame->length());
	}

	#[Test]
	#[TestDox('a delta carries a valid parent digest and a depth of at least one')]
	#[Group('strata/delta')]
	public function deltaIsCoherent(): void
	{
		$parent = Hash::of('previous');
		$frame = new DeltaFrame(DeltaFrame::MODE_DELTA, 'zstd', 'bytes', $parent, 1, 100);

		$this->assertFalse($frame->isAnchor());
		$this->assertSame($parent, $frame->parent);
		$this->assertSame(1, $frame->depth);
	}

	/**
	 * @return array<string, array{array<int, mixed>, string}>
	 */
	public static function incoherentFrameProvider(): array
	{
		$parent = Hash::of('previous');

		return [
			'unknown mode' => [['nonsense', 'zstd', 'b', null, 0, 0], 'Unknown delta frame mode'],
			'empty codec' => [
				[DeltaFrame::MODE_ANCHOR, '', 'b', null, 0, 0],
				'must record the codec',
			],
			'negative depth' => [
				[DeltaFrame::MODE_ANCHOR, 'zstd', 'b', null, -1, 0],
				'depth cannot be negative',
			],
			'negative plain length' => [
				[DeltaFrame::MODE_ANCHOR, 'zstd', 'b', null, 0, -1],
				'plain length cannot be negative',
			],
			'anchor with a parent' => [
				[DeltaFrame::MODE_ANCHOR, 'zstd', 'b', $parent, 0, 0],
				'An anchor frame cannot have a parent',
			],
			'anchor at depth' => [
				[DeltaFrame::MODE_ANCHOR, 'zstd', 'b', null, 2, 0],
				'An anchor frame is always at depth zero',
			],
			'delta with no parent' => [
				[DeltaFrame::MODE_DELTA, 'zstd', 'b', null, 1, 0],
				'A delta frame must name its parent',
			],
			'delta with a bad parent' => [
				[DeltaFrame::MODE_DELTA, 'zstd', 'b', 'not-a-digest', 1, 0],
				'parent must be a valid digest',
			],
			'delta at depth zero' => [
				[DeltaFrame::MODE_DELTA, 'zstd', 'b', $parent, 0, 0],
				'always at depth one or more',
			],
		];
	}

	#[Test]
	#[TestDox('$_dataName is refused at construction rather than stored')]
	#[Group('strata/delta')]
	#[DataProvider('incoherentFrameProvider')]
	public function incoherentFramesAreRefused(array $arguments, string $message): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		new DeltaFrame(...$arguments);
	}

	#endregion

	#region Measurement

	#[Test]
	#[TestDox('ratio() reports plain over encoded size')]
	#[Group('strata/delta')]
	public function ratioReportsCompression(): void
	{
		$frame = new DeltaFrame(DeltaFrame::MODE_ANCHOR, 'zstd', str_repeat('x', 10), null, 0, 100);

		$this->assertSame(10.0, $frame->ratio());
	}

	#[Test]
	#[TestDox('ratio() is 1.0 when either side is zero, rather than dividing by zero')]
	#[Group('strata/delta')]
	public function ratioHandlesZero(): void
	{
		$this->assertSame(
			1.0,
			(new DeltaFrame(DeltaFrame::MODE_ANCHOR, 'z', '', null, 0, 0))->ratio(),
		);
		$this->assertSame(
			1.0,
			(new DeltaFrame(DeltaFrame::MODE_ANCHOR, 'z', 'ab', null, 0, 0))->ratio(),
		);
		$this->assertSame(
			1.0,
			(new DeltaFrame(DeltaFrame::MODE_ANCHOR, 'z', '', null, 0, 9))->ratio(),
		);
	}

	#endregion

	#region Serialization

	#[Test]
	#[TestDox('fromArray(jsonSerialize()) is the identity for an anchor')]
	#[Group('strata/delta')]
	public function anchorRoundTripsThroughJson(): void
	{
		$frame = new DeltaFrame(DeltaFrame::MODE_ANCHOR, 'zstd', random_bytes(64), null, 0, 999);
		$rebuilt = DeltaFrame::fromArray($frame->jsonSerialize());

		$this->assertEquals($frame, $rebuilt);
	}

	#[Test]
	#[TestDox('fromArray(jsonSerialize()) is the identity for a delta')]
	#[Group('strata/delta')]
	public function deltaRoundTripsThroughJson(): void
	{
		$frame = new DeltaFrame(
			DeltaFrame::MODE_DELTA,
			'zstd',
			random_bytes(64),
			Hash::of('previous'),
			7,
			999,
		);
		$rebuilt = DeltaFrame::fromArray($frame->jsonSerialize());

		$this->assertEquals($frame, $rebuilt);
	}

	#[Test]
	#[TestDox('binary bytes survive a real json_encode round trip')]
	#[Group('strata/delta')]
	public function binaryBytesSurviveJsonEncode(): void
	{
		$bytes = "\0\1\2\xff\xfe" . random_bytes(256);
		$frame = new DeltaFrame(DeltaFrame::MODE_ANCHOR, 'zstd', $bytes, null, 0, 12);

		$encoded = json_encode($frame);
		$this->assertIsString($encoded);

		$rebuilt = DeltaFrame::fromArray((array) json_decode($encoded, true));
		$this->assertSame($bytes, $rebuilt->bytes);
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function badArrayProvider(): array
	{
		return [
			'missing mode' => [['codec' => 'zstd', 'bytes' => ''], 'missing "mode"'],
			'missing codec' => [['mode' => 'anchor', 'bytes' => ''], 'missing "codec"'],
			'missing bytes' => [['mode' => 'anchor', 'codec' => 'zstd'], 'missing "bytes"'],
			'bad base64' => [
				['mode' => 'anchor', 'codec' => 'zstd', 'bytes' => '!!!not base64!!!'],
				'not valid base64',
			],
		];
	}

	#[Test]
	#[TestDox('fromArray() refuses $_dataName')]
	#[Group('strata/delta')]
	#[DataProvider('badArrayProvider')]
	public function fromArrayRefusesMalformed(array $data, string $message): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		DeltaFrame::fromArray($data);
	}

	#endregion
}
