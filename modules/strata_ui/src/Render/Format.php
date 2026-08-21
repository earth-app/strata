<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Render;

use Drupal\strata\Cas\Hash;

/**
 * The handful of value formatters every Strata page needs.
 *
 * Static because they are pure functions of their input, and shared because a byte count rendered one
 * way in a table and another way in a chart axis reads as two different numbers.
 *
 * @see Chart
 */
final class Format
{
	/**
	 * Byte units, smallest first.
	 */
	public const UNITS = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];

	/**
	 * Renders a byte count in the largest unit that stays readable.
	 *
	 * @param float|int $bytes
	 *   The count.
	 * @param int $decimals
	 *   Decimal places above the byte unit.
	 *
	 * @return string
	 *   The rendered size.
	 */
	public static function bytes(float|int $bytes, int $decimals = 1): string
	{
		$value = (float) $bytes;
		$sign = $value < 0.0 ? '-' : '';
		$value = abs($value);
		$unit = 0;

		while ($value >= 1024.0 && $unit < count(self::UNITS) - 1) {
			$value /= 1024.0;
			$unit++;
		}

		return $unit === 0
			? sprintf('%s%d %s', $sign, (int) round($value), self::UNITS[$unit])
			: sprintf('%s%.' . max(0, $decimals) . 'f %s', $sign, $value, self::UNITS[$unit]);
	}

	/**
	 * Renders a duration in the largest unit that stays readable.
	 *
	 * @param float $seconds
	 *   The duration.
	 *
	 * @return string
	 *   Something such as "4.2 s" or "3 days".
	 */
	public static function duration(float $seconds): string
	{
		$seconds = abs($seconds);

		return match (true) {
			$seconds < 0.001 => sprintf('%.0f us', $seconds * 1_000_000),
			$seconds < 1.0 => sprintf('%.0f ms', $seconds * 1000),
			$seconds < 60.0 => sprintf('%.1f s', $seconds),
			$seconds < 3_600.0 => sprintf('%.0f min', $seconds / 60),
			$seconds < 86_400.0 => sprintf('%.1f hours', $seconds / 3_600),
			default => sprintf('%.1f days', $seconds / 86_400),
		};
	}

	/**
	 * Renders a count with thousands separators.
	 *
	 * @param float|int $count
	 *   The count.
	 *
	 * @return string
	 *   The rendered count.
	 */
	public static function count(float|int $count): string
	{
		return number_format((float) $count, 0, '.', ',');
	}

	/**
	 * Renders a ratio.
	 *
	 * @param float $ratio
	 *   The ratio.
	 *
	 * @return string
	 *   Something such as "5.86x".
	 */
	public static function ratio(float $ratio): string
	{
		return sprintf('%.2fx', $ratio);
	}

	/**
	 * Renders a monetary amount.
	 *
	 * A figure under a cent is rendered with enough places to be non-zero, because "$0.00" reads as
	 * free and a projection that is genuinely nearly free should say how nearly.
	 *
	 * @param float $dollars
	 *   The amount.
	 *
	 * @return string
	 *   The rendered amount.
	 */
	public static function money(float $dollars): string
	{
		if ($dollars > 0.0 && $dollars < 0.01) {
			return sprintf('$%.4f', $dollars);
		}

		return sprintf('$%.2f', $dollars);
	}

	/**
	 * Renders a value in whatever unit a series declares.
	 *
	 * @param float $value
	 *   The value.
	 * @param string $unit
	 *   The unit a series carries.
	 *
	 * @return string
	 *   The rendered value.
	 */
	public static function inUnit(float $value, string $unit): string
	{
		return match ($unit) {
			'bytes' => self::bytes($value),
			'dollars' => self::money($value),
			'x' => self::ratio($value),
			's', 'seconds' => self::duration($value),
			'' => self::count($value),
			default => self::count($value) . ' ' . $unit,
		};
	}

	/**
	 * Shortens a content address for a table cell.
	 *
	 * @param string $address
	 *   The address.
	 * @param int $length
	 *   Characters to keep.
	 *
	 * @return string
	 *   The abbreviated address, or the input unchanged when it is not an address.
	 */
	public static function address(string $address, int $length = 12): string
	{
		return Hash::isValid($address) ? Hash::abbreviate($address, $length) : $address;
	}

	/**
	 * Renders a unix microsecond stamp as a moment.
	 *
	 * @param int $microtime
	 *   Unix microseconds.
	 *
	 * @return string
	 *   An ISO-8601 moment with milliseconds, in UTC.
	 */
	public static function moment(int $microtime): string
	{
		$seconds = intdiv($microtime, 1_000_000);
		$millis = intdiv($microtime % 1_000_000, 1000);

		return sprintf('%s.%03dZ', gmdate('Y-m-d\TH:i:s', $seconds), $millis);
	}
}
