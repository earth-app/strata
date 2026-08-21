<?php

declare(strict_types=1);

namespace Drupal\strata\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\strata\Codec\CodecRegistry;
use Drupal\strata\Crypto\KeyProviderInterface;
use Drupal\strata\Estimate\PriceTable;
use Drupal\strata\Journal\JournalFactory;
use Throwable;

/**
 * Where history is written, how it is compressed, and how it is sealed.
 *
 * **What this form is careful about is telling the operator what the host can actually do.** A codec
 * this host has no extension and no binary for is listed as unavailable with the reason, rather than
 * offered and then silently falling back at the first flush; the same for the journal backend and for
 * the encryption key. A settings form that accepts a configuration the host cannot honour produces a
 * site that looks configured and is not.
 *
 * The flush interval is presented as what it is - a durability window, not a restore granularity.
 * Operations inside a segment stay individually addressable, so a longer interval does not coarsen
 * what a restore can target; it only decides how much is lost if the host dies before a flush.
 *
 * @see CodecRegistry
 * @see JournalFactory
 */
final class StorageSettingsForm extends SettingsFormBase
{
	/**
	 * {@inheritdoc}
	 */
	public function getFormId(): string
	{
		return 'strata_storage_settings';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function settingKeys(): array
	{
		return [
			'enabled' => 'bool',
			'provider' => 'string',
			'local_path' => 'string',
			'site_id' => 'string',
			'key' => 'string',
			'cipher.id' => 'string',
			'codec.id' => 'string',
			'codec.dictionary' => 'bool',
			'delta.enabled' => 'bool',
			'delta.max_depth' => 'int',
			'journal.backend' => 'string',
			'flush.max_age' => 'int',
			'flush.max_bytes' => 'int',
			'flush.max_ops' => 'int',
			'frame.size' => 'int',
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function buildForm(array $form, FormStateInterface $form_state): array
	{
		$form['enabled'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Capture is On'),
			'#description' => $this->t(
				'When this is off nothing is captured and nothing is flushed. Stored history is left alone.',
			),
			'#default_value' => (bool) $this->setting('enabled', false),
		];

		$this->addDestination($form);
		$this->addSealing($form);
		$this->addFlush($form);

		return parent::buildForm($form, $form_state);
	}

	/**
	 * {@inheritdoc}
	 */
	public function validateForm(array &$form, FormStateInterface $form_state): void
	{
		if (
			$form_state->getValue('cipher__id') !== 'none' &&
			trim((string) $form_state->getValue('key')) === ''
		) {
			$form_state->setErrorByName(
				'key',
				$this->t(
					'Encryption is on and no key is chosen. Choose one, or set the cipher to none.',
				),
			);
		}

		if ((int) $form_state->getValue('flush__max_age') < 1) {
			$form_state->setErrorByName(
				'flush__max_age',
				$this->t('The flush interval must be at least one second.'),
			);
		}

		parent::validateForm($form, $form_state);
	}

	#region Sections

	/**
	 * Where objects are written.
	 *
	 * @param array<string, mixed> $form
	 *   The form, added to by reference.
	 */
	private function addDestination(array &$form): void
	{
		$form['destination'] = [
			'#type' => 'details',
			'#title' => $this->t('Destination'),
			'#open' => true,
		];

		$form['destination']['provider'] = [
			'#type' => 'select',
			'#title' => $this->t('Storage Provider'),
			'#options' => $this->providerOptions(),
			'#default_value' => (string) $this->setting('provider', 'local'),
			'#description' => $this->t(
				'S3, any S3-compatible endpoint and Cloudflare R2 all need the Strata S3 submodule.',
			),
		];

		$form['destination']['local_path'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Local Directory'),
			'#default_value' => (string) $this->setting('local_path', 'private://strata'),
			'#description' => $this->t(
				'Used only by the local provider. A stream wrapper works here.',
			),
			'#states' => ['visible' => [':input[name="provider"]' => ['value' => 'local']]],
		];

		$form['destination']['site_id'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Site Identifier'),
			'#default_value' => (string) $this->setting('site_id', ''),
			'#description' => $this->t(
				'Changing this on a site with history starts a second history.',
			),
		];
	}

	/**
	 * How a frame is compressed and sealed.
	 *
	 * @param array<string, mixed> $form
	 *   The form, added to by reference.
	 */
	private function addSealing(array &$form): void
	{
		$form['sealing'] = [
			'#type' => 'details',
			'#title' => $this->t('Compression and Encryption'),
			'#open' => true,
		];

		$form['sealing']['codec__id'] = [
			'#type' => 'select',
			'#title' => $this->t('Compression Codec'),
			'#options' => $this->codecOptions(),
			'#default_value' => (string) $this->setting('codec.id', ''),
			'#description' => $this->t(
				'Run drush strata:calibrate to measure what this host achieves.',
			),
		];

		$form['sealing']['codec__dictionary'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Train and Use Dictionaries'),
			'#default_value' => (bool) $this->setting('codec.dictionary', true),
			'#description' => $this->t(
				'Trained on cron, and used during compaction, not on the flush path.',
			),
		];

		$form['sealing']['delta__enabled'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Delta Code Against the Previous Version'),
			'#default_value' => (bool) $this->setting('delta.enabled', true),
		];

		$form['sealing']['delta__max_depth'] = [
			'#type' => 'number',
			'#title' => $this->t('Delta Chain Cap'),
			'#min' => 1,
			'#max' => 1024,
			'#default_value' => (int) $this->setting('delta.max_depth', 32),
			'#description' => $this->t('A deeper chain stores less and takes longer to restore.'),
			'#states' => ['visible' => [':input[name="delta__enabled"]' => ['checked' => true]]],
		];

		$form['sealing']['cipher__id'] = [
			'#type' => 'select',
			'#title' => $this->t('Cipher'),
			'#options' => [
				'xchacha20poly1305' => $this->t('XChaCha20-Poly1305'),
				'none' => $this->t('None, Store Frames Unencrypted'),
			],
			'#default_value' => (string) $this->setting('cipher.id', 'xchacha20poly1305'),
		];

		$form['sealing']['retired_keys'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Retired Keys'),
			'#default_value' => implode(', ', $this->retiredKeys()),
			'#description' => $this->t(
				'Comma-separated. Keep a key here until strata:rotate-key reports the rotation done.',
			),
			'#states' => [
				'invisible' => [':input[name="cipher__id"]' => ['value' => 'none']],
			],
		];

		$form['sealing']['key'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Key Entity'),
			'#default_value' => (string) $this->setting('key', ''),
			'#description' => $this->t(
				'A Key module key holding @bytes bytes. Losing it makes every frame unreadable.',
				['@bytes' => KeyProviderInterface::KEY_BYTES],
			),
			'#states' => [
				'invisible' => [':input[name="cipher__id"]' => ['value' => 'none']],
			],
		];
	}

	/**
	 * When a window is sealed.
	 *
	 * @param array<string, mixed> $form
	 *   The form, added to by reference.
	 */
	private function addFlush(array &$form): void
	{
		$prices = PriceTable::of((string) $this->setting('provider', 'local'));

		$form['flush'] = [
			'#type' => 'details',
			'#title' => $this->t('Durability Window'),
			'#open' => false,
			'#description' => $this->t(
				'Whichever bound is reached first seals the window. At 15 seconds, requests cost @cost.',
				['@cost' => sprintf('$%.2f', $prices->writeCost(201_600))],
			),
		];

		$form['flush']['journal__backend'] = [
			'#type' => 'select',
			'#title' => $this->t('Journal Backend'),
			'#options' => $this->journalOptions(),
			'#default_value' => (string) $this->setting(
				'journal.backend',
				JournalFactory::DATABASE,
			),
		];

		$form['flush']['flush__max_age'] = [
			'#type' => 'number',
			'#title' => $this->t('Seconds'),
			'#min' => 1,
			'#default_value' => (int) $this->setting('flush.max_age', 15),
		];

		$form['flush']['flush__max_bytes'] = [
			'#type' => 'number',
			'#title' => $this->t('Buffered Bytes'),
			'#min' => 1024,
			'#default_value' => (int) $this->setting('flush.max_bytes', 4_194_304),
		];

		$form['flush']['flush__max_ops'] = [
			'#type' => 'number',
			'#title' => $this->t('Buffered Operations'),
			'#min' => 1,
			'#default_value' => (int) $this->setting('flush.max_ops', 5000),
		];

		$form['flush']['frame__size'] = [
			'#type' => 'select',
			'#title' => $this->t('Frame Size'),
			'#options' => [
				8192 => $this->t('8 KiB'),
				16384 => $this->t('16 KiB'),
				32768 => $this->t('32 KiB'),
			],
			'#default_value' => (int) $this->setting('frame.size', 16384),
			'#description' => $this->t('Existing frames keep the size they were written at.'),
		];
	}

	#endregion

	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state): void
	{
		$retired = array_values(
			array_filter(
				array_map('trim', explode(',', (string) $form_state->getValue('retired_keys'))),
				static fn(string $id): bool => $id !== '',
			),
		);

		$this->config(self::SETTINGS)->set('retired_keys', $retired)->save();

		parent::submitForm($form, $form_state);
	}

	/**
	 * The retired keys currently configured.
	 *
	 * @return list<string>
	 *   Key entity machine names.
	 */
	private function retiredKeys(): array
	{
		/** @var list<string> $retired */
		$retired = $this->setting('retired_keys', []) ?? [];

		return array_values(array_map('strval', $retired));
	}

	#region Options

	/**
	 * The providers this site can write to.
	 *
	 * @return array<string, string>
	 *   Provider id keyed to a label.
	 */
	private function providerOptions(): array
	{
		$options = [
			'local' => (string) $this->t('Local Directory'),
			'null' => (string) $this->t('None, Discard Everything'),
		];

		foreach ($this->engine?->providerIds() ?? [] as $id) {
			$options[$id] = $id;
		}

		return $options;
	}

	/**
	 * The codecs this host can actually run, and why the others cannot.
	 *
	 * An unavailable codec is listed with its reason rather than omitted, because an operator looking
	 * for zstd needs to be told the extension is missing, not left wondering whether this release
	 * supports it.
	 *
	 * @return array<string, string>
	 *   Codec id keyed to a label.
	 */
	private function codecOptions(): array
	{
		$options = ['' => (string) $this->t('Best Available')];

		try {
			$registry = CodecRegistry::withShippedCodecs();
		} catch (Throwable) {
			return $options;
		}

		foreach ($registry->available() as $id) {
			$options[$id] = $id;
		}

		foreach ($registry->unavailable() as $id => $reasons) {
			$options[$id] = (string) $this->t('@id (unavailable: @why)', [
				'@id' => $id,
				'@why' => implode('; ', $reasons),
			]);
		}

		return $options;
	}

	/**
	 * The journal backends this host can use.
	 *
	 * @return array<string, string>
	 *   Backend id keyed to a label.
	 */
	private function journalOptions(): array
	{
		return [
			JournalFactory::DATABASE => (string) $this->t('Database Table'),
			JournalFactory::REDIS => extension_loaded('redis')
				? (string) $this->t('Redis Stream')
				: (string) $this->t('Redis Stream (ext-redis is not loaded, the database is used)'),
		];
	}

	#endregion
}
