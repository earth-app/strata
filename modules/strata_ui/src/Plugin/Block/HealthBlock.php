<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\strata\Health\Finding;
use Drupal\strata_ui\Render\Format;

/**
 * Open findings by severity, and what the last restore drill proved.
 *
 * **A site that has never run a drill is reported as such rather than as healthy.** Nothing open is
 * not the same as known good: it means no tripwire has fired, which on a store nobody has read back
 * is the expected state whether the backups work or not.
 *
 * @see StrataBlockBase
 */
#[
	Block(
		id: 'strata_health',
		admin_label: new TranslatableMarkup('Strata Health'),
		category: new TranslatableMarkup('Strata'),
	),
]
final class HealthBlock extends StrataBlockBase
{
	/**
	 * {@inheritdoc}
	 */
	protected function rows(): array
	{
		$counts = [];

		foreach (Finding::severities() as $name) {
			$counts[$name] = 0;
		}

		foreach ($this->engine->ledger()->summary() as $row) {
			$name = Finding::severities()[(int) $row['severity']] ?? 'UNKNOWN';
			$counts[$name] = ($counts[$name] ?? 0) + 1;
		}

		$rows = [];

		foreach ($counts as $name => $count) {
			$rows[] = [
				'label' => $name,
				'value' => Format::count($count),
				'severity' => $count > 0 ? $this->severityOf($name) : 'ok',
			];
		}

		$rows[] = $this->drillRow();

		return $rows;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function detailRoute(): string
	{
		return 'strata_ui.health';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return list<string>
	 *   The permissions that may see this block.
	 */
	protected function requiredPermissions(): array
	{
		return ['view strata health', 'administer strata'];
	}

	/**
	 * What the newest drill proved.
	 *
	 * @return array{label: string, value: string, severity: string}
	 *   The row.
	 */
	private function drillRow(): array
	{
		$newest = $this->engine->drillIndex()->newest();

		if ($newest === null) {
			return [
				'label' => (string) $this->t('Last Drill'),
				'value' => (string) $this->t('Never run'),
				'severity' => 'warn',
			];
		}

		$verdict = (string) $newest['verdict'];

		return [
			'label' => (string) $this->t('Last Drill'),
			'value' => $verdict,
			'severity' => match ($verdict) {
				'pass' => 'ok',
				'fail' => 'error',
				default => 'warn',
			},
		];
	}

	/**
	 * How bad a severity name is, in the block's own vocabulary.
	 *
	 * @param string $name
	 *   A severity name.
	 *
	 * @return string
	 *   One of "ok", "warn" or "error".
	 */
	private function severityOf(string $name): string
	{
		return match ($name) {
			'CRITICAL', 'ERROR' => 'error',
			'WARN' => 'warn',
			default => 'ok',
		};
	}
}
