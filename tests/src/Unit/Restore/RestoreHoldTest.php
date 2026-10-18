<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Restore;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\MemoryHealthLedger;
use Drupal\strata\Restore\RestoreHold;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Proves the two rungs a person sets are read by the one thing they are meant to stop.
 *
 * A rung nothing reads is a label. These cases pin what each of the two does: quarantine takes one
 * commit out of use, refuse stops every restore, and releasing either puts everything back.
 */
#[CoversClass(RestoreHold::class)]
class RestoreHoldTest extends TestCase
{
	private function commit(string $name): string
	{
		return Hash::of($name);
	}

	private function quarantine(MemoryHealthLedger $ledger, string $commit): void
	{
		$ledger->record(
			new Finding(
				'commit.quarantined',
				Finding::ERROR,
				$commit,
				'quarantined by gmitch: bad import',
			),
		);
		$ledger->setRung('commit.quarantined', 'quarantine');
	}

	#[Test]
	#[TestDox('a ledger holding nothing refuses nothing')]
	#[Group('strata/restore')]
	public function anEmptyLedgerHoldsNothing(): void
	{
		$this->assertNull((new RestoreHold(new MemoryHealthLedger()))->reason($this->commit('a')));
	}

	#[Test]
	#[TestDox('a quarantined commit is refused and the reason names the code and what was written')]
	#[Group('strata/restore')]
	public function aQuarantinedCommitIsRefused(): void
	{
		$ledger = new MemoryHealthLedger();
		$this->quarantine($ledger, $this->commit('a'));

		$reason = (new RestoreHold($ledger))->reason($this->commit('a'));

		$this->assertNotNull($reason);
		$this->assertStringContainsString('commit.quarantined', $reason);
		$this->assertStringContainsString('bad import', $reason);
		$this->assertStringContainsString(substr($this->commit('a'), 0, 12), $reason);
	}

	#[Test]
	#[TestDox('only the quarantined commit is held, so its neighbours still restore')]
	#[Group('strata/restore')]
	public function otherCommitsAreNotHeld(): void
	{
		$ledger = new MemoryHealthLedger();
		$this->quarantine($ledger, $this->commit('a'));

		$this->assertNull((new RestoreHold($ledger))->reason($this->commit('b')));
	}

	#[Test]
	#[
		TestDox(
			'an abbreviation of eight characters or more names the commit, and a shorter one does not',
		),
	]
	#[Group('strata/restore')]
	public function anAbbreviationNamesTheCommit(): void
	{
		$ledger = new MemoryHealthLedger();
		$full = $this->commit('a');
		$this->quarantine($ledger, $full);
		$hold = new RestoreHold($ledger);

		$this->assertNotNull($hold->reason(substr($full, 0, 8)));
		$this->assertNull($hold->reason(substr($full, 0, 7)), 'too short to be sure of');
		$this->assertNull($hold->reason(''));
	}

	#[Test]
	#[
		TestDox(
			'a code held at refuse stops a restore to any commit while one of its findings is open',
		),
	]
	#[Group('strata/restore')]
	public function refuseStopsEveryRestore(): void
	{
		$ledger = new MemoryHealthLedger();
		$ledger->record(
			new Finding('frame.hash_mismatch', Finding::ERROR, 'frame:aa', 'wrong bytes'),
		);
		$ledger->setRung('frame.hash_mismatch', 'refuse');
		$hold = new RestoreHold($ledger);

		$this->assertStringContainsString(
			'frame.hash_mismatch is held at refuse',
			(string) $hold->reason($this->commit('anything')),
		);

		$ledger->resolve('frame.hash_mismatch', 'frame:aa');

		$this->assertNull(
			$hold->reason($this->commit('anything')),
			'resolved, so nothing holds it',
		);
	}

	#[Test]
	#[
		TestDox(
			'releasing a quarantine puts the commit back, and a rung below quarantine holds nothing',
		),
	]
	#[Group('strata/restore')]
	public function releasingPutsItBack(): void
	{
		$ledger = new MemoryHealthLedger();
		$commit = $this->commit('a');
		$this->quarantine($ledger, $commit);
		$hold = new RestoreHold($ledger);

		$this->assertNotNull($hold->reason($commit));

		$ledger->setRung('commit.quarantined', 'rebuild');

		$this->assertNull($hold->reason($commit));
	}

	#[Test]
	#[TestDox('a finding at quarantine whose scope is not a commit holds no commit')]
	#[Group('strata/restore')]
	public function aNonCommitScopeHoldsNothing(): void
	{
		$ledger = new MemoryHealthLedger();
		$ledger->record(new Finding('ref.missing', Finding::CRITICAL, 'heads/main', 'ref gone'));
		$ledger->setRung('ref.missing', 'quarantine');

		$this->assertNull((new RestoreHold($ledger))->reason($this->commit('a')));
	}
}
