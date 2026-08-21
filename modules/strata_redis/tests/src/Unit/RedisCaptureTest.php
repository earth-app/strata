<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_redis\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\strata\Capture\CaptureScope;
use Drupal\strata\Capture\Classifier\Classification;
use Drupal\strata\Capture\Classifier\ClassificationRegistry;
use Drupal\strata\Capture\Classifier\EphemeralScope;
use Drupal\strata\Capture\Classifier\Heuristics;
use Drupal\strata\Capture\KeyRecorder;
use Drupal\strata\Capture\PayloadCodec;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Journal\MemoryJournal;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;
use Drupal\strata_redis\CaptureReport;
use Drupal\strata_redis\RedisCapture;
use Drupal\strata_redis\RedisClientFactory;
use Drupal\strata_redis\RedisKeyspaceSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Proves the three rules that decide a key, and that nothing is ever dropped quietly.
 *
 * Authoritative is captured, derivable is skipped, and unclassified is captured AND flagged. The third
 * is the one worth a test: a backup that silently skips what it does not recognise has gaps nobody
 * knows about, so an unrecognised key has to arrive in the journal verbatim and be counted as needing
 * a decision. The same rule covers a value that cannot be read exactly - it is named in the report
 * rather than stored approximately or forgotten.
 *
 * `ext-redis` is absent here and `drupal/redis` is not installed, so there is no kernel lane for this
 * module. The pass runs against a stand-in client and a real recorder over a real journal, which means
 * the payload assertions are on bytes that went through the encoder a restore reads back.
 */
#[CoversClass(RedisCapture::class)]
class RedisCaptureTest extends TestCase
{
	#region Fixtures

	/**
	 * The journal captured keys land in.
	 */
	private MemoryJournal $journal;

	/**
	 * What the pass logged.
	 */
	private RecordingLogger $logger;

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->journal = new MemoryJournal();
		$this->logger = new RecordingLogger();
	}

	/**
	 * A capture over a stand-in client.
	 *
	 * @param object $client
	 *   The client the walk runs against.
	 * @param object|null $reader
	 *   The client values are read with, when it differs from the one the walk uses.
	 * @param bool $ephemeral
	 *   Whether the ephemeral realm is captured.
	 * @param int $keyLimit
	 *   Keys one pass reads.
	 * @param int $byteBudget
	 *   Bytes one pass captures.
	 *
	 * @return RedisCapture
	 *   The capture.
	 */
	private function capture(
		object $client,
		?object $reader = null,
		bool $ephemeral = true,
		int $keyLimit = RedisCapture::DEFAULT_KEYS,
		int $byteBudget = RedisCapture::DEFAULT_BYTES,
	): RedisCapture {
		$scope = new CaptureScope($this->settings($ephemeral));

		return new RedisCapture(
			new RedisKeyspaceSource(new RedisClientFactory($this->logger, $client)),
			new RedisClientFactory($this->logger, $reader ?? $client),
			new EphemeralScope($scope, new ClassificationRegistry($this->database())),
			new KeyRecorder($this->journal, $scope, $this->account(), $this->logger),
			$this->logger,
			$keyLimit,
			$byteBudget,
		);
	}

	/**
	 * A capture that cannot resolve a client at all.
	 *
	 * Skipped where `ext-redis` is loaded, because the factory would then open a real socket and the
	 * outcome would depend on what is listening on the host.
	 *
	 * @return RedisCapture
	 *   The capture.
	 */
	private function unavailableCapture(): RedisCapture
	{
		if (RedisClientFactory::isSupported()) {
			$this->markTestSkipped(
				'ext-redis is loaded here, so an absent client cannot be driven',
			);
		}

		$scope = new CaptureScope($this->settings(true));

		return new RedisCapture(
			new RedisKeyspaceSource(new RedisClientFactory($this->logger)),
			new RedisClientFactory($this->logger),
			new EphemeralScope($scope, new ClassificationRegistry($this->database())),
			new KeyRecorder($this->journal, $scope, $this->account(), $this->logger),
			$this->logger,
		);
	}

	/**
	 * Settings that switch the ephemeral realm on or off.
	 *
	 * CaptureScope is final and reads the config factory, so the scope under test is the real one over
	 * stubbed settings rather than a subclass that answers differently.
	 *
	 * @param bool $ephemeral
	 *   Whether the realm is captured.
	 *
	 * @return ConfigFactoryInterface
	 *   The factory.
	 */
	private function settings(bool $ephemeral): ConfigFactoryInterface
	{
		$config = $this->createMock(ImmutableConfig::class);
		$config->method('getRawData')->willReturn([
			'enabled' => true,
			'capture' => ['ephemeral' => $ephemeral],
		]);

		$factory = $this->createMock(ConfigFactoryInterface::class);
		$factory->method('get')->willReturn($config);

		return $factory;
	}

	/**
	 * A database holding no stored classifications, so the heuristics decide every key.
	 *
	 * @return Connection
	 *   The connection.
	 */
	private function database(): Connection
	{
		$select = $this->createMock(SelectInterface::class);
		$select->method('fields')->willReturnSelf();
		$select->method('execute')->willReturn(null);

		$database = $this->createMock(Connection::class);
		$database->method('select')->willReturn($select);

		return $database;
	}

	/**
	 * A current-user stand-in that reports nobody, because a cron pass is unattended.
	 *
	 * @return AccountProxyInterface
	 *   The account.
	 */
	private function account(): AccountProxyInterface
	{
		$account = $this->createMock(AccountProxyInterface::class);
		$account->method('id')->willReturn(0);

		return $account;
	}

	/**
	 * The journal entry for one subject.
	 *
	 * @param string $subject
	 *   The subject key.
	 *
	 * @return array{operation: JournalOp, payload: string|null}
	 *   The entry.
	 */
	private function entry(string $subject): array
	{
		foreach ($this->journal->read(1000) as $entry) {
			if ($entry['operation']->subject === $subject) {
				return $entry;
			}
		}

		self::fail(sprintf('%s was not captured', $subject));
	}

	/**
	 * Whether one subject reached the journal.
	 *
	 * @param string $subject
	 *   The subject key.
	 *
	 * @return bool
	 *   TRUE when it did.
	 */
	private function wasCaptured(string $subject): bool
	{
		foreach ($this->journal->read(1000) as $entry) {
			if ($entry['operation']->subject === $subject) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The payload one captured key holds, decoded the way a replay decodes it.
	 *
	 * @param string $subject
	 *   The subject key.
	 *
	 * @return array<string, mixed>
	 *   The field map.
	 */
	private function payload(string $subject): array
	{
		$payload = $this->entry($subject)['payload'];

		if ($payload === null) {
			self::fail(sprintf('%s was captured with no payload', $subject));
		}

		$decoded = PayloadCodec::decode(Realm::EPHEMERAL, $payload);

		if ($decoded === null) {
			self::fail(sprintf('%s did not decode as a field map', $subject));
		}

		return $decoded;
	}

	/**
	 * The encoded value one captured key holds.
	 *
	 * @param string $subject
	 *   The subject key.
	 *
	 * @return mixed
	 *   The value, still base64 encoded.
	 */
	private function value(string $subject): mixed
	{
		return $this->payload($subject)[PayloadCodec::VALUE] ?? null;
	}

	#endregion

	#region Passes That Do Not Run

	#[Test]
	#[TestDox('a switched off realm captures nothing and says which switch')]
	#[Group('strata/redis')]
	public function switchedOffRealmCapturesNothing(): void
	{
		$redis = (new FakeRedis())->hold('queue:jobs', 'string', 'work');

		$report = $this->capture($redis, ephemeral: false)->capture();

		$this->assertSame(CaptureReport::REALM_OFF, $report->stopped);
		$this->assertSame(
			'ephemeral capture is switched off, so no key is captured',
			$report->reason,
		);
		$this->assertFalse($report->ran());
		$this->assertSame(0, $this->journal->pending());
		$this->assertSame(0, $redis->scans, 'a switched off realm is not a round trip');
	}

	#[Test]
	#[TestDox('no client at all is unavailable and carries the reason')]
	#[Group('strata/redis')]
	public function noClientIsUnavailable(): void
	{
		$report = $this->unavailableCapture()->capture();

		$this->assertSame(CaptureReport::UNAVAILABLE, $report->stopped);
		$this->assertSame('ext-redis is not loaded on this host', $report->reason);
		$this->assertFalse($report->ran());
		$this->assertSame(0, $this->journal->pending());
	}

	#[Test]
	#[TestDox('a walk that raises is reported rather than thrown at the cron hook')]
	#[Group('strata/redis')]
	public function walkThatRaisesIsReported(): void
	{
		$redis = (new FakeRedis())->hold('queue:jobs', 'string', 'work');
		$redis->failing = ['scan'];

		$report = $this->capture($redis)->capture();

		$this->assertSame(CaptureReport::FAILED, $report->stopped);
		$this->assertSame('scan is unavailable', $report->reason);
		$this->assertStringContainsString('could not capture', $this->logger->at('error'));
	}

	#endregion

	#region Classification

	#[Test]
	#[TestDox('a derivable key is skipped and the report says why')]
	#[Group('strata/redis')]
	public function derivableKeyIsSkipped(): void
	{
		$redis = (new FakeRedis())
			->hold('cache_render:page', 'string', 'rebuilt on demand')
			->hold('queue:jobs', 'string', 'work');

		$report = $this->capture($redis)->capture();

		$this->assertSame(2, $report->scanned);
		$this->assertSame(1, $report->captured);
		$this->assertSame(1, $report->skipped);
		$this->assertSame(
			'derivable, so a restore would never write it back',
			$report->reasonFor('cache_render:page'),
		);
		$this->assertFalse($this->wasCaptured('cache_render:page'));
		$this->assertTrue($this->wasCaptured('queue:jobs'));
	}

	#[Test]
	#[TestDox('an unclassified key is captured verbatim and flagged rather than dropped')]
	#[Group('strata/redis')]
	public function unclassifiedKeyIsCapturedAndFlagged(): void
	{
		$this->assertSame(
			Classification::UNCLASSIFIED,
			Heuristics::classify('mystery:thing'),
			'the fixture only means anything while nothing classifies it',
		);

		$report = $this->capture(
			(new FakeRedis())->hold('mystery:thing', 'string', 'nobody knows'),
		)->capture();

		$this->assertSame(1, $report->captured);
		$this->assertSame(1, $report->flagged);
		$this->assertSame(0, $report->skipped);
		$this->assertTrue($report->needsDecisions());
		$this->assertSame(
			'captured verbatim and waiting for someone to decide it',
			$report->reasonFor('mystery:thing'),
		);
		$this->assertSame(
			base64_encode('nobody knows'),
			$this->value('mystery:thing'),
			'an unrecognised key is stored, not skipped',
		);
	}

	#[Test]
	#[TestDox('a captured key is an update, because a pass sees a value and not a write')]
	#[Group('strata/redis')]
	public function capturedKeyIsRecordedAsAnUpdate(): void
	{
		$this->capture((new FakeRedis())->hold('queue:jobs', 'string', 'work'))->capture();

		$operation = $this->entry('queue:jobs')['operation'];

		$this->assertSame(Realm::EPHEMERAL, $operation->realm);
		$this->assertSame(Verb::UPDATE, $operation->verb);
		$this->assertSame('queue:jobs', $operation->subject, 'the key is the subject, verbatim');
	}

	#endregion

	#region Types

	#[Test]
	#[TestDox('a string is stored base64 encoded, so arbitrary bytes survive json')]
	#[Group('strata/redis')]
	public function stringIsStoredBase64Encoded(): void
	{
		$value = "\xff\xfe binary";
		$report = $this->capture(
			(new FakeRedis())->hold('queue:jobs', 'string', $value),
		)->capture();

		$this->assertSame(
			[
				RedisCapture::FIELD_TYPE => RedisCapture::TYPE_STRING,
				RedisCapture::FIELD_ENCODING => RedisCapture::ENCODING,
				PayloadCodec::VALUE => base64_encode($value),
			],
			$this->payload('queue:jobs'),
		);
		$this->assertSame(
			strlen($value),
			$report->bytes,
			'the raw length is counted, not the base64',
		);
	}

	#[Test]
	#[TestDox('a hash is stored field by field, both halves encoded')]
	#[Group('strata/redis')]
	public function hashIsStoredFieldByField(): void
	{
		$report = $this->capture(
			(new FakeRedis())->hold('queue:map', 'hash', ['name' => 'value']),
		)->capture();

		$this->assertSame(
			[base64_encode('name') => base64_encode('value')],
			$this->value('queue:map'),
		);
		$this->assertSame(RedisCapture::TYPE_HASH, $this->payload('queue:map')['type']);
		$this->assertSame(9, $report->bytes);
	}

	#[Test]
	#[TestDox('a numeric hash field name arrives as an integer key and is stored exactly')]
	#[Group('strata/redis')]
	public function numericHashFieldNameIsStoredExactly(): void
	{
		$this->capture((new FakeRedis())->hold('queue:map', 'hash', ['5' => 'x']))->capture();

		$this->assertSame([base64_encode('5') => base64_encode('x')], $this->value('queue:map'));
	}

	#[Test]
	#[TestDox('a list keeps the order the list holds')]
	#[Group('strata/redis')]
	public function listKeepsItsOrder(): void
	{
		$this->capture(
			(new FakeRedis())->hold('queue:jobs', 'list', ['first', 'second']),
		)->capture();

		$this->assertSame(
			[base64_encode('first'), base64_encode('second')],
			$this->value('queue:jobs'),
		);
		$this->assertSame(RedisCapture::TYPE_LIST, $this->payload('queue:jobs')['type']);
	}

	#[Test]
	#[TestDox('a set is stored as its members')]
	#[Group('strata/redis')]
	public function setIsStoredAsItsMembers(): void
	{
		$report = $this->capture(
			(new FakeRedis())->hold('flood:attempts', 'set', ['one', 'two']),
		)->capture();

		$this->assertSame(
			[base64_encode('one'), base64_encode('two')],
			$this->value('flood:attempts'),
		);
		$this->assertSame(6, $report->bytes);
	}

	#[Test]
	#[TestDox('a sorted set keeps its scores and does not count them as stored bytes')]
	#[Group('strata/redis')]
	public function sortedSetKeepsItsScores(): void
	{
		$report = $this->capture(
			(new FakeRedis())->hold('queue:ranked', 'zset', ['alpha' => 1.5]),
		)->capture();

		$this->assertSame([base64_encode('alpha') => 1.5], $this->value('queue:ranked'));
		$this->assertSame(RedisCapture::TYPE_ZSET, $this->payload('queue:ranked')['type']);
		$this->assertSame(5, $report->bytes, 'the member is counted and the score is not');
	}

	#[Test]
	#[TestDox('a score arriving as a numeric string is still a score')]
	#[Group('strata/redis')]
	public function numericStringScoreIsAccepted(): void
	{
		$this->capture(
			(new FakeRedis())->hold('queue:ranked', 'zset', ['alpha' => '2.25', 'beta' => 3]),
		)->capture();

		$this->assertSame(
			[base64_encode('alpha') => 2.25, base64_encode('beta') => 3],
			$this->value('queue:ranked'),
			'a whole score is json 3 and reads back as an int, which zadd takes either way',
		);
	}

	#[Test]
	#[TestDox('a stream is named rather than stored, because no single read reproduces one')]
	#[Group('strata/redis')]
	public function streamIsNamedRatherThanStored(): void
	{
		$report = $this->capture(
			(new FakeRedis())->hold('queue:events', 'stream', ['1-0' => ['field' => 'value']]),
		)->capture();

		$this->assertSame(0, $report->captured);
		$this->assertSame(1, $report->skipped);
		$this->assertSame(
			'a stream is not read back byte for byte by one command, so it is named rather than stored',
			$report->reasonFor('queue:events'),
		);
		$this->assertFalse($this->wasCaptured('queue:events'));
	}

	#[Test]
	#[TestDox('a type answered by name rather than by code is understood')]
	#[Group('strata/redis')]
	public function typeAnsweredByNameIsUnderstood(): void
	{
		$redis = (new FakeRedis())->hold('queue:jobs', 'string', 'work');
		$redis->typeAsName = true;

		$this->assertSame(1, $this->capture($redis)->capture()->captured);
	}

	/**
	 * @return array<string, array{mixed, string}>
	 *   The reply keyed to the type name it means.
	 */
	public static function typeReplyProvider(): array
	{
		return [
			'the string code' => [1, RedisCapture::TYPE_STRING],
			'the set code' => [2, RedisCapture::TYPE_SET],
			'the list code' => [3, RedisCapture::TYPE_LIST],
			'the zset code' => [4, RedisCapture::TYPE_ZSET],
			'the hash code' => [5, RedisCapture::TYPE_HASH],
			'the stream code' => [6, RedisCapture::TYPE_STREAM],
			'a missing key' => [0, ''],
			'an unknown code' => [99, ''],
			'a name' => ['hash', RedisCapture::TYPE_HASH],
			'a name in the wrong case' => ['  ZSET ', RedisCapture::TYPE_ZSET],
			'the reply for a missing key' => ['none', ''],
			'an unknown name' => ['geo', ''],
			'nothing at all' => [null, ''],
			'a float' => [1.5, ''],
			'a list' => [[1], ''],
		];
	}

	#[Test]
	#[TestDox('$_dataName is read as the type it means')]
	#[Group('strata/redis')]
	#[DataProvider('typeReplyProvider')]
	public function typeRepliesAreRead(mixed $reply, string $expected): void
	{
		$this->assertSame($expected, RedisCapture::typeOf($reply));
	}

	#[Test]
	#[TestDox('the reply codes are the numbers phpredis answers with')]
	#[Group('strata/redis')]
	public function replyCodesMatchPhpredis(): void
	{
		$this->assertSame(FakeRedis::CODES, array_flip(RedisCapture::TYPES));
	}

	#endregion

	#region Values That Cannot Be Read

	#[Test]
	#[TestDox('a value that cannot be read is reported rather than silently lost')]
	#[Group('strata/redis')]
	public function valueThatCannotBeReadIsReported(): void
	{
		$report = $this->capture((new FakeRedis())->hold('queue:jobs', 'string', false))->capture();

		$this->assertSame(1, $report->scanned);
		$this->assertSame(0, $report->captured);
		$this->assertSame(1, $report->skipped);
		$this->assertSame(
			'the value came back as bool rather than bytes, so it is not stored',
			$report->reasonFor('queue:jobs'),
		);
		$this->assertSame(0, $this->journal->pending());
	}

	#[Test]
	#[TestDox('a type command that raises leaves the key named rather than guessed at')]
	#[Group('strata/redis')]
	public function typeCommandThatRaisesIsReported(): void
	{
		$redis = (new FakeRedis())->hold('queue:jobs', 'string', 'work');
		$redis->failing = ['type'];

		$report = $this->capture($redis)->capture();

		$this->assertSame(1, $report->skipped);
		$this->assertSame(
			'the key went away, or holds a type this release does not recognise',
			$report->reasonFor('queue:jobs'),
		);
	}

	#[Test]
	#[TestDox('a client with no type command names every key instead of storing it')]
	#[Group('strata/redis')]
	public function clientWithNoTypeCommandNamesEveryKey(): void
	{
		$redis = new ScanOnlyRedis();
		$redis->names = ['queue:jobs'];

		$report = $this->capture($redis)->capture();

		$this->assertSame(1, $report->skipped);
		$this->assertSame(
			'the key went away, or holds a type this release does not recognise',
			$report->reasonFor('queue:jobs'),
		);
	}

	#[Test]
	#[TestDox('a client missing the read command for a type says which command')]
	#[Group('strata/redis')]
	public function clientMissingTheReadCommandSaysWhich(): void
	{
		$report = $this->capture(
			(new FakeRedis())->hold('queue:jobs', 'string', 'work'),
			new TypeOnlyRedis(),
		)->capture();

		$this->assertSame(1, $report->skipped);
		$this->assertSame(
			'the client does not support get, which reading a string needs',
			$report->reasonFor('queue:jobs'),
		);
	}

	#[Test]
	#[TestDox('a collection that did not come back as one is reported')]
	#[Group('strata/redis')]
	public function collectionThatIsNotOneIsReported(): void
	{
		$report = $this->capture(
			(new FakeRedis())->hold('flood:attempts', 'set', false),
		)->capture();

		$this->assertSame(
			'the set did not come back as a collection, so it is not stored',
			$report->reasonFor('flood:attempts'),
		);
	}

	#[Test]
	#[TestDox('a map that did not come back as one is reported')]
	#[Group('strata/redis')]
	public function mapThatIsNotOneIsReported(): void
	{
		$report = $this->capture((new FakeRedis())->hold('queue:map', 'hash', false))->capture();

		$this->assertSame(
			'the hash did not come back as a map, so it is not stored',
			$report->reasonFor('queue:map'),
		);
	}

	#[Test]
	#[TestDox('a member that is not bytes refuses the whole key rather than half of it')]
	#[Group('strata/redis')]
	public function memberThatIsNotBytesRefusesTheKey(): void
	{
		$report = $this->capture(
			(new FakeRedis())->hold('queue:jobs', 'list', ['fine', 7]),
		)->capture();

		$this->assertSame(0, $report->captured);
		$this->assertSame(
			'a member of the list came back as int rather than bytes, so the key is not stored',
			$report->reasonFor('queue:jobs'),
		);
	}

	#[Test]
	#[TestDox('a hash field that is not bytes refuses the whole key')]
	#[Group('strata/redis')]
	public function hashFieldThatIsNotBytesRefusesTheKey(): void
	{
		$report = $this->capture(
			(new FakeRedis())->hold('queue:map', 'hash', ['name' => 7]),
		)->capture();

		$this->assertSame(0, $report->captured);
		$this->assertSame(
			'the field name came back as int rather than bytes, so the key is not stored',
			$report->reasonFor('queue:map'),
		);
	}

	#[Test]
	#[TestDox('a score that is not a number refuses the whole key')]
	#[Group('strata/redis')]
	public function scoreThatIsNotANumberRefusesTheKey(): void
	{
		$report = $this->capture(
			(new FakeRedis())->hold('queue:ranked', 'zset', ['alpha' => 'nope']),
		)->capture();

		$this->assertSame(0, $report->captured);
		$this->assertSame(
			'the score for alpha came back as string rather than a number, so the key is not stored',
			$report->reasonFor('queue:ranked'),
		);
	}

	#endregion

	#region Bounds

	#[Test]
	#[TestDox('the shipped bounds are the ones the docblocks describe')]
	#[Group('strata/redis')]
	public function shippedBoundsAreDocumented(): void
	{
		$this->assertSame(5000, Constant::of(RedisCapture::class, 'DEFAULT_KEYS'));
		$this->assertSame(
			8 * 1024 * 1024,
			Constant::of(RedisCapture::class, 'DEFAULT_BYTES'),
			'the docblock calls the byte budget 8 MiB',
		);
		$this->assertSame(50, Constant::of(RedisCapture::class, 'REASON_LIMIT'));
	}

	#[Test]
	#[TestDox('the key limit stops the pass and says it stopped')]
	#[Group('strata/redis')]
	public function keyLimitStopsThePass(): void
	{
		$report = $this->capture($this->manyKeys(3), keyLimit: 2)->capture();

		$this->assertSame(CaptureReport::KEY_LIMIT, $report->stopped);
		$this->assertSame('the pass stopped at its limit of 2 keys', $report->reason);
		$this->assertSame(2, $report->scanned);
		$this->assertFalse($report->isComplete());
	}

	#[Test]
	#[TestDox('a limit asked for at the call overrides the configured one')]
	#[Group('strata/redis')]
	public function limitAskedForAtTheCallWins(): void
	{
		$report = $this->capture($this->manyKeys(3), keyLimit: 5000)->capture(1);

		$this->assertSame(CaptureReport::KEY_LIMIT, $report->stopped);
		$this->assertSame(1, $report->scanned);
	}

	#[Test]
	#[TestDox('a limit below one is clamped to one key rather than to none')]
	#[Group('strata/redis')]
	public function limitBelowOneIsClamped(): void
	{
		$report = $this->capture($this->manyKeys(3))->capture(0);

		$this->assertSame(1, $report->scanned);
		$this->assertSame(1, $report->captured);
	}

	#[Test]
	#[TestDox('the byte budget stops the pass at the key that would have exceeded it')]
	#[Group('strata/redis')]
	public function byteBudgetStopsThePass(): void
	{
		$redis = (new FakeRedis())
			->hold('queue:a', 'string', '0123456789')
			->hold('queue:b', 'string', '0123456789');

		$report = $this->capture($redis, byteBudget: 15)->capture();

		$this->assertSame(CaptureReport::BYTE_BUDGET, $report->stopped);
		$this->assertSame('the 15 byte budget was reached at queue:b', $report->reason);
		$this->assertSame(2, $report->scanned);
		$this->assertSame(1, $report->captured);
		$this->assertSame(10, $report->bytes);
		$this->assertTrue($this->wasCaptured('queue:a'));
		$this->assertFalse($this->wasCaptured('queue:b'));
	}

	#[Test]
	#[TestDox('a value larger than the whole budget is captured on its own rather than never')]
	#[Group('strata/redis')]
	public function valueLargerThanTheBudgetIsStillCaptured(): void
	{
		$report = $this->capture(
			(new FakeRedis())->hold('queue:huge', 'string', str_repeat('x', 100)),
			byteBudget: 10,
		)->capture();

		$this->assertSame(1, $report->captured);
		$this->assertSame(100, $report->bytes);
		$this->assertTrue($report->isComplete());
		$this->assertTrue($this->wasCaptured('queue:huge'));
	}

	#[Test]
	#[TestDox('the per-key reason sample is bounded while the counts are not')]
	#[Group('strata/redis')]
	public function reasonSampleIsBounded(): void
	{
		$redis = new FakeRedis();

		for ($index = 0; $index < RedisCapture::REASON_LIMIT + 10; $index++) {
			$redis->hold(sprintf('cache_render:%d', $index), 'string', 'derived');
		}

		$report = $this->capture($redis)->capture();

		$this->assertSame(RedisCapture::REASON_LIMIT + 10, $report->skipped);
		$this->assertCount(RedisCapture::REASON_LIMIT, $report->reasons);
	}

	#endregion

	#region Logging

	#[Test]
	#[TestDox('a finished pass is logged with its summary')]
	#[Group('strata/redis')]
	public function finishedPassIsLogged(): void
	{
		$report = $this->capture(
			(new FakeRedis())->hold('queue:jobs', 'string', 'work'),
		)->capture();

		$this->assertStringContainsString($report->summary(), $this->logger->at('info'));
	}

	#[Test]
	#[TestDox('a pass that stored something undecided warns rather than only noting it')]
	#[Group('strata/redis')]
	public function passThatStoredSomethingUndecidedWarns(): void
	{
		$this->capture((new FakeRedis())->hold('mystery:thing', 'string', 'x'))->capture();

		$this->assertStringContainsString('nobody has classified', $this->logger->at('warning'));
	}

	#[Test]
	#[TestDox('a pass with nothing undecided does not warn')]
	#[Group('strata/redis')]
	public function passWithNothingUndecidedDoesNotWarn(): void
	{
		$this->capture((new FakeRedis())->hold('queue:jobs', 'string', 'work'))->capture();

		$this->assertSame('', $this->logger->at('warning'));
	}

	#endregion

	/**
	 * A keyspace of authoritative string keys.
	 *
	 * @param int $count
	 *   How many keys.
	 *
	 * @return FakeRedis
	 *   The client.
	 */
	private function manyKeys(int $count): FakeRedis
	{
		$redis = new FakeRedis();

		for ($index = 0; $index < $count; $index++) {
			$redis->hold(sprintf('queue:%d', $index), 'string', 'work');
		}

		return $redis;
	}
}
