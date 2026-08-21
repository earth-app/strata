<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\Core\Config\Config;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\TestTools\Extension\SchemaInspector;
use Drupal\strata\Cas\Framer;
use Drupal\strata\Cas\MemoryFrameIndex;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Cas\Packer;
use Drupal\strata\Codec\CodecRegistry;
use Drupal\strata\Crypto\CipherInterface;
use Drupal\strata\Crypto\StaticKeyProvider;
use Drupal\strata\Crypto\XChaCha20Poly1305Cipher;
use Drupal\strata\Storage\Plugin\Strata\Storage\LocalStorage;
use Drupal\strata\Tree\CommitLog;
use Drupal\strata\Tree\RefStore;
use Drupal\strata\Tree\BaseReader;
use Drupal\strata\Tree\BaseWriter;

/**
 * Base for tests that need a booted Drupal with Strata installed.
 *
 * The kernel lane exists for what the unit lane cannot reach: the schema this module installs, its
 * configuration, its services, and the capture hooks, which only fire when a real entity is saved
 * through a real entity manager.
 *
 * Every test gets a store on a temporary directory rather than a network endpoint, so the lane is
 * hermetic and fast. The provider contract is the same one S3 implements, so a test written here
 * exercises the code path a site uses.
 */
abstract class StrataKernelTestBase extends KernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'key', 'strata'];

	/**
	 * {@inheritdoc}
	 *
	 * Every value this module writes is validated against `config/schema/` on save. Without this a
	 * setting whose schema says integer and whose form submits a string saves cleanly here and fails
	 * on a site that has schema checking on, which is the shape of bug that reaches a release.
	 *
	 * @var bool
	 */
	protected $strictConfigSchema = true;

	/**
	 * Directory the store under test lives in.
	 */
	protected string $storeRoot = '';

	/**
	 * The provider the store under test writes to.
	 */
	protected ?LocalStorage $provider = null;

	/**
	 * The cipher frames are sealed with.
	 */
	protected ?CipherInterface $cipher = null;

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->installConfig(['strata']);
		$this->installSchema('strata', array_keys($this->strataSchema()));

		// the install file carries hook_requirements and hook_uninstall as well as the schema
		$this->moduleHandler()->loadInclude('strata', 'install');

		$this->storeRoot = $this->siteDirectory . '/strata-store';
		$this->provider = new LocalStorage($this->storeRoot);
		$this->cipher = new XChaCha20Poly1305Cipher(StaticKeyProvider::generate()->key());
	}

	/**
	 * {@inheritdoc}
	 */
	protected function tearDown(): void
	{
		$this->provider = null;
		$this->cipher = null;

		parent::tearDown();
	}

	/**
	 * The schema this module declares, resolved through core rather than by calling the hook.
	 *
	 * @return array<string, array<string, mixed>>
	 *   Table name keyed to its schema definition.
	 */
	protected function strataSchema(): array
	{
		return SchemaInspector::getTablesSpecification($this->moduleHandler(), 'strata');
	}

	/**
	 * The module handler.
	 *
	 * @return ModuleHandlerInterface
	 *   The handler.
	 */
	protected function moduleHandler(): ModuleHandlerInterface
	{
		return $this->container->get('module_handler');
	}

	/**
	 * The provider under test.
	 *
	 * @return LocalStorage
	 *   The provider.
	 */
	protected function provider(): LocalStorage
	{
		return $this->provider ??= new LocalStorage($this->storeRoot);
	}

	/**
	 * The cipher under test.
	 *
	 * @return CipherInterface
	 *   The cipher.
	 */
	protected function cipher(): CipherInterface
	{
		return $this->cipher ??= new XChaCha20Poly1305Cipher(StaticKeyProvider::generate()->key());
	}

	/**
	 * An object store writing to the provider under test.
	 *
	 * @param int $frameSize
	 *   Frame size in bytes.
	 * @param int $packTarget
	 *   Pack target in bytes.
	 *
	 * @return ObjectStore
	 *   A store with an in-memory index.
	 */
	protected function objectStore(int $frameSize = 16384, int $packTarget = 65536): ObjectStore
	{
		return new ObjectStore(
			$this->provider(),
			new MemoryFrameIndex(),
			CodecRegistry::withShippedCodecs(),
			$this->cipher(),
			new Framer($frameSize),
			new Packer($packTarget),
			19,
		);
	}

	/**
	 * A commit log over the provider under test.
	 *
	 * @return CommitLog
	 *   The log.
	 */
	protected function commitLog(): CommitLog
	{
		return new CommitLog($this->provider(), new RefStore($this->provider()));
	}

	/**
	 * A base anchor writer over the provider under test.
	 *
	 * @return BaseWriter
	 *   The writer.
	 */
	protected function baseWriter(): BaseWriter
	{
		return new BaseWriter($this->provider());
	}

	/**
	 * A base anchor reader over the provider under test.
	 *
	 * @return BaseReader
	 *   The reader.
	 */
	protected function baseReader(): BaseReader
	{
		return new BaseReader($this->provider());
	}

	/**
	 * The module's editable settings.
	 *
	 * @return Config
	 *   The settings object.
	 */
	protected function settings(): Config
	{
		return $this->config('strata.settings');
	}
}
