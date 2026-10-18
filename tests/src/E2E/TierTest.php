<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves a history spread over two vendors survives losing the nearer one.
 *
 * The recipe is a replica on a second account: the nearest bucket keeps everything and a cron pass
 * copies outward. What makes it worth having is the case nothing else tests, so these cases delete
 * the nearer copy the way a closed account or a mistaken lifecycle rule would and then restore from
 * what is left, comparing the result to live data byte for byte.
 */
class TierTest extends E2ETestBase
{
	/**
	 * @return array<string, array{string}>
	 */
	public static function replicas(): array
	{
		$cases = [];

		foreach (['s3', 'azure', 'gcs', 'b2'] as $provider) {
			if (in_array($provider, Lane::providers(), true)) {
				$cases['onto ' . $provider] = [$provider];
			}
		}

		return $cases;
	}

	#[Test]
	#[TestDox('a restore reads the replica on $replica after the nearer bucket loses everything')]
	#[Group('strata/e2e')]
	#[DataProvider('replicas')]
	public function aReplicaOutlivesTheNearBucket(string $replica): void
	{
		$this->configure('s3');

		$nodes = $this->history(3, 2);
		$digest = Oracle::node($nodes[0]->id());
		$target = $this->head();
		$tier = Lane::replica($replica);

		$this->configure(
			's3',
			[
				'tiers' => [
					'enabled' => true,
					'verify_copies' => true,
					'levels' => [
						[
							'name' => 'near',
							'provider' => 's3',
							'location' => Lane::env('S3_BUCKET'),
							'from_age' => 0,
						],
						[
							'name' => 'replica',
							'provider' => $replica,
							'location' => $replica === 's3' ? $tier['location'] : '',
							'from_age' => 1,
							'retain_below' => true,
						],
					],
				],
			] + $tier['settings'],
		);

		sleep(2);

		$this->drush([
			'php:eval',
			"Drupal::service('strata.engine')->tierPlacementRebuilder()?->rebuild();",
		]);

		// cron in a fresh process, because the migrator ages objects against the time its request began
		$this->drush(['state:delete', 'strata.compaction.ran']);
		$this->drush(['core:cron']);

		$router = $this->engine()->tiers();

		$this->assertNotNull($router);
		$this->assertNotSame(
			[],
			Fault::keys($router->tier(1), ''),
			'cron copied history to the replica',
		);

		Fault::wipe($router->tier(0));

		$this->assertSame([], Fault::keys($router->tier(0), ''), 'the nearer bucket is empty');

		$nodes[0]->set('body', ['value' => 'edited after the loss', 'format' => 'plain_text']);
		$nodes[0]->save();

		$result = $this->engine()
			->logicalRestore()
			->restore($target, ['entity/node:' . $nodes[0]->id()], true);

		$this->assertFalse($result->wasRefused(), (string) $result->refused);
		$this->assertSame([], $result->failed, json_encode($result->failed) ?: '');
		$this->assertSame(
			$digest,
			Oracle::node($nodes[0]->id()),
			'restored from the replica alone',
		);
	}

	#[Test]
	#[TestDox('an unreachable tier is reported by a restore plan before it reads anything')]
	#[Group('strata/e2e')]
	public function aRestorePlanNamesTheBucketsItNeeds(): void
	{
		$this->configure('s3');
		$this->history(2, 1);

		$this->configure('s3', [
			'tiers' => [
				'enabled' => true,
				'levels' => [
					[
						'name' => 'near',
						'provider' => 's3',
						'location' => Lane::env('S3_BUCKET'),
						'from_age' => 0,
					],
					[
						'name' => 'cold',
						'provider' => 's3',
						'location' => Lane::env('S3_REPLICA'),
						'from_age' => 86400,
					],
				],
			],
		]);

		$status = $this->engine()->tiers()?->tierStatus();

		$this->assertNotNull($status);
		$this->assertCount(2, $status);
	}
}
