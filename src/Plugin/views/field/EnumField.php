<?php

declare(strict_types=1);

namespace Drupal\strata\Plugin\views\field;

use Drupal\Core\Form\FormStateInterface;
use Drupal\strata\Capture\Classifier\Classification;
use Drupal\strata\Health\Finding;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Renders a stored enum value as the label it was given a label for.
 *
 * The realm, verb, severity and classification columns hold the enum's case value - `keyvalue`, `ddl`,
 * `WARN` - because that is what the journal stores and what a filter matches on. Each of those enums
 * already carries a label for exactly this, so a report shows "Key-value store" and "Schema change"
 * rather than the wire form.
 *
 * There is a subclass per column rather than one handler for all four because `ViewsField` is not a
 * repeatable attribute, so a plugin id needs a class of its own. The subclasses carry the attribute and
 * name their enum; everything else is here.
 *
 * @see Realm
 * @see Verb
 * @see Finding
 * @see Classification
 */
abstract class EnumField extends FieldPluginBase
{
	/**
	 * Every label this column can render, keyed by the value the column stores.
	 *
	 * @return array<string|int, string>
	 *   Stored value keyed to label.
	 */
	abstract public static function options(): array;

	/**
	 * The labels of an enum whose cases carry one.
	 *
	 * @param list<Realm|Verb|Classification> $cases
	 *   The enum's cases.
	 *
	 * @return array<string, string>
	 *   Case value keyed to label.
	 */
	protected static function labelled(array $cases): array
	{
		$labels = [];

		foreach ($cases as $case) {
			$labels[$case->value] = $case->label();
		}

		return $labels;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The option definitions.
	 */
	protected function defineOptions(): array
	{
		$options = parent::defineOptions();
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
		$form['raw'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Show the Stored Value'),
			'#description' => $this->t('The value as the table holds it, rather than its label.'),
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
		if ((bool) $this->options['raw']) {
			return (string) $value;
		}

		// a value this release has no label for is shown as it is, never as an empty cell
		return static::options()[$value] ?? (string) $value;
	}
}
