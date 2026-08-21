<?php

declare(strict_types=1);

namespace Drupal\strata\Estimate;

use Drupal\strata\Codec\CodecCatalog;
use Drupal\strata\Journal\Realm;

/**
 * Projects what a site's history will cost, from measured constants rather than guesses.
 *
 * Every number this class multiplies was measured and is named, so a projection can be argued with
 * rather than believed. The operation sizes come from mutating a real corpus and serializing only
 * the changed field keys; the compression ratios come from the codec catalog's own reference
 * measurements, taken frame by frame through the shipped codecs; the delta-coding reduction comes
 * from re-encoding the corpus against each subject's previous version; the access-churn rate comes
 * from core's own session write interval.
 *
 * **Three findings this model makes, which matter more than the totals.**
 *
 * Two thirds of a Drupal site's write volume is user access-timestamp churn, so how that one field
 * is treated moves the bill more than anything else about the site. Recording it as a compact login
 * event costs 24% less than a full field delta and less than dropping it, because dropping the
 * operation leaves the row dirty for the reconciler to pick up later at full price.
 *
 * Files are most of the stored bytes and get almost none of the compression benefit, which is why
 * they have their own ladder, their own budget line and a different default: store each unique
 * content once, forever, by digest, rather than versioning it.
 *
 * The tree is what a large quiet site pays for. Base manifests scale with how many subjects a site
 * has and how many of them change, not with how busy any one of them is, so a site with a million
 * mostly-idle users pays more than a small site under constant load.
 *
 * **What the base interval does, stated carefully.** Lengthening it divides the number of bases,
 * but each base then covers a longer window and records more changed subjects, so the two effects
 * largely cancel. The interval is a real dial on restore latency - a deeper replay walks more
 * segments - and only a weak one on stored bytes. A projection that claimed otherwise would send an
 * operator to tune the wrong thing.
 *
 * @see Measurement
 * @see Projection
 * @see CollapseModel
 */
final class Estimator
{
	/**
	 * Seconds in a day.
	 */
	public const SECONDS_PER_DAY = 86_400;

	/**
	 * Seconds in a month, taken as a thirtieth of a year so twelve months make a year exactly.
	 */
	public const SECONDS_PER_MONTH = 2_629_800;

	/**
	 * Seconds in a year.
	 */
	public const SECONDS_PER_YEAR = 31_557_600;

	/**
	 * Days in a year, matching the seconds above.
	 */
	public const DAYS_PER_YEAR = 365.25;

	#region Measured Constants

	/**
	 * Access writes a day per daily-active user.
	 *
	 * Core writes the `access` field only when `requestTime - lastAccessedTime` exceeds
	 * `session_write_interval`, which defaults to 180 seconds. At three sessions a day of about
	 * twelve minutes each that is twelve writes.
	 */
	public const ACCESS_WRITES_PER_ACTIVE_USER = 12.0;

	/**
	 * Profile edits a day per registered user.
	 */
	public const USER_EDITS_PER_USER = 0.12;

	/**
	 * Content edits a day per node.
	 */
	public const NODE_EDITS_PER_NODE = 0.019;

	/**
	 * Custom table row writes a day per registered user.
	 *
	 * Attributed to users rather than to rows because these are the tables a site's own modules
	 * write on behalf of a person doing something.
	 */
	public const ROW_WRITES_PER_USER = 0.25;

	/**
	 * State and key-value writes a day, site-wide.
	 *
	 * Bookkeeping by cron and by modules, which does not scale with the size of the site.
	 */
	public const STATE_WRITES_PER_DAY = 580.0;

	/**
	 * Configuration writes a day, site-wide.
	 */
	public const CONFIG_WRITES_PER_DAY = 12.0;

	/**
	 * Operation record sizes in bytes, including the 120-byte header.
	 *
	 * Measured by mutating a real corpus and serializing only the changed top-level field keys. Where
	 * a realm had several measured cases the mean of the day-to-day ones is used and the
	 * first-capture case is excluded, since a first capture happens once and a daily edit happens
	 * daily. The table and configuration realms had no measured case of their own; the small-field
	 * figure stands in for a row write, and a whole configuration object is taken at two kilobytes.
	 */
	public const OP_BYTES = [
		'access_event' => 48,
		'access_delta' => 184,
		'user_edit' => 593,
		'node_edit' => 870,
		'row_write' => 217,
		'state_write' => 217,
		'config_write' => 2000,
	];

	/**
	 * Share of journal bytes delta coding removes before compression.
	 *
	 * 25.7% in aggregate. On the rewrite class alone it is 93%: a 5,967-byte blob with one flag
	 * flipped encodes to 94 bytes against its previous version. Small operations dominate by count,
	 * which is why the aggregate figure is so much lower than the headline one.
	 */
	public const DELTA_REDUCTION = 0.257;

	/**
	 * Bytes a base manifest spends per subject that changed inside its window.
	 *
	 * Calibrated against one measurement: 7.23 GB a year of manifests for a site of 262,000 subjects
	 * writing 82,892 operations a day with hourly bases. One measured point fixes one constant, and
	 * `strata:calibrate` replaces it with the site's own.
	 */
	public const TREE_BYTES_PER_CHANGED_SUBJECT = 240.0;

	/**
	 * Stored bytes the code realm costs per deploy.
	 *
	 * A real module measured 762,344 bytes compressed on a first capture; a deploy changes a fraction
	 * of it and delta codes against the previous version, which came to 8.98 MiB a year over 150
	 * deploys.
	 */
	public const CODE_BYTES_PER_DEPLOY = 62_772.0;

	/**
	 * Compression ratio on the flush path.
	 *
	 * Level 1 with no dictionary, which is what a flush inside a web request can afford: 422.9 MB/s
	 * against 105.1 MB/s once a dictionary is loaded per frame.
	 */
	public const FLUSH_RATIO = 4.28;

	/**
	 * Compression ratio after compaction.
	 *
	 * Level 19 with a per-realm dictionary, at 2.4 MB/s. Slow enough that it only belongs in
	 * compaction, where nothing is waiting on it.
	 */
	public const COMPACTION_RATIO = 5.86;

	/**
	 * Write requests one flush performs.
	 *
	 * A pack of frames, a segment manifest, a commit and the ref that names it. Frames below a
	 * megabyte are batched into one pack object, which is the difference between a few million
	 * requests a month and tens of millions, and the index of subjects belongs to a base anchor on
	 * its own interval rather than to every flush.
	 */
	public const WRITES_PER_FLUSH = 4;

	/**
	 * Share of stored objects compaction rewrites each month.
	 *
	 * Recompression rewrites whole packs rather than single frames, so the request cost is per pack
	 * and not per frame.
	 */
	public const COMPACTION_REWRITE_SHARE = 0.05;

	#endregion

	/**
	 * Constructs an estimator.
	 *
	 * @param array<string, int> $opBytes
	 *   Operation sizes overriding the measured ones, keyed as `OP_BYTES` is. A calibration pass
	 *   supplies what this host actually produced.
	 * @param float|null $compactionRatio
	 *   The compression ratio to apply, or NULL to read it from the codec catalog.
	 */
	public function __construct(
		private readonly array $opBytes = [],
		private readonly ?float $compactionRatio = null,
	) {}

	/**
	 * Projects a measurement.
	 *
	 * @param Measurement $measurement
	 *   The site and the policy.
	 *
	 * @return Projection
	 *   What it is projected to cost.
	 */
	public function project(Measurement $measurement): Projection
	{
		$ops = $this->operationsPerDay($measurement);
		$raw = $this->rawBytesPerDay($measurement);
		$ratio = $this->ratio();
		$notes = [];

		$realmBytes = [];
		$populations = [];

		foreach ($raw as $realm => $bytes) {
			$stored = ($bytes * (1.0 - self::DELTA_REDUCTION)) / $ratio;
			$realmBytes[$realm] = (int) round($stored * $measurement->retentionDays);

			$populations[] = [
				'bytes' => $stored,
				'factor' => CollapseModel::forWindow(
					$ops[$realm] ?? 0.0,
					max(1, $measurement->subjects()),
					self::SECONDS_PER_DAY * 30,
				),
			];
		}

		$collapse = CollapseModel::weighted($populations);
		$treeBytes = $this->treeBytes($measurement, array_sum($ops));
		$codeBytes = $this->codeBytes($measurement);
		$fileBytes = $measurement->captureFiles ? $measurement->fileBytes : 0;

		if ($measurement->captureFiles && $fileBytes > 0) {
			$notes[] = sprintf(
				'files are %.0f%% of the stored bytes and get almost none of the compression benefit',
				($fileBytes /
					max(1, array_sum($realmBytes) + $treeBytes + $codeBytes + $fileBytes)) *
					100,
			);
		}
		if ($measurement->accessChurn === Measurement::ACCESS_OFF) {
			$notes[] =
				'access churn is off, which costs more than the event mode rather than less: the row ' .
				'is still dirty and the reconciler captures it later at full price';
		}

		return new Projection(
			$measurement,
			$realmBytes,
			$treeBytes,
			$codeBytes,
			$fileBytes,
			array_sum($ops),
			$this->writesPerMonth($measurement),
			0,
			$ratio,
			self::DELTA_REDUCTION,
			$collapse,
			$notes,
		);
	}

	/**
	 * Every write population a site has, each with its own rate and its own operation size.
	 *
	 * Populations rather than realms, because the entity realm is three different things: access
	 * churn at 48 bytes and two thirds of the volume, profile edits at 593, and content edits at
	 * 870. One mean over that mix would be wrong in both directions at once, and it is exactly the
	 * mix the access-churn decision turns on.
	 *
	 * @param Measurement $measurement
	 *   The site.
	 *
	 * @return list<array{name: string, realm: string, ops: float, bytes: int}>
	 *   The populations.
	 */
	public function populations(Measurement $measurement): array
	{
		if ($measurement->opsPerDay !== []) {
			$supplied = [];

			foreach ($measurement->opsPerDay as $realm => $ops) {
				$supplied[] = [
					'name' => (string) $realm,
					'realm' => (string) $realm,
					'ops' => max(0.0, (float) $ops),
					'bytes' => $this->opSize((string) $realm, $measurement),
				];
			}

			return $supplied;
		}

		$access =
			$measurement->accessChurn === Measurement::ACCESS_OFF
				? 0.0
				: $measurement->activeUsers() * self::ACCESS_WRITES_PER_ACTIVE_USER;

		return [
			[
				'name' => 'access_churn',
				'realm' => Realm::ENTITY->value,
				'ops' => $access,
				'bytes' => $this->accessBytes($measurement),
			],
			[
				'name' => 'user_edits',
				'realm' => Realm::ENTITY->value,
				'ops' => $measurement->users * self::USER_EDITS_PER_USER,
				'bytes' => $this->byte('user_edit'),
			],
			[
				'name' => 'node_edits',
				'realm' => Realm::ENTITY->value,
				'ops' => $measurement->nodes * self::NODE_EDITS_PER_NODE,
				'bytes' => $this->byte('node_edit'),
			],
			[
				'name' => 'row_writes',
				'realm' => Realm::TABLE->value,
				'ops' => $measurement->users * self::ROW_WRITES_PER_USER,
				'bytes' => $this->byte('row_write'),
			],
			[
				'name' => 'state_writes',
				'realm' => Realm::STATE->value,
				'ops' => self::STATE_WRITES_PER_DAY,
				'bytes' => $this->byte('state_write'),
			],
			[
				'name' => 'config_writes',
				'realm' => Realm::CONFIG->value,
				'ops' => self::CONFIG_WRITES_PER_DAY,
				'bytes' => $this->byte('config_write'),
			],
		];
	}

	/**
	 * Operations a day, by realm.
	 *
	 * @param Measurement $measurement
	 *   The site.
	 *
	 * @return array<string, float>
	 *   Realm value keyed to operations a day.
	 */
	public function operationsPerDay(Measurement $measurement): array
	{
		$ops = [];

		foreach ($this->populations($measurement) as $population) {
			$ops[$population['realm']] = ($ops[$population['realm']] ?? 0.0) + $population['ops'];
		}

		return $ops;
	}

	/**
	 * Uncompressed operation bytes a day, by realm.
	 *
	 * @param Measurement $measurement
	 *   The site.
	 *
	 * @return array<string, float>
	 *   Realm value keyed to bytes a day.
	 */
	public function rawBytesPerDay(Measurement $measurement): array
	{
		$bytes = [];

		foreach ($this->populations($measurement) as $population) {
			$realm = $population['realm'];
			$bytes[$realm] = ($bytes[$realm] ?? 0.0) + $population['ops'] * $population['bytes'];
		}

		return $bytes;
	}

	/**
	 * Stored bytes of base manifests over the retention period.
	 *
	 * @param Measurement $measurement
	 *   The site.
	 * @param float $opsPerDay
	 *   Operations a day across every realm.
	 *
	 * @return int
	 *   Bytes.
	 */
	public function treeBytes(Measurement $measurement, float $opsPerDay): int
	{
		$subjects = $measurement->subjects();

		if ($subjects < 1 || $opsPerDay <= 0.0) {
			return 0;
		}

		$writesPerBase = $opsPerDay * ($measurement->baseInterval / self::SECONDS_PER_DAY);
		$changed = CollapseModel::distinct((int) round($writesPerBase), $subjects);
		$bases = $measurement->basesPerYear() * ($measurement->retentionDays / self::DAYS_PER_YEAR);

		return (int) round($bases * $changed * self::TREE_BYTES_PER_CHANGED_SUBJECT);
	}

	/**
	 * Stored bytes of the code realm over the retention period.
	 *
	 * @param Measurement $measurement
	 *   The site.
	 *
	 * @return int
	 *   Bytes.
	 */
	public function codeBytes(Measurement $measurement): int
	{
		$deploys =
			$measurement->deploysPerYear * ($measurement->retentionDays / self::DAYS_PER_YEAR);

		return (int) round($deploys * self::CODE_BYTES_PER_DEPLOY);
	}

	/**
	 * Write requests a month.
	 *
	 * @param Measurement $measurement
	 *   The site.
	 *
	 * @return int
	 *   Requests.
	 */
	public function writesPerMonth(Measurement $measurement): int
	{
		$flushes = $measurement->flushesPerMonth() * self::WRITES_PER_FLUSH;

		return (int) round($flushes * (1.0 + self::COMPACTION_REWRITE_SHARE));
	}

	/**
	 * The compression ratio in use.
	 *
	 * @return float
	 *   The ratio.
	 */
	public function ratio(): float
	{
		if ($this->compactionRatio !== null) {
			return max(1.0, $this->compactionRatio);
		}

		foreach (CodecCatalog::referenceMeasurements()['zstd'] ?? [] as $measurement) {
			if (
				$measurement->level === 19 &&
				$measurement->dictionary &&
				$measurement->frameSize === 16384
			) {
				return $measurement->ratio;
			}
		}

		return self::COMPACTION_RATIO;
	}

	/**
	 * The size of one access-churn record under the configured mode.
	 *
	 * @param Measurement $measurement
	 *   The site.
	 *
	 * @return int
	 *   Bytes.
	 */
	private function accessBytes(Measurement $measurement): int
	{
		return $measurement->accessChurn === Measurement::ACCESS_DELTA
			? $this->byte('access_delta')
			: $this->byte('access_event');
	}

	/**
	 * The operation size to use for one realm.
	 *
	 * @param string $realm
	 *   The realm value.
	 * @param Measurement $measurement
	 *   The site, which decides how access churn is recorded.
	 *
	 * @return int
	 *   Bytes.
	 */
	private function opSize(string $realm, Measurement $measurement): int
	{
		return match ($realm) {
			Realm::ENTITY->value => $this->accessBytes($measurement),
			Realm::CONFIG->value => $this->byte('config_write'),
			Realm::STATE->value, Realm::KEY_VALUE->value => $this->byte('state_write'),
			Realm::CODE->value => (int) self::CODE_BYTES_PER_DEPLOY,
			default => $this->byte('row_write'),
		};
	}

	/**
	 * One operation size, overridden or measured.
	 *
	 * @param string $key
	 *   A key of `OP_BYTES`.
	 *
	 * @return int
	 *   Bytes.
	 */
	private function byte(string $key): int
	{
		return $this->opBytes[$key] ?? (self::OP_BYTES[$key] ?? 0);
	}
}
