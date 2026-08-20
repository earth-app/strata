<?php

declare(strict_types=1);

namespace Drupal\strata\Journal;

use RuntimeException;

/**
 * The append-only log a capture writes to and a flush drains.
 *
 * Capture happens inside a web request, so the write here has to be cheap: an append and nothing
 * else. Compression, encryption, framing and the network all happen later, in the flush, off the
 * request path. The semantic capture that produces an operation measures about 7 microseconds, and
 * the append is the only other cost on the request.
 *
 * A journal is durable but not permanent. Once a flush has sealed a window into a segment the
 * operations are redundant and are trimmed. What makes it necessary rather than a buffer is that a
 * request can end, or crash, between the capture and the flush.
 *
 * @see JournalOp
 * @see FlushPolicy
 */
interface JournalInterface
{
	/**
	 * Appends an operation and its payload.
	 *
	 * The sequence on the supplied operation is ignored; the journal assigns it, because only the
	 * journal can order two concurrent requests.
	 *
	 * @param JournalOp $operation
	 *   The operation to append.
	 * @param string|null $payload
	 *   The value the operation captured, or NULL for an operation that carries none.
	 *
	 * @return JournalOp
	 *   The operation as appended, carrying its assigned sequence.
	 *
	 * @throws RuntimeException
	 *   When the append fails.
	 */
	public function append(JournalOp $operation, ?string $payload = null): JournalOp;

	/**
	 * Reads a window of operations in capture order, oldest first.
	 *
	 * @param int $limit
	 *   Most operations to return.
	 *
	 * @return list<array{operation: JournalOp, payload: string|null}>
	 *   Operations paired with their payloads.
	 *
	 * @throws RuntimeException
	 *   When the read fails.
	 */
	public function read(int $limit = 5000): array;

	/**
	 * Removes every operation up to and including a sequence.
	 *
	 * Called after a flush has sealed a segment. Trimming is separate from reading so a flush that
	 * fails leaves the window intact and can be retried.
	 *
	 * @param int $throughSequence
	 *   The highest sequence to remove.
	 *
	 * @return int
	 *   How many operations were removed.
	 *
	 * @throws RuntimeException
	 *   When the trim fails.
	 */
	public function trim(int $throughSequence): int;

	/**
	 * How many operations are waiting.
	 *
	 * @return int
	 *   The pending count.
	 */
	public function pending(): int;

	/**
	 * Bytes of payload waiting.
	 *
	 * @return int
	 *   The pending payload total.
	 */
	public function pendingBytes(): int;

	/**
	 * Unix microseconds of the oldest waiting operation.
	 *
	 * @return int|null
	 *   The microtime, or NULL when nothing is waiting.
	 */
	public function oldest(): ?int;

	/**
	 * Removes everything without flushing it.
	 *
	 * @return int
	 *   How many operations were removed.
	 */
	public function clear(): int;
}
