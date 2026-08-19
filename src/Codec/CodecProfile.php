<?php

declare(strict_types=1);

namespace Drupal\strata\Codec;

use JsonSerializable;

/**
 * Everything the settings form needs to say about one codec.
 *
 * Three things together, because an administrator deciding whether a compressor is worth
 * installing needs all three at once and none of them is useful alone: what it costs in size and
 * speed, whether this machine can run it, and if not, the exact command that fixes that.
 *
 * The reference measurements are shipped figures from a fixed Drupal-shaped corpus, so two hosts
 * can be compared. They are NOT a promise about a particular site: ratio depends heavily on what
 * the content is, and a corpus with a lot of hashes or already-compressed media will do worse than
 * one that is mostly prose and repeated field structure. Calibrator measures the real thing on
 * site data and marks those figures as such, and the form shows both.
 *
 * @see CodecCatalog
 * @see CodecMeasurement
 */
final class CodecProfile implements JsonSerializable
{
	/**
	 * Constructs a profile.
	 *
	 * @param string $id
	 *   The codec id as recorded in a frame header.
	 * @param string $label
	 *   Human-readable name for the form.
	 * @param string $summary
	 *   One sentence on what this codec is for.
	 * @param bool $available
	 *   Whether it can run on this host.
	 * @param string|null $reason
	 *   Why it cannot run, or NULL when it can.
	 * @param bool $supportsDictionary
	 *   Whether it accepts a trained dictionary, which is what delta coding needs.
	 * @param bool $perFrame
	 *   Whether it may be chosen for the flush path, as opposed to bulk work only.
	 * @param array{min: int, max: int, default: int, fast: int, dense: int} $levels
	 *   Its level range.
	 * @param list<CodecMeasurement> $measurements
	 *   Reference figures, and any measured on this host.
	 * @param array<string, string> $install
	 *   Platform label keyed to the command that installs it. Empty when it needs no install.
	 * @param string|null $documentation
	 *   Where to read more, or NULL.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly string $summary,
		public readonly bool $available,
		public readonly ?string $reason,
		public readonly bool $supportsDictionary,
		public readonly bool $perFrame,
		public readonly array $levels,
		public readonly array $measurements = [],
		public readonly array $install = [],
		public readonly ?string $documentation = null,
	) {}

	/**
	 * The measurement closest to a given frame size, preferring one taken on this host.
	 *
	 * @param int $frameSize
	 *   The frame size to report at.
	 * @param bool $dictionary
	 *   Whether to report the dictionary figure.
	 * @param string $level
	 *   One of "fast", "default" or "dense".
	 *
	 * @return CodecMeasurement|null
	 *   The best matching measurement, or NULL when none was recorded.
	 */
	public function measurement(
		int $frameSize,
		bool $dictionary = false,
		string $level = 'dense',
	): ?CodecMeasurement {
		$wanted = $this->levels[$level] ?? $this->levels['default'];
		$best = null;

		foreach ($this->measurements as $measurement) {
			if ($measurement->dictionary !== $dictionary || $measurement->level !== $wanted) {
				continue;
			}
			if ($best === null) {
				$best = $measurement;

				continue;
			}
			// a figure from this host always wins; otherwise take the nearer frame size
			if ($measurement->onThisHost && !$best->onThisHost) {
				$best = $measurement;

				continue;
			}
			if ($best->onThisHost && !$measurement->onThisHost) {
				continue;
			}
			if (abs($measurement->frameSize - $frameSize) < abs($best->frameSize - $frameSize)) {
				$best = $measurement;
			}
		}

		return $best;
	}

	/**
	 * Whether any figure in this profile was measured here rather than shipped.
	 *
	 * @return bool
	 *   TRUE when calibration has run for this codec.
	 */
	public function isCalibrated(): bool
	{
		foreach ($this->measurements as $measurement) {
			if ($measurement->onThisHost) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The same profile with extra measurements folded in.
	 *
	 * Readonly, so calibration produces a new profile rather than mutating a shared one.
	 *
	 * @param list<CodecMeasurement> $measurements
	 *   Measurements to add.
	 *
	 * @return self
	 *   A new profile carrying both sets.
	 */
	public function withMeasurements(array $measurements): self
	{
		return new self(
			$this->id,
			$this->label,
			$this->summary,
			$this->available,
			$this->reason,
			$this->supportsDictionary,
			$this->perFrame,
			$this->levels,
			[...$this->measurements, ...$measurements],
			$this->install,
			$this->documentation,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The profile as a plain array for a render array or a JSON response.
	 */
	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'label' => $this->label,
			'summary' => $this->summary,
			'available' => $this->available,
			'reason' => $this->reason,
			'supportsDictionary' => $this->supportsDictionary,
			'perFrame' => $this->perFrame,
			'levels' => $this->levels,
			'calibrated' => $this->isCalibrated(),
			'measurements' => array_map(
				static fn(CodecMeasurement $m): array => $m->jsonSerialize(),
				$this->measurements,
			),
			'install' => $this->install,
			'documentation' => $this->documentation,
		];
	}
}
