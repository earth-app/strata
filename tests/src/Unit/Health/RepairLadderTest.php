<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Health;

use Drupal\strata\Health\Finding;
use Drupal\strata\Health\RepairLadder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(RepairLadder::class)]
class RepairLadderTest extends TestCase
{
	#region Shape

	#[Test]
	#[TestDox('the rungs are ordered cheapest first')]
	#[Group('strata/health')]
	public function rungsAreOrderedLowestFirst(): void
	{
		$this->assertSame(
			['observe', 'reindex', 'refetch', 'rebuild', 'quarantine', 'refuse'],
			RepairLadder::RUNGS,
		);
	}

	/**
	 * @return array<string, array{string, int}>
	 */
	public static function rankProvider(): array
	{
		return [
			'observe' => ['observe', 0],
			'reindex' => ['reindex', 1],
			'refetch' => ['refetch', 2],
			'rebuild' => ['rebuild', 3],
			'quarantine' => ['quarantine', 4],
			'refuse' => ['refuse', 5],
			'not a rung' => ['reboot', RepairLadder::UNRANKED],
			'empty' => ['', RepairLadder::UNRANKED],
			'wrong case' => ['Observe', RepairLadder::UNRANKED],
		];
	}

	#[Test]
	#[TestDox('rank() places $_dataName')]
	#[Group('strata/health')]
	#[DataProvider('rankProvider')]
	public function rankIsThePositionOnTheLadder(string $rung, int $expected): void
	{
		$this->assertSame($expected, RepairLadder::rank($rung));
	}

	#endregion

	#region Entry

	/**
	 * @return array<string, array{int, string}>
	 */
	public static function initialRungProvider(): array
	{
		return [
			'info' => [Finding::INFO, 'observe'],
			'warn' => [Finding::WARN, 'observe'],
			'error' => [Finding::ERROR, 'reindex'],
			'critical' => [Finding::CRITICAL, 'quarantine'],
			'above critical' => [99, 'quarantine'],
			'below info' => [-1, 'observe'],
		];
	}

	#[Test]
	#[TestDox('a $_dataName finding starts on the right rung')]
	#[Group('strata/health')]
	#[DataProvider('initialRungProvider')]
	public function initialRungMapsEverySeverity(int $severity, string $expected): void
	{
		$this->assertSame($expected, RepairLadder::initialRung($severity));
	}

	#endregion

	#region Movement

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function escalateProvider(): array
	{
		return [
			'observe' => ['observe', 'reindex'],
			'reindex' => ['reindex', 'refetch'],
			'refetch' => ['refetch', 'rebuild'],
			'rebuild' => ['rebuild', 'quarantine'],
			'quarantine' => ['quarantine', 'refuse'],
			'refuse saturates' => ['refuse', 'refuse'],
			'an unknown rung falls to the bottom' => ['reboot', 'observe'],
		];
	}

	#[Test]
	#[TestDox('escalate(): $_dataName')]
	#[Group('strata/health')]
	#[DataProvider('escalateProvider')]
	public function escalateMovesOneRungUp(string $rung, string $expected): void
	{
		$this->assertSame($expected, RepairLadder::escalate($rung));
	}

	/**
	 * @return array<string, array{string, string|null}>
	 */
	public static function decayProvider(): array
	{
		return [
			'refuse' => ['refuse', 'quarantine'],
			'quarantine' => ['quarantine', 'rebuild'],
			'rebuild' => ['rebuild', 'refetch'],
			'refetch' => ['refetch', 'reindex'],
			'reindex' => ['reindex', 'observe'],
			'observe stops tracking' => ['observe', null],
			'an unknown rung stops tracking' => ['reboot', null],
		];
	}

	#[Test]
	#[TestDox('decay(): $_dataName')]
	#[Group('strata/health')]
	#[DataProvider('decayProvider')]
	public function decayMovesOneRungDown(string $rung, ?string $expected): void
	{
		$this->assertSame($expected, RepairLadder::decay($rung));
	}

	#[Test]
	#[TestDox('escalate() then decay() returns to the same rung')]
	#[Group('strata/health')]
	public function escalateAndDecayAreInverses(): void
	{
		foreach (['observe', 'reindex', 'refetch', 'rebuild', 'quarantine'] as $rung) {
			$this->assertSame($rung, RepairLadder::decay(RepairLadder::escalate($rung)));
		}
	}

	#[Test]
	#[TestDox('repeated escalation stops at refuse instead of running off the ladder')]
	#[Group('strata/health')]
	public function escalationSaturates(): void
	{
		$rung = 'observe';
		for ($i = 0; $i < 20; $i++) {
			$rung = RepairLadder::escalate($rung);
		}

		$this->assertSame('refuse', $rung);
	}

	#endregion

	#region Automation

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function automaticProvider(): array
	{
		return [
			'observe' => ['observe', true],
			'reindex' => ['reindex', true],
			'refetch' => ['refetch', true],
			'rebuild' => ['rebuild', true],
			'quarantine' => ['quarantine', false],
			'refuse' => ['refuse', false],
			'an unknown rung fails closed' => ['reboot', false],
			'an empty rung fails closed' => ['', false],
		];
	}

	#[Test]
	#[TestDox('isAutomatic(): $_dataName')]
	#[Group('strata/health')]
	#[DataProvider('automaticProvider')]
	public function isAutomaticForEveryRung(string $rung, bool $expected): void
	{
		$this->assertSame($expected, RepairLadder::isAutomatic($rung));
	}

	#[Test]
	#[TestDox('the automatic ceiling sits directly below quarantine')]
	#[Group('strata/health')]
	public function automaticCeilingIsBelowQuarantine(): void
	{
		$this->assertSame(
			RepairLadder::rank('quarantine') - 1,
			RepairLadder::rank(RepairLadder::AUTOMATIC_CEILING),
		);
	}

	#[Test]
	#[TestDox('nothing a critical finding starts at runs unattended')]
	#[Group('strata/health')]
	public function aCriticalFindingNeverStartsAutomatic(): void
	{
		$this->assertFalse(RepairLadder::isAutomatic(RepairLadder::initialRung(Finding::CRITICAL)));
	}

	#endregion
}
