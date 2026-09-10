<?php

declare(strict_types=1);

namespace Drupal\strata_gcs\Form;

use Drupal\Core\Config\Config;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\strata\Engine;
use Drupal\strata\Storage\StorageProviderManager;
use Drupal\strata_gcs\GcsProviderFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

/**
 * The bucket and the credential.
 *
 * **A secret already stored is never rendered back into the page.** A service account key is a
 * private key, and a form that showed it would put that key into the HTML of an admin page, the
 * browser cache and any proxy between. The field is left blank with a note that one is set, and an
 * empty submission keeps what is there - so clearing it deliberately needs its own control.
 *
 * **A pre-issued token is the safer of the two.** Workload identity mints one for the pod and no
 * private key ever reaches the configuration, which is why the environment variable is read when
 * neither field is filled in.
 *
 * @see GcsProviderFactory
 */
final class GcsSettingsForm extends ConfigFormBase
{
	/**
	 * The configuration object this edits.
	 */
	public const SETTINGS = 'strata.settings';

	/**
	 * The provider id this form configures, which is not what the site necessarily writes to.
	 */
	private const PROVIDER = 'gcs';

	/**
	 * The engine, reset after a save so the next request uses the new endpoint.
	 */
	protected ?Engine $engine = null;

	/**
	 * The provider registry, which can probe one provider by id without building the store.
	 */
	protected ?StorageProviderManager $providers = null;

	/**
	 * {@inheritdoc}
	 */
	public static function create(ContainerInterface $container): static
	{
		$form = parent::create($container);
		$form->engine = $container->has('strata.engine') ? $container->get('strata.engine') : null;
		$form->providers = $container->has('strata.storage_providers')
			? $container->get('strata.storage_providers')
			: null;

		return $form;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getFormId(): string
	{
		return 'strata_gcs_settings';
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
		$form['bucket'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Bucket'),
			'#default_value' => (string) $this->setting('bucket'),
			'#description' => $this->t('Everything is written under the _strata prefix inside it.'),
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
		$bucket = trim((string) $form_state->getValue('bucket'));

		if ($bucket !== '' && preg_match('/^[a-z0-9][a-z0-9._-]{1,220}[a-z0-9]$/', $bucket) !== 1) {
			$form_state->setErrorByName(
				'bucket',
				$this->t(
					'A bucket name is lowercase characters, digits, dots, dashes or underscores.',
				),
			);
		}

		$account = trim((string) $form_state->getValue('service_account'));

		if ($account !== '' && !$this->isServiceAccount($account)) {
			$form_state->setErrorByName(
				'service_account',
				$this->t('A service account key is json carrying client_email and private_key.'),
			);
		}

		$api = trim((string) $form_state->getValue('api_url'));

		if ($api !== '' && parse_url($api, PHP_URL_HOST) === null) {
			$form_state->setErrorByName(
				'api_url',
				$this->t('An api url has to name a host. Leave it empty for the public api.'),
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
			->set('gcs.bucket', trim((string) $form_state->getValue('bucket')))
			->set('gcs.api_url', trim((string) $form_state->getValue('api_url')))
			->set('gcs.storage_class', trim((string) $form_state->getValue('storage_class')))
			->set('gcs.resumable_threshold', (int) $form_state->getValue('resumable_threshold'));

		$this->store($config, $form_state, 'gcs.service_account', 'service_account', 'clear_key');
		$this->store($config, $form_state, 'gcs.access_token', 'access_token', 'clear_token');

		$config->save();
		$this->engine?->reset();

		parent::submitForm($form, $form_state);
	}

	#region Sections

	/**
	 * Where the bearer token comes from.
	 *
	 * @param array<string, mixed> $form
	 *   The form, added to by reference.
	 */
	private function addCredentials(array &$form): void
	{
		$hasKey = (string) $this->setting('service_account') !== '';
		$hasToken = (string) $this->setting('access_token') !== '';

		$form['credentials'] = [
			'#type' => 'details',
			'#title' => $this->t('Credentials'),
			'#open' => true,
			'#description' => $this->t('Leave both empty to read GOOGLE_ACCESS_TOKEN instead.'),
		];

		$form['credentials']['service_account'] = [
			'#type' => 'textarea',
			'#title' => $this->t('Service Account Key'),
			'#rows' => 4,
			'#default_value' => '',
			'#description' => $hasKey
				? $this->t('A key is stored. Leave empty to keep it.')
				: $this->t('The whole json file. It holds a private key, so treat it as one.'),
		];

		$form['credentials']['clear_key'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Remove the Stored Service Account Key'),
			'#default_value' => false,
			'#access' => $hasKey,
		];

		$form['credentials']['access_token'] = [
			'#type' => 'password',
			'#title' => $this->t('Access Token'),
			'#description' => $hasToken
				? $this->t('A token is stored. Leave empty to keep it.')
				: $this->t('An OAuth2 token. Nothing here refreshes it when it expires.'),
		];

		$form['credentials']['clear_token'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Remove the Stored Access Token'),
			'#default_value' => false,
			'#access' => $hasToken,
		];
	}

	/**
	 * The switches that make a non-default endpoint work.
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

		$form['compatibility']['api_url'] = [
			'#type' => 'textfield',
			'#title' => $this->t('API URL'),
			'#default_value' => (string) $this->setting('api_url'),
			'#description' => $this->t(
				'Empty uses the public api. A private endpoint overrides it.',
			),
		];

		$form['compatibility']['storage_class'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Storage Class'),
			'#default_value' => (string) $this->setting('storage_class'),
			'#description' => $this->t(
				'Empty uses the bucket default. Nearline, Coldline or Archive.',
			),
		];

		$form['compatibility']['resumable_threshold'] = [
			'#type' => 'number',
			'#title' => $this->t('Resumable Threshold, Bytes'),
			'#min' => 0,
			'#default_value' => (int) $this->setting('resumable_threshold'),
			'#description' => $this->t(
				'Zero uses the endpoint maximum. A lower value buys retry granularity per chunk.',
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
	 * Whether a pasted value is a service account key.
	 *
	 * @param string $value
	 *   The submitted text.
	 *
	 * @return bool
	 *   TRUE when it parses as json carrying both fields a service account needs.
	 */
	private function isServiceAccount(string $value): bool
	{
		$parsed = json_decode($value, true);

		if (!is_array($parsed)) {
			return false;
		}

		return trim((string) ($parsed['client_email'] ?? '')) !== '' &&
			trim((string) ($parsed['private_key'] ?? '')) !== '';
	}

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

		$value = trim((string) $submitted->getValue($field));

		if ($value !== '') {
			$config->set($key, $value);
		}
	}

	/**
	 * A sentence saying whether the provider this form configures can be reached.
	 *
	 * Probed by id through the provider manager rather than through `Engine::provider()`, which
	 * resolves whatever `strata.settings` names. A fresh install names `local`, so this page used to
	 * answer "local answered." on the form for a bucket nobody had reached yet.
	 *
	 * Reported rather than thrown: a form that fataled on an unreachable bucket could never be used
	 * to fix the bucket.
	 *
	 * @return string
	 *   What happened when the provider was asked.
	 */
	private function reachability(): string
	{
		if ($this->providers === null) {
			return (string) $this->t('Strata is not registered here, so nothing could be checked.');
		}

		$reason = $this->providers->reachability(self::PROVIDER);

		return $reason === null
			? (string) $this->t('@id answered.', ['@id' => self::PROVIDER])
			: (string) $this->t('@id did not answer: @why', [
				'@id' => self::PROVIDER,
				'@why' => $reason,
			]);
	}

	/**
	 * One GCS setting.
	 *
	 * @param string $key
	 *   The key inside the `gcs` mapping.
	 *
	 * @return mixed
	 *   The configured value.
	 */
	private function setting(string $key): mixed
	{
		return $this->config(self::SETTINGS)->get('gcs.' . $key);
	}
}
