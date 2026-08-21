<?php

declare(strict_types=1);

namespace Drupal\strata_azure\Form;

use Drupal\Core\Config\Config;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\strata\Engine;
use Drupal\strata_azure\AzureEndpoint;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

/**
 * The account, the container and the credential.
 *
 * **A secret already stored is never rendered back into the page.** A form that showed it would put
 * the credential into the HTML of an admin page, the browser cache and any proxy between. The field
 * is left blank with a note that one is set, and an empty submission keeps what is there - so
 * clearing a secret deliberately needs its own control.
 *
 * **A SAS token is the safer of the two credentials.** An account key grants everything the account
 * can do, in every container, for as long as it exists. A SAS token is scoped and expires, and the
 * cost of that is that somebody has to mint a new one before it does. The form says so at the point
 * of decision rather than in documentation nobody reads.
 *
 * @see AzureEndpoint
 */
final class AzureSettingsForm extends ConfigFormBase
{
	/**
	 * The configuration object this edits.
	 */
	public const SETTINGS = 'strata.settings';

	/**
	 * The engine, reset after a save so the next request uses the new endpoint.
	 */
	protected ?Engine $engine = null;

	/**
	 * {@inheritdoc}
	 */
	public static function create(ContainerInterface $container): static
	{
		$form = parent::create($container);
		$form->engine = $container->get('strata.engine');

		return $form;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getFormId(): string
	{
		return 'strata_azure_settings';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return list<string>
	 *   The configuration this form edits.
	 */
	protected function getEditableConfigNames(): array
	{
		return [self::SETTINGS];
	}

	/**
	 * {@inheritdoc}
	 */
	public function buildForm(array $form, FormStateInterface $form_state): array
	{
		$form['account'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Storage Account'),
			'#default_value' => (string) $this->setting('account'),
			'#description' => $this->t('The account name, not the full endpoint host.'),
		];

		$form['container'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Container'),
			'#default_value' => (string) $this->setting('container'),
			'#description' => $this->t('Strata never creates it, so the container has to exist.'),
		];

		$this->addCredentials($form);
		$this->addCompatibility($form);
		$this->addReachability($form);

		return parent::buildForm($form, $form_state);
	}

	/**
	 * {@inheritdoc}
	 */
	public function validateForm(array &$form, FormStateInterface $form_state): void
	{
		$account = trim((string) $form_state->getValue('account'));

		if ($account !== '' && preg_match('/^[a-z0-9]{3,24}$/', $account) !== 1) {
			$form_state->setErrorByName(
				'account',
				$this->t('An account name is 3 to 24 lowercase letters and digits.'),
			);
		}

		$container = trim((string) $form_state->getValue('container'));

		if (
			$container !== '' &&
			preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $container) !== 1
		) {
			$form_state->setErrorByName(
				'container',
				$this->t('A container name is 3 to 63 lowercase characters, digits or dashes.'),
			);
		}

		$key = (string) $form_state->getValue('account_key');

		if ($key !== '' && base64_decode($key, true) === false) {
			$form_state->setErrorByName(
				'account_key',
				$this->t('An account key is base64, exactly as the portal prints it.'),
			);
		}

		$service = trim((string) $form_state->getValue('service_url'));

		if ($service !== '' && parse_url($service, PHP_URL_HOST) === null) {
			$form_state->setErrorByName(
				'service_url',
				$this->t('A service url has to name a host, such as http://127.0.0.1:10000.'),
			);
		}

		parent::validateForm($form, $form_state);
	}

	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state): void
	{
		$config = $this->config(self::SETTINGS);

		$config
			->set('azure.account', trim((string) $form_state->getValue('account')))
			->set('azure.container', trim((string) $form_state->getValue('container')))
			->set('azure.endpoint_suffix', trim((string) $form_state->getValue('endpoint_suffix')))
			->set('azure.service_url', trim((string) $form_state->getValue('service_url')))
			->set('azure.access_tier', trim((string) $form_state->getValue('access_tier')))
			->set('azure.block_threshold', (int) $form_state->getValue('block_threshold'));

		$this->store($config, $form_state, 'azure.account_key', 'account_key', 'clear_account_key');
		$this->store($config, $form_state, 'azure.sas_token', 'sas_token', 'clear_sas_token');

		$config->save();
		$this->engine?->reset();

		parent::submitForm($form, $form_state);
	}

	#region Sections

	/**
	 * How the account is authenticated.
	 *
	 * @param array<string, mixed> $form
	 *   The form, added to by reference.
	 */
	private function addCredentials(array &$form): void
	{
		$hasKey = (string) $this->setting('account_key') !== '';
		$hasSas = (string) $this->setting('sas_token') !== '';

		$form['credentials'] = [
			'#type' => 'details',
			'#title' => $this->t('Credentials'),
			'#open' => true,
			'#description' => $this->t('Set one of these. A sas token is scoped and expires.'),
		];

		$form['credentials']['account_key'] = [
			'#type' => 'password',
			'#title' => $this->t('Account Key'),
			'#description' => $hasKey
				? $this->t('A key is stored. Leave empty to keep it.')
				: $this->t('Grants everything the account can do, in every container.'),
		];

		$form['credentials']['clear_account_key'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Remove the Stored Account Key'),
			'#default_value' => false,
			'#access' => $hasKey,
		];

		$form['credentials']['sas_token'] = [
			'#type' => 'password',
			'#title' => $this->t('SAS Token'),
			'#description' => $hasSas
				? $this->t('A token is stored. Leave empty to keep it.')
				: $this->t('The query string, with or without its leading question mark.'),
		];

		$form['credentials']['clear_sas_token'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Remove the Stored SAS Token'),
			'#default_value' => false,
			'#access' => $hasSas,
		];
	}

	/**
	 * The switches that make a non-public endpoint work.
	 *
	 * @param array<string, mixed> $form
	 *   The form, added to by reference.
	 */
	private function addCompatibility(array &$form): void
	{
		$form['compatibility'] = [
			'#type' => 'details',
			'#title' => $this->t('Endpoint Compatibility'),
			'#open' => false,
		];

		$form['compatibility']['endpoint_suffix'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Endpoint Suffix'),
			'#default_value' =>
				(string) ($this->setting('endpoint_suffix') ?: AzureEndpoint::PUBLIC_SUFFIX),
			'#description' => $this->t('Sovereign clouds answer on their own dns suffix.'),
		];

		$form['compatibility']['service_url'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Service URL'),
			'#default_value' => (string) $this->setting('service_url'),
			'#description' => $this->t(
				'Overrides the suffix. Azurite puts the account in the path.',
			),
		];

		$form['compatibility']['access_tier'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Access Tier'),
			'#default_value' => (string) $this->setting('access_tier'),
			'#description' => $this->t(
				'Empty uses the container default. Hot, Cool, Cold or Archive.',
			),
		];

		$form['compatibility']['block_threshold'] = [
			'#type' => 'number',
			'#title' => $this->t('Block Threshold, Bytes'),
			'#min' => 0,
			'#default_value' => (int) $this->setting('block_threshold'),
			'#description' => $this->t(
				'Zero uses the endpoint maximum. A lower value buys retry granularity per block.',
			),
		];
	}

	/**
	 * Whether the configured endpoint answers right now.
	 *
	 * @param array<string, mixed> $form
	 *   The form, added to by reference.
	 */
	private function addReachability(array &$form): void
	{
		$form['reachable'] = [
			'#type' => 'item',
			'#title' => $this->t('Reachability'),
			'#markup' => $this->reachability(),
			'#weight' => 100,
		];
	}

	#endregion

	/**
	 * Saves a secret, keeping what is stored when the field came back empty.
	 *
	 * @param Config $config
	 *   The configuration being written.
	 * @param FormStateInterface $submitted
	 *   The submitted values.
	 * @param string $key
	 *   The configuration key the secret lives at.
	 * @param string $field
	 *   The form field the new value arrives in.
	 * @param string $clear
	 *   The checkbox that empties the stored value.
	 */
	private function store(
		Config $config,
		FormStateInterface $submitted,
		string $key,
		string $field,
		string $clear,
	): void {
		if ((bool) $submitted->getValue($clear)) {
			$config->set($key, '');

			return;
		}

		$value = (string) $submitted->getValue($field);

		if ($value !== '') {
			$config->set($key, $value);
		}
	}

	/**
	 * A sentence saying whether the provider can be reached.
	 *
	 * Reported rather than thrown: a form that fataled on an unreachable container could never be
	 * used to fix the container.
	 *
	 * @return string
	 *   What happened when the provider was asked.
	 */
	private function reachability(): string
	{
		if ($this->engine === null) {
			return (string) $this->t('The engine is unavailable, so nothing could be checked.');
		}

		try {
			$provider = $this->engine->provider();
		} catch (Throwable $error) {
			return (string) $this->t('Not configured yet: @why', ['@why' => $error->getMessage()]);
		}

		if ($provider->isReachable()) {
			return (string) $this->t('@id answered.', ['@id' => $provider->id()]);
		}

		return (string) $this->t('@id did not answer: @why', [
			'@id' => $provider->id(),
			'@why' => (string) ($provider->unreachableReason() ?? $this->t('no reason given')),
		]);
	}

	/**
	 * One Azure setting.
	 *
	 * @param string $key
	 *   The key inside the `azure` mapping.
	 *
	 * @return mixed
	 *   The configured value.
	 */
	private function setting(string $key): mixed
	{
		return $this->config(self::SETTINGS)->get('azure.' . $key);
	}
}
