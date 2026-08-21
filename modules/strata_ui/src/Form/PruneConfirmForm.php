<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\strata\Compaction\PruneReceipt;
use Drupal\strata\Engine;
use Drupal\strata_ui\Render\Format;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

/**
 * Shows what a prune would destroy, then asks.
 *
 * A prune is the one operation here that cannot be undone by anything Strata holds - it removes the
 * bytes a restore would have needed. So the page is built from a real dry run against the real store,
 * and what it shows is the receipt that dry run produced rather than a projection.
 *
 * **A prune that would orphan something reachable refuses, and the refusal is shown instead of the
 * button.** All three reachability classes are checked: a commit or anchor, a dictionary any live
 * frame's header names, and a delta parent any live frame decodes against. A store whose reachability
 * walk could not finish is never pruned on a guess.
 *
 * @see PruneReceipt
 */
final class PruneConfirmForm extends ConfirmFormBase
{
	/**
	 * The engine, once injected.
	 */
	protected Engine $engine;

	/**
	 * The dry run, once performed.
	 */
	protected ?PruneReceipt $preview = null;

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
		return 'strata_prune_confirm';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getQuestion(): TranslatableMarkup
	{
		return $this->t('Prune stored history?');
	}

	/**
	 * {@inheritdoc}
	 */
	public function getDescription(): TranslatableMarkup
	{
		return $this->t('This destroys restore points permanently, and writes a receipt first.');
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCancelUrl(): Url
	{
		return Url::fromRoute('strata_ui.explorer');
	}

	/**
	 * {@inheritdoc}
	 */
	public function getConfirmText(): TranslatableMarkup
	{
		return $this->t('Prune');
	}

	/**
	 * {@inheritdoc}
	 */
	public function buildForm(array $form, FormStateInterface $form_state): array
	{
		$receipt = $this->preview();
		$form = parent::buildForm($form, $form_state);

		if ($receipt->wasRefused()) {
			$form['actions']['submit']['#access'] = false;
			$form['refused'] = [
				'#type' => 'item',
				'#title' => $this->t('Refused'),
				'#markup' => (string) $receipt->refused,
				'#weight' => -10,
			];

			return $form;
		}

		$form['preview'] = [
			'#type' => 'table',
			'#header' => [$this->t('What'), $this->t('How Much')],
			'#rows' => [
				[$this->t('Frames removed'), Format::count(count($receipt->frames))],
				[$this->t('Objects removed'), Format::count(count($receipt->objects))],
				[$this->t('Commits no longer restorable'), Format::count(count($receipt->commits))],
				[
					$this->t('Frames kept because something needs them'),
					Format::count($receipt->keptCount()),
				],
				[$this->t('Bytes freed'), Format::bytes($receipt->bytes)],
			],
			'#weight' => -10,
		];

		if ($receipt->isEmpty()) {
			$form['actions']['submit']['#access'] = false;
			$form['nothing'] = [
				'#type' => 'item',
				'#markup' => $this->t('There is nothing to prune.'),
				'#weight' => -9,
			];
		}

		return $form;
	}

	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state): void
	{
		try {
			$receipt = $this->engine->compactor()->prune(true);
		} catch (Throwable $error) {
			$this->messenger()->addError(
				$this->t('The prune could not run: @why', ['@why' => $error->getMessage()]),
			);
			$form_state->setRedirectUrl($this->getCancelUrl());

			return;
		}

		if ($receipt->wasRefused()) {
			$this->messenger()->addError(
				$this->t('The prune was refused: @why', ['@why' => (string) $receipt->refused]),
			);
		} else {
			$this->messenger()->addStatus($receipt->summary());
		}

		$form_state->setRedirectUrl($this->getCancelUrl());
	}

	/**
	 * The dry run this page is built from.
	 *
	 * @return PruneReceipt
	 *   The receipt.
	 */
	private function preview(): PruneReceipt
	{
		if ($this->preview !== null) {
			return $this->preview;
		}

		// building a compactor reads the provider and the cipher, and either can refuse
		try {
			return $this->preview = $this->engine->compactor()->prune(false);
		} catch (Throwable $error) {
			return $this->preview = new PruneReceipt(
				false,
				[],
				[],
				0,
				[],
				[],
				$error->getMessage(),
			);
		}
	}
}
