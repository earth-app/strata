<?php

declare(strict_types=1);

namespace Drupal\strata_b2\Form;

use Drupal\Core\Config\Config;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\strata\Engine;
use Drupal\strata\Storage\StorageProviderManager;
use Drupal\strata_b2\B2Account;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

/**
 * The bucket, named both ways, and the application key.
 *
 * **A secret already stored is never rendered back into the page.** A form that showed it would put
 * the credential into the HTML of an admin page, the browser cache and any proxy between. The field
 * is left blank with a note that one is set, and an empty submission keeps what is there - so
 * clearing a secret deliberately needs its own control.
 *
 * **An application key scoped to this bucket is the reason to use the native API.** The S3-compatible
 * gateway cannot authenticate a key restricted to a bucket and a name prefix, so a site using the
 * gateway has to hand Drupal a key that can read every bucket in the account. This one does not.
 *
 * @see B2Account
 */
final class B2SettingsForm extends ConfigFormBase
{
	/**
	 * The configuration object this edits.
	 */
	public const SETTINGS = 'strata.settings';

	/**
	 * The provider id this form configures, which is not what the site necessarily writes to.
	 */
	private const PROVIDER = 'b2';

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
		return 'strata_b2_settings';
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
		$form['bucket_name'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Bucket Name'),
			'#default_value' => (string) $this->setting('bucket_name'),
			'#description' => $this->t('Reads address the bucket by name.'),
		];

		$form['bucket_id'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Bucket ID'),
			'#default_value' => (string) $this->setting('bucket_id'),
			'#description' => $this->t('Writes address the bucket by id. The console shows both.'),
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
		$name = trim((string) $form_state->getValue('bucket_name'));

		if ($name !== '' && preg_match('/^[a-z0-9][a-z0-9-]{4,48}[a-z0-9]$/', $name) !== 1) {
			$form_state->setErrorByName(
				'bucket_name',
				$this->t('A bucket name is 6 to 50 lowercase characters, digits or dashes.'),
			);
		}

		$id = trim((string) $form_state->getValue('key_id'));
		$key = (string) $form_state->getValue('application_key');

		// half a key authenticates nothing and b2 has no fallback to reach for
		if ($id !== '' && $key === '' && (string) $this->setting('application_key') === '') {
			$form_state->setErrorByName(
				'application_key',
				$this->t('A key id needs its application key.'),
			);
		}

		$api = trim((string) $form_state->getValue('api_url'));

		if ($api !== '' && parse_url($api, PHP_URL_HOST) === null) {
			$form_state->setErrorByName(
				'api_url',
				$this->t('An authorization url has to name a host. Leave it empty for Backblaze.'),
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
			->set('b2.bucket_id', trim((string) $form_state->getValue('bucket_id')))
			->set('b2.bucket_name', trim((string) $form_state->getValue('bucket_name')))
			->set('b2.key_id', trim((string) $form_state->getValue('key_id')))
			->set('b2.api_url', trim((string) $form_state->getValue('api_url')))
			->set('b2.large_file_threshold', (int) $form_state->getValue('large_file_threshold'));

		$this->store($config, $form_state, 'b2.application_key', 'application_key', 'clear_key');

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
		$stored = (string) $this->setting('application_key') !== '';

		$form['credentials'] = [
			'#type' => 'details',
			'#title' => $this->t('Credentials'),
			'#open' => true,
			'#description' => $this->t('An application key scoped to this bucket is enough.'),
		];

		$form['credentials']['key_id'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Key ID'),
			'#default_value' => (string) $this->setting('key_id'),
		];

		$form['credentials']['application_key'] = [
			'#type' => 'password',
			'#title' => $this->t('Application Key'),
			'#description' => $stored
				? $this->t('A key is stored. Leave empty to keep it.')
				: $this->t('Stored in configuration, which a configuration export includes.'),
		];

		$form['credentials']['clear_key'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Remove the Stored Application Key'),
			'#default_value' => false,
			'#access' => $stored,
		];

		$form['credentials']['needed'] = [
			'#type' => 'item',
			'#title' => $this->t('Capabilities the Key Needs'),
			'#markup' => $this->t('listFiles, readFiles, writeFiles and deleteFiles.'),
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
			'#title' => $this->t('Authorization URL'),
			'#default_value' => (string) $this->setting('api_url'),
			'#description' => $this->t('Empty asks Backblaze. The account is told the rest.'),
		];

		$form['compatibility']['large_file_threshold'] = [
			'#type' => 'number',
			'#title' => $this->t('Large File Threshold, Bytes'),
			'#min' => 0,
			'#default_value' => (int) $this->setting('large_file_threshold'),
			'#description' => $this->t(
				'Zero uses the endpoint maximum. A lower value buys retry granularity per part.',
			),
		];
	}

	/**
	 * Whether the configured account answers right now.
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
	 * One B2 setting.
	 *
	 * @param string $key
	 *   The key inside the `b2` mapping.
	 *
	 * @return mixed
	 *   The configured value.
	 */
	private function setting(string $key): mixed
	{
		return $this->config(self::SETTINGS)->get('b2.' . $key);
	}
}
