<?php

declare(strict_types=1);

namespace Drupal\strata\Anomaly;

use Drupal\strata\Health\Finding;
use JsonSerializable;

/**
 * One measurement that departed from its own history.
 *
 * Carries the numbers rather than a verdict, so an operator reading the finding can see what the
 * baseline was and decide whether the departure is a fault or a busy afternoon. A detector that
 * reported only "op rate is unusual" would be unactionable.
 *
 * @see AnomalyDetector
 */
final class Anomaly implements JsonSerializable
{
	/**
	 * The finding code every anomaly is recorded under.
	 */
	public const CODE = 'anomaly.detected';

	/**
	 * Constructs an anomaly.
	 *
	 * @param string $metric
	 *   Which measurement departed, such as "op_rate".
	 * @param string $label
	 *   The measurement's operator-facing name.
	 * @param float $observed
	 *   The latest observation.
	 * @param float $baseline
	 *   The median of the observations before it.
	 * @param float $deviations
	 *   How far the observation sits from the baseline, signed.
	 * @param int $samples
	 *   How many earlier observations the baseline came from.
	 * @param string $unit
	 *   What the numbers are counted in, for rendering.
	 */
	public function __construct(
		public readonly string $metric,
		public readonly string $label,
		public readonly float $observed,
		public readonly float $baseline,
		public readonly float $deviations,
		public readonly int $samples,
		public readonly string $unit = '',
	) {}

	/**
	 * Which direction the measurement moved.
	 *
	 * @return string
	 *   Either "above" or "below".
	 */
	public function direction(): string
	{
		return $this->deviations >= 0.0 ? 'above' : 'below';
	}

	/**
	 * How severe this departure is.
	 *
	 * A departure is a symptom and never a fault on its own, so it tops out at ERROR: a site that
	 * doubled its traffic is not corrupt, and a detector that could raise CRITICAL would put the
	 * repair ladder straight onto the quarantine rung for a busy Tuesday.
	 *
	 * @return int
	 *   INFO, WARN or ERROR.
	 */
	public function severity(): int
	{
		$magnitude = abs($this->deviations);

		return match (true) {
			$magnitude >= AnomalyDetector::ERROR_SIGMA => Finding::ERROR,
			$magnitude >= AnomalyDetector::WARN_SIGMA => Finding::WARN,
			default => Finding::INFO,
		};
	}

	/**
	 * The finding this anomaly is recorded as.
	 *
	 * @return Finding
	 *   The finding, scoped to the metric so the ledger tracks each measurement separately.
	 */
	public function toFinding(): Finding
	{
		return new Finding(self::CODE, $this->severity(), $this->metric, $this->describe());
	}

	/**
	 * A sentence an operator can act on.
	 *
	 * @return string
	 *   What moved, by how much, against what.
	 */
	public function describe(): string
	{
		return sprintf(
			'%s is %s%s, %.1f deviations %s a baseline of %s%s over %d samples',
			$this->label,
			$this->render($this->observed),
			$this->unit === '' ? '' : ' ' . $this->unit,
			abs($this->deviations),
			$this->direction(),
			$this->render($this->baseline),
			$this->unit === '' ? '' : ' ' . $this->unit,
			$this->samples,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function jsonSerialize(): array
	{
		return [
			'metric' => $this->metric,
			'label' => $this->label,
			'observed' => $this->observed,
			'baseline' => $this->baseline,
			'deviations' => round($this->deviations, 3),
			'direction' => $this->direction(),
			'samples' => $this->samples,
			'unit' => $this->unit,
			'severity' => $this->severity(),
		];
	}

	/**
	 * Renders a measurement without trailing noise.
	 *
	 * @param float $value
	 *   The value.
	 *
	 * @return string
	 *   The value, as an integer when it is one.
	 */
	private function render(float $value): string
	{
		return $value === floor($value) && abs($value) < 1.0e15
			? number_format($value, 0, '.', '')
			: sprintf('%.2f', $value);
	}
}
