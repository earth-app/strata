<?php

declare(strict_types=1);

namespace Drupal\strata\Tier;

use JsonSerializable;

/**
 * What one migration pass moved, and what it would not move.
 *
 * The refusals are the part to read. A pass that copies nothing because every candidate still has a
 * delta child in a nearer tier is working correctly, and a report that showed only the zero would
 * look like a broken schedule.
 *
 * @see TierMigrator
 */
final class TierMigrationReport implements JsonSerializable
{
	/**
	 * Constructs a report.
	 *
	 * @param int $examined
	 *   Objects considered.
	 * @param int $copied
	 *   Objects written into a further tier and verified there.
	 * @param int $moved
	 *   Objects whose nearer copy was then removed. Always at most $copied.
	 * @param int $bytes
	 *   Bytes copied, counted once per object rather than once per request.
	 * @param array<int, int> $intoTier
	 *   Tier index keyed to how many objects were copied into it.
	 * @param list<string> $refused
	 *   One line per candidate that was deliberately left where it is, with the reason.
	 * @param list<string> $problems
	 *   One line per candidate that failed, with the reason. A verified copy that could not be made
	 *   is a problem; a copy that was not attempted is a refusal.
	 * @param float $seconds
	 *   How long the pass took.
	 */
	public function __construct(
		public readonly int $examined = 0,
		public readonly int $copied = 0,
		public readonly int $moved = 0,
		public readonly int $bytes = 0,
		public readonly array $intoTier = [],
		public readonly array $refused = [],
		public readonly array $problems = [],
		public readonly float $seconds = 0.0,
	) {}

	/**
	 * Whether every candidate the pass looked at was handled without a failure.
	 *
	 * A refusal is not a failure; it is the pass declining to break a delta chain or to touch a tier
	 * it could not reach.
	 *
	 * @return bool
	 *   TRUE when nothing failed.
	 */
	public function isClean(): bool
	{
		return $this->problems === [];
	}

	/**
	 * A one-line summary for a log entry or a command's output.
	 *
	 * @return string
	 *   The summary.
	 */
	public function summary(): string
	{
		$summary = sprintf(
			'tiering examined %d objects, copied %d, moved %d, %d bytes in %.2fs',
			$this->examined,
			$this->copied,
			$this->moved,
			$this->bytes,
			$this->seconds,
		);

		if ($this->refused !== []) {
			$summary .= sprintf('; %d left in place', count($this->refused));
		}

		return $this->isClean()
			? $summary
			: $summary . sprintf('; %d failed', count($this->problems));
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The report as a plain array.
	 */
	public function jsonSerialize(): array
	{
		return [
			'examined' => $this->examined,
			'copied' => $this->copied,
			'moved' => $this->moved,
			'bytes' => $this->bytes,
			'intoTier' => $this->intoTier,
			'refused' => $this->refused,
			'problems' => $this->problems,
			'seconds' => round($this->seconds, 4),
		];
	}
}
