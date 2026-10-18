<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\E2E;

use Drupal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves a whole table comes back row for row on the database engine this lane runs on.
 *
 * Physical restore is the one path that is different on every engine: MariaDB and PostgreSQL swap a
 * shadow table in with one atomic rename, while the truncate strategy empties and refills in place.
 * SQLite accepts any bytes in any column and has no atomic table swap, so the kernel lane cannot see
 * what these cases see. Rows are written the ordinary way, through the database API, and the digest
 * of the table is compared before and after, so a row that came back changed or went missing fails.
 */
class PhysicalRestoreTest extends E2ETestBase
{
	/**
	 * The table the cases write.
	 */
	private const TABLE = 'e2e_ledger';

	/**
	 * @return array<string, array{string}>
	 */
	public static function strategies(): array
	{
		return ['truncate' => ['truncate'], 'shadow swap' => ['shadow_swap']];
	}

	#[Test]
	#[TestDox('the $strategy strategy returns a table to exactly the rows it held at a commit')]
	#[Group('strata/e2e')]
	#[DataProvider('strategies')]
	public function aTableComesBackRowForRow(string $strategy): void
	{
		$this->configure('s3');

		$available = $this->engine()->physicalRestore()->available();

		if (!isset($available[$strategy])) {
			$this->markTestSkipped($strategy . ' is not available on ' . Lane::database());
		}

		$this->ledger();

		for ($id = 1; $id <= 8; $id++) {
			$this->row($id, 'original ' . $id);
		}

		$this->capture();
		$target = $this->head();
		$digest = Oracle::table(self::TABLE);

		$this->assertNotSame('', $target, 'the rows were captured and sealed');

		Drupal::database()
			->update(self::TABLE)
			->fields(['label' => 'changed'])
			->condition('id', [2, 3], 'IN')
			->execute();
		Drupal::database()->delete(self::TABLE)->condition('id', 5)->execute();

		for ($id = 9; $id <= 12; $id++) {
			$this->row($id, 'added ' . $id);
		}

		$this->capture();

		$this->assertNotSame($digest, Oracle::table(self::TABLE));

		$result = $this->engine()
			->physicalRestore()
			->restore(self::TABLE, $target, $strategy, true, true);

		$this->assertFalse($result->wasRefused(), (string) $result->refused);
		$this->assertSame([], $result->failed, json_encode($result->failed) ?: '');
		$this->assertSame(
			$digest,
			Oracle::table(self::TABLE),
			'the table is row for row what it was',
		);
	}

	#[Test]
	#[TestDox('a physical restore refuses to empty a table the target never saw unless asked')]
	#[Group('strata/e2e')]
	public function aRestoreThatWouldLoseRowsIsRefused(): void
	{
		$this->configure('s3');
		$this->ledger();

		$this->row(1, 'only row');
		$this->capture();
		$target = $this->head();

		$this->assertNotSame('', $target, 'the row was captured and sealed');

		for ($id = 2; $id <= 40; $id++) {
			$this->row($id, 'later ' . $id);
		}

		$this->capture();

		$result = $this->engine()
			->physicalRestore()
			->restore(self::TABLE, $target, 'truncate', true, false);

		$this->assertTrue($result->wasRefused(), 'forty rows would have become one without a word');
		$this->assertSame(40, $this->rows());
	}

	/**
	 * Creates the table and empties it.
	 */
	private function ledger(): void
	{
		$schema = Drupal::database()->schema();

		if (!$schema->tableExists(self::TABLE)) {
			$schema->createTable(self::TABLE, [
				'fields' => [
					'id' => ['type' => 'int', 'not null' => true],
					'label' => [
						'type' => 'varchar',
						'length' => 64,
						'not null' => true,
						'default' => '',
					],
					'changed' => ['type' => 'int', 'not null' => true, 'default' => 0],
				],
				'primary key' => ['id'],
			]);
		}

		Drupal::database()->truncate(self::TABLE)->execute();
		$this->engine()->reset();
		$this->engine()
			->reconciler()
			->reconcile([self::TABLE]);
	}

	/**
	 * Writes one row through the database API.
	 *
	 * @param int $id
	 *   The row id.
	 * @param string $label
	 *   The label.
	 */
	private function row(int $id, string $label): void
	{
		Drupal::database()
			->insert(self::TABLE)
			->fields(['id' => $id, 'label' => $label, 'changed' => 1_700_000_000 + $id])
			->execute();
	}

	/**
	 * Gets the table's rows into the store the way cron does, then seals them.
	 */
	private function capture(): void
	{
		$this->settle();
		$this->engine()
			->reconciler()
			->reconcile([self::TABLE]);
		$this->seal();
	}

	/**
	 * How many rows the table holds.
	 *
	 * @return int
	 *   The count.
	 */
	private function rows(): int
	{
		return (int) Drupal::database()
			->select(self::TABLE, 't')
			->countQuery()
			->execute()
			?->fetchField();
	}
}
