<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Health;

use Drupal\strata\Health\Finding;
use Drupal\strata\Health\HealthLedgerInterface;
use Drupal\strata\Health\MemoryHealthLedger;
use Drupal\strata\Health\RepairLadder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

#[CoversClass(MemoryHealthLedger::class)]
class MemoryHealthLedgerTest extends TestCase
{
	/**
	 * The timestamp the injected clock reports, moved by hand instead of by sleeping.
	 *
	 * @var int
	 */
	private int $now = 1_000_000;

	private function ledger(int $maxPerCode = MemoryHealthLedger::MAX_PER_CODE): MemoryHealthLedger
	{
		return new MemoryHealthLedger($maxPerCode, fn(): int => $this->now);
	}

	/**
	 * @param Finding[] $findings
	 *   The findings to reduce to their contexts.
	 *
	 * @return string[]
	 */
	private static function contexts(array $findings): array
	{
		return array_map(static fn(Finding $finding): string => $finding->context, $findings);
	}

	#region Construction

	#[Test]
	#[TestDox('it is a health ledger')]
	#[Group('strata/health')]
	public function implementsTheLedgerContract(): void
	{
		$this->assertInstanceOf(HealthLedgerInterface::class, $this->ledger());
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function badRetentionProvider(): array
	{
		return [
			'zero' => [0],
			'negative' => [-5],
		];
	}

	#[Test]
	#[TestDox('a $_dataName retention is refused')]
	#[Group('strata/health')]
	#[DataProvider('badRetentionProvider')]
	public function refusesRetentionBelowOne(int $maxPerCode): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('retention must be at least 1');

		new MemoryHealthLedger($maxPerCode);
	}

	#[Test]
	#[TestDox('a clock that does not return an int is refused when it is read')]
	#[Group('strata/health')]
	public function refusesClockThatDoesNotReturnAnInt(): void
	{
		$ledger = new MemoryHealthLedger(50, static fn(): float => 1.5);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('must return an int, got float');

		$ledger->record(new Finding('a.b', Finding::INFO));
	}

	#[Test]
	#[TestDox('with no clock injected the wall clock is used')]
	#[Group('strata/health')]
	public function fallsBackToTheWallClock(): void
	{
		$ledger = new MemoryHealthLedger();
		$ledger->record(new Finding('a.b', Finding::INFO));

		$this->assertCount(1, $ledger->open());
		$this->assertSame(1, $ledger->purge(time() + 1));
	}

	#endregion

	#region Recording

	#[Test]
	#[TestDox('a new ledger has nothing open')]
	#[Group('strata/health')]
	public function startsEmpty(): void
	{
		$this->assertSame([], $this->ledger()->open());
	}

	#[Test]
	#[TestDox('a recorded finding comes back unchanged')]
	#[Group('strata/health')]
	public function recordsFinding(): void
	{
		$finding = new Finding('frame.orphaned', Finding::WARN, 'bd', 'no manifest');
		$ledger = $this->ledger();
		$ledger->record($finding);

		$this->assertSame([$finding], $ledger->open());
	}

	#[Test]
	#[TestDox('open() is ordered by when each finding was recorded, across codes')]
	#[Group('strata/health')]
	public function openIsChronologicalAcrossCodes(): void
	{
		$ledger = $this->ledger();
		$ledger->record(new Finding('a.a', Finding::INFO, '', 'first'));
		$ledger->record(new Finding('b.b', Finding::INFO, '', 'second'));
		$ledger->record(new Finding('a.a', Finding::INFO, '', 'third'));
		$ledger->record(new Finding('b.b', Finding::INFO, '', 'fourth'));

		$this->assertSame(['first', 'second', 'third', 'fourth'], self::contexts($ledger->open()));
	}

	#[Test]
	#[TestDox('retention is capped per code, dropping the oldest first')]
	#[Group('strata/health')]
	public function capsRetentionPerCode(): void
	{
		$ledger = $this->ledger(3);
		foreach (['one', 'two', 'three', 'four', 'five'] as $note) {
			$ledger->record(new Finding('a.a', Finding::INFO, $note, $note));
		}

		$this->assertSame(['three', 'four', 'five'], self::contexts($ledger->open()));
	}

	#[Test]
	#[TestDox('the cap is per code, not across the whole ledger')]
	#[Group('strata/health')]
	public function theCapIsPerCode(): void
	{
		$ledger = $this->ledger(2);
		foreach (['a.a', 'b.b', 'c.c'] as $code) {
			$ledger->record(new Finding($code, Finding::INFO, '', $code . '-1'));
			$ledger->record(new Finding($code, Finding::INFO, '', $code . '-2'));
			$ledger->record(new Finding($code, Finding::INFO, '', $code . '-3'));
		}

		$this->assertCount(6, $ledger->open());
		$this->assertSame(
			['a.a-2', 'a.a-3', 'b.b-2', 'b.b-3', 'c.c-2', 'c.c-3'],
			self::contexts($ledger->open()),
		);
	}

	#[Test]
	#[TestDox('a retention of one keeps only the newest finding for a code')]
	#[Group('strata/health')]
	public function aRetentionOfOneKeepsTheNewest(): void
	{
		$ledger = $this->ledger(1);
		$ledger->record(new Finding('a.a', Finding::INFO, '', 'old'));
		$ledger->record(new Finding('a.a', Finding::INFO, '', 'new'));

		$this->assertSame(['new'], self::contexts($ledger->open()));
	}

	#endregion

	#region Resolving

	#[Test]
	#[TestDox('resolve() clears only the scope it was given')]
	#[Group('strata/health')]
	public function resolveClearsOneScope(): void
	{
		$ledger = $this->ledger();
		$ledger->record(new Finding('a.a', Finding::ERROR, 'bd', 'first frame'));
		$ledger->record(new Finding('a.a', Finding::ERROR, 'ce', 'other frame'));

		$this->assertSame(1, $ledger->resolve('a.a', 'bd'));
		$this->assertSame(['other frame'], self::contexts($ledger->open()));
	}

	#[Test]
	#[TestDox('resolve() clears every finding sharing that code and scope')]
	#[Group('strata/health')]
	public function resolveClearsRepeatsOfTheSameScope(): void
	{
		$ledger = $this->ledger();
		$ledger->record(new Finding('a.a', Finding::ERROR, 'bd', 'pass one'));
		$ledger->record(new Finding('a.a', Finding::ERROR, 'bd', 'pass two'));

		$this->assertSame(2, $ledger->resolve('a.a', 'bd'));
		$this->assertSame([], $ledger->open());
	}

	#[Test]
	#[TestDox('resolve() leaves other codes with the same scope alone')]
	#[Group('strata/health')]
	public function resolveIsScopedToOneCode(): void
	{
		$ledger = $this->ledger();
		$ledger->record(new Finding('a.a', Finding::ERROR, 'bd', 'from a'));
		$ledger->record(new Finding('b.b', Finding::ERROR, 'bd', 'from b'));

		$this->assertSame(1, $ledger->resolve('a.a', 'bd'));
		$this->assertSame(['from b'], self::contexts($ledger->open()));
	}

	#[Test]
	#[TestDox('resolve() reports nothing cleared for a code or scope it does not hold')]
	#[Group('strata/health')]
	public function resolveIsZeroWhenNothingMatches(): void
	{
		$ledger = $this->ledger();
		$ledger->record(new Finding('a.a', Finding::ERROR, 'bd'));

		$this->assertSame(0, $ledger->resolve('c.c', 'bd'));
		$this->assertSame(0, $ledger->resolve('a.a', 'zz'));
		$this->assertCount(1, $ledger->open());
	}

	#endregion

	#region Rungs

	#[Test]
	#[TestDox('a code nothing is known about sits at the bottom of the ladder')]
	#[Group('strata/health')]
	public function anUnknownCodeSitsAtTheBottom(): void
	{
		$this->assertSame('observe', $this->ledger()->rungFor('a.a'));
	}

	/**
	 * @return array<string, array{int[], string}>
	 */
	public static function derivedRungProvider(): array
	{
		return [
			'info only' => [[Finding::INFO], 'observe'],
			'warn only' => [[Finding::WARN], 'observe'],
			'error only' => [[Finding::ERROR], 'reindex'],
			'critical only' => [[Finding::CRITICAL], 'quarantine'],
			'warn then critical' => [[Finding::WARN, Finding::CRITICAL], 'quarantine'],
			'critical then warn' => [[Finding::CRITICAL, Finding::WARN], 'quarantine'],
			'info then error' => [[Finding::INFO, Finding::ERROR], 'reindex'],
		];
	}

	#[Test]
	#[TestDox('with $_dataName recorded the rung is derived from the worst severity')]
	#[Group('strata/health')]
	#[DataProvider('derivedRungProvider')]
	public function rungIsDerivedFromTheWorstSeverity(array $severities, string $expected): void
	{
		$ledger = $this->ledger();
		foreach ($severities as $severity) {
			$ledger->record(new Finding('a.a', $severity, (string) $severity));
		}

		$this->assertSame($expected, $ledger->rungFor('a.a'));
	}

	#[Test]
	#[TestDox('an explicit rung wins over the derived one')]
	#[Group('strata/health')]
	public function setRungOverridesTheDerivedRung(): void
	{
		$ledger = $this->ledger();
		$ledger->record(new Finding('a.a', Finding::INFO));
		$ledger->setRung('a.a', 'rebuild');

		$this->assertSame('rebuild', $ledger->rungFor('a.a'));
	}

	#[Test]
	#[TestDox('every rung on the ladder can be set')]
	#[Group('strata/health')]
	public function everyRungCanBeSet(): void
	{
		$ledger = $this->ledger();
		foreach (RepairLadder::RUNGS as $rung) {
			$ledger->setRung('a.a', $rung);
			$this->assertSame($rung, $ledger->rungFor('a.a'));
		}
	}

	#[Test]
	#[TestDox('a rung that is not on the ladder is refused')]
	#[Group('strata/health')]
	public function setRungRefusesAnUnknownRung(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('"reboot" is not a rung on the ladder');

		$this->ledger()->setRung('a.a', 'reboot');
	}

	#[Test]
	#[TestDox('resolving the last finding for a code forgets its rung')]
	#[Group('strata/health')]
	public function resolveForgetsTheRung(): void
	{
		$ledger = $this->ledger();
		$ledger->record(new Finding('a.a', Finding::CRITICAL, 'bd'));
		$ledger->setRung('a.a', 'refuse');

		$ledger->resolve('a.a', 'bd');

		$this->assertSame('observe', $ledger->rungFor('a.a'));
	}

	#[Test]
	#[TestDox('resolving one of several findings keeps the rung')]
	#[Group('strata/health')]
	public function resolveKeepsTheRungWhileAnythingRemains(): void
	{
		$ledger = $this->ledger();
		$ledger->record(new Finding('a.a', Finding::CRITICAL, 'bd'));
		$ledger->record(new Finding('a.a', Finding::CRITICAL, 'ce'));
		$ledger->setRung('a.a', 'refuse');

		$ledger->resolve('a.a', 'bd');

		$this->assertSame('refuse', $ledger->rungFor('a.a'));
	}

	#endregion

	#region Purging

	#[Test]
	#[TestDox('purge() drops findings recorded before the cutoff')]
	#[Group('strata/health')]
	public function purgeDropsWhatIsOldEnough(): void
	{
		$ledger = $this->ledger();
		$ledger->record(new Finding('a.a', Finding::INFO, '', 'old'));
		$this->now += 100;
		$ledger->record(new Finding('a.a', Finding::INFO, '', 'new'));

		$this->assertSame(1, $ledger->purge(1_000_050));
		$this->assertSame(['new'], self::contexts($ledger->open()));
	}

	#[Test]
	#[TestDox('a finding recorded exactly at the cutoff is kept')]
	#[Group('strata/health')]
	public function purgeKeepsTheBoundary(): void
	{
		$ledger = $this->ledger();
		$ledger->record(new Finding('a.a', Finding::INFO, '', 'boundary'));

		$this->assertSame(0, $ledger->purge($this->now));
		$this->assertSame(['boundary'], self::contexts($ledger->open()));
	}

	#[Test]
	#[TestDox('purge() reports nothing dropped when everything is recent')]
	#[Group('strata/health')]
	public function purgeIsZeroWhenNothingIsOldEnough(): void
	{
		$ledger = $this->ledger();
		$ledger->record(new Finding('a.a', Finding::INFO));

		$this->assertSame(0, $ledger->purge($this->now - 1));
		$this->assertCount(1, $ledger->open());
	}

	#[Test]
	#[TestDox('purge() reports nothing dropped on an empty ledger')]
	#[Group('strata/health')]
	public function purgeIsZeroOnAnEmptyLedger(): void
	{
		$this->assertSame(0, $this->ledger()->purge($this->now));
	}

	#[Test]
	#[TestDox('purge() spans every code')]
	#[Group('strata/health')]
	public function purgeSpansEveryCode(): void
	{
		$ledger = $this->ledger();
		$ledger->record(new Finding('a.a', Finding::INFO));
		$ledger->record(new Finding('b.b', Finding::INFO));
		$ledger->record(new Finding('c.c', Finding::INFO));
		$this->now += 10;

		$this->assertSame(3, $ledger->purge($this->now));
		$this->assertSame([], $ledger->open());
	}

	#[Test]
	#[TestDox('purging the last finding for a code forgets its rung')]
	#[Group('strata/health')]
	public function purgeForgetsTheRungOfAnEmptiedCode(): void
	{
		$ledger = $this->ledger();
		$ledger->record(new Finding('a.a', Finding::INFO));
		$ledger->setRung('a.a', 'refuse');
		$this->now += 10;

		$ledger->purge($this->now);

		$this->assertSame('observe', $ledger->rungFor('a.a'));
	}

	#[Test]
	#[TestDox('purge() leaves the rung of a code it holds no findings for')]
	#[Group('strata/health')]
	public function purgeLeavesAnUntouchedRungAlone(): void
	{
		$ledger = $this->ledger();
		$ledger->setRung('a.a', 'refuse');
		$ledger->record(new Finding('b.b', Finding::INFO));
		$this->now += 10;

		$this->assertSame(1, $ledger->purge($this->now));
		$this->assertSame('refuse', $ledger->rungFor('a.a'));
	}

	#endregion
}
