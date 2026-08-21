<?php

declare(strict_types=1);

namespace Drupal\strata\Health;

use InvalidArgumentException;

/**
 * Where findings are kept, and where each code currently sits on the ladder.
 *
 * The ledger is the memory a health system needs and a single sweep cannot have: whether this is
 * the first time a code has fired or the fortieth, and how far up the ladder the response has
 * already climbed. A tripwire answers "is this wrong now"; the ledger answers "how long has it
 * been wrong, and what have we already tried".
 *
 * Every implementation must be bounded. Scope is the field that grows without limit - a frame
 * hash is a new scope every time - so an implementation caps what it retains per code rather than
 * trusting a purge to run.
 *
 * A ledger stores and answers. It never repairs, never escalates on its own, and never calls a
 * tripwire; escalation is the caller's decision, made with RepairLadder, and recorded here.
 *
 * @see Finding
 * @see RepairLadder
 * @see MemoryHealthLedger
 */
interface HealthLedgerInterface
{
	/**
	 * Records one finding.
	 *
	 * @param Finding $finding
	 *   What a tripwire noticed.
	 */
	public function record(Finding $finding): void;

	/**
	 * Every finding still held, oldest first.
	 *
	 * @return Finding[]
	 *   The open findings, empty when nothing is outstanding.
	 */
	public function open(): array;

	/**
	 * Clears the findings for one code and scope.
	 *
	 * Scoped rather than code-wide, because one frame being repaired says nothing about the next
	 * frame reporting the same code.
	 *
	 * @param string $code
	 *   The finding code.
	 * @param string $scope
	 *   The exact scope that has been dealt with.
	 *
	 * @return int
	 *   How many findings were cleared.
	 */
	public function resolve(string $code, string $scope): int;

	/**
	 * The rung a code currently sits at.
	 *
	 * @param string $code
	 *   The finding code.
	 *
	 * @return string
	 *   A rung name from RepairLadder::RUNGS.
	 */
	public function rungFor(string $code): string;

	/**
	 * Moves a code to a rung.
	 *
	 * @param string $code
	 *   The finding code.
	 * @param string $rung
	 *   A rung name from RepairLadder::RUNGS.
	 *
	 * @throws InvalidArgumentException
	 *   When $rung is not on the ladder.
	 */
	public function setRung(string $code, string $rung): void;

	/**
	 * Open findings collapsed to one row per code.
	 *
	 * What a dashboard and a metrics export both read: a code with four hundred scopes is one line an
	 * operator acts on rather than four hundred, and the scopes are only useful once they have decided
	 * to look at that code.
	 *
	 * @return list<array{code: string, severity: int, rung: string, scopes: int, newest: int}>
	 *   One entry per open code, worst severity first.
	 */
	public function summary(): array;

	/**
	 * Drops findings recorded before a point in time.
	 *
	 * @param int $olderThan
	 *   Unix timestamp; findings recorded strictly before this are dropped.
	 *
	 * @return int
	 *   How many findings were dropped.
	 */
	public function purge(int $olderThan): int;
}
