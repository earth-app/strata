<?php

declare(strict_types=1);

namespace Drupal\strata\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\strata\Estimate\Measurement;
use Drupal\strata\Journal\Realm;

/**
 * Which realms are captured, and how the one that dominates the volume is treated.
 *
 * **The access-churn choice is the largest single lever on what a site stores**, so it is a radio
 * group with its measured consequence written next to each option rather than a checkbox. Two thirds
 * of a Drupal site's write volume is `users_field_data.access` and `login` timestamp churn: core
 * writes `access` whenever the request is more than `session_write_interval` seconds newer than the
 * last one, which defaults to 180.
 *
 * Turning it off costs MORE than recording a compact event, which is the result an operator would
 * never guess. Dropping the operation leaves the row dirty, and the reconciler then picks it up on
 * cron at the full price of a field delta.
 *
 * The raw SQL tap is offered with the measured overhead, because it is the one capture source that
 * costs something on every request rather than only when something changes.
 *
 * @see Realm
 * @see Measurement
 */
final class CaptureSettingsForm extends SettingsFormBase
{
	/**
	 * {@inheritdoc}
	 */
	public function getFormId(): string
	{
		return 'strata_capture_settings';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function settingKeys(): array
	{
		$keys = [
			'capture.statements' => 'bool',
			'capture.access_churn' => 'string',
			'capture.sample_rows' => 'int',
			'code.root' => 'string',
			'anomaly.enabled' => 'bool',
			'drill.enabled' => 'bool',
			'drill.interval' => 'int',
			'drill.sample' => 'int',
		];

		foreach (Realm::cases() as $realm) {
			$keys['capture.' . $realm->value] = 'bool';
		}

		return $keys;
	}

	/**
	 * {@inheritdoc}
	 */
	public function buildForm(array $form, FormStateInterface $form_state): array
	{
		$form['realms'] = [
			'#type' => 'details',
			'#title' => $this->t('Realms'),
			'#open' => true,
			'#description' => $this->t(
				'A realm that is off is not captured and cannot be restored.',
			),
		];

		foreach (Realm::cases() as $realm) {
			$form['realms'][$this->fieldFor('capture.' . $realm->value)] = [
				'#type' => 'checkbox',
				'#title' => $realm->label(),
				'#default_value' => (bool) $this->setting('capture.' . $realm->value, false),
				'#description' => $this->realmNote($realm),
			];
		}

		$this->addAccessChurn($form);
		$this->addStatements($form);
		$this->addAssurance($form);

		return parent::buildForm($form, $form_state);
	}

	#region Sections

	/**
	 * The access-churn mode, with what each one costs.
	 *
	 * @param array<string, mixed> $form
	 *   The form, added to by reference.
	 */
	private function addAccessChurn(array &$form): void
	{
		$form['churn'] = [
			'#type' => 'details',
			'#title' => $this->t('User Access Timestamps'),
			'#open' => true,
			'#description' => $this->t(
				"Two thirds of a typical site's write volume is this one field.",
			),
		];

		$form['churn'][$this->fieldFor('capture.access_churn')] = [
			'#type' => 'radios',
			'#title' => $this->t('Record Access Churn as'),
			'#options' => [
				Measurement::ACCESS_EVENT => $this->t(
					'A compact login event, 48 bytes. Recommended.',
				),
				Measurement::ACCESS_DELTA => $this->t(
					'A full field delta, 184 bytes. No extra recovery.',
				),
				Measurement::ACCESS_OFF => $this->t('Not at all. Costs more than the event mode.'),
			],
			'#default_value' => (string) $this->setting(
				'capture.access_churn',
				Measurement::ACCESS_EVENT,
			),
		];

		$form['churn']['excluded'] = [
			'#type' => 'item',
			'#title' => $this->t('Never Restored'),
			'#markup' => $this->t(
				'Access, login and init are always excluded from a user restore.',
			),
		];
	}

	/**
	 * The raw SQL tap, with its measured cost.
	 *
	 * @param array<string, mixed> $form
	 *   The form, added to by reference.
	 */
	private function addStatements(array &$form): void
	{
		$form['statements'] = [
			'#type' => 'details',
			'#title' => $this->t('Raw SQL Capture'),
			'#open' => false,
		];

		$form['statements'][$this->fieldFor('capture.statements')] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Watch Statements'),
			'#default_value' => (bool) $this->setting('capture.statements', true),
			'#description' => $this->t(
				'Under 10 microseconds a mutation, measured. See the page help.',
			),
		];

		$form['statements'][$this->fieldFor('capture.sample_rows')] = [
			'#type' => 'number',
			'#title' => $this->t('Reconciler Sample Rows'),
			'#min' => 0,
			'#max' => 10_000,
			'#default_value' => (int) $this->setting('capture.sample_rows', 200),
			'#description' => $this->t('Rows digested per table to notice an uncaptured change.'),
		];

		$form['statements'][$this->fieldFor('code.root')] = [
			'#type' => 'textfield',
			'#title' => $this->t('Project Root'),
			'#default_value' => (string) $this->setting('code.root', ''),
			'#description' => $this->t(
				'Where the code realm walks. Empty derives the composer root.',
			),
		];
	}

	/**
	 * The passes that check the capture is working.
	 *
	 * @param array<string, mixed> $form
	 *   The form, added to by reference.
	 */
	private function addAssurance(array &$form): void
	{
		$form['assurance'] = [
			'#type' => 'details',
			'#title' => $this->t('Proving It Works'),
			'#open' => false,
		];

		$form['assurance'][$this->fieldFor('anomaly.enabled')] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Detect Anomalies'),
			'#default_value' => (bool) $this->setting('anomaly.enabled', true),
			'#description' => $this->t(
				"Scored against this site's own history, never a fixed threshold.",
			),
		];

		$form['assurance'][$this->fieldFor('drill.enabled')] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Run Restore Drills'),
			'#default_value' => (bool) $this->setting('drill.enabled', false),
			'#description' => $this->t(
				'Proves the store reproduces the site. Costs one replay a subject.',
			),
		];

		$form['assurance'][$this->fieldFor('drill.interval')] = [
			'#type' => 'number',
			'#title' => $this->t('Seconds Between Drills'),
			'#min' => 60,
			'#default_value' => (int) $this->setting('drill.interval', 86_400),
			'#states' => [
				'visible' => [':input[name="drill__enabled"]' => ['checked' => true]],
			],
		];

		$form['assurance'][$this->fieldFor('drill.sample')] = [
			'#type' => 'number',
			'#title' => $this->t('Subjects Per Drill'),
			'#min' => 1,
			'#default_value' => (int) $this->setting('drill.sample', 50),
			'#states' => [
				'visible' => [':input[name="drill__enabled"]' => ['checked' => true]],
			],
		];
	}

	#endregion

	/**
	 * What an operator needs to know about one realm before switching it on.
	 *
	 * @param Realm $realm
	 *   The realm.
	 *
	 * @return string
	 *   The note, empty when the realm needs none.
	 */
	private function realmNote(Realm $realm): string
	{
		return match ($realm) {
			Realm::FILE => (string) $this->t('Needs Strata Files. Has its own retention ladder.'),
			Realm::EPHEMERAL => (string) $this->t('Never written back by a restore.'),
			Realm::SCHEMA => (string) $this->t('A logical restore refuses this realm.'),
			Realm::CODE => (string) $this->t('Own code as bytes, vendor as a lockfile reference.'),
			default => '',
		};
	}
}
