<?php

declare(strict_types=1);

namespace Drupal\strata\Delta;

use Drupal\strata\Cas\FrameIndexInterface;
use Drupal\strata\Cas\FrameRecord;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\HealthLedgerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Breaks delta chains that have grown too long by rewriting a link standalone.
 *
 * Delta coding trades storage for a dependency. A frame coded against the version before it is 63.70x
 * smaller on the rewrite class, and reading it reads its parent, and that parent's parent. The chain
 * is what makes the saving and it is also the whole risk: a long chain multiplies read cost and turns
 * one lost frame into the loss of everything derived from it.
 *
 * So a chain is capped, and this is what enforces the cap after the fact. The writer refuses to
 * extend a chain past the limit, which keeps new frames in bounds; a chain can still be over the limit
 * because the limit was lowered, or because a chain was built by an older release. Rewriting the
 * deepest link standalone cuts the chain there: everything above it keeps its parent, everything below
 * it becomes reachable in fewer reads.
 *
 * **Nothing is deleted.** The rewritten frame keeps its content address, because the address is the
 * digest of the decoded bytes and those are unchanged - only the encoding changes. Every frame that
 * named it still names it, and the old bytes are superseded rather than orphaned.
 *
 * @see ChainDepthPolicy
 * @see DeltaCodec
 */
final class Reanchorer
{
	/**
	 * Code raised when a chain is found past the cap.
	 */
	public const TOO_DEEP = 'delta.chain_too_deep';

	/**
	 * Frames rewritten in one pass by default.
	 *
	 * Each one is a read of its whole chain and a write, so a pass is bounded and the next cron run
	 * continues. A cap that let one pass rewrite an unbounded number of frames would be the slowest
	 * thing in the cron queue on the one site that needed it most.
	 */
	public const DEFAULT_BUDGET = 200;

	/**
	 * Constructs a reanchorer.
	 *
	 * @param FrameIndexInterface $index
	 *   Where chain depths are read from and written back.
	 * @param ObjectStore $store
	 *   Reads a frame through its chain and writes the standalone replacement.
	 * @param ChainDepthPolicy $policy
	 *   The cap being enforced.
	 * @param HealthLedgerInterface $ledger
	 *   Where a chain past the cap is recorded.
	 * @param LoggerInterface $logger
	 *   Records what a pass did.
	 */
	public function __construct(
		private readonly FrameIndexInterface $index,
		private readonly ObjectStore $store,
		private readonly ChainDepthPolicy $policy,
		private readonly HealthLedgerInterface $ledger,
		private readonly LoggerInterface $logger,
	) {}

	/**
	 * Rewrites the frames whose chains are past the cap.
	 *
	 * @param int $budget
	 *   Most frames to rewrite, or zero for the default.
	 *
	 * @return array{examined: int, reanchored: int, deepest: int, saved: int, problems: list<string>}
	 *   How many frames were looked at, how many were rewritten, the deepest chain found, how many
	 *   stored bytes the rewrites cost or saved, and anything that could not be done.
	 */
	public function run(int $budget = 0): array
	{
		$budget = $budget > 0 ? $budget : self::DEFAULT_BUDGET;
		$examined = 0;
		$reanchored = 0;
		$deepest = 0;
		$saved = 0;
		$problems = [];

		foreach ($this->overDeep($budget) as $record) {
			$examined++;
			$deepest = max($deepest, $record->deltaDepth);

			try {
				$saved += $this->reanchor($record);
				$reanchored++;
			} catch (Throwable $error) {
				$problems[] = sprintf(
					'%s: %s',
					Hash::abbreviate($record->hash),
					$error->getMessage(),
				);
			}
		}

		if ($deepest > 0) {
			$this->ledger->record(
				new Finding(
					self::TOO_DEEP,
					$reanchored === $examined ? Finding::INFO : Finding::WARN,
					'delta',
					sprintf(
						'%d of %d chains past the cap of %d were re-anchored; deepest was %d',
						$reanchored,
						$examined,
						$this->policy->maxDepth(),
						$deepest,
					),
				),
			);
		}

		$this->logger->info(
			'Strata re-anchored %reanchored of %examined delta chains, deepest %deepest',
			['%reanchored' => $reanchored, '%examined' => $examined, '%deepest' => $deepest],
		);

		return [
			'examined' => $examined,
			'reanchored' => $reanchored,
			'deepest' => $deepest,
			'saved' => $saved,
			'problems' => $problems,
		];
	}

	/**
	 * Rewrites one frame so it no longer depends on a parent.
	 *
	 * @param FrameRecord $record
	 *   The frame to re-anchor.
	 *
	 * @return int
	 *   Stored bytes saved, which is negative when the standalone form is larger - and it usually is.
	 *   The point of a re-anchor is bounding the read, not saving bytes, and reporting the cost
	 *   honestly is what lets an operator judge the cap.
	 *
	 * @throws \RuntimeException
	 *   When the frame cannot be read through its chain, or the rewrite cannot be stored.
	 */
	public function reanchor(FrameRecord $record): int
	{
		$bytes = $this->store->frame($record->hash);

		if ($bytes === null) {
			throw new \RuntimeException('the index does not know this frame');
		}

		$before = $record->storedSize;

		// the address is the digest of the decoded bytes, so rewriting keeps it and every reference
		$this->index->forget([$record->hash]);
		$this->store->write($bytes);
		$this->store->commit();

		$after = $this->index->get($record->hash);

		if ($after === null) {
			throw new \RuntimeException('the rewritten frame was not indexed');
		}
		if ($after->isDelta()) {
			throw new \RuntimeException('the rewritten frame is still a delta');
		}

		$this->index->reference($record->hash, max(0, $record->references - 1));

		return $before - $after->storedSize;
	}

	/**
	 * Frames whose chains are past the cap, deepest first.
	 *
	 * @param int $limit
	 *   Most records to return.
	 *
	 * @return list<FrameRecord>
	 *   The records.
	 */
	public function overDeep(int $limit): array
	{
		return $this->index->deepestChains($this->policy->maxDepth(), $limit);
	}

	/**
	 * The deepest chain in the index.
	 *
	 * What the health dashboard shows next to the cap, so an operator can see how close the store is
	 * running to it rather than only hearing about it once it is past.
	 *
	 * @return int
	 *   The depth, zero when nothing is delta coded.
	 */
	public function deepest(): int
	{
		$found = $this->index->deepestChains(0, 1);

		return $found === [] ? 0 : $found[0]->deltaDepth;
	}
}
