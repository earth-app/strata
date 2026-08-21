<?php

declare(strict_types=1);

namespace Drupal\strata\Compaction;

use JsonSerializable;

/**
 * The written record of what a prune removed, and what that cost.
 *
 * A prune is the only operation Strata performs that destroys a restore target, so it is the one
 * operation that produces a document rather than a log line. The receipt exists to be read before
 * the prune runs - in dry-run form the numbers are identical and nothing is deleted - because "this
 * will free 4.2 GB" and "this will remove your only restore point older than a week" are the same
 * sentence read two ways, and only one of them is the decision the operator is making.
 *
 * A receipt that refused carries the reason. Nothing was removed in that case, including anything
 * that was individually safe: a prune either completes as described or does nothing, since a
 * partially applied prune leaves history in a state neither the receipt nor the operator describes.
 *
 * @see Reachability
 * @see Compactor
 */
final class PruneReceipt implements JsonSerializable
{
	/**
	 * Constructs a receipt.
	 *
	 * @param bool $applied
	 *   FALSE for a dry run or a refusal; TRUE when the objects were actually removed.
	 * @param list<string> $frames
	 *   Frame addresses removed.
	 * @param list<string> $objects
	 *   Object keys removed, which is fewer than the frames when frames shared a pack.
	 * @param int $bytes
	 *   Stored bytes freed.
	 * @param list<string> $commits
	 *   Commit ids that stopped being restore targets.
	 * @param list<string> $kept
	 *   One line per candidate that was NOT removed, naming what held it alive. This is the half of
	 *   the receipt that proves the three reachability classes were consulted.
	 * @param string|null $refused
	 *   Why nothing was removed, or NULL when the prune ran.
	 * @param float $seconds
	 *   How long it took.
	 */
	public function __construct(
		public readonly bool $applied = false,
		public readonly array $frames = [],
		public readonly array $objects = [],
		public readonly int $bytes = 0,
		public readonly array $commits = [],
		public readonly array $kept = [],
		public readonly ?string $refused = null,
		public readonly float $seconds = 0.0,
	) {}

	/**
	 * A receipt for a prune that would not run.
	 *
	 * @param string $reason
	 *   Why it refused.
	 * @param list<string> $kept
	 *   What was examined and held back.
	 *
	 * @return self
	 *   The receipt.
	 */
	public static function refuse(string $reason, array $kept = []): self
	{
		return new self(false, [], [], 0, [], $kept, $reason);
	}

	/**
	 * Whether the prune declined to run.
	 *
	 * @return bool
	 *   TRUE when it refused.
	 */
	public function wasRefused(): bool
	{
		return $this->refused !== null;
	}

	/**
	 * Whether the prune found nothing to do.
	 *
	 * @return bool
	 *   TRUE when there were no candidates.
	 */
	public function isEmpty(): bool
	{
		return $this->frames === [] && $this->commits === [];
	}

	/**
	 * How many candidates were held back by reachability.
	 *
	 * @return int
	 *   The count.
	 */
	public function keptCount(): int
	{
		return count($this->kept);
	}

	/**
	 * A one-line summary for a log entry or a command's output.
	 *
	 * @return string
	 *   The summary.
	 */
	public function summary(): string
	{
		if ($this->wasRefused()) {
			return sprintf('prune refused: %s', (string) $this->refused);
		}
		if ($this->isEmpty()) {
			return 'nothing to prune';
		}

		return sprintf(
			'%s %d frames in %d objects, %s freed, %d restore points removed, %d held back',
			$this->applied ? 'pruned' : 'would prune',
			count($this->frames),
			count($this->objects),
			self::humanBytes($this->bytes),
			count($this->commits),
			$this->keptCount(),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The receipt as a plain array, which is the form written to the audit log.
	 */
	public function jsonSerialize(): array
	{
		return [
			'applied' => $this->applied,
			'refused' => $this->refused,
			'frames' => $this->frames,
			'objects' => $this->objects,
			'bytes' => $this->bytes,
			'commits' => $this->commits,
			'kept' => $this->kept,
			'seconds' => round($this->seconds, 4),
		];
	}

	/**
	 * Renders a byte count at a readable scale.
	 *
	 * @param int $bytes
	 *   The count.
	 *
	 * @return string
	 *   For example "4.2 GiB".
	 */
	private static function humanBytes(int $bytes): string
	{
		$units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
		$value = (float) $bytes;
		$unit = 0;

		while ($value >= 1024.0 && $unit < count($units) - 1) {
			$value /= 1024.0;
			$unit++;
		}

		return sprintf($unit === 0 ? '%d %s' : '%.1f %s', $value, $units[$unit]);
	}
}
