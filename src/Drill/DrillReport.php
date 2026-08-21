<?php

declare(strict_types=1);

namespace Drupal\strata\Drill;

use JsonSerializable;

/**
 * What one restore drill proved, and what it could not check.
 *
 * The four counts are deliberately separate. A subject that MATCHED proves the store reproduces what
 * the site holds. A subject that DIFFERED is a real failure. A subject that was SKIPPED could not be
 * judged - it changed after the commit the drill replayed, so a difference there is the site being
 * newer rather than the store being wrong. A subject that was UNREADABLE is a failure of a different
 * kind: the store could not produce a value at all.
 *
 * Collapsing skipped into either column is how a drill starts lying. Counted as a pass it inflates
 * confidence; counted as a failure it makes every busy site look broken.
 *
 * @see DrillRunner
 */
final class DrillReport implements JsonSerializable
{
	/**
	 * The store reproduced what the site holds.
	 */
	public const MATCHED = 'matched';

	/**
	 * The store produced a value and it was not what the site holds.
	 */
	public const DIFFERED = 'differed';

	/**
	 * The subject moved on after the commit that was replayed, so nothing was proved either way.
	 */
	public const SKIPPED = 'skipped';

	/**
	 * The store could not produce a value.
	 */
	public const UNREADABLE = 'unreadable';

	/**
	 * Constructs a report.
	 *
	 * @param string $target
	 *   Commit the drill replayed against.
	 * @param string $mode
	 *   Either "replay" or "scratch".
	 * @param array<string, string> $matched
	 *   Subject path keyed to a note, for subjects that reproduced exactly.
	 * @param array<string, string> $differed
	 *   Subject path keyed to what differed.
	 * @param array<string, string> $skipped
	 *   Subject path keyed to why it could not be judged.
	 * @param array<string, string> $unreadable
	 *   Subject path keyed to why the store could not produce a value.
	 * @param int $sampled
	 *   How many subjects were examined.
	 * @param int $population
	 *   How many subjects the index holds, so a reader knows what share was checked.
	 * @param float $seconds
	 *   How long the drill took.
	 * @param int $ranAt
	 *   Unix seconds the drill ran.
	 * @param string|null $refused
	 *   Why the drill did not run at all, when it did not.
	 */
	public function __construct(
		public readonly string $target,
		public readonly string $mode = 'replay',
		public readonly array $matched = [],
		public readonly array $differed = [],
		public readonly array $skipped = [],
		public readonly array $unreadable = [],
		public readonly int $sampled = 0,
		public readonly int $population = 0,
		public readonly float $seconds = 0.0,
		public readonly int $ranAt = 0,
		public readonly ?string $refused = null,
	) {}

	/**
	 * Whether the drill proved the store reproduces the site.
	 *
	 * A drill that could judge nothing is not a pass. An operator who read "0 differences" as a
	 * healthy backup when every subject was skipped would have measured nothing and been reassured
	 * by it.
	 *
	 * @return bool
	 *   TRUE when at least one subject was judged and none differed or was unreadable.
	 */
	public function passed(): bool
	{
		return $this->refused === null &&
			$this->judged() > 0 &&
			$this->differed === [] &&
			$this->unreadable === [];
	}

	/**
	 * Whether the drill ran at all.
	 *
	 * @return bool
	 *   TRUE when it was refused.
	 */
	public function wasRefused(): bool
	{
		return $this->refused !== null;
	}

	/**
	 * How many subjects the drill could actually judge.
	 *
	 * @return int
	 *   Matched plus differed plus unreadable.
	 */
	public function judged(): int
	{
		return count($this->matched) + count($this->differed) + count($this->unreadable);
	}

	/**
	 * The share of judged subjects that reproduced exactly.
	 *
	 * @return float
	 *   Between 0.0 and 1.0, or 0.0 when nothing could be judged.
	 */
	public function accuracy(): float
	{
		$judged = $this->judged();

		return $judged === 0 ? 0.0 : count($this->matched) / $judged;
	}

	/**
	 * The verdict, in one word.
	 *
	 * @return string
	 *   Either "pass", "fail", "inconclusive" or "refused".
	 */
	public function verdict(): string
	{
		return match (true) {
			$this->refused !== null => 'refused',
			$this->judged() === 0 => 'inconclusive',
			$this->passed() => 'pass',
			default => 'fail',
		};
	}

	/**
	 * A one-line summary.
	 *
	 * @return string
	 *   The verdict and the counts.
	 */
	public function summary(): string
	{
		if ($this->refused !== null) {
			return sprintf('drill refused: %s', $this->refused);
		}

		return sprintf(
			'%s: %d matched, %d differed, %d unreadable, %d skipped of %d sampled in %.2fs',
			$this->verdict(),
			count($this->matched),
			count($this->differed),
			count($this->unreadable),
			count($this->skipped),
			$this->sampled,
			$this->seconds,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function jsonSerialize(): array
	{
		return [
			'target' => $this->target,
			'mode' => $this->mode,
			'verdict' => $this->verdict(),
			'matched' => count($this->matched),
			'differed' => $this->differed,
			'skipped' => count($this->skipped),
			'unreadable' => $this->unreadable,
			'sampled' => $this->sampled,
			'population' => $this->population,
			'accuracy' => round($this->accuracy(), 4),
			'seconds' => round($this->seconds, 4),
			'ran_at' => $this->ranAt,
			'refused' => $this->refused,
		];
	}
}
