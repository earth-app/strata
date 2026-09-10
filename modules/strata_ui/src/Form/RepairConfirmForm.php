<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\strata\Engine;
use Drupal\strata\Health\RepairLadder;
use Drupal\strata_ui\Render\Format;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

/**
 * Runs one safe repair rung against a finding code, on demand.
 *
 * The same rungs cron runs, run now and reported rather than logged. What each one does is on the
 * page, because "refetch" and "rebuild" are not self-explanatory and an operator about to click one
 * during an incident should not have to read the source to find out whether it writes anything.
 *
 * **The two upper rungs are not reachable from here.** Quarantine has its own form and refuse is not
 * an action at all - it is the state a finding reaches when nothing automatic can help, and clearing
 * it means fixing the underlying fault.
 *
 * @see RepairLadder
 */
final class RepairConfirmForm extends ConfirmFormBase
{
	/**
	 * The engine, once injected.
	 */
	protected Engine $engine;

	/**
	 * The finding code being repaired.
	 */
	protected string $code = '';

	/**
	 * {@inheritdoc}
	 */
	public static function create(ContainerInterface $container): static
	{
		$form = new static();
		$form->engine = $container->get('strata.engine');

		return $form;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getFormId(): string
	{
		return 'strata_repair_confirm';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getQuestion(): TranslatableMarkup
	{
		return $this->t('Run the @rung rung against @code?', [
			'@rung' => $this->rung(),
			'@code' => $this->code,
		]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCancelUrl(): Url
	{
		return Url::fromRoute('strata_ui.health');
	}

	/**
	 * {@inheritdoc}
	 */
	public function getConfirmText(): TranslatableMarkup
	{
		return $this->t('Repair');
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array<string, mixed> $form
	 *   The form.
	 * @param FormStateInterface $form_state
	 *   The form state.
	 * @param string $code
	 *   The finding code to repair.
	 *
	 * @return array<string, mixed>
	 *   The form.
	 */
	public function buildForm(array $form, FormStateInterface $form_state, string $code = ''): array
	{
		$this->code = $code;
		$rung = $this->rung();
		$form = parent::buildForm($form, $form_state);

		if (!RepairLadder::isAutomatic($rung)) {
			$form['actions']['submit']['#access'] = false;
			$form['manual'] = [
				'#type' => 'item',
				'#title' => $this->t('Not Automatic'),
				'#markup' => $this->t(
					'@code sits on the @rung rung, which needs a person and is not offered here.',
					['@code' => $code, '@rung' => $rung],
				),
				'#weight' => -10,
			];

			return $form;
		}

		$form['what'] = [
			'#type' => 'item',
			'#title' => $this->t('What This Does'),
			'#markup' => $this->describe($rung),
			'#weight' => -10,
		];

		$form['scopes'] = [
			'#type' => 'item',
			'#title' => $this->t('Open Scopes'),
			'#markup' => Format::count($this->scopes()),
			'#weight' => -9,
		];

		return $form;
	}

	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state): void
	{
		try {
			$report = $this->engine->repairPass()->runCode($this->code);

			$this->messenger()->addStatus(
				$report->ran === []
					? $this->t('@code was observed. Nothing needed doing.', [
						'@code' => $this->code,
					])
					: $report->summary(),
			);

			foreach ($report->ran as $line) {
				$this->messenger()->addStatus($line);
			}
		} catch (Throwable $error) {
			$this->messenger()->addError(
				$this->t('The repair did not complete: @why', ['@why' => $error->getMessage()]),
			);
		}

		$form_state->setRedirectUrl($this->getCancelUrl());
	}

	/**
	 * The rung this code currently sits on.
	 *
	 * @return string
	 *   A rung name.
	 */
	private function rung(): string
	{
		return $this->code === '' ? 'observe' : $this->engine->ledger()->rungFor($this->code);
	}

	/**
	 * How many scopes are open under this code.
	 *
	 * @return int
	 *   The count.
	 */
	private function scopes(): int
	{
		foreach ($this->engine->ledger()->summary() as $row) {
			if ((string) $row['code'] === $this->code) {
				return (int) $row['scopes'];
			}
		}

		return 0;
	}

	/**
	 * What one rung does, in a sentence.
	 *
	 * @param string $rung
	 *   The rung name.
	 *
	 * @return string
	 *   The description.
	 */
	private function describe(string $rung): string
	{
		return match ($rung) {
			'observe' => (string) $this->t('Records the finding and changes nothing.'),
			'reindex' => (string) $this->t(
				'Rebuilds the local index from the bucket. Writes nothing to the bucket.',
			),
			'refetch' => (string) $this->t(
				'Re-reads every object and checks it against the address it is filed under.',
			),
			'rebuild' => (string) $this->t(
				'Re-reads every object, as refetch does. Reaching here means refetch did not settle it.',
			),
			default => (string) $this->t('This rung is not run from here.'),
		};
	}
}
