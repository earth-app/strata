<?php

declare(strict_types=1);

namespace Drupal\strata\Codec;

/**
 * The shipped reference figures for every codec, and how to install the ones that are missing.
 *
 * Where the numbers come from: a fixed 6,357,223-byte corpus of Drupal-shaped data - 1,000 node
 * images in `toArray()` shape, 1,000 user images carrying JSON field values, and 4,000
 * custom-table rows including hashed API tokens. Split into frames, each frame compressed
 * independently - which is what Strata actually does - with a zstd dictionary trained on 5% of
 * the frames. Measured on PHP 8.5.7, arm64, with
 * `ext-zstd` 0.18.0 and `ext-brotli` 0.21.0.
 *
 * What the numbers say, at 16 KiB frames, which is the shipped default:
 *
 * | codec  | level | dict | ratio | compress   | decompress |
 * | ------ | ----- | ---- | ----- | ---------- | ---------- |
 * | none   | -     | -    | 1.00x | 18,948 MB/s| -          |
 * | gzip   | 1     | no   | 3.84x | 180.6 MB/s | 589.8 MB/s |
 * | gzip   | 9     | no   | 4.40x | 67.0 MB/s  | 589.8 MB/s |
 * | zstd   | 1     | no   | 4.28x | 422.9 MB/s | 640.3 MB/s |
 * | zstd   | 1     | yes  | 4.67x | 105.1 MB/s | 640.3 MB/s |
 * | zstd   | 19    | no   | 4.87x | 4.5 MB/s   | 640.3 MB/s |
 * | zstd   | 19    | yes  | 5.86x | 2.4 MB/s   | 640.3 MB/s |
 * | brotli | 11    | no   | 5.24x | 1.1 MB/s   | 461.6 MB/s |
 * | brotli | 11    | yes  | 6.07x | 0.9 MB/s   | 461.6 MB/s |
 *
 * Three things follow, and they are why the defaults are what they are:
 *
 * - **zstd level 1 is the flush-path choice.** 4.28x at 422.9 MB/s against gzip level 9's 4.40x at
 *   67.0 MB/s: a fraction worse on size for more than six times the throughput, on a path that
 *   runs inside a web request.
 * - **A dictionary costs throughput, not just disk.** At level 1 it takes 422.9 MB/s down to
 *   105.1 MB/s for 4.28x to 4.67x. Whole-batch measurement hides this, because the dictionary is
 *   loaded once there and per frame here. It is used during compaction, where 5.86x against
 *   4.87x is worth 2.4 MB/s, and by delta coding, which cannot work without it.
 * - **brotli wins on size and loses on time.** 6.07x is the best figure here, at 0.9 MB/s. That is
 *   a compaction codec on a large site and nothing else.
 *
 * What they do not say: ratio depends on content. This corpus includes incompressible hashed
 * tokens, so it runs low against a prose-heavy site and high against one storing mostly media.
 * These figures exist so two hosts can be compared and so a size estimate has somewhere to start;
 * Calibrator measures the real thing on real site data and the settings form shows both, marking
 * which is which.
 *
 * @see CodecProfile
 * @see CodecRegistry
 */
final class CodecCatalog
{
	/**
	 * Builds a profile for every codec a registry knows about.
	 *
	 * @param CodecRegistry $registry
	 *   The registry to read availability from.
	 *
	 * @return list<CodecProfile>
	 *   Profiles in write-preference order, available codecs first.
	 */
	public static function profiles(CodecRegistry $registry): array
	{
		$profiles = [];

		foreach (['zstd', 'brotli', 'gzip', 'none'] as $id) {
			$profiles[] = self::profile($registry, $id);
		}

		// fully usable first, then degraded, then missing
		usort($profiles, static function (CodecProfile $a, CodecProfile $b): int {
			$rank = static fn(CodecProfile $p): int => ($p->available ? 0 : 2) +
				($p->perFrame ? 0 : 1);

			return $rank($a) <=> $rank($b);
		});

		return $profiles;
	}

	/**
	 * Builds one profile.
	 *
	 * @param CodecRegistry $registry
	 *   The registry to read availability from.
	 * @param string $id
	 *   The codec id.
	 *
	 * @return CodecProfile
	 *   The profile, marked unavailable when nothing on this host can run it.
	 */
	public static function profile(CodecRegistry $registry, string $id): CodecProfile
	{
		$codec = $registry->get($id);
		$available = $registry->canRead($id);
		$perFrame = $registry->canWritePerFrame($id);
		$reasons = $registry->unavailable()[$id] ?? [];

		// readable but not usable per frame is the degraded case: the bucket stays decodable and
		// compaction still benefits, but the flush path has quietly fallen back to another codec.
		// it needs the same install instructions an outright missing codec gets
		$reason = null;
		if (!$available) {
			$reason = implode('; ', $reasons) ?: $codec->unavailableReason();
		} elseif (!$perFrame) {
			$reason = sprintf(
				'Usable for reading and for compaction, but not on the flush path: %s. Backups ' .
					'still work; new frames are compressed with another codec.',
				$codec->unavailableReason() ??
					'this host has only an out-of-process implementation',
			);
		}

		return new CodecProfile(
			$id,
			self::labels()[$id] ?? $id,
			self::summaries()[$id] ?? '',
			$available,
			$reason,
			$codec->supportsDictionary(),
			$perFrame,
			$codec->levels(),
			self::referenceMeasurements()[$id] ?? [],
			$available && $perFrame ? [] : self::install()[$id] ?? [],
			self::documentation()[$id] ?? null,
		);
	}

	/**
	 * Human-readable names.
	 *
	 * @return array<string, string>
	 *   Codec id keyed to label.
	 */
	public static function labels(): array
	{
		return [
			'zstd' => 'Zstandard',
			'brotli' => 'Brotli',
			'gzip' => 'Gzip (DEFLATE)',
			'none' => 'No Compression',
		];
	}

	/**
	 * One sentence each on what a codec is for.
	 *
	 * @return array<string, string>
	 *   Codec id keyed to summary.
	 */
	public static function summaries(): array
	{
		return [
			'zstd' =>
				'The recommended codec. Fast enough for the flush path at level 1 and the ' .
				'densest practical option at level 19, and the only one whose dictionary support ' .
				'lets Strata store a rewritten value as a delta against its previous version.',
			'brotli' =>
				'The smallest output measured, at roughly a quarter of Zstandard\'s speed. ' .
				'Worth enabling for compaction on a large site; too slow for the flush path.',
			'gzip' =>
				'Always available, because Drupal core requires ext-zlib. The floor rather ' .
				'than the recommendation: it cannot take a dictionary, so delta coding is ' .
				'unavailable and every frame is stored standalone.',
			'none' =>
				'Stores frames verbatim. Correct for already-compressed content such as ' .
				'media blocks, where compression spends CPU to add bytes, and used as the ' .
				'baseline every other codec is measured against.',
		];
	}

	/**
	 * How to install each codec, by platform.
	 *
	 * @return array<string, array<string, string>>
	 *   Codec id keyed to platform label keyed to command.
	 */
	public static function install(): array
	{
		return [
			'zstd' => [
				'macOS (Homebrew)' => 'brew install zstd && pecl install zstd',
				'Debian / Ubuntu' =>
					'sudo apt-get install -y libzstd-dev php-pear php-dev' .
					' && sudo pecl install zstd',
				'RHEL / Fedora' =>
					'sudo dnf install -y libzstd-devel php-pear php-devel' .
					' && sudo pecl install zstd',
				'Alpine' =>
					'apk add --no-cache zstd-dev php-pear php-dev build-base' .
					' && pecl install zstd',
				'Docker (php official image)' =>
					'RUN apt-get update' .
					' && apt-get install -y libzstd-dev && pecl install zstd' .
					' && docker-php-ext-enable zstd',
				'After installing' =>
					'Add extension=zstd.so to php.ini, then restart PHP-FPM' .
					' or Apache. Verify with: php -m | grep zstd',
			],
			'brotli' => [
				'macOS (Homebrew)' => 'brew install brotli && pecl install brotli',
				'Debian / Ubuntu' =>
					'sudo apt-get install -y libbrotli-dev php-pear php-dev' .
					' && sudo pecl install brotli',
				'RHEL / Fedora' =>
					'sudo dnf install -y brotli-devel php-pear php-devel' .
					' && sudo pecl install brotli',
				'Alpine' =>
					'apk add --no-cache brotli-dev php-pear php-dev build-base' .
					' && pecl install brotli',
				'Docker (php official image)' =>
					'RUN apt-get update' .
					' && apt-get install -y libbrotli-dev && pecl install brotli' .
					' && docker-php-ext-enable brotli',
				'After installing' =>
					'Add extension=brotli.so to php.ini, then restart PHP-FPM' .
					' or Apache. Verify with: php -m | grep brotli',
			],
			'gzip' => [
				'Any platform' =>
					'ext-zlib is required by Drupal core, so a Drupal site that runs ' .
					'at all already has it. If it is genuinely missing, rebuild PHP with ' .
					'--with-zlib.',
			],
		];
	}

	/**
	 * Where to read more about each codec.
	 *
	 * @return array<string, string>
	 *   Codec id keyed to URL.
	 */
	public static function documentation(): array
	{
		return [
			'zstd' => 'https://pecl.php.net/package/zstd',
			'brotli' => 'https://pecl.php.net/package/brotli',
			'gzip' => 'https://www.php.net/manual/en/book.zlib.php',
		];
	}

	/**
	 * The shipped reference measurements described in this class's docblock.
	 *
	 * @return array<string, list<CodecMeasurement>>
	 *   Codec id keyed to its measurements.
	 */
	public static function referenceMeasurements(): array
	{
		return [
			'zstd' => [
				new CodecMeasurement('zstd', 1, false, 8192, 3.88, 334.2, 640.3),
				new CodecMeasurement('zstd', 1, true, 8192, 4.16, 62.5, 640.3),
				new CodecMeasurement('zstd', 3, false, 8192, 3.95, 325.4, 640.3),
				new CodecMeasurement('zstd', 3, true, 8192, 4.62, 22.9, 640.3),
				new CodecMeasurement('zstd', 19, false, 8192, 4.24, 5.2, 640.3),
				new CodecMeasurement('zstd', 19, true, 8192, 5.62, 1.4, 640.3),
				new CodecMeasurement('zstd', 1, false, 16384, 4.28, 422.9, 640.3),
				new CodecMeasurement('zstd', 1, true, 16384, 4.67, 105.1, 640.3),
				new CodecMeasurement('zstd', 3, false, 16384, 4.37, 385.9, 640.3),
				new CodecMeasurement('zstd', 3, true, 16384, 4.74, 42.0, 640.3),
				new CodecMeasurement('zstd', 19, false, 16384, 4.87, 4.5, 640.3),
				new CodecMeasurement('zstd', 19, true, 16384, 5.86, 2.4, 640.3),
			],
			'brotli' => [
				new CodecMeasurement('brotli', 4, false, 8192, 3.97, 89.1, 461.6),
				new CodecMeasurement('brotli', 4, true, 8192, 3.96, 9.7, 461.6),
				new CodecMeasurement('brotli', 6, false, 8192, 4.34, 60.9, 461.6),
				new CodecMeasurement('brotli', 6, true, 8192, 5.27, 8.6, 461.6),
				new CodecMeasurement('brotli', 11, false, 8192, 4.66, 1.1, 461.6),
				new CodecMeasurement('brotli', 11, true, 8192, 5.8, 0.8, 461.6),
				new CodecMeasurement('brotli', 4, false, 16384, 4.33, 111.3, 461.6),
				new CodecMeasurement('brotli', 4, true, 16384, 4.32, 17.6, 461.6),
				new CodecMeasurement('brotli', 6, false, 16384, 4.82, 65.5, 461.6),
				new CodecMeasurement('brotli', 6, true, 16384, 5.44, 14.2, 461.6),
				new CodecMeasurement('brotli', 11, false, 16384, 5.24, 1.1, 461.6),
				new CodecMeasurement('brotli', 11, true, 16384, 6.07, 0.9, 461.6),
			],
			'gzip' => [
				new CodecMeasurement('gzip', 1, false, 8192, 3.6, 152.3, 589.8),
				new CodecMeasurement('gzip', 6, false, 8192, 3.92, 81.3, 589.8),
				new CodecMeasurement('gzip', 9, false, 8192, 3.92, 76.4, 589.8),
				new CodecMeasurement('gzip', 1, false, 16384, 3.84, 180.6, 589.8),
				new CodecMeasurement('gzip', 6, false, 16384, 4.39, 77.2, 589.8),
				new CodecMeasurement('gzip', 9, false, 16384, 4.4, 67.0, 589.8),
			],
			'none' => [
				new CodecMeasurement('none', 0, false, 8192, 1.0, 9687.2, 9687.2),
				new CodecMeasurement('none', 0, false, 16384, 1.0, 18948.5, 18948.5),
			],
		];
	}
}
