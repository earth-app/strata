<?php

declare(strict_types=1);

namespace Drupal\strata_s3\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\strata\Engine;
use Drupal\strata_s3\S3Endpoint;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

/**
 * The bucket, the endpoint and the credentials.
 *
 * **A secret already stored is never rendered back into the page.** A form that showed it would put
 * the credential into the HTML of an admin page, the browser cache and any proxy between. The field
 * is left blank with a note that one is set, and an empty submission keeps what is there - so
 * clearing a secret deliberately needs its own control.
 *
 * **Credentials in configuration are the fallback, not the recommendation.** Leaving both fields
 * empty uses the credential chain - environment variables, then `~/.aws/credentials`, then an IMDSv2
 * instance profile - which keeps the secret out of the config export entirely. The form says so at
 * the point of decision rather than in documentation nobody reads.
 *
 * **Checksums default to off because sending them broke real endpoints.** The AWS SDKs began sending
 * mandatory CRC32 headers in early 2025 and R2, MinIO, Backblaze B2 and Dell ECS all rejected them.
 * The switch exists so a site whose endpoint wants them can have them, and the default is what works
 * on the endpoints people actually use.
 *
 * @see S3Endpoint
 */
final class S3SettingsForm extends ConfigFormBase
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
		return 'strata_s3_settings';
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

		$form['endpoint'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Endpoint URL'),
			'#default_value' => (string) $this->setting('endpoint'),
			'#description' => $this->t('Leave empty for AWS S3. For R2, the account endpoint URL.'),
		];

		$form['region'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Region'),
			'#default_value' => (string) ($this->setting('region') ?? 'us-east-1'),
			'#description' => $this->t('R2 ignores this and wants @auto.', [
				'@auto' => S3Endpoint::R2_REGION,
			]),
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

		if ($bucket !== '' && preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket) !== 1) {
			$form_state->setErrorByName(
				'bucket',
				$this->t('A bucket name is 3 to 63 lowercase characters, digits, dots or dashes.'),
			);
		}

		$id = trim((string) $form_state->getValue('access_key_id'));
		$secret = (string) $form_state->getValue('secret_access_key');

		// half a credential pair authenticates nothing and is worse than none, which has a fallback
		if ($id !== '' && $secret === '' && (string) $this->setting('secret_access_key') === '') {
			$form_state->setErrorByName(
				'secret_access_key',
				$this->t('An access key id needs its secret. Leave both empty to use the chain.'),
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
			->set('s3.bucket', trim((string) $form_state->getValue('bucket')))
			->set('s3.endpoint', trim((string) $form_state->getValue('endpoint')))
			->set('s3.region', trim((string) $form_state->getValue('region')))
			->set('s3.access_key_id', trim((string) $form_state->getValue('access_key_id')))
			->set('s3.path_style', (bool) $form_state->getValue('path_style'))
			->set('s3.send_checksums', (bool) $form_state->getValue('send_checksums'))
			->set('s3.storage_class', trim((string) $form_state->getValue('storage_class')))
			->set('s3.multipart_threshold', (int) $form_state->getValue('multipart_threshold'));

		$secret = (string) $form_state->getValue('secret_access_key');

		if ((bool) $form_state->getValue('clear_secret')) {
			$config->set('s3.secret_access_key', '');
		} elseif ($secret !== '') {
			$config->set('s3.secret_access_key', $secret);
		}

		$config->save();
		$this->engine?->reset();

		parent::submitForm($form, $form_state);
	}

	#region Sections

	/**
	 * How the endpoint is authenticated.
	 *
	 * @param array<string, mixed> $form
	 *   The form, added to by reference.
	 */
	private function addCredentials(array &$form): void
	{
		$stored = (string) $this->setting('secret_access_key') !== '';

		$form['credentials'] = [
			'#type' => 'details',
			'#title' => $this->t('Credentials'),
			'#open' => true,
			'#description' => $this->t('Leave both empty to use the environment credential chain.'),
		];

		$form['credentials']['access_key_id'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Access Key ID'),
			'#default_value' => (string) $this->setting('access_key_id'),
		];

		$form['credentials']['secret_access_key'] = [
			'#type' => 'password',
			'#title' => $this->t('Secret Access Key'),
			'#description' => $stored
				? $this->t('A secret is stored. Leave empty to keep it.')
				: $this->t('Stored in configuration, which a configuration export includes.'),
		];

		$form['credentials']['clear_secret'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Remove the Stored Secret'),
			'#default_value' => false,
			'#access' => $stored,
		];

		$form['credentials']['chain'] = [
			'#type' => 'item',
			'#title' => $this->t('The Chain, in Order'),
			'#markup' => $this->t(
				'Environment variables, then an AWS profile file, then an IMDSv2 instance profile.',
			),
		];
	}

	/**
	 * The switches that make a non-AWS endpoint work.
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

		$form['compatibility']['path_style'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Address the Bucket in the Path'),
			'#default_value' => (bool) $this->setting('path_style'),
			'#description' => $this->t('MinIO, Ceph and most self-hosted endpoints need this on.'),
		];

		$form['compatibility']['send_checksums'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Send Checksum Headers'),
			'#default_value' => (bool) $this->setting('send_checksums'),
			'#description' => $this->t(
				'Off by default: R2, MinIO and B2 rejected the CRC32 family.',
			),
		];

		$form['compatibility']['storage_class'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Storage Class'),
			'#default_value' => (string) $this->setting('storage_class'),
			'#description' => $this->t(
				'Empty uses the endpoint default. Files have their own class.',
			),
		];

		$form['compatibility']['multipart_threshold'] = [
			'#type' => 'number',
			'#title' => $this->t('Multipart Threshold, Bytes'),
			'#min' => 0,
			'#default_value' => (int) $this->setting('multipart_threshold'),
			'#description' => $this->t(
				'Zero uses the endpoint maximum. A lower value buys retry granularity per part.',
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
	 * A sentence saying whether the provider can be reached.
	 *
	 * Reported rather than thrown: a form that fataled on an unreachable bucket could never be used
	 * to fix the bucket.
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
	 * One S3 setting.
	 *
	 * @param string $key
	 *   The key inside the `s3` mapping.
	 *
	 * @return mixed
	 *   The configured value.
	 */
	private function setting(string $key): mixed
	{
		return $this->config(self::SETTINGS)->get('s3.' . $key);
	}
}
