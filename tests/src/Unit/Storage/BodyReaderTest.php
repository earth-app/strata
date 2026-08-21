<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Storage;

use Drupal\strata\Storage\BodyReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Proves a body is cut into the pieces every remote provider expects.
 *
 * Three providers depend on the two properties asserted here: every piece but the last is exactly
 * one part long, and a body ending on a boundary yields no empty piece after it. An empty final
 * piece is a request that S3 answers with an error and that Azure commits as a zero-length block.
 */
#[CoversClass(BodyReader::class)]
class BodyReaderTest extends TestCase
{
	/**
	 * A stream holding one payload, positioned at the start.
	 *
	 * @param string $payload
	 *   The bytes.
	 *
	 * @return resource
	 *   An open readable stream.
	 */
	private function streamOf(string $payload)
	{
		$handle = fopen('php://temp', 'r+b');

		$this->assertIsResource($handle);
		fwrite($handle, $payload);
		rewind($handle);

		return $handle;
	}

	/**
	 * A stream that cannot report its own size, as a pipe or a socket cannot.
	 *
	 * @param string $payload
	 *   The bytes.
	 *
	 * @return resource
	 *   An open readable stream.
	 */
	private function unsizableStream(string $payload)
	{
		$path = sys_get_temp_dir() . '/strata-body-' . bin2hex(random_bytes(8)) . '.gz';
		file_put_contents($path, (string) gzencode($payload, 1));

		$handle = fopen('compress.zlib://' . $path, 'rb');

		$this->assertIsResource($handle);
		$this->assertFalse(@fstat($handle), 'the fixture stream has to be unsizable');
		@unlink($path);

		return $handle;
	}

	#[Test]
	#[TestDox('a string is cut into uniform pieces with a short one at the end')]
	#[Group('strata/storage')]
	public function stringsAreCutIntoUniformPieces(): void
	{
		$pieces = iterator_to_array(BodyReader::sliceString(str_repeat('x', 25), 10));

		$this->assertSame([10, 10, 5], array_map('strlen', $pieces));
		$this->assertSame(str_repeat('x', 25), implode('', $pieces));
	}

	#[Test]
	#[TestDox('a string ending on a boundary yields no empty final piece')]
	#[Group('strata/storage')]
	public function stringEndingOnABoundaryYieldsNoEmptyPiece(): void
	{
		$this->assertSame(
			[10, 10],
			array_map(
				'strlen',
				iterator_to_array(BodyReader::sliceString(str_repeat('x', 20), 10)),
			),
		);
	}

	#[Test]
	#[TestDox('a stream is cut into the same pieces a string of the same bytes would be')]
	#[Group('strata/storage')]
	public function streamsAreCutLikeStrings(): void
	{
		$payload = str_repeat('strata', 7);
		$stream = $this->unsizableStream($payload);
		$first = BodyReader::readExactly($stream, 10, 'frames/thing');
		$pieces = iterator_to_array(BodyReader::sliceStream($stream, 10, $first, 'frames/thing'));

		fclose($stream);

		$this->assertSame($payload, implode('', $pieces));
		$this->assertSame(
			array_map('strlen', iterator_to_array(BodyReader::sliceString($payload, 10))),
			array_map('strlen', $pieces),
		);
	}

	#[Test]
	#[TestDox('a stream ending on a boundary yields no empty final piece')]
	#[Group('strata/storage')]
	public function streamEndingOnABoundaryYieldsNoEmptyPiece(): void
	{
		$payload = str_repeat('x', 20);
		$stream = $this->unsizableStream($payload);
		$first = BodyReader::readExactly($stream, 10, 'frames/thing');
		$pieces = iterator_to_array(BodyReader::sliceStream($stream, 10, $first, 'frames/thing'));

		fclose($stream);

		$this->assertSame([10, 10], array_map('strlen', $pieces));
	}

	#[Test]
	#[TestDox('reading a fixed number of bytes stops short only at the end of the stream')]
	#[Group('strata/storage')]
	public function readExactlyStopsOnlyAtTheEnd(): void
	{
		$stream = $this->unsizableStream('0123456789');

		$this->assertSame('01234', BodyReader::readExactly($stream, 5, 'k'));
		$this->assertSame('56789', BodyReader::readExactly($stream, 8, 'k'));
		$this->assertSame('', BodyReader::readExactly($stream, 8, 'k'));

		fclose($stream);
	}

	#[Test]
	#[TestDox('reading to the end returns everything left from the current position')]
	#[Group('strata/storage')]
	public function readAllReturnsTheRest(): void
	{
		$stream = $this->streamOf('0123456789');
		fseek($stream, 4);

		$this->assertSame('456789', BodyReader::readAll($stream, 'k'));

		fclose($stream);
	}

	#[Test]
	#[TestDox('a sizable stream reports what is left and an unsizable one reports nothing')]
	#[Group('strata/storage')]
	public function sizeIsReportedOnlyWhereItIsKnown(): void
	{
		$sizable = $this->streamOf('0123456789');
		fseek($sizable, 4);

		$this->assertSame(6, BodyReader::sizeOf($sizable));

		fclose($sizable);

		$unsizable = $this->unsizableStream('0123456789');

		$this->assertNull(BodyReader::sizeOf($unsizable));

		fclose($unsizable);
	}

	#[Test]
	#[TestDox('an empty stream reports no size rather than zero, which reads as unknown')]
	#[Group('strata/storage')]
	public function anEmptyStreamReportsNoSize(): void
	{
		$stream = $this->streamOf('');

		$this->assertNull(BodyReader::sizeOf($stream));

		fclose($stream);
	}
}
