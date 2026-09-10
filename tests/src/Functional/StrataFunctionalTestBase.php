<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Functional;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\Config;
use Drupal\Tests\BrowserTestBase;
use Drupal\node\NodeInterface;
use Drupal\strata\Engine;
use Drupal\strata\Flush\FlushResult;
use Drupal\user\UserInterface;

/**
 * Base for tests that drive Strata through a browser.
 *
 * The functional lane exists for what the kernel lane cannot reach: a route's access check, a form
 * that has to render and then round-trip its own values, and a page built by a real theme layer. A
 * controller that fatals only once a template asks for a key it never set fails here and nowhere
 * else.
 *
 * Every test writes to a store inside its own test site and turns encryption off. Encryption is on
 * by default with no key, so a site that has not chosen one refuses to assemble the engine at all -
 * which is the correct behaviour and would make every page in this lane a test of that one refusal.
 *
 * @see Engine
 */
abstract class StrataFunctionalTestBase extends BrowserTestBase
{
	/**
	 * The content type `content()` creates nodes of.
	 */
	public const CONTENT_TYPE = 'strata_page';

	/**
	 * A commit address no store holds, for driving a route that takes one.
	 */
	public const ABSENT_COMMIT = '0000000000000000000000000000000000000000000000000000000000000000';

	/**
	 * A finding code no ledger holds.
	 */
	public const ABSENT_CODE = 'strata.pack_missing';

	/**
	 * A branch name no ref holds, matching what the merge route's requirement allows.
	 */
	public const ABSENT_BRANCH = 'absent-branch';

	/**
	 * The bucket a timeline window route is asked for, in unix seconds.
	 */
	public const WINDOW_START = 1_755_000_000;

	/**
	 * That bucket's width in seconds.
	 */
	public const WINDOW_WIDTH = 60;

	/**
	 * {@inheritdoc}
	 *
	 * @var string
	 */
	protected $defaultTheme = 'stark';

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
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'node', 'field', 'key', 'strata', 'strata_ui'];

	/**
	 * Directory the store under test lives in.
	 */
	protected string $storeRoot = '';

	/**
	 * Whether the content type has been created yet.
	 */
	private bool $contentTypeExists = false;

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->storeRoot = $this->storePath();
		$this->configure();
	}

	#region Configuration

	/**
	 * Points the module at a store inside this test site and turns encryption off.
	 *
	 * @param array<string, mixed> $overrides
	 *   Dotted setting keys to apply on top, so a test can change one dial without restating the
	 *   rest of the configuration.
	 */
	protected function configure(array $overrides = []): void
	{
		$settings = $this->settings()
			->set('enabled', true)
			->set('provider', 'local')
			->set('local_path', $this->storeRoot)
			->set('cipher.id', 'none');

		foreach ($overrides as $key => $value) {
			$settings->set($key, $value);
		}

		$settings->save();

		$this->resetEngine();
	}

	/**
	 * Creates a usable encryption key and points the module at it.
	 *
	 * `configure()` turns encryption off, because most of this lane is about something else and a
	 * site with no key cannot assemble the engine at all. A test about what a fully configured site
	 * reports needs the other state.
	 *
	 * @param string $id
	 *   The key entity machine name.
	 */
	protected function encryptWith(string $id = 'strata_functional'): void
	{
		$this->container
			->get('entity_type.manager')
			->getStorage('key')
			->create([
				'id' => $id,
				'label' => $id,
				'key_type' => 'authentication',
				'key_provider' => 'config',
				'key_provider_settings' => ['key_value' => str_repeat('s', 32)],
			])
			->save();

		$this->configure(['cipher.id' => 'xchacha20poly1305', 'key' => $id]);
	}

	/**
	 * Rebuilds the capture scope and the engine after a settings change.
	 *
	 * The engine caches the provider, the cipher and the object store on first use, so a test that
	 * saved a new setting and then flushed would flush through the old one.
	 */
	protected function resetEngine(): void
	{
		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();
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

	/**
	 * Where the store under test lives.
	 *
	 * An absolute path rather than a stream wrapper, because the store is read by the test runner
	 * and written by the web server and both have to resolve it to the same directory.
	 *
	 * @return string
	 *   The absolute path.
	 */
	protected function storePath(): string
	{
		$private = $this->container->get('file_system')->realpath('private://');

		if (!is_string($private) || $private === '') {
			return $this->root . '/' . $this->siteDirectory . '/strata-store';
		}

		return $private . '/strata-store';
	}

	#endregion

	#region Fixtures

	/**
	 * Creates an account holding exactly these permissions and logs it in.
	 *
	 * @param list<string> $permissions
	 *   The permissions to grant, which may be empty for an account that holds none.
	 *
	 * @return UserInterface
	 *   The account, logged in.
	 */
	protected function user(array $permissions = []): UserInterface
	{
		$account = $this->drupalCreateUser($permissions);
		$this->drupalLogin($account);

		return $account;
	}

	/**
	 * Creates a node, and the content type to hold it on first call.
	 *
	 * The body is left to the node creation trait, which fills it with the site's default filter
	 * format. A test that needs a known value changes the title, which needs no format.
	 *
	 * @param string $title
	 *   The title.
	 *
	 * @return NodeInterface
	 *   The saved node.
	 */
	protected function content(string $title): NodeInterface
	{
		if (!$this->contentTypeExists) {
			$this->drupalCreateContentType([
				'type' => self::CONTENT_TYPE,
				'name' => 'Strata Page',
			]);
			$this->contentTypeExists = true;
		}

		return $this->drupalCreateNode(['type' => self::CONTENT_TYPE, 'title' => $title]);
	}

	/**
	 * Seals the current window whether or not a flush bound has been reached.
	 *
	 * A plain `flush()` asks the policy first and is skipped when nothing is due, so a test that
	 * called it would pass or fail on how long the assertions above it took.
	 *
	 * @return FlushResult
	 *   What the flush did.
	 */
	protected function flush(): FlushResult
	{
		return $this->engine()->flusher()->flush(true);
	}

	/**
	 * The engine, as the test runner sees it.
	 *
	 * @return Engine
	 *   The engine.
	 */
	protected function engine(): Engine
	{
		return $this->container->get('strata.engine');
	}

	/**
	 * The id of the newest sealed commit.
	 *
	 * @return string|null
	 *   The commit id, or NULL when nothing has been sealed.
	 */
	protected function head(): ?string
	{
		return $this->engine()->commitLog()->head()?->id();
	}

	#endregion

	#region Routes

	/**
	 * Values for every placeholder any Strata route declares.
	 *
	 * @param string|null $commit
	 *   The commit a route asks for, or NULL for one no store holds.
	 * @param string|null $earlier
	 *   The commit a diff pair compares against, or NULL for one no store holds.
	 *
	 * @return array<string, string>
	 *   Placeholder name keyed to its value.
	 */
	protected function placeholders(?string $commit = null, ?string $earlier = null): array
	{
		return [
			'start' => (string) self::WINDOW_START,
			'resolution' => (string) self::WINDOW_WIDTH,
			'commit' => $commit ?? self::ABSENT_COMMIT,
			'from' => $earlier ?? self::ABSENT_COMMIT,
			'to' => $commit ?? self::ABSENT_COMMIT,
			'code' => self::ABSENT_CODE,
			'branch' => self::ABSENT_BRANCH,
		];
	}

	/**
	 * This module's own directory.
	 *
	 * Derived from where this file sits rather than from the extension list, so it resolves the same
	 * way whatever the checkout is nested inside.
	 *
	 * @return string
	 *   The absolute path.
	 */
	protected function moduleRoot(): string
	{
		return dirname(__DIR__, 3);
	}

	/**
	 * Every route one module declares.
	 *
	 * Read from the YAML rather than from the route provider, so a spec driven by this covers a route
	 * the moment it is added and cannot be satisfied by a route that was never registered.
	 *
	 * @param string $module
	 *   The module name, either this module or one of its submodules.
	 *
	 * @return array<string, array<string, mixed>>
	 *   Route name keyed to its definition.
	 */
	protected function routesOf(string $module): array
	{
		$directory =
			$module === 'strata'
				? $this->moduleRoot()
				: $this->moduleRoot() . '/modules/' . $module;
		$yaml = file_get_contents($directory . '/' . $module . '.routing.yml');

		$this->assertIsString($yaml, sprintf('%s declares a routing file', $module));

		$routes = Yaml::decode($yaml);

		$this->assertIsArray($routes, sprintf('%s.routing.yml decodes to a mapping', $module));

		return $routes;
	}

	/**
	 * One route's path with its placeholders filled in.
	 *
	 * @param array<string, mixed> $route
	 *   The route definition.
	 * @param array<string, string> $parameters
	 *   Placeholder name keyed to the value to use.
	 *
	 * @return string
	 *   The path.
	 */
	protected function pathOf(array $route, array $parameters = []): string
	{
		$path = (string) ($route['path'] ?? '');

		foreach ($parameters as $name => $value) {
			$path = str_replace('{' . $name . '}', $value, $path);
		}

		return $path;
	}

	/**
	 * The permissions one route accepts.
	 *
	 * A `+` in a `_permission` requirement means any one of them is enough, which is what lets a
	 * route be reached by a narrow permission or by "administer strata".
	 *
	 * @param array<string, mixed> $route
	 *   The route definition.
	 *
	 * @return list<string>
	 *   The permissions, empty when the route declares none.
	 */
	protected function permissionsOf(array $route): array
	{
		$requirements = $route['requirements'] ?? [];
		$declared = is_array($requirements) ? (string) ($requirements['_permission'] ?? '') : '';

		if (trim($declared) === '') {
			return [];
		}

		return array_values(array_map('trim', explode('+', $declared)));
	}

	#endregion
}
