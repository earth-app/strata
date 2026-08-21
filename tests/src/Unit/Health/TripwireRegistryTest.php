<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Health;

use Drupal\strata\Health\Finding;
use Drupal\strata\Health\TripwireInterface;
use Drupal\strata\Health\TripwireRegistry;
use Error;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(TripwireRegistry::class)]
class TripwireRegistryTest extends TestCase
{
	private static function silent(string $code): TripwireInterface
	{
		return new class ($code) implements TripwireInterface {
			public function __construct(private readonly string $code) {}

			public function code(): string
			{
				return $this->code;
			}

			public function check(array $observation): ?Finding
			{
				return null;
			}
		};
	}

	private static function firing(string $code, int $severity = Finding::WARN): TripwireInterface
	{
		return new class ($code, $severity) implements TripwireInterface {
			public function __construct(
				private readonly string $code,
				private readonly int $severity,
			) {}

			public function code(): string
			{
				return $this->code;
			}

			public function check(array $observation): ?Finding
			{
				return new Finding(
					$this->code,
					$this->severity,
					(string) ($observation['scope'] ?? ''),
					(string) ($observation['note'] ?? ''),
				);
			}
		};
	}

	private static function throwing(string $code, Throwable $error): TripwireInterface
	{
		return new class ($code, $error) implements TripwireInterface {
			public function __construct(
				private readonly string $code,
				private readonly Throwable $error,
			) {}

			public function code(): string
			{
				return $this->code;
			}

			public function check(array $observation): ?Finding
			{
				throw $this->error;
			}
		};
	}

	#region Registration

	#[Test]
	#[TestDox('a new registry holds nothing')]
	#[Group('strata/health')]
	public function startsEmpty(): void
	{
		$registry = new TripwireRegistry();

		$this->assertSame([], $registry->codes());
		$this->assertSame([], $registry->all());
		$this->assertFalse($registry->has('a.b'));
		$this->assertSame([], $registry->evaluate([]));
	}

	#[Test]
	#[TestDox('a tripwire is registered under its own code')]
	#[Group('strata/health')]
	public function registersUnderTheTripwireCode(): void
	{
		$wire = self::silent('frame.orphaned');
		$registry = new TripwireRegistry();
		$registry->register($wire);

		$this->assertTrue($registry->has('frame.orphaned'));
		$this->assertSame($wire, $registry->get('frame.orphaned'));
		$this->assertSame(['frame.orphaned' => $wire], $registry->all());
	}

	#[Test]
	#[TestDox('register() returns the registry so a set can be built in one expression')]
	#[Group('strata/health')]
	public function registrationIsFluent(): void
	{
		$registry = new TripwireRegistry();
		$returned = $registry->register(self::silent('a.b'))->register(self::silent('c.d'));

		$this->assertSame($registry, $returned);
		$this->assertSame(['a.b', 'c.d'], $registry->codes());
	}

	#[Test]
	#[TestDox('codes come back in registration order')]
	#[Group('strata/health')]
	public function codesFollowRegistrationOrder(): void
	{
		$registry = new TripwireRegistry();
		$registry->register(self::silent('z.z'));
		$registry->register(self::silent('a.a'));
		$registry->register(self::silent('m.m'));

		$this->assertSame(['z.z', 'a.a', 'm.m'], $registry->codes());
	}

	#[Test]
	#[TestDox('get() returns null for a code nothing is registered under')]
	#[Group('strata/health')]
	public function getIsNullForAnUnknownCode(): void
	{
		$this->assertNull((new TripwireRegistry())->get('a.b'));
	}

	#[Test]
	#[TestDox('a second tripwire on the same code is refused')]
	#[Group('strata/health')]
	public function refusesDuplicateCode(): void
	{
		$registry = new TripwireRegistry();
		$registry->register(self::silent('frame.orphaned'));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('already registered under "frame.orphaned"');

		$registry->register(self::firing('frame.orphaned'));
	}

	#[Test]
	#[TestDox('a duplicate registration leaves the first tripwire in place')]
	#[Group('strata/health')]
	public function aRefusedDuplicateChangesNothing(): void
	{
		$first = self::silent('frame.orphaned');
		$registry = new TripwireRegistry();
		$registry->register($first);

		try {
			$registry->register(self::firing('frame.orphaned'));
			$this->fail('a duplicate code was accepted');
		} catch (InvalidArgumentException $error) {
			$this->assertStringContainsString('already registered', $error->getMessage());
		}

		$this->assertSame($first, $registry->get('frame.orphaned'));
		$this->assertSame([], $registry->evaluate([]));
	}

	#[Test]
	#[TestDox('a tripwire with an empty code is refused')]
	#[Group('strata/health')]
	public function refusesAnEmptyCode(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('returned an empty tripwire code');

		(new TripwireRegistry())->register(self::silent(''));
	}

	#endregion

	#region Sweeping

	#[Test]
	#[TestDox('evaluate() collects only the tripwires that fired')]
	#[Group('strata/health')]
	public function evaluateCollectsWhatFired(): void
	{
		$registry = new TripwireRegistry();
		$registry->register(self::firing('a.a', Finding::WARN));
		$registry->register(self::silent('b.b'));
		$registry->register(self::firing('c.c', Finding::ERROR));

		$findings = $registry->evaluate([]);

		$this->assertCount(2, $findings);
		$this->assertSame('a.a', $findings[0]->code);
		$this->assertSame('c.c', $findings[1]->code);
	}

	#[Test]
	#[TestDox('evaluate() returns nothing when every check is satisfied')]
	#[Group('strata/health')]
	public function evaluateIsEmptyWhenNothingFired(): void
	{
		$registry = new TripwireRegistry();
		$registry->register(self::silent('a.a'))->register(self::silent('b.b'));

		$this->assertSame([], $registry->evaluate([]));
	}

	#[Test]
	#[TestDox('evaluate() hands the observation to every tripwire unchanged')]
	#[Group('strata/health')]
	public function evaluatePassesTheObservationThrough(): void
	{
		$registry = new TripwireRegistry();
		$registry->register(self::firing('a.a'))->register(self::firing('b.b'));

		$findings = $registry->evaluate(['scope' => 'frames/bd', 'note' => 'digest mismatch']);

		$this->assertCount(2, $findings);
		foreach ($findings as $finding) {
			$this->assertSame('frames/bd', $finding->scope);
			$this->assertSame('digest mismatch', $finding->context);
		}
	}

	#[Test]
	#[TestDox('findings come back in registration order')]
	#[Group('strata/health')]
	public function findingsFollowRegistrationOrder(): void
	{
		$registry = new TripwireRegistry();
		$registry->register(self::firing('z.z'));
		$registry->register(self::firing('a.a'));

		$this->assertSame(
			['z.z', 'a.a'],
			array_map(
				static fn(Finding $finding): string => $finding->code,
				$registry->evaluate([]),
			),
		);
	}

	#endregion

	#region Broken Tripwires

	/**
	 * @return array<string, array{Throwable}>
	 */
	public static function thrownProvider(): array
	{
		return [
			'an exception' => [new RuntimeException('storage went away')],
			'a logic error' => [new LogicException('impossible state')],
			'an engine error' => [new Error('Call to a member function on null')],
		];
	}

	#[Test]
	#[TestDox('$_dataName thrown by a tripwire becomes a tripwire.failed finding')]
	#[Group('strata/health')]
	#[DataProvider('thrownProvider')]
	public function aThrowingTripwireBecomesFinding(Throwable $error): void
	{
		$registry = new TripwireRegistry();
		$registry->register(self::throwing('frame.digest_mismatch', $error));

		$findings = $registry->evaluate([]);

		$this->assertCount(1, $findings);
		$this->assertSame(TripwireRegistry::FAILED, $findings[0]->code);
		$this->assertSame(Finding::ERROR, $findings[0]->severity);
		$this->assertSame('frame.digest_mismatch', $findings[0]->scope);
		$this->assertStringContainsString($error::class, $findings[0]->context);
		$this->assertStringContainsString($error->getMessage(), $findings[0]->context);
	}

	#[Test]
	#[TestDox('the failure code is stable')]
	#[Group('strata/health')]
	public function theFailureCodeIsStable(): void
	{
		$this->assertSame('tripwire.failed', TripwireRegistry::FAILED);
	}

	#[Test]
	#[TestDox('a throwing tripwire does not abort the sweep')]
	#[Group('strata/health')]
	public function aThrowingTripwireDoesNotAbortTheSweep(): void
	{
		$registry = new TripwireRegistry();
		$registry->register(self::firing('a.a'));
		$registry->register(self::throwing('b.b', new RuntimeException('boom')));
		$registry->register(self::firing('c.c'));
		$registry->register(self::silent('d.d'));

		$findings = $registry->evaluate([]);

		$this->assertSame(
			['a.a', TripwireRegistry::FAILED, 'c.c'],
			array_map(static fn(Finding $finding): string => $finding->code, $findings),
		);
		$this->assertSame('b.b', $findings[1]->scope);
	}

	#[Test]
	#[TestDox('several broken tripwires each report once')]
	#[Group('strata/health')]
	public function everyBrokenTripwireReports(): void
	{
		$registry = new TripwireRegistry();
		$registry->register(self::throwing('a.a', new RuntimeException('first')));
		$registry->register(self::throwing('b.b', new RuntimeException('second')));

		$findings = $registry->evaluate([]);

		$this->assertCount(2, $findings);
		$this->assertSame(
			['a.a', 'b.b'],
			array_map(static fn(Finding $finding): string => $finding->scope, $findings),
		);
	}

	#[Test]
	#[TestDox('a long message from a broken tripwire is clamped, not dropped')]
	#[Group('strata/health')]
	public function aLongThrownMessageIsClamped(): void
	{
		$registry = new TripwireRegistry();
		$registry->register(self::throwing('a.a', new RuntimeException(str_repeat('x', 5000))));

		$findings = $registry->evaluate([]);

		$this->assertSame(Finding::MAX_CONTEXT, strlen($findings[0]->context));
	}

	#endregion
}
