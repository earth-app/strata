<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Capture\Reconciler;
use Drupal\strata\Engine;
use Drupal\strata\Health\Finding;
use Drupal\strata\Journal\JournalInterface;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves the reconciler detects changes nothing captured.
 *
 * This is the lane that makes "capture is complete" a measurement rather than a claim. Every other
 * test proves something WAS captured; these prove the module notices when something was not.
 */
class ReconcilerTest extends StrataKernelTestBase
{
	/**
	 * A table nothing instruments, so a write to it is invisible to every capture hook.
	 *
	 * Named without this module's own `strata_` prefix, because the capture scope excludes that
	 * prefix by design and a table named inside it would never be watched.
	 */
	private const TABLE = 'reconciler_widget';

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
		$this->createWidgetTable();
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

	private function reconciler(): Reconciler
	{
		return $this->container->get('strata.reconciler');
	}

	/**
	 * Creates a table with a single-column key and a changed timestamp.
	 */
	private function createWidgetTable(): void
	{
		$schema = $this->container->get('database')->schema();

		if ($schema->tableExists(self::TABLE)) {
			return;
		}

		$schema->createTable(self::TABLE, [
			'fields' => [
				'wid' => ['type' => 'serial', 'not null' => true],
				'label' => [
					'type' => 'varchar',
					'length' => 64,
					'not null' => true,
					'default' => '',
				],
				'changed' => ['type' => 'int', 'not null' => true, 'default' => 0],
			],
			'primary key' => ['wid'],
		]);
	}

	/**
	 * Writes a row straight to the database, bypassing every capture hook.
	 */
	private function insertWidget(string $label, int $changed = 0): int
	{
		return (int) $this->container
			->get('database')
			->insert(self::TABLE)
			->fields(['label' => $label, 'changed' => $changed])
			->execute();
	}

	/**
	 * Open finding codes.
	 *
	 * @return list<string>
	 *   The codes.
	 */
	private function ledgerCodes(): array
	{
		return array_values(
			array_map(
				static fn(Finding $finding): string => $finding->code,
				$this->container->get('strata.health_ledger')->open(),
			),
		);
	}

	#region Watermarks

	#[Test]
	#[TestDox('a first reading measures the table and reports no drift')]
	#[Group('strata/capture')]
	public function firstReadingIsNotDrift(): void
	{
		$this->insertWidget('one');

		$report = $this->reconciler()->reconcile([self::TABLE]);

		$this->assertTrue($report->isClean(), implode('; ', $report->problems));
		$this->assertSame(1, $report->examined);
		$this->assertSame(0, $report->changed, 'nothing to compare against yet');
		$this->assertSame(0, $report->drifted);
		$this->assertSame([], $this->ledgerCodes());
	}

	#[Test]
	#[TestDox('a reading records the row count, the highest key and the highest timestamp')]
	#[Group('strata/capture')]
	public function readingMeasuresFourThings(): void
	{
		$this->insertWidget('one', 1_700_000_000);
		$this->insertWidget('two', 1_700_000_500);

		$watermark = $this->reconciler()->observe(self::TABLE);

		$this->assertSame(self::TABLE, $watermark->table);
		$this->assertSame(2, $watermark->rowCount);
		$this->assertSame('2', $watermark->maxKey);
		$this->assertSame(1_700_000_500, $watermark->maxChanged);
		$this->assertNotSame('', $watermark->digest);
		$this->assertTrue($watermark->isMeasured());
	}

	#[Test]
	#[TestDox('an empty table measures as empty rather than as unreadable')]
	#[Group('strata/capture')]
	public function emptyTableIsMeasured(): void
	{
		$watermark = $this->reconciler()->observe(self::TABLE);

		$this->assertSame(0, $watermark->rowCount);
		$this->assertNull($watermark->maxKey);
		$this->assertSame('', $watermark->digest, 'no rows means no sample');
		$this->assertTrue($watermark->isMeasured());
	}

	#[Test]
	#[TestDox('a reading is stored and read back')]
	#[Group('strata/capture')]
	public function readingsPersist(): void
	{
		$this->insertWidget('one');
		$observed = $this->reconciler()->observe(self::TABLE);
		$this->reconciler()->store($observed);

		$stored = $this->reconciler()->stored(self::TABLE);

		$this->assertNotNull($stored);
		$this->assertSame($observed->rowCount, $stored->rowCount);
		$this->assertSame($observed->maxKey, $stored->maxKey);
		$this->assertSame($observed->maxChanged, $stored->maxChanged);
		$this->assertSame($observed->digest, $stored->digest);
	}

	#[Test]
	#[TestDox('a table never read has no stored reading')]
	#[Group('strata/capture')]
	public function unreadTableHasNoReading(): void
	{
		$this->assertNull($this->reconciler()->stored(self::TABLE));
	}

	#endregion

	#region Detecting Drift

	#[Test]
	#[TestDox('a write nothing captured is reported as drift, and names what moved')]
	#[Group('strata/capture')]
	public function uncapturedWriteIsDrift(): void
	{
		$this->reconciler()->reconcile([self::TABLE]);
		$this->insertWidget('appeared out of nowhere');

		$report = $this->reconciler()->reconcile([self::TABLE], 100, false);

		$this->assertFalse($report->isClean());
		$this->assertSame(1, $report->changed);
		$this->assertSame(1, $report->drifted);
		$this->assertArrayHasKey(self::TABLE, $report->drift);
		$this->assertStringContainsString('rows 0 to 1', $report->drift[self::TABLE]);
		$this->assertContains('watermark.drift', $this->ledgerCodes());
	}

	#[Test]
	#[TestDox('an in-place update with no row count change is still caught by the digest')]
	#[Group('strata/capture')]
	public function inPlaceUpdateIsCaught(): void
	{
		$id = $this->insertWidget('before', 0);
		$this->reconciler()->reconcile([self::TABLE]);

		// the row count and the highest key are unchanged, so only the digest can see this
		$this->container
			->get('database')
			->update(self::TABLE)
			->fields(['label' => 'after'])
			->condition('wid', $id)
			->execute();

		$report = $this->reconciler()->reconcile([self::TABLE], 100, false);

		$this->assertSame(1, $report->drifted);
		$this->assertStringContainsString('sampled rows differ', $report->drift[self::TABLE]);
	}

	#[Test]
	#[TestDox('an insert paired with deleting an older row is caught by the highest key')]
	#[Group('strata/capture')]
	public function insertWithOlderDeleteIsCaught(): void
	{
		$older = $this->insertWidget('older');
		$this->reconciler()->reconcile([self::TABLE]);

		// the count comes back to one, so only the moved maximum can see this
		$this->insertWidget('newer');
		$this->container->get('database')->delete(self::TABLE)->condition('wid', $older)->execute();

		$report = $this->reconciler()->reconcile([self::TABLE], 100, false);

		$this->assertSame(1, $report->drifted);
		$this->assertStringContainsString('key 1 to 2', $report->drift[self::TABLE]);
	}

	#[Test]
	#[TestDox('a row inserted and deleted again between readings is a stated blind spot')]
	#[Group('strata/capture')]
	public function insertThenDeleteOfTheSameRowIsNotCaught(): void
	{
		$this->insertWidget('stays');
		$this->reconciler()->reconcile([self::TABLE]);

		$database = $this->container->get('database');
		$transient = $this->insertWidget('transient');
		$database->delete(self::TABLE)->condition('wid', $transient)->execute();

		$report = $this->reconciler()->reconcile([self::TABLE], 100, false);

		// the count returns to one and MAX(wid) drops back with the deleted row, so nothing moved.
		// this is the limit Watermark documents, asserted so a future change cannot claim otherwise
		// without this test failing
		$this->assertSame(0, $report->drifted);
		$this->assertTrue($report->isClean());
	}

	#[Test]
	#[TestDox('a table that did not change is not reported')]
	#[Group('strata/capture')]
	public function unchangedTableIsQuiet(): void
	{
		$this->insertWidget('settled');
		$this->reconciler()->reconcile([self::TABLE]);

		$report = $this->reconciler()->reconcile([self::TABLE]);

		$this->assertTrue($report->isClean());
		$this->assertSame(0, $report->changed);
		$this->assertStringContainsString('all accounted for', $report->summary());
	}

	#[Test]
	#[TestDox('a change that WAS captured is not reported as drift')]
	#[Group('strata/capture')]
	public function capturedChangeIsNotDrift(): void
	{
		$this->reconciler()->reconcile([self::TABLE]);

		// the statement tap is what would normally do this; the journal entry is what matters here
		$this->insertWidget('accounted for');
		$this->container->get('strata.statement_capture')->enable();
		$this->recordTableOperation();

		$report = $this->reconciler()->reconcile([self::TABLE], 100, false);

		$this->assertSame(1, $report->changed);
		$this->assertSame(0, $report->drifted, 'the journal accounts for the change');
		$this->assertTrue($report->isClean());
	}

	#endregion

	#region Closing The Gap

	#[Test]
	#[TestDox('a drifted table has its rows captured, so the gap closes')]
	#[Group('strata/capture')]
	public function driftedRowsAreCaptured(): void
	{
		$this->reconciler()->reconcile([self::TABLE]);
		$first = $this->insertWidget('one');
		$this->insertWidget('two');

		$report = $this->reconciler()->reconcile([self::TABLE], 100, true);

		$this->assertSame(1, $report->drifted);
		$this->assertSame(2, $report->captured);

		$subjects = [];

		foreach ($this->journal()->read(100) as $entry) {
			if ($entry['operation']->realm === Realm::TABLE) {
				$subjects[] = $entry['operation']->subject;
			}
		}

		$this->assertContains(sprintf('%s:wid=%d', self::TABLE, $first), $subjects);
	}

	#[Test]
	#[TestDox('a captured row carries its columns, so a restore has something to write')]
	#[Group('strata/capture')]
	public function capturedRowsCarryTheirColumns(): void
	{
		$this->reconciler()->reconcile([self::TABLE]);
		$id = $this->insertWidget('restorable', 1_700_000_000);

		$this->reconciler()->reconcile([self::TABLE], 100, true);

		foreach ($this->journal()->read(100) as $entry) {
			if ($entry['operation']->subject !== sprintf('%s:wid=%d', self::TABLE, $id)) {
				continue;
			}

			$row = json_decode((string) $entry['payload'], true);

			$this->assertSame('restorable', $row['label']);
			$this->assertSame(1_700_000_000, (int) $row['changed']);
			$this->assertContains('label', $entry['operation']->fields);

			return;
		}

		$this->fail('the drifted row was not captured');
	}

	#[Test]
	#[TestDox('a dry run reports drift without capturing anything')]
	#[Group('strata/capture')]
	public function dryRunCapturesNothing(): void
	{
		$this->reconciler()->reconcile([self::TABLE]);
		$this->insertWidget('unseen');

		$report = $this->reconciler()->reconcile([self::TABLE], 100, false);

		$this->assertSame(1, $report->drifted);
		$this->assertSame(0, $report->captured);
		$this->assertSame(0, $this->journal()->pending());
	}

	#endregion

	#region Scope And Bounds

	#[Test]
	#[TestDox('this module\'s own tables are never watched')]
	#[Group('strata/capture')]
	public function ownTablesAreNotWatched(): void
	{
		$tables = $this->reconciler()->tables();

		$this->assertNotEmpty($tables);
		$this->assertContains(self::TABLE, $tables, 'a table in scope is watched');

		foreach ($tables as $table) {
			$this->assertStringStartsNotWith('strata_', $table);
		}
	}

	#[Test]
	#[TestDox('a bounded pass says it did not cover everything')]
	#[Group('strata/capture')]
	public function boundedPassSaysSo(): void
	{
		$all = $this->reconciler()->tables();
		$this->assertGreaterThan(1, count($all));

		$report = $this->reconciler()->reconcile([], 1);

		$this->assertFalse($report->complete);
		$this->assertSame(1, $report->examined);
		$this->assertStringContainsString('of the captured tables', $report->summary());
	}

	#[Test]
	#[TestDox('a table that cannot be read is reported rather than skipped silently')]
	#[Group('strata/capture')]
	public function unreadableTableIsReported(): void
	{
		$report = $this->reconciler()->reconcile(['strata_no_such_table_exists'], 100, false);

		$this->assertFalse($report->isClean());
		$this->assertCount(1, $report->problems);
		$this->assertSame(0, $report->examined);
		$this->assertStringContainsString('unreadable', $report->summary());
	}

	#[Test]
	#[TestDox('forgetting the readings makes the next pass a baseline rather than a drift storm')]
	#[Group('strata/capture')]
	public function forgettingResetsTheBaseline(): void
	{
		$this->insertWidget('one');
		$this->reconciler()->reconcile([self::TABLE]);

		$this->assertGreaterThan(0, $this->reconciler()->forget());
		$this->assertNull($this->reconciler()->stored(self::TABLE));

		$this->insertWidget('two');
		$report = $this->reconciler()->reconcile([self::TABLE], 100, false);

		$this->assertSame(0, $report->drifted, 'a first reading after a reset cannot be drift');
	}

	#endregion

	/**
	 * Appends a table operation, standing in for what the statement tap would have recorded.
	 */
	private function recordTableOperation(): void
	{
		$this->container->get('strata.statement_capture')->commit();

		if ($this->journal()->pending() > 0) {
			return;
		}

		$this->journal()->append(
			new JournalOp(
				0,
				(int) round(microtime(true) * 1_000_000),
				Realm::TABLE,
				self::TABLE,
				Verb::CREATE,
				null,
				null,
				null,
				null,
				0,
				'1 write to ' . self::TABLE,
			),
		);
	}
}
