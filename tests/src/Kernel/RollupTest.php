<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Compaction\LevelPolicy;
use Drupal\strata\Compaction\Rollup;
use Drupal\strata\Engine;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;
use Drupal\strata\Segment\SegmentBuilder;
use Drupal\strata\Segment\SegmentReader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves fine segments fold into the coarser level above them.
 *
 * What the rollup is worth is smaller than it looks and is worth being exact about: collapse is writes
 * per distinct subject inside the window, which is 1.00 at every level below a day on a site with many
 * subjects. So the saving at fine levels is the object count - hundreds of segment manifests become one
 * - rather than the operations inside them. These tests assert both halves: that a repeated subject
 * collapses to its last value when it is repeated, and that a window of distinct subjects folds into
 * one segment without losing any of them.
 */
class RollupTest extends StrataKernelTestBase
{
	/**
	 * A ladder where only the finest level ages out.
	 *
	 * Level one is kept forever, so a pass folds level zero into level one and stops there. A pass
	 * cascades the whole ladder when every level ages out, which `ladderCascades` covers with its own
	 * policy; keeping the default ladder to one hop is what makes the counts in every other test mean
	 * what they say.
	 */
	private const LADDER = [
		['window' => 10, 'keep' => 1],
		['window' => 100, 'keep' => 0],
		['window' => 1000, 'keep' => 0],
	];

	/**
	 * A ladder where every level ages out, so one pass climbs all of it.
	 */
	private const CASCADING = [
		['window' => 10, 'keep' => 1],
		['window' => 100, 'keep' => 1],
		['window' => 1000, 'keep' => 0],
	];

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->config('strata.settings')
			->set('enabled', true)
			->set('provider', 'local')
			->set('local_path', $this->storeRoot)
			->set('cipher.id', 'none')
			->set('retention.levels', self::LADDER)
			->save();

		$this->engine()->reset();
	}

	private function engine(): Engine
	{
		return $this->container->get('strata.engine');
	}

	private function policy(?array $ladder = null): LevelPolicy
	{
		return new LevelPolicy($ladder ?? self::LADDER);
	}

	private function rollup(?array $ladder = null): Rollup
	{
		return new Rollup(
			$this->engine()->segmentReader(),
			$this->engine()->segmentWriter(),
			$this->policy($ladder),
			$this->container->get('strata.frame_index'),
			$this->container->get('logger.channel.strata'),
		);
	}

	/**
	 * Writes one fine segment holding the given subjects.
	 *
	 * @param array<string, string> $subjects
	 *   Subject name keyed to its payload.
	 * @param int $second
	 *   The epoch second to file the segment under.
	 * @param int $sequence
	 *   The sequence the first operation carries.
	 *
	 * @return string
	 *   The segment key.
	 */
	private function segment(array $subjects, int $second, int $sequence = 1): string
	{
		$builder = new SegmentBuilder($this->engine()->objectStore());
		$at = $sequence;

		foreach ($subjects as $subject => $payload) {
			$builder->add(
				new JournalOp(
					$at++,
					$second * 1_000_000,
					Realm::ENTITY,
					(string) $subject,
					Verb::UPDATE,
					null,
					null,
					null,
					null,
					strlen($payload),
					'test',
					[],
				),
				$payload,
			);
		}

		$manifest = $builder->build();

		$this->assertNotNull($manifest);

		return $this->engine()->segmentWriter()->write($manifest, $second);
	}

	/**
	 * Segment keys the store holds at one level.
	 *
	 * @return list<string>
	 *   The keys.
	 */
	private function keys(int $level): array
	{
		return iterator_to_array($this->engine()->segmentReader()->keys($level), false);
	}

	#region Folding

	#[Test]
	#[TestDox('a window of fine segments becomes one coarse segment')]
	#[Group('strata/compaction')]
	public function windowBecomesOneSegment(): void
	{
		$this->segment(['user:1' => 'one'], 1_000, 1);
		$this->segment(['user:2' => 'two'], 1_001, 2);
		$this->segment(['user:3' => 'three'], 1_002, 3);

		$this->assertCount(3, $this->keys(0));

		$result = $this->rollup()->run(1_000_000);

		$this->assertSame(1, $result['windows']);
		$this->assertSame(3, $result['segments']);
		$this->assertSame(3, $result['operations'], 'three distinct subjects all survive');
		$this->assertSame(0, $result['collapsed']);
		$this->assertCount(1, $this->keys(1));
		$this->assertCount(3, $this->keys(0), 'the fine segments stay until a prune removes them');
	}

	#[Test]
	#[TestDox('a subject written in several fine segments survives once, with its last value')]
	#[Group('strata/compaction')]
	public function repeatedSubjectCollapsesToItsLastValue(): void
	{
		$this->segment(['user:1' => 'first'], 1_000, 1);
		$this->segment(['user:1' => 'second'], 1_001, 2);
		$this->segment(['user:1' => 'third'], 1_002, 3);

		$result = $this->rollup()->run(1_000_000);

		$this->assertSame(1, $result['operations']);
		$this->assertSame(2, $result['collapsed']);

		$coarse = $this->engine()->segmentReader()->read($this->keys(1)[0]);

		$this->assertSame(1, $coarse->count());
		$this->assertSame('user:1', $coarse->operations[0]->subject);
		$this->assertSame(
			'third',
			$this->engine()->objectStore()->read($coarse->payloadFor($coarse->operations[0])),
			'the surviving operation carries the newest payload',
		);
	}

	#[Test]
	#[TestDox('the coarse segment is filed under its window rather than its first operation')]
	#[Group('strata/compaction')]
	public function coarseSegmentIsFiledUnderItsWindow(): void
	{
		$this->segment(['user:1' => 'one'], 1_045, 1);
		$this->rollup()->run(1_000_000);

		$key = $this->keys(1)[0];

		$this->assertSame(
			$this->policy()->windowStart(1_045, 1),
			SegmentReader::secondOf($key),
			'the key names the window the segment covers',
		);
	}

	#[Test]
	#[TestDox('segments in different windows fold separately')]
	#[Group('strata/compaction')]
	public function separateWindowsFoldSeparately(): void
	{
		$this->segment(['user:1' => 'a'], 1_000, 1);
		$this->segment(['user:2' => 'b'], 1_200, 2);

		$result = $this->rollup()->run(1_000_000);

		$this->assertSame(2, $result['windows']);
		$this->assertCount(2, $this->keys(1));
	}

	#[Test]
	#[TestDox('a pass climbs every level whose retention has elapsed, not just the first')]
	#[Group('strata/compaction')]
	public function ladderCascades(): void
	{
		$this->segment(['user:1' => 'a'], 1_000, 1);

		$result = $this->rollup(self::CASCADING)->run(1_000_000);

		$this->assertSame(2, $result['windows'], 'level zero into one, then one into two');
		$this->assertCount(1, $this->keys(1));
		$this->assertCount(1, $this->keys(2));
	}

	#[Test]
	#[TestDox('a level kept forever is not rolled up, however old its windows are')]
	#[Group('strata/compaction')]
	public function permanentLevelIsNotRolledUp(): void
	{
		$this->segment(['user:1' => 'a'], 1_000, 1);

		$this->rollup()->run(1_000_000);

		$this->assertCount(1, $this->keys(1));
		$this->assertCount(0, $this->keys(2), 'level one is kept forever, so it stays put');
		$this->assertTrue($this->policy()->isPermanent(1));
	}

	#[Test]
	#[TestDox('the coarsest level rolls up into nothing, since it has nowhere to go')]
	#[Group('strata/compaction')]
	public function coarsestLevelDoesNotRollUp(): void
	{
		$this->assertNull($this->policy()->promotes($this->policy()->depth() - 1));
		$this->assertSame([], $this->rollup()->dueWindows($this->policy()->depth() - 1, 1_000_000));
	}

	#endregion

	#region Bounds

	#[Test]
	#[TestDox('a window still inside its retention is not rolled up')]
	#[Group('strata/compaction')]
	public function retainedWindowIsLeftAlone(): void
	{
		$this->segment(['user:1' => 'a'], 1_000, 1);

		// the level keeps one second, so at second 1,000 the window has not aged out
		$result = $this->rollup()->run(1_000);

		$this->assertSame(0, $result['windows']);
		$this->assertCount(0, $this->keys(1));
	}

	#[Test]
	#[TestDox('a pass is bounded, so one cron run cannot be consumed by rolling up')]
	#[Group('strata/compaction')]
	public function passIsBounded(): void
	{
		for ($i = 0; $i < 6; $i++) {
			$this->segment(['user:' . $i => 'payload ' . $i], 1_000 + $i * 100, $i + 1);
		}

		$result = $this->rollup()->run(1_000_000, 2);

		$this->assertLessThanOrEqual(2, $result['segments']);
		$this->assertGreaterThan(0, $result['windows']);
	}

	#[Test]
	#[TestDox('an empty store rolls up nothing rather than failing')]
	#[Group('strata/compaction')]
	public function emptyStoreRollsUpNothing(): void
	{
		$result = $this->rollup()->run(1_000_000);

		$this->assertSame(0, $result['windows']);
		$this->assertSame([], $result['written']);
		$this->assertSame([], $result['problems']);
	}

	#endregion

	#region References

	#[Test]
	#[
		TestDox(
			'a frame the coarse segment carries forward gains a reference, so a prune cannot take it',
		),
	]
	#[Group('strata/compaction')]
	public function carriedFramesGainAReference(): void
	{
		$this->segment(['user:1' => str_repeat('payload ', 40)], 1_000, 1);

		$index = $this->container->get('strata.frame_index');
		$fine = $this->engine()->segmentReader()->read($this->keys(0)[0]);
		$frame = $fine->payloadFor($fine->operations[0])[0];
		$before = $index->get($frame)?->references ?? 0;

		$this->rollup()->run(1_000_000);

		$this->assertGreaterThan($before, $index->get($frame)?->references ?? 0);
	}

	#[Test]
	#[TestDox('a compaction pass rolls up as part of its run')]
	#[Group('strata/compaction')]
	public function compactionRollsUp(): void
	{
		$this->segment(['user:1' => 'a'], 1_000, 1);

		$report = $this->engine()->compactor()->compact();

		$this->assertSame([], $report->problems);
		$this->assertCount(1, $this->keys(1), 'the compaction pass folded the window');
	}

	#endregion
}
