<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Engine;
use Drupal\strata\Journal\JournalInterface;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;
use Drupal\strata\Restore\PhysicalRestore;
use Drupal\strata\Restore\Plugin\Strata\Restore\ShadowSwapStrategy;
use Drupal\strata\Restore\Plugin\Strata\Restore\TruncateRestoreStrategy;
use Drupal\strata\Restore\RestoreAudit;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves a whole table can be put back, and refuses when doing so would delete rows.
 *
 * The lane that matters most here is the refusal. Strata stores churn rather than snapshots, so the
 * rows it can restore are the rows it captured, and a naive implementation would happily truncate a
 * 50,000-row table down to the 400 rows it happened to see.
 */
class PhysicalRestoreTest extends StrataKernelTestBase
{
	/**
	 * A table nothing else instruments.
	 */
	private const TABLE = 'physical_widget';

	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'field', 'key', 'strata'];

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->installEntitySchema('user');
		$this->installSchema('user', ['users_data']);

		$this->config('strata.settings')
			->set('enabled', true)
			->set('provider', 'local')
			->set('local_path', $this->storeRoot)
			->set('cipher.id', 'none')
			->save();

		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();
		$this->createTable();
		$this->journal()->trim(PHP_INT_MAX);
	}

	private function engine(): Engine
	{
		return $this->container->get('strata.engine');
	}

	private function journal(): JournalInterface
	{
		return $this->container->get('strata.journal');
	}

	private function restore(): PhysicalRestore
	{
		return $this->container->get('strata.physical_restore');
	}

	private function createTable(): void
	{
		$schema = $this->container->get('database')->schema();

		if ($schema->tableExists(self::TABLE)) {
			return;
		}

		$schema->createTable(self::TABLE, [
			'fields' => [
				'wid' => ['type' => 'int', 'not null' => true],
				'label' => [
					'type' => 'varchar',
					'length' => 64,
					'not null' => true,
					'default' => '',
				],
			],
			'primary key' => ['wid'],
		]);
	}

	/**
	 * Writes a row straight to the table.
	 */
	private function insert(int $id, string $label): void
	{
		$this->container
			->get('database')
			->insert(self::TABLE)
			->fields(['wid' => $id, 'label' => $label])
			->execute();
	}

	/**
	 * Records a row the way the reconciler does, so a replay can find it.
	 */
	private function captureRow(int $id, string $label): void
	{
		$payload = (string) json_encode(['wid' => $id, 'label' => $label]);

		$this->journal()->append(
			new JournalOp(
				0,
				(int) round(microtime(true) * JournalOp::MICROSECONDS_PER_SECOND),
				Realm::TABLE,
				sprintf('%s:wid=%d', self::TABLE, $id),
				Verb::UPDATE,
				null,
				null,
				null,
				null,
				strlen($payload),
				sprintf('Reconciled %s row %d', self::TABLE, $id),
				['wid', 'label'],
			),
			$payload,
		);
	}

	/**
	 * The head commit id, having flushed whatever is pending.
	 */
	private function commit(): string
	{
		$this->engine()->flusher()->flush(true);

		return (string) $this->engine()->commitLog()->head()?->id();
	}

	/**
	 * The table's rows, keyed by id.
	 *
	 * @return array<int, string>
	 *   Id keyed to label.
	 */
	private function rows(): array
	{
		$rows = [];

		foreach (
			$this->container->get('database')->select(self::TABLE, 't')->fields('t')->execute()
			as $row
		) {
			$rows[(int) $row->wid] = (string) $row->label;
		}

		ksort($rows);

		return $rows;
	}

	#region Strategies

	#[Test]
	#[TestDox('the truncate strategy runs on every driver')]
	#[Group('strata/restore')]
	public function truncateIsAlwaysAvailable(): void
	{
		$this->assertArrayHasKey('truncate', $this->restore()->available());
		$this->assertArrayNotHasKey('truncate', $this->restore()->unavailable());
	}

	#[Test]
	#[TestDox('the shadow swap refuses on sqlite rather than degrading to a truncate')]
	#[Group('strata/restore')]
	public function shadowSwapRefusesOnSqlite(): void
	{
		$driver = strtolower($this->container->get('database')->driver());
		$unavailable = $this->restore()->unavailable();

		if (in_array($driver, ShadowSwapStrategy::SUPPORTED_DRIVERS, true)) {
			$this->assertArrayHasKey('shadow_swap', $this->restore()->available());

			return;
		}

		$this->assertArrayHasKey('shadow_swap', $unavailable);
		$this->assertStringContainsString('atomically', $unavailable['shadow_swap']);
		$this->assertStringContainsString('truncate strategy', $unavailable['shadow_swap']);
	}

	#[Test]
	#[TestDox('every strategy describes what it costs the site while it runs')]
	#[Group('strata/restore')]
	public function strategiesDescribeTheirCost(): void
	{
		foreach ([new TruncateRestoreStrategy(), new ShadowSwapStrategy()] as $strategy) {
			$this->assertNotSame('', $strategy->id());
			$this->assertNotSame('', $strategy->label());
			$this->assertNotSame('', $strategy->describe());
		}
	}

	#[Test]
	#[TestDox('a strategy that is not registered is named rather than silently substituted')]
	#[Group('strata/restore')]
	public function unknownStrategyIsNamed(): void
	{
		$this->captureRow(1, 'one');
		$this->insert(1, 'one');
		$target = $this->commit();

		$result = $this->restore()->restore(self::TABLE, $target, 'magic', true);

		$this->assertTrue($result->wasRefused());
		$this->assertStringContainsString(
			'not a registered restore strategy',
			(string) $result->refused,
		);
	}

	#endregion

	#region Planning

	#[Test]
	#[TestDox('a plan reports the captured rows against the live rows')]
	#[Group('strata/restore')]
	public function planReportsCoverage(): void
	{
		$this->captureRow(1, 'one');
		$this->captureRow(2, 'two');
		$this->insert(1, 'one');
		$this->insert(2, 'two');
		$target = $this->commit();

		$plan = $this->restore()->plan(self::TABLE, $target);

		$this->assertNull($plan->refused);
		$this->assertSame(2, $plan->liveRows);
		$this->assertSame(2, $plan->capturedRows);
		$this->assertSame(['label', 'wid'], $plan->columns);
		$this->assertSame(0, $plan->rowsLost());
		$this->assertFalse($plan->wouldLoseRows());
		$this->assertTrue($plan->isActionable());
		$this->assertStringContainsString('100.0% coverage', $plan->summary());
	}

	#[Test]
	#[TestDox('a table that does not exist is refused by name')]
	#[Group('strata/restore')]
	public function missingTableIsRefused(): void
	{
		$this->captureRow(1, 'one');
		$target = $this->commit();

		$plan = $this->restore()->plan('no_such_table', $target);

		$this->assertNotNull($plan->refused);
		$this->assertStringContainsString('does not exist', $plan->refused);
		$this->assertFalse($plan->isActionable());
	}

	#[Test]
	#[TestDox('an empty table restoring to empty loses nothing')]
	#[Group('strata/restore')]
	public function emptyTableIsFullCoverage(): void
	{
		$target = $this->commit();
		$plan = $this->restore()->plan(self::TABLE, $target);

		$this->assertSame(1.0, $plan->coverage());
		$this->assertFalse($plan->wouldLoseRows());
	}

	#endregion

	#region Refusing To Delete Rows

	#[Test]
	#[TestDox('a restore that would remove most of a table refuses and names both numbers')]
	#[Group('strata/restore')]
	public function refusesToEmptyAnUncapturedTable(): void
	{
		// one row captured, twenty in the table: the other nineteen predate capture
		$this->captureRow(1, 'captured');

		for ($i = 1; $i <= 20; $i++) {
			$this->insert($i, 'row ' . $i);
		}

		$target = $this->commit();
		$plan = $this->restore()->plan(self::TABLE, $target);

		$this->assertTrue($plan->wouldLoseRows());
		$this->assertSame(19, $plan->rowsLost());
		$this->assertStringContainsString(
			'holds 20 rows and only 1 were captured',
			$plan->rowLossWarning(),
		);
		$this->assertStringContainsString('changes rather than snapshots', $plan->rowLossWarning());

		$result = $this->restore()->restore(self::TABLE, $target, 'truncate', true);

		$this->assertTrue($result->wasRefused());
		$this->assertStringContainsString('would remove 19 rows', (string) $result->refused);
		$this->assertCount(20, $this->rows(), 'the table is untouched');
	}

	#[Test]
	#[TestDox('an operator can accept the row loss explicitly, and only then')]
	#[Group('strata/restore')]
	public function rowLossCanBeAccepted(): void
	{
		$this->captureRow(1, 'captured');

		for ($i = 1; $i <= 20; $i++) {
			$this->insert($i, 'row ' . $i);
		}

		$target = $this->commit();

		$result = $this->restore()->restore(self::TABLE, $target, 'truncate', true, true);

		$this->assertFalse($result->wasRefused(), (string) $result->refused);
		$this->assertSame([1 => 'captured'], $this->rows());
	}

	#[Test]
	#[TestDox('a refused restore is still recorded in the audit')]
	#[Group('strata/restore')]
	public function refusalIsAudited(): void
	{
		$this->captureRow(1, 'captured');

		for ($i = 1; $i <= 20; $i++) {
			$this->insert($i, 'row ' . $i);
		}

		$target = $this->commit();
		$this->restore()->restore(self::TABLE, $target, 'truncate', true);

		$rows = $this->container->get('strata.restore_audit')->recent();

		$this->assertCount(1, $rows);
		$this->assertSame(RestoreAudit::PHYSICAL, $rows[0]['mode']);
		$this->assertSame(RestoreAudit::REFUSED, $rows[0]['outcome']);
		$this->assertSame('table/' . self::TABLE, $rows[0]['scope']);
	}

	#endregion

	#region Applying

	#[Test]
	#[TestDox('a restore puts the captured rows back and removes what came after')]
	#[Group('strata/restore')]
	public function restorePutsRowsBack(): void
	{
		$this->captureRow(1, 'original one');
		$this->captureRow(2, 'original two');
		$this->insert(1, 'original one');
		$this->insert(2, 'original two');
		$target = $this->commit();

		// the table moves on: one row edited, one added
		$database = $this->container->get('database');
		$database
			->update(self::TABLE)
			->fields(['label' => 'edited'])
			->condition('wid', 1)
			->execute();
		$this->insert(3, 'added later');

		$this->assertSame([1 => 'edited', 2 => 'original two', 3 => 'added later'], $this->rows());

		$result = $this->restore()->restore(self::TABLE, $target, 'truncate', true, true);

		$this->assertFalse($result->wasRefused(), (string) $result->refused);
		$this->assertSame(RestoreAudit::SUCCEEDED, $result->outcome());
		$this->assertSame([1 => 'original one', 2 => 'original two'], $this->rows());
	}

	#[Test]
	#[TestDox('a restore takes a snapshot first, so it can itself be undone')]
	#[Group('strata/restore')]
	public function restoreTakesSnapshot(): void
	{
		$this->captureRow(1, 'one');
		$this->insert(1, 'one');
		$target = $this->commit();

		$result = $this->restore()->restore(self::TABLE, $target, 'truncate', true, true);

		$this->assertNotNull($result->snapshot);
		$this->assertTrue($this->engine()->commitLog()->exists((string) $result->snapshot));
	}

	#[Test]
	#[TestDox('a dry run reports what it would do and changes nothing')]
	#[Group('strata/restore')]
	public function dryRunWritesNothing(): void
	{
		$this->captureRow(1, 'one');
		$this->insert(1, 'one');
		$this->insert(2, 'two');
		$target = $this->commit();

		$before = $this->rows();
		$result = $this->restore()->restore(self::TABLE, $target, 'truncate', false, true);

		$this->assertFalse($result->wasRefused());
		$this->assertNull($result->snapshot, 'a dry run takes no snapshot');
		$this->assertSame($before, $this->rows());
	}

	#[Test]
	#[TestDox('a failed restore leaves the table exactly as it was')]
	#[Group('strata/restore')]
	public function failedRestoreRollsBack(): void
	{
		// a captured row naming a column the table does not have cannot be written
		$payload = (string) json_encode(['wid' => 1, 'label' => 'one', 'gone' => 'x']);

		$this->journal()->append(
			new JournalOp(
				0,
				(int) round(microtime(true) * JournalOp::MICROSECONDS_PER_SECOND),
				Realm::TABLE,
				self::TABLE . ':wid=1',
				Verb::UPDATE,
				null,
				null,
				null,
				null,
				strlen($payload),
				'row with a stale column',
				['wid', 'label', 'gone'],
			),
			$payload,
		);

		$this->insert(1, 'live one');
		$this->insert(2, 'live two');
		$target = $this->commit();

		$result = $this->restore()->restore(self::TABLE, $target, 'truncate', true, true);

		$this->assertSame(RestoreAudit::FAILED, $result->outcome());
		$this->assertSame(
			[1 => 'live one', 2 => 'live two'],
			$this->rows(),
			'the rollback left the table untouched',
		);
	}

	#[Test]
	#[TestDox('the site is left out of maintenance mode after a truncate restore')]
	#[Group('strata/restore')]
	public function maintenanceModeIsReleased(): void
	{
		$this->captureRow(1, 'one');
		$this->insert(1, 'one');
		$target = $this->commit();

		$this->restore()->restore(self::TABLE, $target, 'truncate', true, true);

		$this->assertFalse(
			(bool) $this->container->get('state')->get('system.maintenance_mode'),
			'a restore that turned maintenance mode on turns it back off',
		);
	}

	#endregion
}
