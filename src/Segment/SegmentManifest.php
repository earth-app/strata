<?php

declare(strict_types=1);

namespace Drupal\strata\Segment;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Journal\JournalOp;
use InvalidArgumentException;
use JsonSerializable;

/**
 * The ordered record of what one flush captured.
 *
 * A segment is the unit a flush writes and a replay reads. It holds the operations in sequence
 * order, the frame map that reconstructs each operation's payload, and the counters the UI reports.
 *
 * The format carries a version. A segment written by one release must stay readable by every later
 * one, because the whole point of the store is that a restore reaches back further than the code
 * does; a reader that meets a version it does not understand says so rather than guessing at the
 * layout.
 *
 * @see SegmentBuilder
 * @see SegmentWriter
 * @see SegmentReader
 */
final class SegmentManifest implements JsonSerializable
{
	/**
	 * Format version written into every manifest.
	 */
	public const VERSION = 1;

	/**
	 * Constructs a manifest.
	 *
	 * @param int $level
	 *   Compaction level; 0 for a freshly flushed segment, higher after a rollup.
	 * @param int $firstSequence
	 *   Journal sequence of the earliest operation.
	 * @param int $lastSequence
	 *   Journal sequence of the latest operation.
	 * @param int $firstMicrotime
	 *   Unix microseconds of the earliest operation.
	 * @param int $lastMicrotime
	 *   Unix microseconds of the latest operation.
	 * @param list<JournalOp> $operations
	 *   The operations, in sequence order.
	 * @param array<string, list<string>> $payloads
	 *   Operation key keyed to the frame map that reconstructs its payload. An operation with no
	 *   payload, such as a delete, has no entry.
	 * @param int $collapsedFrom
	 *   How many operations were captured before collapsing produced this set.
	 * @param int $rawBytes
	 *   Decoded payload bytes the operations describe.
	 * @param int $version
	 *   Manifest format version.
	 *
	 * @throws InvalidArgumentException
	 *   When the sequence or time range is inverted, a count is negative, or a payload map names an
	 *   operation the manifest does not carry.
	 */
	public function __construct(
		public readonly int $level,
		public readonly int $firstSequence,
		public readonly int $lastSequence,
		public readonly int $firstMicrotime,
		public readonly int $lastMicrotime,
		public readonly array $operations,
		public readonly array $payloads = [],
		public readonly int $collapsedFrom = 0,
		public readonly int $rawBytes = 0,
		public readonly int $version = self::VERSION,
	) {
		if ($level < 0 || $collapsedFrom < 0 || $rawBytes < 0 || $version < 1) {
			throw new InvalidArgumentException('A segment manifest count cannot be negative');
		}
		if ($operations !== [] && $lastSequence < $firstSequence) {
			throw new InvalidArgumentException(
				sprintf(
					'A segment cannot end at sequence %d and begin at %d',
					$lastSequence,
					$firstSequence,
				),
			);
		}
		if ($operations !== [] && $lastMicrotime < $firstMicrotime) {
			throw new InvalidArgumentException('A segment cannot end before it begins');
		}

		$keys = [];
		foreach ($operations as $operation) {
			$keys[$operation->key()] = true;
		}

		foreach (array_keys($payloads) as $key) {
			if (!isset($keys[$key])) {
				throw new InvalidArgumentException(
					sprintf('A payload names "%s", which this segment does not carry', $key),
				);
			}
		}
		foreach ($payloads as $key => $map) {
			foreach ($map as $frame) {
				if (!Hash::isValid($frame)) {
					throw new InvalidArgumentException(
						sprintf('Payload "%s" names a frame that is not a valid digest', $key),
					);
				}
			}
		}
	}

	/**
	 * How many operations the segment carries.
	 *
	 * @return int
	 *   The operation count.
	 */
	public function count(): int
	{
		return count($this->operations);
	}

	/**
	 * Whether the segment carries nothing.
	 *
	 * @return bool
	 *   TRUE when there are no operations.
	 */
	public function isEmpty(): bool
	{
		return $this->operations === [];
	}

	/**
	 * How long the segment covers, in seconds.
	 *
	 * @return float
	 *   Seconds between the earliest and latest operation.
	 */
	public function duration(): float
	{
		return ($this->lastMicrotime - $this->firstMicrotime) / JournalOp::MICROSECONDS_PER_SECOND;
	}

	/**
	 * What collapsing removed.
	 *
	 * @return int
	 *   Operations dropped, never below zero.
	 */
	public function collapsed(): int
	{
		return max(0, $this->collapsedFrom - $this->count());
	}

	/**
	 * The frame map for one operation.
	 *
	 * @param JournalOp $operation
	 *   The operation.
	 *
	 * @return list<string>
	 *   Frame content addresses, or an empty list when the operation carries no payload.
	 */
	public function payloadFor(JournalOp $operation): array
	{
		return $this->payloads[$operation->key()] ?? [];
	}

	/**
	 * Every distinct frame the segment references.
	 *
	 * A prune counts references through this, so a frame named by two operations is counted twice
	 * rather than once.
	 *
	 * @return list<string>
	 *   Frame content addresses, in first-reference order, with duplicates removed.
	 */
	public function frames(): array
	{
		$frames = [];

		foreach ($this->payloads as $map) {
			foreach ($map as $frame) {
				$frames[$frame] = true;
			}
		}

		return array_keys($frames);
	}

	/**
	 * Operations grouped by realm.
	 *
	 * @return array<string, list<JournalOp>>
	 *   Realm value keyed to its operations, in sequence order.
	 */
	public function byRealm(): array
	{
		$grouped = [];

		foreach ($this->operations as $operation) {
			$grouped[$operation->realm->value][] = $operation;
		}

		return $grouped;
	}

	/**
	 * A one-line summary for a commit label.
	 *
	 * @return string
	 *   Something such as "12 entity, 3 config".
	 */
	public function summary(): string
	{
		$parts = [];

		foreach ($this->byRealm() as $realm => $operations) {
			$parts[] = sprintf('%d %s', count($operations), $realm);
		}

		return $parts === [] ? 'no operations' : implode(', ', $parts);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The manifest as a plain array.
	 */
	public function jsonSerialize(): array
	{
		return [
			'version' => $this->version,
			'level' => $this->level,
			'firstSequence' => $this->firstSequence,
			'lastSequence' => $this->lastSequence,
			'firstMicrotime' => $this->firstMicrotime,
			'lastMicrotime' => $this->lastMicrotime,
			'collapsedFrom' => $this->collapsedFrom,
			'rawBytes' => $this->rawBytes,
			'operations' => array_map(
				static fn(JournalOp $op): array => $op->jsonSerialize(),
				$this->operations,
			),
			'payloads' => $this->payloads,
		];
	}

	/**
	 * Rebuilds a manifest from its serialized form.
	 *
	 * @param array<string, mixed> $data
	 *   The array produced by SegmentManifest::jsonSerialize().
	 *
	 * @return self
	 *   The manifest.
	 *
	 * @throws InvalidArgumentException
	 *   When the version is newer than this release understands, or the manifest is incoherent.
	 */
	public static function fromArray(array $data): self
	{
		$version = (int) ($data['version'] ?? 0);

		if ($version < 1 || $version > self::VERSION) {
			throw new InvalidArgumentException(
				sprintf(
					'Segment manifest version %d cannot be read by this release, which understands up to %d',
					$version,
					self::VERSION,
				),
			);
		}

		/** @var list<array<string, mixed>> $rawOperations */
		$rawOperations = $data['operations'] ?? [];
		/** @var array<string, list<string>> $payloads */
		$payloads = $data['payloads'] ?? [];

		return new self(
			(int) ($data['level'] ?? 0),
			(int) ($data['firstSequence'] ?? 0),
			(int) ($data['lastSequence'] ?? 0),
			(int) ($data['firstMicrotime'] ?? 0),
			(int) ($data['lastMicrotime'] ?? 0),
			array_map(static fn(array $op): JournalOp => JournalOp::fromArray($op), $rawOperations),
			$payloads,
			(int) ($data['collapsedFrom'] ?? 0),
			(int) ($data['rawBytes'] ?? 0),
			$version,
		);
	}
}
