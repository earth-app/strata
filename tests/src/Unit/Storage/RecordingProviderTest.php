<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Storage;

use Drupal\strata\Storage\ByteRange;
use Drupal\strata\Storage\Capabilities;
use Drupal\strata\Storage\ObjectMeta;
use Drupal\strata\Storage\ObjectPage;
use Drupal\strata\Storage\Plugin\Strata\Storage\NullStorage;
use Drupal\strata\Storage\ProviderStats;
use Drupal\strata\Storage\PutResult;
use Drupal\strata\Storage\RecordingProvider;
use Drupal\strata\Storage\StorageProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

#[CoversClass(RecordingProvider::class)]
class RecordingProviderTest extends TestCase
{
	#region Fixtures

	/**
	 * Seconds the fake clock reports, advanced by hand instead of by sleeping.
	 *
	 * @var float
	 */
	private float $now = 1_000.0;

	/**
	 * Seconds every clock reading adds, so one call measures exactly this long.
	 *
	 * @var float
	 */
	private float $step = 0.25;

	/**
	 * The accumulator the decorator under test writes into.
	 *
	 * @var ProviderStats
	 */
	private ProviderStats $stats;

	protected function setUp(): void
	{
		parent::setUp();

		$this->now = 1_000.0;
		$this->step = 0.25;
		$this->stats = new ProviderStats();
	}

	private function tick(): float
	{
		$reading = $this->now;
		$this->now += $this->step;

		return $reading;
	}

	private function recording(?StorageProviderInterface $inner = null): RecordingProvider
	{
		return new RecordingProvider(
			$inner ?? new NullStorage(),
			$this->stats,
			fn(): float => $this->tick(),
		);
	}

	private function fake(?Throwable $error = null, string $payload = ''): StorageProviderInterface
	{
		return new class ($error, $payload) implements StorageProviderInterface {
			public function __construct(
				private readonly ?Throwable $error,
				private readonly string $payload,
			) {}

			public function id(): string
			{
				return 'fake';
			}

			public function label(): string
			{
				return 'Fake Store';
			}

			public function capabilities(): Capabilities
			{
				return Capabilities::local();
			}

			public function isReachable(): bool
			{
				return $this->error === null;
			}

			public function unreachableReason(): ?string
			{
				return $this->error?->getMessage();
			}

			public function put(string $key, mixed $body, array $options = []): PutResult
			{
				$this->guard();

				return new PutResult($key, is_string($body) ? strlen($body) : 0);
			}

			public function get(string $key, ?ByteRange $range = null): string
			{
				$this->guard();

				return $this->payload;
			}

			public function stream(string $key)
			{
				$this->guard();

				$handle = fopen('php://memory', 'rb+');
				if ($handle === false) {
					throw new RuntimeException('Cannot open a memory stream');
				}

				fwrite($handle, $this->payload);
				rewind($handle);

				return $handle;
			}

			public function head(string $key): ?ObjectMeta
			{
				$this->guard();

				return new ObjectMeta($key, strlen($this->payload));
			}

			public function exists(string $key): bool
			{
				$this->guard();

				return true;
			}

			public function delete(array $keys): int
			{
				$this->guard();

				return count($keys);
			}

			public function list(
				string $prefix = '',
				?string $cursor = null,
				int $limit = 1000,
				?string $delimiter = null,
			): ObjectPage {
				$this->guard();

				return new ObjectPage();
			}

			private function guard(): void
			{
				if ($this->error !== null) {
					throw $this->error;
				}
			}
		};
	}

	private function caught(callable $call): ?Throwable
	{
		try {
			$call();
		} catch (Throwable $e) {
			return $e;
		}

		return null;
	}

	#endregion

	#region Identity

	#[Test]
	#[TestDox('identity is forwarded to the inner provider')]
	#[Group('strata/storage')]
	public function forwardsIdentity(): void
	{
		$inner = new NullStorage();
		$provider = $this->recording($inner);

		$this->assertSame($inner->id(), $provider->id());
		$this->assertSame($inner->label(), $provider->label());
		$this->assertEquals($inner->capabilities(), $provider->capabilities());
		$this->assertTrue($provider->isReachable());
		$this->assertNull($provider->unreachableReason());
	}

	#[Test]
	#[TestDox('reading identity records no billed operation')]
	#[Group('strata/storage')]
	public function identityIsNotBilled(): void
	{
		$provider = $this->recording();

		$provider->id();
		$provider->label();
		$provider->capabilities();
		$provider->isReachable();
		$provider->unreachableReason();

		$this->assertSame(0, $this->stats->operations());
		$this->assertSame(0.0, $this->stats->seconds());
	}

	#[Test]
	#[TestDox('an unreachable inner provider reports its own reason through the decorator')]
	#[Group('strata/storage')]
	public function forwardsUnreachableReason(): void
	{
		$provider = $this->recording($this->fake(new RuntimeException('bucket is gone')));

		$this->assertFalse($provider->isReachable());
		$this->assertSame('bucket is gone', $provider->unreachableReason());
		$this->assertSame(0, $this->stats->operations());
	}

	#[Test]
	#[TestDox('stats() hands back the accumulator it was given')]
	#[Group('strata/storage')]
	public function exposesTheAccumulator(): void
	{
		$this->assertSame($this->stats, $this->recording()->stats());
	}

	#endregion

	#region Class A

	#[Test]
	#[TestDox('a put records its size and its duration as a billed write')]
	#[Group('strata/storage')]
	public function recordsPut(): void
	{
		$result = $this->recording()->put('frames/aa/bb', 'hello world');

		$this->assertSame(11, $result->size);
		$this->assertSame(1, $this->stats->classA());
		$this->assertSame(0, $this->stats->classB());
		$this->assertSame(11, $this->stats->bytes());
		$this->assertSame(0.25, $this->stats->seconds());
		$this->assertSame(0.25, $this->stats->latency('put')['max']);
	}

	#[Test]
	#[TestDox('a streamed put records the size the store reported')]
	#[Group('strata/storage')]
	public function recordsStreamedPut(): void
	{
		$handle = fopen('php://memory', 'rb+');
		$this->assertIsResource($handle);

		fwrite($handle, str_repeat('x', 4_096));
		rewind($handle);

		$this->recording()->put('frames/cc/dd', $handle);
		fclose($handle);

		$this->assertSame(4_096, $this->stats->bytes());
		$this->assertSame(1, $this->stats->classA());
	}

	#[Test]
	#[TestDox('a delete counts once per call, whatever the batch holds')]
	#[Group('strata/storage')]
	public function recordsDeleteOncePerCall(): void
	{
		$provider = $this->recording();
		$provider->put('a', 'one');
		$provider->put('b', 'two');

		$this->assertSame(2, $provider->delete(['a', 'b']));
		$this->assertSame(1, $this->stats->byOperation()['delete']['count']);
		$this->assertSame(0, $this->stats->byOperation()['delete']['bytes']);
	}

	#[Test]
	#[TestDox('a list records one billed write and no bytes')]
	#[Group('strata/storage')]
	public function recordsList(): void
	{
		$provider = $this->recording();
		$provider->put('frames/aa', 'one');

		$page = $provider->list('frames');

		$this->assertCount(1, $page);
		$this->assertSame(1, $this->stats->byOperation()['list']['count']);
		$this->assertSame(0, $this->stats->byOperation()['list']['bytes']);
	}

	#endregion

	#region Class B

	#[Test]
	#[TestDox('a get records the bytes it returned')]
	#[Group('strata/storage')]
	public function recordsGet(): void
	{
		$body = $this->recording($this->fake(null, 'abcdef'))->get('frames/aa/bb');

		$this->assertSame('abcdef', $body);
		$this->assertSame(1, $this->stats->classB());
		$this->assertSame(6, $this->stats->bytes());
	}

	#[Test]
	#[TestDox('a ranged get is recorded the same way as a whole one')]
	#[Group('strata/storage')]
	public function recordsRangedGet(): void
	{
		$this->recording($this->fake(null, 'abc'))->get('frames/aa/bb', new ByteRange(0, 3));

		$this->assertSame(1, $this->stats->classB());
		$this->assertSame(3, $this->stats->bytes());
	}

	#[Test]
	#[TestDox('a stream is billed as a get with no byte count')]
	#[Group('strata/storage')]
	public function recordsStreamAsGet(): void
	{
		$handle = $this->recording($this->fake(null, 'abcdef'))->stream('frames/aa/bb');

		$this->assertIsResource($handle);
		$this->assertSame('abcdef', stream_get_contents($handle));
		fclose($handle);

		$this->assertSame(1, $this->stats->byOperation()['get']['count']);
		$this->assertSame(0, $this->stats->bytes());
	}

	#[Test]
	#[TestDox('a head records a billed read whether or not the object is there')]
	#[Group('strata/storage')]
	public function recordsHead(): void
	{
		$provider = $this->recording();
		$provider->put('frames/aa', 'one');

		$this->assertNotNull($provider->head('frames/aa'));
		$this->assertNull($provider->head('frames/zz'));
		$this->assertSame(2, $this->stats->byOperation()['head']['count']);
		$this->assertSame(0, $this->stats->failures());
	}

	#[Test]
	#[TestDox('an existence check is billed as a head')]
	#[Group('strata/storage')]
	public function recordsExistsAsHead(): void
	{
		$provider = $this->recording();
		$provider->put('frames/aa', 'one');

		$this->assertTrue($provider->exists('frames/aa'));
		$this->assertFalse($provider->exists('frames/zz'));
		$this->assertSame(2, $this->stats->byOperation()['head']['count']);
		$this->assertSame(2, $this->stats->classB());
	}

	#endregion

	#region Failures

	#[Test]
	#[TestDox('a read against a dry-run store is recorded as a failed billed read and rethrown')]
	#[Group('strata/storage')]
	public function recordsFailureFromRealProvider(): void
	{
		$provider = $this->recording();

		$error = $this->caught(static fn(): string => $provider->get('frames/aa'));

		$this->assertInstanceOf(RuntimeException::class, $error);
		$this->assertSame(1, $this->stats->failures());
		$this->assertSame(1, $this->stats->classB());
		$this->assertSame(0, $this->stats->bytes());
		$this->assertSame(0.25, $this->stats->latency('get')['max']);
	}

	/**
	 * @return array<string, array{string, string, int, int}>
	 */
	public static function failingCallProvider(): array
	{
		return [
			'put' => ['put', 'put', 1, 0],
			'delete' => ['delete', 'delete', 1, 0],
			'list' => ['list', 'list', 1, 0],
			'get' => ['get', 'get', 0, 1],
			'stream' => ['stream', 'get', 0, 1],
			'head' => ['head', 'head', 0, 1],
			'exists' => ['exists', 'head', 0, 1],
		];
	}

	#[Test]
	#[TestDox('a failed $_dataName is recorded under its billed verb and rethrown')]
	#[Group('strata/storage')]
	#[DataProvider('failingCallProvider')]
	public function recordsEveryFailedCall(
		string $method,
		string $operation,
		int $classA,
		int $classB,
	): void {
		$provider = $this->recording($this->fake(new RuntimeException('endpoint refused')));

		$error = $this->caught(static function () use ($provider, $method): void {
			match ($method) {
				'put' => $provider->put('a', 'b'),
				'delete' => $provider->delete(['a']),
				'list' => $provider->list('a'),
				'get' => $provider->get('a'),
				'stream' => $provider->stream('a'),
				'head' => $provider->head('a'),
				'exists' => $provider->exists('a'),
			};
		});

		$this->assertInstanceOf(RuntimeException::class, $error);
		$this->assertSame('endpoint refused', $error->getMessage());
		$this->assertSame(1, $this->stats->failures());
		$this->assertSame(1, $this->stats->byOperation()[$operation]['failures']);
		$this->assertSame($classA, $this->stats->classA());
		$this->assertSame($classB, $this->stats->classB());
	}

	#[Test]
	#[TestDox('the exception the inner provider raised is the one the caller sees')]
	#[Group('strata/storage')]
	public function rethrowsTheOriginalException(): void
	{
		$thrown = new RuntimeException('endpoint refused');
		$provider = $this->recording($this->fake($thrown));

		$error = $this->caught(static fn(): PutResult => $provider->put('a', 'b'));

		$this->assertSame($thrown, $error);
	}

	#endregion

	#region Clock

	#[Test]
	#[TestDox('with no clock injected the wall clock is used')]
	#[Group('strata/storage')]
	public function fallsBackToTheWallClock(): void
	{
		$provider = new RecordingProvider(new NullStorage(), $this->stats);
		$provider->put('frames/aa', 'one');

		$this->assertSame(1, $this->stats->operations());
		$this->assertGreaterThanOrEqual(0.0, $this->stats->seconds());
	}

	#[Test]
	#[TestDox('a clock counting whole seconds is widened rather than refused')]
	#[Group('strata/storage')]
	public function acceptsIntegerClock(): void
	{
		$readings = [10, 13];
		$provider = new RecordingProvider(new NullStorage(), $this->stats, static function () use (
			&$readings,
		): int {
			return array_shift($readings) ?? 0;
		});

		$provider->put('frames/aa', 'one');

		$this->assertSame(3.0, $this->stats->seconds());
	}

	#[Test]
	#[TestDox('a clock that does not return a number is refused when it is read')]
	#[Group('strata/storage')]
	public function refusesNonNumericClock(): void
	{
		$provider = new RecordingProvider(
			new NullStorage(),
			$this->stats,
			static fn(): string => 'now',
		);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('must return a number, got string');

		$provider->put('frames/aa', 'one');
	}

	#[Test]
	#[TestDox('a clock that steps backwards records a zero duration instead of raising')]
	#[Group('strata/storage')]
	public function toleratesClockRewind(): void
	{
		$this->step = -5.0;
		$provider = $this->recording();

		$provider->put('frames/aa', 'one');

		$this->assertSame(0.0, $this->stats->seconds());
		$this->assertSame(1, $this->stats->operations());
	}

	#[Test]
	#[TestDox('every call adds its own duration to the window')]
	#[Group('strata/storage')]
	public function durationsAccumulateAcrossCalls(): void
	{
		$provider = $this->recording();
		$provider->put('a', 'one');
		$provider->put('b', 'two');
		$provider->exists('a');

		$this->assertSame(0.75, $this->stats->seconds());
		$this->assertSame(3, $this->stats->operations());
	}

	#endregion

	#region Sharing

	#[Test]
	#[TestDox('two decorators sharing an accumulator total their traffic together')]
	#[Group('strata/storage')]
	public function sharesOneAccumulator(): void
	{
		$first = $this->recording();
		$second = $this->recording();

		$first->put('a', 'one');
		$second->put('b', 'two two');

		$this->assertSame(2, $this->stats->operations());
		$this->assertSame(10, $this->stats->bytes());
		$this->assertSame($first->stats(), $second->stats());
	}

	#[Test]
	#[TestDox('a decorator with its own accumulator counts only its own traffic')]
	#[Group('strata/storage')]
	public function keepsSeparateAccumulatorsApart(): void
	{
		$other = new ProviderStats();
		$mine = $this->recording();
		$theirs = new RecordingProvider(new NullStorage(), $other, fn(): float => $this->tick());

		$mine->put('a', 'one');
		$theirs->put('b', 'two two');

		$this->assertSame(1, $this->stats->operations());
		$this->assertSame(3, $this->stats->bytes());
		$this->assertSame(1, $other->operations());
		$this->assertSame(7, $other->bytes());
	}

	#endregion
}
