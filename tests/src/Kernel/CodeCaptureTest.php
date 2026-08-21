<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Code\CodeCapture;
use Drupal\strata\Engine;
use Drupal\strata\Journal\JournalInterface;
use Drupal\strata\Journal\Realm;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves the code realm is captured on a deploy and costs nothing in between.
 *
 * The unit lane covers the walk and the redaction over a fixture tree. What it cannot cover is the
 * claim the cost model rests on: a cron run on a site nobody deployed to must append no operations
 * at all, and that depends on the digest being compared against state through a real state service.
 */
class CodeCaptureTest extends StrataKernelTestBase
{
	/**
	 * A throwaway project root the capture walks.
	 */
	private string $projectRoot = '';

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->projectRoot = $this->siteDirectory . '/strata-project';

		$this->write('modules/custom/acme/acme.info.yml', "name: Acme\ntype: module\n");
		$this->write('modules/custom/acme/acme.module', '<?php // acme');
		$this->write('modules/custom/acme/src/Thing.php', '<?php class Thing {}');
		$this->write('composer.lock', '{"packages":[]}');

		$this->config('strata.settings')
			->set('enabled', true)
			->set('provider', 'local')
			->set('local_path', $this->storeRoot)
			->set('cipher.id', 'none')
			->set('code.root', $this->projectRoot)
			->save();

		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();
	}

	private function engine(): Engine
	{
		return $this->container->get('strata.engine');
	}

	private function journal(): JournalInterface
	{
		return $this->container->get('strata.journal');
	}

	private function capture(): CodeCapture
	{
		return $this->engine()->codeCapture();
	}

	/**
	 * Writes a file under the throwaway project root.
	 */
	private function write(string $relative, string $contents): void
	{
		$full = $this->projectRoot . '/' . $relative;
		$directory = dirname($full);

		if (!is_dir($directory)) {
			mkdir($directory, 0777, true);
		}

		file_put_contents($full, $contents);
	}

	/**
	 * Subjects the journal holds for the code realm.
	 *
	 * @return list<string>
	 *   The subjects, in the order they were appended.
	 */
	private function codeSubjects(): array
	{
		$subjects = [];

		foreach ($this->journal()->read(5000) as $entry) {
			if ($entry['operation']->realm === Realm::CODE) {
				$subjects[] = $entry['operation']->subject;
			}
		}

		return $subjects;
	}

	#[Test]
	#[TestDox('a first pass captures the site\'s own code and its lockfile')]
	#[Group('strata/code')]
	public function firstPassCapturesEverything(): void
	{
		$report = $this->capture()->capture();
		$subjects = $this->codeSubjects();

		$this->assertTrue($report->ran);
		$this->assertSame(3, $report->files);
		$this->assertSame(1, $report->lockfiles);
		$this->assertSame([], $report->problems);
		$this->assertContains('modules/custom/acme/acme.module', $subjects);
		$this->assertContains('modules/custom/acme/src/Thing.php', $subjects);
		$this->assertContains('composer.lock', $subjects);
	}

	#[Test]
	#[TestDox('a second pass over unchanged code appends nothing at all')]
	#[Group('strata/code')]
	public function unchangedCodeCostsNothing(): void
	{
		$this->capture()->capture();
		$after = count($this->codeSubjects());

		$report = $this->capture()->capture();

		$this->assertFalse($report->ran);
		$this->assertSame('the code and the vendor tree are unchanged', $report->skipped);
		$this->assertSame(0, $report->files);
		$this->assertCount($after, $this->codeSubjects(), 'nothing was appended');
	}

	#[Test]
	#[TestDox('an edited file makes the next pass capture again')]
	#[Group('strata/code')]
	public function editedCodeIsCapturedAgain(): void
	{
		$this->capture()->capture();
		$this->write('modules/custom/acme/acme.module', '<?php // acme, edited');

		$report = $this->capture()->capture();

		$this->assertTrue($report->ran);
		$this->assertSame(3, $report->files);
	}

	#[Test]
	#[TestDox('a forced pass captures whether or not anything moved')]
	#[Group('strata/code')]
	public function forcedPassAlwaysCaptures(): void
	{
		$this->capture()->capture();

		$this->assertTrue($this->capture()->capture(true)->ran);
	}

	#[Test]
	#[TestDox('the code realm is skipped by name when it is switched off')]
	#[Group('strata/code')]
	public function realmCanBeSwitchedOff(): void
	{
		$this->config('strata.settings')->set('capture.code', false)->save();
		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();

		$report = $this->capture()->capture();

		$this->assertFalse($report->ran);
		$this->assertSame('the code realm is not captured', $report->skipped);
		$this->assertSame([], $this->codeSubjects());
	}

	#[Test]
	#[TestDox('settings.php is captured with its secrets replaced by a marker')]
	#[Group('strata/code')]
	public function settingsAreRedacted(): void
	{
		$this->write(
			'sites/default/settings.php',
			"<?php\n\$settings['hash_salt'] = 'a-real-salt';\n\$settings['keep'] = 1;\n",
		);

		$report = $this->capture()->capture();
		$payload = '';

		foreach ($this->journal()->read(5000) as $entry) {
			if ($entry['operation']->subject === 'sites/default/settings.php') {
				$payload = (string) $entry['payload'];
			}
		}

		$this->assertSame(1, $report->redacted);
		$this->assertNotSame('', $payload);
		$this->assertStringNotContainsString('a-real-salt', $payload);
		$this->assertStringContainsString('keep', $payload);
	}

	#[Test]
	#[TestDox('a measurement says what a capture would cost without appending anything')]
	#[Group('strata/code')]
	public function measurementWritesNothing(): void
	{
		$measured = $this->capture()->measure();

		$this->assertSame(3, $measured['files']);
		$this->assertSame(1, $measured['lockfiles']);
		$this->assertGreaterThan(0, $measured['bytes']);
		$this->assertFalse($measured['truncated']);
		$this->assertSame([], $this->codeSubjects());
	}

	#[Test]
	#[TestDox('a walk that reaches its bound reports the realm as incomplete')]
	#[Group('strata/code')]
	public function boundedWalkIsReported(): void
	{
		$this->config('strata.settings')->set('code.max_files', 2)->save();
		$this->engine()->reset();

		$report = $this->capture()->capture();

		$this->assertSame(2, $report->files);
		$this->assertNotSame([], $report->problems);
		$this->assertStringContainsString('incomplete', $report->problems[0]);
	}
}
