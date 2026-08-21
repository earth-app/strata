<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Event;

use Drupal\strata\Event\StrataEvents;
use Drupal\strata\Event\Webhook\WebhookSignature;
use Drupal\strata\Event\Webhook\WebhookSubscription;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Proves the webhook value layer: what a signature covers, and what a subscription accepts.
 *
 * The signature lane covers the two properties the class docblock claims. The timestamp is inside
 * the signed material and the age is checked, so a captured delivery cannot be replayed later. The
 * separator is inside the signed material too, so a body that starts with digits cannot be traded
 * against the timestamp for the same digest.
 *
 * The subscription lane covers what configuration is allowed to say. An event name this release
 * does not dispatch is dropped, the empty list is the every-event shape, and the secret never
 * reaches the serialized form.
 */
#[CoversClass(WebhookSignature::class)]
#[CoversClass(WebhookSubscription::class)]
#[CoversClass(StrataEvents::class)]
class WebhookTest extends TestCase
{
	/**
	 * A secret every signature case shares.
	 */
	private const SECRET = 'shhh-a-shared-secret';

	#region Signing

	#[Test]
	#[TestDox('sign() produces a t= and v1= header value')]
	#[Group('strata/webhook')]
	public function signProducesTheHeaderShape(): void
	{
		$header = WebhookSignature::sign('{"a":1}', self::SECRET, 1_700_000_000);

		$this->assertMatchesRegularExpression('/^t=\d+,v1=[0-9a-f]{64}$/', $header);
		$this->assertStringStartsWith('t=1700000000,', $header);
	}

	#[Test]
	#[TestDox('sign() is deterministic for the same body, secret and timestamp')]
	#[Group('strata/webhook')]
	public function signIsDeterministic(): void
	{
		$this->assertSame(
			WebhookSignature::sign('body', self::SECRET, 10),
			WebhookSignature::sign('body', self::SECRET, 10),
		);
	}

	#[Test]
	#[TestDox('a signature the sender produced verifies at the receiver')]
	#[Group('strata/webhook')]
	public function verifyAcceptsAGenuineSignature(): void
	{
		$body = '{"event":"strata.commit.sealed"}';
		$header = WebhookSignature::sign($body, self::SECRET, 1_700_000_000);

		$this->assertTrue(WebhookSignature::verify($header, $body, self::SECRET, 1_700_000_000));
	}

	#[Test]
	#[TestDox('a tampered body does not verify')]
	#[Group('strata/webhook')]
	public function verifyRejectsATamperedBody(): void
	{
		$header = WebhookSignature::sign('{"amount":1}', self::SECRET, 500);

		$this->assertFalse(WebhookSignature::verify($header, '{"amount":2}', self::SECRET, 500));
	}

	#[Test]
	#[TestDox('a tampered timestamp does not verify')]
	#[Group('strata/webhook')]
	public function verifyRejectsATamperedTimestamp(): void
	{
		$header = WebhookSignature::sign('body', self::SECRET, 500);
		$moved = str_replace('t=500,', 't=501,', $header);

		$this->assertNotSame($header, $moved);
		$this->assertFalse(WebhookSignature::verify($moved, 'body', self::SECRET, 500));
	}

	#[Test]
	#[TestDox('the wrong secret does not verify')]
	#[Group('strata/webhook')]
	public function verifyRejectsTheWrongSecret(): void
	{
		$header = WebhookSignature::sign('body', self::SECRET, 500);

		$this->assertFalse(WebhookSignature::verify($header, 'body', 'other-secret', 500));
	}

	#endregion

	#region Replay window

	#[Test]
	#[TestDox('a signature older than the tolerance is refused, which is the replay guard')]
	#[Group('strata/webhook')]
	public function verifyRefusesAStaleSignature(): void
	{
		$now = 1_700_000_000;
		$header = WebhookSignature::sign('body', self::SECRET, $now - 400);

		$this->assertFalse(WebhookSignature::verify($header, 'body', self::SECRET, $now));
		$this->assertTrue(WebhookSignature::verify($header, 'body', self::SECRET, $now, 600));
	}

	/**
	 * @return array<string, array{int, bool}>
	 */
	public static function ageProvider(): array
	{
		return [
			'no age at all' => [0, true],
			'one second inside the window' => [WebhookSignature::DEFAULT_TOLERANCE - 1, true],
			'exactly at the window' => [WebhookSignature::DEFAULT_TOLERANCE, true],
			'one second past the window' => [WebhookSignature::DEFAULT_TOLERANCE + 1, false],
			'far past the window' => [WebhookSignature::DEFAULT_TOLERANCE * 100, false],
		];
	}

	#[Test]
	#[TestDox('a signature $_dataName is accepted or refused on age alone')]
	#[Group('strata/webhook')]
	#[DataProvider('ageProvider')]
	public function verifyBoundsTheAge(int $age, bool $expected): void
	{
		$now = 1_700_000_000;
		$header = WebhookSignature::sign('body', self::SECRET, $now - $age);

		$this->assertSame($expected, WebhookSignature::verify($header, 'body', self::SECRET, $now));
	}

	#[Test]
	#[TestDox('a signature from the future is bounded too, so a bad sender clock cannot widen it')]
	#[Group('strata/webhook')]
	public function verifyBoundsTheFuture(): void
	{
		$now = 1_700_000_000;
		$header = WebhookSignature::sign('body', self::SECRET, $now + 400);

		$this->assertFalse(WebhookSignature::verify($header, 'body', self::SECRET, $now));
	}

	#endregion

	#region Malformed headers

	/**
	 * @return array<string, array{string}>
	 */
	public static function malformedHeaderProvider(): array
	{
		$good = WebhookSignature::sign('body', self::SECRET, 500);

		return [
			'empty' => [''],
			'no timestamp' => [substr($good, (int) strpos($good, ',') + 1)],
			'no digest' => ['t=500'],
			'a non-numeric timestamp' => ['t=abc,' . substr($good, (int) strpos($good, ',') + 1)],
			'a later scheme version' => [str_replace('v1=', 'v2=', $good)],
			'no separators at all' => ['garbage'],
			'only separators' => [',,,'],
			'a key with no value' => ['t,v1'],
			'the digest alone' => [substr($good, (int) strpos($good, 'v1=') + 3)],
		];
	}

	#[Test]
	#[TestDox('a header with $_dataName is refused')]
	#[Group('strata/webhook')]
	#[DataProvider('malformedHeaderProvider')]
	public function verifyRefusesMalformedHeader(string $header): void
	{
		$this->assertFalse(WebhookSignature::verify($header, 'body', self::SECRET, 500));
	}

	#[Test]
	#[TestDox('a digest differing only in its last character is refused')]
	#[Group('strata/webhook')]
	public function verifyRefusesANearMissDigest(): void
	{
		$header = WebhookSignature::sign('body', self::SECRET, 500);
		$last = substr($header, -1);
		$near = substr($header, 0, -1) . ($last === '0' ? '1' : '0');

		$this->assertNotSame($header, $near);
		$this->assertSame(strlen($header), strlen($near));
		$this->assertFalse(WebhookSignature::verify($near, 'body', self::SECRET, 500));
	}

	#[Test]
	#[TestDox('a truncated digest is refused rather than matching on its prefix')]
	#[Group('strata/webhook')]
	public function verifyRefusesATruncatedDigest(): void
	{
		$header = WebhookSignature::sign('body', self::SECRET, 500);

		$this->assertFalse(
			WebhookSignature::verify(substr($header, 0, -8), 'body', self::SECRET, 500),
		);
	}

	#endregion

	#region Separator

	#[Test]
	#[TestDox('the separator is signed, so a body cannot be traded against the timestamp')]
	#[Group('strata/webhook')]
	public function separatorPreventsConcatenationAmbiguity(): void
	{
		// without the '.' both cases would hash the bytes "15x"
		$first = WebhookSignature::sign('5.x', self::SECRET, 1);
		$second = WebhookSignature::sign('x', self::SECRET, 15);

		$this->assertNotSame(
			substr($first, (int) strpos($first, 'v1=')),
			substr($second, (int) strpos($second, 'v1=')),
		);
		$this->assertFalse(WebhookSignature::verify($first, 'x', self::SECRET, 1));
		$this->assertFalse(WebhookSignature::verify($second, '5.x', self::SECRET, 15));
	}

	#[Test]
	#[TestDox('the header names are stable, since a receiver hard-codes them')]
	#[Group('strata/webhook')]
	public function headerNamesAreStable(): void
	{
		$this->assertSame('X-Strata-Signature', WebhookSignature::HEADER);
		$this->assertSame('X-Strata-Event', WebhookSignature::EVENT_HEADER);
		$this->assertSame('X-Strata-Delivery', WebhookSignature::DELIVERY_HEADER);
		$this->assertSame('v1', WebhookSignature::VERSION);
		$this->assertSame(300, WebhookSignature::DEFAULT_TOLERANCE);
	}

	#endregion

	#region Subscription filtering

	#[Test]
	#[TestDox('fromArray() drops an event name this release does not dispatch')]
	#[Group('strata/webhook')]
	public function fromArrayDropsUnknownEvents(): void
	{
		$subscription = WebhookSubscription::fromArray([
			'url' => 'https://example.com/hook',
			'events' => [
				StrataEvents::COMMIT_SEALED,
				'strata.something.invented',
				StrataEvents::PRUNE_APPLIED,
			],
		]);

		$this->assertSame(
			[StrataEvents::COMMIT_SEALED, StrataEvents::PRUNE_APPLIED],
			$subscription->events,
		);
		$this->assertTrue($subscription->wants(StrataEvents::COMMIT_SEALED));
		$this->assertFalse($subscription->wants(StrataEvents::HEALTH_FINDING));
	}

	#[Test]
	#[TestDox('an event list that filters down to nothing means every event')]
	#[Group('strata/webhook')]
	public function emptyListMeansEveryEvent(): void
	{
		$subscription = WebhookSubscription::fromArray([
			'url' => 'https://example.com/hook',
			'events' => ['strata.only.invented.names'],
		]);

		$this->assertSame([], $subscription->events);

		foreach (StrataEvents::all() as $name) {
			$this->assertTrue($subscription->wants($name), $name);
		}
	}

	#[Test]
	#[TestDox('an absent event list means every event')]
	#[Group('strata/webhook')]
	public function absentListMeansEveryEvent(): void
	{
		$subscription = WebhookSubscription::fromArray(['url' => 'https://example.com/hook']);

		$this->assertSame([], $subscription->events);
		$this->assertTrue($subscription->wants(StrataEvents::DRILL_FINISHED));
	}

	#[Test]
	#[TestDox('a non-list events value is treated as no list at all')]
	#[Group('strata/webhook')]
	public function scalarEventsValueIsIgnored(): void
	{
		$subscription = WebhookSubscription::fromArray([
			'url' => 'https://example.com/hook',
			'events' => StrataEvents::COMMIT_SEALED,
		]);

		$this->assertSame([], $subscription->events);
	}

	#[Test]
	#[TestDox('a disabled subscription wants nothing, list or no list')]
	#[Group('strata/webhook')]
	public function disabledWantsNothing(): void
	{
		$empty = WebhookSubscription::fromArray([
			'url' => 'https://example.com/hook',
			'enabled' => false,
		]);
		$listed = WebhookSubscription::fromArray([
			'url' => 'https://example.com/hook',
			'enabled' => false,
			'events' => [StrataEvents::COMMIT_SEALED],
		]);

		foreach (StrataEvents::all() as $name) {
			$this->assertFalse($empty->wants($name), $name);
			$this->assertFalse($listed->wants($name), $name);
		}
	}

	#endregion

	#region Subscription sequences

	#[Test]
	#[TestDox('fromSequence() skips an entry with no url and a non-array entry')]
	#[Group('strata/webhook')]
	public function fromSequenceSkipsUnusableEntries(): void
	{
		/** @var list<array<string, mixed>> $sequence */
		$sequence = [
			['url' => 'https://one.example/hook'],
			['url' => ''],
			['url' => '   '],
			['secret' => 'no-url-here'],
			'not an array at all',
			42,
			null,
			['url' => 'https://two.example/hook'],
		];

		$subscriptions = WebhookSubscription::fromSequence($sequence);

		$this->assertCount(2, $subscriptions);
		$this->assertSame('https://one.example/hook', $subscriptions[0]->url);
		$this->assertSame('https://two.example/hook', $subscriptions[1]->url);
	}

	#[Test]
	#[TestDox('fromSequence() of nothing configured is an empty list')]
	#[Group('strata/webhook')]
	public function fromSequenceOfNull(): void
	{
		$this->assertSame([], WebhookSubscription::fromSequence(null));
		$this->assertSame([], WebhookSubscription::fromSequence([]));
	}

	#[Test]
	#[
		TestDox(
			'a url is trimmed, so trailing whitespace in configuration is not a distinct endpoint',
		),
	]
	#[Group('strata/webhook')]
	public function urlIsTrimmed(): void
	{
		$subscription = WebhookSubscription::fromArray(['url' => "  https://example.com/hook\n"]);

		$this->assertSame('https://example.com/hook', $subscription->url);
	}

	#endregion

	#region Subscription clamping

	/**
	 * @return array<string, array{mixed, int}>
	 */
	public static function timeoutProvider(): array
	{
		return [
			'zero' => [0, 1],
			'negative' => [-30, 1],
			'one' => [1, 1],
			'a real value' => [45, 45],
			'a numeric string' => ['20', 20],
			'garbage' => ['soon', 1],
		];
	}

	#[Test]
	#[TestDox('a timeout of $_dataName clamps to at least one second')]
	#[Group('strata/webhook')]
	#[DataProvider('timeoutProvider')]
	public function timeoutClampsToOne(mixed $given, int $expected): void
	{
		$subscription = WebhookSubscription::fromArray([
			'url' => 'https://example.com/hook',
			'timeout' => $given,
		]);

		$this->assertSame($expected, $subscription->timeout);
	}

	#[Test]
	#[TestDox('attempts clamp to at least one, so a delivery is always tried once')]
	#[Group('strata/webhook')]
	public function attemptsClampToOne(): void
	{
		foreach ([0, -1, -100] as $given) {
			$subscription = WebhookSubscription::fromArray([
				'url' => 'https://example.com/hook',
				'attempts' => $given,
			]);

			$this->assertSame(1, $subscription->attempts, (string) $given);
		}

		$this->assertSame(
			7,
			WebhookSubscription::fromArray([
				'url' => 'https://example.com/hook',
				'attempts' => 7,
			])->attempts,
		);
	}

	#[Test]
	#[TestDox('the defaults are what an entry with only a url gets')]
	#[Group('strata/webhook')]
	public function defaultsApplyToABareEntry(): void
	{
		$subscription = WebhookSubscription::fromArray(['url' => 'https://example.com/hook']);

		$this->assertSame(WebhookSubscription::DEFAULT_TIMEOUT, $subscription->timeout);
		$this->assertSame(WebhookSubscription::DEFAULT_ATTEMPTS, $subscription->attempts);
		$this->assertTrue($subscription->enabled);
		$this->assertSame('', $subscription->secret);
	}

	#endregion

	#region Subscription presentation

	#[Test]
	#[TestDox('isSigned() is true only when a secret is set')]
	#[Group('strata/webhook')]
	public function isSignedFollowsTheSecret(): void
	{
		$this->assertTrue((new WebhookSubscription('https://e.example/h', 'sec'))->isSigned());
		$this->assertFalse((new WebhookSubscription('https://e.example/h'))->isSigned());
		$this->assertFalse((new WebhookSubscription('https://e.example/h', ''))->isSigned());
	}

	#[Test]
	#[TestDox('label() names the host and what the endpoint is subscribed to')]
	#[Group('strata/webhook')]
	public function labelNamesHostAndEvents(): void
	{
		$every = new WebhookSubscription('https://hooks.example.com/strata');

		$this->assertSame('hooks.example.com (every event)', $every->label());

		$some = new WebhookSubscription('https://hooks.example.com/strata', '', [
			StrataEvents::COMMIT_SEALED,
			StrataEvents::PRUNE_APPLIED,
		]);

		$this->assertSame(
			'hooks.example.com (strata.commit.sealed, strata.prune.applied)',
			$some->label(),
		);
	}

	#[Test]
	#[TestDox('label() falls back to the whole url when there is no host to name')]
	#[Group('strata/webhook')]
	public function labelFallsBackToTheUrl(): void
	{
		$this->assertSame(
			'/local/path (every event)',
			(new WebhookSubscription('/local/path'))->label(),
		);
	}

	#[Test]
	#[TestDox('jsonSerialize() reports that the endpoint is signed without emitting the secret')]
	#[Group('strata/webhook')]
	public function jsonSerializeNeverEmitsTheSecret(): void
	{
		$subscription = new WebhookSubscription(
			'https://hooks.example.com/strata',
			'top-secret-value',
			[StrataEvents::COMMIT_SEALED],
			true,
			15,
			5,
		);
		$serialized = $subscription->jsonSerialize();

		$this->assertSame(
			[
				'url' => 'https://hooks.example.com/strata',
				'events' => [StrataEvents::COMMIT_SEALED],
				'enabled' => true,
				'timeout' => 15,
				'attempts' => 5,
				'signed' => true,
			],
			$serialized,
		);
		$this->assertArrayNotHasKey('secret', $serialized);
		$this->assertStringNotContainsString(
			'top-secret-value',
			(string) json_encode($subscription),
		);
	}

	#endregion

	#region Event names

	#[Test]
	#[TestDox('all() and has() agree on every name')]
	#[Group('strata/webhook')]
	public function allAndHasAgree(): void
	{
		foreach (StrataEvents::all() as $name) {
			$this->assertTrue(StrataEvents::has($name), $name);
		}

		$this->assertFalse(StrataEvents::has(''));
		$this->assertFalse(StrataEvents::has('strata.commit'));
		$this->assertFalse(StrataEvents::has('strata.commit.sealed.extra'));
	}

	#[Test]
	#[TestDox('every constant on the class is registered in all()')]
	#[Group('strata/webhook')]
	public function everyConstantIsRegistered(): void
	{
		$constants = (new ReflectionClass(StrataEvents::class))->getConstants();

		$this->assertNotEmpty($constants);

		foreach ($constants as $name => $value) {
			$this->assertIsString($value, $name);
			$this->assertTrue(StrataEvents::has($value), $name . ' is missing from all()');
		}

		$this->assertCount(count($constants), StrataEvents::all());
	}

	#[Test]
	#[TestDox('every name is dotted and prefixed, since a site subscribes to it verbatim')]
	#[Group('strata/webhook')]
	public function everyNameIsPrefixed(): void
	{
		foreach (StrataEvents::all() as $name) {
			$this->assertStringStartsWith('strata.', $name);
			$this->assertSame(3, count(explode('.', $name)), $name);
		}

		$this->assertSame(array_values(array_unique(StrataEvents::all())), StrataEvents::all());
	}

	#endregion
}
