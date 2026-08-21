<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Budget;

use Drupal\strata\Budget\EscalationLadder;
use Drupal\strata\Journal\Realm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(EscalationLadder::class)]
class EscalationLadderTest extends TestCase
{
	#region Shape

	#[Test]
	#[TestDox('the rungs are ordered cheapest first')]
	#[Group('strata/budget')]
	public function rungsAreOrderedLowestFirst(): void
	{
		$this->assertSame(['normal', 'warn', 'reduce', 'pause', 'stop'], EscalationLadder::RUNGS);
	}

	#[Test]
	#[TestDox('the thresholds climb with the rungs they open')]
	#[Group('strata/budget')]
	public function thresholdsAreOrdered(): void
	{
		$this->assertLessThan(EscalationLadder::REDUCE_AT, EscalationLadder::WARN_AT);
		$this->assertLessThan(EscalationLadder::PAUSE_AT, EscalationLadder::REDUCE_AT);
		$this->assertLessThan(EscalationLadder::STOP_AT, EscalationLadder::PAUSE_AT);
		$this->assertSame(1.0, EscalationLadder::REDUCE_AT);
	}

	/**
	 * @return array<string, array{string, int}>
	 */
	public static function rankProvider(): array
	{
		return [
			'normal' => ['normal', 0],
			'warn' => ['warn', 1],
			'reduce' => ['reduce', 2],
			'pause' => ['pause', 3],
			'stop' => ['stop', 4],
			'not a rung' => ['halt', EscalationLadder::UNRANKED],
			'empty' => ['', EscalationLadder::UNRANKED],
			'wrong case' => ['Normal', EscalationLadder::UNRANKED],
		];
	}

	#[Test]
	#[TestDox('rank() places $_dataName')]
	#[Group('strata/budget')]
	#[DataProvider('rankProvider')]
	public function rankIsThePositionOnTheLadder(string $rung, int $expected): void
	{
		$this->assertSame($expected, EscalationLadder::rank($rung));
	}

	#[Test]
	#[TestDox('the paused realms are real journal realms')]
	#[Group('strata/budget')]
	public function pausedRealmsAreJournalRealms(): void
	{
		foreach (EscalationLadder::PAUSED_REALMS as $realm) {
			$this->assertNotNull(Realm::tryFrom($realm));
		}

		$this->assertSame(['file', 'ephemeral', 'code'], EscalationLadder::PAUSED_REALMS);
	}

	#endregion

	#region Entry

	/**
	 * @return array<string, array{float, string}>
	 */
	public static function rungForProvider(): array
	{
		return [
			'nothing used' => [0.0, 'normal'],
			'a negative reading' => [-1.0, 'normal'],
			'half the budget' => [0.5, 'normal'],
			'just under the soft line' => [0.79, 'normal'],
			'exactly the soft line' => [0.8, 'warn'],
			'just under the ceiling' => [0.99, 'warn'],
			'exactly the ceiling' => [1.0, 'reduce'],
			'just under a quarter over' => [1.24, 'reduce'],
			'exactly a quarter over' => [1.25, 'pause'],
			'just under half over' => [1.49, 'pause'],
			'exactly half over' => [1.5, 'stop'],
			'ten times over' => [10.0, 'stop'],
		];
	}

	#[Test]
	#[TestDox('rungFor(): $_dataName')]
	#[Group('strata/budget')]
	#[DataProvider('rungForProvider')]
	public function rungForMapsEveryFraction(float $usedFraction, string $expected): void
	{
		$this->assertSame($expected, EscalationLadder::rungFor($usedFraction));
	}

	#[Test]
	#[TestDox('rungFor() never leaves the ladder')]
	#[Group('strata/budget')]
	public function rungForAlwaysReturnsRung(): void
	{
		foreach ([-99.0, 0.0, 0.8, 1.0, 1.25, 1.5, 1_000.0] as $fraction) {
			$this->assertContains(EscalationLadder::rungFor($fraction), EscalationLadder::RUNGS);
		}
	}

	#endregion

	#region Movement

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function escalateProvider(): array
	{
		return [
			'normal' => ['normal', 'warn'],
			'warn' => ['warn', 'reduce'],
			'reduce' => ['reduce', 'pause'],
			'pause' => ['pause', 'stop'],
			'stop saturates' => ['stop', 'stop'],
			'an unknown rung falls to the bottom' => ['halt', 'normal'],
		];
	}

	#[Test]
	#[TestDox('escalate(): $_dataName')]
	#[Group('strata/budget')]
	#[DataProvider('escalateProvider')]
	public function escalateMovesOneRungUp(string $rung, string $expected): void
	{
		$this->assertSame($expected, EscalationLadder::escalate($rung));
	}

	/**
	 * @return array<string, array{string, string|null}>
	 */
	public static function decayProvider(): array
	{
		return [
			'stop' => ['stop', 'pause'],
			'pause' => ['pause', 'reduce'],
			'reduce' => ['reduce', 'warn'],
			'warn' => ['warn', 'normal'],
			'normal stops tracking' => ['normal', null],
			'an unknown rung stops tracking' => ['halt', null],
		];
	}

	#[Test]
	#[TestDox('decay(): $_dataName')]
	#[Group('strata/budget')]
	#[DataProvider('decayProvider')]
	public function decayMovesOneRungDown(string $rung, ?string $expected): void
	{
		$this->assertSame($expected, EscalationLadder::decay($rung));
	}

	#[Test]
	#[TestDox('escalate() then decay() returns to the same rung')]
	#[Group('strata/budget')]
	public function escalateAndDecayAreInverses(): void
	{
		foreach (['normal', 'warn', 'reduce', 'pause'] as $rung) {
			$this->assertSame($rung, EscalationLadder::decay(EscalationLadder::escalate($rung)));
		}
	}

	#[Test]
	#[TestDox('repeated escalation stops at stop instead of running off the ladder')]
	#[Group('strata/budget')]
	public function escalationSaturates(): void
	{
		$rung = 'normal';
		for ($i = 0; $i < 20; $i++) {
			$rung = EscalationLadder::escalate($rung);
		}

		$this->assertSame('stop', $rung);
	}

	#endregion

	#region Realms

	#[Test]
	#[TestDox('every realm is checked against every rung')]
	#[Group('strata/budget')]
	public function pausesRealmForEveryRealmAtEveryRung(): void
	{
		foreach (Realm::cases() as $realm) {
			$this->assertFalse(EscalationLadder::pausesRealm('normal', $realm->value));
			$this->assertFalse(EscalationLadder::pausesRealm('warn', $realm->value));
			$this->assertFalse(EscalationLadder::pausesRealm('reduce', $realm->value));
			$this->assertSame(
				in_array($realm->value, EscalationLadder::PAUSED_REALMS, true),
				EscalationLadder::pausesRealm('pause', $realm->value),
			);
			$this->assertTrue(EscalationLadder::pausesRealm('stop', $realm->value));
		}
	}

	/**
	 * @return array<string, array{Realm}>
	 */
	public static function criticalRealmProvider(): array
	{
		return [
			'entity' => [Realm::ENTITY],
			'config' => [Realm::CONFIG],
			'state' => [Realm::STATE],
			'key-value' => [Realm::KEY_VALUE],
			'table' => [Realm::TABLE],
			'schema' => [Realm::SCHEMA],
		];
	}

	#[Test]
	#[TestDox('the $_dataName realm keeps being captured at the pause rung')]
	#[Group('strata/budget')]
	#[DataProvider('criticalRealmProvider')]
	public function keepsTheCriticalRealmsWhilePaused(Realm $realm): void
	{
		$this->assertFalse(EscalationLadder::pausesRealm('pause', $realm->value));
		$this->assertTrue(EscalationLadder::pausesRealm('stop', $realm->value));
	}

	/**
	 * @return array<string, array{Realm}>
	 */
	public static function rebuildableRealmProvider(): array
	{
		return [
			'file' => [Realm::FILE],
			'ephemeral' => [Realm::EPHEMERAL],
			'code' => [Realm::CODE],
		];
	}

	#[Test]
	#[TestDox('the $_dataName realm stops being captured at the pause rung')]
	#[Group('strata/budget')]
	#[DataProvider('rebuildableRealmProvider')]
	public function dropsTheRebuildableRealmsWhilePaused(Realm $realm): void
	{
		$this->assertFalse(EscalationLadder::pausesRealm('reduce', $realm->value));
		$this->assertTrue(EscalationLadder::pausesRealm('pause', $realm->value));
		$this->assertTrue(EscalationLadder::pausesRealm('stop', $realm->value));
	}

	#[Test]
	#[TestDox('stopsEverything() is true at stop and nowhere else')]
	#[Group('strata/budget')]
	public function stopsEverythingOnlyAtTheTop(): void
	{
		foreach (EscalationLadder::RUNGS as $rung) {
			$this->assertSame($rung === 'stop', EscalationLadder::stopsEverything($rung));
		}

		$this->assertFalse(EscalationLadder::stopsEverything('halt'));
	}

	#[Test]
	#[TestDox('a rung nothing on the ladder produced suspends nothing')]
	#[Group('strata/budget')]
	public function unknownRungConstrainsNothing(): void
	{
		foreach (Realm::cases() as $realm) {
			$this->assertFalse(EscalationLadder::pausesRealm('halt', $realm->value));
			$this->assertFalse(EscalationLadder::pausesRealm('', $realm->value));
		}
	}

	#[Test]
	#[TestDox('a realm nothing captures is still stopped at the stop rung')]
	#[Group('strata/budget')]
	public function stopCoversRealmsItDoesNotKnow(): void
	{
		$this->assertTrue(EscalationLadder::pausesRealm('stop', 'invented'));
		$this->assertFalse(EscalationLadder::pausesRealm('pause', 'invented'));
	}

	#endregion
}
