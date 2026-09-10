<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\strata\Engine;
use Drupal\strata_ui\Render\Format;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

/**
 * Seals whatever is captured but not yet written, now rather than on the next cron.
 *
 * **This is the last step of a first install, and the only one an operator with no shell can reach.**
 * A key, a destination and capture switched on are all forms; sealing was a cron run or a drush
 * command, and a site can be missing both. No report shows anything until one commit exists.
 *
 * The flush is forced rather than policy-driven. Somebody pressing this button is not asking whether
 * a bound has been reached.
 *
 * @see Engine::flusher()
 */
final class FlushConfirmForm extends ConfirmFormBase
{
	/**
	 * The engine, once injected.
	 */
	protected Engine $engine;

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
		return 'strata_flush_confirm';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getQuestion(): TranslatableMarkup
	{
		return $this->t('Seal everything captured so far?');
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCancelUrl(): Url
	{
		return Url::fromRoute('strata_ui.status');
	}

	/**
	 * {@inheritdoc}
	 */
	public function getConfirmText(): TranslatableMarkup
	{
		return $this->t('Seal Now');
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array<string, mixed> $form
	 *   The form.
	 * @param FormStateInterface $form_state
	 *   The form state.
	 *
	 * @return array<string, mixed>
	 *   The form.
	 */
	public function buildForm(array $form, FormStateInterface $form_state): array
	{
		$form = parent::buildForm($form, $form_state);

		$form['what'] = [
			'#type' => 'item',
			'#title' => $this->t('What This Does'),
			'#markup' => $this->t(
				'Writes the captured changes to the store as one commit, which is what cron does on its own schedule.',
			),
			'#weight' => -10,
		];

		$form['pending'] = [
			'#type' => 'item',
			'#title' => $this->t('Waiting to Seal'),
			'#markup' => $this->pending(),
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
			$result = $this->engine->flusher()->flush(true);

			$result->ran
				? $this->messenger()->addStatus($result->summary())
				: $this->messenger()->addWarning(
					$this->t('Nothing was captured, so there was nothing to seal.'),
				);
		} catch (Throwable $error) {
			$this->messenger()->addError(
				$this->t('The window could not be sealed: @why', ['@why' => $error->getMessage()]),
			);
		}

		$form_state->setRedirectUrl($this->getCancelUrl());
	}

	/**
	 * How much is captured and not yet written.
	 *
	 * @return string
	 *   The count and its size, or why neither could be read.
	 */
	private function pending(): string
	{
		try {
			$journal = $this->engine->journal();

			// @count rather than @ops would be read as a plural argument, which this is not
			return (string) $this->t('@ops operations, @bytes', [
				'@ops' => Format::count($journal->pending()),
				'@bytes' => Format::bytes($journal->pendingBytes()),
			]);
		} catch (Throwable $error) {
			return (string) $this->t('Could not be read: @why', ['@why' => $error->getMessage()]);
		}
	}
}
