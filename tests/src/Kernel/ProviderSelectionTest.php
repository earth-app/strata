<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Engine;
use Drupal\strata\Site\SiteScopedProvider;
use Drupal\strata\Storage\RecordingProvider;
use Drupal\strata\Storage\StorageProviderManager;
use Drupal\strata_s3\S3StorageProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

/**
 * Proves a submodule's provider is discovered and selected from configuration.
 */
class ProviderSelectionTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'key', 'strata', 'strata_s3'];

	private function engine(): Engine
	{
		return $this->container->get('strata.engine');
	}

	#[Test]
	#[TestDox('a submodule provider is collected into the manager')]
	#[Group('strata/storage')]
	public function submoduleProviderIsCollected(): void
	{
		$this->assertTrue($this->container->has('strata.storage_providers'));

		$manager = $this->container->get('strata.storage_providers');

		$this->assertInstanceOf(StorageProviderManager::class, $manager);
	}

	#[Test]
	#[TestDox('the s3 provider is selected when configuration names it')]
	#[Group('strata/storage')]
	public function s3ProviderIsSelectedFromConfiguration(): void
	{
		$this->config('strata.settings')
			->set('provider', 's3')
			->set('site_id', 'kernel-test')
			->set('s3.bucket', 'strata-backups')
			->set('s3.region', 'us-east-1')
			->set('s3.endpoint', 'http://127.0.0.1:9000')
			->set('s3.access_key_id', 'strata')
			->set('s3.secret_access_key', 'stratatest')
			->set('s3.path_style', true)
			->save();

		$this->engine()->reset();
		$provider = $this->engine()->provider();

		// site scoping wraps request counting wraps the endpoint, and each reports the id below it
		$this->assertInstanceOf(SiteScopedProvider::class, $provider);
		$this->assertInstanceOf(RecordingProvider::class, $provider->unscoped());
		$this->assertInstanceOf(S3StorageProvider::class, $provider->unscoped()->inner());
		$this->assertSame('s3', $provider->id());
	}

	#[Test]
	#[TestDox('the key prefix keeps every object under one folder so the bucket stays shareable')]
	#[Group('strata/storage')]
	public function keysAreConfinedToOneFolder(): void
	{
		$this->config('strata.settings')
			->set('provider', 's3')
			->set('site_id', 'earth-app')
			->set('s3.bucket', 'strata-backups')
			->save();

		$this->engine()->reset();

		$this->assertStringStartsWith(
			'_strata/earth-app/',
			$this->container->get('strata_s3.factory')->create()->endpoint()->keyPrefix,
		);
	}

	#[Test]
	#[TestDox('a configured multipart threshold reaches the provider capabilities')]
	#[Group('strata/storage')]
	public function multipartThresholdReachesCapabilities(): void
	{
		$this->config('strata.settings')
			->set('provider', 's3')
			->set('s3.bucket', 'strata-backups')
			->set('s3.multipart_threshold', 16 * 1024 * 1024)
			->save();

		$this->engine()->reset();

		$this->assertSame(
			16 * 1024 * 1024,
			$this->container->get('strata_s3.factory')->create()->capabilities()->maxSinglePut,
		);
	}

	#[Test]
	#[TestDox('selecting s3 with no bucket is refused rather than addressing nothing')]
	#[Group('strata/storage')]
	public function s3WithoutBucketIsRefused(): void
	{
		$this->config('strata.settings')->set('provider', 's3')->set('s3.bucket', '')->save();
		$this->engine()->reset();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no bucket is configured');

		$this->container->get('strata_s3.factory')->create();
	}

	#[Test]
	#[TestDox('the local provider is still selected by default')]
	#[Group('strata/storage')]
	public function localRemainsTheDefault(): void
	{
		$this->config('strata.settings')->set('local_path', $this->storeRoot)->save();
		$this->engine()->reset();

		$this->assertSame('local', $this->engine()->provider()->id());
	}
}
