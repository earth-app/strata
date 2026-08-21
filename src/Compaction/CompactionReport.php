<?php

declare(strict_types=1);

namespace Drupal\strata\Compaction;

use JsonSerializable;

/**
 * What one compaction pass did.
 *
 * Split from the prune receipt on purpose. Recompression rewrites how bytes are stored and
 * destroys nothing, so it reports totals; a prune destroys restore targets, so it produces a
 * document. A pass that recompressed and then declined to prune is a success with a refusal in it,
 * and the two halves have to be readable separately.
 *
 * @see Compactor
 * @see PruneReceipt
 */
final class CompactionReport implements JsonSerializable
{
	/**
	 * Constructs a report.
	 *
	 * @param int $packs
	 *   Packs rewritten at the dense setting.
	 * @param int $frames
	 *   Frames those packs carried.
	 * @param int $bytesBefore
	 *   Stored bytes the rewritten packs occupied before.
	 * @param int $bytesAfter
	 *   Stored bytes they occupy now.
	 * @param int $skipped
	 *   Packs already dense enough to leave alone.
	 * @param PruneReceipt|null $prune
	 *   What the prune half of the pass did, or NULL when no prune was asked for.
	 * @param list<string> $problems
	 *   One line per object the pass could not read.
	 * @param float $seconds
	 *   How long it took.
	 */
	public function __construct(
		public readonly int $packs = 0,
		public readonly int $frames = 0,
		public readonly int $bytesBefore = 0,
		public readonly int $bytesAfter = 0,
		public readonly int $skipped = 0,
		public readonly ?PruneReceipt $prune = null,
		public readonly array $problems = [],
		public readonly float $seconds = 0.0,
	) {}

	/**
	 * Whether the pass read everything it reached for.
	 *
	 * @return bool
	 *   TRUE when nothing was skipped for being unreadable.
	 */
	public function isClean(): bool
	{
		return $this->problems === [];
	}

	/**
	 * Stored bytes the pass freed.
	 *
	 * @return int
	 *   The difference between the before and after totals.
	 */
	public function saved(): int
	{
		return max(0, $this->bytesBefore - $this->bytesAfter);
	}

	/**
	 * How much denser the rewritten packs became.
	 *
	 * @return float
	 *   Bytes before divided by bytes after, or 1.0 when nothing was rewritten.
	 */
	public function gain(): float
	{
		return $this->bytesAfter > 0 ? $this->bytesBefore / $this->bytesAfter : 1.0;
	}

	/**
	 * A one-line summary for a log entry or a command's output.
	 *
	 * @return string
	 *   The summary.
	 */
	public function summary(): string
	{
		$parts = [
			sprintf(
				'recompressed %d packs holding %d frames, saving %s in %.2fs',
				$this->packs,
				$this->frames,
				self::humanBytes($this->saved()),
				$this->seconds,
			),
		];

		if ($this->skipped > 0) {
			$parts[] = sprintf('%d packs already dense', $this->skipped);
		}
		if ($this->prune !== null) {
			$parts[] = $this->prune->summary();
		}
		if (!$this->isClean()) {
			$parts[] = sprintf('%d objects unreadable', count($this->problems));
		}

		return implode('; ', $parts);
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
			'packs' => $this->packs,
			'frames' => $this->frames,
			'bytesBefore' => $this->bytesBefore,
			'bytesAfter' => $this->bytesAfter,
			'saved' => $this->saved(),
			'skipped' => $this->skipped,
			'gain' => round($this->gain(), 4),
			'prune' => $this->prune,
			'problems' => $this->problems,
			'seconds' => round($this->seconds, 4),
			'clean' => $this->isClean(),
		];
	}

	/**
	 * Renders a byte count at a readable scale.
	 *
	 * @param int $bytes
	 *   The count.
	 *
	 * @return string
	 *   For example "4.2 MiB".
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
