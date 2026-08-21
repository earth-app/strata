<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Site;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\strata\Site\SiteContext;
use Drupal\strata\Site\SiteScopedProvider;
use Drupal\strata\Storage\Plugin\Strata\Storage\LocalStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(SiteContext::class)]
#[CoversClass(SiteScopedProvider::class)]
class SiteTest extends TestCase
{
	#region Fixtures

	/**
	 * Roots created during a test, removed afterwards.
	 *
	 * @var list<string>
	 */
	private array $roots = [];

	/**
	 * {@inheritdoc}
	 */
	protected function tearDown(): void
	{
		foreach ($this->roots as $root) {
			$this->removeTree($root);
		}

		$this->roots = [];

		parent::tearDown();
	}

	/**
	 * A context over the given configured id and connection identity.
	 *
	 * SiteContext is final and reads the real config factory, so the context under test is the real
	 * one over stubbed settings.
	 *
	 * @param string $configured
	 *   The configured site id, or an empty string to have one derived.
	 * @param array<string, string> $options
	 *   Connection options the id is derived from.
	 */
	private function context(string $configured = '', array $options = []): SiteContext
	{
		$config = $this->createMock(ImmutableConfig::class);
		$config->method('get')->willReturn($configured);

		$factory = $this->createMock(ConfigFactoryInterface::class);
		$factory->method('get')->willReturn($config);

		$database = $this->createMock(Connection::class);
		$database
			->method('getConnectionOptions')
			->willReturn(
				$options === []
					? ['database' => 'drupal', 'prefix' => '', 'host' => 'localhost']
					: $options,
			);

		return new SiteContext($factory, $database);
	}

	/**
	 * A local store under a throwaway root.
	 */
	private function local(): LocalStorage
	{
		$root = sys_get_temp_dir() . '/strata-site-' . bin2hex(random_bytes(8));
		$this->roots[] = $root;

		return new LocalStorage($root);
	}

	/**
	 * A provider scoped to one site over a local store.
	 *
	 * @return array{SiteScopedProvider, LocalStorage}
	 *   The wrapper and the store it wraps, so a test can read the real key.
	 */
	private function scoped(string $id = 'alpha'): array
	{
		$inner = $this->local();

		return [new SiteScopedProvider($inner, $this->context($id)), $inner];
	}

	private function removeTree(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}

		foreach (scandir($path) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$child = $path . '/' . $entry;
			is_dir($child) ? $this->removeTree($child) : @unlink($child);
		}

		@rmdir($path);
	}

	#endregion

	#region Site Identity

	#[Test]
	#[TestDox('a configured id is used as it stands')]
	#[Group('strata/site')]
	public function configuredIdIsUsed(): void
	{
		$context = $this->context('earth-app');

		$this->assertSame('earth-app', $context->id());
		$this->assertTrue($context->isExplicit());
		$this->assertSame('earth-app/', $context->prefix());
	}

	#[Test]
	#[TestDox('an id nobody configured is derived from the database, not from a domain')]
	#[Group('strata/site')]
	public function derivedIdComesFromTheConnection(): void
	{
		$first = $this->context('', ['database' => 'site_one', 'prefix' => '', 'host' => 'db']);
		$second = $this->context('', ['database' => 'site_two', 'prefix' => '', 'host' => 'db']);

		$this->assertFalse($first->isExplicit());
		$this->assertSame(SiteContext::ID_LENGTH, strlen($first->id()));
		$this->assertNotSame($first->id(), $second->id(), 'two databases are two sites');
	}

	#[Test]
	#[TestDox('two sites sharing a database are told apart by their table prefix')]
	#[Group('strata/site')]
	public function prefixDistinguishesSharedDatabases(): void
	{
		$first = $this->context('', ['database' => 'shared', 'prefix' => 'one_', 'host' => 'db']);
		$second = $this->context('', ['database' => 'shared', 'prefix' => 'two_', 'host' => 'db']);

		$this->assertNotSame($first->id(), $second->id());
	}

	#[Test]
	#[TestDox('a derived id is stable, so a second look does not start a second history')]
	#[Group('strata/site')]
	public function derivedIdIsStable(): void
	{
		$options = ['database' => 'drupal', 'prefix' => 'p_', 'host' => 'db'];
		$context = $this->context('', $options);
		$id = $context->id();

		$this->assertSame($id, $context->id());
		$this->assertSame(
			$id,
			$this->context('', $options)->id(),
			'the same identity derives the same id',
		);
	}

	#[Test]
	#[TestDox('a reset forgets the resolved id so a settings change is picked up')]
	#[Group('strata/site')]
	public function resetForgetsTheId(): void
	{
		$context = $this->context('alpha');

		$this->assertSame('alpha', $context->id());

		$context->reset();

		$this->assertSame('alpha', $context->id());
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function idProvider(): array
	{
		return [
			'a plain id' => ['earth-app', 'earth-app'],
			'surrounding space' => ['  alpha  ', 'alpha'],
			'a slash' => ['alpha/beta', 'alphabeta'],
			'a traversal' => ['../../other', 'other'],
			'a null byte' => ["alpha\0beta", 'alphabeta'],
			'unicode' => ['site-\u{00e9}', 'site-u00e9'],
			'nothing usable' => ['///', ''],
		];
	}

	#[Test]
	#[TestDox('$_dataName is reduced to something safe in a key')]
	#[Group('strata/site')]
	#[DataProvider('idProvider')]
	public function idsAreNormalised(string $configured, string $expected): void
	{
		$this->assertSame($expected, SiteContext::normalise($configured));
	}

	#[Test]
	#[TestDox('an id that normalises to nothing falls back to a derived one')]
	#[Group('strata/site')]
	public function unusableIdIsDerived(): void
	{
		$context = $this->context('///');

		$this->assertSame(SiteContext::ID_LENGTH, strlen($context->id()));
		$this->assertFalse($context->isExplicit());
	}

	#[Test]
	#[TestDox('a key belonging to another site strips to nothing rather than to itself')]
	#[Group('strata/site')]
	public function foreignKeysDoNotStrip(): void
	{
		$context = $this->context('alpha');

		$this->assertSame('alpha/commits/aa', $context->key('commits/aa'));
		$this->assertSame('alpha/commits/aa', $context->key('/commits/aa'));
		$this->assertSame('commits/aa', $context->strip('alpha/commits/aa'));
		$this->assertNull($context->strip('beta/commits/aa'));
		$this->assertNull($context->strip('commits/aa'));
	}

	#endregion

	#region Scoped Storage

	/**
	 * @return array<string, array{string}>
	 */
	public static function sharedKeyProvider(): array
	{
		return [
			'a frame' => ['frames/aa/bb/cc'],
			'a pack' => ['packs/one.pack'],
			'a media block' => ['media/aa/bb/cc'],
			'a dictionary' => ['dicts/entity/1.zdict'],
		];
	}

	#[Test]
	#[TestDox('$_dataName is addressed by its content, so it is shared rather than namespaced')]
	#[Group('strata/site')]
	#[DataProvider('sharedKeyProvider')]
	public function contentIsShared(string $key): void
	{
		[$provider, $inner] = $this->scoped();

		$this->assertTrue(SiteScopedProvider::isShared($key));

		$provider->put($key, 'bytes');

		$this->assertTrue($inner->exists($key), 'the key reached the store unprefixed');
		$this->assertSame('bytes', $provider->get($key));
	}

	#[Test]
	#[TestDox('everything describing a site is written under that site')]
	#[Group('strata/site')]
	public function historyIsNamespaced(): void
	{
		[$provider, $inner] = $this->scoped('alpha');

		$provider->put('commits/aa/bb/cc', 'commit');

		$this->assertFalse(SiteScopedProvider::isShared('commits/aa/bb/cc'));
		$this->assertTrue($inner->exists('alpha/commits/aa/bb/cc'));
		$this->assertFalse($inner->exists('commits/aa/bb/cc'));
		$this->assertSame('commit', $provider->get('commits/aa/bb/cc'));
	}

	#[Test]
	#[TestDox('one site cannot read another site\'s commit through its own key')]
	#[Group('strata/site')]
	public function sitesCannotReadEachOther(): void
	{
		$inner = $this->local();
		$alpha = new SiteScopedProvider($inner, $this->context('alpha'));
		$beta = new SiteScopedProvider($inner, $this->context('beta'));

		$alpha->put('refs/heads/main', 'alpha-tip');
		$beta->put('refs/heads/main', 'beta-tip');

		$this->assertSame('alpha-tip', $alpha->get('refs/heads/main'));
		$this->assertSame('beta-tip', $beta->get('refs/heads/main'));
		$this->assertTrue($inner->exists('alpha/refs/heads/main'));
		$this->assertTrue($inner->exists('beta/refs/heads/main'));
	}

	#[Test]
	#[TestDox('a listing returns this site\'s keys as the caller composed them')]
	#[Group('strata/site')]
	public function listingUnscopesItsKeys(): void
	{
		$inner = $this->local();
		$alpha = new SiteScopedProvider($inner, $this->context('alpha'));
		$beta = new SiteScopedProvider($inner, $this->context('beta'));

		$alpha->put('commits/one', 'a');
		$alpha->put('commits/two', 'b');
		$beta->put('commits/three', 'c');

		$keys = array_column($alpha->list('commits/')->objects, 'key');

		sort($keys);

		$this->assertSame(['commits/one', 'commits/two'], $keys);
	}

	#[Test]
	#[TestDox('a shared listing is not confined, since frames belong to the bucket')]
	#[Group('strata/site')]
	public function sharedListingIsNotConfined(): void
	{
		$inner = $this->local();
		$alpha = new SiteScopedProvider($inner, $this->context('alpha'));
		$beta = new SiteScopedProvider($inner, $this->context('beta'));

		$alpha->put('frames/aa/one', 'a');
		$beta->put('frames/aa/two', 'b');

		$keys = array_column($alpha->list('frames/')->objects, 'key');

		sort($keys);

		$this->assertSame(['frames/aa/one', 'frames/aa/two'], $keys);
	}

	#[Test]
	#[TestDox('a head reports the key the caller asked about, not the scoped one')]
	#[Group('strata/site')]
	public function headReportsTheCallersKey(): void
	{
		[$provider] = $this->scoped('alpha');

		$provider->put('commits/aa', 'commit');
		$meta = $provider->head('commits/aa');

		$this->assertNotNull($meta);
		$this->assertSame('commits/aa', $meta->key);
		$this->assertSame(6, $meta->size);
		$this->assertNull($provider->head('commits/missing'));
	}

	#[Test]
	#[TestDox('a delete removes this site\'s object and leaves the other site\'s alone')]
	#[Group('strata/site')]
	public function deleteIsConfined(): void
	{
		$inner = $this->local();
		$alpha = new SiteScopedProvider($inner, $this->context('alpha'));
		$beta = new SiteScopedProvider($inner, $this->context('beta'));

		$alpha->put('commits/aa', 'a');
		$beta->put('commits/aa', 'b');

		$this->assertSame(1, $alpha->delete(['commits/aa']));
		$this->assertFalse($alpha->exists('commits/aa'));
		$this->assertTrue($beta->exists('commits/aa'));
	}

	#[Test]
	#[TestDox('the wrapper reports the identity of what it wraps')]
	#[Group('strata/site')]
	public function wrapperDelegatesIdentity(): void
	{
		[$provider, $inner] = $this->scoped();

		$this->assertSame($inner->id(), $provider->id());
		$this->assertSame($inner->label(), $provider->label());
		$this->assertSame($inner->isReachable(), $provider->isReachable());
		$this->assertSame($inner->unreachableReason(), $provider->unreachableReason());
		$this->assertSame(
			$inner->capabilities()->maxSinglePut,
			$provider->capabilities()->maxSinglePut,
		);
		$this->assertSame($inner, $provider->unscoped());
	}

	#[Test]
	#[TestDox('a stream reads this site\'s object')]
	#[Group('strata/site')]
	public function streamIsScoped(): void
	{
		[$provider] = $this->scoped('alpha');

		$provider->put('commits/aa', 'streamed');
		$handle = $provider->stream('commits/aa');

		$this->assertSame('streamed', (string) stream_get_contents($handle));

		fclose($handle);
	}

	#endregion
}
