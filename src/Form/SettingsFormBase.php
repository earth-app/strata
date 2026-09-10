<?php

declare(strict_types=1);

namespace Drupal\strata\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\strata\Engine;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared behaviour for the settings forms.
 *
 * Every settings form edits one part of a single configuration object, so they all read and write the
 * same name and all have to leave the parts they do not own alone. `ConfigFormBase::submitForm()`
 * saves whatever it is handed, so a form that set a whole mapping would silently blank the keys it
 * did not render - which is how a capture form ends up resetting the retention ladder.
 *
 * So each form declares the keys it owns and nothing else is touched.
 *
 * **The engine is reset after every save.** It caches the provider, the cipher, the codec registry
 * and the object store on first use, so a request that saved a new bucket and then flushed would
 * flush to the old one.
 *
 * @see Engine::reset()
 */
abstract class SettingsFormBase extends ConfigFormBase
{
	/**
	 * The configuration object every settings form edits.
	 */
	public const SETTINGS = 'strata.settings';

	/**
	 * The engine, reset after a save.
	 */
	protected ?Engine $engine = null;

	/**
	 * {@inheritdoc}
	 *
	 * **The engine is asked for, never required.** `composer.json` PSR-4 maps `Drupal\strata\` onto
	 * `src/`, so every class here loads off composer's autoloader whether or not the module is in
	 * `core.extension`, while `strata.services.yml` reaches the container only when it is. A router
	 * table still holding this route after the module left - a failed install, a `composer remove`
	 * with no uninstall - therefore resolves the class, calls this, and answered 500 with
	 * `ServiceNotFoundException` until 1.0.3. The property is nullable and every use of it is
	 * null-safe, so asking is the whole fix.
	 */
	public static function create(ContainerInterface $container): static
	{
		$form = parent::create($container);
		$form->engine = $container->has('strata.engine') ? $container->get('strata.engine') : null;

		return $form;
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
	public function submitForm(array &$form, FormStateInterface $form_state): void
	{
		$config = $this->config(self::SETTINGS);

		foreach ($this->settingKeys() as $key => $type) {
			$config->set($key, $this->coerce($form_state->getValue($this->fieldFor($key)), $type));
		}

		$config->save();
		$this->engine?->reset();

		parent::submitForm($form, $form_state);
	}

	/**
	 * The settings this form owns, keyed to their type.
	 *
	 * @return array<string, string>
	 *   Dotted config key keyed to one of "string", "int", "float" or "bool".
	 */
	abstract protected function settingKeys(): array;

	/**
	 * The form field a config key is edited by.
	 *
	 * Dots are not valid in a form element name, so a nested key is rendered under a flattened one.
	 *
	 * @param string $key
	 *   The dotted config key.
	 *
	 * @return string
	 *   The form field name.
	 */
	protected function fieldFor(string $key): string
	{
		return str_replace('.', '__', $key);
	}

	/**
	 * One current setting.
	 *
	 * @param string $key
	 *   The dotted config key.
	 * @param mixed $default
	 *   What to return when nothing is configured.
	 *
	 * @return mixed
	 *   The configured value.
	 */
	protected function setting(string $key, mixed $default = null): mixed
	{
		return $this->config(self::SETTINGS)->get($key) ?? $default;
	}

	/**
	 * Coerces a submitted value to the type the config schema declares.
	 *
	 * A form always submits strings, and configuration is schema-validated on save, so an integer
	 * setting handed the string "15" fails validation on a site running `strictConfigSchema`.
	 *
	 * @param mixed $value
	 *   The submitted value.
	 * @param string $type
	 *   One of "string", "int", "float" or "bool".
	 *
	 * @return mixed
	 *   The coerced value.
	 */
	protected function coerce(mixed $value, string $type): mixed
	{
		return match ($type) {
			'int' => (int) $value,
			'float' => (float) $value,
			'bool' => (bool) $value,
			default => is_string($value) ? trim($value) : (string) $value,
		};
	}
}
