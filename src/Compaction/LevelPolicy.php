<?php

declare(strict_types=1);

namespace Drupal\strata\Compaction;

use InvalidArgumentException;

/**
 * The retention ladder: how long each granularity of history is kept.
 *
 * A level says "roll operations up into windows this wide, and keep them this long". The default
 * ladder is 15 s for an hour, 1 min for a day, 1 h for a week, 1 d for 90 days, 1 mo forever, which
 * gives per-second restore granularity on today's history and per-month depth on last year's.
 *
 * What rolling up actually buys is measured and is not what it looks like. Collapsing repeated
 * operations on the same subject is worth almost nothing below the day level: on a 50,000-user site
 * with 262,000 subjects and 82,892 writes a day, the coupon-collector expectation for a one-hour
 * window is 1.00x, for a day 1.07x, and only at monthly rollups does it reach 4.05x. The reason to
 * compact a fine level is therefore recompression - zstd level 1 to level 19 with a dictionary,
 * 4.28x to 5.86x - and delta re-anchoring, not fewer operations.
 *
 * @see Compactor
 * @see Recompressor
 */
final class LevelPolicy
{
	/**
	 * Windows and retentions the module ships with, coarsest last.
	 *
	 * @var list<array{window: int, keep: int}>
	 */
	public const DEFAULT_LEVELS = [
		['window' => 15, 'keep' => 3600],
		['window' => 60, 'keep' => 86400],
		['window' => 3600, 'keep' => 604800],
		['window' => 86400, 'keep' => 7776000],
		['window' => 2592000, 'keep' => 0],
	];

	/**
	 * The levels, level number keyed to its window and retention.
	 *
	 * @var list<array{window: int, keep: int}>
	 */
	private readonly array $levels;

	/**
	 * Constructs a policy.
	 *
	 * @param list<array{window: int, keep: int}> $levels
	 *   Windows in seconds with retention in seconds, coarsest last. A retention of zero means
	 *   forever. Each window must be wider than the one below it, or a rollup would produce
	 *   segments the level below already covers.
	 *
	 * @throws InvalidArgumentException
	 *   When the ladder is empty, a window is not positive, a retention is negative, or the windows
	 *   do not increase.
	 */
	public function __construct(array $levels = self::DEFAULT_LEVELS)
	{
		if ($levels === []) {
			throw new InvalidArgumentException('A retention ladder needs at least one level');
		}

		$previous = 0;

		foreach ($levels as $depth => $level) {
			$window = (int) ($level['window'] ?? 0);
			$keep = (int) ($level['keep'] ?? 0);

			if ($window < 1) {
				throw new InvalidArgumentException(
					sprintf('Level %d has a window of %d seconds', $depth, $window),
				);
			}
			if ($keep < 0) {
				throw new InvalidArgumentException(
					sprintf('Level %d keeps history for %d seconds', $depth, $keep),
				);
			}
			if ($window <= $previous) {
				throw new InvalidArgumentException(
					sprintf(
						'Level %d has a %d second window, which is not wider than level %d at %d',
						$depth,
						$window,
						$depth - 1,
						$previous,
					),
				);
			}

			$previous = $window;
		}

		$this->levels = array_values($levels);
	}

	/**
	 * Builds a policy from the module's settings array.
	 *
	 * @param array<int, array<string, mixed>> $configured
	 *   The `retention.levels` setting.
	 *
	 * @return self
	 *   The policy, falling back to the shipped ladder when nothing is configured.
	 */
	public static function fromSettings(array $configured): self
	{
		if ($configured === []) {
			return new self();
		}

		$levels = [];

		foreach ($configured as $level) {
			$levels[] = [
				'window' => (int) ($level['window'] ?? 0),
				'keep' => (int) ($level['keep'] ?? 0),
			];
		}

		return new self($levels);
	}

	/**
	 * How many levels the ladder has.
	 *
	 * @return int
	 *   The count.
	 */
	public function depth(): int
	{
		return count($this->levels);
	}

	/**
	 * The window width at a level.
	 *
	 * @param int $level
	 *   Level number, zero-based.
	 *
	 * @return int
	 *   Window in seconds.
	 *
	 * @throws InvalidArgumentException
	 *   When the level is not on the ladder.
	 */
	public function window(int $level): int
	{
		return $this->level($level)['window'];
	}

	/**
	 * How long a level's history is kept.
	 *
	 * @param int $level
	 *   Level number, zero-based.
	 *
	 * @return int
	 *   Retention in seconds; zero means forever.
	 *
	 * @throws InvalidArgumentException
	 *   When the level is not on the ladder.
	 */
	public function retention(int $level): int
	{
		return $this->level($level)['keep'];
	}

	/**
	 * Whether a level's history is kept indefinitely.
	 *
	 * @param int $level
	 *   Level number, zero-based.
	 *
	 * @return bool
	 *   TRUE when nothing at this level is ever pruned.
	 *
	 * @throws InvalidArgumentException
	 *   When the level is not on the ladder.
	 */
	public function isPermanent(int $level): bool
	{
		return $this->retention($level) === 0;
	}

	/**
	 * The level a segment is rolled up into.
	 *
	 * @param int $level
	 *   Level number, zero-based.
	 *
	 * @return int|null
	 *   The next level up, or NULL when this is the coarsest and nothing rolls up out of it.
	 */
	public function promotes(int $level): ?int
	{
		return $level + 1 < $this->depth() ? $level + 1 : null;
	}

	/**
	 * The window a moment in time falls into at a level.
	 *
	 * Windows are anchored on the epoch rather than on the first segment, so two hosts compacting
	 * the same history independently produce the same window boundaries.
	 *
	 * @param int $second
	 *   Unix seconds.
	 * @param int $level
	 *   Level number, zero-based.
	 *
	 * @return int
	 *   The unix second the window starts at.
	 *
	 * @throws InvalidArgumentException
	 *   When the level is not on the ladder.
	 */
	public function windowStart(int $second, int $level): int
	{
		$window = $this->window($level);

		return intdiv($second, $window) * $window;
	}

	/**
	 * Whether history at a level is old enough to roll up.
	 *
	 * A window that has not closed is still being written to, so rolling it up would produce a
	 * segment the next flush contradicts.
	 *
	 * @param int $second
	 *   Unix second the window starts at.
	 * @param int $level
	 *   Level number, zero-based.
	 * @param int $now
	 *   Unix seconds now.
	 *
	 * @return bool
	 *   TRUE when the window has closed and its retention has elapsed.
	 *
	 * @throws InvalidArgumentException
	 *   When the level is not on the ladder.
	 */
	public function isDueForRollup(int $second, int $level, int $now): bool
	{
		if ($this->promotes($level) === null) {
			return false;
		}

		$keep = $this->retention($level);

		if ($keep === 0) {
			return false;
		}

		return $second + $keep <= $now;
	}

	/**
	 * The unix second before which a level's history is past its retention.
	 *
	 * @param int $level
	 *   Level number, zero-based.
	 * @param int $now
	 *   Unix seconds now.
	 *
	 * @return int|null
	 *   The cutoff, or NULL when this level is kept forever.
	 *
	 * @throws InvalidArgumentException
	 *   When the level is not on the ladder.
	 */
	public function cutoff(int $level, int $now): ?int
	{
		$keep = $this->retention($level);

		return $keep === 0 ? null : $now - $keep;
	}

	/**
	 * The whole ladder, for the settings form and the storage explorer.
	 *
	 * @return list<array{window: int, keep: int}>
	 *   The levels, coarsest last.
	 */
	public function levels(): array
	{
		return $this->levels;
	}

	/**
	 * One level, checked.
	 *
	 * @param int $level
	 *   Level number, zero-based.
	 *
	 * @return array{window: int, keep: int}
	 *   The level.
	 *
	 * @throws InvalidArgumentException
	 *   When the level is not on the ladder.
	 */
	private function level(int $level): array
	{
		if (!isset($this->levels[$level])) {
			throw new InvalidArgumentException(
				sprintf('Level %d is not on a ladder of %d levels', $level, $this->depth()),
			);
		}

		return $this->levels[$level];
	}
}
