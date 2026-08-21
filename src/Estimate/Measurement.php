<?php

declare(strict_types=1);

namespace Drupal\strata\Estimate;

use InvalidArgumentException;
use JsonSerializable;

/**
 * The shape of a site and the policy applied to it, as an estimate's input.
 *
 * Two kinds of thing, deliberately in one object because an estimate is meaningless without both.
 * The shape - users, nodes, rows, files - decides how much churn there is. The policy - flush
 * interval, base interval, retention, whether files are captured at all - decides what that churn
 * costs. A site twice the size on half the interval is not twice the bill.
 *
 * **Per-realm daily operation counts can be supplied directly.** Left empty they are derived from
 * the shape using the recorded per-subject rates, which is what the estimate form does. A
 * calibration pass instead counts what the site actually wrote in the last day and passes those,
 * which turns a projection into a back-test.
 *
 * @see Estimator
 * @see Projection
 */
final class Measurement implements JsonSerializable
{
	/**
	 * Access churn recorded as a compact login event.
	 *
	 * The default. 48 bytes per event, 24% cheaper than a full field delta, and it keeps the
	 * security trail a rollback should never erase.
	 */
	public const ACCESS_EVENT = 'event';

	/**
	 * Access churn recorded as a full field delta.
	 */
	public const ACCESS_DELTA = 'delta';

	/**
	 * Access churn not recorded at all.
	 *
	 * Measured as costing MORE than the event mode rather than less, because the row is still dirty
	 * and the reconciler picks it up later at full price.
	 */
	public const ACCESS_OFF = 'off';

	/**
	 * Every access churn mode.
	 */
	public const ACCESS_MODES = [self::ACCESS_EVENT, self::ACCESS_DELTA, self::ACCESS_OFF];

	/**
	 * Constructs a measurement.
	 *
	 * @param int $users
	 *   Registered users.
	 * @param int $nodes
	 *   Content items.
	 * @param int $rows
	 *   Rows in tables the site's own modules write, which are subjects like any other.
	 * @param int $files
	 *   Managed and unmanaged files.
	 * @param int $fileBytes
	 *   Total bytes those files occupy.
	 * @param float $activeShare
	 *   Share of users active on a given day, which is what generates access churn.
	 * @param int $segmentInterval
	 *   Seconds between flushes, which is the durability window and the request-cost dial.
	 * @param int $baseInterval
	 *   Seconds between base anchors.
	 * @param int $retentionDays
	 *   How long the coarsest retention level keeps history.
	 * @param int $deploysPerYear
	 *   Deploys, which is how often the code realm changes.
	 * @param string $accessChurn
	 *   One of the access modes.
	 * @param bool $captureFiles
	 *   Whether the file realm is captured, which is the single largest switch in the model.
	 * @param array<string, float> $opsPerDay
	 *   Realm value keyed to operations a day, overriding what the shape would imply. Empty to
	 *   derive them.
	 *
	 * @throws InvalidArgumentException
	 *   When a count is negative, an interval is not positive, or the access mode is unknown.
	 */
	public function __construct(
		public readonly int $users = 0,
		public readonly int $nodes = 0,
		public readonly int $rows = 0,
		public readonly int $files = 0,
		public readonly int $fileBytes = 0,
		public readonly float $activeShare = 0.1,
		public readonly int $segmentInterval = 15,
		public readonly int $baseInterval = 14_400,
		public readonly int $retentionDays = 365,
		public readonly int $deploysPerYear = 150,
		public readonly string $accessChurn = self::ACCESS_EVENT,
		public readonly bool $captureFiles = false,
		public readonly array $opsPerDay = [],
	) {
		if (
			$users < 0 ||
			$nodes < 0 ||
			$rows < 0 ||
			$files < 0 ||
			$fileBytes < 0 ||
			$deploysPerYear < 0
		) {
			throw new InvalidArgumentException('A measurement cannot count anything below zero');
		}
		if ($activeShare < 0.0 || $activeShare > 1.0) {
			throw new InvalidArgumentException('The active share is a fraction between 0 and 1');
		}
		if ($segmentInterval < 1 || $baseInterval < 1 || $retentionDays < 1) {
			throw new InvalidArgumentException('An interval and a retention must be at least one');
		}
		if (!in_array($accessChurn, self::ACCESS_MODES, true)) {
			throw new InvalidArgumentException(
				sprintf('Unknown access churn mode "%s"', $accessChurn),
			);
		}
	}

	/**
	 * How many distinct things the history describes.
	 *
	 * The number the tree cost scales with, and the reason a user costs about ten times what a node
	 * costs: both are one subject, but a user is written to far more often.
	 *
	 * @return int
	 *   Subjects.
	 */
	public function subjects(): int
	{
		return $this->users + $this->nodes + $this->rows;
	}

	/**
	 * Users active on a given day.
	 *
	 * @return float
	 *   The count, fractional because it is a rate rather than a headcount.
	 */
	public function activeUsers(): float
	{
		return $this->users * $this->activeShare;
	}

	/**
	 * Flushes a month, which is what the write-request bill is made of.
	 *
	 * Independent of the site's size, which is the whole point of stating it separately: a
	 * thousand-user site and a million-user site perform the same number of flushes.
	 *
	 * @return int
	 *   Flushes.
	 */
	public function flushesPerMonth(): int
	{
		return (int) round(Estimator::SECONDS_PER_MONTH / $this->segmentInterval);
	}

	/**
	 * Base anchors a year.
	 *
	 * @return int
	 *   Bases.
	 */
	public function basesPerYear(): int
	{
		return (int) round(Estimator::SECONDS_PER_YEAR / $this->baseInterval);
	}

	/**
	 * The same measurement with one field replaced.
	 *
	 * What a capacity search and a what-if column both need: an estimate is run many times over one
	 * shape with one dial moved, and a value object that cannot be varied would force the caller to
	 * repeat every other field.
	 *
	 * @param array<string, mixed> $changes
	 *   Field name keyed to its new value.
	 *
	 * @return Measurement
	 *   The new measurement.
	 */
	public function with(array $changes): self
	{
		$values = $this->jsonSerialize();

		foreach ($changes as $field => $value) {
			if (!array_key_exists($field, $values)) {
				throw new InvalidArgumentException(
					sprintf('Unknown measurement field "%s"', $field),
				);
			}

			$values[$field] = $value;
		}

		return self::fromArray($values);
	}

	/**
	 * A measurement from its serialized form.
	 *
	 * @param array<string, mixed> $values
	 *   The fields, any of which may be missing.
	 *
	 * @return Measurement
	 *   The measurement.
	 */
	public static function fromArray(array $values): self
	{
		/** @var array<string, float> $ops */
		$ops = is_array($values['ops_per_day'] ?? null) ? $values['ops_per_day'] : [];

		return new self(
			(int) ($values['users'] ?? 0),
			(int) ($values['nodes'] ?? 0),
			(int) ($values['rows'] ?? 0),
			(int) ($values['files'] ?? 0),
			(int) ($values['file_bytes'] ?? 0),
			(float) ($values['active_share'] ?? 0.1),
			(int) ($values['segment_interval'] ?? 15),
			(int) ($values['base_interval'] ?? 14_400),
			(int) ($values['retention_days'] ?? 365),
			(int) ($values['deploys_per_year'] ?? 150),
			(string) ($values['access_churn'] ?? self::ACCESS_EVENT),
			(bool) ($values['capture_files'] ?? false),
			$ops,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The measurement as data.
	 */
	public function jsonSerialize(): array
	{
		return [
			'users' => $this->users,
			'nodes' => $this->nodes,
			'rows' => $this->rows,
			'files' => $this->files,
			'file_bytes' => $this->fileBytes,
			'active_share' => $this->activeShare,
			'segment_interval' => $this->segmentInterval,
			'base_interval' => $this->baseInterval,
			'retention_days' => $this->retentionDays,
			'deploys_per_year' => $this->deploysPerYear,
			'access_churn' => $this->accessChurn,
			'capture_files' => $this->captureFiles,
			'ops_per_day' => $this->opsPerDay,
		];
	}
}
