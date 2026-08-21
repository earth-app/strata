<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\strata\Access\RestoreAccess;
use Drupal\strata\Engine;
use Drupal\strata\Restore\Conflict;
use Drupal\strata\Restore\RestorePlan;
use Drupal\strata\Restore\SubjectStatus;
use Drupal\strata_ui\Render\Format;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

/**
 * Prints exactly what a rollback would do, then asks.
 *
 * **The manifest is built before the question, not after the answer.** Every subject is classified as
 * restorable, degraded or unrestorable by a real preflight against the real store, and the split is
 * on the page above the button. A confirmation that said "this will restore 1,240 subjects" without
 * saying which of them cannot be read would be asking the operator to approve something nobody had
 * checked.
 *
 * **A degraded subject is skipped by default and the form says so.** Filling it with a default value
 * would invent data, and inventing data during a recovery is worse than leaving the current value in
 * place. Opting in is a separate checkbox with the count next to it.
 *
 * **Concurrent edits are surfaced separately from the rollback itself.** Every rollback discards
 * changes made after the target - that is what a rollback is - so listing those would flag every
 * restore as dangerous. What is worth flagging is a subject someone edited AFTER this plan was built,
 * because that edit was made by a person who did not know this was about to happen.
 *
 * Access is checked here rather than on the route: it depends on which realms the plan touches, and
 * that is not knowable until the plan exists.
 *
 * @see RestorePlan
 * @see RestoreAccess
 */
final class RollbackConfirmForm extends ConfirmFormBase
{
	/**
	 * The engine, once injected.
	 */
	protected Engine $engine;

	/**
	 * The commit being rolled back to.
	 */
	protected string $commit = '';

	/**
	 * The plan, once built.
	 */
	protected ?RestorePlan $plan = null;

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
		return 'strata_rollback_confirm';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getQuestion(): TranslatableMarkup
	{
		return $this->t('Roll the site back to @commit?', [
			'@commit' => Format::address($this->commit),
		]);
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
		return $this->t('Roll Back');
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array<string, mixed> $form
	 *   The form.
	 * @param FormStateInterface $form_state
	 *   The form state.
	 * @param string $commit
	 *   The commit to roll back to.
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
		$plan = $this->plan();

		// a plan covering no subject has nothing to confirm, which is what an absent commit produces:
		// Preflight reports the unreadable target as a problem rather than raising, so a form that
		// only checked for NULL would offer a live button for a commit that does not exist
		if ($plan === null || $plan->subjects === []) {
			$build = [
				'reason' => ['#markup' => $this->t('That commit could not be planned against.')],
			];

			foreach ($plan->problems ?? [] as $at => $problem) {
				$build['problem_' . $at] = [
					'#type' => 'container',
					'#attributes' => ['class' => ['strata-warning']],
					'text' => ['#markup' => $problem],
				];
			}

			return $build;
		}

		$access = $this->engine->restoreAccess()->forPlan($this->currentUser(), $plan);

		if (!$access->isAllowed()) {
			return [
				'#markup' => $this->t(
					'This plan touches realms you may not roll back, and a plan is never partly applied.',
				),
			];
		}

		$form = parent::buildForm($form, $form_state);
		$counts = $plan->counts();

		$form['manifest'] = $this->manifest($plan, $counts);
		$form['conflicts'] = $this->conflicts($plan);

		$form['snapshot'] = [
			'#type' => 'item',
			'#title' => $this->t('Before Anything Is Written'),
			'#markup' => $this->t(
				'A snapshot is captured first, always. It makes this reversible.',
			),
			'#weight' => 40,
		];

		$form['fill_degraded'] = [
			'#type' => 'checkbox',
			'#title' => $this->t(
				'Also write the @count degraded subjects, filling what could not be read',
				['@count' => $counts[SubjectStatus::DEGRADED->value] ?? 0],
			),
			'#default_value' => false,
			'#description' => $this->t(
				'Filling what could not be read invents data. Leave this off.',
			),
			'#access' => ($counts[SubjectStatus::DEGRADED->value] ?? 0) > 0,
			'#weight' => 45,
		];

		$form['accept_conflicts'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Overwrite the subjects edited since this plan was built'),
			'#default_value' => false,
			'#access' => $this->engine->preflight()->concurrentChanges($plan) !== [],
			'#weight' => 46,
		];

		return $form;
	}

	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state): void
	{
		if ($this->commit === '') {
			$this->messenger()->addError($this->t('That commit could not be planned against.'));

			return;
		}

		// re-planned against the store as it is now rather than as the confirmation page found it
		$plan = $this->engine
			->preflight()
			->plan(
				$this->commit,
				(bool) $form_state->getValue('fill_degraded'),
				null,
				(bool) $form_state->getValue('accept_conflicts'),
			);

		$result = $this->engine->logicalRestore()->apply($plan, 'ui', true);

		if ($result->wasRefused()) {
			$this->messenger()->addError(
				$this->t('The rollback was refused: @why', ['@why' => (string) $result->refused]),
			);
		} else {
			$this->messenger()->addStatus($result->summary());
		}

		$form_state->setRedirectUrl($this->getCancelUrl());
	}

	#region Manifest

	/**
	 * The manifest, as a table of counts and a list of what will not be written.
	 *
	 * @param RestorePlan $plan
	 *   The plan.
	 * @param array<string, int> $counts
	 *   How many subjects fall in each status.
	 *
	 * @return array<string, mixed>
	 *   A render array.
	 */
	private function manifest(RestorePlan $plan, array $counts): array
	{
		$rows = [];

		foreach (SubjectStatus::cases() as $status) {
			$rows[] = [
				$status->label(),
				Format::count($counts[$status->value] ?? 0),
				$status->isWrittenByDefault()
					? $this->t('Written')
					: $this->t('Skipped and listed in the audit log'),
			];
		}

		$build = [
			'#type' => 'details',
			'#title' => $this->t('What Would Happen'),
			'#open' => true,
			'#weight' => 10,
		];

		$build['counts'] = [
			'#type' => 'table',
			'#header' => [$this->t('Status'), $this->t('Subjects'), $this->t('Action')],
			'#rows' => $rows,
		];

		$build['depth'] = [
			'#type' => 'item',
			'#markup' => $this->t(
				'Replaying to this point walks @depth commits and took @seconds to plan.',
				[
					'@depth' => Format::count($plan->depth),
					'@seconds' => Format::duration($plan->seconds),
				],
			),
		];

		if ($plan->problems !== []) {
			$build['problems'] = [
				'#theme' => 'item_list',
				'#title' => $this->t('Problems Found While Planning'),
				'#items' => array_slice($plan->problems, 0, 50),
			];
		}

		$build['never'] = [
			'#type' => 'item',
			'#markup' => $this->t(
				'Later content is never deleted, and access fields are never written.',
			),
		];

		return $build;
	}

	/**
	 * The subjects someone edited after this plan was built.
	 *
	 * @param RestorePlan $plan
	 *   The plan.
	 *
	 * @return array<string, mixed>
	 *   A render array, empty when there are none.
	 */
	private function conflicts(RestorePlan $plan): array
	{
		$conflicts = $this->engine->preflight()->concurrentChanges($plan);

		if ($conflicts === []) {
			return [];
		}

		$items = [];

		foreach (array_slice($conflicts, 0, 50) as $conflict) {
			$items[] = $conflict instanceof Conflict ? $conflict->describe() : (string) $conflict;
		}

		return [
			'#theme' => 'item_list',
			'#title' => $this->t('@count subjects changed after this plan was built', [
				'@count' => count($conflicts),
			]),
			'#items' => $items,
			'#weight' => 20,
		];
	}

	#endregion

	/**
	 * The plan for this commit, built once.
	 *
	 * @return RestorePlan|null
	 *   The plan, or NULL when the commit cannot be planned against.
	 */
	private function plan(): ?RestorePlan
	{
		if ($this->plan !== null) {
			return $this->plan;
		}
		if ($this->commit === '') {
			return null;
		}

		try {
			return $this->plan = $this->engine->preflight()->plan($this->commit);
		} catch (Throwable) {
			return null;
		}
	}
}
