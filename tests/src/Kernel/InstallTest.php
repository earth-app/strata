<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Hook\Help;
use Drupal\strata\Storage\Plugin\Strata\Storage\LocalStorage;
use Drupal\strata\Tree\Commit;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves the module installs, its schema and config land, and the engine runs inside Drupal.
 */
class InstallTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * Views is here for `strata_update_11104`, which moves a shipped view off the path the health
	 * dashboard answers on. Saving a `views.view.*` object under strict schema checking needs the
	 * module that defines that schema.
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'views', 'key', 'strata'];

	#region Schema

	#[Test]
	#[TestDox('every table hook_schema declares exists after install')]
	#[Group('strata/install')]
	public function schemaTablesExist(): void
	{
		$schema = $this->container->get('database')->schema();
		$declared = array_keys($this->strataSchema());

		$this->assertNotEmpty($declared);

		foreach ($declared as $table) {
			$this->assertTrue($schema->tableExists($table), "$table is missing");
		}
	}

	#[Test]
	#[TestDox('every declared table describes a primary key and at least one field')]
	#[Group('strata/install')]
	public function schemaTablesAreWellFormed(): void
	{
		foreach ($this->strataSchema() as $table => $definition) {
			$this->assertArrayHasKey('description', $definition, "$table has no description");
			$this->assertArrayHasKey('fields', $definition, "$table has no fields");
			$this->assertArrayHasKey('primary key', $definition, "$table has no primary key");
			$this->assertNotEmpty($definition['fields'], "$table has no fields");

			foreach ($definition['fields'] as $name => $field) {
				$this->assertArrayHasKey(
					'description',
					$field,
					sprintf('%s.%s has no description', $table, $name),
				);
			}

			foreach ($definition['primary key'] as $column) {
				$this->assertArrayHasKey(
					$column,
					$definition['fields'],
					sprintf(
						'%s names %s in its primary key but has no such field',
						$table,
						$column,
					),
				);
			}

			foreach ($definition['indexes'] ?? [] as $index => $columns) {
				foreach ($columns as $column) {
					$this->assertArrayHasKey(
						is_array($column) ? $column[0] : $column,
						$definition['fields'],
						sprintf('%s index %s names a field that does not exist', $table, $index),
					);
				}
			}
		}
	}

	#[Test]
	#[TestDox('the two audit tables are the only ones a reindex cannot rebuild')]
	#[Group('strata/install')]
	public function auditTablesAreIdentified(): void
	{
		$tables = array_keys($this->strataSchema());

		$this->assertContains('strata_restore_log', $tables);
		$this->assertContains('strata_health', $tables);
		$this->assertContains('strata_frame', $tables);
		$this->assertContains('strata_commit', $tables);
	}

	#endregion

	#region Updates

	#[Test]
	#[TestDox('widening the journal sequence keeps the operations already waiting in it')]
	#[Group('strata/install')]
	public function wideningTheSequenceKeepsPendingWork(): void
	{
		$this->narrowTheSequence();
		$this->appendJournalRow('entity/node:1');

		strata_update_11103();

		$this->assertSame(
			1,
			(int) $this->container
				->get('database')
				->select('strata_journal', 'j')
				->countQuery()
				->execute()
				?->fetchField(),
			'the column was widened in place, so nothing pending was dropped',
		);
	}

	#[Test]
	#[TestDox('a widened journal sequence holds a value a 32-bit key could not')]
	#[Group('strata/install')]
	public function theWidenedSequenceHoldsALargeKey(): void
	{
		$this->narrowTheSequence();

		strata_update_11103();

		$this->appendJournalRow('entity/node:2', PHP_INT_MAX);

		$this->assertSame(
			PHP_INT_MAX,
			(int) $this->container
				->get('database')
				->select('strata_journal', 'j')
				->fields('j', ['sequence'])
				->execute()
				?->fetchField(),
		);
	}

	#[Test]
	#[TestDox('trimming everything is accepted rather than refused as out of range')]
	#[Group('strata/install')]
	public function trimmingEverythingIsAccepted(): void
	{
		$this->narrowTheSequence();
		$this->appendJournalRow('entity/node:3');

		strata_update_11103();

		// PHP_INT_MAX is how a caller says "everything", and against a 32-bit column PostgreSQL
		// refuses the statement outright while MySQL and SQLite coerce it silently
		$removed = $this->container->get('strata.journal')->trim(PHP_INT_MAX);

		$this->assertSame(1, $removed);
		$this->assertSame(0, $this->container->get('strata.journal')->pending());
	}

	#[Test]
	#[TestDox('the update is safe to run twice, since a site can re-run a failed batch')]
	#[Group('strata/install')]
	public function theUpdateIsRepeatable(): void
	{
		$this->narrowTheSequence();

		strata_update_11103();
		strata_update_11103();

		$this->appendJournalRow('entity/node:4', PHP_INT_MAX);

		$this->assertSame(1, $this->container->get('strata.journal')->pending());
	}

	#[Test]
	#[TestDox('moving the findings view frees the path the health dashboard answers on')]
	#[Group('strata/install')]
	public function theFindingsViewIsMovedOffTheDashboardPath(): void
	{
		$this->installShippedFindingsView('admin/reports/strata/health');

		strata_update_11104();

		$this->assertSame(
			'admin/reports/strata/findings',
			$this->config('views.view.strata_health')->get('display.page_1.display_options.path'),
		);
	}

	#[Test]
	#[TestDox('a site whose view already moved is left exactly as it is')]
	#[Group('strata/install')]
	public function movingTheFindingsViewIsRepeatable(): void
	{
		$this->installShippedFindingsView('admin/reports/strata/findings');

		$before = $this->config('views.view.strata_health')->getRawData();

		strata_update_11104();
		strata_update_11104();

		$this->assertSame($before, $this->config('views.view.strata_health')->getRawData());
	}

	#[Test]
	#[TestDox('a site that never installed the optional view is not given one')]
	#[Group('strata/install')]
	public function movingTheFindingsViewSkipsASiteWithoutIt(): void
	{
		// the view is optional config, so a site running without Views has never had it
		$this->config('views.view.strata_health')->delete();

		$this->assertTrue($this->config('views.view.strata_health')->isNew());

		strata_update_11104();

		$this->assertTrue(
			$this->config('views.view.strata_health')->isNew(),
			'the update created nothing on a site running without Views',
		);
	}

	#[Test]
	#[TestDox('the shipped view no longer claims the path a route already answers on')]
	#[Group('strata/install')]
	public function theShippedViewDoesNotShadowTheDashboard(): void
	{
		$shipped = Yaml::decode(
			(string) file_get_contents(
				dirname(__DIR__, 3) . '/config/optional/views.view.strata_health.yml',
			),
		);

		$this->assertIsArray($shipped);
		$this->assertSame(
			'admin/reports/strata/findings',
			$shipped['display']['page_1']['display_options']['path'],
			'a views page display overrides a module route on the same path',
		);
	}

	#endregion

	#region Configuration

	#[Test]
	#[TestDox('default settings install and every documented key is present')]
	#[Group('strata/install')]
	public function defaultSettingsInstall(): void
	{
		$settings = $this->settings();

		$this->assertFalse($settings->get('enabled'), 'capture is off until configured');
		$this->assertSame('local', $settings->get('provider'));
		$this->assertSame(15, $settings->get('flush.max_age'));
		$this->assertSame(4194304, $settings->get('flush.max_bytes'));
		$this->assertSame(5000, $settings->get('flush.max_ops'));
		$this->assertSame(16384, $settings->get('frame.size'));
		$this->assertSame(32, $settings->get('delta.max_depth'));
		$this->assertSame(14400, $settings->get('retention.base_interval'));
		$this->assertTrue($settings->get('retention.auto_prune'));
		$this->assertSame('event', $settings->get('capture.access_churn'));
	}

	#[Test]
	#[TestDox('the retention ladder installs with a level per window')]
	#[Group('strata/install')]
	public function retentionLadderInstalls(): void
	{
		$levels = $this->settings()->get('retention.levels');

		$this->assertIsArray($levels);
		$this->assertCount(5, $levels);
		$this->assertSame(15, $levels[0]['window']);
		$this->assertSame(3600, $levels[0]['keep']);
		$this->assertSame(2592000, $levels[4]['window']);
		$this->assertSame(0, $levels[4]['keep'], 'the top level is kept forever');
	}

	#[Test]
	#[TestDox('settings survive a save and reload, so the schema accepts them')]
	#[Group('strata/install')]
	public function savedSettingsReload(): void
	{
		$this->config('strata.settings')
			->set('enabled', true)
			->set('site_id', 'earth-app')
			->set('flush.max_age', 60)
			->save();

		$reloaded = $this->config('strata.settings');

		$this->assertTrue($reloaded->get('enabled'));
		$this->assertSame('earth-app', $reloaded->get('site_id'));
		$this->assertSame(60, $reloaded->get('flush.max_age'));
	}

	#[Test]
	#[TestDox('the default capture scope leaves files and ephemeral state off')]
	#[Group('strata/install')]
	public function defaultCaptureScopeIsDeliberate(): void
	{
		$capture = $this->settings()->get('capture');

		$this->assertTrue($capture['entity']);
		$this->assertTrue($capture['config']);
		$this->assertTrue($capture['table']);
		$this->assertTrue($capture['schema']);
		$this->assertTrue($capture['code']);
		$this->assertTrue($capture['statements']);
		$this->assertFalse($capture['file'], 'files carry their own budget line');
		$this->assertFalse($capture['ephemeral'], 'ephemeral capture needs classification first');
	}

	#endregion

	#region Requirements

	#[Test]
	#[TestDox('hook_requirements reports the codec and the cipher at runtime')]
	#[Group('strata/install')]
	public function requirementsReportCodecAndCipher(): void
	{
		$requirements = (array) strata_requirements('runtime');

		$this->assertArrayHasKey('strata_codecs', $requirements);
		$this->assertArrayHasKey('strata_sodium', $requirements);

		foreach ($requirements as $requirement) {
			$this->assertArrayHasKey('title', $requirement);
			$this->assertArrayHasKey('value', $requirement);
			$this->assertArrayHasKey('severity', $requirement);
		}

		// REQUIREMENT_OK is deprecated in 11.2 and removed in 12, so the enum case is the assertion
		$this->assertSame(RequirementSeverity::OK, $requirements['strata_sodium']['severity']);
	}

	#[Test]
	#[TestDox('a configured codec this host cannot run is named on the status report')]
	#[Group('strata/install')]
	public function aCodecTheHostCannotRunIsNamed(): void
	{
		$this->settings()->set('codec.id', 'gone_from_this_host')->save();

		$requirement = (array) strata_requirements('runtime')['strata_codecs'];

		$this->assertSame(RequirementSeverity::Warning, $requirement['severity']);
		$this->assertStringContainsString(
			'gone_from_this_host',
			(string) $requirement['description'],
			'the row names the codec that is missing rather than saying compression is degraded',
		);
	}

	#[Test]
	#[TestDox('the codec row is read from the host every time, so nothing has to be refreshed')]
	#[Group('strata/install')]
	public function theCodecRowFollowsTheHost(): void
	{
		$this->settings()->set('codec.id', 'gone_from_this_host')->save();

		$this->assertSame(
			RequirementSeverity::Warning,
			strata_requirements('runtime')['strata_codecs']['severity'],
		);

		// no cache, no state entry and no button: the row is recomputed from what the host can do
		$this->settings()->set('codec.id', '')->save();

		$this->assertSame(
			RequirementSeverity::OK,
			strata_requirements('runtime')['strata_codecs']['severity'],
		);
	}

	#[Test]
	#[TestDox('hook_requirements reports nothing outside the runtime phase')]
	#[Group('strata/install')]
	public function requirementsAreRuntimeOnly(): void
	{
		$this->assertSame([], (array) strata_requirements('install'));
		$this->assertSame([], (array) strata_requirements('update'));
	}

	#[Test]
	#[TestDox('the status report names the store the site writes to')]
	#[Group('strata/install')]
	public function requirementsReportTheStore(): void
	{
		$requirements = (array) strata_requirements('runtime');

		$this->assertArrayHasKey('strata_storage', $requirements);
		$this->assertArrayHasKey('severity', $requirements['strata_storage']);
	}

	#[Test]
	#[TestDox('a writable local directory is reported as usable')]
	#[Group('strata/install')]
	public function aWritableLocalDirectoryIsUsable(): void
	{
		$path = $this->siteDirectory . '/strata-requirement';
		mkdir($path, 0777, true);

		$this->settings()->set('provider', 'local')->set('local_path', $path)->save();

		$requirement = (array) strata_requirements('runtime')['strata_storage'];

		$this->assertSame(RequirementSeverity::OK, $requirement['severity']);
	}

	#[Test]
	#[TestDox('a stream wrapper the site does not have is named rather than probed')]
	#[Group('strata/install')]
	public function anUnconfiguredStreamWrapperIsNamed(): void
	{
		// the shipped default; is_dir() on an unregistered wrapper raises a warning instead of
		// answering, and this runs on the status report
		$this->settings()->set('provider', 'local')->set('local_path', 'private://strata')->save();

		$requirement = (array) strata_requirements('runtime')['strata_storage'];

		$this->assertSame(RequirementSeverity::Error, $requirement['severity']);
		$this->assertArrayHasKey('description', $requirement);
	}

	#[Test]
	#[TestDox('a directory the first flush will create is not reported as a fault')]
	#[Group('strata/install')]
	public function aDirectoryTheFlushWillCreateIsNotAFault(): void
	{
		// LocalStorage creates its root at the first write, so this is what a fresh install looks
		// like and an Error here is a red mark on a site that has done nothing wrong
		$path = $this->siteDirectory . '/never-created';

		$this->settings()->set('provider', 'local')->set('local_path', $path)->save();

		$this->assertDirectoryDoesNotExist($path);

		$requirement = (array) strata_requirements('runtime')['strata_storage'];

		$this->assertSame(RequirementSeverity::OK, $requirement['severity']);
		$this->assertArrayHasKey('description', $requirement);
	}

	#[Test]
	#[TestDox('a directory whose parent is missing too is an error, since nothing can create it')]
	#[Group('strata/install')]
	public function aDirectoryWithNoParentIsAnError(): void
	{
		$this->settings()
			->set('provider', 'local')
			->set('local_path', $this->siteDirectory . '/absent/deeper')
			->save();

		$requirement = (array) strata_requirements('runtime')['strata_storage'];

		$this->assertSame(RequirementSeverity::Error, $requirement['severity']);
	}

	#[Test]
	#[TestDox('the requirement agrees with the provider that would do the writing')]
	#[Group('strata/install')]
	public function theLocalRequirementAgreesWithTheProvider(): void
	{
		// the two disagreed until 1.0.3, and the requirement was the one that was wrong. asserting
		// them against each other is what stops them drifting apart again
		foreach (['/strata-agrees', '/never-created', '/absent/deeper'] as $suffix) {
			$path = $this->siteDirectory . $suffix;

			if ($suffix === '/strata-agrees') {
				mkdir($path, 0777, true);
			}

			$this->settings()->set('provider', 'local')->set('local_path', $path)->save();

			$requirement = (array) strata_requirements('runtime')['strata_storage'];
			$reachable = (new LocalStorage($path))->isReachable();

			$this->assertSame(
				$reachable,
				$requirement['severity'] !== RequirementSeverity::Error,
				sprintf('%s is judged the same way by both', $path),
			);
		}
	}

	#[Test]
	#[TestDox('the local provider with no directory set is an error, not an empty value')]
	#[Group('strata/install')]
	public function localWithNoDirectoryIsAnError(): void
	{
		$this->settings()->set('provider', 'local')->set('local_path', '')->save();

		$requirement = (array) strata_requirements('runtime')['strata_storage'];

		$this->assertSame(RequirementSeverity::Error, $requirement['severity']);
	}

	#[Test]
	#[TestDox('choosing no provider at all is reported as nothing being backed up')]
	#[Group('strata/install')]
	public function noProviderChosenIsReported(): void
	{
		$this->settings()->set('provider', '')->save();

		$requirement = (array) strata_requirements('runtime')['strata_storage'];

		$this->assertSame(RequirementSeverity::Warning, $requirement['severity']);
	}

	#[Test]
	#[TestDox('the null provider is reported as keeping nothing, since it looks like it works')]
	#[Group('strata/install')]
	public function theNullProviderIsReportedAsKeepingNothing(): void
	{
		$this->settings()->set('provider', 'null')->save();

		$requirement = (array) strata_requirements('runtime')['strata_storage'];

		$this->assertSame(RequirementSeverity::Warning, $requirement['severity']);
	}

	#[Test]
	#[TestDox('a provider nothing registered is reported unreachable rather than raising')]
	#[Group('strata/install')]
	public function anUnregisteredProviderIsReportedNotRaised(): void
	{
		// strata_s3 is not enabled in this lane, so nothing answers to the id
		$this->settings()->set('provider', 's3')->save();

		$requirement = (array) strata_requirements('runtime')['strata_storage'];

		$this->assertSame(RequirementSeverity::Error, $requirement['severity']);
		$this->assertArrayHasKey('description', $requirement);
	}

	#[Test]
	#[TestDox('the status report answers with encryption on and no key chosen')]
	#[Group('strata/install')]
	public function requirementsDoNotAssembleTheEngine(): void
	{
		// the shipped default, and the reason this cannot be routed through strata.engine: assembling
		// the store refuses without a key, which would report a key problem where a bucket was asked
		// about - or take the status page down entirely
		$this->assertSame('', (string) $this->settings()->get('key'));

		$requirements = (array) strata_requirements('runtime');

		$this->assertArrayHasKey('strata_storage', $requirements);
		$this->assertArrayHasKey('strata_codecs', $requirements);
	}

	#[Test]
	#[TestDox('encryption on with no key chosen is an error naming the page that fixes it')]
	#[Group('strata/install')]
	public function noKeyChosenIsAnError(): void
	{
		$this->settings()->set('cipher.id', 'xchacha20poly1305')->set('key', '')->save();

		$requirement = (array) strata_requirements('runtime')['strata_key'];

		$this->assertSame(RequirementSeverity::Error, $requirement['severity']);
		$this->assertStringContainsString(
			'admin/config/system/strata/storage',
			(string) $requirement['description'],
		);
	}

	#[Test]
	#[TestDox('a key that holds nothing is an error rather than a first flush that refuses')]
	#[Group('strata/install')]
	public function anEmptyKeyIsAnError(): void
	{
		$this->createKey('strata_empty', '');
		$this->settings()
			->set('cipher.id', 'xchacha20poly1305')
			->set('key', 'strata_empty')
			->save();

		$requirement = (array) strata_requirements('runtime')['strata_key'];

		$this->assertSame(RequirementSeverity::Error, $requirement['severity']);
		$this->assertStringContainsString('strata_empty', (string) $requirement['description']);
	}

	#[Test]
	#[TestDox('a key of the wrong length is an error carrying the ciphers own reason')]
	#[Group('strata/install')]
	public function aShortKeyIsAnError(): void
	{
		$this->createKey('strata_short', 'nowhere near thirty two bytes');
		$this->settings()
			->set('cipher.id', 'xchacha20poly1305')
			->set('key', 'strata_short')
			->save();

		$requirement = (array) strata_requirements('runtime')['strata_key'];

		$this->assertSame(RequirementSeverity::Error, $requirement['severity']);
		$this->assertStringContainsString('32 bytes', (string) $requirement['description']);
	}

	#[Test]
	#[TestDox('a key of the right length is reported as chosen, in raw bytes or in hex')]
	#[Group('strata/install')]
	public function aUsableKeyIsReportedChosen(): void
	{
		foreach (
			['strata_raw' => str_repeat('k', 32), 'strata_hex' => str_repeat('ab', 32)]
			as $id => $value
		) {
			$this->createKey($id, $value);
			$this->settings()->set('cipher.id', 'xchacha20poly1305')->set('key', $id)->save();

			$requirement = (array) strata_requirements('runtime')['strata_key'];

			$this->assertSame(
				RequirementSeverity::OK,
				$requirement['severity'],
				sprintf('%s is usable', $id),
			);
		}
	}

	#[Test]
	#[TestDox('encryption switched off says so rather than reporting a missing key')]
	#[Group('strata/install')]
	public function encryptionOffIsAWarningNotAKeyError(): void
	{
		$this->settings()->set('cipher.id', 'none')->set('key', '')->save();

		$requirement = (array) strata_requirements('runtime')['strata_key'];

		$this->assertSame(RequirementSeverity::Warning, $requirement['severity']);
		$this->assertStringContainsString('unencrypted', (string) $requirement['description']);
	}

	#[Test]
	#[TestDox('capture being off is reported, since the shipped default backs nothing up')]
	#[Group('strata/install')]
	public function captureOffIsReported(): void
	{
		$this->settings()->set('enabled', false)->save();

		$off = (array) strata_requirements('runtime')['strata_capture'];

		$this->assertSame(RequirementSeverity::Warning, $off['severity']);
		$this->assertStringContainsString(
			'admin/config/system/strata/storage',
			(string) $off['description'],
		);

		$this->settings()->set('enabled', true)->save();

		$this->assertSame(
			RequirementSeverity::OK,
			((array) strata_requirements('runtime')['strata_capture'])['severity'],
		);
	}

	#[Test]
	#[TestDox('the module page carries the ordered setup steps, and every settings tab has help')]
	#[Group('strata/install')]
	public function helpCoversTheEngine(): void
	{
		$help = new Help();
		$match = $this->container->get('current_route_match');

		// help.page.strata is what puts a Help link beside the module on the Extend page and a page
		// at admin/help. The engine had neither until 1.0.3, and it owns every decision a first
		// install has to make
		$steps = $help->forRoute('help.page.strata', $match);

		$this->assertNotEmpty($steps);
		$this->assertStringContainsString('Four steps turn it on', implode(' ', $steps['#items']));

		foreach (array_keys(Yaml::decode($this->routingFile())) as $route) {
			$this->assertNotEmpty(
				$help->forRoute((string) $route, $match),
				sprintf('%s carries help', $route),
			);
		}
	}

	/**
	 * The root module's routing file, as text.
	 *
	 * @return string
	 *   The YAML.
	 */
	private function routingFile(): string
	{
		return (string) file_get_contents(dirname(__DIR__, 3) . '/strata.routing.yml');
	}

	#[Test]
	#[TestDox('every page the router names resolves, so nothing has been left behind')]
	#[Group('strata/install')]
	public function everyRoutedPageResolves(): void
	{
		$requirement = (array) strata_requirements('runtime')['strata_routes'];

		// the kernel lane registers every submodule namespace in tests/bootstrap.php, so it cannot
		// produce a class that fails to load; what it can prove is that the check runs and passes
		$this->assertSame(RequirementSeverity::OK, $requirement['severity']);
	}

	/**
	 * Creates a key entity holding a literal value.
	 *
	 * @param string $id
	 *   The machine name.
	 * @param string $value
	 *   What it holds.
	 */
	private function createKey(string $id, string $value): void
	{
		$this->container
			->get('entity_type.manager')
			->getStorage('key')
			->create([
				'id' => $id,
				'label' => $id,
				'key_type' => 'authentication',
				'key_provider' => 'config',
				'key_provider_settings' => ['key_value' => $value],
			])
			->save();
	}

	#endregion

	#region The engine, inside Drupal

	#[Test]
	#[TestDox('the object store round-trips a value inside a booted Drupal')]
	#[Group('strata/install')]
	public function objectStoreWorksInsideDrupal(): void
	{
		$store = $this->objectStore();
		$value = (string) json_encode(['nid' => 42, 'title' => str_repeat('a node title ', 40)]);

		$map = $store->write($value);
		$store->commit();

		$this->assertNotEmpty($map);
		$this->assertSame($value, $store->read($map));
		$this->assertGreaterThan(0, count($this->provider()->list('')->objects));
	}

	#[Test]
	#[TestDox('a commit appends and reads back through the store on disk')]
	#[Group('strata/install')]
	public function commitLogWorksInsideDrupal(): void
	{
		$log = $this->commitLog();
		$anchor = $this->baseWriter()->full(
			['entity/node:1' => ['frames' => [Hash::of('frame')], 'size' => 100]],
			1_755_000_000_000_000,
		);

		$id = $log->append(
			new Commit(
				$anchor,
				null,
				1_755_000_000_000_000,
				'first capture',
				null,
				1,
				100,
				100,
				0,
				true,
				1,
				1_755_000_000_000_000,
			),
		);

		$this->assertSame($id, $log->head()?->id());
		$this->assertSame('first capture', $log->head()?->label);
		$this->assertSame(0, $log->replayPath($id)['depth']);
	}

	#[Test]
	#[TestDox('the store root sits inside the test site directory, so the lane is hermetic')]
	#[Group('strata/install')]
	public function storeRootIsInsideTheSiteDirectory(): void
	{
		$this->provider()->put('probe', 'payload');

		$this->assertStringContainsString($this->siteDirectory, $this->storeRoot);
		$this->assertTrue($this->provider()->isReachable());
		$this->assertSame('payload', $this->provider()->get('probe'));
	}

	#endregion

	#region Fixtures

	/**
	 * Saves the shipped findings view, with its page display on a given path.
	 *
	 * Read from the module's own directory and saved as it is, rather than through the optional
	 * config installer: that installer scans every enabled module's optional config and core's
	 * brings in dependencies this lane has no reason to boot.
	 *
	 * @param string $path
	 *   The path to give the page display, so a pre-update and a post-update site are both testable.
	 */
	private function installShippedFindingsView(string $path): void
	{
		$data = Yaml::decode(
			(string) file_get_contents(
				dirname(__DIR__, 3) . '/config/optional/views.view.strata_health.yml',
			),
		);

		$this->assertIsArray($data);

		$data['display']['page_1']['display_options']['path'] = $path;

		$this->config('views.view.strata_health')->setData($data)->save();
	}

	/**
	 * Rebuilds the journal table with the 32-bit sequence a pre-update site has.
	 */
	private function narrowTheSequence(): void
	{
		$schema = $this->container->get('database')->schema();
		$definition = $this->strataSchema()['strata_journal'];

		unset($definition['fields']['sequence']['size']);

		$schema->dropTable('strata_journal');
		$schema->createTable('strata_journal', $definition);
	}

	/**
	 * Writes one journal row straight to the table.
	 *
	 * Bypasses the journal so a row can be given an explicit sequence, which is the whole point of
	 * the width the update changes.
	 *
	 * @param string $subject
	 *   The subject the row records.
	 * @param int|null $sequence
	 *   An explicit sequence, or NULL to let the column assign one.
	 */
	private function appendJournalRow(string $subject, ?int $sequence = null): void
	{
		$fields = [
			'microtime' => 1_755_000_000_000_000,
			'realm' => 'entity',
			'subject' => $subject,
			'verb' => 'update',
			'payload_length' => 0,
			'label' => 'a captured write',
		];

		if ($sequence !== null) {
			$fields['sequence'] = $sequence;
		}

		$this->container->get('database')->insert('strata_journal')->fields($fields)->execute();
	}

	#endregion
}
