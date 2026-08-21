<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Codec;

use Drupal\strata\Codec\CodecCatalog;
use Drupal\strata\Codec\CodecMeasurement;
use Drupal\strata\Codec\CodecProfile;
use Drupal\strata\Codec\CodecRegistry;
use Drupal\strata\Codec\CompressionCodecInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(CodecCatalog::class)]
#[CoversClass(CodecProfile::class)]
#[CoversClass(CodecMeasurement::class)]
class CodecCatalogTest extends TestCase
{
	#region Fakes

	private function fake(
		string $id,
		bool $available = true,
		bool $dictionary = false,
		string $reason = 'the fake extension is not loaded',
	): CompressionCodecInterface {
		return new class ($id, $available, $dictionary, $reason) implements
			CompressionCodecInterface
		{
			public function __construct(
				private readonly string $codecId,
				private readonly bool $available,
				private readonly bool $dictionary,
				private readonly string $reason,
			) {}

			public function id(): string
			{
				return $this->codecId;
			}

			public function isAvailable(): bool
			{
				return $this->available;
			}

			public function unavailableReason(): ?string
			{
				return $this->available ? null : $this->reason;
			}

			public function supportsDictionary(): bool
			{
				return $this->available && $this->dictionary;
			}

			public function levels(): array
			{
				return ['min' => 1, 'max' => 19, 'default' => 3, 'fast' => 1, 'dense' => 19];
			}

			public function compress(
				string $data,
				?int $level = null,
				?string $dictionary = null,
			): string {
				return $data;
			}

			public function decompress(string $data, ?string $dictionary = null): string
			{
				return $data;
			}
		};
	}

	#endregion

	#region Measurements

	#[Test]
	#[TestDox('a measurement converts a ratio into the forms a form actually shows')]
	#[Group('strata/codec')]
	public function measurementConvertsRatio(): void
	{
		$m = new CodecMeasurement('zstd', 19, true, 16384, 4.0, 100.0, 640.0);

		$this->assertSame(25.0, $m->percentOfOriginal());
		$this->assertSame(256.0, $m->megabytesPerGigabyte());
		$this->assertEqualsWithDelta(10.0, $m->secondsFor(1000 * 1048576), 0.001);
	}

	#[Test]
	#[
		TestDox(
			'a measurement with no throughput reports an infinite duration, not a division by zero',
		),
	]
	#[Group('strata/codec')]
	public function zeroThroughputReportsInfinity(): void
	{
		$m = new CodecMeasurement('zstd', 19, false, 16384, 4.0, 0.0);

		$this->assertInfinite($m->secondsFor(1048576));
	}

	/**
	 * @return array<string, array{float, int, float, float, string}>
	 */
	public static function impossibleMeasurementProvider(): array
	{
		return [
			'ratio below one' => [0.9, 16384, 1.0, 1.0, 'below 1.0 means the measurement failed'],
			'zero ratio' => [0.0, 16384, 1.0, 1.0, 'below 1.0 means the measurement failed'],
			'zero frame size' => [4.0, 0, 1.0, 1.0, 'Frame size must be positive'],
			'negative frame size' => [4.0, -1, 1.0, 1.0, 'Frame size must be positive'],
			'negative compress speed' => [4.0, 16384, -1.0, 1.0, 'Throughput cannot be negative'],
			'negative decompress speed' => [4.0, 16384, 1.0, -1.0, 'Throughput cannot be negative'],
		];
	}

	#[Test]
	#[TestDox('$_dataName is refused, because it means the measurement broke')]
	#[Group('strata/codec')]
	#[DataProvider('impossibleMeasurementProvider')]
	public function impossibleMeasurementsAreRefused(
		float $ratio,
		int $frameSize,
		float $compress,
		float $decompress,
		string $message,
	): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		new CodecMeasurement('zstd', 19, false, $frameSize, $ratio, $compress, $decompress);
	}

	#endregion

	#region Reference figures

	#[Test]
	#[TestDox('every shipped reference measurement is internally coherent')]
	#[Group('strata/codec')]
	public function referenceMeasurementsAreCoherent(): void
	{
		$all = CodecCatalog::referenceMeasurements();

		$this->assertNotEmpty($all);

		foreach ($all as $id => $measurements) {
			$this->assertNotEmpty($measurements, "$id has no reference figures");

			foreach ($measurements as $m) {
				$this->assertSame($id, $m->codec);
				$this->assertGreaterThanOrEqual(1.0, $m->ratio);
				$this->assertGreaterThan(0.0, $m->compressMbPerSecond);
				$this->assertFalse($m->onThisHost, 'a shipped figure is not a host figure');
			}
		}
	}

	#[Test]
	#[TestDox('the reference figures reproduce the decisions the defaults are based on')]
	#[Group('strata/codec')]
	public function referenceFiguresSupportTheDefaults(): void
	{
		$at = static function (string $codec, int $level, bool $dict): CodecMeasurement {
			foreach (CodecCatalog::referenceMeasurements()[$codec] as $m) {
				if ($m->level === $level && $m->dictionary === $dict && $m->frameSize === 16384) {
					return $m;
				}
			}

			throw new InvalidArgumentException("no reference figure for $codec level $level");
		};

		// zstd level 1 is the flush-path choice: far faster than gzip 9 for a comparable ratio
		$this->assertGreaterThan(
			$at('gzip', 9, false)->compressMbPerSecond * 5,
			$at('zstd', 1, false)->compressMbPerSecond,
		);

		// a dictionary buys ratio and costs throughput; both halves must be visible in the figures
		$this->assertGreaterThan($at('zstd', 19, false)->ratio, $at('zstd', 19, true)->ratio);
		$this->assertLessThan(
			$at('zstd', 1, false)->compressMbPerSecond,
			$at('zstd', 1, true)->compressMbPerSecond,
		);

		// brotli wins on size and loses badly on time
		$this->assertGreaterThan($at('zstd', 19, true)->ratio, $at('brotli', 11, true)->ratio);
		$this->assertLessThan(
			$at('zstd', 19, true)->compressMbPerSecond,
			$at('brotli', 11, true)->compressMbPerSecond,
		);
	}

	#[Test]
	#[TestDox('every codec that can be installed carries a command for every supported platform')]
	#[Group('strata/codec')]
	public function installInstructionsAreComplete(): void
	{
		foreach (['zstd', 'brotli'] as $id) {
			$install = CodecCatalog::install()[$id];
			$platforms = ['macOS (Homebrew)', 'Debian / Ubuntu', 'RHEL / Fedora', 'Alpine'];

			foreach ($platforms as $platform) {
				$this->assertArrayHasKey($platform, $install, "$id has no $platform command");
				$this->assertStringContainsString($id, $install[$platform]);
			}

			$this->assertArrayHasKey('After installing', $install);
			$this->assertStringContainsString('php -m', $install['After installing']);
			$this->assertArrayHasKey($id, CodecCatalog::documentation());
			$this->assertNotSame('', CodecCatalog::labels()[$id]);
			$this->assertNotSame('', CodecCatalog::summaries()[$id]);
		}
	}

	#endregion

	#region Profiles

	#[Test]
	#[TestDox('a profile for this host reports every shipped codec')]
	#[Group('strata/codec')]
	public function profilesCoverEveryShippedCodec(): void
	{
		$ids = array_map(
			static fn(CodecProfile $p): string => $p->id,
			CodecCatalog::profiles(CodecRegistry::withShippedCodecs()),
		);

		sort($ids);
		$this->assertSame(['brotli', 'gzip', 'none', 'zstd'], $ids);
	}

	#[Test]
	#[TestDox('an unavailable codec carries its reason and its install commands')]
	#[Group('strata/codec')]
	public function unavailableCodecCarriesInstallCommands(): void
	{
		$registry = (new CodecRegistry())
			->register($this->fake('brotli', false, false, 'ext-brotli is not loaded'))
			->register($this->fake('gzip', true, false))
			->register($this->fake('none', true, false))
			->register($this->fake('zstd', true, true));

		$profile = CodecCatalog::profile($registry, 'brotli');

		$this->assertFalse($profile->available);
		$this->assertFalse($profile->perFrame);
		$this->assertSame('ext-brotli is not loaded', $profile->reason);
		$this->assertNotEmpty($profile->install);
		$this->assertArrayHasKey('macOS (Homebrew)', $profile->install);
	}

	#[Test]
	#[TestDox('a readable but not per-frame codec is reported as degraded, not as available')]
	#[Group('strata/codec')]
	public function readableButSlowCodecIsReportedAsDegraded(): void
	{
		$registry = (new CodecRegistry())
			->register($this->fake('zstd', false, true, 'ext-zstd is not loaded'))
			->register($this->fake('zstd', true, true), false)
			->register($this->fake('gzip', true, false));

		$profile = CodecCatalog::profile($registry, 'zstd');

		$this->assertTrue($profile->available, 'frames written with zstd stay readable');
		$this->assertFalse($profile->perFrame, 'but it must not be chosen for the flush path');
		$this->assertStringContainsString('not on the flush path', (string) $profile->reason);
		$this->assertStringContainsString('Backups still work', (string) $profile->reason);
		$this->assertNotEmpty(
			$profile->install,
			'a degraded codec still needs install instructions',
		);
	}

	#[Test]
	#[TestDox('a fully usable codec offers no install instructions and no reason')]
	#[Group('strata/codec')]
	public function usableCodecOffersNoInstructions(): void
	{
		$registry = (new CodecRegistry())->register($this->fake('gzip', true, false));
		$profile = CodecCatalog::profile($registry, 'gzip');

		$this->assertTrue($profile->available);
		$this->assertTrue($profile->perFrame);
		$this->assertNull($profile->reason);
		$this->assertSame([], $profile->install);
	}

	#[Test]
	#[TestDox('profiles are ordered usable, then degraded, then missing')]
	#[Group('strata/codec')]
	public function profilesAreOrderedByUsability(): void
	{
		$registry = (new CodecRegistry())
			->register($this->fake('zstd', false, true, 'ext-zstd is not loaded'))
			->register($this->fake('zstd', true, true), false)
			->register($this->fake('brotli', false, false, 'ext-brotli is not loaded'))
			->register($this->fake('gzip', true, false))
			->register($this->fake('none', true, false));

		$ids = array_map(
			static fn(CodecProfile $p): string => $p->id,
			CodecCatalog::profiles($registry),
		);

		$this->assertSame(['gzip', 'none', 'zstd', 'brotli'], $ids);
	}

	#[Test]
	#[TestDox('measurement() prefers a figure taken on this host over the shipped one')]
	#[Group('strata/codec')]
	public function hostMeasurementBeatsReference(): void
	{
		$profile = CodecCatalog::profile(CodecRegistry::withShippedCodecs(), 'gzip');
		$this->assertFalse($profile->isCalibrated());

		$calibrated = $profile->withMeasurements([
			new CodecMeasurement('gzip', 9, false, 16384, 9.99, 12.3, 45.6, true),
		]);

		$this->assertTrue($calibrated->isCalibrated());
		$this->assertSame(9.99, $calibrated->measurement(16384, false, 'dense')?->ratio);
		$this->assertFalse($profile->isCalibrated(), 'the original profile must not be mutated');
	}

	#[Test]
	#[TestDox('measurement() falls back to the nearest frame size it has')]
	#[Group('strata/codec')]
	public function measurementFallsBackToNearestFrameSize(): void
	{
		$profile = CodecCatalog::profile(CodecRegistry::withShippedCodecs(), 'gzip');

		$this->assertSame(8192, $profile->measurement(4096, false, 'dense')?->frameSize);
		$this->assertSame(16384, $profile->measurement(65536, false, 'dense')?->frameSize);
	}

	#[Test]
	#[TestDox('measurement() returns null rather than inventing a figure it does not have')]
	#[Group('strata/codec')]
	public function measurementReturnsNullWhenAbsent(): void
	{
		$profile = CodecCatalog::profile(CodecRegistry::withShippedCodecs(), 'gzip');

		$this->assertNull(
			$profile->measurement(16384, true, 'dense'),
			'gzip has no dictionary figure',
		);
	}

	#[Test]
	#[TestDox('a profile serializes to something a render array can consume')]
	#[Group('strata/codec')]
	public function profileSerializes(): void
	{
		$profile = CodecCatalog::profile(CodecRegistry::withShippedCodecs(), 'zstd');
		$array = $profile->jsonSerialize();
		$keys = ['id', 'label', 'summary', 'available', 'perFrame', 'levels', 'measurements'];

		foreach ([...$keys, 'install'] as $key) {
			$this->assertArrayHasKey($key, $array);
		}

		$this->assertIsString(json_encode($profile));
	}

	#endregion
}
