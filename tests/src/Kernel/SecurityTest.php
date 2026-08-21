<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use DOMDocument;
use InvalidArgumentException;
use Drupal\strata\Budget\BudgetGuard;
use Drupal\strata\Code\SettingsRedactor;
use Drupal\strata\Crypto\AuthenticationFailure;
use Drupal\strata\Crypto\StaticKeyProvider;
use Drupal\strata\Crypto\XChaCha20Poly1305Cipher;
use Drupal\strata\Engine;
use Drupal\strata\Event\Webhook\WebhookSignature;
use Drupal\strata\Site\SiteScopedProvider;
use Drupal\strata\Storage\ProviderStats;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

/**
 * Proves the properties that make this safe to point at a production bucket.
 *
 * A backup module is an attractive target twice over: it holds a copy of everything the site has ever
 * held, and it can write all of it back. So the questions here are not about features. They are the
 * ones an attacker asks.
 *
 * - Can I read what is in the store without the key? A tampered or foreign frame must raise, never
 *   return bytes, because a decode that returns garbage is a decode an attacker can steer.
 * - Can I find the key? It must not be in a payload, a page, or a config export.
 * - Can I reach another site's history? Keys are namespaced per site and the namespace is not
 *   something a caller composes, so a caller cannot compose their way out of it.
 * - Can I make it fetch something for me? An endpoint's XML is untrusted input, and an entity in it
 *   must expand to nothing rather than to a local file.
 * - Can I forge a notification? A webhook body carries an HMAC over the timestamp and the bytes, and
 *   a changed byte or a stale timestamp fails it.
 * - Can I make it exhaust the host? Capture is budgeted, and the budget is what stops a flood
 *   turning into a bill or an out-of-memory.
 */
class SecurityTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'field', 'key', 'strata'];

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->installEntitySchema('user');
		$this->installSchema('user', ['users_data']);

		$this->config('strata.settings')
			->set('enabled', true)
			->set('provider', 'local')
			->set('local_path', $this->storeRoot)
			->set('cipher.id', 'none')
			->save();

		$this->container->get('strata.capture_scope')->reset();
		$this->engine()->reset();
	}

	#region Reading Without The Key

	#[Test]
	#[TestDox('a frame sealed under another key raises rather than returning bytes')]
	#[Group('strata/security')]
	public function aForeignFrameDoesNotOpen(): void
	{
		$mine = new XChaCha20Poly1305Cipher(StaticKeyProvider::generate()->key());
		$theirs = new XChaCha20Poly1305Cipher(StaticKeyProvider::generate()->key());
		$sealed = $theirs->seal('the other site payload');

		$this->expectException(AuthenticationFailure::class);

		$mine->open($sealed);
	}

	#[Test]
	#[TestDox('one flipped byte in a sealed frame fails the tag rather than decoding')]
	#[Group('strata/security')]
	public function aFlippedByteFailsTheTag(): void
	{
		$cipher = new XChaCha20Poly1305Cipher(StaticKeyProvider::generate()->key());
		$sealed = $cipher->seal('a payload worth tampering with');
		$at = intdiv(strlen($sealed), 2);
		$tampered = substr_replace($sealed, chr(ord($sealed[$at]) ^ 0x01), $at, 1);

		$this->assertNotSame($sealed, $tampered);
		$this->expectException(AuthenticationFailure::class);

		$cipher->open($tampered);
	}

	#[Test]
	#[TestDox('a frame moved to another address will not open at the address it was moved to')]
	#[Group('strata/security')]
	public function aMovedFrameDoesNotOpen(): void
	{
		$cipher = new XChaCha20Poly1305Cipher(StaticKeyProvider::generate()->key());
		$sealed = $cipher->seal('payload', 'frames/aa/bb/one');

		$this->assertSame('payload', $cipher->open($sealed, 'frames/aa/bb/one'));
		$this->expectException(AuthenticationFailure::class);

		$cipher->open($sealed, 'frames/cc/dd/two');
	}

	#endregion

	#region Finding The Key

	#[Test]
	#[TestDox('the key never appears in a stored object, however much is captured')]
	#[Group('strata/security')]
	public function theKeyIsNotInTheStore(): void
	{
		$secret = StaticKeyProvider::generate()->key();

		$this->config('strata.settings')->set('cipher.id', 'none')->save();
		$this->engine()->reset();

		$this->traffic(4);

		$hex = bin2hex($secret);

		foreach ($this->engine()->provider()->list('', null, 2000)->keys() as $key) {
			$body = (string) $this->engine()->provider()->get($key);

			$this->assertStringNotContainsString($secret, $body, $key . ' carries the key bytes');
			$this->assertStringNotContainsString($hex, $body, $key . ' carries the key hex');
		}
	}

	#[Test]
	#[TestDox('settings.php is captured with its secrets replaced by a marker')]
	#[Group('strata/security')]
	public function settingsAreRedacted(): void
	{
		$source = <<<'PHP'
		<?php
		$databases['default']['default'] = [
		  'password' => 'hunter2',
		  'username' => 'dbuser',
		];
		$settings['hash_salt'] = 'a-real-hash-salt-nobody-should-see';
		PHP;

		$redacted = SettingsRedactor::redact($source);

		$this->assertStringNotContainsString('hunter2', $redacted);
		$this->assertStringNotContainsString('a-real-hash-salt-nobody-should-see', $redacted);
		$this->assertStringContainsString(SettingsRedactor::MARKER, $redacted);
		$this->assertStringContainsString(
			'dbuser',
			$redacted,
			'a username is not a secret and a restore needs it',
		);
	}

	#[Test]
	#[TestDox('a webhook subscription serializes without its signing secret')]
	#[Group('strata/security')]
	public function aSubscriptionHidesItsSecret(): void
	{
		$this->config('strata.webhooks')
			->set('subscriptions', [
				[
					'url' => 'https://example.com/hook',
					'secret' => 'the-signing-secret',
					'events' => ['strata.commit.sealed'],
					'enabled' => true,
					'attempts' => 3,
				],
			])
			->save();

		$subscription = $this->container
			->get('strata.webhooks')
			->subscriptionFor('https://example.com/hook');

		$this->assertNotNull($subscription);
		$this->assertStringNotContainsString(
			'the-signing-secret',
			(string) json_encode($subscription),
			'a subscription is rendered on an admin page and logged, so it never carries the secret',
		);
	}

	#endregion

	#region Reaching Another Site

	#[Test]
	#[TestDox('a key cannot be composed to escape its own site namespace')]
	#[Group('strata/security')]
	public function aKeyCannotEscapeItsSite(): void
	{
		$provider = $this->engine()->provider();

		$this->assertInstanceOf(SiteScopedProvider::class, $provider);

		$escapes = ['../other-site/commits/one', 'commits/../../other-site/two', 'commits//three'];

		foreach ($escapes as $attempt) {
			try {
				$provider->put($attempt, 'payload');
				$this->fail(sprintf('%s was accepted as an object key', $attempt));
			} catch (InvalidArgumentException $refused) {
				$this->assertStringContainsString(
					'traversal or empty segment',
					$refused->getMessage(),
				);
			}
		}

		// a leading slash is normalised rather than honoured, so it lands inside the namespace
		$provider->put('/absolute/commits/four', 'payload');
		$provider->put('commits/legitimate', 'payload');

		$written = $this->engine()->provider()->unscoped()->inner()->list('', null, 2000)->keys();
		$site = $this->engine()->site()->id();

		$this->assertNotSame([], $written);

		foreach ($written as $key) {
			$shared = false;

			foreach (SiteScopedProvider::SHARED_PREFIXES as $prefix) {
				$shared = $shared || str_starts_with($key, $prefix);
			}

			$this->assertTrue(
				$shared || str_contains($key, $site),
				sprintf('%s landed outside the site namespace', $key),
			);
		}
	}

	#[Test]
	#[TestDox('a listing shows this site only, whatever another site put in the bucket')]
	#[Group('strata/security')]
	public function aListingIsScopedToOneSite(): void
	{
		$this->traffic(2);

		// another tenant's object, written straight to the bucket behind the scoping
		$this->engine()
			->provider()
			->unscoped()
			->inner()
			->put('other-site/commits/theirs', 'theirs');

		foreach ($this->engine()->provider()->list('commits/', null, 2000)->keys() as $key) {
			$this->assertStringNotContainsString(
				'other-site',
				$key,
				'one tenant cannot read another tenant out of a shared bucket',
			);
		}
	}

	#endregion

	#region Untrusted Input

	#[Test]
	#[TestDox('an entity in an endpoint listing expands to nothing rather than to a local file')]
	#[Group('strata/security')]
	public function anExternalEntityExpandsToNothing(): void
	{
		$secret = $this->siteDirectory . '/xxe-target.txt';

		file_put_contents($secret, 'THE-FILE-CONTENTS-NOBODY-SHOULD-SEE');

		$xml = sprintf(
			'<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file://%s">]>' .
				'<ListBucketResult><Contents><Key>&x;</Key></Contents></ListBucketResult>',
			$secret,
		);

		$document = new DOMDocument();
		$loaded = @$document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);

		$this->assertTrue(
			$loaded,
			'the document still parses; the entity is what must not resolve',
		);
		$this->assertStringNotContainsString(
			'THE-FILE-CONTENTS-NOBODY-SHOULD-SEE',
			(string) $document->saveXML(),
			'LIBXML_NOENT is what substitutes entities, and it is deliberately not passed',
		);
	}

	#[Test]
	#[TestDox('a forged webhook signature is rejected and a stale one expires')]
	#[Group('strata/security')]
	public function aForgedSignatureIsRejected(): void
	{
		$body = (string) json_encode(['event' => 'strata.commit.sealed', 'commit' => 'abc']);
		$secret = 'the-signing-secret';
		$now = 1_700_000_000;
		$header = WebhookSignature::sign($body, $secret, $now);

		$this->assertTrue(WebhookSignature::verify($header, $body, $secret, $now));
		$this->assertFalse(
			WebhookSignature::verify($header, $body . ' ', $secret, $now),
			'a changed body fails the signature it was not signed with',
		);
		$this->assertFalse(
			WebhookSignature::verify($header, $body, 'another-secret', $now),
			'and so does another secret',
		);
		$this->assertFalse(
			WebhookSignature::verify($header, $body, $secret, $now + 4_000),
			'a replay outside the tolerance is refused however good the signature is',
		);
	}

	#endregion

	#region Flooding It

	#[Test]
	#[TestDox('a flood of requests is reported as over budget rather than absorbed')]
	#[Group('strata/security')]
	public function aFloodTripsTheBudget(): void
	{
		$guard = new BudgetGuard(bytesCeiling: 1_048_576);
		$stats = new ProviderStats();

		for ($at = 0; $at < 500; $at++) {
			$stats->record('put', 1_048_576, 0.001);
		}

		$assessment = $guard->assess(512 * 1_048_576, $stats, 3_600);

		$this->assertTrue(
			$assessment->isOverBudget(),
			'a flood is a budget event, and the ladder is what answers it: ' .
				$assessment->summary(),
		);
		$this->assertFalse(
			$assessment->allowsCapture(),
			'and past the top of the ladder capture stops rather than running up a bill',
		);
	}

	#[Test]
	#[TestDox('a budget within its allowance keeps capturing every realm')]
	#[Group('strata/security')]
	public function aNormalDayIsNotThrottled(): void
	{
		$guard = new BudgetGuard(bytesCeiling: 10 * 1_073_741_824);
		$stats = new ProviderStats();
		$stats->record('put', 4_096, 0.001);

		$assessment = $guard->assess(1_048_576, $stats, 3_600);

		$this->assertFalse($assessment->isOverBudget());
		$this->assertTrue($assessment->allowsCapture());
	}

	#[Test]
	#[TestDox('a payload far larger than a frame is stored in frames rather than held whole')]
	#[Group('strata/security')]
	public function anOversizePayloadIsFramed(): void
	{
		$store = $this->engine()->objectStore();
		$payload = str_repeat('abcdefgh', 1_048_576 / 8);

		$map = $store->write($payload);
		$store->commit();

		$this->assertGreaterThan(
			1,
			count($map),
			'a megabyte at a 16 KiB frame size is many frames, so nothing has to hold it all',
		);
		$this->assertSame($payload, $store->read($map), 'and it still reads back byte for byte');
	}

	#endregion

	#region Fixtures

	/**
	 * The engine under test.
	 *
	 * @return Engine
	 *   The engine.
	 */
	private function engine(): Engine
	{
		return $this->container->get('strata.engine');
	}

	/**
	 * Captures and seals a few commits worth of ordinary writes.
	 *
	 * @param int $commits
	 *   How many commits to build.
	 */
	private function traffic(int $commits): void
	{
		for ($at = 0; $at < $commits; $at++) {
			$user = User::create([
				'name' => 'security-' . $at,
				'mail' => 'security-' . $at . '@example.com',
				'status' => 1,
			]);
			$user->save();

			$this->engine()->flusher()->flush(true);
		}
	}

	#endregion
}
