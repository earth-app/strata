<?php

declare(strict_types=1);

namespace Drupal\strata\Branch;

/**
 * Merges one configuration object across a base, a target and a branch.
 *
 * **This works because configuration is captured WHOLE.** Every save records the object's complete
 * raw data, so its value at the merge base, at the target tip and at the branch tip are three
 * complete documents and comparing them is an ordinary operation. Content entities and table rows are
 * captured as field deltas against a parent, so the same operation over them would mean replaying two
 * divergent delta chains and inventing a value wherever they disagree. Nothing here is given a
 * non-configuration realm, and Merger refuses one by name.
 *
 * The comparison runs key by key and recurses into nested maps, so two people editing different
 * settings of the same object both get their edit. It stops recursing at a list. A sequential array
 * carries meaning in its order and not in its indices, so merging two lists position by position
 * would splice the third item of one into the third slot of the other; a list both sides changed is
 * one value in disagreement, which is a conflict.
 *
 * **A conflict is never resolved on its own.** Two values written for the same key is a disagreement
 * between two people, and the only safe automatic answer is to stop and show both. A strategy the
 * caller names can decide them, and it decides only the keys that actually collided.
 *
 * @see MergeEntry
 * @see MergeStrategy
 * @see Merger
 */
final class ThreeWayMerge
{
	/**
	 * How deep the key-by-key comparison recurses before treating a value as one lump.
	 *
	 * Configuration nests a handful of levels at most; anything past this is a structure that will be
	 * compared whole, which is conservative rather than wrong.
	 */
	public const MAX_DEPTH = 32;

	/**
	 * Merges one object.
	 *
	 * @param string $name
	 *   The configuration object name.
	 * @param array<string, mixed>|null $base
	 *   Its data at the merge base, or NULL when it did not exist there.
	 * @param array<string, mixed>|null $ours
	 *   Its data at the target tip, or NULL when it does not exist there.
	 * @param array<string, mixed>|null $theirs
	 *   Its data at the branch tip, or NULL when it does not exist there.
	 * @param MergeStrategy $strategy
	 *   What to do with a key both sides changed.
	 *
	 * @return MergeEntry
	 *   What the merge decided.
	 */
	public function merge(
		string $name,
		?array $base,
		?array $ours,
		?array $theirs,
		MergeStrategy $strategy = MergeStrategy::REFUSE,
	): MergeEntry {
		$existence = $this->existence($name, $base, $ours, $theirs, $strategy);

		if ($existence !== null) {
			return $existence;
		}

		$merged = $this->mergeMaps($base ?? [], $ours ?? [], $theirs ?? [], '', 0);

		return $this->entry($name, $merged, $strategy);
	}

	#region Existence

	/**
	 * The entry for an object that does not exist on all three sides.
	 *
	 * Creation and removal are decided before any key is looked at, because a key-by-key comparison
	 * against a document that is not there would read every key as an addition.
	 *
	 * @param string $name
	 *   The configuration object name.
	 * @param array<string, mixed>|null $base
	 *   Its data at the merge base.
	 * @param array<string, mixed>|null $ours
	 *   Its data at the target tip.
	 * @param array<string, mixed>|null $theirs
	 *   Its data at the branch tip.
	 * @param MergeStrategy $strategy
	 *   What to do with a disagreement.
	 *
	 * @return MergeEntry|null
	 *   The entry, or NULL when all three sides have the object and the keys decide it.
	 */
	private function existence(
		string $name,
		?array $base,
		?array $ours,
		?array $theirs,
		MergeStrategy $strategy,
	): ?MergeEntry {
		// gone on both sides, however it got there
		if ($ours === null && $theirs === null) {
			return new MergeEntry($name, MergeOutcome::UNCHANGED, [], false);
		}

		// removed on the branch: a target that also moved it is two people disagreeing about whether
		// the object should exist, which is not something a key comparison can express
		if ($theirs === null) {
			if ($base !== null && $this->same($base, $ours)) {
				return new MergeEntry($name, MergeOutcome::THEIRS, [], false);
			}

			return $this->wholeObjectConflict($name, $base, $ours, null, $strategy);
		}

		if ($ours === null) {
			if ($base !== null && $this->same($base, $theirs)) {
				// the target removed it and the branch left it alone
				return new MergeEntry($name, MergeOutcome::OURS, [], false);
			}
			if ($base === null) {
				// created only on the branch
				return new MergeEntry(
					$name,
					MergeOutcome::THEIRS,
					$theirs,
					true,
					[],
					$this->paths($theirs, '', 0),
				);
			}

			return $this->wholeObjectConflict($name, $base, null, $theirs, $strategy);
		}

		// present on both sides but not at the base, so both created it; identical creations agree
		if ($base === null && !$this->same($ours, $theirs)) {
			return $this->wholeObjectConflict($name, null, $ours, $theirs, $strategy);
		}

		return null;
	}

	/**
	 * A conflict over whether the object exists at all.
	 *
	 * @param string $name
	 *   The configuration object name.
	 * @param array<string, mixed>|null $base
	 *   Its data at the merge base.
	 * @param array<string, mixed>|null $ours
	 *   Its data at the target tip.
	 * @param array<string, mixed>|null $theirs
	 *   Its data at the branch tip.
	 * @param MergeStrategy $strategy
	 *   What to do with the disagreement.
	 *
	 * @return MergeEntry
	 *   The entry, resolved to one side's whole value when a strategy named one.
	 */
	private function wholeObjectConflict(
		string $name,
		?array $base,
		?array $ours,
		?array $theirs,
		MergeStrategy $strategy,
	): MergeEntry {
		$conflicts = ['' => ['ours' => $ours, 'theirs' => $theirs]];

		$taken = match ($strategy) {
			MergeStrategy::OURS => $ours,
			MergeStrategy::THEIRS => $theirs,
			MergeStrategy::REFUSE => $base,
		};

		return new MergeEntry(
			$name,
			MergeOutcome::CONFLICT,
			$taken ?? [],
			$taken !== null,
			$conflicts,
			$strategy === MergeStrategy::THEIRS && $theirs !== null
				? $this->paths($theirs, '', 0)
				: [],
			!$strategy->isRefusal(),
		);
	}

	#endregion

	#region Keys

	/**
	 * Merges two maps against their base, key by key.
	 *
	 * @param array<string, mixed> $base
	 *   The map at the merge base.
	 * @param array<string, mixed> $ours
	 *   The map at the target tip.
	 * @param array<string, mixed> $theirs
	 *   The map at the branch tip.
	 * @param string $prefix
	 *   Dotted path of the map inside the object, empty at the top.
	 * @param int $depth
	 *   How far the recursion has gone.
	 *
	 * @return array{value: array<string, mixed>, conflicts: array<string, array{ours: mixed, theirs: mixed}>, theirs: list<string>, ours: list<string>}
	 *   The merged map, the keys that collided with both values, and the paths each side moved.
	 */
	private function mergeMaps(
		array $base,
		array $ours,
		array $theirs,
		string $prefix,
		int $depth,
	): array {
		$value = [];
		$conflicts = [];
		$fromTheirs = [];
		$fromOurs = [];

		foreach (array_keys($ours + $theirs + $base) as $key) {
			$path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
			$inOurs = array_key_exists($key, $ours);
			$inTheirs = array_key_exists($key, $theirs);
			$hadBase = array_key_exists($key, $base);
			$atBase = $base[$key] ?? null;

			// neither side has it any more, so neither does the result
			if (!$inOurs && !$inTheirs) {
				continue;
			}

			$ourValue = $ours[$key] ?? null;
			$theirValue = $theirs[$key] ?? null;
			$ourMoved = $inOurs !== $hadBase || ($inOurs && !$this->same($atBase, $ourValue));
			$theirMoved =
				$inTheirs !== $hadBase || ($inTheirs && !$this->same($atBase, $theirValue));

			// only the target moved it, or nobody did; either way the site already holds the answer
			if (!$theirMoved) {
				if ($inOurs) {
					$value[$key] = $ourValue;
				}
				if ($ourMoved) {
					$fromOurs[] = $path;
				}

				continue;
			}

			// only the branch moved it, which includes removing it
			if (!$ourMoved) {
				if ($inTheirs) {
					$value[$key] = $theirValue;
				}

				$fromTheirs[] = $path;

				continue;
			}

			// both moved it to the same place, which is agreement rather than collision
			if ($inOurs === $inTheirs && $this->same($ourValue, $theirValue)) {
				if ($inOurs) {
					$value[$key] = $ourValue;
				}

				continue;
			}

			// both moved a key that is a map on both sides, so the disagreement may be deeper down
			if (
				$depth < self::MAX_DEPTH &&
				$this->isMergeableMap($ourValue) &&
				$this->isMergeableMap($theirValue)
			) {
				$nested = $this->mergeMaps(
					$this->isMergeableMap($atBase) ? $atBase : [],
					$ourValue,
					$theirValue,
					$path,
					$depth + 1,
				);

				$value[$key] = $nested['value'];
				$conflicts = [...$conflicts, ...$nested['conflicts']];
				$fromTheirs = [...$fromTheirs, ...$nested['theirs']];
				$fromOurs = [...$fromOurs, ...$nested['ours']];

				continue;
			}

			$conflicts[$path] = ['ours' => $ourValue, 'theirs' => $theirValue];

			// something has to stand in the merged value until a strategy decides; the target's own
			// value is the one already on the site, so it is the least surprising placeholder
			if ($inOurs) {
				$value[$key] = $ourValue;
			}
		}

		return [
			'value' => $value,
			'conflicts' => $conflicts,
			'theirs' => $fromTheirs,
			'ours' => $fromOurs,
		];
	}

	/**
	 * The entry a merged map produces, once a strategy has had its say.
	 *
	 * @param string $name
	 *   The configuration object name.
	 * @param array{value: array<string, mixed>, conflicts: array<string, array{ours: mixed, theirs: mixed}>, theirs: list<string>, ours: list<string>} $merged
	 *   What the key comparison produced.
	 * @param MergeStrategy $strategy
	 *   What to do with a key both sides changed.
	 *
	 * @return MergeEntry
	 *   The entry.
	 */
	private function entry(string $name, array $merged, MergeStrategy $strategy): MergeEntry
	{
		$value = $merged['value'];
		$conflicts = $merged['conflicts'];
		$fromTheirs = $merged['theirs'];

		if ($conflicts === []) {
			$outcome = match (true) {
				$fromTheirs === [] && $merged['ours'] === [] => MergeOutcome::UNCHANGED,
				$fromTheirs === [] => MergeOutcome::OURS,
				$merged['ours'] === [] => MergeOutcome::THEIRS,
				default => MergeOutcome::MERGED,
			};

			return new MergeEntry($name, $outcome, $value, true, [], $fromTheirs);
		}

		if ($strategy === MergeStrategy::THEIRS) {
			foreach ($conflicts as $path => $pair) {
				$value = $this->set($value, (string) $path, $pair['theirs']);
				$fromTheirs[] = (string) $path;
			}
		}

		return new MergeEntry(
			$name,
			MergeOutcome::CONFLICT,
			$value,
			true,
			$conflicts,
			$fromTheirs,
			!$strategy->isRefusal(),
		);
	}

	#endregion

	#region Values

	/**
	 * Whether a value is a map this merges into rather than compares whole.
	 *
	 * @param mixed $value
	 *   The value.
	 *
	 * @return bool
	 *   TRUE for a non-empty associative array.
	 */
	private function isMergeableMap(mixed $value): bool
	{
		return is_array($value) && $value !== [] && !array_is_list($value);
	}

	/**
	 * Whether two values are the same for merge purposes.
	 *
	 * Compared by identity rather than equality, so `0`, `''`, `false` and `null` stay four different
	 * settings. Arrays are compared after sorting by key at every level, because two saves that wrote
	 * the same settings in a different order wrote the same configuration.
	 *
	 * @param mixed $left
	 *   One value.
	 * @param mixed $right
	 *   The other.
	 *
	 * @return bool
	 *   TRUE when they are the same.
	 */
	private function same(mixed $left, mixed $right): bool
	{
		if (is_array($left) && is_array($right)) {
			return $this->normalize($left) === $this->normalize($right);
		}

		return $left === $right;
	}

	/**
	 * An array with every map level sorted by key.
	 *
	 * @param array<array-key, mixed> $value
	 *   The array.
	 * @param int $depth
	 *   Recursion guard.
	 *
	 * @return array<array-key, mixed>
	 *   The array, key-sorted at every level. A list keeps its order, which is its meaning.
	 */
	private function normalize(array $value, int $depth = 0): array
	{
		if ($depth > self::MAX_DEPTH) {
			return $value;
		}

		foreach ($value as $key => $item) {
			if (is_array($item)) {
				$value[$key] = $this->normalize($item, $depth + 1);
			}
		}

		if (!array_is_list($value)) {
			ksort($value);
		}

		return $value;
	}

	/**
	 * Every dotted path a map holds, stopping at a leaf.
	 *
	 * @param array<string, mixed> $value
	 *   The map.
	 * @param string $prefix
	 *   Dotted path of the map, empty at the top.
	 * @param int $depth
	 *   Recursion guard.
	 *
	 * @return list<string>
	 *   The paths.
	 */
	private function paths(array $value, string $prefix, int $depth): array
	{
		$paths = [];

		foreach ($value as $key => $item) {
			$path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

			if ($this->isMergeableMap($item) && $depth < self::MAX_DEPTH) {
				$paths = [...$paths, ...$this->paths($item, $path, $depth + 1)];

				continue;
			}

			$paths[] = $path;
		}

		return $paths;
	}

	/**
	 * A map with one dotted path set to a value.
	 *
	 * @param array<string, mixed> $value
	 *   The map.
	 * @param string $path
	 *   The dotted path.
	 * @param mixed $item
	 *   What to set it to.
	 *
	 * @return array<string, mixed>
	 *   The map.
	 */
	private function set(array $value, string $path, mixed $item): array
	{
		$segments = explode('.', $path);
		$last = array_pop($segments);
		$cursor = &$value;

		foreach ($segments as $segment) {
			if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
				$cursor[$segment] = [];
			}

			$cursor = &$cursor[$segment];
		}

		$cursor[$last] = $item;
		unset($cursor);

		return $value;
	}

	#endregion
}
