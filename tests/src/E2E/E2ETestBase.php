<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\E2E;

use Drupal;
use Drupal\Component\Serialization\Yaml;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Engine;
use Drupal\strata\Flush\FlushResult;
use Drupal\strata\Storage\StorageProviderInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A test that runs against the installed site, its real database and a real object store.
 *
 * Nothing here is mocked. The site was installed by `drush site:install`, the database is MariaDB or
 * PostgreSQL, and the provider is an emulator in its own container. Each test gets a site id of its
 * own, so its history sits under its own prefix, and an empty set of local Strata tables, so no test
 * inherits another's index.
 */
abstract class E2ETestBase extends TestCase
{
	/**
	 * The key entity every test seals with.
	 */
	protected const KEY = 'strata_e2e';

	/**
	 * The site id this test's history sits under.
	 */
	protected string $site = '';

	/**
	 * The provider this test is configured for.
	 */
	protected string $lane = 'local';

	/**
	 * Whether the content model has been created in this process.
	 */
	private static bool $modelled = false;

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		self::model();
		$this->restoreDefaults();
		$this->wipeLocal();
		$this->site = 'e2e-' . bin2hex(random_bytes(4));
		$this->configure('local');
	}

	/**
	 * Points Strata at a provider, or skips the test when this run does not cover it.
	 *
	 * @param string $provider
	 *   A name from `Lane::ALL`.
	 * @param array<string, mixed> $extra
	 *   Further settings by dotted key.
	 */
	protected function configure(string $provider, array $extra = []): void
	{
		if (!in_array($provider, Lane::providers(), true)) {
			$this->markTestSkipped(sprintf('%s is not in STRATA_E2E_PROVIDERS', $provider));
		}

		$this->lane = $provider;

		Lane::configure(
			$provider,
			$this->site,
			array_merge(['key' => self::KEY, 'cipher.id' => 'xchacha20poly1305'], $extra),
		);
		$this->reset();
	}

	/**
	 * Rebuilds the engine and the capture scope after a settings change.
	 */
	protected function reset(): void
	{
		Drupal::service('strata.capture_scope')->reset();
		$this->engine()->reset();
	}

	/**
	 * The assembled engine.
	 *
	 * @return Engine
	 *   The engine.
	 */
	protected function engine(): Engine
	{
		return Drupal::service('strata.engine');
	}

	/**
	 * The store the engine writes to.
	 *
	 * @return StorageProviderInterface
	 *   The provider, already scoped to this test's site id.
	 */
	protected function provider(): StorageProviderInterface
	{
		return $this->engine()->provider();
	}

	/**
	 * Gets everything captured so far into the journal, then seals it as one commit.
	 *
	 * @return FlushResult
	 *   What the flush did.
	 */
	protected function seal(): FlushResult
	{
		$this->settle();

		return $this->engine()->flusher()->flush(true);
	}

	/**
	 * Drains the buffers that normally empty when a request ends.
	 */
	protected function settle(): void
	{
		Drupal::service('strata.statement_capture')->commit();
		Drupal::service('state')->destruct();
	}

	/**
	 * The commit the ref points at.
	 *
	 * @return string
	 *   The id, or an empty string when nothing has been sealed.
	 */
	protected function head(): string
	{
		return (string) ($this->engine()->commitIndex()->newest()['id'] ?? '');
	}

	/**
	 * Creates and saves an article.
	 *
	 * @param string $title
	 *   The title.
	 * @param string $body
	 *   The body text.
	 *
	 * @return Node
	 *   The saved node.
	 */
	protected function article(string $title, string $body): Node
	{
		$node = Node::create([
			'type' => 'article',
			'title' => $title,
			'body' => ['value' => $body, 'format' => 'plain_text'],
			'uid' => 1,
			'status' => 1,
		]);
		$node->save();

		return $node;
	}

	/**
	 * The object key that holds a frame, whether it sits in a pack or on its own.
	 *
	 * @param string $frame
	 *   The frame digest.
	 *
	 * @return string
	 *   The key relative to this test's site.
	 */
	protected function objectKeyOf(string $frame): string
	{
		$record = $this->engine()->frameIndex()->get($frame);

		$this->assertNotNull($record, 'the frame is indexed');

		return $record->isPacked()
			? Hash::key((string) $record->pack, ObjectStore::PACK_PREFIX)
			: Hash::key($frame, ObjectStore::FRAME_PREFIX);
	}

	/**
	 * Builds a history of several commits over several nodes, each edited in turn.
	 *
	 * @param int $edits
	 *   How many times every node is rewritten after it is created, one commit per round.
	 * @param int $count
	 *   How many nodes.
	 *
	 * @return list<Node>
	 *   The nodes.
	 */
	protected function history(int $edits = 3, int $count = 3): array
	{
		$nodes = [];
		$bodies = [];

		for ($i = 0; $i < $count; $i++) {
			$bodies[$i] = self::prose($i);
			$nodes[] = $this->article('Subject ' . $i, $bodies[$i]);
		}

		$this->seal();

		for ($edit = 1; $edit <= $edits; $edit++) {
			foreach ($nodes as $i => $node) {
				$node->set('body', [
					'value' => $bodies[$i] . ' edit ' . $edit,
					'format' => 'plain_text',
				]);
				$node->save();
			}

			$this->seal();
		}

		return $nodes;
	}

	/**
	 * About six kilobytes of text that does not compress on its own, the same every run.
	 *
	 * Delta coding only pays when the previous version is a better dictionary than anything else, and
	 * that needs a body that standalone compression cannot already shrink. A chain of digests is
	 * incompressible and repeatable, which a random string is not.
	 *
	 * @param int $seed
	 *   Which body.
	 *
	 * @return string
	 *   The text.
	 */
	protected static function prose(int $seed): string
	{
		$text = '';
		$digest = hash('sha256', 'strata-e2e-' . $seed);

		while (strlen($text) < 6000) {
			$digest = hash('sha256', $digest);
			$text .= $digest . ' ';
		}

		return $text;
	}

	/**
	 * Runs a Drush command in a separate process and returns what it printed.
	 *
	 * @param list<string> $arguments
	 *   The command and its arguments.
	 * @param int $expected
	 *   The exit code the command should end with.
	 *
	 * @return string
	 *   Standard output and standard error together.
	 */
	protected function drush(array $arguments, int $expected = 0): string
	{
		$command = array_merge(
			['/srv/drupal/vendor/bin/drush', '--root=/srv/drupal/web'],
			$arguments,
		);
		$process = proc_open(
			$command,
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			'/srv/drupal',
		);

		if (!is_resource($process)) {
			throw new RuntimeException('Could not start drush');
		}

		$out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
		$code = proc_close($process);

		$this->assertSame($expected, $code, 'drush ' . implode(' ', $arguments) . "\n" . $out);

		return $out;
	}

	/**
	 * Puts `strata.settings` back to what the module ships, so one test's settings never reach the next.
	 */
	private function restoreDefaults(): void
	{
		$defaults = Yaml::decode(
			(string) file_get_contents(dirname(__DIR__, 3) . '/config/install/strata.settings.yml'),
		);

		Drupal::configFactory()->getEditable('strata.settings')->setData($defaults)->save();
		Drupal::service('strata.capture_scope')->reset();
		$this->engine()->reset();
	}

	/**
	 * Empties Strata's local tables and state, so a test starts with no index.
	 */
	private function wipeLocal(): void
	{
		$database = Drupal::database();

		foreach (array_values($database->schema()->findTables('strata_%')) as $table) {
			$database->truncate($table)->execute();
		}

		$keys =
			$database
				->select('key_value', 'k')
				->fields('k', ['name'])
				->condition('collection', 'state')
				->condition('name', 'strata.%', 'LIKE')
				->execute()
				?->fetchCol() ?? [];

		if ($keys !== []) {
			Drupal::state()->deleteMultiple($keys);
		}

		$this->ensureKey();
	}

	/**
	 * Makes sure the key entity the cipher seals with exists.
	 */
	private function ensureKey(): void
	{
		$storage = Drupal::entityTypeManager()->getStorage('key');

		if ($storage->load(self::KEY) === null) {
			$id = Drupal::service('strata.key_maker')->create(self::KEY);

			if ($id !== self::KEY) {
				throw new RuntimeException(
					sprintf('The key was made as %s, not %s', $id, self::KEY),
				);
			}
		}
	}

	/**
	 * Creates the content type the tests write.
	 */
	private static function model(): void
	{
		if (self::$modelled) {
			return;
		}

		if (FieldStorageConfig::loadByName('node', 'body') === null) {
			FieldStorageConfig::create([
				'field_name' => 'body',
				'entity_type' => 'node',
				'type' => 'text_with_summary',
			])->save();
		}

		if (NodeType::load('article') === null) {
			$type = NodeType::create(['type' => 'article', 'name' => 'Article']);
			$type->save();

			FieldConfig::create([
				'field_storage' => FieldStorageConfig::loadByName('node', 'body'),
				'bundle' => 'article',
				'label' => 'Body',
				'settings' => ['display_summary' => true],
			])->save();
		}

		self::$modelled = true;
	}
}
