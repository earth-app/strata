<?php

declare(strict_types=1);

namespace Drupal\strata\Tier;

use JsonSerializable;

/**
 * Which buckets a restore needs, worked out before it starts.
 *
 * The question an operator has to be able to answer at plan time is whether the cold bucket has to be
 * up. Finding out halfway through a rollback - with some subjects written and the rest unreadable - is
 * the outcome this exists to prevent.
 *
 * Objects whose tier is not recorded are counted separately and never guessed at. While that count is
 * above zero every tier is reported as possibly needed, because "it is in one of these buckets and I
 * do not know which" is the true answer and narrowing it without evidence is the failure mode the
 * whole design refuses.
 *
 * @see TierRequirements
 */
final class TierRequirementReport implements JsonSerializable
{
	/**
	 * Constructs a report.
	 *
	 * @param string $target
	 *   Commit the restore would replay to.
	 * @param array<int, array{name: string, objects: int, reachable: bool, reason: string|null}> $tiers
	 *   Tier index keyed to its name, how many of the objects this restore needs are in it, whether
	 *   it can be reached right now, and why not when it cannot.
	 * @param int $objects
	 *   Objects the restore depends on.
	 * @param int $unplaced
	 *   Objects whose tier is not recorded, so any tier may hold them.
	 * @param bool $bounded
	 *   TRUE when the walk stopped early, either at its own limit or because every tier was already
	 *   required and nothing more could change the answer.
	 * @param list<string> $problems
	 *   One line per object the walk could not resolve at all.
	 * @param float $seconds
	 *   How long the walk took.
	 */
	public function __construct(
		public readonly string $target,
		public readonly array $tiers = [],
		public readonly int $objects = 0,
		public readonly int $unplaced = 0,
		public readonly bool $bounded = false,
		public readonly array $problems = [],
		public readonly float $seconds = 0.0,
	) {}

	/**
	 * Tier names this restore needs.
	 *
	 * @return list<string>
	 *   Names, nearest first.
	 */
	public function names(): array
	{
		$names = [];

		foreach ($this->tiers as $tier) {
			$names[] = $tier['name'];
		}

		return $names;
	}

	/**
	 * Tiers this restore needs that cannot be reached right now.
	 *
	 * @return array<int, string>
	 *   Tier index keyed to the reason.
	 */
	public function unreachable(): array
	{
		$down = [];

		foreach ($this->tiers as $index => $tier) {
			if (!$tier['reachable']) {
				$down[$index] = $tier['reason'] ?? 'the endpoint did not say why';
			}
		}

		return $down;
	}

	/**
	 * Whether every bucket this restore needs can be reached.
	 *
	 * @return bool
	 *   TRUE when nothing stands in the way of starting.
	 */
	public function isSatisfiable(): bool
	{
		return $this->unreachable() === [] && $this->problems === [];
	}

	/**
	 * Whether the answer is as precise as it can be.
	 *
	 * @return bool
	 *   TRUE when every object this restore needs has a recorded tier.
	 */
	public function isPrecise(): bool
	{
		return $this->unplaced === 0;
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
			'a restore to this commit reads %d objects from %s',
			$this->objects,
			$this->names() === [] ? 'no tier' : implode(', ', $this->names()),
		);

		if (!$this->isPrecise()) {
			$summary .= sprintf('; %d objects have no recorded tier', $this->unplaced);
		}

		$down = $this->unreachable();

		return $down === []
			? $summary
			: $summary . sprintf('; %d of those tiers cannot be reached', count($down));
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
			'target' => $this->target,
			'tiers' => $this->tiers,
			'objects' => $this->objects,
			'unplaced' => $this->unplaced,
			'bounded' => $this->bounded,
			'satisfiable' => $this->isSatisfiable(),
			'problems' => $this->problems,
			'seconds' => round($this->seconds, 4),
		];
	}
}
