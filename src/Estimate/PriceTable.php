<?php

declare(strict_types=1);

namespace Drupal\strata\Estimate;

use InvalidArgumentException;
use JsonSerializable;

/**
 * What one provider charges, and what it gives away.
 *
 * Object storage is priced on three axes and only one of them is bytes. Storage is charged per
 * gigabyte-month, writes are charged per million (S3 calls them PUT, R2 calls them Class A), and
 * reads are charged per million (GET, Class B). A backup system is unusual in that it writes
 * constantly and reads almost never, so the write axis is the one that decides the bill - and it
 * depends on the flush interval rather than on the size of the site.
 *
 * The consequence is stated in one line: at a fifteen-second interval a site of any size performs
 * about 201,635 writes a month, which is a fifth of R2's free allowance and about a dollar a month
 * on S3. On R2 the free tier absorbs the whole thing; on S3 that dollar is a floor a small site
 * cannot get under.
 *
 * **These are shipped defaults, not a promise.** Published list prices as read on 2026-08-20, for
 * R2 and for S3 Standard in `us-east-1`. A provider changes its prices and a different region
 * charges differently, so every field is overridable and the estimate names the table it used.
 *
 * @see Estimator
 * @see FreeTierModel
 */
final class PriceTable implements JsonSerializable
{
	/**
	 * Bytes in a gigabyte, decimal, as every provider bills.
	 *
	 * Providers price per GB and mean 10^9, not 2^30. Using the binary value would understate a
	 * bill by 7.4%.
	 */
	public const BYTES_PER_GB = 1_000_000_000;

	/**
	 * Requests in the unit prices are quoted in.
	 */
	public const REQUESTS_PER_UNIT = 1_000_000;

	/**
	 * Constructs a table.
	 *
	 * @param string $id
	 *   Short lowercase identifier, matching the storage provider's own id where there is one.
	 * @param string $label
	 *   Human-readable name.
	 * @param float $storagePerGbMonth
	 *   Dollars per gigabyte-month of stored data.
	 * @param float $writesPerMillion
	 *   Dollars per million write requests.
	 * @param float $readsPerMillion
	 *   Dollars per million read requests.
	 * @param float $egressPerGb
	 *   Dollars per gigabyte of egress. Zero on R2, which is the reason a restore is free there.
	 * @param int $freeStorageBytes
	 *   Stored bytes included at no charge each month.
	 * @param int $freeWrites
	 *   Write requests included at no charge each month.
	 * @param int $freeReads
	 *   Read requests included at no charge each month.
	 *
	 * @throws InvalidArgumentException
	 *   When the id is empty or any price is negative.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly float $storagePerGbMonth,
		public readonly float $writesPerMillion,
		public readonly float $readsPerMillion,
		public readonly float $egressPerGb = 0.0,
		public readonly int $freeStorageBytes = 0,
		public readonly int $freeWrites = 0,
		public readonly int $freeReads = 0,
	) {
		if (trim($id) === '') {
			throw new InvalidArgumentException('A price table needs an id');
		}
		if (
			$storagePerGbMonth < 0 ||
			$writesPerMillion < 0 ||
			$readsPerMillion < 0 ||
			$egressPerGb < 0
		) {
			throw new InvalidArgumentException('A price cannot be negative');
		}
		if ($freeStorageBytes < 0 || $freeWrites < 0 || $freeReads < 0) {
			throw new InvalidArgumentException('A free allowance cannot be negative');
		}
	}

	#region Shipped Tables

	/**
	 * Cloudflare R2.
	 *
	 * Egress is free, which is what makes a restore cost nothing to pull, and the free tier is
	 * generous enough that a full year of database history for a large site fits inside it.
	 *
	 * @return PriceTable
	 *   The table.
	 */
	public static function r2(): self
	{
		return new self(
			'r2',
			'Cloudflare R2',
			0.015,
			4.5,
			0.36,
			0.0,
			10 * self::BYTES_PER_GB,
			1_000_000,
			10_000_000,
		);
	}

	/**
	 * AWS S3 Standard, us-east-1.
	 *
	 * No free allowance beyond the first-year introductory tier, which is not modelled because an
	 * estimate that expires after twelve months is worse than one that does not exist.
	 *
	 * @return PriceTable
	 *   The table.
	 */
	public static function s3(): self
	{
		return new self('s3', 'AWS S3 Standard', 0.023, 5.0, 0.4, 0.09);
	}

	/**
	 * AWS S3 Standard-Infrequent Access.
	 *
	 * Where file blocks belong. Cheaper to keep and dearer to read, which is the right trade for
	 * content that is written once and read only during a restore.
	 *
	 * @return PriceTable
	 *   The table.
	 */
	public static function s3InfrequentAccess(): self
	{
		return new self('s3_ia', 'AWS S3 Standard-IA', 0.0125, 10.0, 1.0, 0.09);
	}

	/**
	 * A store on the server's own disk.
	 *
	 * Priced at zero rather than excluded, so the estimator can compare a local target against a
	 * remote one without a special case. Disk is not free, but its cost is not per-request and is
	 * not something this module can read.
	 *
	 * @return PriceTable
	 *   The table.
	 */
	public static function local(): self
	{
		return new self('local', 'Local filesystem', 0.0, 0.0, 0.0);
	}

	/**
	 * Every shipped table, keyed by id.
	 *
	 * @return array<string, PriceTable>
	 *   The tables.
	 */
	public static function all(): array
	{
		$tables = [self::r2(), self::s3(), self::s3InfrequentAccess(), self::local()];
		$keyed = [];

		foreach ($tables as $table) {
			$keyed[$table->id] = $table;
		}

		return $keyed;
	}

	/**
	 * One shipped table by id.
	 *
	 * @param string $id
	 *   The id.
	 *
	 * @return PriceTable
	 *   The table.
	 *
	 * @throws InvalidArgumentException
	 *   When no shipped table has that id.
	 */
	public static function of(string $id): self
	{
		$tables = self::all();

		if (!isset($tables[$id])) {
			throw new InvalidArgumentException(sprintf('No price table is shipped for "%s"', $id));
		}

		return $tables[$id];
	}

	/**
	 * The table to cost a storage provider with, falling back rather than raising.
	 *
	 * Four tables are shipped and the provider select offers every registered submodule, so
	 * `azure`, `gcs`, `b2` and `null` all name a provider with no published prices - as does any id
	 * an add-on registers. PriceTable::of() is the right shape for a caller naming a table it knows
	 * exists; a caller naming whatever a site happens to have configured needs this one, because the
	 * throw lands on the settings page the operator would use to change the provider back.
	 *
	 * @param string $id
	 *   A storage provider id, which may be empty or unknown.
	 *
	 * @return self
	 *   The table for that provider, or the local one, which charges nothing.
	 */
	public static function forProvider(string $id): self
	{
		return self::all()[$id] ?? self::local();
	}

	#endregion

	#region Costing

	/**
	 * What storing this many bytes costs for a month, after the free allowance.
	 *
	 * @param int $bytes
	 *   Stored bytes.
	 *
	 * @return float
	 *   Dollars.
	 */
	public function storageCost(int $bytes): float
	{
		$billable = max(0, $bytes - $this->freeStorageBytes);

		return ($billable / self::BYTES_PER_GB) * $this->storagePerGbMonth;
	}

	/**
	 * What this many write requests cost for a month, after the free allowance.
	 *
	 * @param int $requests
	 *   Write requests.
	 *
	 * @return float
	 *   Dollars.
	 */
	public function writeCost(int $requests): float
	{
		$billable = max(0, $requests - $this->freeWrites);

		return ($billable / self::REQUESTS_PER_UNIT) * $this->writesPerMillion;
	}

	/**
	 * What this many read requests cost for a month, after the free allowance.
	 *
	 * @param int $requests
	 *   Read requests.
	 *
	 * @return float
	 *   Dollars.
	 */
	public function readCost(int $requests): float
	{
		$billable = max(0, $requests - $this->freeReads);

		return ($billable / self::REQUESTS_PER_UNIT) * $this->readsPerMillion;
	}

	/**
	 * What egressing this many bytes costs.
	 *
	 * Only a restore or a verify egresses anything, so this is zero in the steady state and is
	 * shown for the restore estimate rather than the monthly one.
	 *
	 * @param int $bytes
	 *   Bytes read out.
	 *
	 * @return float
	 *   Dollars.
	 */
	public function egressCost(int $bytes): float
	{
		return ($bytes / self::BYTES_PER_GB) * $this->egressPerGb;
	}

	/**
	 * The monthly bill for a given usage.
	 *
	 * @param int $bytes
	 *   Stored bytes.
	 * @param int $writes
	 *   Write requests per month.
	 * @param int $reads
	 *   Read requests per month.
	 *
	 * @return float
	 *   Dollars per month.
	 */
	public function monthlyCost(int $bytes, int $writes, int $reads = 0): float
	{
		return $this->storageCost($bytes) + $this->writeCost($writes) + $this->readCost($reads);
	}

	/**
	 * Whether this provider gives anything away.
	 *
	 * @return bool
	 *   TRUE when any allowance is non-zero.
	 */
	public function hasFreeTier(): bool
	{
		return $this->freeStorageBytes > 0 || $this->freeWrites > 0 || $this->freeReads > 0;
	}

	#endregion

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The table as data.
	 */
	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'label' => $this->label,
			'storage_per_gb_month' => $this->storagePerGbMonth,
			'writes_per_million' => $this->writesPerMillion,
			'reads_per_million' => $this->readsPerMillion,
			'egress_per_gb' => $this->egressPerGb,
			'free_storage_bytes' => $this->freeStorageBytes,
			'free_writes' => $this->freeWrites,
			'free_reads' => $this->freeReads,
		];
	}
}
