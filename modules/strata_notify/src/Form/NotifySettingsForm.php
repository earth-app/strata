<?php

declare(strict_types=1);

namespace Drupal\strata_notify\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\strata\Health\Finding;
use Drupal\strata_notify\NotificationPolicy;

/**
 * Who is notified, about what.
 *
 * Each event is a separate checkbox with what it costs in volume next to it, so an operator switching
 * on commit notifications is told first that a site flushing every fifteen seconds sends 5,760 mails
 * a day. A settings form that let somebody discover that by receiving them would be the reason they
 * stopped reading the channel.
 *
 * @see NotificationPolicy
 */
final class NotifySettingsForm extends ConfigFormBase
{
	/**
	 * {@inheritdoc}
	 */
	public function getFormId(): string
	{
		return 'strata_notify_settings';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return list<string>
	 *   The configuration this form edits.
	 */
	protected function getEditableConfigNames(): array
	{
		return [NotificationPolicy::CONFIG];
	}

	/**
	 * {@inheritdoc}
	 */
	public function buildForm(array $form, FormStateInterface $form_state): array
	{
		$config = $this->config(NotificationPolicy::CONFIG);

		$form['recipients'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Recipients'),
			'#default_value' => (string) $config->get('recipients'),
			'#description' => $this->t(
				'Comma-separated addresses. With none set, nothing is mailed whatever is ticked below.',
			),
		];

		$form['severity'] = [
			'#type' => 'select',
			'#title' => $this->t('Lowest Finding Severity Mailed'),
			'#options' => [
				Finding::INFO => $this->t('Info and Above, Everything'),
				Finding::WARN => $this->t('Warning and Above'),
				Finding::ERROR => $this->t('Error and Above'),
				Finding::CRITICAL => $this->t('Critical Only'),
			],
			'#default_value' => (int) ($config->get('severity') ?? Finding::ERROR),
			'#description' => $this->t(
				'Findings only. A failed restore is notified whatever this says.',
			),
		];

		$form['events'] = [
			'#type' => 'details',
			'#title' => $this->t('Events'),
			'#open' => true,
			'#tree' => true,
		];

		foreach ($this->eventFields() as $key => $field) {
			$form['events'][$key] = [
				'#type' => 'checkbox',
				'#title' => $field['title'],
				'#default_value' => (bool) $config->get('events.' . $key),
				'#description' => $field['description'],
			];
		}

		$form['digest'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Collapse Repeats'),
			'#default_value' => $config->get('digest') !== false,
			'#description' => $this->t(
				'One mail names the code and the count, not one mail per scope.',
			),
		];

		return parent::buildForm($form, $form_state);
	}

	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state): void
	{
		/** @var array<string, mixed> $events */
		$events = $form_state->getValue('events') ?? [];
		$config = $this->config(NotificationPolicy::CONFIG);

		$config
			->set('recipients', trim((string) $form_state->getValue('recipients')))
			->set('severity', (int) $form_state->getValue('severity'))
			->set('digest', (bool) $form_state->getValue('digest'));

		foreach (array_keys($this->eventFields()) as $key) {
			$config->set('events.' . $key, (bool) ($events[$key] ?? false));
		}

		$config->save();

		parent::submitForm($form, $form_state);
	}

	/**
	 * Each event checkbox, with what it costs in volume.
	 *
	 * @return array<string, array{title: string, description: string}>
	 *   Setting key keyed to its label and note.
	 */
	private function eventFields(): array
	{
		return [
			'restore' => [
				'title' => (string) $this->t('A Restore Finished'),
				'description' => (string) $this->t(
					'Including one that was refused, which is the case most worth hearing about.',
				),
			],
			'budget' => [
				'title' => (string) $this->t('A Budget Ceiling Was Crossed'),
				'description' => (string) $this->t('At most once per cron run.'),
			],
			'health' => [
				'title' => (string) $this->t('A Finding Was Recorded'),
				'description' => (string) $this->t('Filtered by the severity above.'),
			],
			'drill' => [
				'title' => (string) $this->t('A Restore Drill Finished'),
				'description' => (string) $this->t(
					'One per drill. Its absence is information too.',
				),
			],
			'prune' => [
				'title' => (string) $this->t('A Prune Destroyed Restore Points'),
				'description' => (string) $this->t('At most once per cron run.'),
			],
			'commit' => [
				'title' => (string) $this->t('A Flush Sealed a Window'),
				'description' => (string) $this->t(
					'A 15 second flush sends about 5,760 of these a day.',
				),
			],
		];
	}
}
