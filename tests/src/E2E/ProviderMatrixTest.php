<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\E2E;

use Drupal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Redis;

/**
 * Proves every backend in the lane behaves the way Strata's storage contract assumes.
 *
 * The storage interface is a contract with eleven operations and four capability flags, and each
 * emulator implements a different subset of S3, Blob, Cloud Storage or B2 behind it. Nothing in the
 * unit lane can tell whether an endpoint honours a range read or lists in key order, so these cases
 * ask the real thing, one backend at a time.
 */
class ProviderMatrixTest extends E2ETestBase
{
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
	#[TestDox('an object written by one process is readable by another straight away on $provider')]
	#[Group('strata/e2e')]
	#[DataProvider('providers')]
	public function aSecondProcessReadsWhatTheFirstWrote(string $provider): void
	{
		$this->configure($provider);

		$key = 'e2e/crossprocess-' . bin2hex(random_bytes(4));

		$this->provider()->put($key, bin2hex(random_bytes(64)));

		$seen = $this->drush([
			'php:eval',
			sprintf(
				"echo Drupal::service('strata.engine')->provider()->exists('%s') ? 'present' : 'absent';",
				$key,
			),
		]);

		$this->assertStringContainsString(
			'present',
			$seen,
			$provider . ' showed another process nothing',
		);
	}

	#[Test]
	#[TestDox('a Redis journal queues a capture in a stream and a flush seals it into the store')]
	#[Group('strata/e2e')]
	public function aRedisJournalQueuesAndFlushes(): void
	{
		$this->configure('s3', ['journal.backend' => 'redis']);

		$redis = new Redis();
		$redis->connect(Lane::env('REDIS_HOST', 'redis'));
		$redis->del('strata:journal');

		$pending = $this->drush([
			'php:eval',
			"Drupal\\node\\Entity\\Node::create(['type' => 'article', 'title' => 'Through redis', 'uid' => 1, 'status' => 1, 'body' => ['value' => 'queued in a stream', 'format' => 'plain_text']])->save(); echo 'pending=', Drupal::service('strata.journal')->pending();",
		]);

		$this->assertMatchesRegularExpression('/pending=[1-9]/', $pending, $pending);
		$this->assertGreaterThan(0, $redis->xLen('strata:journal'), 'the capture is in the stream');
		$this->assertSame(
			0,
			(int) Drupal::database()
				->select('strata_journal', 'j')
				->countQuery()
				->execute()
				?->fetchField(),
			'and not in the database table',
		);

		$flushed = $this->drush(['strata:flush']);

		$this->assertStringContainsString('Sealed', $flushed . ' Sealed');
		$this->assertSame(0, $redis->xLen('strata:journal'), 'the flush drained the stream');
		$this->assertGreaterThanOrEqual(
			1,
			(int) Drupal::database()
				->select('strata_commit', 'c')
				->countQuery()
				->execute()
				?->fetchField(),
		);
		$this->assertTrue($this->engine()->verifier()->verify('heads/main')->isClean());
	}

	#[Test]
	#[TestDox('switching providers in one process writes to the new destination on $provider')]
	#[Group('strata/e2e')]
	#[DataProvider('providers')]
	public function aSettingsChangeMovesTheNextWrite(string $provider): void
	{
		$this->configure('local');
		$this->provider()->put('e2e/before', 'local');

		$this->configure($provider);
		$this->provider()->put('e2e/after', $provider);

		$this->assertFalse($this->provider()->exists('e2e/before') && $provider !== 'local');
		$this->assertSame($provider, $this->provider()->get('e2e/after'));
	}
}
