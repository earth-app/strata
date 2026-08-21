<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Tree\Commit;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves the module installs, its schema and config land, and the engine runs inside Drupal.
 */
class InstallTest extends StrataKernelTestBase
{
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
	#[TestDox('hook_requirements reports nothing outside the runtime phase')]
	#[Group('strata/install')]
	public function requirementsAreRuntimeOnly(): void
	{
		$this->assertSame([], (array) strata_requirements('install'));
		$this->assertSame([], (array) strata_requirements('update'));
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
}
