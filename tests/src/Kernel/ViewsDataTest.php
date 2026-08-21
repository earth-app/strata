<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\strata\Cas\DatabaseFrameIndex;
use Drupal\strata\Capture\Classifier\ClassificationRegistry;
use Drupal\strata\Codec\Dictionary\DictionaryStore;
use Drupal\strata\Health\DatabaseHealthLedger;
use Drupal\strata\Health\Finding;
use Drupal\strata\Journal\DatabaseJournal;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;
use Drupal\strata\Plugin\views\field\Bytes;
use Drupal\strata\Plugin\views\field\ClassificationField;
use Drupal\strata\Plugin\views\field\RealmField;
use Drupal\strata\Plugin\views\field\SeverityField;
use Drupal\strata\Plugin\views\field\VerbField;
use Drupal\strata\Restore\RestoreAudit;
use Drupal\strata\Tree\CommitIndex;
use Drupal\strata\Tree\SubjectIndex;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves Views collects Strata's tables and can build a query over them.
 *
 * The one thing the plan flagged as needing checking rather than asserting: whether Views' data
 * collection picks up an attribute hook in 11.2, or whether a `strata.views.inc` file is still needed.
 * The answer is here rather than in a comment.
 */
class ViewsDataTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'views', 'key', 'strata'];

	/**
	 * Every table the module exposes.
	 *
	 * @return array<string, array{string, string}>
	 *   Table name and its base field.
	 */
	public static function tableProvider(): array
	{
		return [
			'the journal' => [DatabaseJournal::TABLE, 'sequence'],
			'the commit index' => [CommitIndex::TABLE, 'id'],
			'the subject index' => [SubjectIndex::TABLE, 'subject'],
			'the frame index' => [DatabaseFrameIndex::TABLE, 'hash'],
			'the dictionary index' => [DictionaryStore::TABLE, 'id'],
			'the restore audit' => [RestoreAudit::TABLE, 'id'],
			'the health ledger' => [DatabaseHealthLedger::TABLE, 'id'],
			'the classification registry' => [ClassificationRegistry::TABLE, 'pattern'],
		];
	}

	#region Collection

	#[Test]
	#[TestDox('an attribute hook is enough: views collects the data with no .views.inc file')]
	#[Group('strata/views')]
	public function attributeHookIsCollected(): void
	{
		$data = Views::viewsData()->get(CommitIndex::TABLE);

		$this->assertNotEmpty($data, 'the #[Hook(\'views_data\')] implementation was collected');
		$this->assertFileDoesNotExist(
			dirname(__DIR__, 3) . '/strata.views.inc',
			'no procedural fallback is needed',
		);
	}

	#[Test]
	#[TestDox('$_dataName is exposed with a base field views can query on')]
	#[Group('strata/views')]
	#[DataProvider('tableProvider')]
	public function tableIsExposed(string $table, string $field): void
	{
		$data = Views::viewsData()->get($table);

		$this->assertNotEmpty($data);
		$this->assertSame('Strata', (string) $data['table']['group']);
		$this->assertSame('strata', $data['table']['provider']);
		$this->assertSame($field, $data['table']['base']['field']);
		$this->assertArrayHasKey($field, $data, 'the base field is exposed as a column');
	}

	#[Test]
	#[TestDox('every exposed column carries a title, so nothing shows up unnamed')]
	#[Group('strata/views')]
	public function everyColumnIsNamed(): void
	{
		foreach (array_keys(self::tableProvider()) as $name) {
			[$table] = self::tableProvider()[$name];

			foreach (Views::viewsData()->get($table) as $column => $definition) {
				if ($column === 'table') {
					continue;
				}

				$this->assertArrayHasKey('title', $definition, sprintf('%s.%s', $table, $column));
				$this->assertNotSame('', (string) $definition['title']);
			}
		}
	}

	#[Test]
	#[TestDox('every exposed column exists in the table it is exposed from')]
	#[Group('strata/views')]
	public function everyColumnExists(): void
	{
		$schema = $this->container->get('database')->schema();

		foreach (array_keys(self::tableProvider()) as $name) {
			[$table] = self::tableProvider()[$name];

			foreach (Views::viewsData()->get($table) as $column => $definition) {
				// the table entry describes the table, and a relationship names a column of its own
				if ($column === 'table' || isset($definition['relationship']['field'])) {
					continue;
				}
				if (isset($definition['relationship']) && !isset($definition['field'])) {
					continue;
				}

				$this->assertTrue(
					$schema->fieldExists($table, (string) $column),
					sprintf('%s.%s is exposed but not in the schema', $table, (string) $column),
				);
			}
		}
	}

	#[Test]
	#[TestDox('a commit joins to its parent, so a history view is possible')]
	#[Group('strata/views')]
	public function commitJoinsToItsParent(): void
	{
		$data = Views::viewsData()->get(CommitIndex::TABLE);

		$this->assertArrayHasKey('parent_commit', $data);
		$this->assertSame(CommitIndex::TABLE, $data['parent_commit']['relationship']['base']);
		$this->assertSame('id', $data['parent_commit']['relationship']['base field']);
		$this->assertSame('parent', $data['parent_commit']['relationship']['field']);
	}

	#[Test]
	#[TestDox('a restore joins to the commit it targeted')]
	#[Group('strata/views')]
	public function restoreJoinsToItsTarget(): void
	{
		$data = Views::viewsData()->get(RestoreAudit::TABLE);

		$this->assertArrayHasKey('target_commit', $data);
		$this->assertSame(CommitIndex::TABLE, $data['target_commit']['relationship']['base']);
	}

	#[Test]
	#[TestDox('an actor joins to the user account, so a view can show who did it')]
	#[Group('strata/views')]
	public function actorJoinsToTheUser(): void
	{
		foreach ([CommitIndex::TABLE, DatabaseJournal::TABLE, RestoreAudit::TABLE] as $table) {
			$data = Views::viewsData()->get($table);

			$this->assertArrayHasKey('actor', $data, $table);
			$this->assertSame('users_field_data', $data['actor']['relationship']['base']);
			$this->assertSame('uid', $data['actor']['relationship']['base field']);
			// Drupal 11 has no user_name views field; the uid is shown and the join supplies the account
			$this->assertSame('numeric', $data['actor']['field']['id']);
		}
	}

	#endregion

	#region Handlers

	#[Test]
	#[TestDox('the strata handlers are registered, so a view that names one is not broken')]
	#[Group('strata/views')]
	public function handlersAreRegistered(): void
	{
		$fields = $this->container->get('plugin.manager.views.field');

		foreach (
			[
				'strata_bytes',
				'strata_digest',
				'strata_commit',
				'strata_microtime',
				'strata_realm',
				'strata_verb',
				'strata_severity',
				'strata_classification',
			]
			as $id
		) {
			$this->assertTrue($fields->hasDefinition($id), $id);
		}

		$filters = $this->container->get('plugin.manager.views.filter');

		foreach (
			['strata_realm', 'strata_verb', 'strata_severity', 'strata_classification']
			as $id
		) {
			$this->assertTrue($filters->hasDefinition($id), $id);
		}
	}

	#[Test]
	#[TestDox('every column names a handler that exists')]
	#[Group('strata/views')]
	public function everyColumnNamesARealHandler(): void
	{
		$managers = [
			'field' => $this->container->get('plugin.manager.views.field'),
			'filter' => $this->container->get('plugin.manager.views.filter'),
			'sort' => $this->container->get('plugin.manager.views.sort'),
			'argument' => $this->container->get('plugin.manager.views.argument'),
			'relationship' => $this->container->get('plugin.manager.views.relationship'),
		];

		foreach (array_keys(self::tableProvider()) as $name) {
			[$table] = self::tableProvider()[$name];

			foreach (Views::viewsData()->get($table) as $column => $definition) {
				if ($column === 'table') {
					continue;
				}

				foreach ($managers as $kind => $manager) {
					if (!isset($definition[$kind]['id'])) {
						continue;
					}

					$this->assertTrue(
						$manager->hasDefinition($definition[$kind]['id']),
						sprintf(
							'%s.%s uses the %s handler %s',
							$table,
							$column,
							$kind,
							$definition[$kind]['id'],
						),
					);
				}
			}
		}
	}

	#[Test]
	#[TestDox('a byte count renders in the largest unit that stays readable')]
	#[Group('strata/views')]
	public function bytesRenderReadably(): void
	{
		$this->assertSame('0 B', Bytes::format(0));
		$this->assertSame('512 B', Bytes::format(512));
		$this->assertSame('1.0 KiB', Bytes::format(1024));
		$this->assertSame('4.0 MiB', Bytes::format(4_194_304));
		$this->assertSame('1.00 GiB', Bytes::format(1_073_741_824, 2));
		$this->assertSame('-1.0 KiB', Bytes::format(-1024));
	}

	#[Test]
	#[TestDox('an enum column offers exactly the values the enum has')]
	#[Group('strata/views')]
	public function enumOptionsComeFromTheEnum(): void
	{
		$this->assertCount(count(Realm::cases()), RealmField::options());
		$this->assertSame(Realm::KEY_VALUE->label(), RealmField::options()['keyvalue']);
		$this->assertCount(count(Verb::cases()), VerbField::options());
		$this->assertSame(Verb::DDL->label(), VerbField::options()['ddl']);
		$this->assertSame('WARN', SeverityField::options()[Finding::WARN]);
		$this->assertArrayHasKey('unclassified', ClassificationField::options());
	}

	#endregion

	#region Querying

	/**
	 * Installs the shipped views, which are optional config a kernel test does not get by default.
	 *
	 * Read from the module's own directory and saved as they are, rather than through the optional
	 * config installer: that installer scans every enabled module's optional config, and core's brings
	 * in dependencies this lane has no reason to boot.
	 */
	private function installDefaultViews(): void
	{
		$directory = dirname(__DIR__, 3) . '/config/optional';

		foreach ((array) glob($directory . '/views.view.strata_*.yml') as $file) {
			/** @var array<string, mixed> $data */
			$data = Yaml::decode((string) file_get_contents((string) $file));

			$this->config('views.view.' . $data['id'])
				->setData($data)
				->save();
		}

		$this->container->get('views.views_data')->clear();
	}

	#[Test]
	#[TestDox('a view over the commit index builds and executes')]
	#[Group('strata/views')]
	public function viewOverCommitsExecutes(): void
	{
		$this->installDefaultViews();

		$view = Views::getView('strata_commits');

		$this->assertNotNull($view, 'the default view is installed');

		$view->setDisplay('page_1');
		$view->preExecute();
		$view->execute();

		$this->assertSame(
			[],
			$view->result,
			'an empty history returns no rows rather than failing',
		);
		$this->assertNotSame('', (string) $view->build_info['query']);
	}

	#[Test]
	#[TestDox('every default view installs and executes')]
	#[Group('strata/views')]
	public function everyDefaultViewExecutes(): void
	{
		$this->installDefaultViews();

		foreach (
			[
				'strata_commits',
				'strata_operations',
				'strata_frames',
				'strata_restores',
				'strata_health',
			]
			as $id
		) {
			$view = Views::getView($id);

			$this->assertNotNull($view, $id);

			$view->setDisplay();
			$view->preExecute();
			$view->execute();

			$this->assertIsArray($view->result, $id);
		}
	}

	#endregion
}
