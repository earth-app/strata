<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Restore;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Restore\Preflight;
use Drupal\strata\Restore\ReplayResult;
use Drupal\strata\Restore\RestoreAudit;
use Drupal\strata\Restore\RestorePlan;
use Drupal\strata\Restore\RestoreResult;
use Drupal\strata\Restore\SubjectStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(RestorePlan::class)]
#[CoversClass(ReplayResult::class)]
#[CoversClass(RestoreResult::class)]
#[CoversClass(SubjectStatus::class)]
class RestorePlanTest extends TestCase
{
	/**
	 * A commit id to plan against.
	 */
	private static function target(): string
	{
		return Hash::of('target');
	}

	/**
	 * A result at each status, built through ReplayResult so the classification is the real one.
	 */
	private static function replay(SubjectStatus $status, string $subject): ReplayResult
	{
		return match ($status) {
			SubjectStatus::RESTORABLE => new ReplayResult($subject, true, ['name' => 'x'], 1, 1),
			SubjectStatus::DEGRADED => new ReplayResult($subject, true, ['name' => 'x'], 1, 2, [
				'commit abc: a frame is gone',
			]),
			SubjectStatus::UNRESTORABLE => ReplayResult::absent($subject, 3),
		};
	}

	#region Classification

	/**
	 * @return array<string, array{ReplayResult, SubjectStatus}>
	 */
	public static function statusProvider(): array
	{
		return [
			'a complete replay' => [
				new ReplayResult('entity/node:1', true, ['title' => 't'], 2, 2),
				SubjectStatus::RESTORABLE,
			],
			'some versions read and some lost' => [
				new ReplayResult('entity/node:2', true, ['title' => 't'], 1, 2, ['lost one']),
				SubjectStatus::DEGRADED,
			],
			'every version lost' => [
				new ReplayResult('entity/node:3', true, [], 0, 2, ['lost all']),
				SubjectStatus::UNRESTORABLE,
			],
			'a subject history never mentions' => [
				ReplayResult::absent('entity/node:4'),
				SubjectStatus::UNRESTORABLE,
			],
			'a delete with nothing lost' => [
				new ReplayResult('entity/node:5', false, [], 1, 2),
				SubjectStatus::RESTORABLE,
			],
		];
	}

	#[Test]
	#[TestDox('$_dataName classifies as expected')]
	#[Group('strata/restore')]
	#[DataProvider('statusProvider')]
	public function classification(ReplayResult $result, SubjectStatus $expected): void
	{
		$this->assertSame($expected, $result->status());
	}

	#[Test]
	#[TestDox('only a fully restorable subject is written without a human opting in')]
	#[Group('strata/restore')]
	public function onlyRestorableIsWrittenByDefault(): void
	{
		$this->assertTrue(SubjectStatus::RESTORABLE->isWrittenByDefault());
		$this->assertFalse(SubjectStatus::DEGRADED->isWrittenByDefault());
		$this->assertFalse(SubjectStatus::UNRESTORABLE->isWrittenByDefault());
	}

	#[Test]
	#[TestDox('a degraded subject still has a value, and an unrestorable one does not')]
	#[Group('strata/restore')]
	public function hasValue(): void
	{
		$this->assertTrue(SubjectStatus::RESTORABLE->hasValue());
		$this->assertTrue(SubjectStatus::DEGRADED->hasValue());
		$this->assertFalse(SubjectStatus::UNRESTORABLE->hasValue());
	}

	#[Test]
	#[TestDox('every status has a label for the confirm form')]
	#[Group('strata/restore')]
	public function everyStatusIsLabelled(): void
	{
		foreach (SubjectStatus::cases() as $status) {
			$this->assertNotSame('', $status->label());
		}
	}

	#[Test]
	#[TestDox('a replayed result serializes field names rather than field values')]
	#[Group('strata/restore')]
	public function resultSerializesNamesOnly(): void
	{
		$data = (new ReplayResult(
			'entity/node:1',
			true,
			['title' => 'secret'],
			1,
			1,
		))->jsonSerialize();

		$this->assertSame(['title'], $data['fields']);
		$this->assertStringNotContainsString('secret', (string) json_encode($data));
		$this->assertSame('restorable', $data['status']);
	}

	#endregion

	#region The Plan

	#[Test]
	#[TestDox('the counts cover every status even when nothing sits at one')]
	#[Group('strata/restore')]
	public function countsAreComplete(): void
	{
		$plan = new RestorePlan(self::target(), 4, [
			'entity/node:1' => self::replay(SubjectStatus::RESTORABLE, 'entity/node:1'),
			'entity/node:2' => self::replay(SubjectStatus::RESTORABLE, 'entity/node:2'),
		]);

		$this->assertSame(
			['restorable' => 2, 'degraded' => 0, 'unrestorable' => 0],
			$plan->counts(),
		);
		$this->assertTrue($plan->isComplete());
	}

	#[Test]
	#[TestDox('a degraded subject is not writable by default and is named in the skip list')]
	#[Group('strata/restore')]
	public function degradedIsSkipped(): void
	{
		$plan = new RestorePlan(self::target(), 2, [
			'entity/node:1' => self::replay(SubjectStatus::RESTORABLE, 'entity/node:1'),
			'entity/node:2' => self::replay(SubjectStatus::DEGRADED, 'entity/node:2'),
		]);

		$this->assertSame(['entity/node:1'], array_keys($plan->writable()));
		$this->assertArrayHasKey('entity/node:2', $plan->skipped());
		$this->assertStringContainsString('only part of it', $plan->skipped()['entity/node:2']);
		$this->assertStringContainsString(
			'1 versions unreadable',
			$plan->skipped()['entity/node:2'],
		);
		$this->assertFalse($plan->isComplete());
	}

	#[Test]
	#[TestDox('opting in makes a degraded subject writable and leaves unrestorable ones out')]
	#[Group('strata/restore')]
	public function optingInWritesDegraded(): void
	{
		$plan = new RestorePlan(
			self::target(),
			2,
			[
				'entity/node:1' => self::replay(SubjectStatus::DEGRADED, 'entity/node:1'),
				'entity/node:2' => self::replay(SubjectStatus::UNRESTORABLE, 'entity/node:2'),
			],
			[],
			true,
		);

		$this->assertSame(['entity/node:1'], array_keys($plan->writable()));
		$this->assertSame('nothing usable survives for it', $plan->skipped()['entity/node:2']);
	}

	#[Test]
	#[TestDox('a plan with nothing writable is empty even when it holds subjects')]
	#[Group('strata/restore')]
	public function planWithNothingWritableIsEmpty(): void
	{
		$plan = new RestorePlan(self::target(), 1, [
			'entity/node:1' => self::replay(SubjectStatus::UNRESTORABLE, 'entity/node:1'),
		]);

		$this->assertTrue($plan->isEmpty());
		$this->assertFalse($plan->isComplete());
		$this->assertCount(1, $plan->skipped());
	}

	#[Test]
	#[TestDox('a plan carrying a problem is never complete, however clean its subjects are')]
	#[Group('strata/restore')]
	public function problemsBlockCompleteness(): void
	{
		$plan = new RestorePlan(
			self::target(),
			1,
			['entity/node:1' => self::replay(SubjectStatus::RESTORABLE, 'entity/node:1')],
			['the target commit does not read'],
		);

		$this->assertFalse($plan->isComplete());
		$this->assertFalse($plan->isEmpty(), 'the writable subject is still writable');
	}

	#[Test]
	#[TestDox('subjects can be filtered to one status')]
	#[Group('strata/restore')]
	public function filterByStatus(): void
	{
		$plan = new RestorePlan(self::target(), 1, [
			'entity/node:1' => self::replay(SubjectStatus::RESTORABLE, 'entity/node:1'),
			'entity/node:2' => self::replay(SubjectStatus::DEGRADED, 'entity/node:2'),
			'entity/node:3' => self::replay(SubjectStatus::DEGRADED, 'entity/node:3'),
		]);

		$this->assertCount(1, $plan->withStatus(SubjectStatus::RESTORABLE));
		$this->assertCount(2, $plan->withStatus(SubjectStatus::DEGRADED));
		$this->assertCount(0, $plan->withStatus(SubjectStatus::UNRESTORABLE));
	}

	#[Test]
	#[TestDox('the summary names all three counts and what would be written')]
	#[Group('strata/restore')]
	public function summaryNamesEveryCount(): void
	{
		$summary = (new RestorePlan(self::target(), 7, [
			'entity/node:1' => self::replay(SubjectStatus::RESTORABLE, 'entity/node:1'),
			'entity/node:2' => self::replay(SubjectStatus::DEGRADED, 'entity/node:2'),
			'entity/node:3' => self::replay(SubjectStatus::UNRESTORABLE, 'entity/node:3'),
		]))->summary();

		$this->assertStringContainsString('1 restorable', $summary);
		$this->assertStringContainsString('1 degraded', $summary);
		$this->assertStringContainsString('1 unrestorable', $summary);
		$this->assertStringContainsString('7 commits', $summary);
		$this->assertStringContainsString('1 would be written', $summary);
	}

	#endregion

	#region Outcomes

	#[Test]
	#[TestDox('a restore with any failed subject is failed, however much of it worked')]
	#[Group('strata/restore')]
	public function anyFailureFailsTheRestore(): void
	{
		$result = new RestoreResult(
			self::target(),
			Hash::of('snapshot'),
			['entity/node:1', 'entity/node:2'],
			[],
			['entity/node:3' => 'a constraint rejected it'],
		);

		$this->assertSame(RestoreAudit::FAILED, $result->outcome());
		$this->assertStringContainsString('1 failed to write', $result->summary());
		$this->assertStringContainsString('undo with', $result->summary());
	}

	#[Test]
	#[TestDox('a restore that wrote everything it planned is a success')]
	#[Group('strata/restore')]
	public function cleanRestoreSucceeds(): void
	{
		$result = new RestoreResult(
			self::target(),
			null,
			['entity/node:1'],
			['entity/node:2' => 'x'],
		);

		$this->assertSame(RestoreAudit::SUCCEEDED, $result->outcome());
		$this->assertFalse($result->wasRefused());
		$this->assertStringContainsString('restored 1 subjects, skipped 1', $result->summary());
		$this->assertStringNotContainsString('undo with', $result->summary());
	}

	#[Test]
	#[TestDox('a refusal carries its reason and reports as refused')]
	#[Group('strata/restore')]
	public function refusalReportsAsRefused(): void
	{
		$result = RestoreResult::refuse(self::target(), 'the snapshot could not be taken');

		$this->assertTrue($result->wasRefused());
		$this->assertSame(RestoreAudit::REFUSED, $result->outcome());
		$this->assertSame([], $result->restored);
		$this->assertStringContainsString('snapshot could not be taken', $result->summary());
	}

	#endregion

	#region Realms

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function realmProvider(): array
	{
		return [
			'an entity' => ['entity/node:1', true],
			'config' => ['config/system.site', true],
			'a table row' => ['table/users_field_data:1', true],
			'ephemeral state' => ['ephemeral/cache:page', false],
			'a realm this release does not know' => ['future/thing:1', true],
			'a path with no realm' => ['bare-subject', true],
		];
	}

	#[Test]
	#[TestDox('$_dataName is decided correctly for a restore')]
	#[Group('strata/restore')]
	#[DataProvider('realmProvider')]
	public function realmExclusion(string $subject, bool $expected): void
	{
		$this->assertSame($expected, Preflight::isRestorableRealm($subject));
	}

	#endregion
}
