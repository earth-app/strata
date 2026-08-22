<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Capture;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\strata\Capture\CaptureScope;
use Drupal\strata\Journal\Realm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Covers the gate every mutation passes through, on the settings a site can actually hold.
 *
 * The scope is read once per request and held, so a wrong answer here is a wrong answer for every
 * capture in that request. What this lane proves is the degenerate configurations: a fresh install
 * with nothing switched on, capture switched off with every realm still ticked, and every realm
 * switched off with the statement tap still running - which is deliberate and the one case that
 * surprises a reader.
 */
#[CoversClass(CaptureScope::class)]
class CaptureScopeTest extends TestCase
{
	#region Fixtures

	/**
	 * A scope over one settings array, using the real class rather than a subclass.
	 *
	 * @param array<string, mixed> $settings
	 *   What `strata.settings` holds.
	 *
	 * @return CaptureScope
	 *   The scope under test.
	 */
	private function scope(array $settings): CaptureScope
	{
		$config = $this->createMock(ImmutableConfig::class);
		$config->method('getRawData')->willReturn($settings);

		$factory = $this->createMock(ConfigFactoryInterface::class);
		$factory->method('get')->willReturn($config);

		return new CaptureScope($factory);
	}

	#endregion

	#region Nothing Configured

	#[Test]
	#[TestDox('a fresh install with no settings at all captures nothing rather than everything')]
	#[Group('strata/capture')]
	public function emptySettingsCaptureNothing(): void
	{
		$scope = $this->scope([]);

		$this->assertFalse($scope->isEnabled());
		$this->assertFalse($scope->tapsStatements());
		$this->assertFalse($scope->coversEntityType('node'));
		$this->assertFalse($scope->coversTable('node_field_data'));

		foreach (Realm::cases() as $realm) {
			$this->assertFalse($scope->covers($realm), $realm->value . ' must be off by default');
		}
	}

	#[Test]
	#[TestDox('capture switched off overrides every realm that is still ticked')]
	#[Group('strata/capture')]
	public function disabledCaptureOverridesEveryRealm(): void
	{
		$capture = ['statements' => true];

		foreach (Realm::cases() as $realm) {
			$capture[$realm->value] = true;
		}

		$scope = $this->scope(['enabled' => false, 'capture' => $capture]);

		$this->assertFalse($scope->tapsStatements());

		foreach (Realm::cases() as $realm) {
			$this->assertFalse($scope->covers($realm), $realm->value . ' must stay off');
		}
	}

	#[Test]
	#[TestDox('every realm switched off still leaves the statement tap running when it is on')]
	#[Group('strata/capture')]
	public function everyRealmOffStillTapsStatements(): void
	{
		$scope = $this->scope(['enabled' => true, 'capture' => ['statements' => true]]);

		foreach (Realm::cases() as $realm) {
			$this->assertFalse($scope->covers($realm));
		}

		// the tap is the only driver-agnostic write seam, so it is switched separately on purpose
		$this->assertTrue($scope->tapsStatements());
	}

	#endregion

	#region What Is Never Captured

	/**
	 * @return array<string, array{string}>
	 */
	public static function excludedEntityTypeProvider(): array
	{
		return [
			'a commit' => ['strata_commit'],
			'a frame' => ['strata_frame'],
		];
	}

	#[Test]
	#[TestDox('$_dataName is never captured, since recording it would feed on itself')]
	#[Group('strata/capture')]
	#[DataProvider('excludedEntityTypeProvider')]
	public function ownEntityTypesAreNeverCaptured(string $entityTypeId): void
	{
		$scope = $this->scope(['enabled' => true, 'capture' => ['entity' => true]]);

		$this->assertTrue($scope->coversEntityType('node'));
		$this->assertFalse($scope->coversEntityType($entityTypeId));
	}

	#[Test]
	#[TestDox('a table this module owns is never captured, whatever the table realm says')]
	#[Group('strata/capture')]
	public function ownTablesAreNeverCaptured(): void
	{
		$scope = $this->scope(['enabled' => true, 'capture' => ['table' => true]]);

		$this->assertTrue($scope->coversTable('node_field_data'));
		$this->assertFalse($scope->coversTable('strata_journal'));
		$this->assertFalse($scope->coversTable(CaptureScope::OWN_PREFIX));

		// the prefix is matched at the start only, so a site table merely containing it is captured
		$this->assertTrue($scope->coversTable('my_strata_notes'));
	}

	#[Test]
	#[TestDox('only the default connection target is captured, since a replica sees no writes')]
	#[Group('strata/capture')]
	public function onlyTheDefaultTargetIsCaptured(): void
	{
		$scope = $this->scope(['enabled' => true, 'capture' => ['table' => true]]);

		$this->assertTrue($scope->coversTarget('default'));
		$this->assertFalse($scope->coversTarget('replica'));
		$this->assertFalse($scope->coversTarget(''));
	}

	#endregion

	#region Access Churn

	/**
	 * @return array<string, array{mixed, string}>
	 */
	public static function churnModeProvider(): array
	{
		return [
			'a full field delta' => ['delta', 'delta'],
			'a compact event' => ['event', 'event'],
			'dropping it' => ['drop', 'drop'],
			'nothing configured' => [null, 'event'],
			'a mode this release does not know' => ['collapse', 'event'],
			'an empty string' => ['', 'event'],
			'a number' => [7, 'event'],
		];
	}

	#[Test]
	#[TestDox('$_dataName reads back as the mode the capture path acts on')]
	#[Group('strata/capture')]
	#[DataProvider('churnModeProvider')]
	public function churnModeFallsBackRatherThanPassingThrough(
		mixed $configured,
		string $expected,
	): void {
		$capture = $configured === null ? [] : ['access_churn' => $configured];

		$this->assertSame(
			$expected,
			$this->scope(['enabled' => true, 'capture' => $capture])->accessChurnMode(),
		);
	}

	#[Test]
	#[TestDox('the churn mode answers even with capture off, since only a capture path asks')]
	#[Group('strata/capture')]
	public function churnModeIsNotGatedOnEnabled(): void
	{
		$scope = $this->scope(['enabled' => false, 'capture' => ['access_churn' => 'drop']]);

		$this->assertFalse($scope->isEnabled());
		$this->assertSame('drop', $scope->accessChurnMode());
	}

	#endregion

	#region Resolution

	#[Test]
	#[TestDox('settings are resolved once and held, so a mutation does not pay for a config read')]
	#[Group('strata/capture')]
	public function settingsAreResolvedOnce(): void
	{
		$config = $this->createMock(ImmutableConfig::class);
		$config
			->expects($this->once())
			->method('getRawData')
			->willReturn(['enabled' => true]);

		$factory = $this->createMock(ConfigFactoryInterface::class);
		$factory->method('get')->willReturn($config);

		$scope = new CaptureScope($factory);

		$this->assertTrue($scope->isEnabled());
		$this->assertTrue($scope->isEnabled());
		$this->assertFalse($scope->covers(Realm::ENTITY));
	}

	#[Test]
	#[TestDox('resetting reads the settings again, which is what a settings form needs')]
	#[Group('strata/capture')]
	public function resetRereadsTheSettings(): void
	{
		$config = $this->createMock(ImmutableConfig::class);
		$config
			->expects($this->exactly(2))
			->method('getRawData')
			->willReturn(['enabled' => true]);

		$factory = $this->createMock(ConfigFactoryInterface::class);
		$factory->method('get')->willReturn($config);

		$scope = new CaptureScope($factory);

		$this->assertTrue($scope->isEnabled());
		$scope->reset();
		$this->assertTrue($scope->isEnabled());
	}

	#endregion
}
