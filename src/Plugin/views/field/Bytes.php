<?php

declare(strict_types=1);

namespace Drupal\strata\Plugin\views\field;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Renders a byte count in the largest unit that stays readable.
 *
 * Every size in Strata is a byte count and most of them are large. A raw `4194304` in a table cell is
 * a number an operator has to decode; `4.0 MiB` is one they can compare at a glance, which is the whole
 * job of a report.
 *
 * Binary units, because these are stored bytes and every tool an operator will check them against - du,
 * ls, the object store's own console - uses binary too. Provider billing uses decimal gigabytes and the
 * estimator converts there; a table of stored sizes is not a bill.
 */
#[ViewsField('strata_bytes')]
final class Bytes extends FieldPluginBase
{
	/**
	 * The units, largest last.
	 */
	public const UNITS = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The option definitions.
	 */
	protected function defineOptions(): array
	{
		$options = parent::defineOptions();
		$options['precision'] = ['default' => 1];
		$options['raw'] = ['default' => false];

		return $options;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array<string, mixed> $form
	 *   The form.
	 * @param FormStateInterface $form_state
	 *   The form state.
	 */
	public function buildOptionsForm(&$form, FormStateInterface $form_state): void
	{
		$form['precision'] = [
			'#type' => 'number',
			'#title' => $this->t('Decimal Places'),
			'#default_value' => $this->options['precision'],
			'#min' => 0,
			'#max' => 3,
		];
		$form['raw'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Show the Exact Byte Count'),
			'#description' => $this->t('Useful when a value is being compared with another tool.'),
			'#default_value' => $this->options['raw'],
		];

		parent::buildOptionsForm($form, $form_state);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param ResultRow $values
	 *   The row.
	 *
	 * @return string
	 *   The rendered value.
	 */
	public function render(ResultRow $values): string
	{
		$value = $this->getValue($values);

		if ($value === null || $value === '') {
			return '';
		}

		$bytes = (int) $value;

		if ((bool) $this->options['raw']) {
			return number_format($bytes);
		}

		return self::format($bytes, (int) $this->options['precision']);
	}

	/**
	 * A byte count in the largest unit that stays readable.
	 *
	 * @param int $bytes
	 *   The count.
	 * @param int $precision
	 *   Decimal places for everything above bytes.
	 *
	 * @return string
	 *   Something such as "4.0 MiB".
	 */
	public static function format(int $bytes, int $precision = 1): string
	{
		$negative = $bytes < 0;
		$size = abs($bytes);
		$unit = 0;

		while ($size >= 1024 && $unit < count(self::UNITS) - 1) {
			$size /= 1024;
			$unit++;
		}

		return sprintf(
			'%s%s %s',
			$negative ? '-' : '',
			$unit === 0 ? (string) (int) $size : number_format($size, max(0, $precision)),
			self::UNITS[$unit],
		);
	}
}
