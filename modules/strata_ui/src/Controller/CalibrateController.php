<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Controller;

use Drupal\strata\Codec\CodecCatalog;
use Drupal\strata\Codec\CodecMeasurement;
use Drupal\strata\Codec\CodecProfile;
use Drupal\strata_ui\Render\Format;

/**
 * What this host can actually do, codec by codec.
 *
 * **This page reports availability and the shipped reference figures; it measures nothing.** A real
 * calibration compresses a sample of the site's own stored history several times per codec, which
 * takes far longer than a page load should and needs history to sample. That is
 * `drush strata:calibrate`, and this page says so rather than pretending a reference figure came from
 * this machine.
 *
 * What it does answer without measuring anything is the question an operator has first: which codecs
 * are usable here, and why the others are not. A codec whose extension and binary are both absent is
 * listed with its reason rather than omitted, so somebody looking for zstd learns the extension is
 * missing instead of wondering whether the release supports it.
 *
 * @see CodecCatalog
 * @see CodecProfile
 */
final class CalibrateController extends StrataControllerBase
{
	/**
	 * The codec report.
	 *
	 * @return array<string, mixed>
	 *   The page.
	 */
	public function page(): array
	{
		return $this->guard(fn(): array => $this->report());
	}

	/**
	 * The report, once the codec registry is known to build.
	 *
	 * @return array<string, mixed>
	 *   The render array.
	 */
	private function report(): array
	{
		$registry = $this->engine->codecs();
		$frameSize = (int) $this->config('strata.settings')->get('frame.size');
		$rows = [];

		foreach (CodecCatalog::profiles($registry) as $profile) {
			$rows[] = $this->row($profile, $frameSize);
		}

		return [
			'#type' => 'container',
			'writer' => [
				'#type' => 'item',
				'#title' => $this->t('In Use'),
				'#markup' => $this->t('@codec writes new frames on this host.', [
					'@codec' => $registry->writer()->id(),
				]),
			],
			'codecs' => [
				'#type' => 'table',
				'#caption' => $this->t('Codecs at @size Frames', [
					'@size' => Format::bytes($frameSize),
				]),
				'#header' => [
					$this->t('Codec'),
					$this->t('Available'),
					$this->t('Per Frame'),
					$this->t('Ratio'),
					$this->t('Compress'),
					$this->t('Decompress'),
					$this->t('Reason'),
				],
				'#rows' => $rows,
				'#empty' => $this->t('No codec is registered, which should not be possible.'),
			],
			'measure' => [
				'#type' => 'item',
				'#title' => $this->t('These Are Reference Figures'),
				'#markup' => $this->t(
					'Run drush strata:calibrate to measure this host against its own stored history.',
				),
			],
			'#attached' => ['library' => [self::LIBRARY]],
			'#cache' => ['max-age' => 0],
		];
	}

	/**
	 * One codec's row.
	 *
	 * @param CodecProfile $profile
	 *   The profile.
	 * @param int $frameSize
	 *   The configured frame size, since a measurement is per frame size.
	 *
	 * @return array<int, string>
	 *   The cells.
	 */
	private function row(CodecProfile $profile, int $frameSize): array
	{
		$measurement = $profile->measurement($frameSize, $profile->supportsDictionary);

		return [
			$profile->label,
			$profile->available ? (string) $this->t('Yes') : (string) $this->t('No'),
			$profile->perFrame ? (string) $this->t('Yes') : (string) $this->t('Batched'),
			$this->ratio($measurement),
			$this->rate($measurement?->compressMbPerSecond),
			$this->rate($measurement?->decompressMbPerSecond),
			(string) ($profile->reason ?? '-'),
		];
	}

	/**
	 * A measurement's ratio, or a dash when there is none for this frame size.
	 *
	 * @param CodecMeasurement|null $measurement
	 *   The measurement.
	 *
	 * @return string
	 *   The rendered ratio.
	 */
	private function ratio(?CodecMeasurement $measurement): string
	{
		return $measurement === null ? '-' : Format::ratio($measurement->ratio);
	}

	/**
	 * A throughput figure, or a dash when there is none.
	 *
	 * @param float|null $megabytesPerSecond
	 *   The rate.
	 *
	 * @return string
	 *   The rendered rate.
	 */
	private function rate(?float $megabytesPerSecond): string
	{
		return $megabytesPerSecond === null || $megabytesPerSecond <= 0.0
			? '-'
			: sprintf('%s MB/s', number_format($megabytesPerSecond, 1));
	}
}
