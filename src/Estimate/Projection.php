<?php

declare(strict_types=1);

namespace Drupal\strata\Estimate;

use JsonSerializable;

/**
 * What a measurement is projected to cost.
 *
 * Broken down rather than totalled, because the total answers no question anybody asks. An operator
 * looking at a bill wants to know which realm is spending it, and the answer is almost always one
 * of two things: files, which are most of the bytes and get almost none of the compression benefit,
 * or the tree, which scales with how many subjects a site has rather than how busy it is.
 *
 * Storage and requests are reported separately for the same reason. They are priced on different
 * axes and pulled by different dials: bytes by retention and by what is captured, requests by the
 * flush interval alone.
 *
 * @see Estimator
 * @see FreeTierModel
 */
final class Projection implements JsonSerializable
{
	/**
	 * Constructs a projection.
	 *
	 * @param Measurement $measurement
	 *   What was projected.
	 * @param array<string, int> $realmBytes
	 *   Realm value keyed to stored bytes over the retention period.
	 * @param int $treeBytes
	 *   Stored bytes of base manifests over the retention period.
	 * @param int $codeBytes
	 *   Stored bytes of the code realm over the retention period.
	 * @param int $fileBytes
	 *   Stored bytes of file content, zero when the file realm is off.
	 * @param float $opsPerDay
	 *   Operations captured a day.
	 * @param int $writesPerMonth
	 *   Write requests a month, which is the flush count plus what compaction rewrites.
	 * @param int $readsPerMonth
	 *   Read requests a month, which only verification and restore generate.
	 * @param float $compressionRatio
	 *   The ratio applied to journal bytes.
	 * @param float $deltaReduction
	 *   Share of journal bytes delta coding removed before compression.
	 * @param float $collapse
	 *   The byte-weighted collapse factor the retention ladder achieves.
	 * @param list<string> $notes
	 *   What the reader should know about this projection, such as a dial that is doing most of the
	 *   work or an input that was clamped.
	 */
	public function __construct(
		public readonly Measurement $measurement,
		public readonly array $realmBytes = [],
		public readonly int $treeBytes = 0,
		public readonly int $codeBytes = 0,
		public readonly int $fileBytes = 0,
		public readonly float $opsPerDay = 0.0,
		public readonly int $writesPerMonth = 0,
		public readonly int $readsPerMonth = 0,
		public readonly float $compressionRatio = 1.0,
		public readonly float $deltaReduction = 0.0,
		public readonly float $collapse = 1.0,
		public readonly array $notes = [],
	) {}

	#region Bytes

	/**
	 * Stored bytes of captured operations, across every realm.
	 *
	 * @return int
	 *   Bytes.
	 */
	public function journalBytes(): int
	{
		return array_sum($this->realmBytes);
	}

	/**
	 * Everything stored over the retention period.
	 *
	 * @return int
	 *   Bytes.
	 */
	public function totalBytes(): int
	{
		return $this->journalBytes() + $this->treeBytes + $this->codeBytes + $this->fileBytes;
	}

	/**
	 * Everything stored, as gigabytes as a provider bills them.
	 *
	 * @return float
	 *   Gigabytes.
	 */
	public function totalGigabytes(): float
	{
		return $this->totalBytes() / PriceTable::BYTES_PER_GB;
	}

	/**
	 * What each component contributes, largest first.
	 *
	 * @return array<string, int>
	 *   Component name keyed to bytes.
	 */
	public function components(): array
	{
		$components = ['tree' => $this->treeBytes, 'code' => $this->codeBytes];

		if ($this->fileBytes > 0) {
			$components['files'] = $this->fileBytes;
		}

		foreach ($this->realmBytes as $realm => $bytes) {
			$components[$realm] = $bytes;
		}

		arsort($components);

		return $components;
	}

	/**
	 * Which component is spending the most.
	 *
	 * @return string
	 *   The component name, or an empty string when nothing is stored.
	 */
	public function dominantComponent(): string
	{
		$components = $this->components();

		return (string) (array_key_first($components) ?? '');
	}

	/**
	 * What share of the stored bytes one component is.
	 *
	 * @param string $component
	 *   The component name.
	 *
	 * @return float
	 *   A fraction between 0 and 1.
	 */
	public function share(string $component): float
	{
		$total = $this->totalBytes();

		return $total < 1 ? 0.0 : ($this->components()[$component] ?? 0) / $total;
	}

	#endregion

	#region Money

	/**
	 * The monthly bill on one provider.
	 *
	 * @param PriceTable $prices
	 *   The table to cost against.
	 *
	 * @return float
	 *   Dollars a month.
	 */
	public function monthlyCost(PriceTable $prices): float
	{
		return $prices->monthlyCost(
			$this->totalBytes(),
			$this->writesPerMonth,
			$this->readsPerMonth,
		);
	}

	/**
	 * The monthly bill on every shipped provider.
	 *
	 * @return array<string, float>
	 *   Provider id keyed to dollars a month.
	 */
	public function costs(): array
	{
		$costs = [];

		foreach (PriceTable::all() as $id => $table) {
			$costs[$id] = $this->monthlyCost($table);
		}

		return $costs;
	}

	#endregion

	/**
	 * One line describing the projection.
	 *
	 * @return string
	 *   The summary.
	 */
	public function summary(): string
	{
		return sprintf(
			'%.2f GB over %d days, %s requests a month, %.2f/mo on R2 and %.2f/mo on S3; %s is %.0f%% of it',
			$this->totalGigabytes(),
			$this->measurement->retentionDays,
			number_format($this->writesPerMonth),
			$this->monthlyCost(PriceTable::r2()),
			$this->monthlyCost(PriceTable::s3()),
			$this->dominantComponent(),
			$this->share($this->dominantComponent()) * 100,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The projection as data.
	 */
	public function jsonSerialize(): array
	{
		return [
			'measurement' => $this->measurement->jsonSerialize(),
			'realm_bytes' => $this->realmBytes,
			'tree_bytes' => $this->treeBytes,
			'code_bytes' => $this->codeBytes,
			'file_bytes' => $this->fileBytes,
			'journal_bytes' => $this->journalBytes(),
			'total_bytes' => $this->totalBytes(),
			'ops_per_day' => $this->opsPerDay,
			'writes_per_month' => $this->writesPerMonth,
			'reads_per_month' => $this->readsPerMonth,
			'compression_ratio' => $this->compressionRatio,
			'delta_reduction' => $this->deltaReduction,
			'collapse' => $this->collapse,
			'costs' => $this->costs(),
			'notes' => $this->notes,
		];
	}
}
