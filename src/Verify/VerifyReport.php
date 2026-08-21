<?php

declare(strict_types=1);

namespace Drupal\strata\Verify;

use Drupal\strata\Health\Finding;
use JsonSerializable;

/**
 * What one verify pass found.
 *
 * Counted rather than described: a pass over a year of history touches millions of frames, so the
 * report holds totals plus the findings, and the findings are already clamped by Finding itself.
 *
 * A pass that read everything and found nothing is the only clean result. Anything else names what
 * it could not read, which is what makes "the backups work" a measured claim rather than an
 * assumption.
 *
 * @see Verifier
 */
final class VerifyReport implements JsonSerializable
{
	/**
	 * Constructs a report.
	 *
	 * @param int $commits
	 *   Commits walked.
	 * @param int $trees
	 *   Tree nodes read.
	 * @param int $frames
	 *   Frames fetched and decoded.
	 * @param int $segments
	 *   Segment manifests read.
	 * @param int $bytes
	 *   Decoded bytes verified.
	 * @param list<Finding> $findings
	 *   What went wrong, in the order it was found.
	 * @param float $seconds
	 *   How long the pass took.
	 * @param bool $complete
	 *   FALSE when the pass stopped at a limit rather than reaching the root of history, so a clean
	 *   result covers only what it walked.
	 */
	public function __construct(
		public readonly int $commits = 0,
		public readonly int $trees = 0,
		public readonly int $frames = 0,
		public readonly int $segments = 0,
		public readonly int $bytes = 0,
		public readonly array $findings = [],
		public readonly float $seconds = 0.0,
		public readonly bool $complete = true,
	) {}

	/**
	 * Whether everything the pass touched read back correctly.
	 *
	 * @return bool
	 *   TRUE when nothing was found.
	 */
	public function isClean(): bool
	{
		return $this->findings === [];
	}

	/**
	 * The worst severity found.
	 *
	 * @return int
	 *   A Finding severity ordinal, or Finding::INFO when the pass was clean.
	 */
	public function worst(): int
	{
		$worst = Finding::INFO;

		foreach ($this->findings as $finding) {
			$worst = max($worst, $finding->severity);
		}

		return $worst;
	}

	/**
	 * How many findings there are per code.
	 *
	 * @return array<string, int>
	 *   Finding code keyed to count, highest first.
	 */
	public function byCode(): array
	{
		$counts = [];

		foreach ($this->findings as $finding) {
			$counts[$finding->code] = ($counts[$finding->code] ?? 0) + 1;
		}

		arsort($counts);

		return $counts;
	}

	/**
	 * A one-line summary for a log entry or a command's output.
	 *
	 * @return string
	 *   The summary.
	 */
	public function summary(): string
	{
		$scope = $this->complete ? 'all history' : sprintf('%d commits', $this->commits);

		if ($this->isClean()) {
			return sprintf(
				'verified %s: %d frames, %d trees, %d segments, %s decoded in %.2fs',
				$scope,
				$this->frames,
				$this->trees,
				$this->segments,
				self::humanBytes($this->bytes),
				$this->seconds,
			);
		}

		$codes = [];
		foreach ($this->byCode() as $code => $count) {
			$codes[] = sprintf('%s x%d', $code, $count);
		}

		return sprintf(
			'verified %s with %d findings: %s',
			$scope,
			count($this->findings),
			implode(', ', $codes),
		);
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
			'commits' => $this->commits,
			'trees' => $this->trees,
			'frames' => $this->frames,
			'segments' => $this->segments,
			'bytes' => $this->bytes,
			'seconds' => round($this->seconds, 4),
			'complete' => $this->complete,
			'clean' => $this->isClean(),
			'findings' => $this->findings,
		];
	}

	/**
	 * Renders a byte count at a readable scale.
	 *
	 * @param int $bytes
	 *   The count.
	 *
	 * @return string
	 *   For example "4.0 MiB".
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
