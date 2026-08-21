<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Engine;
use Drupal\strata\Timeline\TimelineBucket;
use Drupal\strata\Timeline\TimelineQuery;
use Drupal\strata\Timeline\TimelineWindow;
use Drupal\strata\Tree\CommitIndex;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves the bucketing arithmetic survives being pushed into SQL.
 *
 * The aggregation is a modulo inside the query rather than a loop in PHP, so the totals are checked
 * against `totals()` over the same window: two different queries agreeing is what makes the bars
 * trustworthy.
 *
 * Empty buckets are asserted to be present. A query returns only the buckets that hold commits, and
 * a chart drawn from those alone draws a continuous line across a quiet period.
 */
class TimelineQueryTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'field', 'key', 'strata'];

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->installEntitySchema('user');
		$this->installSchema('user', ['users_data']);

		$this->config('strata.settings')
			->set('enabled', true)
			->set('provider', 'local')
			->set('local_path', $this->storeRoot)
			->set('cipher.id', 'none')
			->save();

		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();
	}

	private function engine(): Engine
	{
		return $this->container->get('strata.engine');
	}

	private function timeline(): TimelineQuery
	{
		return $this->engine()->timeline();
	}

	private function commits(): CommitIndex
	{
		return $this->engine()->commitIndex();
	}

	/**
	 * Saves a user and seals the window.
	 *
	 * @param string $name
	 *   The account name.
	 *
	 * @return string
	 *   The commit the flush sealed.
	 */
	private function commitUser(string $name): string
	{
		$user = User::create(['name' => $name, 'mail' => $name . '@example.com', 'status' => 1]);
		$user->save();

		$result = $this->engine()->flusher()->flush(true);

		$this->assertTrue($result->ran, 'the flush sealed a commit');

		return (string) $result->commit;
	}

	/**
	 * Seals several commits.
	 *
	 * @param int $count
	 *   How many.
	 *
	 * @return list<string>
	 *   The commit ids, oldest first.
	 */
	private function commitSeveral(int $count): array
	{
		$ids = [];

		for ($i = 0; $i < $count; $i++) {
			$ids[] = $this->commitUser('timeline-' . $i);
		}

		return $ids;
	}

	/**
	 * Moves one commit's stamp, so a window can be built around a known moment.
	 *
	 * @param string $id
	 *   The commit id.
	 * @param int $microtime
	 *   The stamp to give it.
	 */
	private function restamp(string $id, int $microtime): void
	{
		$this->container
			->get('database')
			->update(CommitIndex::TABLE)
			->fields(['microtime' => $microtime])
			->condition('id', $id)
			->execute();
	}

	/**
	 * The microtime of one indexed commit.
	 *
	 * @param string $id
	 *   The commit id.
	 *
	 * @return int
	 *   Unix microseconds.
	 */
	private function stampOf(string $id): int
	{
		$row = $this->commits()->get($id);

		$this->assertNotNull($row);

		return (int) $row['microtime'];
	}

	/**
	 * A window covering every commit at a known resolution.
	 *
	 * @param int $resolution
	 *   Bucket width in seconds.
	 *
	 * @return TimelineWindow
	 *   The window.
	 */
	private function windowOverEverything(int $resolution = 60): TimelineWindow
	{
		$newest = $this->commits()->newest();

		$this->assertNotNull($newest);

		$stamp = (int) $newest['microtime'];

		return new TimelineWindow(
			$stamp - 3_600 * TimelineWindow::MICROS_PER_SECOND,
			$stamp + 3_600 * TimelineWindow::MICROS_PER_SECOND,
			$resolution,
		);
	}

	#region Bucketing

	#[Test]
	#[TestDox('the buckets sum to the same totals the window reports without bucketing')]
	#[Group('strata/timeline')]
	public function bucketsSumToTheTotals(): void
	{
		$this->commitSeveral(3);

		$window = $this->windowOverEverything();
		$buckets = $this->timeline()->buckets($window);
		$totals = $this->timeline()->totals($window);

		$this->assertSame(3, $totals['commits']);
		$this->assertGreaterThan(0, $totals['operations']);
		$this->assertGreaterThan(0, $totals['raw_bytes']);

		$summed = ['commits' => 0, 'operations' => 0, 'raw_bytes' => 0, 'stored_bytes' => 0];

		foreach ($buckets as $bucket) {
			$summed['commits'] += $bucket->commits;
			$summed['operations'] += $bucket->operations;
			$summed['raw_bytes'] += $bucket->rawBytes;
			$summed['stored_bytes'] += $bucket->storedBytes;
		}

		$this->assertSame($totals['commits'], $summed['commits']);
		$this->assertSame($totals['operations'], $summed['operations']);
		$this->assertSame($totals['raw_bytes'], $summed['raw_bytes']);
		$this->assertSame($totals['stored_bytes'], $summed['stored_bytes']);
	}

	#[Test]
	#[TestDox('an anchor is counted as one, so the bars name where a replay can start')]
	#[Group('strata/timeline')]
	public function anchorsAreCounted(): void
	{
		$this->commitUser('anchored');

		$window = $this->windowOverEverything();
		$totals = $this->timeline()->totals($window);
		$anchors = 0;

		foreach ($this->timeline()->buckets($window) as $bucket) {
			$anchors += $bucket->anchors;
		}

		$this->assertSame(1, $totals['anchors'], 'the first commit anchors a replay');
		$this->assertSame($totals['anchors'], $anchors);
	}

	#[Test]
	#[TestDox('commits inside one bucket all land in it, so the arithmetic is not off by one')]
	#[Group('strata/timeline')]
	public function commitsInOneBucketStayTogether(): void
	{
		$ids = $this->commitSeveral(3);
		$width = 60 * TimelineWindow::MICROS_PER_SECOND;
		$start = intdiv($this->stampOf($ids[0]), $width) * $width;

		// the whole flush is inside one minute, one microsecond apart
		foreach ($ids as $at => $id) {
			$this->restamp($id, $start + 1 + $at);
		}

		$window = new TimelineWindow($start, $start + $width * 4, 60);
		$buckets = $this->timeline()->buckets($window);
		$occupied = array_values(
			array_filter($buckets, static fn(TimelineBucket $b): bool => !$b->isEmpty()),
		);

		$this->assertCount(1, $occupied, 'one minute of commits is one bar');
		$this->assertSame(3, $occupied[0]->commits);
		$this->assertSame($start, $occupied[0]->startMicrotime);
		$this->assertSame($start + $width - 1, $occupied[0]->endMicrotime);
	}

	#[Test]
	#[TestDox('a commit on the far edge of a bucket lands in the next one')]
	#[Group('strata/timeline')]
	public function bucketEdgeIsExclusive(): void
	{
		$ids = $this->commitSeveral(2);
		$width = 60 * TimelineWindow::MICROS_PER_SECOND;
		$start = intdiv($this->stampOf($ids[0]), $width) * $width;

		$this->restamp($ids[0], $start + $width - 1);
		$this->restamp($ids[1], $start + $width);

		$window = new TimelineWindow($start, $start + $width * 3, 60);
		$buckets = $this->timeline()->buckets($window);

		$this->assertSame(1, $buckets[0]->commits, 'the last microsecond is still the first bar');
		$this->assertSame(1, $buckets[1]->commits, 'the next microsecond starts the second');
	}

	#[Test]
	#[TestDox('every bucket the window defines is present, quiet ones included')]
	#[Group('strata/timeline')]
	public function quietBucketsArePresent(): void
	{
		$id = $this->commitUser('lonely');
		$width = 60 * TimelineWindow::MICROS_PER_SECOND;
		$start = intdiv($this->stampOf($id), $width) * $width;

		$this->restamp($id, $start + 1);

		$window = new TimelineWindow($start, $start + $width * 5, 60);
		$buckets = $this->timeline()->buckets($window);

		$this->assertCount(6, $buckets);
		$this->assertSame($window->bucketCount(), count($buckets));
		$this->assertFalse($buckets[0]->isEmpty());

		foreach (array_slice($buckets, 1) as $at => $bucket) {
			$this->assertTrue($bucket->isEmpty(), 'bucket ' . ($at + 1) . ' is quiet');
			$this->assertSame(0, $bucket->commits);
			$this->assertSame(0, $bucket->operations);
			$this->assertSame([], $bucket->ids);
		}
	}

	#[Test]
	#[TestDox('a window over an empty history is still a full row of empty buckets')]
	#[Group('strata/timeline')]
	public function emptyHistoryStillHasBuckets(): void
	{
		$window = TimelineWindow::lastSeconds(600, 1_700_000_000);
		$buckets = $this->timeline()->buckets($window);

		$this->assertNotEmpty($buckets);
		$this->assertCount($window->bucketCount(), $buckets);

		foreach ($buckets as $bucket) {
			$this->assertTrue($bucket->isEmpty());
		}

		$this->assertSame(
			[
				'commits' => 0,
				'operations' => 0,
				'raw_bytes' => 0,
				'stored_bytes' => 0,
				'anchors' => 0,
			],
			$this->timeline()->totals($window),
		);
	}

	#[Test]
	#[TestDox('a commit outside the window is not counted in it')]
	#[Group('strata/timeline')]
	public function commitsOutsideTheWindowAreExcluded(): void
	{
		$id = $this->commitUser('elsewhere');
		$stamp = $this->stampOf($id);
		$width = 60 * TimelineWindow::MICROS_PER_SECOND;
		$window = new TimelineWindow($stamp + $width * 10, $stamp + $width * 20, 60);

		$this->assertSame(0, $this->timeline()->totals($window)['commits']);

		foreach ($this->timeline()->buckets($window) as $bucket) {
			$this->assertTrue($bucket->isEmpty());
		}
	}

	#endregion

	#region Drilldown

	#[Test]
	#[TestDox('commitsIn() returns the commits that fell in a bucket')]
	#[Group('strata/timeline')]
	public function commitsInReturnsTheBucketsCommits(): void
	{
		$ids = $this->commitSeveral(3);
		$width = 60 * TimelineWindow::MICROS_PER_SECOND;
		$start = intdiv($this->stampOf($ids[0]), $width) * $width;

		foreach ($ids as $at => $id) {
			$this->restamp($id, $start + 1 + $at);
		}

		$window = new TimelineWindow($start, $start + $width * 2, 60);
		$buckets = $this->timeline()->buckets($window);
		$rows = $this->timeline()->commitsIn($buckets[0]);

		$this->assertCount(3, $rows);
		$this->assertSame(
			array_reverse($ids),
			array_map(static fn(array $row): string => (string) $row['id'], $rows),
			'newest first',
		);
		$this->assertSame([], $this->timeline()->commitsIn($buckets[1]));
	}

	#[Test]
	#[TestDox('a bucket carries the ids of the commits that made it')]
	#[Group('strata/timeline')]
	public function bucketCarriesItsIds(): void
	{
		$ids = $this->commitSeveral(2);

		$window = $this->windowOverEverything();
		$carried = [];

		foreach ($this->timeline()->buckets($window) as $bucket) {
			foreach ($bucket->ids as $id) {
				$carried[] = $id;
			}

			$this->assertTrue($bucket->hasEveryId(), 'nothing was truncated at this size');
		}

		sort($ids);
		sort($carried);

		$this->assertSame($ids, $carried);
	}

	#endregion

	#region Full history

	#[Test]
	#[TestDox('fullHistory() spans the oldest and the newest commit')]
	#[Group('strata/timeline')]
	public function fullHistorySpansEveryCommit(): void
	{
		$ids = $this->commitSeveral(3);

		$window = $this->timeline()->fullHistory(1_700_000_000);

		$this->assertSame($this->stampOf($ids[0]), $window->fromMicrotime);
		$this->assertSame($this->stampOf($ids[2]), $window->toMicrotime);
		$this->assertContains($window->resolution, TimelineWindow::LADDER);
		$this->assertSame(3, $this->timeline()->totals($window)['commits']);
	}

	#[Test]
	#[TestDox('fullHistory() with no history falls back to the last hour')]
	#[Group('strata/timeline')]
	public function fullHistoryOfNothingIsTheLastHour(): void
	{
		$window = $this->timeline()->fullHistory(1_700_000_000);

		$this->assertSame(3_600.0, $window->seconds());
		$this->assertSame(1_700_000_000 * TimelineWindow::MICROS_PER_SECOND, $window->toMicrotime);
	}

	#[Test]
	#[TestDox('a history of one commit is a window of no width rather than a refusal')]
	#[Group('strata/timeline')]
	public function fullHistoryOfOneCommit(): void
	{
		$id = $this->commitUser('solitary');

		$window = $this->timeline()->fullHistory(1_700_000_000);

		$this->assertSame($this->stampOf($id), $window->fromMicrotime);
		$this->assertSame($window->fromMicrotime, $window->toMicrotime);
		$this->assertSame(1, $window->bucketCount());
		$this->assertSame(1, $this->timeline()->buckets($window)[0]->commits);
	}

	#endregion

	#region Whole-store totals

	#[Test]
	#[TestDox('capturedRawBytes() is the sum of every indexed commit raw byte count')]
	#[Group('strata/timeline')]
	public function capturedRawBytesSumsTheIndex(): void
	{
		$this->assertSame(0, $this->timeline()->capturedRawBytes());

		$this->commitSeveral(3);

		$expected = 0;

		foreach ($this->commits()->between(0, PHP_INT_MAX, 100) as $row) {
			$expected += (int) $row['raw_bytes'];
		}

		$this->assertGreaterThan(0, $expected);
		$this->assertSame($expected, $this->timeline()->capturedRawBytes());
	}

	#[Test]
	#[TestDox('capturedRawBytes() counts the whole history, not one window')]
	#[Group('strata/timeline')]
	public function capturedRawBytesIgnoresWindows(): void
	{
		$id = $this->commitUser('whole-store');
		$stamp = $this->stampOf($id);
		$width = 60 * TimelineWindow::MICROS_PER_SECOND;
		$away = new TimelineWindow($stamp + $width * 10, $stamp + $width * 20, 60);

		$this->assertSame(0, $this->timeline()->totals($away)['raw_bytes']);
		$this->assertGreaterThan(0, $this->timeline()->capturedRawBytes());
	}

	#endregion
}
