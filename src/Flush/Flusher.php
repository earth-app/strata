<?php

declare(strict_types=1);

namespace Drupal\strata\Flush;

use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Journal\FlushPolicy;
use Drupal\strata\Journal\JournalInterface;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Segment\SegmentBuilder;
use Drupal\strata\Segment\SegmentWriter;
use Drupal\strata\Tree\Commit;
use Drupal\strata\Tree\CommitIndex;
use Drupal\strata\Tree\CommitLog;
use Drupal\strata\Tree\RefStore;
use Drupal\strata\Tree\TreeBuilder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seals a window of captured operations into a segment, a tree and a commit.
 *
 * The step that turns capture into a backup. Everything before it is local and cheap; this is where
 * bytes leave the site.
 *
 * The order is fixed by what a crash between two steps would leave behind. Payloads are stored
 * first, because a frame nothing references is collectable garbage while a manifest naming a frame
 * that was never written is a corrupt segment. The segment is written before the tree, the tree
 * before the commit, and the commit before the ref moves, so at every point the objects already
 * written are either referenced or collectable, and never dangling.
 *
 * The journal is trimmed last, after the ref has moved. A flush that dies before the trim leaves
 * the window in place and repeats itself on the next run, producing an identical segment - every
 * object is content-addressed, so a repeat costs a few lookups and writes nothing new. A flush that
 * trimmed first and then died would lose the window outright.
 *
 * @see FlushPolicy
 * @see SegmentBuilder
 * @see CommitLog
 */
final class Flusher
{
	/**
	 * Constructs a flusher.
	 *
	 * @param JournalInterface $journal
	 *   The journal to drain.
	 * @param FlushPolicy $policy
	 *   Decides whether a window is due.
	 * @param ObjectStore $store
	 *   Stores payloads.
	 * @param SegmentWriter $segments
	 *   Writes the segment manifest.
	 * @param TreeBuilder $trees
	 *   Builds the tree describing the site.
	 * @param CommitLog $commits
	 *   Appends the commit and advances the ref.
	 * @param Lease $lease
	 *   Keeps two flushes from overlapping.
	 * @param LoggerInterface $logger
	 *   Records what a flush did, and what it could not do.
	 * @param CommitIndex|null $index
	 *   Mirrors the commit into the local index, or NULL to write only to the store. The index is a
	 *   cache over what the bucket already holds, so a flush that cannot reach it has still
	 *   produced a complete backup and says so.
	 */
	public function __construct(
		private readonly JournalInterface $journal,
		private readonly FlushPolicy $policy,
		private readonly ObjectStore $store,
		private readonly SegmentWriter $segments,
		private readonly TreeBuilder $trees,
		private readonly CommitLog $commits,
		private readonly Lease $lease,
		private readonly LoggerInterface $logger,
		private readonly ?CommitIndex $index = null,
	) {}

	/**
	 * Seals a window if one is due.
	 *
	 * @param bool $force
	 *   TRUE to seal whatever is pending regardless of the policy. Used by a shutdown, an export and
	 *   the `strata:flush` command.
	 * @param string $ref
	 *   Ref to advance.
	 *
	 * @return FlushResult
	 *   What happened.
	 */
	public function flush(bool $force = false, string $ref = RefStore::MAIN): FlushResult
	{
		$pending = $this->journal->pending();

		if ($pending < 1) {
			return FlushResult::skipped('nothing pending');
		}

		$reason = $this->policy->reason(
			$pending,
			$this->journal->pendingBytes(),
			$this->journal->oldest(),
			$this->now(),
		);

		if ($reason === null && !$force) {
			return FlushResult::skipped('no flush bound reached');
		}

		if (!$this->lease->acquire(Lease::FLUSH)) {
			return FlushResult::skipped('another flush holds the lease');
		}

		try {
			return $this->seal($reason ?? 'forced', $ref);
		} catch (Throwable $e) {
			// the window is untouched, so the next run repeats this flush rather than losing it
			$this->logger->error('Strata could not seal a window: %message', [
				'%message' => $e->getMessage(),
			]);

			return FlushResult::skipped(sprintf('failed: %s', $e->getMessage()));
		} finally {
			$this->lease->release(Lease::FLUSH);
		}
	}

	/**
	 * Whether a window is due, without sealing one.
	 *
	 * Read by the status page and by `strata:status`, so it does not take the lease.
	 *
	 * @return bool
	 *   TRUE when a flush would run.
	 */
	public function isDue(): bool
	{
		return $this->policy->shouldFlush(
			$this->journal->pending(),
			$this->journal->pendingBytes(),
			$this->journal->oldest(),
			$this->now(),
		);
	}

	/**
	 * How far behind the store is, in seconds.
	 *
	 * The recovery-point lag: how much of the site's recent history exists only on this server. The
	 * number the timeline shows beside the head.
	 *
	 * @return float
	 *   Seconds since the oldest unsealed operation, or 0.0 when nothing is pending.
	 */
	public function lag(): float
	{
		$oldest = $this->journal->oldest();

		if ($oldest === null) {
			return 0.0;
		}

		return max(0.0, ($this->now() - $oldest) / JournalOp::MICROSECONDS_PER_SECOND);
	}

	/**
	 * Does the sealing, with the lease already held.
	 *
	 * @param string $reason
	 *   Which bound fired.
	 * @param string $ref
	 *   Ref to advance.
	 *
	 * @return FlushResult
	 *   What happened.
	 */
	private function seal(string $reason, string $ref): FlushResult
	{
		$started = microtime(true);
		$window = $this->journal->read($this->policy->maxOps() ?: 5000);

		if ($window === []) {
			return FlushResult::skipped('nothing pending');
		}

		$builder = new SegmentBuilder($this->store);

		foreach ($window as $entry) {
			$builder->add($entry['operation'], $entry['payload']);
		}

		$manifest = $builder->build();

		if ($manifest === null) {
			return FlushResult::skipped('nothing pending');
		}

		$segmentKey = $this->segments->write($manifest);

		$head = $this->commits->head($ref);
		$subjects = [];

		foreach ($manifest->operations as $operation) {
			$frames = $manifest->payloadFor($operation);
			$path = $operation->realm->value . '/' . $operation->subject;

			// an operation with no payload removes the subject from the tree
			$subjects[$path] =
				$frames === [] ? null : ['frames' => $frames, 'size' => $operation->payloadLength];
		}

		$tree =
			$head === null
				? $this->trees->build(
					array_filter($subjects, static fn(?array $s): bool => $s !== null),
				)
				: $this->trees->rebuild($head->tree, $subjects);

		$commit = new Commit(
			$tree,
			$head?->id(),
			$manifest->lastMicrotime,
			$manifest->summary(),
			$this->actorOf($manifest->operations),
			$manifest->count(),
			$manifest->rawBytes,
			0,
			0,
			$head === null,
			['segment' => $segmentKey, 'reason' => $reason],
		);

		$commitId = $this->commits->append($commit, $ref);

		$this->index?->record($commitId, $commit);

		// last, and only once the ref has moved: a repeat is cheap, a lost window is not
		$trimmed = $this->journal->trim($manifest->lastSequence);

		$result = new FlushResult(
			true,
			$reason,
			$segmentKey,
			$commitId,
			count($window),
			$manifest->count(),
			$manifest->rawBytes,
			$trimmed,
			microtime(true) - $started,
		);

		$this->logger->info('Strata %summary', ['%summary' => $result->summary()]);

		return $result;
	}

	/**
	 * The user a commit is attributed to.
	 *
	 * A window covering one person's work is attributed to them; one covering several, or covering
	 * unattended work, is attributed to nobody rather than to whoever happened to be last.
	 *
	 * @param list<JournalOp> $operations
	 *   The operations the commit covers.
	 *
	 * @return int|null
	 *   A Drupal user id, or NULL.
	 */
	private function actorOf(array $operations): ?int
	{
		$actors = [];

		foreach ($operations as $operation) {
			if ($operation->actor !== null) {
				$actors[$operation->actor] = true;
			}
		}

		return count($actors) === 1 ? (int) array_key_first($actors) : null;
	}

	/**
	 * The current time in unix microseconds.
	 *
	 * @return int
	 *   Microseconds since the epoch.
	 */
	private function now(): int
	{
		return (int) round(microtime(true) * JournalOp::MICROSECONDS_PER_SECOND);
	}
}
