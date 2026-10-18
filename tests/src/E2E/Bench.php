<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\E2E;

use RuntimeException;

/**
 * Records what the container measured, and holds the exact figures to a committed baseline.
 *
 * A figure is either exact or a timing. An exact figure comes from a fixed workload through
 * deterministic code - requests per flush, bytes stored per operation, a compression ratio, a delta
 * chain depth - so it fails the run when it leaves the baseline by more than its tolerance. A timing
 * depends on the host and is only written to the report, where a trend can be read. Set
 * `E2E_UPDATE_BASELINE=1` to write the figures measured now into the baseline instead of checking.
 */
final class Bench
{
	/**
	 * Everything recorded in this process.
	 *
	 * @var array<string, array<string, array{value: float, unit: string, kind: string}>>
	 */
	private static array $recorded = [];

	/**
	 * Whether the report has been scheduled for the end of the process.
	 */
	private static bool $scheduled = false;

	/**
	 * Records an exact figure and checks it against the baseline.
	 *
	 * @param string $lane
	 *   Which backend the figure belongs to, such as `s3`.
	 * @param string $name
	 *   The figure's name.
	 * @param int|float $value
	 *   What was measured.
	 * @param string $unit
	 *   What it is counted in.
	 * @param float $tolerance
	 *   The fraction it may move from the baseline, so 0.05 is five percent.
	 *
	 * @return string|null
	 *   A description of the breach, or NULL when the figure is within bounds.
	 */
	public static function exact(
		string $lane,
		string $name,
		int|float $value,
		string $unit,
		float $tolerance = 0.05,
	): ?string {
		self::store($lane, $name, (float) $value, $unit, 'exact');

		if (getenv('E2E_UPDATE_BASELINE') === '1') {
			$baseline = self::baseline();
			$baseline[$lane][$name] = [
				'value' => round((float) $value, 4),
				'unit' => $unit,
				'tolerance' => $tolerance,
			];
			self::writeBaseline($baseline);

			return null;
		}

		$expected = self::baseline()[$lane][$name] ?? null;

		if ($expected === null) {
			return sprintf('%s %s has no baseline; run with E2E_UPDATE_BASELINE=1', $lane, $name);
		}

		$base = (float) $expected['value'];
		$allowed = abs($base) * (float) ($expected['tolerance'] ?? $tolerance);
		$moved = abs((float) $value - $base);

		return $moved <= $allowed + 1.0e-9
			? null
			: sprintf(
				'%s %s is %s %s, baseline %s %s with a tolerance of %s%%',
				$lane,
				$name,
				self::trim((float) $value),
				$unit,
				self::trim($base),
				$unit,
				self::trim((float) ($expected['tolerance'] ?? $tolerance) * 100),
			);
	}

	/**
	 * Records a timing, which is reported and never checked.
	 *
	 * @param string $lane
	 *   Which backend.
	 * @param string $name
	 *   The figure's name.
	 * @param int|float $value
	 *   What was measured.
	 * @param string $unit
	 *   What it is counted in.
	 */
	public static function timing(string $lane, string $name, int|float $value, string $unit): void
	{
		self::store($lane, $name, (float) $value, $unit, 'timing');
	}

	/**
	 * What the container is running under, for the report.
	 *
	 * @return array<string, mixed>
	 *   PHP, the compression and crypto extensions, and the cgroup limits when there are any.
	 */
	public static function conditions(): array
	{
		$read = static fn(string $path): ?string => is_readable($path)
			? trim((string) file_get_contents($path))
			: null;

		return [
			'php' => PHP_VERSION,
			'database' => Lane::database(),
			'extensions' => array_values(
				array_filter(
					['sodium', 'zstd', 'brotli', 'redis', 'zlib'],
					static fn(string $extension): bool => extension_loaded($extension),
				),
			),
			'memory_limit_bytes' => $read('/sys/fs/cgroup/memory.max'),
			'memory_current_bytes' => $read('/sys/fs/cgroup/memory.current'),
			'memory_peak_bytes' => $read('/sys/fs/cgroup/memory.peak'),
			'cpu_max' => $read('/sys/fs/cgroup/cpu.max'),
			'disk_free_bytes' => @disk_free_space('/srv/drupal/private'),
			'php_peak_bytes' => memory_get_peak_usage(true),
		];
	}

	/**
	 * The baseline file.
	 *
	 * @return string
	 *   Its path.
	 */
	public static function baselinePath(): string
	{
		return dirname(__DIR__, 2) . '/e2e/baseline.json';
	}

	/**
	 * Remembers a figure and makes sure the report is written when the run ends.
	 *
	 * @param string $lane
	 *   Which backend.
	 * @param string $name
	 *   The figure's name.
	 * @param float $value
	 *   What was measured.
	 * @param string $unit
	 *   What it is counted in.
	 * @param string $kind
	 *   Either `exact` or `timing`.
	 */
	private static function store(
		string $lane,
		string $name,
		float $value,
		string $unit,
		string $kind,
	): void {
		self::$recorded[$lane][$name] = ['value' => $value, 'unit' => $unit, 'kind' => $kind];

		if (!self::$scheduled) {
			self::$scheduled = true;
			register_shutdown_function(self::report(...));
		}
	}

	/**
	 * Writes the report for the run.
	 */
	private static function report(): void
	{
		$directory = dirname(__DIR__, 3) . '/build/e2e';

		if (!is_dir($directory)) {
			@mkdir($directory, 0777, true);
		}

		file_put_contents(
			$directory . '/report-' . Lane::database() . '.json',
			json_encode(
				['conditions' => self::conditions(), 'figures' => self::$recorded],
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
			) . "\n",
		);
	}

	/**
	 * The committed baseline.
	 *
	 * @return array<string, array<string, array{value: float, unit: string, tolerance?: float}>>
	 *   Backend to figure name to its expected value.
	 */
	private static function baseline(): array
	{
		$path = self::baselinePath();

		if (!is_file($path)) {
			return [];
		}

		$parsed = json_decode((string) file_get_contents($path), true);

		if (!is_array($parsed)) {
			throw new RuntimeException('The E2E baseline is not valid JSON');
		}

		return $parsed;
	}

	/**
	 * Writes the baseline with sorted keys, so a diff shows only what moved.
	 *
	 * @param array<string, array<string, mixed>> $baseline
	 *   The baseline.
	 */
	private static function writeBaseline(array $baseline): void
	{
		ksort($baseline);

		foreach ($baseline as &$figures) {
			ksort($figures);
		}

		unset($figures);

		file_put_contents(
			self::baselinePath(),
			json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
		);
	}

	/**
	 * A number without trailing zeros.
	 *
	 * @param float $value
	 *   The number.
	 *
	 * @return string
	 *   The text.
	 */
	private static function trim(float $value): string
	{
		return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
	}
}
