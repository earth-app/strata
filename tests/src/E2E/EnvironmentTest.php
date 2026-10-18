<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\E2E;

use Drupal;
use Drupal\strata\Storage\ByteRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves the container is the environment the rest of the lane assumes, and records its conditions.
 *
 * A lane that silently fell back to gzip, ran on SQLite or sat on a host with no room to write would
 * pass every later test and mean nothing, so this is the first thing that fails. What it measures
 * is written to the report beside the figures every other test records.
 */
class EnvironmentTest extends E2ETestBase
{
	/**
	 * Smallest memory limit a lane container may run under, in bytes.
	 */
	private const MIN_MEMORY = 1_073_741_824;

	/**
	 * Least free disk the private store may start with, in bytes.
	 */
	private const MIN_DISK = 2_147_483_648;

	#[Test]
	#[TestDox('the compression, crypto and journal extensions are loaded')]
	#[Group('strata/e2e')]
	public function extensionsAreLoaded(): void
	{
		foreach (['sodium', 'zstd', 'brotli', 'redis', 'zlib'] as $extension) {
			$this->assertTrue(extension_loaded($extension), $extension . ' is not loaded');
		}
	}

	#[Test]
	#[
		TestDox(
			'the zstd and brotli binaries are on the path for the readers that fall back to them',
		),
	]
	#[Group('strata/e2e')]
	public function binariesAreOnThePath(): void
	{
		foreach (['zstd', 'brotli'] as $binary) {
			exec('command -v ' . $binary . ' 2>&1', $output, $code);

			$this->assertSame(0, $code, $binary . ' is not on PATH');
		}
	}

	#[Test]
	#[TestDox('the site runs on the database engine this lane was started for')]
	#[Group('strata/e2e')]
	public function databaseIsTheOneRequested(): void
	{
		$expected = ['mariadb' => 'mysql', 'postgres' => 'pgsql'][Lane::database()] ?? '';

		$this->assertNotSame('', $expected, 'E2E_DB must be mariadb or postgres');
		$this->assertSame($expected, Drupal::database()->driver());
	}

	#[Test]
	#[TestDox('the container has the memory and disk the measurements assume')]
	#[Group('strata/e2e')]
	public function containerHasRoomToWork(): void
	{
		$conditions = Bench::conditions();
		$limit = $conditions['memory_limit_bytes'];

		if ($limit !== null && $limit !== 'max') {
			$this->assertGreaterThanOrEqual(self::MIN_MEMORY, (int) $limit);
		}

		$this->assertGreaterThanOrEqual(self::MIN_DISK, (int) $conditions['disk_free_bytes']);

		Bench::timing('container', 'php_peak_bytes', (int) $conditions['php_peak_bytes'], 'bytes');
	}

	#[Test]
	#[TestDox('the codec registry reports zstd and brotli as writers on this host')]
	#[Group('strata/e2e')]
	public function codecsAreAvailable(): void
	{
		$registry = $this->engine()->codecs();

		$this->assertSame('zstd', $registry->writer()->id());
		$this->assertTrue($registry->canWritePerFrame('zstd'));
		$this->assertTrue($registry->canWritePerFrame('brotli'));
	}

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
	#[TestDox('the $provider backend answers, reports its capabilities and round-trips an object')]
	#[Group('strata/e2e')]
	#[DataProvider('providers')]
	public function backendAnswers(string $provider): void
	{
		$this->configure($provider);

		$store = $this->provider();
		$started = microtime(true);

		$this->assertTrue($store->isReachable(), (string) $store->unreachableReason());

		$capabilities = $store->capabilities();

		Bench::timing($provider, 'probe_seconds', microtime(true) - $started, 's');

		$this->assertTrue($capabilities->rangeRead, $provider . ' must serve a range read');

		$key = 'e2e/environment-' . bin2hex(random_bytes(4));

		$store->put($key, 'strata');

		$this->assertTrue($store->exists($key));
		$this->assertSame('strata', $store->get($key));
		$this->assertSame('rat', $store->get($key, new ByteRange(2, 3)));
		$this->assertContains($key, $store->list('e2e/environment-')->keys());

		$store->delete([$key]);

		$this->assertFalse($store->exists($key));
	}
}
