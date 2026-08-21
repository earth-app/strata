<?php

declare(strict_types=1);

namespace Drupal\strata\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\strata\Telemetry\OtlpPayload;
use Drupal\strata\Telemetry\TelemetryPass;

/**
 * Where spans and metrics are sent.
 *
 * An empty endpoint switches the whole subsystem off, including the tracer, so a site with no
 * collector pays nothing for the instrumentation rather than buffering spans nobody reads.
 *
 * Headers are entered as one `Name: value` per line and stored as a mapping. They are the only place
 * an API key belongs, so the field is a plain textarea and its contents are stored in configuration
 * like any other setting - a site that exports its configuration will export this too, which is
 * worth knowing before an authorization token goes in it.
 *
 * @see TelemetryPass
 * @see OtlpPayload
 */
final class TelemetrySettingsForm extends SettingsFormBase
{
	/**
	 * {@inheritdoc}
	 */
	public function getFormId(): string
	{
		return 'strata_telemetry_settings';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function settingKeys(): array
	{
		return ['telemetry.endpoint' => 'string'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function buildForm(array $form, FormStateInterface $form_state): array
	{
		$form[$this->fieldFor('telemetry.endpoint')] = [
			'#type' => 'url',
			'#title' => $this->t('Collector Endpoint'),
			'#default_value' => (string) $this->setting('telemetry.endpoint', ''),
			'#description' => $this->t(
				'The collector base URL; @traces and @metrics are appended. Empty exports nothing.',
				[
					'@traces' => OtlpPayload::TRACES_PATH,
					'@metrics' => OtlpPayload::METRICS_PATH,
				],
			),
		];

		$form['headers'] = [
			'#type' => 'textarea',
			'#title' => $this->t('Headers'),
			'#rows' => 4,
			'#default_value' => $this->renderHeaders(),
			'#description' => $this->t(
				'One Name: value per line. Stored in exportable configuration.',
			),
		];

		$form['metrics'] = [
			'#type' => 'item',
			'#title' => $this->t('What is Exported'),
			'#markup' => $this->t(
				'Store totals, findings by severity, and the recovery point lag.',
			),
		];

		return parent::buildForm($form, $form_state);
	}

	/**
	 * {@inheritdoc}
	 */
	public function validateForm(array &$form, FormStateInterface $form_state): void
	{
		foreach ($this->lines((string) $form_state->getValue('headers')) as $line) {
			if (!str_contains($line, ':')) {
				$form_state->setErrorByName(
					'headers',
					$this->t('"@line" is not a Name: value pair.', ['@line' => $line]),
				);
			}
		}

		parent::validateForm($form, $form_state);
	}

	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state): void
	{
		$headers = [];

		foreach ($this->lines((string) $form_state->getValue('headers')) as $line) {
			[$name, $value] = explode(':', $line, 2);
			$name = trim($name);

			if ($name !== '') {
				$headers[$name] = trim($value);
			}
		}

		$this->config(self::SETTINGS)->set('telemetry.headers', $headers)->save();

		parent::submitForm($form, $form_state);
	}

	/**
	 * The stored headers, as the textarea shows them.
	 *
	 * @return string
	 *   One `Name: value` per line.
	 */
	private function renderHeaders(): string
	{
		/** @var array<string, string> $headers */
		$headers = $this->setting('telemetry.headers', []) ?? [];
		$lines = [];

		foreach ($headers as $name => $value) {
			$lines[] = sprintf('%s: %s', $name, $value);
		}

		return implode("\n", $lines);
	}

	/**
	 * The non-empty lines of a textarea.
	 *
	 * @param string $value
	 *   The submitted value.
	 *
	 * @return list<string>
	 *   The trimmed lines.
	 */
	private function lines(string $value): array
	{
		return array_values(
			array_filter(
				array_map('trim', preg_split('/\r\n|\r|\n/', $value) ?: []),
				static fn(string $line): bool => $line !== '',
			),
		);
	}
}
