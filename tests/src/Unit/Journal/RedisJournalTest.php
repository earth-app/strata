<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Journal;

use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\RedisJournal;
use Drupal\strata\Journal\Verb;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RedisJournal::class)]
class RedisJournalTest extends TestCase
{
	private function operation(string $subject, string $label = 'test'): JournalOp
	{
		return new JournalOp(
			0,
			1_700_000_000_000_000,
			Realm::ENTITY,
			$subject,
			Verb::UPDATE,
			null,
			null,
			null,
			null,
			0,
			$label,
			[],
		);
	}

	private function journal(FakeRedis $redis): RedisJournal
	{
		return new RedisJournal($redis, 'strata:test');
	}

	#region Construction

	#[Test]
	#[TestDox('a client with no stream commands at all is refused, naming what it is missing')]
	#[Group('strata/journal')]
	public function clientWithoutStreamsIsRefused(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('does not support xAdd');

		new RedisJournal(new class {});
	}

	#[Test]
	#[TestDox('a client missing one command is refused at construction, not at the first capture')]
	#[Group('strata/journal')]
	public function clientMissingOneCommandIsRefused(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('does not support xDel');

		// an older client, or Predis, which has the reads and not the stream delete
		new RedisJournal(
			new class {
				public function xAdd(): string
				{
					return '';
				}

				public function xRange(): array
				{
					return [];
				}

				public function xLen(): int
				{
					return 0;
				}

				public function del(): int
				{
					return 0;
				}
			},
		);
	}

	#[Test]
	#[TestDox('whether the host can back a journal with redis is reported rather than assumed')]
	#[Group('strata/journal')]
	public function supportIsReported(): void
	{
		$this->assertSame(extension_loaded('redis'), RedisJournal::isSupported());
	}

	#endregion

	#region Sequences

	/**
	 * @return array<string, array{string, int}>
	 */
	public static function idProvider(): array
	{
		return [
			'the first entry of a millisecond' => ['1700000000000-0', 1_700_000_000_000_000],
			'the second entry of the same millisecond' => [
				'1700000000000-1',
				1_700_000_000_000_001,
			],
			'the next millisecond' => ['1700000000001-0', 1_700_000_000_001_000],
			'an id with no counter' => ['1700000000000', 1_700_000_000_000_000],
		];
	}

	#[Test]
	#[TestDox('$_dataName maps to a sequence that keeps the order redis assigned')]
	#[Group('strata/journal')]
	#[DataProvider('idProvider')]
	public function idsMapToSequences(string $id, int $expected): void
	{
		$this->assertSame($expected, RedisJournal::sequenceOf($id));
	}

	#[Test]
	#[TestDox('two appends in one millisecond still order, which a plain timestamp would not')]
	#[Group('strata/journal')]
	public function tiesInsideAMillisecondStillOrder(): void
	{
		$redis = new FakeRedis();
		$journal = $this->journal($redis);

		$first = $journal->append($this->operation('user:1'));
		$second = $journal->append($this->operation('user:2'));

		$this->assertLessThan($second->sequence, $first->sequence);
		$this->assertSame(1, $second->sequence - $first->sequence);
	}

	#endregion

	#region The Contract

	#[Test]
	#[TestDox('an appended operation comes back with its payload and its assigned sequence')]
	#[Group('strata/journal')]
	public function appendRoundTrips(): void
	{
		$redis = new FakeRedis();
		$journal = $this->journal($redis);

		$appended = $journal->append($this->operation('user:7', 'renamed'), 'the payload');
		$window = $journal->read();

		$this->assertGreaterThan(0, $appended->sequence);
		$this->assertCount(1, $window);
		$this->assertSame('user:7', $window[0]['operation']->subject);
		$this->assertSame('renamed', $window[0]['operation']->label);
		$this->assertSame(Realm::ENTITY, $window[0]['operation']->realm);
		$this->assertSame($appended->sequence, $window[0]['operation']->sequence);
		$this->assertSame('the payload', $window[0]['payload']);
	}

	#[Test]
	#[TestDox('an operation with no payload reads back as none rather than as an empty string')]
	#[Group('strata/journal')]
	public function absentPayloadStaysAbsent(): void
	{
		$journal = $this->journal(new FakeRedis());
		$journal->append($this->operation('user:1'));

		$this->assertNull($journal->read()[0]['payload']);
	}

	#[Test]
	#[TestDox('a window comes back oldest first and is bounded by the limit')]
	#[Group('strata/journal')]
	public function windowIsOrderedAndBounded(): void
	{
		$redis = new FakeRedis();
		$journal = $this->journal($redis);

		for ($i = 0; $i < 10; $i++) {
			$redis->tick();
			$journal->append($this->operation('user:' . $i));
		}

		$window = $journal->read(4);
		$subjects = array_map(
			static fn(array $entry): string => $entry['operation']->subject,
			$window,
		);

		$this->assertSame(['user:0', 'user:1', 'user:2', 'user:3'], $subjects);
		$this->assertSame([], $journal->read(0), 'a limit of zero reads nothing');
	}

	#[Test]
	#[TestDox('a trim removes everything through a sequence and leaves what follows')]
	#[Group('strata/journal')]
	public function trimRemovesThroughTheSequence(): void
	{
		$redis = new FakeRedis();
		$journal = $this->journal($redis);
		$sequences = [];

		for ($i = 0; $i < 5; $i++) {
			$redis->tick();
			$sequences[] = $journal->append($this->operation('user:' . $i))->sequence;
		}

		$this->assertSame(3, $journal->trim($sequences[2]));
		$this->assertSame(2, $journal->pending());
		$this->assertSame('user:3', $journal->read()[0]['operation']->subject);
		$this->assertSame(0, $journal->trim(0), 'nothing at or below zero is left to remove');
	}

	#[Test]
	#[TestDox('the pending count and byte total describe what is actually waiting')]
	#[Group('strata/journal')]
	public function pendingIsMeasured(): void
	{
		$redis = new FakeRedis();
		$journal = $this->journal($redis);

		$this->assertSame(0, $journal->pending());
		$this->assertSame(0, $journal->pendingBytes());
		$this->assertNull($journal->oldest());

		$redis->tick();
		$journal->append($this->operation('user:1'), 'twelve bytes');
		$redis->tick();
		$journal->append($this->operation('user:2'), 'four');

		$this->assertSame(2, $journal->pending());
		$this->assertSame(16, $journal->pendingBytes());
		$this->assertSame(1_700_000_000_000_000, $journal->oldest());
	}

	#[Test]
	#[TestDox('clearing reports how many operations it dropped')]
	#[Group('strata/journal')]
	public function clearReportsWhatItDropped(): void
	{
		$redis = new FakeRedis();
		$journal = $this->journal($redis);

		$redis->tick();
		$journal->append($this->operation('user:1'));
		$redis->tick();
		$journal->append($this->operation('user:2'));

		$this->assertSame(2, $journal->clear());
		$this->assertSame(0, $journal->pending());
		$this->assertSame([], $journal->read());
	}

	#endregion

	#region Failure

	#[Test]
	#[TestDox('an append that redis refuses is reported, never silently dropped')]
	#[Group('strata/journal')]
	public function refusedAppendIsReported(): void
	{
		$redis = new FakeRedis();
		$redis->failing = ['xAdd'];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot append');

		$this->journal($redis)->append($this->operation('user:1'));
	}

	#[Test]
	#[TestDox('a read that redis refuses is reported rather than looking like an empty journal')]
	#[Group('strata/journal')]
	public function refusedReadIsReported(): void
	{
		$redis = new FakeRedis();
		$redis->failing = ['xRange'];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot read the journal');

		$this->journal($redis)->read();
	}

	#[Test]
	#[TestDox('an unreachable journal reports nothing pending rather than breaking the request')]
	#[Group('strata/journal')]
	public function unreachableJournalDoesNotBreakCapture(): void
	{
		$redis = new FakeRedis();
		$redis->failing = ['xLen', 'xRange'];
		$journal = $this->journal($redis);

		$this->assertSame(0, $journal->pending());
		$this->assertSame(0, $journal->pendingBytes());
		$this->assertNull($journal->oldest());
	}

	#[Test]
	#[TestDox('an entry nothing can decode is skipped rather than failing the whole window')]
	#[Group('strata/journal')]
	public function undecodableEntryIsSkipped(): void
	{
		$redis = new FakeRedis();
		$journal = $this->journal($redis);

		$journal->append($this->operation('user:1'));
		$redis->entries['1700000000001-0'] = ['op' => 'not json at all', 'payload' => ''];

		$window = $journal->read();

		$this->assertCount(1, $window);
		$this->assertSame('user:1', $window[0]['operation']->subject);
	}

	#endregion
}
