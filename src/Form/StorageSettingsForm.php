<?php

declare(strict_types=1);

namespace Drupal\strata\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\key\KeyRepositoryInterface;
use Drupal\strata\Codec\CodecRegistry;
use Drupal\strata\Crypto\KeyMaker;
use Drupal\strata\Crypto\KeyProviderInterface;
use Drupal\strata\Crypto\StaticKeyProvider;
use Drupal\strata\Estimate\PriceTable;
use Drupal\strata\Journal\JournalFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
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
	 * The key module's repository, for checking a chosen key before the first flush needs it.
	 */
	protected ?KeyRepositoryInterface $keys = null;

	/**
	 * Makes a key entity, for the operator who has not got one yet.
	 */
	protected ?KeyMaker $keyMaker = null;

	/**
	 * {@inheritdoc}
	 */
	public static function create(ContainerInterface $container): static
	{
		$form = parent::create($container);
		$form->keys = $container->has('key.repository') ? $container->get('key.repository') : null;
		$form->keyMaker = $container->has('strata.key_maker')
			? $container->get('strata.key_maker')
			: null;

		return $form;
	}

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
		$key = trim((string) $form_state->getValue('key'));

		if ($form_state->getValue('cipher__id') !== 'none' && $key === '') {
			$form_state->setErrorByName(
				'key',
				$this->t(
					'Encryption is on and no key is chosen. Choose one, or set the cipher to none.',
				),
			);
		} elseif ($form_state->getValue('cipher__id') !== 'none') {
			$this->validateKey($form_state, $key);
		}

		if ($form_state->getValue('provider') === 'local') {
			$this->validateLocalPath($form_state);
		}

		$this->validateCodec($form_state);

		if ((int) $form_state->getValue('flush__max_age') < 1) {
			$form_state->setErrorByName(
				'flush__max_age',
				$this->t('The flush interval must be at least one second.'),
			);
		}

		parent::validateForm($form, $form_state);
	}

	/**
	 * Refuses a codec this host cannot write frames with.
	 *
	 * The select lists an unavailable codec on purpose, with its reason, because somebody looking for
	 * zstd has to be told the extension is missing rather than left wondering whether the release
	 * supports it. Listing one is not the same as accepting it: saving it leaves the flush path
	 * quietly on a different codec, and `Engine::codecs()` then carries a finding for a state a form
	 * could have refused outright.
	 *
	 * @param FormStateInterface $form_state
	 *   The submitted values.
	 */
	private function validateCodec(FormStateInterface $form_state): void
	{
		$id = trim((string) $form_state->getValue('codec__id'));

		if ($id === '') {
			return;
		}

		try {
			$registry = CodecRegistry::withShippedCodecs();
		} catch (Throwable) {
			return;
		}

		if ($registry->canWritePerFrame($id)) {
			return;
		}

		$reasons = $registry->unavailable()[$id] ?? [];

		$form_state->setErrorByName(
			'codec__id',
			$this->t('This host cannot compress with @codec: @why', [
				'@codec' => $id,
				'@why' => implode('; ', $reasons) ?: (string) $this->t('it is not registered'),
			]),
		);
	}

	/**
	 * Refuses a key the cipher could not seal a frame with.
	 *
	 * A key of the wrong length passes every check the form used to make and then raises inside a
	 * queue worker at the first flush, where the only trace is a log line. The rule is the cipher's
	 * own, so it is applied by building what the cipher builds rather than by restating the length.
	 *
	 * @param FormStateInterface $form_state
	 *   The submitted values.
	 * @param string $name
	 *   The chosen key entity id.
	 */
	private function validateKey(FormStateInterface $form_state, string $name): void
	{
		if ($this->keys === null) {
			return;
		}

		try {
			$value = (string) $this->keys->getKey($name)?->getKeyValue();
		} catch (Throwable $error) {
			$form_state->setErrorByName(
				'key',
				$this->t('That key could not be read: @why', ['@why' => $error->getMessage()]),
			);

			return;
		}

		if ($value === '') {
			$form_state->setErrorByName(
				'key',
				$this->t('That key holds no value yet. Give it one at the Keys page first.'),
			);

			return;
		}

		try {
			StaticKeyProvider::fromStored($value);
		} catch (Throwable $error) {
			$form_state->setErrorByName(
				'key',
				$this->t('That key cannot seal a frame: @why', ['@why' => $error->getMessage()]),
			);
		}
	}

	/**
	 * Warns when the local directory is somewhere this site cannot write.
	 *
	 * A warning rather than an error: an operator may be configuring a path a deployment step
	 * creates later, and refusing the save would leave the rest of the form unsaveable. What must
	 * not happen is the save looking clean and the first flush failing in a queue worker.
	 *
	 * @param FormStateInterface $form_state
	 *   The submitted values.
	 */
	private function validateLocalPath(FormStateInterface $form_state): void
	{
		$path = trim((string) $form_state->getValue('local_path'));

		if ($path === '') {
			$form_state->setErrorByName(
				'local_path',
				$this->t('The local provider needs a directory to write to.'),
			);

			return;
		}

		// stream_get_wrappers() rather than the stream wrapper manager: what decides whether is_dir()
		// can answer is what PHP has registered, not what Drupal knows about
		$scheme = StreamWrapperManager::getScheme($path);

		if ($scheme !== false && !in_array($scheme, stream_get_wrappers(), true)) {
			$this->messenger()->addWarning(
				$this->t(
					'This site has no @scheme:// file system, so a flush would have nowhere to write.',
					['@scheme' => $scheme],
				),
			);

			return;
		}

		if (!is_dir($path)) {
			$this->messenger()->addWarning(
				$this->t(
					'@path is not a directory yet. Strata will not be able to flush until it is.',
					[
						'@path' => $path,
					],
				),
			);
		}
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
				'Each remote provider needs its own Strata submodule enabled. Local writes to a directory on this server.',
			),
		];

		$form['destination']['local_path'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Local Directory'),
			'#default_value' => (string) $this->setting('local_path', 'private://strata'),
			'#description' => $this->t(
				'A path on this server, or a stream wrapper such as private://strata.',
			),
			'#states' => ['visible' => [':input[name="provider"]' => ['value' => 'local']]],
		];

		$form['destination']['site_id'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Site Identifier'),
			'#default_value' => (string) $this->setting('site_id', ''),
			'#description' => $this->t(
				'Leave empty to derive one from the database. Changing it on a site with history starts a second history.',
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
				'The compression used on every frame. Run drush strata:calibrate to measure what this host achieves.',
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
			'#description' => $this->t(
				'A version can be stored as the difference from the one before it. A longer chain stores less and takes longer to read back.',
			),
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
			'#description' => $this->t(
				'How every frame is encrypted before it leaves this server. Without it, anyone who can read the store can read this site.',
			),
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

		// key_select rather than a textfield: the machine name of a key entity is not something an
		// operator can be expected to know, and the element's own process callback appends the link
		// that creates one, which is the step a first install is missing
		$form['sealing']['key'] = [
			'#type' => 'key_select',
			'#title' => $this->t('Encryption Key'),
			'#empty_option' => $this->t('- None Chosen -'),
			'#default_value' => (string) $this->setting('key', ''),
			'#description' => $this->t(
				'Must hold @bytes bytes, or @hex hex characters. Losing it makes every frame unreadable.',
				[
					'@bytes' => KeyProviderInterface::KEY_BYTES,
					'@hex' => KeyProviderInterface::KEY_BYTES * 2,
				],
			),
			'#states' => [
				'invisible' => [':input[name="cipher__id"]' => ['value' => 'none']],
			],
		];

		$this->addKeyButton($form);
	}

	/**
	 * The button that makes a key for an operator who has not got one.
	 *
	 * Encryption ships on with no key, so the shipped state of a fresh install is a form that cannot
	 * be saved and a flush that refuses. Getting out of it means creating a key entity in another
	 * module, of the right type and the right byte length.
	 *
	 * The same button replaces a key once one exists, and retires the outgoing one in the same step.
	 * A new key configured without retiring the old one makes every frame written before it
	 * unreadable, and nothing afterwards can tell that apart from corruption.
	 *
	 * @param array<string, mixed> $form
	 *   The form, added to by reference.
	 */
	private function addKeyButton(array &$form): void
	{
		if ($this->keyMaker?->available() !== true) {
			return;
		}

		$form['sealing']['generate_key'] = [
			'#type' => 'submit',
			'#value' =>
				trim((string) $this->setting('key', '')) === ''
					? $this->t('Generate a Key')
					: $this->t('Replace With a New Key'),
			// has to be pressable while the form does not validate, which on a fresh install is
			// every time: encryption is on and no key is chosen
			'#limit_validation_errors' => [],
			'#submit' => ['::generateKey'],
			'#states' => [
				'invisible' => [':input[name="cipher__id"]' => ['value' => 'none']],
			],
		];
	}

	/**
	 * Creates a key, selects it, and retires whichever one it displaced.
	 *
	 * Saves configuration on its own rather than deferring to the main submit, because it runs with
	 * validation limited away and the rest of the submitted values have therefore not been checked.
	 *
	 * @param array<string, mixed> $form
	 *   The form.
	 * @param FormStateInterface $form_state
	 *   The submitted values.
	 */
	public function generateKey(array &$form, FormStateInterface $form_state): void
	{
		if ($this->keyMaker === null) {
			$this->messenger()->addError(
				$this->t('The key module is not available, so no key can be created.'),
			);

			return;
		}

		$previous = trim((string) $this->setting('key', ''));

		try {
			$id = $this->keyMaker->create();
		} catch (Throwable $error) {
			$this->messenger()->addError(
				$this->t('No key could be created: @why', ['@why' => $error->getMessage()]),
			);

			return;
		}

		$config = $this->config(self::SETTINGS)->set('key', $id);

		if ($previous !== '' && $previous !== $id) {
			$config->set(
				'retired_keys',
				array_values(array_unique([...$this->retiredKeys(), $previous])),
			);
		}

		$config->save();
		$this->engine?->reset();

		$this->messenger()->addStatus(
			$this->t('Key @key was created, and new frames are sealed with it.', ['@key' => $id]),
		);
		$this->messenger()->addWarning(
			$this->t(
				'Its value lives in this site configuration, so a configuration export carries it. Keep a copy somewhere this site cannot lose.',
			),
		);

		if ($previous !== '') {
			$this->messenger()->addWarning(
				$this->t(
					'Key @key is retired rather than removed, because everything it sealed becomes unreadable without it. Run drush strata:rotate-key until it reports the rotation complete, then delete it here.',
					['@key' => $previous],
				),
			);
		}

		$form_state->setRedirect('strata.settings.storage');
	}

	/**
	 * When a window is sealed.
	 *
	 * @param array<string, mixed> $form
	 *   The form, added to by reference.
	 */
	private function addFlush(array &$form): void
	{
		$prices = PriceTable::forProvider((string) $this->setting('provider', 'local'));

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
			'#description' => $this->t('Where captured changes wait until the window is sealed.'),
		];

		$form['flush']['flush__max_age'] = [
			'#type' => 'number',
			'#title' => $this->t('Seconds'),
			'#min' => 1,
			'#default_value' => (int) $this->setting('flush.max_age', 15),
			'#description' => $this->t('How much work a host failure right now would cost.'),
		];

		$form['flush']['flush__max_bytes'] = [
			'#type' => 'number',
			'#title' => $this->t('Buffered Bytes'),
			'#min' => 1024,
			'#default_value' => (int) $this->setting('flush.max_bytes', 4_194_304),
			'#description' => $this->t('Seals early when a burst of edits reaches this much.'),
		];

		$form['flush']['flush__max_ops'] = [
			'#type' => 'number',
			'#title' => $this->t('Buffered Operations'),
			'#min' => 1,
			'#default_value' => (int) $this->setting('flush.max_ops', 5000),
			'#description' => $this->t('Seals early when this many changes have been captured.'),
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
			'#description' => $this->t(
				'Stored history is cut into pieces this size, and identical pieces are stored once.',
			),
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
			$options[$id] = self::providerLabel($id);
		}

		return $options;
	}

	/**
	 * A provider id as something to pick from a list.
	 *
	 * A registry entry is a machine name, and rendering `b2` beside "Local Directory" asks the
	 * operator to already know which submodule they enabled. An id from an add-on this module does
	 * not ship falls through to itself, which is the right answer for a name only its author knows.
	 *
	 * @param string $id
	 *   The provider id as it appears in configuration.
	 *
	 * @return string
	 *   What to show.
	 */
	public static function providerLabel(string $id): string
	{
		return match ($id) {
			'local' => 'Local Directory',
			'null' => 'None, Discard Everything',
			's3' => 'AWS S3, S3-Compatible or Cloudflare R2',
			'azure' => 'Azure Blob Storage',
			'gcs' => 'Google Cloud Storage',
			'b2' => 'Backblaze B2',
			'sftp' => 'SFTP',
			default => $id,
		};
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
