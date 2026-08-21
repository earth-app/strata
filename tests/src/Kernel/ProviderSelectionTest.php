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
	protected static $modules = [
		'system',
		'user',
		'key',
		'strata',
		'strata_s3',
		'strata_azure',
		'strata_gcs',
		'strata_b2',
	];

	/**
	 * An account key that is valid base64, which is all Azure credentials need of one.
	 */
	private const AZURE_KEY = 'c3RyYXRhLXNoYXJlZC1rZXktZm9yLXRlc3Rpbmc=';

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

	#[Test]
	#[TestDox('every installed provider submodule is registered under its own id')]
	#[Group('strata/storage')]
	public function everySubmoduleRegistersItsOwnId(): void
	{
		$ids = $this->engine()->providerIds();

		foreach (['s3', 'azure', 'gcs', 'b2'] as $id) {
			$this->assertContains($id, $ids);
		}

		$this->assertSame($ids, array_unique($ids), 'two submodules cannot share an id');
	}

	#[Test]
	#[TestDox('the azure provider is selected when configuration names it')]
	#[Group('strata/storage')]
	public function azureProviderIsSelectedFromConfiguration(): void
	{
		$this->config('strata.settings')
			->set('provider', 'azure')
			->set('site_id', 'earth-app')
			->set('azure.account', 'strataaccount')
			->set('azure.container', 'backups')
			->set('azure.account_key', self::AZURE_KEY)
			->set('azure.access_tier', 'Cool')
			->set('azure.block_threshold', 4 * 1024 * 1024)
			->save();

		$this->engine()->reset();

		$provider = $this->engine()->provider();
		$built = $this->container->get('strata_azure.factory')->create();

		$this->assertSame('azure', $provider->id());
		$this->assertSame('_strata/earth-app/', $built->endpoint()->keys->prefix);
		$this->assertSame('Cool', $built->endpoint()->accessTier);
		$this->assertSame(4 * 1024 * 1024, $built->endpoint()->blockThreshold);
	}

	#[Test]
	#[
		TestDox(
			'selecting azure with no credential is refused rather than authenticating with nothing',
		),
	]
	#[Group('strata/storage')]
	public function azureWithoutCredentialIsRefused(): void
	{
		$this->config('strata.settings')
			->set('provider', 'azure')
			->set('azure.account', 'strataaccount')
			->set('azure.container', 'backups')
			->save();

		$this->engine()->reset();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('neither an account key nor a sas token');

		$this->container->get('strata_azure.factory')->create();
	}

	#[Test]
	#[TestDox('the gcs provider is selected when configuration names it')]
	#[Group('strata/storage')]
	public function gcsProviderIsSelectedFromConfiguration(): void
	{
		$this->config('strata.settings')
			->set('provider', 'gcs')
			->set('site_id', 'earth-app')
			->set('gcs.bucket', 'strata-backups')
			->set('gcs.access_token', 'ya29.kernel-test')
			->set('gcs.storage_class', 'NEARLINE')
			->set('gcs.resumable_threshold', 16 * 1024 * 1024)
			->save();

		$this->engine()->reset();

		$provider = $this->engine()->provider();
		$built = $this->container->get('strata_gcs.factory')->create();

		$this->assertSame('gcs', $provider->id());
		$this->assertSame('_strata/earth-app/', $built->endpoint()->keys->prefix);
		$this->assertSame('NEARLINE', $built->endpoint()->storageClass);
		$this->assertSame(16 * 1024 * 1024, $built->endpoint()->resumableThreshold);
	}

	#[Test]
	#[TestDox('selecting gcs with no bucket is refused rather than addressing nothing')]
	#[Group('strata/storage')]
	public function gcsWithoutBucketIsRefused(): void
	{
		$this->config('strata.settings')->set('provider', 'gcs')->set('gcs.bucket', '')->save();
		$this->engine()->reset();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no bucket is configured');

		$this->container->get('strata_gcs.factory')->create();
	}

	#[Test]
	#[TestDox('the b2 provider is selected when configuration names it')]
	#[Group('strata/storage')]
	public function b2ProviderIsSelectedFromConfiguration(): void
	{
		$this->config('strata.settings')
			->set('provider', 'b2')
			->set('site_id', 'earth-app')
			->set('b2.bucket_id', 'bucket-id-1')
			->set('b2.bucket_name', 'strata-backups')
			->set('b2.key_id', '0022deadbeef')
			->set('b2.application_key', 'K002Example')
			->set('b2.large_file_threshold', 8 * 1024 * 1024)
			->save();

		$this->engine()->reset();

		$provider = $this->engine()->provider();
		$built = $this->container->get('strata_b2.factory')->create();

		$this->assertSame('b2', $provider->id());
		$this->assertSame('_strata/earth-app/', $built->endpoint()->keys->prefix);
		$this->assertSame('bucket-id-1', $built->endpoint()->bucketId);
		$this->assertSame(8 * 1024 * 1024, $built->endpoint()->largeFileThreshold);
	}

	#[Test]
	#[TestDox('selecting b2 with the bucket named only one way is refused')]
	#[Group('strata/storage')]
	public function b2WithoutBothNamesIsRefused(): void
	{
		$this->config('strata.settings')
			->set('provider', 'b2')
			->set('b2.bucket_id', 'bucket-id-1')
			->set('b2.bucket_name', '')
			->save();

		$this->engine()->reset();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('not named by both id and name');

		$this->container->get('strata_b2.factory')->create();
	}
}
