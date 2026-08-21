<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Health;

use Drupal\strata\Health\Finding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use TypeError;

#[CoversClass(Finding::class)]
class FindingTest extends TestCase
{
	#region Fields

	#[Test]
	#[TestDox('every field is kept exactly as it was given')]
	#[Group('strata/health')]
	public function keepsEveryFieldAsGiven(): void
	{
		$finding = new Finding('frame.digest_mismatch', Finding::ERROR, 'bddd813c', 'read 4 bytes');

		$this->assertSame('frame.digest_mismatch', $finding->code);
		$this->assertSame(Finding::ERROR, $finding->severity);
		$this->assertSame('bddd813c', $finding->scope);
		$this->assertSame('read 4 bytes', $finding->context);
	}

	#[Test]
	#[TestDox('scope and context default to empty strings')]
	#[Group('strata/health')]
	public function scopeAndContextAreOptional(): void
	{
		$finding = new Finding('storage.unreachable', Finding::WARN);

		$this->assertSame('', $finding->scope);
		$this->assertSame('', $finding->context);
	}

	#[Test]
	#[TestDox('the severity ordinals are the values the ledger stores')]
	#[Group('strata/health')]
	public function severityOrdinalsAreStable(): void
	{
		$this->assertSame(0, Finding::INFO);
		$this->assertSame(1, Finding::WARN);
		$this->assertSame(2, Finding::ERROR);
		$this->assertSame(3, Finding::CRITICAL);
	}

	/**
	 * @return array<string, array{int, string}>
	 */
	public static function severityNameProvider(): array
	{
		return [
			'info' => [Finding::INFO, 'INFO'],
			'warn' => [Finding::WARN, 'WARN'],
			'error' => [Finding::ERROR, 'ERROR'],
			'critical' => [Finding::CRITICAL, 'CRITICAL'],
			'above the scale' => [99, 'UNKNOWN'],
			'below the scale' => [-1, 'UNKNOWN'],
		];
	}

	#[Test]
	#[TestDox('severityName() names $_dataName')]
	#[Group('strata/health')]
	#[DataProvider('severityNameProvider')]
	public function severityNameMapsEveryOrdinal(int $severity, string $expected): void
	{
		$this->assertSame($expected, (new Finding('a.b', $severity))->severityName());
	}

	#endregion

	#region Clamping

	/**
	 * @return array<string, array{int, int}>
	 */
	public static function contextLengthProvider(): array
	{
		return [
			'one under the cap' => [Finding::MAX_CONTEXT - 1, Finding::MAX_CONTEXT - 1],
			'exactly the cap' => [Finding::MAX_CONTEXT, Finding::MAX_CONTEXT],
			'one over the cap' => [Finding::MAX_CONTEXT + 1, Finding::MAX_CONTEXT],
			'far over the cap' => [Finding::MAX_CONTEXT * 10, Finding::MAX_CONTEXT],
		];
	}

	#[Test]
	#[TestDox('context $_dataName is clamped to MAX_CONTEXT')]
	#[Group('strata/health')]
	#[DataProvider('contextLengthProvider')]
	public function clampsContextAtTheBoundary(int $given, int $expected): void
	{
		$finding = new Finding('a.b', Finding::INFO, '', str_repeat('x', $given));

		$this->assertSame($expected, strlen($finding->context));
	}

	/**
	 * @return array<string, array{int, int}>
	 */
	public static function scopeLengthProvider(): array
	{
		return [
			'one under the cap' => [Finding::MAX_SCOPE - 1, Finding::MAX_SCOPE - 1],
			'exactly the cap' => [Finding::MAX_SCOPE, Finding::MAX_SCOPE],
			'one over the cap' => [Finding::MAX_SCOPE + 1, Finding::MAX_SCOPE],
		];
	}

	#[Test]
	#[TestDox('scope $_dataName is clamped to MAX_SCOPE')]
	#[Group('strata/health')]
	#[DataProvider('scopeLengthProvider')]
	public function clampsScopeAtTheBoundary(int $given, int $expected): void
	{
		$finding = new Finding('a.b', Finding::INFO, str_repeat('x', $given));

		$this->assertSame($expected, strlen($finding->scope));
	}

	#[Test]
	#[TestDox('a clamp never leaves half a multibyte character behind')]
	#[Group('strata/health')]
	public function clampNeverSplitsMultibyteCharacter(): void
	{
		$finding = new Finding('a.b', Finding::INFO, '', str_repeat('€', 200));

		$this->assertSame(399, strlen($finding->context));
		$this->assertSame(1, preg_match('//u', $finding->context));
		$this->assertIsString(json_encode($finding));
	}

	#[Test]
	#[TestDox('bytes that were never valid utf-8 are cut, not eaten')]
	#[Group('strata/health')]
	public function clampLeavesBrokenBytesAlone(): void
	{
		$finding = new Finding('a.b', Finding::INFO, '', str_repeat("\xFF", 500));

		$this->assertSame(Finding::MAX_CONTEXT, strlen($finding->context));
	}

	#endregion

	#region Serialization

	#[Test]
	#[TestDox('jsonSerialize() emits exactly the four stored fields')]
	#[Group('strata/health')]
	public function jsonSerializeEmitsTheFourFields(): void
	{
		$finding = new Finding('frame.orphaned', Finding::WARN, 'frames/bd', 'no manifest');

		$this->assertSame(
			[
				'code' => 'frame.orphaned',
				'severity' => Finding::WARN,
				'scope' => 'frames/bd',
				'context' => 'no manifest',
			],
			$finding->jsonSerialize(),
		);
	}

	#[Test]
	#[TestDox('json_encode() goes through jsonSerialize()')]
	#[Group('strata/health')]
	public function jsonEncodeUsesJsonSerialize(): void
	{
		$finding = new Finding('a.b', Finding::INFO, 's', 'c');

		$this->assertSame(
			'{"code":"a.b","severity":0,"scope":"s","context":"c"}',
			json_encode($finding),
		);
	}

	/**
	 * @return array<string, array{Finding}>
	 */
	public static function roundTripProvider(): array
	{
		return [
			'all fields set' => [new Finding('frame.orphaned', Finding::WARN, 'bd', 'no manifest')],
			'empty scope and context' => [new Finding('a.b', Finding::CRITICAL)],
			'already clamped' => [
				new Finding('a.b', Finding::ERROR, str_repeat('s', 400), str_repeat('c', 900)),
			],
			'multibyte' => [new Finding('a.b', Finding::INFO, 'ünï', str_repeat('€', 200))],
			'severity off the scale' => [new Finding('a.b', 42)],
		];
	}

	#[Test]
	#[TestDox('fromArray(jsonSerialize()) is identity for $_dataName')]
	#[Group('strata/health')]
	#[DataProvider('roundTripProvider')]
	public function roundTripsThroughFromArray(Finding $finding): void
	{
		$this->assertEquals($finding, Finding::fromArray($finding->jsonSerialize()));
	}

	#[Test]
	#[TestDox('a finding survives a trip through json')]
	#[Group('strata/health')]
	public function roundTripsThroughJson(): void
	{
		$finding = new Finding('storage.slow', Finding::WARN, 'r2', 'p99 4.2s');
		$decoded = json_decode((string) json_encode($finding), true);

		$this->assertIsArray($decoded);
		$this->assertEquals($finding, Finding::fromArray($decoded));
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function misspelledKeyProvider(): array
	{
		$good = ['code' => 'a.b', 'severity' => 1, 'scope' => 's', 'context' => 'c'];

		return [
			'code' => [['cod' => 'a.b'] + array_diff_key($good, ['code' => null])],
			'severity' => [['severty' => 1] + array_diff_key($good, ['severity' => null])],
			'scope' => [['scop' => 's'] + array_diff_key($good, ['scope' => null])],
			'context' => [['ctx' => 'c'] + array_diff_key($good, ['context' => null])],
			'severity as a string' => [['severity' => '1'] + $good],
			'nothing at all' => [[]],
		];
	}

	#[Test]
	#[TestDox('fromArray() refuses $_dataName rather than nulling the field')]
	#[Group('strata/health')]
	#[DataProvider('misspelledKeyProvider')]
	public function fromArrayRefusesBadKey(array $data): void
	{
		$this->expectException(TypeError::class);

		Finding::fromArray($data);
	}

	#[Test]
	#[TestDox('fromArray() re-clamps whatever it is handed')]
	#[Group('strata/health')]
	public function fromArrayReclamps(): void
	{
		$finding = Finding::fromArray([
			'code' => 'a.b',
			'severity' => Finding::INFO,
			'scope' => str_repeat('s', 500),
			'context' => str_repeat('c', 5000),
		]);

		$this->assertSame(Finding::MAX_SCOPE, strlen($finding->scope));
		$this->assertSame(Finding::MAX_CONTEXT, strlen($finding->context));
	}

	#endregion
}
