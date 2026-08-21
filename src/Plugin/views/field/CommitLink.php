<?php

declare(strict_types=1);

namespace Drupal\strata\Plugin\views\field;

use Drupal\Core\Form\FormStateInterface;
use Drupal\strata\Cas\Hash;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Renders a commit address, abbreviated and marked up so a timeline can attach to it.
 *
 * Deliberately not a link yet. The timeline and the diff viewer are what a commit id should route to,
 * and inventing a URL for a route that does not exist would ship a table full of broken links. What this
 * does instead is emit the id as a data attribute on a marked-up element, which is what those pages will
 * hook onto when they land - and which is already useful, because a person can select and copy it.
 *
 * An empty value is an empty cell rather than a dash: a root commit genuinely has no parent, and a
 * placeholder would read as a missing one.
 */
#[ViewsField('strata_commit')]
final class CommitLink extends FieldPluginBase
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

		if ($value === '' || !Hash::isValid($value)) {
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
	 * @return array<string, mixed>|string
	 *   The render array, or the plain value when there is no commit to mark up.
	 */
	public function advancedRender(ResultRow $values): array|string
	{
		$rendered = parent::advancedRender($values);
		$value = (string) ($this->getValue($values) ?? '');

		if ($value === '' || !Hash::isValid($value)) {
			return $rendered;
		}

		return [
			'#type' => 'html_tag',
			'#tag' => 'span',
			'#value' => $rendered,
			'#attributes' => [
				'class' => ['strata-commit'],
				'title' => $value,
				'data-strata-commit' => $value,
			],
		];
	}
}
