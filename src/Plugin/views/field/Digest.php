<?php

declare(strict_types=1);

namespace Drupal\strata\Plugin\views\field;

use Drupal\Core\Form\FormStateInterface;
use Drupal\strata\Cas\Hash;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Renders a content address short enough to read, with the whole thing on hover.
 *
 * A BLAKE2b digest is 64 hex characters, and a table of them is a wall. Twelve characters is enough to
 * recognise one and to tell two apart, which is what a person reading a report needs; the full digest
 * stays in the title attribute for the case where they need to copy it.
 *
 * Never use the shortened form as an identifier. It is for a table cell, and this class is the only
 * place that shortening happens for display.
 */
#[ViewsField('strata_digest')]
final class Digest extends FieldPluginBase
{
	/**
	 * Characters shown by default.
	 */
	public const DEFAULT_LENGTH = 12;

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The option definitions.
	 */
	protected function defineOptions(): array
	{
		$options = parent::defineOptions();
		$options['length'] = ['default' => self::DEFAULT_LENGTH];
		$options['full'] = ['default' => false];

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
		$form['length'] = [
			'#type' => 'number',
			'#title' => $this->t('Characters to Show'),
			'#default_value' => $this->options['length'],
			'#min' => 4,
			'#max' => Hash::HEX_LENGTH,
		];
		$form['full'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Show the Whole Digest'),
			'#default_value' => $this->options['full'],
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
		$value = (string) ($this->getValue($values) ?? '');

		if ($value === '') {
			return '';
		}
		if ((bool) $this->options['full'] || !Hash::isValid($value)) {
			return $value;
		}

		return Hash::abbreviate($value, max(4, (int) $this->options['length']));
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param ResultRow $values
	 *   The row.
	 *
	 * @return array<string, mixed>
	 *   The render array, carrying the full digest as a title so it can be copied.
	 */
	public function advancedRender(ResultRow $values): array|string
	{
		$rendered = parent::advancedRender($values);
		$value = (string) ($this->getValue($values) ?? '');

		if ($value === '' || (bool) $this->options['full']) {
			return $rendered;
		}

		return [
			'#type' => 'html_tag',
			'#tag' => 'span',
			'#value' => $rendered,
			'#attributes' => ['title' => $value, 'class' => ['strata-digest']],
		];
	}
}
