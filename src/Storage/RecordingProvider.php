<?php

declare(strict_types=1);

namespace Drupal\strata\Storage;

use Closure;
use Throwable;
use UnexpectedValueException;

/**
 * Wraps any provider and records what every call to it cost.
 *
 * The engine asks a store for objects and gets no bill back. This decorator sits between the two
 * and writes each request into a ProviderStats, so a budget reading is taken from the requests that
 * were actually issued rather than from an estimate of how many a flush should have needed.
 *
 * Two verbs are recorded under the name of the request they really make, because that is what the
 * endpoint charges for:
 *
 * - StorageProviderInterface::exists() is a head.
 * - StorageProviderInterface::stream() is a get, with no byte count; the body is read by whoever
 *   holds the stream, not here.
 *
 * A delete counts once per call rather than once per key: a provider that batches sends one request
 * for the whole array, and the array size is visible in the caller's own code.
 *
 * Identity is forwarded untouched. Reading an id, a label, a capability set or a reachability flag
 * either costs nothing or is already cached by the provider, and counting a settings-form render as
 * store traffic would put the budget guard on the wrong side of the numbers.
 *
 * A failure is recorded and rethrown. Nothing is swallowed; a caller that cannot tell a failed
 * write from a successful one has no way to keep its own state honest.
 *
 * @see ProviderStats
 * @see StorageProviderInterface
 */
final class RecordingProvider implements StorageProviderInterface
{
	/**
	 * Returns the current time in seconds as a float.
	 *
	 * @var Closure
	 */
	private readonly Closure $clock;

	/**
	 * Constructs the decorator.
	 *
	 * @param StorageProviderInterface $inner
	 *   The provider every call is forwarded to.
	 * @param ProviderStats $stats
	 *   The accumulator to write into. Sharing one accumulator across several decorators totals
	 *   them; giving each its own keeps them separate.
	 * @param callable|null $clock
	 *   Returns seconds as a float or an int. NULL uses microtime(true). Injected so a test can
	 *   assert an exact duration instead of sleeping for one.
	 */
	public function __construct(
		private readonly StorageProviderInterface $inner,
		private readonly ProviderStats $stats,
		?callable $clock = null,
	) {
		$this->clock =
			$clock === null ? static fn(): float => microtime(true) : Closure::fromCallable($clock);
	}

	/**
	 * The accumulator this decorator writes into.
	 *
	 * @return ProviderStats
	 *   The same instance that was handed to the constructor.
	 */
	public function stats(): ProviderStats
	{
		return $this->stats;
	}

	/**
	 * The provider every call is forwarded to.
	 *
	 * Exposed for the same reason SiteScopedProvider::unscoped() is: code that has to know which
	 * endpoint is really configured should not have to match on a decorator.
	 *
	 * @return StorageProviderInterface
	 *   The wrapped provider.
	 */
	public function inner(): StorageProviderInterface
	{
		return $this->inner;
	}

	#region Identity

	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return $this->inner->id();
	}

	/**
	 * {@inheritdoc}
	 */
	public function label(): string
	{
		return $this->inner->label();
	}

	/**
	 * {@inheritdoc}
	 */
	public function capabilities(): Capabilities
	{
		return $this->inner->capabilities();
	}

	/**
	 * {@inheritdoc}
	 */
	public function isReachable(): bool
	{
		return $this->inner->isReachable();
	}

	/**
	 * {@inheritdoc}
	 */
	public function unreachableReason(): ?string
	{
		return $this->inner->unreachableReason();
	}

	#endregion

	#region Class A

	/**
	 * {@inheritdoc}
	 */
	public function put(string $key, mixed $body, array $options = []): PutResult
	{
		$started = $this->now();

		try {
			$result = $this->inner->put($key, $body, $options);
		} catch (Throwable $e) {
			$this->recordFailure('put', $started);

			throw $e;
		}

		$this->stats->record('put', $result->size, $this->elapsed($started));

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete(array $keys): int
	{
		$started = $this->now();

		try {
			$deleted = $this->inner->delete($keys);
		} catch (Throwable $e) {
			$this->recordFailure('delete', $started);

			throw $e;
		}

		$this->stats->record('delete', 0, $this->elapsed($started));

		return $deleted;
	}

	/**
	 * {@inheritdoc}
	 */
	public function list(
		string $prefix = '',
		?string $cursor = null,
		int $limit = 1000,
		?string $delimiter = null,
	): ObjectPage {
		$started = $this->now();

		try {
			$page = $this->inner->list($prefix, $cursor, $limit, $delimiter);
		} catch (Throwable $e) {
			$this->recordFailure('list', $started);

			throw $e;
		}

		$this->stats->record('list', 0, $this->elapsed($started));

		return $page;
	}

	#endregion

	#region Class B

	/**
	 * {@inheritdoc}
	 */
	public function get(string $key, ?ByteRange $range = null): string
	{
		$started = $this->now();

		try {
			$body = $this->inner->get($key, $range);
		} catch (Throwable $e) {
			$this->recordFailure('get', $started);

			throw $e;
		}

		$this->stats->record('get', strlen($body), $this->elapsed($started));

		return $body;
	}

	/**
	 * {@inheritdoc}
	 */
	public function stream(string $key)
	{
		$started = $this->now();

		try {
			$stream = $this->inner->stream($key);
		} catch (Throwable $e) {
			$this->recordFailure('get', $started);

			throw $e;
		}

		$this->stats->record('get', 0, $this->elapsed($started));

		return $stream;
	}

	/**
	 * {@inheritdoc}
	 */
	public function head(string $key): ?ObjectMeta
	{
		$started = $this->now();

		try {
			$meta = $this->inner->head($key);
		} catch (Throwable $e) {
			$this->recordFailure('head', $started);

			throw $e;
		}

		$this->stats->record('head', 0, $this->elapsed($started));

		return $meta;
	}

	/**
	 * {@inheritdoc}
	 */
	public function exists(string $key): bool
	{
		$started = $this->now();

		try {
			$exists = $this->inner->exists($key);
		} catch (Throwable $e) {
			$this->recordFailure('head', $started);

			throw $e;
		}

		$this->stats->record('head', 0, $this->elapsed($started));

		return $exists;
	}

	#endregion

	/**
	 * Records a failed attempt.
	 *
	 * No byte count, because a request that raised gives no honest one back; the failure count is
	 * what tells an operator the requests were spent on nothing.
	 *
	 * @param string $operation
	 *   The billed verb the call maps to.
	 * @param float $started
	 *   Clock reading taken before the call.
	 */
	private function recordFailure(string $operation, float $started): void
	{
		$this->stats->record($operation, 0, $this->elapsed($started), true);
	}

	/**
	 * How long a call took.
	 *
	 * Clamped at zero. A clock that steps backwards - an ntp correction, or a test clock rewound
	 * between reads - would otherwise make ProviderStats::record() raise on top of whatever the
	 * call itself was doing, replacing the real error with a bookkeeping one.
	 *
	 * @param float $started
	 *   Clock reading taken before the call.
	 *
	 * @return float
	 *   Seconds elapsed, never negative.
	 */
	private function elapsed(float $started): float
	{
		return max(0.0, $this->now() - $started);
	}

	/**
	 * Reads the injected clock.
	 *
	 * @return float
	 *   Seconds. An int reading is widened, so a test clock counting whole seconds works.
	 *
	 * @throws UnexpectedValueException
	 *   When the injected clock returns anything but a number. Caught here rather than left to the
	 *   subtraction, where a string clock would turn every duration into zero.
	 */
	private function now(): float
	{
		$now = ($this->clock)();

		if (!is_float($now) && !is_int($now)) {
			throw new UnexpectedValueException(
				sprintf('The injected clock must return a number, got %s', get_debug_type($now)),
			);
		}

		return (float) $now;
	}
}
