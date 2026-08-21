<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\strata\Estimate\Estimator;
use Drupal\strata\Estimate\FreeTierModel;
use Drupal\strata\Estimate\Measurement;
use Drupal\strata\Estimate\PriceTable;
use Drupal\strata\Estimate\Projection;
use Drupal\strata_ui\Render\Format;

/**
 * Projects what a site's history will cost, from measured constants rather than guesses.
 *
 * **Every number this multiplies was measured and is named**, so a projection can be argued with
 * rather than believed.
 *
 * The projection is not saved, reads nothing about this site, and changes nothing. It is a calculator
 * that happens to know the measured constants of the pipeline it is calculating for, which is why it
 * answers for a site that does not exist yet as readily as for this one.
 *
 * @see Estimator
 * @see Projection
 * @see FreeTierModel
 */
final class EstimateForm extends FormBase
{
	/**
	 * {@inheritdoc}
	 */
	public function getFormId(): string
	{
		return 'strata_estimate';
	}

	/**
	 * {@inheritdoc}
	 */
	public function buildForm(array $form, FormStateInterface $form_state): array
	{
		$form['#attached']['library'][] = 'strata_ui/strata';

		$form['scale'] = [
			'#type' => 'details',
			'#title' => $this->t('The Site'),
			'#open' => true,
		];

		$form['scale']['users'] = [
			'#type' => 'number',
			'#title' => $this->t('Registered Users'),
			'#min' => 0,
			'#default_value' => $form_state->getValue('users') ?? 1000,
			'#description' => $this->t('A user costs about ten times what a node costs.'),
		];

		$form['scale']['nodes'] = [
			'#type' => 'number',
			'#title' => $this->t('Content Items'),
			'#min' => 0,
			'#default_value' => $form_state->getValue('nodes') ?? 5000,
		];

		$form['scale']['active_share'] = [
			'#type' => 'number',
			'#title' => $this->t('Share of Users Active Daily'),
			'#min' => 0,
			'#max' => 1,
			'#step' => 0.01,
			'#default_value' => $form_state->getValue('active_share') ?? 0.1,
		];

		$form['scale']['file_bytes'] = [
			'#type' => 'number',
			'#title' => $this->t('Bytes of Files'),
			'#min' => 0,
			'#default_value' => $form_state->getValue('file_bytes') ?? 0,
			'#description' => $this->t('A gigabyte of media costs about what 100,000 users cost.'),
		];

		$form['policy'] = [
			'#type' => 'details',
			'#title' => $this->t('The Policy'),
			'#open' => true,
		];

		$form['policy']['capture_files'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Capture Files'),
			'#default_value' => $form_state->getValue('capture_files') ?? false,
		];

		$form['policy']['access_churn'] = [
			'#type' => 'select',
			'#title' => $this->t('Access Churn'),
			'#options' => [
				Measurement::ACCESS_EVENT => $this->t('Compact Login Event'),
				Measurement::ACCESS_DELTA => $this->t('Full Field Delta'),
				Measurement::ACCESS_OFF => $this->t('Not Captured'),
			],
			'#default_value' => $form_state->getValue('access_churn') ?? Measurement::ACCESS_EVENT,
		];

		$form['policy']['segment_interval'] = [
			'#type' => 'number',
			'#title' => $this->t('Flush Interval, Seconds'),
			'#min' => 1,
			'#default_value' => $form_state->getValue('segment_interval') ?? 15,
		];

		$form['policy']['base_interval'] = [
			'#type' => 'number',
			'#title' => $this->t('Anchor Interval, Seconds'),
			'#min' => 60,
			'#default_value' => $form_state->getValue('base_interval') ?? 14_400,
		];

		$form['policy']['retention_days'] = [
			'#type' => 'number',
			'#title' => $this->t('Retained, Days'),
			'#min' => 1,
			'#default_value' => $form_state->getValue('retention_days') ?? 365,
		];

		$form['actions'] = [
			'#type' => 'actions',
			'submit' => [
				'#type' => 'submit',
				'#value' => $this->t('Project'),
			],
		];

		if ($form_state->get('projection') instanceof Projection) {
			$form['result'] = $this->result($form_state->get('projection'));
		}

		return $form;
	}

	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state): void
	{
		$measurement = new Measurement(
			(int) $form_state->getValue('users'),
			(int) $form_state->getValue('nodes'),
			0,
			0,
			(int) $form_state->getValue('file_bytes'),
			(float) $form_state->getValue('active_share'),
			max(1, (int) $form_state->getValue('segment_interval')),
			max(60, (int) $form_state->getValue('base_interval')),
			max(1, (int) $form_state->getValue('retention_days')),
			150,
			(string) $form_state->getValue('access_churn'),
			(bool) $form_state->getValue('capture_files'),
		);

		$form_state->set('projection', (new Estimator())->project($measurement));
		$form_state->setRebuild(true);
	}

	/**
	 * The projection, rendered.
	 *
	 * @param Projection $projection
	 *   What was projected.
	 *
	 * @return array<string, mixed>
	 *   A render array.
	 */
	private function result(Projection $projection): array
	{
		$build = [
			'#type' => 'details',
			'#title' => $this->t('Projection'),
			'#open' => true,
			'#weight' => 100,
		];

		$build['summary'] = ['#markup' => $projection->summary()];
		$build['components'] = [
			'#type' => 'table',
			'#caption' => $this->t('Where the Bytes Go'),
			'#header' => [$this->t('Component'), $this->t('Stored'), $this->t('Share')],
			'#rows' => $this->components($projection),
		];
		$build['providers'] = [
			'#type' => 'table',
			'#caption' => $this->t('Cost a Month'),
			'#header' => [
				$this->t('Provider'),
				$this->t('Storage'),
				$this->t('Requests'),
				$this->t('Total'),
			],
			'#rows' => $this->providers($projection),
		];
		$build['free'] = $this->freeTier($projection);

		if ($projection->notes !== []) {
			$build['notes'] = [
				'#theme' => 'item_list',
				'#title' => $this->t('Worth Knowing'),
				'#items' => $projection->notes,
			];
		}

		return $build;
	}

	/**
	 * The per-component breakdown.
	 *
	 * @param Projection $projection
	 *   The projection.
	 *
	 * @return list<array<int, string>>
	 *   Table rows, largest first.
	 */
	private function components(Projection $projection): array
	{
		$components = $projection->components();
		arsort($components);

		$rows = [];

		foreach ($components as $name => $bytes) {
			$rows[] = [
				(string) $name,
				Format::bytes($bytes),
				sprintf('%.1f%%', $projection->share((string) $name) * 100),
			];
		}

		return $rows;
	}

	/**
	 * The cost at each provider this release knows the prices of.
	 *
	 * @param Projection $projection
	 *   The projection.
	 *
	 * @return list<array<int, string>>
	 *   Table rows.
	 */
	private function providers(Projection $projection): array
	{
		$rows = [];

		foreach (PriceTable::all() as $id => $prices) {
			$rows[] = [
				(string) $id,
				Format::money($prices->storageCost($projection->totalBytes())),
				Format::money($prices->writeCost($projection->writesPerMonth)),
				Format::money($projection->monthlyCost($prices)),
			];
		}

		return $rows;
	}

	/**
	 * Whether this projection fits a free tier, and what binds first.
	 *
	 * @param Projection $projection
	 *   The projection.
	 *
	 * @return array<string, mixed>
	 *   A render array.
	 */
	private function freeTier(Projection $projection): array
	{
		$model = new FreeTierModel(PriceTable::r2(), $projection);

		return [
			'#type' => 'item',
			'#title' => $this->t('Cloudflare R2 Free Tier'),
			'#markup' => $model->summary(),
		];
	}
}
