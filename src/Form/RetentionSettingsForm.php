<?php

declare(strict_types=1);

namespace Drupal\strata\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\strata\Budget\EscalationLadder;
use Drupal\strata\Compaction\LevelPolicy;
use Drupal\strata\Tree\BasePolicy;

/**
 * How long history is kept, how often it is anchored, and what happens at the spend ceiling.
 *
 * **The base interval is a restore-latency dial, not a storage dial**, and the form says so. An
 * earlier model had it as the largest lever on stored bytes; measuring it showed the two effects
 * largely cancel, because a longer interval means fewer anchors that each record more changed
 * subjects. What it really controls is how many segments a replay walks to reach a given moment.
 *
 * The retention ladder is rendered as a table of levels rather than as free-form configuration,
 * because a level whose window is wider than the level above it produces a rollup that folds
 * backwards. The validation refuses that rather than letting a cron run discover it.
 *
 * @see LevelPolicy
 * @see BasePolicy
 * @see EscalationLadder
 */
final class RetentionSettingsForm extends SettingsFormBase
{
	/**
	 * Levels the form renders.
	 */
	public const LEVELS = 5;

	/**
	 * {@inheritdoc}
	 */
	public function getFormId(): string
	{
		return 'strata_retention_settings';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function settingKeys(): array
	{
		return [
			'retention.base_interval' => 'int',
			'retention.base_full_every' => 'int',
			'retention.auto_prune' => 'bool',
			'codec.compaction_level' => 'int',
			'budget.bytes_per_month' => 'int',
			'budget.dollars_per_month' => 'int',
			'budget.action' => 'string',
			'health.auto_repair' => 'bool',
			'health.circuit_threshold' => 'int',
			'health.circuit_cooldown' => 'int',
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function buildForm(array $form, FormStateInterface $form_state): array
	{
		$this->addAnchors($form);
		$this->addLadder($form);
		$this->addBudget($form);
		$this->addHealing($form);

		return parent::buildForm($form, $form_state);
	}

	/**
	 * {@inheritdoc}
	 */
	public function validateForm(array &$form, FormStateInterface $form_state): void
	{
		$previous = 0;

		for ($level = 0; $level < self::LEVELS; $level++) {
			$window = (int) $form_state->getValue(sprintf('level_%d_window', $level));
			$keep = (int) $form_state->getValue(sprintf('level_%d_keep', $level));

			if ($window < 1) {
				$form_state->setErrorByName(
					sprintf('level_%d_window', $level),
					$this->t('Every level needs a window of at least one second.'),
				);

				continue;
			}
			if ($window <= $previous) {
				$form_state->setErrorByName(
					sprintf('level_%d_window', $level),
					$this->t(
						'Level @level is not wider than the level below it, so a rollup would fold backwards.',
						['@level' => $level],
					),
				);
			}
			if ($keep !== 0 && $keep < $window) {
				$form_state->setErrorByName(
					sprintf('level_%d_keep', $level),
					$this->t(
						'Level @level is kept for less than its own window, so it would be pruned unfolded.',
						['@level' => $level],
					),
				);
			}

			$previous = $window;
		}

		parent::validateForm($form, $form_state);
	}

	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state): void
	{
		$levels = [];

		for ($level = 0; $level < self::LEVELS; $level++) {
			$levels[] = [
				'window' => (int) $form_state->getValue(sprintf('level_%d_window', $level)),
				'keep' => (int) $form_state->getValue(sprintf('level_%d_keep', $level)),
			];
		}

		$this->config(self::SETTINGS)->set('retention.levels', $levels)->save();

		parent::submitForm($form, $form_state);
	}

	#region Sections

	/**
	 * The anchor interval.
	 *
	 * @param array<string, mixed> $form
	 *   The form, added to by reference.
	 */
	private function addAnchors(array &$form): void
	{
		$form['anchors'] = [
			'#type' => 'details',
			'#title' => $this->t('Base Anchors'),
			'#open' => true,
			'#description' => $this->t(
				'An anchor is a complete index of every subject at a moment.',
			),
		];

		$form['anchors'][$this->fieldFor('retention.base_interval')] = [
			'#type' => 'number',
			'#title' => $this->t('Seconds Between Anchors'),
			'#min' => 60,
			'#default_value' => (int) $this->setting(
				'retention.base_interval',
				BasePolicy::DEFAULT_INTERVAL,
			),
			'#description' => $this->t(
				'At four hours and a 15 second flush, a replay walks 960 segments.',
			),
		];

		$form['anchors'][$this->fieldFor('retention.base_full_every')] = [
			'#type' => 'number',
			'#title' => $this->t('Delta Anchors Before a Full One'),
			'#min' => 1,
			'#default_value' => (int) $this->setting(
				'retention.base_full_every',
				BasePolicy::DEFAULT_FULL_EVERY,
			),
			'#description' => $this->t(
				'Caps the walk back through the chain to the last full anchor.',
			),
		];
	}

	/**
	 * The retention ladder.
	 *
	 * @param array<string, mixed> $form
	 *   The form, added to by reference.
	 */
	private function addLadder(array &$form): void
	{
		/** @var list<array<string, mixed>> $configured */
		$configured = $this->setting('retention.levels', []) ?? [];

		$form['ladder'] = [
			'#type' => 'details',
			'#title' => $this->t('Retention Ladder'),
			'#open' => true,
			'#description' => $this->t('Each level folds the one below it into wider windows.'),
			'#tree' => false,
		];

		for ($level = 0; $level < self::LEVELS; $level++) {
			$form['ladder'][sprintf('level_%d_window', $level)] = [
				'#type' => 'number',
				'#title' => $this->t('Level @level Window, Seconds', ['@level' => $level]),
				'#min' => 1,
				'#default_value' => (int) ($configured[$level]['window'] ?? 0),
			];

			$form['ladder'][sprintf('level_%d_keep', $level)] = [
				'#type' => 'number',
				'#title' => $this->t('Level @level Retained, Seconds', ['@level' => $level]),
				'#min' => 0,
				'#default_value' => (int) ($configured[$level]['keep'] ?? 0),
				'#description' => $this->t('Zero keeps this level forever.'),
			];
		}

		$form['ladder'][$this->fieldFor('retention.auto_prune')] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Prune on Cron'),
			'#default_value' => (bool) $this->setting('retention.auto_prune', true),
			'#description' => $this->t(
				'Destroys restore points permanently, and writes a receipt first.',
			),
		];

		$form['ladder'][$this->fieldFor('codec.compaction_level')] = [
			'#type' => 'number',
			'#title' => $this->t('Compaction Compression Level'),
			'#min' => 1,
			'#max' => 22,
			'#default_value' => (int) $this->setting('codec.compaction_level', 19),
			'#description' => $this->t(
				'A pack that does not get materially smaller is left alone.',
			),
		];
	}

	/**
	 * The spend ceiling.
	 *
	 * @param array<string, mixed> $form
	 *   The form, added to by reference.
	 */
	private function addBudget(array &$form): void
	{
		$form['budget'] = [
			'#type' => 'details',
			'#title' => $this->t('Spend Ceiling'),
			'#open' => false,
		];

		$form['budget'][$this->fieldFor('budget.bytes_per_month')] = [
			'#type' => 'number',
			'#title' => $this->t('Stored Bytes a Month'),
			'#min' => 0,
			'#default_value' => (int) $this->setting('budget.bytes_per_month', 0),
			'#description' => $this->t('Zero means no ceiling.'),
		];

		$form['budget'][$this->fieldFor('budget.dollars_per_month')] = [
			'#type' => 'number',
			'#title' => $this->t('Dollars a Month'),
			'#min' => 0,
			'#default_value' => (int) $this->setting('budget.dollars_per_month', 0),
			'#description' => $this->t('Zero means no ceiling.'),
		];

		$form['budget'][$this->fieldFor('budget.action')] = [
			'#type' => 'select',
			'#title' => $this->t('At the Ceiling'),
			'#options' => [
				EscalationLadder::WARN => $this->t('Warn Only'),
				EscalationLadder::REDUCE => $this->t('Reduce Retention'),
				EscalationLadder::PAUSE => $this->t('Pause Files, Ephemeral State and Code'),
				EscalationLadder::STOP => $this->t('Stop Capturing'),
			],
			'#default_value' => (string) $this->setting('budget.action', EscalationLadder::WARN),
			'#description' => $this->t('Every rung is logged and raises a finding.'),
		];
	}

	/**
	 * The self-healing policy.
	 *
	 * @param array<string, mixed> $form
	 *   The form, added to by reference.
	 */
	private function addHealing(array &$form): void
	{
		$form['healing'] = [
			'#type' => 'details',
			'#title' => $this->t('Self-Healing'),
			'#open' => false,
		];

		$form['healing'][$this->fieldFor('health.auto_repair')] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Run the Safe Repair Rungs on Cron'),
			'#default_value' => (bool) $this->setting('health.auto_repair', true),
			'#description' => $this->t(
				'Quarantine and refuse never run automatically; both need a person.',
			),
		];

		$form['healing'][$this->fieldFor('health.circuit_threshold')] = [
			'#type' => 'number',
			'#title' => $this->t('Failures Before a Circuit Opens'),
			'#min' => 1,
			'#default_value' => (int) $this->setting('health.circuit_threshold', 3),
			'#description' => $this->t(
				'Keyed on the finding code, so a persistent fault escalates instead of retrying forever.',
			),
		];

		$form['healing'][$this->fieldFor('health.circuit_cooldown')] = [
			'#type' => 'number',
			'#title' => $this->t('Seconds a Circuit Stays Open'),
			'#min' => 1,
			'#default_value' => (int) $this->setting('health.circuit_cooldown', 300),
		];
	}

	#endregion
}
