<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\strata\Archive\ArchiveExporter;
use Drupal\strata\Archive\ArchiveImporter;
use Drupal\strata\Capture\Classifier\Classification;
use Drupal\strata\Drush\Commands\StrataCommands;
use Drupal\strata\Drush\Commands\StrataDiagnosticCommands;
use Drupal\strata\Drush\Commands\StrataMaintenanceCommands;
use Drupal\strata\Drush\Commands\StrataRestoreCommands;
use Drupal\strata\Engine;
use Drupal\strata\Tree\RefStore;
use Drupal\user\Entity\User;
use Drush\Attributes\Command;
use Drush\Attributes\Usage;
use Drush\Commands\DrushCommands;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Proves the command suite is declared correctly and runs on a site with no history.
 *
 * Three claims, checked separately. The attributes are read back by reflection, so a mistyped
 * command name, a name declared twice and a command with no usage example each fail here rather than
 * at `drush help`. Every class is built through its own `create()`, which is what catches a
 * constructor naming a service id that does not exist. And the read-only commands are driven against
 * an empty store, because a fresh install is the state every site passes through and an empty table
 * is the right answer there.
 */
class DrushCommandTest extends StrataKernelTestBase
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
		$this->registerArchiveServices();
	}

	#region Providers

	/**
	 * Every command the suite is meant to declare.
	 *
	 * @return array<string, array{string}>
	 *   The command name, keyed by itself.
	 */
	public static function commandNames(): array
	{
		$names = [
			'strata:status',
			'strata:snapshot',
			'strata:flush',
			'strata:compact',
			'strata:prune',
			'strata:list',
			'strata:diff',
			'strata:rollback',
			'strata:restore',
			'strata:verify',
			'strata:estimate',
			'strata:calibrate',
			'strata:gc',
			'strata:audit',
			'strata:reindex',
			'strata:classify',
			'strata:heal',
			'strata:quarantine',
			'strata:train-dict',
			'strata:reanchor',
			'strata:rotate-key',
			'strata:tiers',
			'strata:export',
			'strata:import',
			'strata:sites',
			'strata:branch',
			'strata:merge',
		];

		return array_combine($names, array_map(static fn(string $n): array => [$n], $names));
	}

	/**
	 * Every class the suite is made of.
	 *
	 * @return array<string, array{string}>
	 *   The class name.
	 */
	public static function commandClasses(): array
	{
		return [
			'the everyday commands' => [StrataCommands::class],
			'the maintenance commands' => [StrataMaintenanceCommands::class],
			'the restore commands' => [StrataRestoreCommands::class],
			'the diagnostic commands' => [StrataDiagnosticCommands::class],
		];
	}

	/**
	 * Every command method the suite declares, found by reflection.
	 *
	 * @return array<string, array{string, string, string}>
	 *   Class, method name and command name, keyed by class and method.
	 */
	public static function commandMethods(): array
	{
		$methods = [];

		foreach (self::commandClasses() as [$class]) {
			$reflection = new ReflectionClass($class);

			foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				foreach ($method->getAttributes(Command::class) as $attribute) {
					$key = sprintf('%s::%s', $reflection->getShortName(), $method->getName());

					$methods[$key] = [
						$class,
						$method->getName(),
						(string) $attribute->newInstance()->name,
					];
				}
			}
		}

		return $methods;
	}

	#endregion

	#region Declaration

	#[Test]
	#[TestDox('$_dataName is declared by exactly one method')]
	#[Group('strata/drush')]
	#[DataProvider('commandNames')]
	public function commandIsDeclaredOnce(string $name): void
	{
		$declared = array_column(self::commandMethods(), 2);

		$this->assertCount(
			1,
			array_keys($declared, $name, true),
			sprintf('%s is declared once and only once', $name),
		);
	}

	#[Test]
	#[TestDox('the suite declares the 27 commands and nothing else')]
	#[Group('strata/drush')]
	public function theSuiteDeclaresExactlyTheCommandSurface(): void
	{
		$declared = array_column(self::commandMethods(), 2);
		$expected = array_keys(self::commandNames());

		sort($declared);
		sort($expected);

		$this->assertSame($expected, $declared);
	}

	#[Test]
	#[TestDox('$_dataName carries a usage example, so drush help is never bare')]
	#[Group('strata/drush')]
	#[DataProvider('commandMethods')]
	public function commandDocumentsItsUsage(string $class, string $method, string $name): void
	{
		$this->assertNotEmpty(
			(new ReflectionMethod($class, $method))->getAttributes(Usage::class),
			sprintf('%s has at least one usage example', $name),
		);
	}

	#[Test]
	#[TestDox('$_dataName construct off the container, so every injected service id exists')]
	#[Group('strata/drush')]
	#[DataProvider('commandClasses')]
	public function commandClassConstructs(string $class): void
	{
		$command = $class::create($this->container);

		$this->assertInstanceOf($class, $command);
		$this->assertInstanceOf(DrushCommands::class, $command);
	}

	#endregion

	#region Empty History

	#[Test]
	#[TestDox('status reports on a site that has never flushed instead of failing')]
	#[Group('strata/drush')]
	public function statusReadsOnAnEmptySite(): void
	{
		$status = $this->everyday()->status()->getArrayCopy();

		$this->assertSame('yes', $status['enabled']);
		$this->assertSame($this->engine()->site()->id(), $status['site']);
		$this->assertSame('-', $status['head'], 'there is no head to name');
		$this->assertSame(0, $status['commits']);
		$this->assertSame(0, $status['frames']);
		$this->assertSame(0, $status['findings']);
	}

	#[Test]
	#[TestDox('an empty history lists no commits rather than failing')]
	#[Group('strata/drush')]
	public function emptyHistoryListsNoCommits(): void
	{
		$rows = $this->everyday()->commits();

		$this->assertInstanceOf(RowsOfFields::class, $rows);
		$this->assertSame([], $rows->getArrayCopy());
	}

	#[Test]
	#[TestDox('an empty bucket holds no sites')]
	#[Group('strata/drush')]
	public function emptyBucketHoldsNoSites(): void
	{
		$this->assertSame([], $this->everyday()->sites()->getArrayCopy());
	}

	#[Test]
	#[TestDox('a single-bucket site reports no tiers rather than inventing one')]
	#[Group('strata/drush')]
	public function singleBucketSiteReportsNoTiers(): void
	{
		$this->assertSame([], $this->diagnostics()->tiers()->getArrayCopy());
	}

	#[Test]
	#[TestDox('flush reports that it sealed nothing when nothing is pending')]
	#[Group('strata/drush')]
	public function flushSealsNothingWhenNothingIsPending(): void
	{
		$result = $this->everyday()
			->flush(['ref' => RefStore::MAIN, 'if-due' => false])
			->getArrayCopy();

		$this->assertSame('no', $result['ran']);
		$this->assertSame('nothing pending', $result['reason']);
		$this->assertSame('-', $result['commit']);
	}

	#[Test]
	#[TestDox('verify finds nothing in a store with nothing in it')]
	#[Group('strata/drush')]
	public function verifyIsCleanOnAnEmptyStore(): void
	{
		$rows = $this->diagnostics()->verify();

		$this->assertInstanceOf(RowsOfFields::class, $rows);
		$this->assertSame([], $rows->getArrayCopy());
	}

	#[Test]
	#[TestDox('the restore log is empty before anything has been restored')]
	#[Group('strata/drush')]
	public function auditIsEmptyBeforeAnyRestore(): void
	{
		$this->assertSame([], $this->diagnostics()->auditLog()->getArrayCopy());
	}

	#[Test]
	#[TestDox('heal lists nothing when the ledger holds no findings')]
	#[Group('strata/drush')]
	public function healListsNothingWhenNothingIsWrong(): void
	{
		$rows = $this->diagnostics()->heal(null, [
			'list' => true,
			'apply' => false,
			'rung' => null,
			'decay' => false,
		]);

		$this->assertSame([], $rows->getArrayCopy());
	}

	#[Test]
	#[TestDox('classify reports every classification even when nothing is undecided')]
	#[Group('strata/drush')]
	public function classifyReportsEveryClassification(): void
	{
		$rows = $this->diagnostics()->classify()->getArrayCopy();

		foreach (Classification::cases() as $case) {
			$this->assertArrayHasKey($case->value, $rows, $case->value);
		}
	}

	#[Test]
	#[TestDox('branch lists nothing on a site that has never flushed')]
	#[Group('strata/drush')]
	public function branchListsNothingBeforeAnyHistory(): void
	{
		$rows = $this->restores()->branch();

		$this->assertInstanceOf(RowsOfFields::class, $rows);
		$this->assertSame([], $rows->getArrayCopy(), 'there is no ref, so there is no branch');
	}

	#[Test]
	#[TestDox('merging a branch that does not exist refuses and names it')]
	#[Group('strata/drush')]
	public function mergingAnUnknownBranchRefuses(): void
	{
		$result = $this->restores()
			->merge('nowhere', ['strategy' => DrushCommands::REQ, 'dry-run' => true])
			->getArrayCopy();

		$this->assertSame('no', $result['applied']);
		$this->assertStringContainsString('nowhere', $result['refused']);
	}

	#[Test]
	#[TestDox('estimate projects a bill without reading the store at all')]
	#[Group('strata/drush')]
	public function estimateProjectsOnAnEmptySite(): void
	{
		$empty = $this->diagnostics()->estimate()->getArrayCopy();

		$this->assertArrayHasKey('writes-per-month', $empty);
		$this->assertArrayHasKey('r2', $empty);

		$sized = $this->diagnostics()
			->estimate([
				'users' => 50000,
				'nodes' => 20000,
				'rows' => 0,
				'files' => 0,
				'file-bytes' => 0,
				'active-share' => 0.1,
				'retention' => 365,
				'deploys' => 150,
				'interval' => 15,
				'access-churn' => 'event',
			])
			->getArrayCopy();

		$this->assertGreaterThan(0, (int) $sized['writes-per-month']);
		$this->assertNotSame('', $sized['dominant']);
	}

	#endregion

	#region Dry Runs

	#[Test]
	#[TestDox('a dry-run prune produces a receipt and removes no object')]
	#[Group('strata/drush')]
	public function dryRunPruneWritesNothing(): void
	{
		$this->flush('grace');

		$before = $this->keys();

		$this->assertNotSame([], $before, 'the flush wrote something to prune against');

		$receipt = $this->maintenance()
			->prune(['dry-run' => true, 'limit' => 1000])
			->getArrayCopy();

		$this->assertSame('no', $receipt['applied'], 'the receipt says it was not applied');
		$this->assertSame($this->keys(), $before, 'no object was removed');
	}

	#[Test]
	#[TestDox('an unsupplied value option reads as absent rather than as its drush sentinel')]
	#[Group('strata/drush')]
	public function unsuppliedValueOptionReadsAsAbsent(): void
	{
		$this->flush('linus');

		$rows = $this->everyday()->commits()->getArrayCopy();

		$this->assertCount(1, $rows, 'the level filter was not taken from the option default');
	}

	#[Test]
	#[TestDox('a bucket with history names the site that wrote it, and its head')]
	#[Group('strata/drush')]
	public function bucketNamesTheSiteThatWroteIt(): void
	{
		$this->flush('ada');

		$rows = $this->everyday()->sites()->getArrayCopy();
		$id = $this->engine()->site()->id();

		$this->assertArrayHasKey($id, $rows);
		$this->assertSame('yes', $rows[$id]['current']);
		$this->assertNotSame('-', $rows[$id]['head'], 'the main ref was read back');
	}

	#endregion

	#region Fixtures

	private function engine(): Engine
	{
		return $this->container->get('strata.engine');
	}

	/**
	 * Puts the archive services in the container.
	 *
	 * The module's own services file has to declare the same pair, under both the service id and the
	 * class name, for `strata:export` and `strata:import` to autowire on a real site.
	 */
	private function registerArchiveServices(): void
	{
		$engine = $this->engine();
		$logger = $this->container->get('logger.channel.strata');

		$exporter = new ArchiveExporter(
			$engine->provider(),
			$engine->commitLog(),
			$engine->refStore(),
			$engine->segmentReader(),
			$engine->baseReader(),
			$this->container->get('strata.frame_index'),
			$engine->dictionaries(),
			$engine->site(),
			$this->container->get('datetime.time'),
			$logger,
		);
		$importer = new ArchiveImporter($engine->provider(), $logger);

		$this->container->set('strata.archive_exporter', $exporter);
		$this->container->set(ArchiveExporter::class, $exporter);
		$this->container->set('strata.archive_importer', $importer);
		$this->container->set(ArchiveImporter::class, $importer);
	}

	/**
	 * Captures a user and seals it, so the store holds real history.
	 */
	private function flush(string $name): void
	{
		User::create([
			'name' => $name,
			'mail' => $name . '@example.com',
			'status' => 1,
		])->save();

		$this->engine()->flusher()->flush(true);
	}

	/**
	 * Every object key the store holds, read outside the site prefix.
	 *
	 * @return list<string>
	 *   The keys.
	 */
	private function keys(): array
	{
		return $this->provider()->list('', null, 1000)->keys();
	}

	/**
	 * Gives a command somewhere to read from and write to, which is what `io()` needs.
	 */
	private function drive(DrushCommands $command): void
	{
		$command->setInput(new ArrayInput([]));
		$command->setOutput(new BufferedOutput());
	}

	private function everyday(): StrataCommands
	{
		$command = StrataCommands::create($this->container);
		$this->drive($command);

		return $command;
	}

	private function maintenance(): StrataMaintenanceCommands
	{
		$command = StrataMaintenanceCommands::create($this->container);
		$this->drive($command);

		return $command;
	}

	private function diagnostics(): StrataDiagnosticCommands
	{
		$command = StrataDiagnosticCommands::create($this->container);
		$this->drive($command);

		return $command;
	}

	private function restores(): StrataRestoreCommands
	{
		$command = StrataRestoreCommands::create($this->container);
		$this->drive($command);

		return $command;
	}

	#endregion
}
