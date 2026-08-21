<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Capture\PayloadCodec;
use Drupal\strata\Engine;
use Drupal\strata\Journal\JournalInterface;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves every realm a database can change is captured and can be put back.
 */
class CaptureRealmTest extends StrataKernelTestBase
{
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

		// the settings save above is itself a config change; start each test from a clean journal
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

	/**
	 * The journal entry for one subject, with its payload.
	 *
	 * @return array{operation: JournalOp, payload: string|null}|null
	 *   The entry, or NULL when the subject was not captured.
	 */
	private function entry(Realm $realm, string $subject): ?array
	{
		foreach ($this->journal()->read(5000) as $entry) {
			if (
				$entry['operation']->realm === $realm &&
				$entry['operation']->subject === $subject
			) {
				return $entry;
			}
		}

		return null;
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
	 * Creates a table nothing instruments, so only the statement tap can see writes to it.
	 */
	private function createWidgetTable(): void
	{
		$schema = $this->container->get('database')->schema();

		if (!$schema->tableExists('capture_widget')) {
			$schema->createTable('capture_widget', [
				'fields' => [
					'wid' => ['type' => 'serial', 'not null' => true],
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

		$this->journal()->trim(PHP_INT_MAX);
	}

	/**
	 * Writes a row straight to the database, bypassing every capture hook.
	 */
	private function insertWidget(string $label): void
	{
		$this->container
			->get('database')
			->insert('capture_widget')
			->fields(['label' => $label])
			->execute();
	}

	#region Config

	#[Test]
	#[TestDox('a saved config object is captured whole, with the keys it holds')]
	#[Group('strata/capture')]
	public function configSaveIsCaptured(): void
	{
		// a kernel test installs no system config, so the first save of system.site is a create
		$this->config('system.site')->set('name', 'First')->save();
		$this->journal()->trim(PHP_INT_MAX);

		$this->config('system.site')->set('name', 'Captured Site')->save();

		$entry = $this->entry(Realm::CONFIG, 'system.site');

		$this->assertNotNull($entry);
		$this->assertSame(Verb::UPDATE, $entry['operation']->verb);
		$this->assertContains('name', $entry['operation']->fields);
		$this->assertStringContainsString('Captured Site', (string) $entry['payload']);
		$this->assertStringContainsString('system.site', $entry['operation']->label);
	}

	#[Test]
	#[TestDox('a new config object is captured as a create')]
	#[Group('strata/capture')]
	public function configCreateIsCaptured(): void
	{
		// a config entity is the unambiguous create: the object cannot already exist
		Role::create(['id' => 'strata_demo', 'label' => 'Strata Demo'])->save();

		$entry = $this->entry(Realm::CONFIG, 'user.role.strata_demo');

		$this->assertNotNull($entry);
		$this->assertSame(Verb::CREATE, $entry['operation']->verb);
		$this->assertContains('label', $entry['operation']->fields);
		$this->assertStringContainsString('Strata Demo', (string) $entry['payload']);
	}

	#[Test]
	#[TestDox('a deleted config object is captured with no payload to restore')]
	#[Group('strata/capture')]
	public function configDeleteIsCaptured(): void
	{
		$this->config('system.date')->set('first_day', 1)->save();
		$this->journal()->trim(PHP_INT_MAX);

		$this->config('system.date')->delete();

		$entry = $this->entry(Realm::CONFIG, 'system.date');

		$this->assertNotNull($entry);
		$this->assertSame(Verb::DELETE, $entry['operation']->verb);
		$this->assertNull($entry['payload']);
		$this->assertSame(0, $entry['operation']->payloadLength);
	}

	#[Test]
	#[TestDox('this module\'s own settings are never captured')]
	#[Group('strata/capture')]
	public function ownSettingsAreNotCaptured(): void
	{
		$this->config('strata.settings')->set('site_id', 'changed')->save();

		$this->assertNull($this->entry(Realm::CONFIG, 'strata.settings'));
	}

	#[Test]
	#[TestDox('config capture stops when the realm is switched off')]
	#[Group('strata/capture')]
	public function configCaptureRespectsScope(): void
	{
		$this->config('strata.settings')->set('capture.config', false)->save();
		$this->container->get('strata.capture_scope')->reset();
		$this->journal()->trim(PHP_INT_MAX);

		$this->config('system.site')->set('name', 'Unwatched')->save();

		$this->assertNull($this->entry(Realm::CONFIG, 'system.site'));
	}

	#endregion

	#region State

	#[Test]
	#[TestDox('a state write is captured with the value it wrote')]
	#[Group('strata/capture')]
	public function stateWriteIsCaptured(): void
	{
		$this->container->get('state')->set('strata_demo.counter', 41);

		$entry = $this->entry(Realm::STATE, 'strata_demo.counter');

		$this->assertNotNull($entry);
		$this->assertSame(Verb::CREATE, $entry['operation']->verb);
		$this->assertSame(
			41,
			PayloadCodec::decode(Realm::STATE, (string) $entry['payload'])[PayloadCodec::VALUE],
		);
	}

	#[Test]
	#[TestDox('a second state write to the same key is an update, not another create')]
	#[Group('strata/capture')]
	public function stateOverwriteIsAnUpdate(): void
	{
		$state = $this->container->get('state');
		$state->set('strata_demo.counter', 1);
		$this->journal()->trim(PHP_INT_MAX);

		$state->set('strata_demo.counter', 2);

		$this->assertSame(
			Verb::UPDATE,
			$this->entry(Realm::STATE, 'strata_demo.counter')['operation']->verb,
		);
	}

	#[Test]
	#[TestDox('a state value that is null round trips as null rather than as missing')]
	#[Group('strata/capture')]
	public function stateNullIsNotMissing(): void
	{
		$this->container->get('state')->set('strata_demo.nothing', null);

		$entry = $this->entry(Realm::STATE, 'strata_demo.nothing');
		$decoded = PayloadCodec::decode(Realm::STATE, (string) $entry['payload']);

		$this->assertNotNull($decoded);
		$this->assertArrayHasKey(PayloadCodec::VALUE, $decoded);
		$this->assertNull($decoded[PayloadCodec::VALUE]);
	}

	#[Test]
	#[
		TestDox(
			'a state value that is false survives, since false is what unserialize returns on error',
		),
	]
	#[Group('strata/capture')]
	public function stateFalseSurvives(): void
	{
		$this->container->get('state')->set('strata_demo.off', false);

		$entry = $this->entry(Realm::STATE, 'strata_demo.off');
		$decoded = PayloadCodec::decode(Realm::STATE, (string) $entry['payload']);

		$this->assertNotNull($decoded);
		$this->assertFalse($decoded[PayloadCodec::VALUE]);
	}

	#[Test]
	#[TestDox('a batch state write is captured per key with one read for the batch')]
	#[Group('strata/capture')]
	public function stateBatchIsCaptured(): void
	{
		$this->container->get('state')->setMultiple([
			'strata_demo.a' => 'one',
			'strata_demo.b' => 'two',
		]);

		$this->assertNotNull($this->entry(Realm::STATE, 'strata_demo.a'));
		$this->assertNotNull($this->entry(Realm::STATE, 'strata_demo.b'));
	}

	#[Test]
	#[TestDox('deleting a state key that was never set records nothing')]
	#[Group('strata/capture')]
	public function stateDeleteOfNothingRecordsNothing(): void
	{
		$this->container->get('state')->delete('strata_demo.never');

		$this->assertNull($this->entry(Realm::STATE, 'strata_demo.never'));
	}

	#[Test]
	#[TestDox('a state delete is captured with no payload')]
	#[Group('strata/capture')]
	public function stateDeleteIsCaptured(): void
	{
		$state = $this->container->get('state');
		$state->set('strata_demo.doomed', 'here');
		$this->journal()->trim(PHP_INT_MAX);

		$state->delete('strata_demo.doomed');

		$entry = $this->entry(Realm::STATE, 'strata_demo.doomed');

		$this->assertNotNull($entry);
		$this->assertSame(Verb::DELETE, $entry['operation']->verb);
		$this->assertNull($entry['payload']);
	}

	#[Test]
	#[TestDox('reads pass through the decorator untouched')]
	#[Group('strata/capture')]
	public function stateReadsPassThrough(): void
	{
		$state = $this->container->get('state');
		$state->set('strata_demo.readable', ['nested' => ['deep' => 7]]);

		$this->assertSame(['nested' => ['deep' => 7]], $state->get('strata_demo.readable'));
		$this->assertSame('fallback', $state->get('strata_demo.absent', 'fallback'));
		$this->assertSame(
			['strata_demo.readable' => ['nested' => ['deep' => 7]]],
			$state->getMultiple(['strata_demo.readable']),
		);
	}

	#endregion

	#region Tables And Schema

	#[Test]
	#[TestDox('a raw write to a table nothing instruments is captured by the statement tap')]
	#[Group('strata/capture')]
	public function rawTableWriteIsCaptured(): void
	{
		$this->createWidgetTable();
		$tap = $this->container->get('strata.statement_capture');
		$tap->enable();
		$this->assertTrue($tap->isTapping(), 'the tap is on when capture.statements is set');

		$this->insertWidget('written directly');
		$this->assertSame(['capture_widget'], $tap->pending());

		$this->assertSame(1, $tap->commit());

		$entry = $this->entry(Realm::TABLE, 'capture_widget');

		$this->assertNotNull($entry);
		$this->assertSame(Verb::CREATE, $entry['operation']->verb);
		$this->assertContains('INSERT', $entry['operation']->fields);
		$this->assertStringContainsString('1 writes to capture_widget', $entry['operation']->label);
	}

	#[Test]
	#[TestDox('many writes to one table fold into one operation')]
	#[Group('strata/capture')]
	public function manyWritesFoldIntoOne(): void
	{
		$this->createWidgetTable();
		$tap = $this->container->get('strata.statement_capture');
		$tap->enable();

		for ($i = 0; $i < 5; $i++) {
			$this->insertWidget('row ' . $i);
		}

		$this->assertSame(5, $tap->writesTo('capture_widget')?->statements);
		$this->assertSame(1, $tap->commit(), 'five statements, one operation');
	}

	#[Test]
	#[TestDox('a destructive verb outranks the inserts it follows')]
	#[Group('strata/capture')]
	public function destructiveVerbWins(): void
	{
		$this->createWidgetTable();
		$tap = $this->container->get('strata.statement_capture');
		$tap->enable();

		$this->insertWidget('doomed');
		$this->container->get('database')->delete('capture_widget')->execute();

		$this->assertSame(Verb::DELETE, $tap->writesTo('capture_widget')?->verb);
	}

	#[Test]
	#[TestDox('a schema change outranks every content change on the same table')]
	#[Group('strata/capture')]
	public function schemaChangeOutranksContent(): void
	{
		$this->createWidgetTable();
		$tap = $this->container->get('strata.statement_capture');
		$tap->enable();

		$this->insertWidget('before the change');
		$this->container
			->get('database')
			->schema()
			->addField('capture_widget', 'extra', ['type' => 'int', 'not null' => false]);

		$writes = $tap->writesTo('capture_widget');

		$this->assertNotNull($writes);
		$this->assertSame(Realm::SCHEMA, $writes->realm);
		$this->assertSame(Verb::DDL, $writes->verb);
	}

	#[Test]
	#[TestDox('the tap stays off when statement capture is switched off')]
	#[Group('strata/capture')]
	public function tapRespectsItsSwitch(): void
	{
		$this->config('strata.settings')->set('capture.statements', false)->save();
		$this->container->get('strata.capture_scope')->reset();

		$tap = $this->container->get('strata.statement_capture');
		$tap->enable();

		$this->assertFalse($tap->isTapping());
	}

	#[Test]
	#[TestDox('this module\'s own tables are never captured by the tap')]
	#[Group('strata/capture')]
	public function ownTablesAreNotTapped(): void
	{
		$tap = $this->container->get('strata.statement_capture');
		$tap->enable();

		$this->container
			->get('database')
			->insert('strata_watermark')
			->fields(['table_name' => 'demo', 'row_count' => 1, 'checked' => 1])
			->execute();

		$this->assertSame([], $tap->pending());
	}

	#endregion

	#region Restore

	#[Test]
	#[TestDox('a config object is restored to the data it held at the target commit')]
	#[Group('strata/capture')]
	public function configIsRestorable(): void
	{
		$this->config('system.site')->set('name', 'Before')->save();
		$target = $this->commit();

		$this->config('system.site')->set('name', 'After')->save();
		$this->commit();

		$this->assertSame('After', $this->config('system.site')->get('name'));

		$result = $this->engine()
			->logicalRestore()
			->restore($target, ['config/system.site'], true);

		$this->assertFalse($result->wasRefused(), (string) $result->refused);
		$this->assertSame(['config/system.site'], $result->restored);
		$this->assertSame('Before', $this->config('system.site')->get('name'));
	}

	#[Test]
	#[TestDox('a state value is restored to what it held at the target commit')]
	#[Group('strata/capture')]
	public function stateIsRestorable(): void
	{
		$state = $this->container->get('state');
		$state->set('strata_demo.version', ['major' => 1]);
		$target = $this->commit();

		$state->set('strata_demo.version', ['major' => 2]);
		$this->commit();

		$this->assertSame(['major' => 2], $state->get('strata_demo.version'));

		$result = $this->engine()
			->logicalRestore()
			->restore($target, ['state/strata_demo.version'], true);

		$this->assertFalse($result->wasRefused(), (string) $result->refused);
		$this->assertSame(['major' => 1], $state->get('strata_demo.version'));
	}

	#[Test]
	#[TestDox('a schema change refuses to be replayed logically and says why')]
	#[Group('strata/capture')]
	public function schemaRefusesLogicalRestore(): void
	{
		$this->engine()->flusher()->flush(true);
		$this->container
			->get('strata.journal')
			->append(
				new JournalOp(
					0,
					(int) round(microtime(true) * 1000000),
					Realm::SCHEMA,
					'strata_demo_table',
					Verb::DDL,
					null,
					null,
					null,
					null,
					0,
					'CREATE TABLE strata_demo_table',
				),
			);
		$target = $this->commit();

		$result = $this->engine()
			->logicalRestore()
			->restore($target, ['schema/strata_demo_table'], true);

		$this->assertTrue($result->wasRefused());
	}

	#endregion
}
