<?php

declare(strict_types=1);

namespace Drupal\strata\Plugin\views\field;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\strata\Journal\JournalOp;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders unix microseconds as a date, keeping the part below the second.
 *
 * Strata stores times in microseconds because a site can write the same subject twice in one second and
 * the order matters. Rendering that through the ordinary date field would divide it by a million and
 * lose the part that made it unambiguous, so a timeline of a busy second would show the same instant
 * repeated.
 *
 * The fractional part is shown by default for exactly that reason, and can be switched off for a report
 * where the second is enough.
 */
#[ViewsField('strata_microtime')]
final class Microtime extends FieldPluginBase
{
	/**
	 * The date formatter.
	 */
	protected ?DateFormatterInterface $dateFormatter = null;

	/**
	 * {@inheritdoc}
	 *
	 * @param ContainerInterface $container
	 *   The container.
	 * @param array<string, mixed> $configuration
	 *   Plugin configuration.
	 * @param string $plugin_id
	 *   Plugin id.
	 * @param mixed $plugin_definition
	 *   Plugin definition.
	 *
	 * @return static
	 *   The handler.
	 */
	public static function create(
		ContainerInterface $container,
		array $configuration,
		$plugin_id,
		$plugin_definition,
	): static {
		$handler = new static($configuration, $plugin_id, $plugin_definition);
		$handler->dateFormatter = $container->get('date.formatter');

		return $handler;
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
		$options['date_format'] = ['default' => 'short'];
		$options['fraction'] = ['default' => true];

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
		$form['date_format'] = [
			'#type' => 'select',
			'#title' => $this->t('Date Format'),
			'#options' => [
				'short' => $this->t('Short'),
				'medium' => $this->t('Medium'),
				'long' => $this->t('Long'),
				'custom' => $this->t('Time Ago'),
			],
			'#default_value' => $this->options['date_format'],
		];
		$form['fraction'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Show Microseconds'),
			'#description' => $this->t(
				'Two writes in one second are two commits, so the fraction is what tells them apart.',
			),
			'#default_value' => $this->options['fraction'],
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

		if ($value === null || $value === '' || (int) $value < 1) {
			return '';
		}

		$microtime = (int) $value;
		$seconds = intdiv($microtime, JournalOp::MICROSECONDS_PER_SECOND);
		$format = (string) $this->options['date_format'];

		$rendered =
			$format === 'custom'
				? (string) $this->dateFormatter?->formatTimeDiffSince($seconds)
				: (string) $this->dateFormatter?->format($seconds, $format);

		if (!(bool) $this->options['fraction'] || $format === 'custom') {
			return $rendered;
		}

		return sprintf('%s.%06d', $rendered, $microtime % JournalOp::MICROSECONDS_PER_SECOND);
	}
}
