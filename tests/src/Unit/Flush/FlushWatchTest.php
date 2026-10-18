<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Flush;

use Drupal\strata\Flush\FlushWatch;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\MemoryHealthLedger;
use Drupal\strata\Health\RepairLadder;
use Drupal\strata\Health\Tripwire\FlushStale;
use Drupal\strata\Health\TripwireRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Proves a store that has stopped being written to is reported, and that a flush clears it.
 *
 * No other tripwire can see this failure, because each one reads something the store holds and a
 * store that refuses writes still holds a perfectly good history.
 */
#[CoversClass(FlushStale::class)]
#[CoversClass(FlushWatch::class)]
class FlushWatchTest extends TestCase
{
	private function watch(MemoryHealthLedger $ledger, int $staleAfter = 3600): FlushWatch
	{
		return new FlushWatch(TripwireRegistry::withCaptureTripwires(), $ledger, $staleAfter);
	}

	#[Test]
	#[TestDox('the tripwire ships in the capture set and is named for what it reports')]
	#[Group('strata/health')]
	public function isRegistered(): void
	{
		$registry = TripwireRegistry::withCaptureTripwires();

		$this->assertTrue($registry->has(FlushWatch::CODE));
		$this->assertSame(FlushWatch::CODE, (new FlushStale())->code());
	}

	/**
	 * @return array<string, array{float, int, bool}>
	 */
	public static function readings(): array
	{
		return [
			'nothing pending' => [0.0, 3600, false],
			'under the limit' => [3599.9, 3600, false],
			'exactly at the limit' => [3600.0, 3600, true],
			'well over the limit' => [90000.0, 3600, true],
			'watch switched off' => [90000.0, 0, false],
		];
	}

	#[Test]
	#[TestDox('a lag of $lag seconds against a limit of $limit leaves the finding open: $open')]
	#[Group('strata/health')]
	#[DataProvider('readings')]
	public function raisesAtTheLimit(float $lag, int $limit, bool $open): void
	{
		$ledger = new MemoryHealthLedger();

		$this->assertSame($open, $this->watch($ledger, $limit)->check($lag));
		$this->assertSame($open ? 1 : 0, count($ledger->open()));
	}

	#[Test]
	#[
		TestDox(
			'the finding is a warning, so no unattended repair spends requests on a failing store',
		),
	]
	#[Group('strata/health')]
	public function isAWarningOnTheObserveRung(): void
	{
		$ledger = new MemoryHealthLedger();

		$this->watch($ledger)->check(7200.0);

		$finding = $ledger->open()[0];

		$this->assertSame(Finding::WARN, $finding->severity);
		$this->assertSame('observe', RepairLadder::initialRung($finding->severity));
	}

	#[Test]
	#[TestDox('the message names both spans in the largest whole unit')]
	#[Group('strata/health')]
	public function namesBothSpans(): void
	{
		$finding = (new FlushStale())->check([
			'flush_lag' => 187200.0,
			'flush_stale_after' => 86400,
		]);

		$this->assertNotNull($finding);
		$this->assertStringContainsString('2 days old', $finding->context);
		$this->assertStringContainsString('limit is 1 day,', $finding->context);
	}

	#[Test]
	#[TestDox('a flush that catches up resolves the finding, and only that one')]
	#[Group('strata/health')]
	public function clearsWhenTheLagFalls(): void
	{
		$ledger = new MemoryHealthLedger();
		$ledger->record(new Finding('frame.missing', Finding::ERROR, 'frames/aa', 'gone'));
		$watch = $this->watch($ledger);

		$this->assertTrue($watch->check(7200.0));
		$this->assertFalse($watch->check(2.0));

		$open = array_map(static fn(Finding $f): string => $f->code, $ledger->open());

		$this->assertSame(['frame.missing'], $open);
	}
}
