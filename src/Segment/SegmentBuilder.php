<?php

declare(strict_types=1);

namespace Drupal\strata\Segment;

use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Journal\JournalOp;
use InvalidArgumentException;
use RuntimeException;

/**
 * Turns a window of journal operations into a manifest, storing each payload as it goes.
 *
 * The order is fixed and each step depends on the last. Operations are collapsed first, so a
 * subject touched ten times in the window is stored once. Payloads then go through the object
 * store, which deduplicates against everything already held, so an unchanged value costs a lookup
 * rather than an upload. Only what survives both is written.
 *
 * A payload is supplied by the caller rather than read here, because the value is already in memory
 * at capture time. Nothing in this class reads Drupal.
 *
 * @see Collapser
 * @see SegmentManifest
 * @see ObjectStore
 */
final class SegmentBuilder
{
	/**
	 * Operations added so far, keyed by nothing; order is preserved.
	 *
	 * @var list<JournalOp>
	 */
	private array $operations = [];

	/**
	 * Payload bytes, keyed by operation key.
	 *
	 * @var array<string, string>
	 */
	private array $payloads = [];

	/**
	 * Constructs a builder.
	 *
	 * @param ObjectStore $store
	 *   Where payloads are stored.
	 * @param int $level
	 *   Compaction level to stamp on the manifest.
	 * @param callable|null $previous
	 *   Given a subject path, returns the frame map of that subject's previous version as a
	 *   `list<string>`, or an empty array when there is none. Supplied rather than looked up here so
	 *   this class keeps knowing nothing about where history lives. Without it every payload is stored
	 *   standalone, which costs the delta-coding gain and nothing else.
	 */
	public function __construct(
		private readonly ObjectStore $store,
		private readonly int $level = 0,
		private readonly mixed $previous = null,
	) {
		if ($level < 0) {
			throw new InvalidArgumentException('A compaction level cannot be negative');
		}
		if ($previous !== null && !is_callable($previous)) {
			throw new InvalidArgumentException('A previous-version lookup must be callable');
		}
	}

	/**
	 * Adds an operation and the payload it captured.
	 *
	 * Adding the same operation key twice is expected: that is what collapsing exists for. The
	 * later payload wins, matching the surviving operation the collapser produces.
	 *
	 * @param JournalOp $operation
	 *   The operation.
	 * @param string|null $payload
	 *   The value the operation captured, or NULL for an operation that carries none.
	 *
	 * @return $this
	 *   The builder, for chaining.
	 */
	public function add(JournalOp $operation, ?string $payload = null): self
	{
		$this->operations[] = $operation;

		if ($payload !== null && $payload !== '') {
			$this->payloads[$operation->key()] = $payload;
		}

		return $this;
	}

	/**
	 * The frame map of one subject's previous version.
	 *
	 * @param JournalOp $operation
	 *   The operation being stored.
	 *
	 * @return list<string>
	 *   Frame addresses, empty when there is no previous version or no lookup was supplied.
	 */
	private function previousMap(JournalOp $operation): array
	{
		if ($this->previous === null) {
			return [];
		}

		/** @var mixed $map */
		$map = ($this->previous)($operation->realm->value . '/' . $operation->subject);

		if (!is_array($map)) {
			return [];
		}

		$frames = [];

		foreach ($map as $frame) {
			$frames[] = (string) $frame;
		}

		return $frames;
	}

	/**
	 * How many operations have been added, before collapsing.
	 *
	 * @return int
	 *   The count.
	 */
	public function count(): int
	{
		return count($this->operations);
	}

	/**
	 * Whether anything has been added.
	 *
	 * @return bool
	 *   TRUE when nothing has been added.
	 */
	public function isEmpty(): bool
	{
		return $this->operations === [];
	}

	/**
	 * Collapses, stores the surviving payloads, and produces the manifest.
	 *
	 * Clears the builder, so the same instance can start the next window.
	 *
	 * @return SegmentManifest|null
	 *   The manifest, or NULL when nothing was added.
	 *
	 * @throws RuntimeException
	 *   When storing a payload fails.
	 */
	public function build(): ?SegmentManifest
	{
		if ($this->operations === []) {
			return null;
		}

		$before = $this->operations;
		$collapsed = Collapser::collapse($before);

		$maps = [];
		$rawBytes = 0;

		foreach ($collapsed as $operation) {
			$key = $operation->key();
			$payload = $this->payloads[$key] ?? null;

			if ($payload === null) {
				continue;
			}

			$maps[$key] = $this->store->write($payload, $this->previousMap($operation));
			$rawBytes += strlen($payload);
		}

		$this->store->commit();

		$first = $collapsed[0];
		$last = $collapsed[count($collapsed) - 1];

		$manifest = new SegmentManifest(
			$this->level,
			$first->sequence,
			$last->sequence,
			$first->microtime,
			$last->microtime,
			$collapsed,
			$maps,
			count($before),
			$rawBytes,
		);

		$this->reset();

		return $manifest;
	}

	/**
	 * Discards everything added without storing it.
	 *
	 * Used when a flush fails and the window will be rebuilt from the journal rather than retried
	 * from memory.
	 */
	public function reset(): void
	{
		$this->operations = [];
		$this->payloads = [];
	}
}
