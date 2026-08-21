<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\strata\Branch\MergeEntry;
use Drupal\strata\Branch\MergeOutcome;
use Drupal\strata\Branch\MergePlan;
use Drupal\strata\Branch\MergeStrategy;
use Drupal\strata\Engine;
use Drupal\strata_ui\Render\Format;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

/**
 * Prints exactly what merging a branch would do, then asks.
 *
 * **The manifest is built before the question, not after the answer.** Every configuration object one
 * side or the other touched is classified by a real three-way comparison against the real merge base,
 * and the split is on the page above the button.
 *
 * **Both values of every collision are printed.** A conflict is two people having written different
 * answers to the same key, and a page that said "3 conflicts" without showing them would be asking
 * somebody to pick between things they have not seen. Nothing is resolved until the strategy select
 * names how, and its default refuses.
 *
 * **The realm refusal is a first-class part of the manifest.** A branch that received a content or
 * table change cannot be merged at all, and the reason is printed with the subjects named rather than
 * the objects being merged and the rest quietly dropped.
 *
 * @see MergePlan
 * @see MergeStrategy
 */
final class MergeConfirmForm extends ConfirmFormBase
{
	/**
	 * The engine, once injected.
	 */
	protected Engine $engine;

	/**
	 * The branch being merged.
	 */
	protected string $branch = '';

	/**
	 * The plan, once built.
	 */
	protected ?MergePlan $plan = null;

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
		return 'strata_merge_confirm';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getQuestion(): TranslatableMarkup
	{
		return $this->t('Merge @branch into the trunk?', ['@branch' => $this->branch]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCancelUrl(): Url
	{
		return Url::fromRoute('strata_ui.branches');
	}

	/**
	 * {@inheritdoc}
	 */
	public function getConfirmText(): TranslatableMarkup
	{
		return $this->t('Merge');
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array<string, mixed> $form
	 *   The form.
	 * @param FormStateInterface $form_state
	 *   The form state.
	 * @param string $branch
	 *   The branch to merge.
	 *
	 * @return array<string, mixed>
	 *   The form.
	 */
	public function buildForm(
		array $form,
		FormStateInterface $form_state,
		string $branch = '',
	): array {
		$this->branch = $branch;
		$plan = $this->plan();

		if ($plan === null) {
			return [
				'reason' => ['#markup' => $this->t('That branch could not be planned against.')],
			];
		}

		$form = parent::buildForm($form, $form_state);

		$form['manifest'] = $this->manifest($plan);
		$form['conflicts'] = $this->conflicts($plan);
		$form['problems'] = $this->problems($plan);

		$form['strategy'] = [
			'#type' => 'select',
			'#title' => $this->t('What to do With a Key Both Sides Changed'),
			'#options' => [
				MergeStrategy::REFUSE->value => $this->t('Refuse'),
				MergeStrategy::OURS->value => $this->t('Keep Ours'),
				MergeStrategy::THEIRS->value => $this->t('Take Theirs'),
			],
			'#default_value' => MergeStrategy::REFUSE->value,
			'#description' => $this->t(
				'Refusing is the default. Picking a side overwrites an edit.',
			),
			'#weight' => 40,
		];

		$form['snapshot'] = [
			'#type' => 'item',
			'#title' => $this->t('Before Anything Is Written'),
			'#markup' => $this->t(
				'A snapshot is captured first, always. It makes this reversible.',
			),
			'#weight' => 45,
		];

		$form['actions']['submit']['#access'] = $plan->problems === [];

		return $form;
	}

	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state): void
	{
		if ($this->branch === '') {
			$this->messenger()->addError($this->t('That branch could not be planned against.'));

			return;
		}

		$merger = $this->engine->merger();

		// re-planned against the store as it is now rather than as the confirmation page found it
		$result = $merger->apply(
			$merger->plan(
				$this->branch,
				MergeStrategy::named((string) $form_state->getValue('strategy')),
			),
			true,
		);

		if ($result->wasRefused()) {
			$this->messenger()->addError(
				$this->t('The merge was refused: @why', ['@why' => (string) $result->refused]),
			);
		} else {
			$this->messenger()->addStatus($result->summary());
		}

		$form_state->setRedirectUrl($this->getCancelUrl());
	}

	#region Manifest

	/**
	 * The manifest, as a table of counts and a row per object.
	 *
	 * @param MergePlan $plan
	 *   The plan.
	 *
	 * @return array<string, mixed>
	 *   A render array.
	 */
	private function manifest(MergePlan $plan): array
	{
		$counts = $plan->counts();
		$summary = [];

		foreach (MergeOutcome::cases() as $outcome) {
			$summary[] = [
				$outcome->label(),
				Format::count($counts[$outcome->value] ?? 0),
				$outcome->isWritten() ? $this->t('Written') : $this->t('Left alone'),
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
			'#header' => [$this->t('Outcome'), $this->t('Objects'), $this->t('Action')],
			'#rows' => $summary,
		];

		$rows = [];

		foreach (array_slice($plan->entries, 0, 200) as $entry) {
			$rows[] = [
				$entry->name,
				$entry->outcome->label(),
				$entry->isWritten() ? $this->t('Yes') : $this->t('No'),
				$entry->describe(),
			];
		}

		if ($rows !== []) {
			$build['objects'] = [
				'#type' => 'table',
				'#header' => [
					$this->t('Object'),
					$this->t('Outcome'),
					$this->t('Written'),
					$this->t('Detail'),
				],
				'#rows' => $rows,
			];
		}

		$build['base'] = [
			'#type' => 'item',
			'#markup' => $this->t('Compared against merge base @base, planned in @seconds.', [
				'@base' => Format::address((string) $plan->base),
				'@seconds' => Format::duration($plan->seconds),
			]),
		];

		return $build;
	}

	/**
	 * Both values of every key the two sides disagree about.
	 *
	 * @param MergePlan $plan
	 *   The plan.
	 *
	 * @return array<string, mixed>
	 *   A render array, empty when nothing collides.
	 */
	private function conflicts(MergePlan $plan): array
	{
		$conflicts = $plan->conflicts();

		if ($conflicts === []) {
			return [];
		}

		$build = [
			'#type' => 'details',
			'#title' => $this->t('@count objects were changed on both sides', [
				'@count' => count($conflicts),
			]),
			'#open' => true,
			'#weight' => 20,
		];

		foreach ($conflicts as $name => $entry) {
			$build['object_' . md5((string) $name)] = $this->conflict($entry);
		}

		return $build;
	}

	/**
	 * One object's colliding keys, with both values.
	 *
	 * @param MergeEntry $entry
	 *   The conflicting entry.
	 *
	 * @return array<string, mixed>
	 *   A render array.
	 */
	private function conflict(MergeEntry $entry): array
	{
		$rows = [];

		foreach (array_slice($entry->conflicts, 0, 50) as $path => $pair) {
			$rows[] = [
				$path === '' ? $this->t('The whole object') : (string) $path,
				$this->render($pair['ours']),
				$this->render($pair['theirs']),
			];
		}

		return [
			'#type' => 'table',
			'#caption' => $entry->name,
			'#header' => [$this->t('Key'), $this->t('On the Trunk'), $this->t('On the Branch')],
			'#rows' => $rows,
		];
	}

	/**
	 * What stops the merge outright.
	 *
	 * @param MergePlan $plan
	 *   The plan.
	 *
	 * @return array<string, mixed>
	 *   A render array, empty when nothing does.
	 */
	private function problems(MergePlan $plan): array
	{
		if ($plan->problems === []) {
			return [];
		}

		return [
			'#theme' => 'item_list',
			'#title' => $this->t('This Merge Cannot Be Applied'),
			'#items' => array_slice($plan->problems, 0, 50),
			'#weight' => 30,
		];
	}

	/**
	 * Renders one value for a table cell.
	 *
	 * @param mixed $value
	 *   The value, or NULL when the key was absent on that side.
	 *
	 * @return string
	 *   A single-line rendering, truncated to keep the table readable.
	 */
	private function render(mixed $value): string
	{
		if ($value === null) {
			return '-';
		}

		$rendered = is_scalar($value)
			? (string) $value
			: (string) json_encode($value, JSON_UNESCAPED_SLASHES);
		$rendered = (string) preg_replace('/\s+/', ' ', $rendered);

		return strlen($rendered) > 120 ? substr($rendered, 0, 117) . '...' : $rendered;
	}

	#endregion

	/**
	 * The plan for this branch, built once.
	 *
	 * @return MergePlan|null
	 *   The plan, or NULL when no branch was named.
	 */
	private function plan(): ?MergePlan
	{
		if ($this->plan !== null) {
			return $this->plan;
		}
		if ($this->branch === '') {
			return null;
		}

		try {
			return $this->plan = $this->engine->merger()->plan($this->branch);
		} catch (Throwable) {
			return null;
		}
	}
}
