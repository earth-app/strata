<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\strata_ui\Render\Format;

/**
 * The four numbers that say whether the backup is working.
 *
 * **The recovery point lag is the one to alert on**, and it is first for that reason. It is the age of
 * the newest sealed commit, which is exactly how much captured work would be lost if the host died
 * now. Everything else on this block describes the shape of the history; that one describes the risk.
 *
 * The block's severity is the worst of its rows, so a dashboard shows one colour and a reader knows
 * whether to look closer without reading four numbers.
 *
 * @see StrataBlockBase
 */
#[
	Block(
		id: 'strata_status',
		admin_label: new TranslatableMarkup('Strata Status'),
		category: new TranslatableMarkup('Strata'),
	),
]
final class StatusBlock extends StrataBlockBase
{
	/**
	 * Lag in seconds at which the block warns.
	 *
	 * Four times the default flush interval, so an ordinary quiet minute does not warn and a stalled
	 * queue worker does.
	 */
	public const WARN_LAG = 60;

	/**
	 * Lag in seconds at which the block reports an error.
	 */
	public const ERROR_LAG = 900;

	/**
	 * {@inheritdoc}
	 */
	protected function rows(): array
	{
		$telemetry = $this->engine->telemetryPass();
		$statistics = $this->engine->frameIndex()->statistics();
		$commits = $this->engine->commitIndex();
		$lag = $telemetry->rpoLag();
		$newest = $commits->newest();

		return [
			[
				'label' => (string) $this->t('Recovery Point Lag'),
				'value' =>
					$newest === null
						? (string) $this->t('Nothing captured yet')
						: Format::duration($lag),
				'severity' => $this->lagSeverity($newest === null ? null : $lag),
			],
			[
				'label' => (string) $this->t('Last Sealed'),
				'value' =>
					$newest === null
						? (string) $this->t('Never')
						: Format::moment((int) $newest['microtime']),
				'severity' => 'ok',
			],
			[
				'label' => (string) $this->t('Commits'),
				'value' => Format::count($commits->count()),
				'severity' => 'ok',
			],
			[
				'label' => (string) $this->t('Stored'),
				'value' => sprintf(
					'%s at %s',
					Format::bytes($statistics['storedBytes']),
					Format::ratio($statistics['ratio']),
				),
				'severity' => 'ok',
			],
			[
				'label' => (string) $this->t('Pending'),
				'value' => sprintf(
					'%s operations, %s',
					Format::count($this->engine->journal()->pending()),
					Format::bytes($this->engine->journal()->pendingBytes()),
				),
				'severity' => 'ok',
			],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	protected function detailRoute(): string
	{
		return 'strata_ui.status';
	}

	/**
	 * How bad a given lag is.
	 *
	 * A site that has captured nothing has no lag rather than an infinite one; a fresh install has
	 * lost nothing and should not be alerting on its first page load.
	 *
	 * @param float|null $lag
	 *   Seconds, or NULL when nothing has been captured.
	 *
	 * @return string
	 *   One of "ok", "warn" or "error".
	 */
	private function lagSeverity(?float $lag): string
	{
		if ($lag === null) {
			return 'ok';
		}

		return match (true) {
			$lag >= self::ERROR_LAG => 'error',
			$lag >= self::WARN_LAG => 'warn',
			default => 'ok',
		};
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return list<string>
	 *   The permissions that may see this block.
	 */
	protected function requiredPermissions(): array
	{
		return ['view strata timeline', 'administer strata'];
	}
}
