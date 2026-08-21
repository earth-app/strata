<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Cas;

use Drupal\strata\Cas\FrameEnvelope;
use Drupal\strata\Cas\Hash;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(FrameEnvelope::class)]
class FrameEnvelopeTest extends TestCase
{
	#region Round Trip

	#[Test]
	#[TestDox('a wrapped frame reports back the codec, cipher and length it was written with')]
	#[Group('strata/cas')]
	public function roundTrip(): void
	{
		$parsed = FrameEnvelope::parse(
			'frames/x',
			FrameEnvelope::wrap('zstd', 'xchacha20poly1305', null, 16384, null, 0, 'sealed body'),
		);

		$this->assertSame('zstd', $parsed['codec']);
		$this->assertSame('xchacha20poly1305', $parsed['cipher']);
		$this->assertNull($parsed['dictionary']);
		$this->assertSame(16384, $parsed['raw']);
		$this->assertNull($parsed['parent']);
		$this->assertSame(0, $parsed['depth']);
		$this->assertSame('sealed body', $parsed['body']);
	}

	#[Test]
	#[TestDox('a dictionary and a delta parent survive the round trip')]
	#[Group('strata/cas')]
	public function carriesDictionaryAndParent(): void
	{
		$parent = Hash::of('previous version');

		$parsed = FrameEnvelope::parse(
			'frames/x',
			FrameEnvelope::wrap('zstd', 'none', 'entity/7', 900, $parent, 3, 'body'),
		);

		$this->assertSame('entity/7', $parsed['dictionary']);
		$this->assertSame($parent, $parsed['parent']);
		$this->assertSame(3, $parsed['depth']);
	}

	#[Test]
	#[TestDox('a body containing newlines is not split by the header parser')]
	#[Group('strata/cas')]
	public function bodyWithNewlinesSurvives(): void
	{
		$body = "line\nline\nline\n";

		$parsed = FrameEnvelope::parse(
			'frames/x',
			FrameEnvelope::wrap('none', 'none', null, strlen($body), null, 0, $body),
		);

		$this->assertSame($body, $parsed['body']);
	}

	#[Test]
	#[TestDox('an empty body is readable, since a codec may compress nothing to nothing')]
	#[Group('strata/cas')]
	public function emptyBody(): void
	{
		$parsed = FrameEnvelope::parse(
			'frames/x',
			FrameEnvelope::wrap('none', 'none', null, 0, null, 0, ''),
		);

		$this->assertSame('', $parsed['body']);
		$this->assertSame(0, $parsed['raw']);
	}

	#endregion

	#region Corruption

	#[Test]
	#[TestDox('an object with too few header lines is reported as truncated')]
	#[Group('strata/cas')]
	public function refusesTruncatedHeader(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no readable header');

		FrameEnvelope::parse('frames/x', "STRATA-FRM-1\nzstd\nnone\n");
	}

	#[Test]
	#[TestDox('an object from another format is named rather than parsed')]
	#[Group('strata/cas')]
	public function refusesForeignMagic(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('STRATA-FRM-1');

		FrameEnvelope::parse('frames/x', "STRATA-FRM-9\nzstd\nnone\n-\n10\n-\nbody");
	}

	#[Test]
	#[TestDox('a header with no decoded length is refused')]
	#[Group('strata/cas')]
	public function refusesMissingLength(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('does not record its decoded length');

		FrameEnvelope::parse('frames/x', "STRATA-FRM-1\nzstd\nnone\n-\nmany\n-\nbody");
	}

	#[Test]
	#[TestDox('a delta parent with no chain depth is refused')]
	#[Group('strata/cas')]
	public function refusesParentWithoutDepth(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no chain depth');

		FrameEnvelope::parse(
			'frames/x',
			"STRATA-FRM-1\nzstd\nnone\n-\n10\n" . Hash::of('p') . "\nbody",
		);
	}

	#[Test]
	#[TestDox('a delta parent that is not a digest is refused')]
	#[Group('strata/cas')]
	public function refusesMalformedParent(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('not a valid digest');

		FrameEnvelope::parse('frames/x', "STRATA-FRM-1\nzstd\nnone\n-\n10\nnope:2\nbody");
	}

	#endregion
}
