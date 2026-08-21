<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Cas;

use Drupal\strata\Cas\Chunker\FastCdcChunker;
use Drupal\strata\Cas\Framer;
use Drupal\strata\Cas\Hash;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(FastCdcChunker::class)]
class ChunkerTest extends TestCase
{
	/**
	 * Chunk target used throughout, small enough to keep the fixtures cheap.
	 */
	private const TARGET = 2048;

	/**
	 * Content with enough variety for the rolling hash to find boundaries in it.
	 *
	 * Deterministic rather than random, so a boundary assertion means the same thing on every run.
	 */
	private function content(int $length, int $seed = 1): string
	{
		$out = '';
		$state = $seed;

		while (strlen($out) < $length) {
			$state = ($state * 1103515245 + 12345) & 0x7fffffff;
			$out .= pack('N', $state);
		}

		return substr($out, 0, $length);
	}

	/**
	 * A stream over a string.
	 *
	 * @return resource
	 *   The stream, positioned at the start.
	 */
	private function stream(string $payload)
	{
		$handle = fopen('php://memory', 'r+');

		if ($handle === false) {
			$this->fail('could not open a memory stream');
		}

		fwrite($handle, $payload);
		rewind($handle);

		return $handle;
	}

	/**
	 * Every chunk of a payload.
	 *
	 * @return list<array{offset: int, bytes: string}>
	 *   The chunks in order.
	 */
	private function chunks(string $payload, int $budget = 0, int $target = self::TARGET): array
	{
		$stream = $this->stream($payload);
		$chunks = [];

		foreach ((new FastCdcChunker($target))->chunk($stream, $budget) as $chunk) {
			$chunks[] = $chunk;
		}

		fclose($stream);

		return $chunks;
	}

	/**
	 * The digest of every chunk of a payload.
	 *
	 * @return list<string>
	 *   The digests in order.
	 */
	private function digests(string $payload): array
	{
		return array_map(
			static fn(array $chunk): string => Hash::of($chunk['bytes']),
			$this->chunks($payload),
		);
	}

	#region Construction

	/**
	 * @return array<string, array{int}>
	 */
	public static function badTargetProvider(): array
	{
		return [
			'zero' => [0],
			'negative' => [-1],
			'below one kibibyte' => [1023],
			'above four mebibytes' => [4_194_305],
		];
	}

	#[Test]
	#[TestDox('a chunk target of $_dataName is refused')]
	#[Group('strata/cas')]
	#[DataProvider('badTargetProvider')]
	public function refusesBadTarget(int $target): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A chunk target must be between');

		new FastCdcChunker($target);
	}

	#[Test]
	#[TestDox('the measured throughput is part of the interface, so a switch can print it')]
	#[Group('strata/cas')]
	public function throughputIsReported(): void
	{
		$chunker = new FastCdcChunker();

		$this->assertSame('fastcdc', $chunker->id());
		$this->assertSame(FastCdcChunker::MEASURED_THROUGHPUT, $chunker->throughput());

		// the number an administrator is shown: two orders of magnitude under fixed framing
		$this->assertLessThan(10_000_000, $chunker->throughput());
	}

	#[Test]
	#[TestDox('the size bounds come from the target rather than being configured separately')]
	#[Group('strata/cas')]
	public function boundsFollowTheTarget(): void
	{
		$chunker = new FastCdcChunker(self::TARGET);

		$this->assertSame((int) (self::TARGET * FastCdcChunker::MIN_FRACTION), $chunker->minimum());
		$this->assertSame(self::TARGET * FastCdcChunker::MAX_MULTIPLE, $chunker->maximum());
		$this->assertGreaterThan($chunker->minimum(), $chunker->maximum());
	}

	#endregion

	#region Splitting

	#[Test]
	#[TestDox('an empty stream yields nothing rather than one empty chunk')]
	#[Group('strata/cas')]
	public function emptyStreamYieldsNothing(): void
	{
		$this->assertSame([], $this->chunks(''));
	}

	#[Test]
	#[TestDox('the chunks concatenate back to the input, in order and with no gaps')]
	#[Group('strata/cas')]
	public function chunksReassemble(): void
	{
		$payload = $this->content(200_000);
		$chunks = $this->chunks($payload);
		$rebuilt = '';
		$offset = 0;

		foreach ($chunks as $chunk) {
			$this->assertSame($offset, $chunk['offset'], 'the offset follows the previous chunk');

			$rebuilt .= $chunk['bytes'];
			$offset += strlen($chunk['bytes']);
		}

		$this->assertGreaterThan(10, count($chunks), 'the content produced boundaries');
		$this->assertSame($payload, $rebuilt);
	}

	#[Test]
	#[TestDox('every chunk but the last sits inside the configured size bounds')]
	#[Group('strata/cas')]
	public function chunksStayWithinBounds(): void
	{
		$chunker = new FastCdcChunker(self::TARGET);
		$chunks = $this->chunks($this->content(200_000));
		$last = array_pop($chunks);

		foreach ($chunks as $chunk) {
			$length = strlen($chunk['bytes']);

			$this->assertGreaterThanOrEqual($chunker->minimum(), $length);
			$this->assertLessThanOrEqual($chunker->maximum(), $length);
		}

		$this->assertNotNull($last);
		$this->assertLessThanOrEqual($chunker->maximum(), strlen((string) $last['bytes']));
	}

	#[Test]
	#[TestDox('a payload shorter than the minimum is one chunk')]
	#[Group('strata/cas')]
	public function shortPayloadIsOneChunk(): void
	{
		$payload = $this->content(100);
		$chunks = $this->chunks($payload);

		$this->assertCount(1, $chunks);
		$this->assertSame($payload, $chunks[0]['bytes']);
	}

	#[Test]
	#[TestDox('content with no boundary in it is cut at the maximum rather than held forever')]
	#[Group('strata/cas')]
	public function incompressibleRunIsCutAtTheMaximum(): void
	{
		$chunker = new FastCdcChunker(self::TARGET);
		$chunks = $this->chunks(str_repeat("\0", $chunker->maximum() * 3));

		$this->assertGreaterThanOrEqual(3, count($chunks));
		$this->assertSame($chunker->maximum(), strlen($chunks[0]['bytes']));
	}

	#[Test]
	#[TestDox('the same content splits identically, so a chunk has one identity everywhere')]
	#[Group('strata/cas')]
	public function boundariesAreDeterministic(): void
	{
		$payload = $this->content(120_000);

		$this->assertSame($this->digests($payload), $this->digests($payload));
	}

	#[Test]
	#[TestDox('a budget stops the read rather than the file')]
	#[Group('strata/cas')]
	public function budgetBoundsOneRun(): void
	{
		$payload = $this->content(200_000);
		$bounded = $this->chunks($payload, 40_000);
		$read = 0;

		foreach ($bounded as $chunk) {
			$read += strlen($chunk['bytes']);
		}

		$this->assertGreaterThan(0, $read);
		$this->assertLessThanOrEqual(40_000, $read);
		$this->assertSame(substr($payload, 0, $read), implode('', array_column($bounded, 'bytes')));
	}

	#endregion

	#region Why It Exists

	#[Test]
	#[TestDox('an insertion shifts one chunk, which is the only thing fixed framing cannot do')]
	#[Group('strata/cas')]
	public function insertionShiftsOneChunk(): void
	{
		$payload = $this->content(200_000);
		$shifted = substr($payload, 0, 50_000) . $this->content(64, 9) . substr($payload, 50_000);

		$before = $this->digests($payload);
		$after = $this->digests($shifted);
		$shared = count(array_intersect($before, $after));

		$framer = new Framer(Framer::MIN_SIZE);
		$fixedBefore = array_map([Hash::class, 'of'], iterator_to_array($framer->split($payload)));
		$fixedAfter = array_map([Hash::class, 'of'], iterator_to_array($framer->split($shifted)));
		$fixedShared = count(array_intersect($fixedBefore, $fixedAfter));

		// the measured claim: content-defined boundaries survive an insertion, fixed offsets do not
		$this->assertGreaterThan(0.8 * count($before), $shared, 'most chunks are unchanged');
		$this->assertLessThan(0.5 * count($fixedBefore), $fixedShared, 'fixed frames mostly moved');
	}

	#[Test]
	#[TestDox('an in-place edit changes only the chunks it touched')]
	#[Group('strata/cas')]
	public function inPlaceEditIsLocal(): void
	{
		$payload = $this->content(200_000);
		$edited = substr_replace($payload, $this->content(64, 7), 120_000, 64);

		$before = $this->digests($payload);
		$after = $this->digests($edited);

		$this->assertSame(strlen($payload), strlen($edited));
		$this->assertGreaterThan(0.8 * count($before), count(array_intersect($before, $after)));
	}

	#endregion
}
