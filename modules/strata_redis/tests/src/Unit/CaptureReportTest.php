<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_redis\Unit;

use Drupal\strata_redis\CaptureReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Proves no outcome reads as success when the pass did not run.
 *
 * Six outcomes reach the same value object and only three of them mean a keyspace was walked, so a
 * caller that treats "it could not look" as "it found nothing" reports an uncaptured keyspace as a
 * captured one. The lane drives every accessor on an empty report as well as a populated one, because
 * the empty one is what a fresh install produces and it is the case a summary or a ratio gets wrong.
 */
#[CoversClass(CaptureReport::class)]
class CaptureReportTest extends TestCase
{
	#region Outcomes

	/**
	 * @return array<string, array{string, bool, bool}>
	 *   Outcome keyed to whether it ran and whether it finished.
	 */
	public static function outcomeProvider(): array
	{
		return [
			'complete' => [CaptureReport::COMPLETE, true, true],
			'key limit' => [CaptureReport::KEY_LIMIT, true, false],
			'byte budget' => [CaptureReport::BYTE_BUDGET, true, false],
			'unavailable' => [CaptureReport::UNAVAILABLE, false, false],
			'realm off' => [CaptureReport::REALM_OFF, false, false],
			'failed' => [CaptureReport::FAILED, false, false],
		];
	}

	#[Test]
	#[TestDox('a $_dataName outcome reports whether it ran and whether it finished')]
	#[Group('strata/redis')]
	#[DataProvider('outcomeProvider')]
	public function outcomesAreDistinguishable(string $stopped, bool $ran, bool $complete): void
	{
		$report = new CaptureReport($stopped);

		$this->assertSame($stopped, $report->stopped);
		$this->assertSame($ran, $report->ran());
		$this->assertSame($complete, $report->isComplete());
	}

	#[Test]
	#[TestDox('every declared outcome is either one that ran or one that did not')]
	#[Group('strata/redis')]
	public function everyOutcomeIsAccountedFor(): void
	{
		$outcomes = [];

		foreach ((new ReflectionClass(CaptureReport::class))->getConstants() as $name => $value) {
			if (!is_string($value)) {
				continue;
			}

			$outcomes[$name] = $value;

			$this->assertSame(
				!in_array($value, CaptureReport::DID_NOT_RUN, true),
				(new CaptureReport($value))->ran(),
				sprintf('%s has no decision about whether it ran', $name),
			);
		}

		$this->assertCount(6, $outcomes, 'a new outcome needs a decision in this test');
		$this->assertCount(3, CaptureReport::DID_NOT_RUN);
	}

	#[Test]
	#[TestDox('an empty report is a finished pass that looked at nothing')]
	#[Group('strata/redis')]
	public function emptyReportIsAFinishedPass(): void
	{
		$report = new CaptureReport();

		$this->assertSame(CaptureReport::COMPLETE, $report->stopped);
		$this->assertSame('', $report->reason);
		$this->assertSame(0, $report->scanned);
		$this->assertSame(0, $report->captured);
		$this->assertSame(0, $report->flagged);
		$this->assertSame(0, $report->skipped);
		$this->assertSame(0, $report->bytes);
		$this->assertSame([], $report->reasons);
		$this->assertTrue($report->ran());
		$this->assertTrue($report->isComplete());
		$this->assertFalse($report->needsDecisions());
		$this->assertSame('', $report->reasonFor('anything'));
	}

	#[Test]
	#[TestDox('only a flagged key makes a pass need a decision')]
	#[Group('strata/redis')]
	public function onlyAFlaggedKeyNeedsADecision(): void
	{
		$this->assertFalse((new CaptureReport(flagged: 0))->needsDecisions());
		$this->assertTrue((new CaptureReport(flagged: 1))->needsDecisions());
	}

	#endregion

	#region Summary

	#[Test]
	#[TestDox('a finished pass summarises its counts')]
	#[Group('strata/redis')]
	public function finishedPassSummarisesItsCounts(): void
	{
		$report = new CaptureReport(
			CaptureReport::COMPLETE,
			'',
			scanned: 9,
			captured: 4,
			flagged: 2,
			skipped: 5,
			bytes: 1024,
		);

		$this->assertSame(
			'captured 4 of 9 ephemeral keys in 1024 bytes, skipped 5, 2 undecided',
			$report->summary(),
		);
	}

	#[Test]
	#[TestDox('an empty pass summarises as zero rather than as nothing')]
	#[Group('strata/redis')]
	public function emptyPassStillSummarises(): void
	{
		$this->assertSame(
			'captured 0 of 0 ephemeral keys in 0 bytes, skipped 0, 0 undecided',
			(new CaptureReport())->summary(),
		);
	}

	#[Test]
	#[TestDox('a bounded pass appends why it stopped')]
	#[Group('strata/redis')]
	public function boundedPassAppendsWhyItStopped(): void
	{
		$report = new CaptureReport(
			CaptureReport::KEY_LIMIT,
			'the pass stopped at its limit of 2 keys',
			scanned: 2,
			captured: 2,
		);

		$this->assertStringEndsWith(
			' (the pass stopped at its limit of 2 keys)',
			$report->summary(),
		);
		$this->assertStringStartsWith('captured 2 of 2 ephemeral keys', $report->summary());
	}

	#[Test]
	#[TestDox('a pass that could not run says so instead of reporting zeroes')]
	#[Group('strata/redis')]
	public function passThatCouldNotRunSaysSo(): void
	{
		$summary = (new CaptureReport(
			CaptureReport::UNAVAILABLE,
			'ext-redis is not loaded on this host',
		))->summary();

		$this->assertSame(
			'no ephemeral state was captured (unavailable): ext-redis is not loaded on this host',
			$summary,
		);
		$this->assertStringNotContainsString('captured 0 of 0', $summary);
	}

	#[Test]
	#[TestDox('a pass that could not run and gave no reason says that too')]
	#[Group('strata/redis')]
	public function passThatGaveNoReasonSaysThat(): void
	{
		$this->assertSame(
			'no ephemeral state was captured (failed): no reason was given',
			(new CaptureReport(CaptureReport::FAILED))->summary(),
		);
	}

	#endregion

	#region Reasons

	#[Test]
	#[TestDox('a reason is read back per key and an unrecorded key is empty')]
	#[Group('strata/redis')]
	public function reasonsAreReadBackPerKey(): void
	{
		$report = new CaptureReport(
			CaptureReport::COMPLETE,
			'',
			reasons: ['cache_render:x' => 'derivable', 'mystery:y' => 'undecided'],
		);

		$this->assertSame('derivable', $report->reasonFor('cache_render:x'));
		$this->assertSame('undecided', $report->reasonFor('mystery:y'));
		$this->assertSame('', $report->reasonFor('queue:never-seen'));
	}

	#endregion

	#region Serialization

	#[Test]
	#[TestDox('the serialized form carries every field and both derived flags')]
	#[Group('strata/redis')]
	public function serializedFormCarriesEveryField(): void
	{
		$report = new CaptureReport(
			CaptureReport::BYTE_BUDGET,
			'the 16 byte budget was reached at queue:jobs',
			scanned: 3,
			captured: 1,
			flagged: 1,
			skipped: 1,
			bytes: 12,
			reasons: ['queue:jobs' => 'captured verbatim'],
		);

		$this->assertSame(
			[
				'stopped' => 'byte_budget',
				'reason' => 'the 16 byte budget was reached at queue:jobs',
				'scanned' => 3,
				'captured' => 1,
				'flagged' => 1,
				'skipped' => 1,
				'bytes' => 12,
				'reasons' => ['queue:jobs' => 'captured verbatim'],
				'ran' => true,
				'complete' => false,
			],
			$report->jsonSerialize(),
		);
	}

	#[Test]
	#[TestDox('the serialized form survives a json round trip')]
	#[Group('strata/redis')]
	public function serializedFormSurvivesJson(): void
	{
		$report = new CaptureReport(
			CaptureReport::REALM_OFF,
			'ephemeral capture is switched off, so no key is captured',
		);

		$this->assertSame(
			$report->jsonSerialize(),
			json_decode(json_encode($report, JSON_THROW_ON_ERROR), true),
		);
	}

	#endregion
}
