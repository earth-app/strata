<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\E2E;

use Drupal;
use Drupal\node\Entity\Node;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\RepairLadder;
use Drupal\strata\Verify\VerifyReport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Redis;

/**
 * Proves every tripwire fires on the failure it exists for, and only then.
 *
 * Each case builds a real history, damages the store the way a real fault would, and asks the module
 * what it can tell. The damage is done to the bucket, the database or the filesystem; no case builds
 * a finding by hand. A tripwire that stays silent here would stay silent on the night it was meant
 * for, so every assertion names the code, the severity and the repair rung the finding lands on, and
 * the first case also runs the repair ladder to see what it can and cannot fix.
 */
class TripwireTest extends E2ETestBase
{
	#region Storage faults

	#[Test]
	#[
		TestDox(
			'frame.unindexed fires when the local index loses a frame the store still holds, and a reindex clears it',
		),
	]
	#[Group('strata/e2e')]
	public function frameUnindexed(): void
	{
		$this->configure('s3');
		$nodes = $this->history();
		$frame = $this->latestFrame($nodes[0]);

		Drupal::database()->delete('strata_frame')->condition('hash', $frame)->execute();

		$report = $this->verify();

		$this->assertFinding($report, 'frame.unindexed', Finding::ERROR, 'reindex');
		$this->assertTrue(RepairLadder::isAutomatic('reindex'));

		$ran = $this->engine()->repairPass()->runCode('frame.unindexed');

		$this->assertSame(0, $ran->failed, json_encode($ran));
		$this->assertTrue($this->verify()->isClean(), 'a reindex rebuilt what the index lost');
	}

	#[Test]
	#[
		TestDox(
			'frame.missing fires when an object the index names is gone from the bucket, and waits for a person',
		),
	]
	#[Group('strata/e2e')]
	public function frameMissing(): void
	{
		$this->configure('s3');
		$nodes = $this->history();

		Fault::delete($this->provider(), [$this->objectKeyOf($this->latestFrame($nodes[0]))]);

		$report = $this->verify();

		$this->assertFinding($report, 'frame.missing', Finding::CRITICAL, 'quarantine');
		$this->assertFalse(RepairLadder::isAutomatic('quarantine'));
		$this->assertGreaterThan(0, $this->engine()->repairPass()->run()->held);
	}

	#[Test]
	#[TestDox('frame.unreadable fires when a stored frame no longer decodes')]
	#[Group('strata/e2e')]
	public function frameUnreadable(): void
	{
		$this->configure('s3', ['cipher.id' => 'none']);
		$nodes = $this->history();

		Fault::overwrite($this->provider(), $this->objectKeyOf($this->latestFrame($nodes[0])));

		$this->assertFinding($this->verify(), 'frame.unreadable', Finding::ERROR, 'reindex');
	}

	#[Test]
	#[TestDox('frame.hash_mismatch fires when a frame decodes cleanly to the wrong content')]
	#[Group('strata/e2e')]
	public function frameHashMismatch(): void
	{
		$this->configure('s3', ['cipher.id' => 'none', 'delta.enabled' => false]);

		Drupal::state()->set('e2e.swap.a', str_repeat('a', 400));
		Drupal::state()->set('e2e.swap.b', str_repeat('b', 400));
		$this->seal();

		$subjects = $this->engine()->subjectIndex();
		$index = $this->engine()->frameIndex();
		$first = $index->get($subjects->frames('state/e2e.swap.a')[0]);
		$second = $index->get($subjects->frames('state/e2e.swap.b')[0]);

		if ($first === null || $second === null || $first->storedSize !== $second->storedSize) {
			$this->markTestSkipped('the two frames did not encode to the same length');
		}

		$key = $this->objectKeyOf($first->hash);
		$pack = $this->provider()->get($key);
		$other = $this->provider()->get($this->objectKeyOf($second->hash));
		$spliced = substr_replace(
			$pack,
			substr($other, $second->offset, $second->storedSize),
			$first->offset,
			$first->storedSize,
		);

		$this->provider()->put($key, $spliced);

		$this->assertFinding(
			$this->verify(),
			'frame.hash_mismatch',
			Finding::CRITICAL,
			'quarantine',
		);
	}

	#[Test]
	#[TestDox('frame.aead_fail fires when a sealed frame changes by one bit')]
	#[Group('strata/e2e')]
	public function frameAeadFailure(): void
	{
		$this->configure('s3');
		$nodes = $this->history();
		$frame = $this->latestFrame($nodes[0]);
		$record = $this->engine()->frameIndex()->get($frame);

		$this->assertNotNull($record);

		Fault::flip(
			$this->provider(),
			$this->objectKeyOf($frame),
			$record->offset + intdiv($record->storedSize, 2),
		);

		$this->assertFinding($this->verify(), 'frame.aead_fail', Finding::CRITICAL, 'quarantine');
	}

	#[Test]
	#[
		TestDox(
			'pack.short_read fires when a pack is cut short, which is what an interrupted upload leaves',
		),
	]
	#[Group('strata/e2e')]
	public function packShortRead(): void
	{
		$this->configure('s3');
		$nodes = $this->history();

		$frame = $this->latestFrame($nodes[0]);
		$record = $this->engine()->frameIndex()->get($frame);
		$key = $this->objectKeyOf($frame);

		$this->assertNotNull($record);

		$this->provider()->put(
			$key,
			substr(
				$this->provider()->get($key),
				0,
				$record->offset + intdiv($record->storedSize, 2),
			),
		);

		$this->assertFinding($this->verify(false), 'pack.short_read', Finding::ERROR, 'reindex');
	}

	#[Test]
	#[
		TestDox(
			'dict.missing fires when the dictionaries a frame was written against leave the bucket',
		),
	]
	#[Group('strata/e2e')]
	public function dictionaryMissing(): void
	{
		$this->configure('s3');

		for ($i = 0; $i < 60; $i++) {
			$this->article(
				'Dictionary ' . $i,
				json_encode(['n' => $i, 'text' => str_repeat('lorem ', 30)]),
			);
		}

		$this->seal();
		$this->engine()->dictionaryPass()->run();
		$this->reset();

		for ($i = 60; $i < 90; $i++) {
			$this->article(
				'Dictionary ' . $i,
				json_encode(['n' => $i, 'text' => str_repeat('lorem ', 30)]),
			);
		}

		$this->seal();

		$dictionaries = Fault::keys($this->provider(), 'dicts/');

		if ($dictionaries === []) {
			$this->markTestSkipped('no dictionary was trained for this corpus');
		}

		Fault::delete($this->provider(), $dictionaries);
		$this->reset();

		$this->assertFinding($this->verify(), 'dict.missing', Finding::CRITICAL, 'quarantine');
	}

	#[Test]
	#[TestDox('delta.parent_missing fires when the index loses the frame a delta decodes against')]
	#[Group('strata/e2e')]
	public function deltaParentMissing(): void
	{
		$this->configure('s3');
		$nodes = $this->history(4);
		$record = $this->engine()->frameIndex()->get($this->latestFrame($nodes[0]));

		$this->assertNotNull($record);
		$this->assertNotNull(
			$record->deltaParent,
			'the newest version is coded as a delta: ' .
				json_encode($record) .
				json_encode(Drupal::config('strata.settings')->get('delta')) .
				' policy=' .
				json_encode($this->engine()->chainDepthPolicy() !== null) .
				' codec=' .
				$this->engine()->codecs()->writer()->id() .
				' frames=' .
				json_encode(
					$this->engine()
						->subjectIndex()
						->frames('entity/node:' . $nodes[0]->id()),
				),
		);

		Drupal::database()
			->delete('strata_frame')
			->condition('hash', $record->deltaParent)
			->execute();

		$this->assertFinding(
			$this->verify(),
			'delta.parent_missing',
			Finding::CRITICAL,
			'quarantine',
		);
	}

	#[Test]
	#[TestDox('delta.chain_too_deep warns when a chain is longer than the cap the site now sets')]
	#[Group('strata/e2e')]
	public function deltaChainTooDeep(): void
	{
		$this->configure('s3');
		$this->history(6, 1);

		$this->configure('s3', ['delta.max_depth' => 2]);

		$this->assertFinding($this->verify(), 'delta.chain_too_deep', Finding::WARN, 'observe');
	}

	#[Test]
	#[TestDox('segment.truncated fires when a segment manifest is cut short')]
	#[Group('strata/e2e')]
	public function segmentTruncated(): void
	{
		$this->configure('s3');
		$this->history();

		$segments = Fault::keys($this->provider(), 'segments/');

		$this->assertNotSame([], $segments);

		Fault::truncate($this->provider(), $segments[0], 0.3);

		$this->assertFinding($this->verify(), 'segment.truncated', Finding::ERROR, 'reindex');
	}

	#[Test]
	#[
		TestDox(
			'commit.parent_missing and base.unreachable fire when a commit in the middle of history is gone',
		),
	]
	#[Group('strata/e2e')]
	public function commitParentMissing(): void
	{
		$this->configure('s3');
		$this->history(4);

		$head = $this->engine()->commitLog()->head();

		$this->assertNotNull($head);
		$this->assertNotNull($head->parent);

		Fault::delete($this->provider(), [Hash::key($head->parent, 'commits')]);

		$report = $this->verify();

		$this->assertFinding($report, 'commit.parent_missing', Finding::CRITICAL, 'quarantine');
		$this->assertFinding($report, 'base.unreachable', Finding::CRITICAL, 'quarantine');
	}

	#[Test]
	#[TestDox('anchor.missing fires when a base anchor leaves the bucket')]
	#[Group('strata/e2e')]
	public function anchorMissing(): void
	{
		$this->configure('s3');
		$this->history();

		$anchors = Fault::keys($this->provider(), 'bases/');

		$this->assertNotSame([], $anchors);

		Fault::delete($this->provider(), $anchors);

		$this->assertFinding($this->verify(), 'anchor.missing', Finding::CRITICAL, 'quarantine');
	}

	#[Test]
	#[
		TestDox(
			'ref.missing fires when the ref is deleted while the local index still names the commits',
		),
	]
	#[Group('strata/e2e')]
	public function refMissing(): void
	{
		$this->configure('s3');
		$this->history();

		Fault::delete($this->provider(), Fault::keys($this->provider(), 'refs/'));

		$this->assertFinding($this->verify(), 'ref.missing', Finding::CRITICAL, 'quarantine');
	}

	#[Test]
	#[
		TestDox(
			'tier.unreachable fires when a tier in the ladder cannot be reached, and says so instead of reporting it empty',
		),
	]
	#[Group('strata/e2e')]
	public function tierUnreachable(): void
	{
		$this->configure('s3');
		$this->history();

		$this->configure('s3', [
			'tiers' => [
				'enabled' => true,
				'verify_copies' => true,
				'levels' => [
					[
						'name' => 'hot',
						'provider' => 's3',
						'location' => Lane::env('S3_BUCKET'),
						'from_age' => 0,
					],
					[
						'name' => 'cold',
						'provider' => 's3',
						'location' => 'no-such-bucket-' . bin2hex(random_bytes(3)),
						'from_age' => 86400,
					],
				],
			],
		]);

		$this->assertFinding($this->verify(), 'tier.unreachable', Finding::ERROR, 'reindex');
	}

	#[Test]
	#[TestDox('key.rotated_mid_flight warns when one segment holds frames sealed under two keys')]
	#[Group('strata/e2e')]
	public function keyRotatedMidFlight(): void
	{
		$this->configure('s3');

		Drupal::state()->set('e2e.shared', ['value' => 'sealed under the first key']);
		$this->seal();

		$next = Drupal::service('strata.key_maker')->create('strata_e2e_next');

		$this->configure('s3', ['key' => $next, 'retired_keys' => [self::KEY]]);

		Drupal::state()->set('e2e.twin', ['value' => 'sealed under the first key']);
		Drupal::state()->set('e2e.fresh', ['value' => 'sealed under the second key']);
		$this->seal();

		$this->assertCodeOpen('key.rotated_mid_flight', Finding::WARN);
	}

	#endregion

	#region Capture faults

	#[Test]
	#[
		TestDox(
			'watermark.drift warns when a table changes with no captured operation, and the rows are captured to close the gap',
		),
	]
	#[Group('strata/e2e')]
	public function watermarkDrift(): void
	{
		$this->configure('s3', ['capture.statements' => false]);

		$schema = Drupal::database()->schema();

		if (!$schema->tableExists('e2e_watermark')) {
			$schema->createTable('e2e_watermark', [
				'fields' => [
					'id' => ['type' => 'serial', 'not null' => true],
					'label' => [
						'type' => 'varchar',
						'length' => 64,
						'not null' => true,
						'default' => '',
					],
					'changed' => ['type' => 'int', 'not null' => true, 'default' => 0],
				],
				'primary key' => ['id'],
			]);
		}

		Drupal::database()
			->insert('e2e_watermark')
			->fields(['label' => 'first', 'changed' => 1])
			->execute();
		$this->engine()
			->reconciler()
			->reconcile(['e2e_watermark']);

		Drupal::database()
			->insert('e2e_watermark')
			->fields(['label' => 'second', 'changed' => 2])
			->execute();
		$report = $this->engine()
			->reconciler()
			->reconcile(['e2e_watermark']);

		$this->assertSame(1, $report->drifted, $report->summary());
		$this->assertCodeOpen('watermark.drift', Finding::WARN);
	}

	#[Test]
	#[
		TestDox(
			'classification.unclassified_growth warns when a quarter of the Redis keyspace has no decision',
		),
	]
	#[Group('strata/e2e')]
	public function unclassifiedGrowth(): void
	{
		$this->configure('s3');

		$redis = new Redis();
		$redis->connect(Lane::env('REDIS_HOST', 'redis'));
		$redis->flushDB();

		for ($i = 0; $i < 600; $i++) {
			$redis->set('mystery:' . $i, 'value-' . $i);
		}

		$report = $this->engine()->keyspaceDiscovery()->discover();

		$redis->flushDB();

		$this->assertGreaterThanOrEqual(600, $report->keys);
		$this->assertCodeOpen(
			'classification.unclassified_growth',
			Finding::WARN,
			json_encode($report),
		);
	}

	#[Test]
	#[
		TestDox(
			'code.vendor_drift fires when a dependency is patched in place and the lock did not move',
		),
	]
	#[Group('strata/e2e')]
	public function vendorDrift(): void
	{
		$this->configure('s3', ['code.root' => '/srv/drupal']);

		$this->engine()->codeCapture()->capture();

		$target = '/srv/drupal/vendor/autoload.php';
		$original = (string) file_get_contents($target);

		try {
			file_put_contents($target, $original . ' ');
			$this->engine()->codeCapture()->capture();
		} finally {
			file_put_contents($target, $original);
		}

		$this->assertCodeOpen('code.vendor_drift', Finding::ERROR);
	}

	#[Test]
	#[
		TestDox(
			'flush.stale warns from cron when the store is unreachable and a change keeps waiting',
		),
	]
	#[Group('strata/e2e')]
	public function flushStale(): void
	{
		$this->configure('s3', [
			's3.endpoint' => 'http://127.0.0.1:9',
			'flush.max_ops' => 1,
			'flush.stale_after' => 1,
		]);

		$this->article('Waiting', self::prose(0));
		$this->settle();
		sleep(2);

		$this->drush(['core:cron']);

		$this->assertCodeOpen('flush.stale', Finding::WARN, 'after cron met a dead store');
		$this->assertSame(
			'observe',
			RepairLadder::initialRung(Finding::WARN),
			'nothing unattended repairs a store that refuses writes',
		);
	}

	#[Test]
	#[
		TestDox(
			'file.shift_detected warns when most of a file moves, which fixed blocks cannot follow',
		),
	]
	#[Group('strata/e2e')]
	public function fileShiftDetected(): void
	{
		$this->configure('s3', ['capture.file' => true]);

		$path = '/srv/drupal/private/shift-' . bin2hex(random_bytes(3)) . '.bin';
		$body = random_bytes(2 * 1024 * 1024);

		file_put_contents($path, $body);
		$this->engine()->fileCapture()->capture($path);

		file_put_contents($path, random_bytes(70_000) . $body);
		$this->engine()->fileCapture()->capture($path);

		@unlink($path);

		$this->assertCodeOpen('file.shift_detected', Finding::WARN);
	}

	#endregion

	/**
	 * Verifies the store, deep unless asked otherwise.
	 *
	 * @param bool $deep
	 *   FALSE to check the index and the objects' sizes without reading their bytes.
	 *
	 * @return VerifyReport
	 *   The report.
	 */
	private function verify(bool $deep = true): VerifyReport
	{
		return $this->engine()->verifier()->verify('heads/main', null, $deep);
	}

	/**
	 * The newest frame of a node.
	 *
	 * @param Node $node
	 *   The node.
	 *
	 * @return string
	 *   The frame digest.
	 */
	private function latestFrame(Node $node): string
	{
		$frames = $this->engine()
			->subjectIndex()
			->frames('entity/node:' . $node->id());

		$this->assertNotSame([], $frames);

		return $frames[array_key_last($frames)];
	}

	/**
	 * Asserts a verify report holds a finding, and where the repair ladder starts it.
	 *
	 * @param VerifyReport $report
	 *   The report.
	 * @param string $code
	 *   The finding code.
	 * @param int $severity
	 *   The severity it must carry.
	 * @param string $rung
	 *   The rung it must land on.
	 */
	private function assertFinding(
		VerifyReport $report,
		string $code,
		int $severity,
		string $rung,
	): void {
		$found = array_values(
			array_filter(
				$report->findings,
				static fn(Finding $finding): bool => $finding->code === $code,
			),
		);

		$this->assertNotSame([], $found, $code . ' did not fire; ' . $report->summary());
		$this->assertSame($severity, $found[0]->severity, $code . ' severity');
		$this->assertSame($rung, $this->engine()->ledger()->rungFor($code), $code . ' rung');
	}

	/**
	 * Asserts the ledger holds an open finding.
	 *
	 * @param string $code
	 *   The finding code.
	 * @param int $severity
	 *   The severity it must carry.
	 * @param string $note
	 *   Context for a failure message.
	 */
	private function assertCodeOpen(string $code, int $severity, string $note = ''): void
	{
		$open = array_values(
			array_filter(
				$this->engine()->ledger()->open(),
				static fn(Finding $finding): bool => $finding->code === $code,
			),
		);

		$this->assertNotSame([], $open, $code . ' was never recorded ' . $note);
		$this->assertSame($severity, $open[0]->severity, $code . ' severity');
	}
}
