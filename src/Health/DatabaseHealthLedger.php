<?php

declare(strict_types=1);

namespace Drupal\strata\Health;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use InvalidArgumentException;

/**
 * The ledger a site runs on.
 *
 * Findings outlive the request that noticed them, so a verify pass on cron leaves something for the
 * health dashboard to show and something the repair ladder can escalate from. This is one of the
 * two tables an uninstall exports before dropping, because nothing in the bucket can reproduce it:
 * the bucket records what the site looks like, not what went wrong reaching it.
 *
 * A row is inserted once per code and scope and given a fresh timestamp when the same symptom is
 * seen again, so a check that fires every cron run produces one open row rather than one row per
 * run. What the dashboard sorts by is how many scopes a code covers, which is the difference
 * between one broken frame and a broken bucket.
 *
 * @see HealthLedgerInterface
 * @see Finding
 * @see RepairLadder
 */
final class DatabaseHealthLedger implements HealthLedgerInterface
{
	/**
	 * The table findings live in.
	 */
	public const TABLE = 'strata_health';

	/**
	 * Constructs a ledger.
	 *
	 * @param Connection $database
	 *   The database.
	 */
	public function __construct(private readonly Connection $database) {}

	#region Findings

	/**
	 * {@inheritdoc}
	 */
	public function record(Finding $finding): void
	{
		$existing = $this->database
			->select(self::TABLE, 'h')
			->fields('h', ['id', 'severity'])
			->condition('code', $finding->code)
			->condition('scope', $finding->scope)
			->isNull('resolved')
			->range(0, 1)
			->execute()
			?->fetchAssoc();

		$rung = $this->startingRung($finding);

		if ($existing !== false && $existing !== null) {
			// the same symptom seen again refreshes the row rather than adding one
			$this->database
				->update(self::TABLE)
				->fields([
					'severity' => max((int) $existing['severity'], $finding->severity),
					'context' => $finding->context,
					'rung' => $rung,
					'created' => $this->now(),
				])
				->condition('id', (int) $existing['id'])
				->execute();

			return;
		}

		$this->database
			->insert(self::TABLE)
			->fields([
				'code' => $finding->code,
				'severity' => $finding->severity,
				'scope' => $finding->scope,
				'context' => $finding->context,
				'rung' => $rung,
				'created' => $this->now(),
				'resolved' => null,
			])
			->execute();
	}

	/**
	 * The rung a finding lands on.
	 *
	 * A code that has already been escalated stays where it is. Otherwise the severity decides,
	 * which is what puts a critical finding straight at quarantine instead of walking it up the
	 * ladder one cron run at a time while the site cannot restore.
	 *
	 * @param Finding $finding
	 *   The finding being recorded.
	 *
	 * @return string
	 *   The rung name.
	 */
	private function startingRung(Finding $finding): string
	{
		$current = $this->rungFor($finding->code);
		$initial = RepairLadder::initialRung($finding->severity);

		return RepairLadder::rank($initial) > RepairLadder::rank($current) ? $initial : $current;
	}

	/**
	 * {@inheritdoc}
	 */
	public function open(): array
	{
		$rows =
			$this->database
				->select(self::TABLE, 'h')
				->fields('h')
				->isNull('resolved')
				->orderBy('severity', 'DESC')
				->orderBy('created', 'DESC')
				->execute()
				?->fetchAll(FetchAs::Associative) ?? [];

		return array_map(
			static fn(array $row): Finding => new Finding(
				(string) $row['code'],
				(int) $row['severity'],
				(string) $row['scope'],
				(string) $row['context'],
			),
			$rows,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function resolve(string $code, string $scope): int
	{
		return (int) $this->database
			->update(self::TABLE)
			->fields(['resolved' => $this->now()])
			->condition('code', $code)
			->condition('scope', $scope)
			->isNull('resolved')
			->execute();
	}

	/**
	 * {@inheritdoc}
	 *
	 * Only resolved rows are dropped. An open finding is the reason someone is looking at the
	 * dashboard, and age is not evidence that it stopped being true.
	 */
	public function purge(int $olderThan): int
	{
		return (int) $this->database
			->delete(self::TABLE)
			->condition('created', $olderThan, '<')
			->isNotNull('resolved')
			->execute();
	}

	#endregion

	#region Rungs

	/**
	 * {@inheritdoc}
	 *
	 * The rung is stored per row but is a property of the CODE, so the answer is the highest rung
	 * any row for that code carries. A code with nothing recorded sits at the bottom of the ladder.
	 */
	public function rungFor(string $code): string
	{
		$rows =
			$this->database
				->select(self::TABLE, 'h')
				->fields('h', ['rung'])
				->condition('code', $code)
				->execute()
				?->fetchCol() ?? [];

		$highest = RepairLadder::RUNGS[0];

		foreach ($rows as $rung) {
			if (RepairLadder::rank((string) $rung) > RepairLadder::rank($highest)) {
				$highest = (string) $rung;
			}
		}

		return $highest;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @throws InvalidArgumentException
	 *   When the rung is not one the ladder knows. Storing a typo would park the code where
	 *   RepairLadder::isAutomatic() refuses it forever.
	 */
	public function setRung(string $code, string $rung): void
	{
		if (RepairLadder::rank($rung) === RepairLadder::UNRANKED) {
			throw new InvalidArgumentException(sprintf('"%s" is not a rung on the ladder', $rung));
		}

		$this->database
			->update(self::TABLE)
			->fields(['rung' => $rung])
			->condition('code', $code)
			->execute();
	}

	#endregion

	/**
	 * How many times each open code has been seen, worst first.
	 *
	 * What the health dashboard lists. Grouped in the database rather than by loading every row,
	 * since a site that has been broken for a week can hold a lot of them.
	 *
	 * @return list<array{code: string, severity: int, rung: string, scopes: int, newest: int}>
	 *   One row per open code.
	 */
	public function summary(): array
	{
		$query = $this->database->select(self::TABLE, 'h');
		$query->addField('h', 'code');
		$query->addExpression('MAX([h].[severity])', 'severity');
		$query->addExpression('COUNT(*)', 'scopes');
		$query->addExpression('MAX([h].[created])', 'newest');
		$query->isNull('h.resolved');
		$query->groupBy('h.code');
		$query->orderBy('severity', 'DESC');

		$rows = $query->execute()?->fetchAll(FetchAs::Associative) ?? [];

		return array_map(
			fn(array $row): array => [
				'code' => (string) $row['code'],
				'severity' => (int) $row['severity'],
				'rung' => $this->rungFor((string) $row['code']),
				'scopes' => (int) $row['scopes'],
				'newest' => (int) $row['newest'],
			],
			$rows,
		);
	}

	/**
	 * The current time.
	 *
	 * @return int
	 *   Unix seconds.
	 */
	private function now(): int
	{
		return time();
	}
}
