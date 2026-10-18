<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\E2E;

use Drupal;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Measures what the store costs inside the container and holds it to a committed baseline.
 *
 * The workload is fixed: thirty nodes, then a run of flushes each rewriting ten of them. Everything
 * counted - requests per flush, bytes stored, the compression ratio, the deepest delta chain, objects
 * in the bucket - comes out the same every run, so it fails the build when it leaves the baseline.
 * Times depend on the host, so they are written to the report and never compared.
 *
 * The request figures are the point. README promises a flush of a few write requests whatever the
 * size of the site, and a regression in how many reads a flush needs would not show in any test that
 * only checks bytes: a rewrite that walks its delta chain is correct and costs two hundred requests.
 */
class StorageBenchmarkTest extends E2ETestBase
{
	/**
	 * Nodes in the workload.
	 */
	private const NODES = 30;

	/**
	 * Flushes after the first, each rewriting `REWRITES` nodes.
	 */
	private const ROUNDS = 12;

	/**
	 * Nodes rewritten in each round.
	 */
	private const REWRITES = 10;

	/**
	 * Write requests a flush may issue however large it is: pack, segment, commit and ref.
	 */
	private const MAX_PUTS_PER_FLUSH = 6;

	/**
	 * @return array<string, array{string}>
	 */
	public static function providers(): array
	{
		$cases = [];

		foreach (Lane::providers() as $provider) {
			$cases[$provider] = [$provider];
		}

		return $cases;
	}

	#[Test]
	#[TestDox('a steady flush stays inside the baseline for requests and bytes on $provider')]
	#[Group('strata/e2e')]
	#[DataProvider('providers')]
	public function aSteadyFlushStaysInsideTheBaseline(string $provider): void
	{
		$this->configure($provider);

		$nodes = $this->populate();
		$stats = $this->engine()->providerStats();
		$flushes = [];
		$seconds = [];

		for ($round = 1; $round <= self::ROUNDS; $round++) {
			for ($i = 0; $i < self::REWRITES; $i++) {
				$index = ($round * 7 + $i * 3) % self::NODES;
				$nodes[$index]->set('body', [
					'value' => self::prose($index) . ' round ' . $round,
					'format' => 'plain_text',
				]);
				$nodes[$index]->save();
			}

			$stats->reset();
			$started = microtime(true);
			$result = $this->seal();
			$seconds[] = microtime(true) - $started;
			$operations = $stats->byOperation();

			$this->assertTrue($result->ran, $result->summary());

			$flushes[] = [
				'put' => (int) ($operations['put']['count'] ?? 0),
				'get' => (int) ($operations['get']['count'] ?? 0),
				'head' => (int) ($operations['head']['count'] ?? 0),
				'list' => (int) ($operations['list']['count'] ?? 0),
				'bytes' => (int) ($operations['put']['bytes'] ?? 0),
			];
		}

		$steady = array_slice($flushes, 4);
		$mean = static fn(string $field): float => array_sum(array_column($steady, $field)) /
			count($steady);

		foreach ($flushes as $flush) {
			$this->assertLessThanOrEqual(
				self::MAX_PUTS_PER_FLUSH,
				$flush['put'],
				'a flush writes a handful of objects whatever the site holds',
			);
		}

		$statistics = $this->engine()->frameIndex()->statistics();
		$bucket = $this->bucket();
		$figures = [
			'put_per_flush' => $mean('put'),
			'get_per_flush' => $mean('get'),
			'head_per_flush' => $mean('head'),
			'put_bytes_per_flush' => $mean('bytes'),
			'frames' => (int) $statistics['frames'],
			'stored_bytes' => (int) $statistics['storedBytes'],
			'raw_bytes' => (int) $statistics['rawBytes'],
			'compression_ratio' => (float) $statistics['ratio'],
			'commits' => $this->engine()->commitIndex()->count(),
			'deepest_chain' => $this->deepestChain(),
			'bucket_objects' => $bucket['objects'],
			'bucket_bytes' => $bucket['bytes'],
		];
		$tolerances = [
			'put_bytes_per_flush' => 0.15,
			'stored_bytes' => 0.15,
			'raw_bytes' => 0.15,
			'compression_ratio' => 0.15,
			'bucket_bytes' => 0.15,
		];
		$breaches = [];

		foreach ($figures as $name => $value) {
			$breach = Bench::exact(
				$provider,
				$name,
				$value,
				self::unit($name),
				$tolerances[$name] ?? 0.1,
			);

			if ($breach !== null) {
				$breaches[] = $breach;
			}
		}

		Bench::timing($provider, 'flush_seconds_mean', array_sum($seconds) / count($seconds), 's');
		Bench::timing($provider, 'flush_seconds_max', max($seconds), 's');
		Bench::timing($provider, 'php_peak_bytes', memory_get_peak_usage(true), 'bytes');

		$this->assertSame([], $breaches, implode("\n", $breaches));
	}

	#[Test]
	#[TestDox('requests per flush do not grow with the size of the site')]
	#[Group('strata/e2e')]
	public function flushCostIsIndependentOfSiteSize(): void
	{
		$this->configure('s3');

		$stats = $this->engine()->providerStats();
		$counts = [];

		Drupal::state()->set('e2e.size.warm', 'the first commit also writes the first anchor');
		$this->seal();

		foreach ([10, 100] as $size) {
			for ($i = 0; $i < $size; $i++) {
				Drupal::state()->set(
					'e2e.size.' . $size . '.' . $i,
					'value ' . $i . ' ' . str_repeat('x', 200),
				);
			}

			$stats->reset();
			$this->seal();
			$operations = $stats->byOperation();
			$counts[$size] = (int) ($operations['put']['count'] ?? 0);
		}

		$this->assertSame($counts[10], $counts[100], json_encode($counts) ?: '');
	}

	#[Test]
	#[TestDox('reads per rewrite stay flat as a subject builds a delta chain')]
	#[Group('strata/e2e')]
	public function rewriteReadsDoNotGrowWithChainDepth(): void
	{
		$this->configure('s3');

		$node = $this->article('Chain', self::prose(0));
		$stats = $this->engine()->providerStats();
		$reads = [];

		$this->seal();

		for ($edit = 1; $edit <= 14; $edit++) {
			$node->set('body', [
				'value' => self::prose(0) . ' edit ' . $edit,
				'format' => 'plain_text',
			]);
			$node->save();

			$stats->reset();
			$this->seal();
			$reads[] = (int) ($stats->byOperation()['get']['count'] ?? 0);
		}

		$this->assertLessThanOrEqual(
			min($reads) + 2,
			max($reads),
			'a rewrite reads its parent from the cache, so depth must not show: ' .
				json_encode($reads),
		);
		Bench::timing('s3', 'rewrite_gets_max', max($reads), 'requests');
	}

	/**
	 * Creates the nodes the workload rewrites and seals them as the first commit.
	 *
	 * @return list<Node>
	 *   The nodes.
	 */
	private function populate(): array
	{
		$nodes = [];

		for ($i = 0; $i < self::NODES; $i++) {
			$nodes[] = $this->article('Workload ' . $i, self::prose($i));
		}

		$this->seal();

		return $nodes;
	}

	/**
	 * The deepest delta chain in the store.
	 *
	 * @return int
	 *   Links, or 0 when nothing is delta coded.
	 */
	private function deepestChain(): int
	{
		$deepest = $this->engine()->frameIndex()->deepestChains(1, 1);

		return $deepest === [] ? 0 : $deepest[0]->deltaDepth;
	}

	/**
	 * What the bucket holds under this test's site.
	 *
	 * @return array{objects: int, bytes: int}
	 *   Object count and bytes, as the endpoint lists them.
	 */
	private function bucket(): array
	{
		$objects = 0;
		$bytes = 0;
		$cursor = null;

		do {
			$page = $this->provider()->list('', $cursor, 1000);

			foreach ($page->objects as $meta) {
				$objects++;
				$bytes += $meta->size;
			}

			$cursor = $page->cursor;
		} while ($cursor !== null);

		return ['objects' => $objects, 'bytes' => $bytes];
	}

	/**
	 * The unit a figure is counted in.
	 *
	 * @param string $name
	 *   The figure's name.
	 *
	 * @return string
	 *   The unit.
	 */
	private static function unit(string $name): string
	{
		return match (true) {
			str_contains($name, 'bytes') => 'bytes',
			str_contains($name, 'ratio') => 'x',
			str_contains($name, 'per_flush') => 'requests',
			default => 'count',
		};
	}
}
