<?php

declare(strict_types=1);

namespace Drupal\strata\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\strata\Compaction\LevelPolicy;
use Drupal\strata\Tier\TierMap;
use Drupal\strata\Tier\TierMigrator;
use InvalidArgumentException;

/**
 * Which buckets history is spread across, and when it moves between them.
 *
 * **The ladder is validated by building it**, not by a second copy of the rules written for a form.
 * TierMap refuses a repeated bucket, a threshold that does not increase and a tier naming no
 * provider, and every one of those produces a store that looks configured and is not; running the
 * real constructor here is what stops the two sets of rules drifting apart.
 *
 * **The retention cutoffs are printed next to the age thresholds**, because the two are separate
 * dials that answer questions about the same history and an operator setting one wants to see the
 * other. A tier threshold near a retention cutoff means a bucket fills up as a rollup level empties.
 *
 * **The nearest tier's threshold is not editable.** It holds everything by definition, and a form
 * that let it be set would let a site configure a store with a gap at the front.
 *
 * @see TierMap
 * @see TierMigrator
 */
final class TierSettingsForm extends SettingsFormBase
{
	/**
	 * Tier rows the form renders.
	 *
	 * Four is what the shape this exists for needs - hot, last month, half a year, a year or two -
	 * and a row left without a provider is dropped on save, so a site using two configures two.
	 */
	public const TIERS = 4;

	/**
	 * {@inheritdoc}
	 */
	public function getFormId(): string
	{
		return 'strata_tier_settings';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function settingKeys(): array
	{
		return ['tiers.enabled' => 'bool', 'tiers.verify_copies' => 'bool'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function buildForm(array $form, FormStateInterface $form_state): array
	{
		$form[$this->fieldFor('tiers.enabled')] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Spread History Across Several Buckets'),
			'#default_value' => (bool) $this->setting('tiers.enabled', false),
			'#description' => $this->t(
				'Off writes everything to the one provider on the Storage tab, exactly as before.',
			),
		];

		$form[$this->fieldFor('tiers.verify_copies')] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Verify Every Copy Before Dropping the Nearer One'),
			'#default_value' => (bool) $this->setting('tiers.verify_copies', true),
			'#description' => $this->t(
				'Costs one extra read per object, once. Off gives up the only proof the copy arrived.',
			),
		];

		$this->addLadder($form);

		return parent::buildForm($form, $form_state);
	}

	/**
	 * {@inheritdoc}
	 */
	public function validateForm(array &$form, FormStateInterface $form_state): void
	{
		$rows = $this->rowsFrom($form_state);

		if ((bool) $form_state->getValue($this->fieldFor('tiers.enabled')) && count($rows) < 2) {
			$form_state->setErrorByName(
				'tier_1_provider',
				$this->t('Tiering needs at least two tiers, so name a provider on the second one.'),
			);
		}

		try {
			TierMap::fromSettings(true, $rows);
		} catch (InvalidArgumentException $error) {
			$form_state->setErrorByName('tier_1_provider', $error->getMessage());
		}

		parent::validateForm($form, $form_state);
	}

	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state): void
	{
		$this->config(self::SETTINGS)->set('tiers.levels', $this->rowsFrom($form_state))->save();

		parent::submitForm($form, $form_state);
	}

	#region Sections

	/**
	 * The tier rows.
	 *
	 * @param array<string, mixed> $form
	 *   The form, added to by reference.
	 */
	private function addLadder(array &$form): void
	{
		/** @var list<array<string, mixed>> $configured */
		$configured = $this->setting('tiers.levels', []) ?? [];

		$form['ladder'] = [
			'#type' => 'details',
			'#title' => $this->t('Tiers, Nearest First'),
			'#open' => true,
			'#description' => $this->cutoffs(),
			'#tree' => false,
		];

		$form['ladder']['example'] = [
			'#type' => 'item',
			'#title' => $this->t('A Worked Ladder'),
			'#markup' => $this->t(
				'Tier 0 on the bucket this site already writes to, taking everything. Tier 1 pointed at a cheaper storage class, taking objects older than 2592000 seconds, which is 30 days. Keep the copy below it until a verify has read the far one back.',
			),
		];

		for ($tier = 0; $tier < self::TIERS; $tier++) {
			$this->addTier($form['ladder'], $tier, $configured[$tier] ?? []);
		}
	}

	/**
	 * One tier row.
	 *
	 * @param array<string, mixed> $ladder
	 *   The ladder element, added to by reference.
	 * @param int $tier
	 *   Position on the ladder.
	 * @param array<string, mixed> $configured
	 *   What is configured for that position.
	 */
	private function addTier(array &$ladder, int $tier, array $configured): void
	{
		$ladder[sprintf('tier_%d_name', $tier)] = [
			'#type' => 'textfield',
			'#title' => $this->t('Tier @n Name', ['@n' => $tier]),
			'#maxlength' => 48,
			'#default_value' => (string) ($configured['name'] ?? ''),
			'#description' => $this->t('Also the name its request counts are reported under.'),
		];

		$ladder[sprintf('tier_%d_provider', $tier)] = [
			'#type' => 'select',
			'#title' => $this->t('Tier @n Provider', ['@n' => $tier]),
			'#options' => $this->providerOptions(),
			'#default_value' => (string) ($configured['provider'] ?? ''),
		];

		$ladder[sprintf('tier_%d_location', $tier)] = [
			'#type' => 'textfield',
			'#title' => $this->t('Tier @n Bucket or Directory', ['@n' => $tier]),
			'#default_value' => (string) ($configured['location'] ?? ''),
			'#description' => $this->t('Empty uses whatever that provider is configured with.'),
		];

		$ladder[sprintf('tier_%d_storage_class', $tier)] = [
			'#type' => 'textfield',
			'#title' => $this->t('Tier @n Storage Class', ['@n' => $tier]),
			'#default_value' => (string) ($configured['storage_class'] ?? ''),
			'#description' => $this->t('Ignored by a provider whose endpoint has no such thing.'),
		];

		if ($tier > 0) {
			$ladder[sprintf('tier_%d_from_age', $tier)] = [
				'#type' => 'number',
				'#title' => $this->t('Tier @n Takes Objects Older Than, Seconds', ['@n' => $tier]),
				'#min' => 1,
				'#default_value' => (int) ($configured['from_age'] ?? 0),
			];

			$ladder[sprintf('tier_%d_retain_below', $tier)] = [
				'#type' => 'checkbox',
				'#title' => $this->t('Tier @n Keeps the Copy Below It', ['@n' => $tier]),
				'#default_value' => (bool) ($configured['retain_below'] ?? false),
				'#description' => $this->t(
					'On is a second copy. Off moves the object, so history is split across buckets.',
				),
			];
		}
	}

	#endregion

	/**
	 * The tier rows a submission describes, with the empty ones dropped.
	 *
	 * @param FormStateInterface $form_state
	 *   The submitted form.
	 *
	 * @return list<array<string, mixed>>
	 *   Rows in ladder order.
	 */
	private function rowsFrom(FormStateInterface $form_state): array
	{
		$rows = [];

		for ($tier = 0; $tier < self::TIERS; $tier++) {
			$provider = trim((string) $form_state->getValue(sprintf('tier_%d_provider', $tier)));

			// a row nobody named a provider for is a row this site does not use
			if ($provider === '') {
				continue;
			}

			$rows[] = [
				'name' => trim((string) $form_state->getValue(sprintf('tier_%d_name', $tier))),
				'provider' => $provider,
				'location' => trim(
					(string) $form_state->getValue(sprintf('tier_%d_location', $tier)),
				),
				'storage_class' => trim(
					(string) $form_state->getValue(sprintf('tier_%d_storage_class', $tier)),
				),
				'from_age' =>
					count($rows) === 0
						? 0
						: (int) $form_state->getValue(sprintf('tier_%d_from_age', $tier)),
				'retain_below' => (bool) $form_state->getValue(
					sprintf('tier_%d_retain_below', $tier),
				),
			];
		}

		return $rows;
	}

	/**
	 * Where the retention ladder's levels currently end, as a hint for the age thresholds.
	 *
	 * @return string
	 *   A sentence naming the cutoffs, or a plain sentence when nothing is configured.
	 */
	private function cutoffs(): string
	{
		/** @var array<int, array<string, mixed>> $levels */
		$levels = $this->setting('retention.levels', []) ?? [];
		$keeps = [];

		foreach (LevelPolicy::fromSettings($levels)->levels() as $level) {
			if ($level['keep'] > 0) {
				$keeps[] = (string) $level['keep'];
			}
		}

		if ($keeps === []) {
			return (string) $this->t('The retention ladder keeps every level forever.');
		}

		return (string) $this->t('Retention folds levels at @seconds seconds.', [
			'@seconds' => implode(', ', $keeps),
		]);
	}

	/**
	 * The providers a tier can be built from.
	 *
	 * @return array<string, string>
	 *   Provider id keyed to a label, with an empty option meaning the row is unused.
	 */
	private function providerOptions(): array
	{
		$options = [
			'' => (string) $this->t('Unused'),
			'local' => (string) $this->t('Local Directory'),
		];

		foreach ($this->engine?->providerIds() ?? [] as $id) {
			$options[$id] = StorageSettingsForm::providerLabel($id);
		}

		return $options;
	}
}
