<?php

declare(strict_types=1);

namespace Drupal\strata\Storage;

use InvalidArgumentException;
use JsonSerializable;

/**
 * What a store cost over one window: requests, bytes, failures and latency.
 *
 * Object storage is not billed by volume alone. Cloudflare R2 charges $0.015 per GB-month of
 * storage, $4.50 per million class-A operations and $0.36 per million class-B operations; AWS S3
 * charges $0.023, $5.00 and $0.40 for the same three lines. A capture that writes a million small
 * frames costs more in requests than in storage, and an accounting that only summed bytes would
 * never show it.
 *
 * Class A is the billed-write set: put, delete and list. Class B is the billed-read set: get and
 * head. Every attempt is counted, failures included, because an endpoint that refused a request
 * still handled it and still billed for it. ProviderStats::failures() is what separates the two.
 *
 * Mutable, and scoped to one request or one flush; this is an accumulator, not a record.
 * ProviderStats::merge() folds one window into another and ProviderStats::reset() starts a fresh
 * one.
 *
 * @see RecordingProvider
 * @see StorageProviderInterface
 */
final class ProviderStats implements JsonSerializable
{
	/**
	 * Every operation that is counted, class A first.
	 */
	public const OPERATIONS = ['put', 'delete', 'list', 'get', 'head'];

	/**
	 * The billed-write operations.
	 */
	public const CLASS_A = ['put', 'delete', 'list'];

	/**
	 * The billed-read operations.
	 */
	public const CLASS_B = ['get', 'head'];

	/**
	 * Most latency samples held per operation.
	 *
	 * A flush of a large site issues far more requests than a percentile needs to describe, and one
	 * float per request would let the accumulator grow for the length of the run. The newest samples
	 * are kept and the oldest dropped, so the figures describe what the store is doing now rather
	 * than what it did during the first thousand calls.
	 */
	public const LATENCY_SAMPLES = 1000;

	/**
	 * Attempts per operation.
	 *
	 * @var array<string, int>
	 */
	private array $counts = [];

	/**
	 * Bytes moved per operation.
	 *
	 * @var array<string, int>
	 */
	private array $volumes = [];

	/**
	 * Failed attempts per operation.
	 *
	 * @var array<string, int>
	 */
	private array $failed = [];

	/**
	 * The newest LATENCY_SAMPLES durations per operation, in arrival order.
	 *
	 * @var array<string, list<float>>
	 */
	private array $samples = [];

	/**
	 * Seconds spent across every operation, untruncated.
	 *
	 * @var float
	 */
	private float $seconds = 0.0;

	#region Recording

	/**
	 * Records one attempt against the store.
	 *
	 * @param string $operation
	 *   One of ProviderStats::OPERATIONS.
	 * @param int $bytes
	 *   Bytes that crossed the wire. Zero for an operation that moves no body, such as a head or a
	 *   delete.
	 * @param float $seconds
	 *   Wall-clock time the attempt took.
	 * @param bool $failed
	 *   TRUE when the attempt raised. It is still counted, because the request was still made.
	 *
	 * @throws InvalidArgumentException
	 *   When $operation is not a billed verb, or $bytes or $seconds is negative. Each of those is
	 *   a caller bug that would otherwise show up as a budget reading nobody can explain.
	 */
	public function record(
		string $operation,
		int $bytes,
		float $seconds,
		bool $failed = false,
	): void {
		self::assertOperation($operation);

		if ($bytes < 0) {
			throw new InvalidArgumentException(
				sprintf('Recorded bytes cannot be negative, got %d', $bytes),
			);
		}
		if ($seconds < 0.0) {
			throw new InvalidArgumentException(
				sprintf('A recorded duration cannot be negative, got %.6f', $seconds),
			);
		}

		$this->counts[$operation] = ($this->counts[$operation] ?? 0) + 1;
		$this->volumes[$operation] = ($this->volumes[$operation] ?? 0) + $bytes;
		$this->seconds += $seconds;

		if ($failed) {
			$this->failed[$operation] = ($this->failed[$operation] ?? 0) + 1;
		}

		$this->sample($operation, $seconds);
	}

	/**
	 * Folds another window into this one.
	 *
	 * Latency samples are appended, so the other window's observations count as the newer ones and
	 * the cap is applied to the result.
	 *
	 * @param ProviderStats $other
	 *   The window to fold in. It is left unchanged.
	 */
	public function merge(ProviderStats $other): void
	{
		foreach ($other->counts as $operation => $count) {
			$this->counts[$operation] = ($this->counts[$operation] ?? 0) + $count;
		}
		foreach ($other->volumes as $operation => $bytes) {
			$this->volumes[$operation] = ($this->volumes[$operation] ?? 0) + $bytes;
		}
		foreach ($other->failed as $operation => $failures) {
			$this->failed[$operation] = ($this->failed[$operation] ?? 0) + $failures;
		}
		foreach ($other->samples as $operation => $samples) {
			foreach ($samples as $seconds) {
				$this->sample($operation, $seconds);
			}
		}

		$this->seconds += $other->seconds;
	}

	/**
	 * Forgets everything and starts a new window.
	 */
	public function reset(): void
	{
		$this->counts = [];
		$this->volumes = [];
		$this->failed = [];
		$this->samples = [];
		$this->seconds = 0.0;
	}

	#endregion

	#region Totals

	/**
	 * Billed writes: put, delete and list.
	 *
	 * @return int
	 *   How many class-A requests were made.
	 */
	public function classA(): int
	{
		return $this->countOf(self::CLASS_A);
	}

	/**
	 * Billed reads: get and head.
	 *
	 * @return int
	 *   How many class-B requests were made.
	 */
	public function classB(): int
	{
		return $this->countOf(self::CLASS_B);
	}

	/**
	 * Bytes that crossed the wire.
	 *
	 * This is transfer volume, not the size of the store. What a bucket holds is a level the store
	 * reports; this is a rate the engine produced.
	 *
	 * @return int
	 *   Total bytes over every operation.
	 */
	public function bytes(): int
	{
		return array_sum($this->volumes);
	}

	/**
	 * Requests made, of either class.
	 *
	 * @return int
	 *   Total attempts, failures included.
	 */
	public function operations(): int
	{
		return array_sum($this->counts);
	}

	/**
	 * Requests that raised.
	 *
	 * @return int
	 *   How many attempts failed.
	 */
	public function failures(): int
	{
		return array_sum($this->failed);
	}

	/**
	 * Wall-clock time spent waiting on the store.
	 *
	 * Counted over every attempt, so it is not affected by the latency sample cap.
	 *
	 * @return float
	 *   Seconds.
	 */
	public function seconds(): float
	{
		return $this->seconds;
	}

	#endregion

	#region Breakdown

	/**
	 * Per-operation attempts, bytes and failures.
	 *
	 * Operations that were never attempted are left out, so a table rendered from this shows the
	 * verbs the run actually used. Order follows ProviderStats::OPERATIONS.
	 *
	 * @return array<string, array{count: int, bytes: int, failures: int}>
	 *   Keyed by operation.
	 */
	public function byOperation(): array
	{
		$breakdown = [];

		foreach (self::OPERATIONS as $operation) {
			$count = $this->counts[$operation] ?? 0;

			if ($count === 0) {
				continue;
			}

			$breakdown[$operation] = [
				'count' => $count,
				'bytes' => $this->volumes[$operation] ?? 0,
				'failures' => $this->failed[$operation] ?? 0,
			];
		}

		return $breakdown;
	}

	/**
	 * Latency figures for one operation.
	 *
	 * Every figure describes the retained sample, which is the newest LATENCY_SAMPLES durations for
	 * that operation. Below the cap that is every attempt; above it, `count` and `total` describe
	 * the retained window rather than the whole run, and ProviderStats::byOperation() and
	 * ProviderStats::seconds() carry the untruncated figures.
	 *
	 * The percentile is nearest-rank: the smallest sample at or above the 95th position of the
	 * sorted window, with no interpolation between neighbours.
	 *
	 * @param string $operation
	 *   One of ProviderStats::OPERATIONS.
	 *
	 * @return array{count: int, total: float, min: float, max: float, mean: float, p95: float}
	 *   All zeroes when the operation has not been attempted.
	 *
	 * @throws InvalidArgumentException
	 *   When $operation is not a billed verb.
	 */
	public function latency(string $operation): array
	{
		self::assertOperation($operation);

		$samples = $this->samples[$operation] ?? [];

		if ($samples === []) {
			return [
				'count' => 0,
				'total' => 0.0,
				'min' => 0.0,
				'max' => 0.0,
				'mean' => 0.0,
				'p95' => 0.0,
			];
		}

		sort($samples);

		$count = count($samples);
		$total = array_sum($samples);
		$rank = max(0, (int) ceil(0.95 * $count) - 1);

		return [
			'count' => $count,
			'total' => $total,
			'min' => $samples[0],
			'max' => $samples[$count - 1],
			'mean' => $total / $count,
			'p95' => $samples[$rank],
		];
	}

	#endregion

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The window as a plain array for a report table or a JSON response.
	 */
	public function jsonSerialize(): array
	{
		$breakdown = $this->byOperation();
		$latency = [];

		foreach (array_keys($breakdown) as $operation) {
			$figures = $this->latency($operation);

			$latency[$operation] = [
				'count' => $figures['count'],
				'total' => round($figures['total'], 4),
				'min' => round($figures['min'], 4),
				'max' => round($figures['max'], 4),
				'mean' => round($figures['mean'], 4),
				'p95' => round($figures['p95'], 4),
			];
		}

		return [
			'operations' => $this->operations(),
			'classA' => $this->classA(),
			'classB' => $this->classB(),
			'bytes' => $this->bytes(),
			'failures' => $this->failures(),
			'seconds' => round($this->seconds, 4),
			'byOperation' => $breakdown,
			'latency' => $latency,
		];
	}

	/**
	 * Adds one duration to an operation's window, dropping the oldest once it is full.
	 *
	 * @param string $operation
	 *   The operation the duration belongs to.
	 * @param float $seconds
	 *   The duration.
	 */
	private function sample(string $operation, float $seconds): void
	{
		$samples = $this->samples[$operation] ?? [];
		$samples[] = $seconds;

		$overflow = count($samples) - self::LATENCY_SAMPLES;
		if ($overflow > 0) {
			$samples = array_slice($samples, $overflow);
		}

		$this->samples[$operation] = $samples;
	}

	/**
	 * Sums the attempt counts of a set of operations.
	 *
	 * @param list<string> $operations
	 *   The operations to add up.
	 *
	 * @return int
	 *   Their total attempt count.
	 */
	private function countOf(array $operations): int
	{
		$total = 0;

		foreach ($operations as $operation) {
			$total += $this->counts[$operation] ?? 0;
		}

		return $total;
	}

	/**
	 * Guards an operation argument.
	 *
	 * @param string $operation
	 *   The candidate verb.
	 *
	 * @throws InvalidArgumentException
	 *   When it is not one of ProviderStats::OPERATIONS.
	 */
	private static function assertOperation(string $operation): void
	{
		if (in_array($operation, self::OPERATIONS, true)) {
			return;
		}

		throw new InvalidArgumentException(
			sprintf(
				'%s is not a billed operation; expected one of %s',
				$operation === '' ? 'An empty string' : $operation,
				implode(', ', self::OPERATIONS),
			),
		);
	}
}
