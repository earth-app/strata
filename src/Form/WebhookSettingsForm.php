<?php

declare(strict_types=1);

namespace Drupal\strata\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\strata\Event\StrataEvents;
use Drupal\strata\Event\Webhook\WebhookDispatcher;
use Drupal\strata\Event\Webhook\WebhookSignature;
use Drupal\strata\Event\Webhook\WebhookSubscription;

/**
 * The endpoints that are told when something happens.
 *
 * **A secret already stored is never rendered back into the form.** A configuration form that showed
 * the current value would put every subscription's signing secret into the HTML of a page, into the
 * browser's cache and into any proxy in between. So the field is left blank with a note that it is
 * set, and an empty submission keeps what is there rather than clearing it - which means clearing a
 * secret deliberately needs its own control.
 *
 * The event list comes from `StrataEvents::all()`, so an event added in a later release appears here
 * without this form being edited.
 *
 * @see WebhookSubscription
 * @see WebhookSignature
 */
final class WebhookSettingsForm extends ConfigFormBase
{
	/**
	 * Subscriptions the form renders.
	 *
	 * Fixed rather than unlimited, because a site needing more than this is integrating through
	 * something other than a settings form.
	 */
	public const SLOTS = 5;

	/**
	 * {@inheritdoc}
	 */
	public function getFormId(): string
	{
		return 'strata_webhook_settings';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return list<string>
	 *   The configuration this form edits.
	 */
	protected function getEditableConfigNames(): array
	{
		return [WebhookDispatcher::CONFIG];
	}

	/**
	 * {@inheritdoc}
	 */
	public function buildForm(array $form, FormStateInterface $form_state): array
	{
		$existing = $this->subscriptions();

		$form['help'] = [
			'#type' => 'item',
			'#markup' => $this->t(
				'Posted as JSON. Headers: @event, @delivery, and @signature when a secret is set.',
				[
					'@event' => WebhookSignature::EVENT_HEADER,
					'@delivery' => WebhookSignature::DELIVERY_HEADER,
					'@signature' => WebhookSignature::HEADER,
				],
			),
			'#description' => $this->t(
				'A receiver must check the timestamp as well as the digest.',
			),
		];

		for ($slot = 0; $slot < self::SLOTS; $slot++) {
			$subscription = $this->at($existing, $slot);

			$form[$this->slot($slot)] = [
				'#type' => 'details',
				'#title' =>
					$subscription === null
						? $this->t('Endpoint @n', ['@n' => $slot + 1])
						: $subscription->label(),
				'#open' => $subscription !== null,
				'#tree' => true,
			];

			$form[$this->slot($slot)]['url'] = [
				'#type' => 'url',
				'#title' => $this->t('URL'),
				'#default_value' => $subscription->url ?? '',
				'#description' => $this->t('Leave empty to remove this endpoint.'),
			];

			$form[$this->slot($slot)]['enabled'] = [
				'#type' => 'checkbox',
				'#title' => $this->t('Enabled'),
				'#default_value' => $subscription->enabled ?? true,
			];

			$form[$this->slot($slot)]['events'] = [
				'#type' => 'checkboxes',
				'#title' => $this->t('Events'),
				'#options' => $this->eventOptions(),
				'#default_value' => $subscription->events ?? [],
				'#description' => $this->t('Ticking none sends every event.'),
			];

			$form[$this->slot($slot)]['secret'] = [
				'#type' => 'password',
				'#title' => $this->t('Signing Secret'),
				'#description' =>
					$subscription !== null && $subscription->isSigned()
						? $this->t('A secret is set. Leave empty to keep it.')
						: $this->t('Leave empty to send unsigned payloads.'),
			];

			$form[$this->slot($slot)]['clear_secret'] = [
				'#type' => 'checkbox',
				'#title' => $this->t('Remove the Stored Secret'),
				'#default_value' => false,
				'#access' => $subscription !== null && $subscription->isSigned(),
			];

			$form[$this->slot($slot)]['timeout'] = [
				'#type' => 'number',
				'#title' => $this->t('Timeout, Seconds'),
				'#min' => 1,
				'#max' => 120,
				'#default_value' => $subscription->timeout ?? WebhookSubscription::DEFAULT_TIMEOUT,
			];

			$form[$this->slot($slot)]['attempts'] = [
				'#type' => 'number',
				'#title' => $this->t('Attempts'),
				'#min' => 1,
				'#max' => 10,
				'#default_value' =>
					$subscription->attempts ?? WebhookSubscription::DEFAULT_ATTEMPTS,
			];
		}

		return parent::buildForm($form, $form_state);
	}

	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state): void
	{
		$existing = $this->subscriptions();
		$subscriptions = [];

		for ($slot = 0; $slot < self::SLOTS; $slot++) {
			/** @var array<string, mixed> $values */
			$values = $form_state->getValue($this->slot($slot)) ?? [];
			$url = trim((string) ($values['url'] ?? ''));

			if ($url === '') {
				continue;
			}

			$subscriptions[] = [
				'url' => $url,
				'secret' => $this->secretFor($values, $this->at($existing, $slot)),
				'events' => $this->chosenEvents($values),
				'enabled' => (bool) ($values['enabled'] ?? true),
				'timeout' => max(
					1,
					(int) ($values['timeout'] ?? WebhookSubscription::DEFAULT_TIMEOUT),
				),
				'attempts' => max(
					1,
					(int) ($values['attempts'] ?? WebhookSubscription::DEFAULT_ATTEMPTS),
				),
			];
		}

		$this->config(WebhookDispatcher::CONFIG)->set('subscriptions', $subscriptions)->save();

		parent::submitForm($form, $form_state);
	}

	/**
	 * The secret to store for one slot.
	 *
	 * @param array<string, mixed> $values
	 *   The submitted slot.
	 * @param WebhookSubscription|null $existing
	 *   What was stored before, or NULL for a new endpoint.
	 *
	 * @return string
	 *   The secret to store, which may be what was already there.
	 */
	private function secretFor(array $values, ?WebhookSubscription $existing): string
	{
		if (($values['clear_secret'] ?? false) === true) {
			return '';
		}

		$submitted = (string) ($values['secret'] ?? '');

		return $submitted !== '' ? $submitted : $existing->secret ?? '';
	}

	/**
	 * The events one slot ticked.
	 *
	 * @param array<string, mixed> $values
	 *   The submitted slot.
	 *
	 * @return list<string>
	 *   Event names, empty for every event.
	 */
	private function chosenEvents(array $values): array
	{
		/** @var array<string, mixed> $ticked */
		$ticked = is_array($values['events'] ?? null) ? $values['events'] : [];

		return array_values(
			array_filter(
				array_map('strval', array_keys(array_filter($ticked))),
				static fn(string $name): bool => StrataEvents::has($name),
			),
		);
	}

	/**
	 * Every event, labelled.
	 *
	 * @return array<string, string>
	 *   Event name keyed to a label.
	 */
	private function eventOptions(): array
	{
		$options = [];

		foreach (StrataEvents::all() as $name) {
			$options[$name] = $name;
		}

		return $options;
	}

	/**
	 * The form element name for one slot.
	 *
	 * @param int $slot
	 *   The slot index.
	 *
	 * @return string
	 *   The element name.
	 */
	private function slot(int $slot): string
	{
		return 'endpoint_' . $slot;
	}

	/**
	 * The subscription filling one slot, if there is one.
	 *
	 * A slot past the end of the stored list is empty, which is the normal case: the form always
	 * renders every slot and a site usually configures fewer.
	 *
	 * @param list<WebhookSubscription> $existing
	 *   What is stored.
	 * @param int $slot
	 *   The slot index.
	 *
	 * @return WebhookSubscription|null
	 *   The subscription, or NULL when the slot is empty.
	 */
	private function at(array $existing, int $slot): ?WebhookSubscription
	{
		return $existing[$slot] ?? null;
	}

	/**
	 * The subscriptions currently stored.
	 *
	 * @return list<WebhookSubscription>
	 *   The subscriptions.
	 */
	private function subscriptions(): array
	{
		/** @var list<array<string, mixed>>|null $sequence */
		$sequence = $this->config(WebhookDispatcher::CONFIG)->get('subscriptions');

		return WebhookSubscription::fromSequence($sequence);
	}
}
