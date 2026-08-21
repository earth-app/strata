<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Engine;
use Drupal\strata\Health\Finding;
use Drupal\strata\Tree\CommitIndex;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves a verify pass reads the backup back and names what does not come out.
 */
class VerifyTest extends StrataKernelTestBase
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

		// the codec is pinned so a flipped byte is a content change rather than a decode failure
		$this->config('strata.settings')
			->set('enabled', true)
			->set('provider', 'local')
			->set('local_path', $this->storeRoot)
			->set('cipher.id', 'none')
			->set('codec.id', 'none')
			->save();

		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();
	}

	private function engine(): Engine
	{
		return $this->container->get('strata.engine');
	}

	private function commitIndex(): CommitIndex
	{
		return $this->container->get('strata.commit_index');
	}

	/**
	 * Saves a user, so the capture hooks produce a real operation.
	 */
	private function user(string $name): User
	{
		$user = User::create(['name' => $name, 'mail' => $name . '@example.com', 'status' => 1]);
		$user->save();

		return $user;
	}

	/**
	 * Flushes a window, having captured something first.
	 */
	private function flush(string $name): void
	{
		$this->user($name);
		$this->engine()->flusher()->flush(true);
	}

	/**
	 * Object keys the store holds under a prefix.
	 *
	 * @return list<string>
	 *   The keys.
	 */
	private function keys(string $prefix = ''): array
	{
		return $this->engine()->provider()->list($prefix, null, 1000)->keys();
	}

	/**
	 * Finding codes an open ledger holds.
	 *
	 * @return list<string>
	 *   The codes.
	 */
	private function ledgerCodes(): array
	{
		return array_values(
			array_map(
				static fn(Finding $finding): string => $finding->code,
				$this->container->get('strata.health_ledger')->open(),
			),
		);
	}

	#region A Clean Pass

	#[Test]
	#[TestDox('a freshly flushed history verifies clean and reports what it read')]
	#[Group('strata/verify')]
	public function cleanHistoryVerifies(): void
	{
		$this->flush('verified-one');
		$this->flush('verified-two');

		$report = $this->engine()->verifier()->verify();

		$this->assertTrue($report->isClean(), implode(', ', array_keys($report->byCode())));
		$this->assertTrue($report->complete, 'the walk reached the root of history');
		$this->assertSame(2, $report->commits);
		$this->assertSame(2, $report->segments);
		$this->assertGreaterThan(0, $report->frames);
		$this->assertGreaterThan(0, $report->trees);
		$this->assertGreaterThan(0, $report->bytes);
		$this->assertSame(Finding::INFO, $report->worst());
		$this->assertSame([], $this->ledgerCodes());
	}

	#[Test]
	#[TestDox('a ref that does not exist yet verifies as an empty pass rather than failing')]
	#[Group('strata/verify')]
	public function emptyHistoryVerifies(): void
	{
		$report = $this->engine()->verifier()->verify();

		$this->assertTrue($report->isClean());
		$this->assertSame(0, $report->commits);
		$this->assertStringContainsString('0 frames', $report->summary());
	}

	#[Test]
	#[TestDox('a bounded pass says it did not reach the root, so clean means only what it walked')]
	#[Group('strata/verify')]
	public function boundedPassSaysSo(): void
	{
		$this->flush('bounded-one');
		$this->flush('bounded-two');
		$this->flush('bounded-three');

		$report = $this->engine()->verifier()->verify(limit: 1);

		$this->assertTrue($report->isClean());
		$this->assertFalse($report->complete);
		$this->assertSame(1, $report->commits);
		$this->assertStringContainsString('1 commits', $report->summary());
	}

	#[Test]
	#[TestDox('a shallow pass checks presence without decoding, so it reads no bytes')]
	#[Group('strata/verify')]
	public function shallowPassSkipsDecoding(): void
	{
		$this->flush('shallow');

		$report = $this->engine()->verifier()->verify(deep: false);

		$this->assertTrue($report->isClean());
		$this->assertGreaterThan(0, $report->frames);
		$this->assertSame(0, $report->bytes, 'nothing was decoded');
	}

	#[Test]
	#[TestDox('a frame shared by two commits is verified once, not once per commit')]
	#[Group('strata/verify')]
	public function sharedFramesAreVerifiedOnce(): void
	{
		$this->flush('shared-one');
		$this->user('shared-two');
		$this->flush('shared-three');

		$report = $this->engine()->verifier()->verify();
		$distinct = $this->container->get('strata.frame_index')->statistics()['frames'];

		$this->assertSame(2, $report->commits);
		$this->assertGreaterThan(2, $distinct);
		$this->assertSame(
			$distinct,
			$report->frames,
			'every frame the two commits reach is verified exactly once',
		);
	}

	#endregion

	#region What Breaks

	#[Test]
	#[TestDox('a deleted pack is reported as a missing frame, at critical')]
	#[Group('strata/verify')]
	public function deletedObjectIsReported(): void
	{
		$this->flush('deleted');

		$packs = $this->keys('packs/');
		$this->assertNotEmpty($packs);
		$this->engine()
			->provider()
			->delete([$packs[0]]);

		$report = $this->engine()->verifier()->verify();

		$this->assertFalse($report->isClean());
		$this->assertArrayHasKey('frame.missing', $report->byCode());
		$this->assertSame(Finding::CRITICAL, $report->worst());
		$this->assertContains('frame.missing', $this->ledgerCodes());
	}

	#[Test]
	#[
		TestDox(
			'a flipped byte inside a pack is reported as content that does not match its address',
		),
	]
	#[Group('strata/verify')]
	public function corruptedFrameIsReported(): void
	{
		$this->flush('corrupted');

		$packs = $this->keys('packs/');
		$this->assertNotEmpty($packs);

		$bytes = $this->engine()->provider()->get($packs[0]);
		$bytes[0] = $bytes[0] === 'x' ? 'y' : 'x';
		$this->engine()->provider()->put($packs[0], $bytes);

		$report = $this->engine()->verifier()->verify();

		$this->assertFalse($report->isClean());
		$this->assertArrayHasKey('frame.hash_mismatch', $report->byCode());
		$this->assertSame(Finding::CRITICAL, $report->worst());
	}

	#[Test]
	#[TestDox('a truncated segment is reported without hiding the frames it named')]
	#[Group('strata/verify')]
	public function truncatedSegmentIsReported(): void
	{
		$this->flush('truncated');

		$segments = $this->keys('segments/');
		$this->assertNotEmpty($segments);
		$this->engine()->provider()->put($segments[0], 'not a segment at all');

		$report = $this->engine()->verifier()->verify();

		$this->assertFalse($report->isClean());
		$this->assertArrayHasKey('segment.truncated', $report->byCode());
		$this->assertGreaterThan(0, $report->frames, 'the tree was still walked');
	}

	#[Test]
	#[TestDox('a missing parent commit stops the walk and says history is truncated')]
	#[Group('strata/verify')]
	public function missingParentIsReported(): void
	{
		$this->flush('parent-one');
		$head = $this->engine()->commitLog()->head();
		$this->flush('parent-two');

		$this->assertNotNull($head);
		$this->engine()
			->provider()
			->delete([
				'commits/' .
				substr((string) $head->id(), 0, 2) .
				'/' .
				substr((string) $head->id(), 2, 2) .
				'/' .
				$head->id(),
			]);
		$this->engine()->commitLog()->flushCache();

		$report = $this->engine()->verifier()->verify();

		$this->assertFalse($report->isClean());
		$this->assertArrayHasKey('commit.parent_missing', $report->byCode());
	}

	#[Test]
	#[
		TestDox(
			'an index with no row for a referenced frame is reported as unindexed, not as missing',
		),
	]
	#[Group('strata/verify')]
	public function unindexedFrameIsReported(): void
	{
		$this->flush('unindexed');

		$this->container->get('strata.frame_index')->clear();

		$report = $this->engine()->verifier()->verify();

		$this->assertFalse($report->isClean());
		$this->assertArrayHasKey('frame.unindexed', $report->byCode());
		$this->assertArrayNotHasKey('frame.missing', $report->byCode());
		$this->assertSame(0, $report->frames, 'nothing could be located to verify');
	}

	#endregion

	#region The Ledger

	#[Test]
	#[TestDox('the same symptom seen twice is one open finding, not two')]
	#[Group('strata/verify')]
	public function repeatedFindingsCollapse(): void
	{
		$this->flush('repeated');

		$packs = $this->keys('packs/');
		$this->engine()
			->provider()
			->delete([$packs[0]]);

		$this->engine()->verifier()->verify();
		$before = count($this->container->get('strata.health_ledger')->open());

		$this->engine()->verifier()->verify();
		$after = count($this->container->get('strata.health_ledger')->open());

		$this->assertSame($before, $after);
		$this->assertGreaterThan(0, $before);
	}

	#[Test]
	#[TestDox('a resolved finding leaves the open list and the summary')]
	#[Group('strata/verify')]
	public function resolvingClearsTheOpenList(): void
	{
		$this->flush('resolvable');

		$packs = $this->keys('packs/');
		$this->engine()
			->provider()
			->delete([$packs[0]]);

		$report = $this->engine()->verifier()->verify();
		$ledger = $this->container->get('strata.health_ledger');

		$this->assertNotEmpty($ledger->open());

		foreach ($report->findings as $finding) {
			$ledger->resolve($finding->code, $finding->scope);
		}

		$this->assertSame([], $ledger->open());
		$this->assertSame([], $ledger->summary());
	}

	#[Test]
	#[TestDox('the summary groups open findings by code with a scope count')]
	#[Group('strata/verify')]
	public function summaryGroupsByCode(): void
	{
		$this->flush('summarised');

		$packs = $this->keys('packs/');
		$this->engine()
			->provider()
			->delete([$packs[0]]);
		$this->engine()->verifier()->verify();

		$summary = $this->container->get('strata.health_ledger')->summary();

		$this->assertNotEmpty($summary);
		$this->assertSame('frame.missing', $summary[0]['code']);
		$this->assertSame(Finding::CRITICAL, $summary[0]['severity']);
		$this->assertGreaterThan(0, $summary[0]['scopes']);
		$this->assertSame('quarantine', $summary[0]['rung'], 'critical starts above automatic');
	}

	#endregion

	#region The Local Index

	#[Test]
	#[TestDox('a flush mirrors its commit into the local index')]
	#[Group('strata/verify')]
	public function flushRecordsTheCommitLocally(): void
	{
		$this->flush('indexed');

		$head = $this->engine()->commitLog()->head();
		$this->assertNotNull($head);

		$row = $this->commitIndex()->get($head->id());

		$this->assertNotNull($row);
		$this->assertSame($head->index, $row['index_ref']);
		$this->assertSame($head->chain, (int) $row['chain']);
		$this->assertSame($head->anchoredAt, (int) $row['anchored_at']);
		$this->assertSame($head->microtime, (int) $row['microtime']);
		$this->assertSame(1, (int) $row['is_base']);
		$this->assertNotNull($row['segment_key']);
		$this->assertSame(1, $this->commitIndex()->count());
	}

	#[Test]
	#[TestDox('the newest indexed commit is the one the ref points at')]
	#[Group('strata/verify')]
	public function newestMatchesTheHead(): void
	{
		$this->flush('newest-one');
		$this->flush('newest-two');

		$head = $this->engine()->commitLog()->head();
		$newest = $this->commitIndex()->newest();

		$this->assertNotNull($newest);
		$this->assertSame($head?->id(), $newest['id']);
		$this->assertSame(2, $this->commitIndex()->count());
	}

	#endregion
}
