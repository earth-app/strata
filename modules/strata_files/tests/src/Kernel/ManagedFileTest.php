<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_files\Kernel;

use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\strata\Engine;
use Drupal\strata\File\MediaStore;
use Drupal\strata\Journal\Realm;
use Drupal\TestTools\Extension\SchemaInspector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves Drupal telling us about a managed file is enough to capture it.
 *
 * The managed case is the one Drupal announces, and it is the one worth hooking. Everything about the
 * blocks is covered where the blocks are; what is covered here is the part only a booted Drupal can
 * answer - whether the hook fires, whether it resolves a stream URI, and whether it correctly leaves a
 * temporary file alone.
 */
class ManagedFileTest extends KernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'file', 'key', 'strata', 'strata_files'];

	/**
	 * Directory the store under test writes to.
	 */
	private string $storeRoot = '';

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->installConfig(['strata']);
		$this->installSchema(
			'strata',
			array_keys(
				SchemaInspector::getTablesSpecification(
					$this->container->get('module_handler'),
					'strata',
				),
			),
		);
		$this->installEntitySchema('user');
		$this->installEntitySchema('file');
		$this->installSchema('file', ['file_usage']);

		$this->storeRoot = $this->siteDirectory . '/strata-store';

		$this->config('strata.settings')
			->set('enabled', true)
			->set('provider', 'local')
			->set('local_path', $this->storeRoot)
			->set('cipher.id', 'none')
			->set('capture.file', true)
			->set('file.block_size', 4096)
			->save();

		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();
	}

	private function engine(): Engine
	{
		return $this->container->get('strata.engine');
	}

	/**
	 * Creates a managed file with the given content and status.
	 */
	private function managed(string $name, string $contents, bool $permanent = true): File
	{
		$uri = 'public://' . $name;

		$this->container->get('file_system')->saveData($contents, $uri);

		$file = File::create(['uri' => $uri, 'filename' => $name]);

		if ($permanent) {
			$file->setPermanent();
		} else {
			$file->setTemporary();
		}

		$file->save();

		return $file;
	}

	/**
	 * Block keys the store holds.
	 *
	 * @return list<string>
	 *   The keys.
	 */
	private function blocks(): array
	{
		return $this->engine()
			->provider()
			->list(MediaStore::PREFIX . '/', null, 5000)
			->keys();
	}

	/**
	 * File-realm subjects the journal holds.
	 *
	 * @return list<string>
	 *   Subject paths.
	 */
	private function subjects(): array
	{
		$subjects = [];

		foreach ($this->container->get('strata.journal')->read(500) as $entry) {
			if ($entry['operation']->realm === Realm::FILE) {
				$subjects[] = $entry['operation']->subject;
			}
		}

		return $subjects;
	}

	#[Test]
	#[TestDox('the submodule installs and hands out a file capture')]
	#[Group('strata/file')]
	public function submoduleInstalls(): void
	{
		$this->assertTrue($this->container->has('strata_files.capture'));
		$this->assertNotNull($this->container->get('strata_files.capture'));
	}

	#[Test]
	#[TestDox('saving a permanent managed file captures it under its uri')]
	#[Group('strata/file')]
	public function permanentFileIsCaptured(): void
	{
		$this->managed('video.bin', str_repeat('block content ', 1000));

		$this->assertNotSame([], $this->blocks());
		$this->assertSame(['public://video.bin'], $this->subjects());
	}

	#[Test]
	#[TestDox('a temporary file is left alone, since drupal is about to delete it')]
	#[Group('strata/file')]
	public function temporaryFileIsNotCaptured(): void
	{
		$this->managed('draft.bin', str_repeat('block content ', 1000), false);

		$this->assertSame([], $this->blocks());
		$this->assertSame([], $this->subjects());
	}

	#[Test]
	#[TestDox('a temporary file that becomes permanent is captured then')]
	#[Group('strata/file')]
	public function fileIsCapturedWhenItBecomesPermanent(): void
	{
		$file = $this->managed('promoted.bin', str_repeat('block content ', 1000), false);

		$this->assertSame([], $this->blocks());

		$file->setPermanent();
		$file->save();

		$this->assertNotSame([], $this->blocks());
		$this->assertSame(['public://promoted.bin'], $this->subjects());
	}

	#[Test]
	#[TestDox('the file realm switched off captures nothing, even for a managed file')]
	#[Group('strata/file')]
	public function realmCanBeSwitchedOff(): void
	{
		$this->config('strata.settings')->set('capture.file', false)->save();
		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();

		$this->managed('ignored.bin', str_repeat('block content ', 1000));

		$this->assertSame([], $this->blocks());
	}

	#[Test]
	#[TestDox('editing a managed file stores only what changed')]
	#[Group('strata/file')]
	public function editingStoresOnlyTheChange(): void
	{
		$contents = str_repeat('block content ', 2000);
		$file = $this->managed('edited.bin', $contents);
		$before = count($this->blocks());

		$this->container
			->get('file_system')
			->saveData(substr_replace($contents, 'XXXX', 100, 4), $file->getFileUri(), 1);
		$file->save();

		$after = count($this->blocks());

		$this->assertGreaterThan($before, $after, 'the changed block was stored');
		$this->assertLessThan($before * 2, $after, 'the unchanged blocks were not stored again');
	}
}
