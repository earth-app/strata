<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Capture\Classifier\Classification;
use Drupal\strata\Capture\Classifier\ClassificationRegistry;
use Drupal\strata\Capture\Classifier\DatabaseKeyspaceSource;
use Drupal\strata\Capture\Classifier\Heuristics;
use Drupal\strata\Capture\Classifier\KeyspaceDiscovery;
use Drupal\strata\Engine;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves the keyspace is discovered, stored and decided against a real database.
 *
 * The unit lane covers the rules and the report. What it cannot cover is the part that matters most
 * on a real site: whether a decision survives the next discovery pass. A pass that overwrote a
 * person's decision with a rule's guess would silently start capturing a cache bin again, and the
 * only place that shows up is a registry backed by the table it writes to.
 */
class KeyspaceTest extends StrataKernelTestBase
{
	private function engine(): Engine
	{
		return $this->container->get('strata.engine');
	}

	private function registry(): ClassificationRegistry
	{
		return $this->container->get('strata.classifications');
	}

	private function discovery(): KeyspaceDiscovery
	{
		return $this->engine()->keyspaceDiscovery();
	}

	#region Registry

	#[Test]
	#[TestDox('a key nothing was stored about falls back to the shipped rules')]
	#[Group('strata/classification')]
	public function unstoredKeysUseTheRules(): void
	{
		$registry = $this->registry();

		$this->assertSame([], $registry->all());
		$this->assertSame(
			Heuristics::classify('cache_render'),
			$registry->classify('cache_render'),
		);
		$this->assertFalse($registry->isDecidedByHuman('cache_render'));
	}

	#[Test]
	#[TestDox('an observation is stored once and its counters are replaced, not added up')]
	#[Group('strata/classification')]
	public function observationsAreReplaced(): void
	{
		$registry = $this->registry();

		$registry->observe('acme_thing*', Classification::UNCLASSIFIED, 10, 1000);
		$registry->observe('acme_thing*', Classification::UNCLASSIFIED, 4, 400);

		$statistics = $registry->statistics();

		$this->assertSame(1, $statistics[Classification::UNCLASSIFIED->value]['patterns']);
		$this->assertSame(4, $statistics[Classification::UNCLASSIFIED->value]['keys']);
		$this->assertSame(400, $statistics[Classification::UNCLASSIFIED->value]['bytes']);
	}

	#[Test]
	#[TestDox('a decision survives the discovery pass that observes the same pattern again')]
	#[Group('strata/classification')]
	public function decisionsSurviveRediscovery(): void
	{
		$registry = $this->registry();

		$registry->observe('acme_thing*', Classification::UNCLASSIFIED, 10, 1000);
		$registry->decide('acme_thing*', Classification::AUTHORITATIVE);

		$this->assertSame(Classification::AUTHORITATIVE, $registry->classify('acme_thing:1'));
		$this->assertTrue($registry->isDecidedByHuman('acme_thing:1'));

		// the pass that runs next sees the pattern again and must not overwrite the decision
		$registry->observe('acme_thing*', Classification::UNCLASSIFIED, 12, 1200);

		$this->assertSame(Classification::AUTHORITATIVE, $registry->classify('acme_thing:1'));
		$this->assertTrue($registry->restores('acme_thing:1'));
		$this->assertSame(
			12,
			$registry->statistics()[Classification::AUTHORITATIVE->value]['keys'],
			'the counters still track what was observed',
		);
	}

	#[Test]
	#[TestDox('the narrower stored pattern decides a key the broader one also matches')]
	#[Group('strata/classification')]
	public function narrowerPatternWins(): void
	{
		$registry = $this->registry();

		$registry->decide('cache*', Classification::DERIVABLE);
		$registry->decide('cache_render*', Classification::AUTHORITATIVE);

		$this->assertSame(Classification::AUTHORITATIVE, $registry->classify('cache_render:page'));
		$this->assertSame(Classification::DERIVABLE, $registry->classify('cache_menu:tree'));
	}

	#[Test]
	#[TestDox('an unclassified key is captured and not restored, so nothing is dropped silently')]
	#[Group('strata/classification')]
	public function unclassifiedIsCapturedNotRestored(): void
	{
		$registry = $this->registry();

		$registry->observe('acme_mystery*', Classification::UNCLASSIFIED, 5, 500);

		$this->assertTrue($registry->captures('acme_mystery:1'));
		$this->assertFalse($registry->restores('acme_mystery:1'));
	}

	#[Test]
	#[TestDox('the undecided list is ordered by volume, so the expensive pattern is first')]
	#[Group('strata/classification')]
	public function undecidedIsOrderedByVolume(): void
	{
		$registry = $this->registry();

		$registry->observe('small*', Classification::UNCLASSIFIED, 2, 100);
		$registry->observe('large*', Classification::UNCLASSIFIED, 900, 90_000);
		$registry->observe('decided*', Classification::AUTHORITATIVE, 50, 5000);

		$patterns = array_column($registry->undecided(), 'pattern');

		$this->assertSame(['large*', 'small*'], $patterns);
	}

	#[Test]
	#[TestDox('forgetting a pattern hands the key back to the rules')]
	#[Group('strata/classification')]
	public function forgettingRestoresTheRules(): void
	{
		$registry = $this->registry();

		$registry->decide('cache_render*', Classification::AUTHORITATIVE);

		$this->assertSame(Classification::AUTHORITATIVE, $registry->classify('cache_render:page'));
		$this->assertTrue($registry->forget('cache_render*'));
		$this->assertFalse($registry->forget('cache_render*'), 'a pattern is only removed once');
		$this->assertSame(
			Heuristics::classify('cache_render:page'),
			$registry->classify('cache_render:page'),
		);
	}

	#[Test]
	#[TestDox('clearing the registry reports how many patterns it dropped')]
	#[Group('strata/classification')]
	public function clearingReportsWhatItDropped(): void
	{
		$registry = $this->registry();

		$registry->observe('one*', Classification::UNCLASSIFIED, 1, 1);
		$registry->observe('two*', Classification::DERIVABLE, 1, 1);

		$this->assertSame(2, $registry->clear());
		$this->assertSame([], $registry->all());
		$this->assertSame(0, $registry->clear());
	}

	#[Test]
	#[TestDox('every classification is present in the statistics even at zero')]
	#[Group('strata/classification')]
	public function statisticsCoverEveryClassification(): void
	{
		$statistics = $this->registry()->statistics();

		foreach (Classification::cases() as $case) {
			$this->assertArrayHasKey($case->value, $statistics);
			$this->assertSame(0, $statistics[$case->value]['patterns']);
		}
	}

	#endregion

	#region Discovery

	/**
	 * Creates a table of a shape the source recognises and fills it.
	 *
	 * A kernel test's cache and key-value backends are in memory, so the connection holds none of the
	 * tables a real site's ephemeral keyspace lives in. The fixture is a real table of a shape the
	 * source looks for, which is what the source actually reads on a site.
	 *
	 * @param string $table
	 *   The table to create.
	 * @param int $rows
	 *   How many entries to write.
	 */
	private function cacheBin(string $table, int $rows): void
	{
		$schema = $this->container->get('database')->schema();

		if (!$schema->tableExists($table)) {
			$schema->createTable($table, [
				'fields' => [
					'cid' => [
						'type' => 'varchar',
						'length' => 255,
						'not null' => true,
						'default' => '',
					],
					'data' => ['type' => 'blob', 'size' => 'big', 'not null' => false],
				],
				'primary key' => ['cid'],
			]);
		}

		for ($i = 0; $i < $rows; $i++) {
			$this->container
				->get('database')
				->insert($table)
				->fields(['cid' => 'entry:' . $i, 'data' => str_repeat('x', 16)])
				->execute();
		}
	}

	#[Test]
	#[TestDox('a pass over the site\'s own tables finds keys and stores what it found')]
	#[Group('strata/classification')]
	public function discoveryReadsTheDatabase(): void
	{
		$this->cacheBin('cache_acme', 4);

		$report = $this->discovery()->discover();

		$this->assertGreaterThanOrEqual(1, $report->sources);
		$this->assertTrue($report->couldLook());
		$this->assertSame(4, $report->keys);
		$this->assertSame(1, $report->patterns);
		$this->assertSame([], $report->problems);
		$this->assertArrayHasKey('cache*', $this->registry()->all(), 'what it saw was stored');
	}

	#[Test]
	#[TestDox('a cache bin is classified as derivable without anyone deciding it')]
	#[Group('strata/classification')]
	public function cacheBinsAreClassifiedByRule(): void
	{
		$this->cacheBin('cache_acme', 2);
		$this->discovery()->discover();

		$this->assertSame(Classification::DERIVABLE, $this->registry()->classify('cache_acme:one'));
		$this->assertFalse($this->registry()->isDecidedByHuman('cache_acme:one'));
	}

	#[Test]
	#[TestDox('the module\'s own tables are not reported as part of the site\'s keyspace')]
	#[Group('strata/classification')]
	public function ownTablesAreExcluded(): void
	{
		$this->cacheBin('cache_acme', 2);
		$this->discovery()->discover();

		$patterns = array_keys($this->registry()->all());

		$this->assertContains('cache*', $patterns);

		foreach ($patterns as $pattern) {
			$this->assertStringNotContainsString('strata_', $pattern);
		}
	}

	#[Test]
	#[TestDox('a second pass rewrites the same patterns rather than growing the table')]
	#[Group('strata/classification')]
	public function repeatedPassesDoNotGrow(): void
	{
		$this->cacheBin('cache_acme', 3);

		$first = $this->discovery()->discover();
		$second = $this->discovery()->discover();

		$this->assertSame(1, $first->patterns);
		$this->assertSame($first->patterns, $second->patterns);
		$this->assertCount($first->patterns, $this->registry()->all());
	}

	#[Test]
	#[TestDox('a bound limits how much of a keyspace one pass reads')]
	#[Group('strata/classification')]
	public function passIsBounded(): void
	{
		$this->cacheBin('cache_acme', 12);

		$this->assertLessThanOrEqual(3, $this->discovery()->discover(3)->keys);
		$this->assertSame(12, $this->discovery()->discover()->keys);
	}

	#[Test]
	#[TestDox('the database source names itself and is always available')]
	#[Group('strata/classification')]
	public function databaseSourceIsAlwaysThere(): void
	{
		$source = new DatabaseKeyspaceSource($this->container->get('database'));

		$this->assertSame('database', $source->id());
		$this->assertTrue($source->isAvailable());
		$this->assertGreaterThanOrEqual(1, $this->discovery()->sourceCount());
	}

	#endregion
}
