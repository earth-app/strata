<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\strata\Engine;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\RepairLadder;
use Drupal\strata_ui\Render\Format;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Marks a commit unusable as a restore target, keeping its bytes.
 *
 * Quarantine is the rung above the ones cron may run, and this is the only way to reach it. It exists
 * for the case where a commit is known to be bad - a bad deploy captured, a corrupt payload written -
 * and someone needs it removed from the restore targets before a colleague picks it off the timeline.
 *
 * **Nothing is deleted.** The bytes stay exactly where they are, and a later release, a reindex or a
 * repair can bring the commit back. That is the whole difference between quarantine and prune, and it
 * is why quarantine is reversible and prune is not.
 *
 * @see RepairLadder
 */
final class QuarantineConfirmForm extends ConfirmFormBase
{
	/**
	 * The finding code a quarantined commit is recorded under.
	 */
	public const CODE = 'commit.quarantined';

	/**
	 * The engine, once injected.
	 */
	protected Engine $engine;

	/**
	 * The commit being quarantined.
	 */
	protected string $commit = '';

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
		return 'strata_quarantine_confirm';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getQuestion(): TranslatableMarkup
	{
		return $this->t('Quarantine @commit?', ['@commit' => Format::address($this->commit)]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getDescription(): TranslatableMarkup
	{
		return $this->t(
			'It stops being a restore target. Its bytes are kept, so this can be undone.',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCancelUrl(): Url
	{
		return Url::fromRoute('strata_ui.timeline');
	}

	/**
	 * {@inheritdoc}
	 */
	public function getConfirmText(): TranslatableMarkup
	{
		return $this->t('Quarantine');
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array<string, mixed> $form
	 *   The form.
	 * @param FormStateInterface $form_state
	 *   The form state.
	 * @param string $commit
	 *   The commit to quarantine.
	 *
	 * @return array<string, mixed>
	 *   The form.
	 */
	public function buildForm(
		array $form,
		FormStateInterface $form_state,
		string $commit = '',
	): array {
		$this->commit = $commit;
		$row = $this->engine->commitIndex()->get($commit);
		$form = parent::buildForm($form, $form_state);

		if ($row === null) {
			$form['actions']['submit']['#access'] = false;
			$form['missing'] = [
				'#type' => 'item',
				'#markup' => $this->t('That commit is not in the local index.'),
				'#weight' => -10,
			];

			return $form;
		}

		$form['detail'] = [
			'#type' => 'table',
			'#header' => [$this->t('Field'), $this->t('Value')],
			'#rows' => [
				[$this->t('Sealed'), Format::moment((int) $row['microtime'])],
				[$this->t('Covers'), (string) $row['label']],
				[$this->t('Operations'), Format::count((int) $row['operations'])],
				[$this->t('Stored'), Format::bytes((int) $row['stored_bytes'])],
			],
			'#weight' => -10,
		];

		$form['reason'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Reason'),
			'#required' => true,
			'#maxlength' => 200,
			'#description' => $this->t(
				'Recorded with the finding, so whoever finds this later knows why it was done.',
			),
			'#weight' => -5,
		];

		return $form;
	}

	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state): void
	{
		$ledger = $this->engine->ledger();

		$ledger->record(
			new Finding(
				self::CODE,
				Finding::ERROR,
				$this->commit,
				sprintf(
					'quarantined by %s: %s',
					$this->currentUser()->getAccountName(),
					(string) $form_state->getValue('reason'),
				),
			),
		);

		// straight to the rung that keeps it out of the restore targets, never through the safe ones
		$ledger->setRung(self::CODE, 'quarantine');

		$this->messenger()->addStatus(
			$this->t('@commit is quarantined. Its bytes are untouched.', [
				'@commit' => Format::address($this->commit),
			]),
		);

		$form_state->setRedirectUrl($this->getCancelUrl());
	}
}
