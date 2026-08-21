<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Functional;

use Drupal\strata\Event\Webhook\WebhookDispatcher;
use Drupal\strata_notify\NotificationPolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves every settings form renders, saves what was changed, and hands the value back.
 *
 * A settings form is the one place a site's whole configuration can be silently corrupted, because
 * `ConfigFormBase::submitForm()` saves whatever it is handed: a form that rendered a key it did not
 * own would blank it. The round trip through a browser is what catches that - the value has to
 * survive being submitted as a string, coerced to its schema type, saved against the schema, and
 * rendered back into the same field.
 *
 * **The secret assertions are the reason this lane exists.** The S3 and webhook forms both promise a
 * stored secret is never rendered back into the page, and the only way to check a promise about HTML
 * is to read the HTML.
 */
#[RunTestsInSeparateProcesses]
class SettingsFormTest extends StrataFunctionalTestBase
{
	/**
	 * The submit button every configuration form carries.
	 */
	public const SAVE = 'Save configuration';

	/**
	 * What a saved configuration form says.
	 */
	public const SAVED = 'The configuration options have been saved.';

	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = [
		'system',
		'user',
		'node',
		'field',
		'key',
		'strata',
		'strata_ui',
		'strata_s3',
		'strata_notify',
	];

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->user(['administer strata', 'manage strata storage']);
	}

	/**
	 * Opens a form and submits it, asserting it saved.
	 *
	 * @param string $path
	 *   The form's path.
	 * @param array<string, mixed> $edit
	 *   Field names keyed to the values to submit.
	 */
	private function save(string $path, array $edit): void
	{
		$this->drupalGet($path);
		$this->assertSession()->statusCodeEquals(200);
		$this->submitForm($edit, self::SAVE);
		$this->assertSession()->pageTextContains(self::SAVED);
		$this->refreshVariables();
	}

	#region Storage

	#[Test]
	#[TestDox('the storage form saves a site identifier and hands it back on reload')]
	#[Group('strata/functional')]
	public function storageFormRoundTripsASetting(): void
	{
		$this->drupalGet('/admin/config/system/strata/storage');

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->fieldExists('site_id');
		$this->assertSession()->fieldExists('provider');
		$this->assertSession()->fieldExists('flush__max_age');

		$this->save('/admin/config/system/strata/storage', [
			'site_id' => 'strata-functional',
			'flush__max_age' => 30,
		]);

		$this->assertSame('strata-functional', $this->settings()->get('site_id'));
		$this->assertSame(30, $this->settings()->get('flush.max_age'));

		$this->drupalGet('/admin/config/system/strata/storage');
		$this->assertSession()->fieldValueEquals('site_id', 'strata-functional');
		$this->assertSession()->fieldValueEquals('flush__max_age', '30');
	}

	#[Test]
	#[TestDox('the storage form refuses encryption with no key rather than storing plaintext')]
	#[Group('strata/functional')]
	public function storageFormRefusesEncryptionWithoutAKey(): void
	{
		$this->drupalGet('/admin/config/system/strata/storage');
		$this->submitForm(['cipher__id' => 'xchacha20poly1305', 'key' => ''], self::SAVE);

		$this->assertSession()->pageTextContains('Encryption is on and no key is chosen.');
		$this->assertSession()->pageTextNotContains(self::SAVED);
		$this->refreshVariables();
		$this->assertSame('none', $this->settings()->get('cipher.id'));
	}

	#endregion

	#region Capture

	#[Test]
	#[TestDox('the capture form saves a realm switch and the reconciler sample size')]
	#[Group('strata/functional')]
	public function captureFormRoundTripsARealm(): void
	{
		$this->save('/admin/config/system/strata/capture', [
			'capture__file' => true,
			'capture__sample_rows' => 500,
		]);

		$this->assertTrue($this->settings()->get('capture.file'));
		$this->assertSame(500, $this->settings()->get('capture.sample_rows'));

		$this->drupalGet('/admin/config/system/strata/capture');
		$this->assertSession()->checkboxChecked('capture__file');
		$this->assertSession()->fieldValueEquals('capture__sample_rows', '500');
	}

	#endregion

	#region Retention

	#[Test]
	#[TestDox('the retention form saves the anchor interval and hands it back on reload')]
	#[Group('strata/functional')]
	public function retentionFormRoundTripsTheAnchorInterval(): void
	{
		$this->save('/admin/config/system/strata/retention', [
			'retention__base_interval' => 7200,
			'codec__compaction_level' => 22,
		]);

		$this->assertSame(7200, $this->settings()->get('retention.base_interval'));
		$this->assertSame(22, $this->settings()->get('codec.compaction_level'));

		$this->drupalGet('/admin/config/system/strata/retention');
		$this->assertSession()->fieldValueEquals('retention__base_interval', '7200');
		$this->assertSession()->fieldValueEquals('codec__compaction_level', '22');
	}

	#[Test]
	#[TestDox('the retention form refuses a ladder level that is not wider than the one below it')]
	#[Group('strata/functional')]
	public function retentionFormRefusesALadderThatFoldsBackwards(): void
	{
		$this->drupalGet('/admin/config/system/strata/retention');
		$this->submitForm(['level_1_window' => 5], self::SAVE);

		$this->assertSession()->pageTextContains('is not wider than the level below it');
		$this->assertSession()->pageTextNotContains(self::SAVED);
	}

	#endregion

	#region Telemetry

	#[Test]
	#[TestDox('the telemetry form round-trips the collector endpoint and its headers')]
	#[Group('strata/functional')]
	public function telemetryFormRoundTripsHeaders(): void
	{
		$this->save('/admin/config/system/strata/telemetry', [
			'telemetry__endpoint' => 'https://collector.example.com',
			'headers' => "X-Scope-Org-Id: strata\nX-Extra: two",
		]);

		$this->assertSame(
			['X-Scope-Org-Id' => 'strata', 'X-Extra' => 'two'],
			$this->settings()->get('telemetry.headers'),
		);

		$this->drupalGet('/admin/config/system/strata/telemetry');
		$this->assertSession()->fieldValueEquals(
			'telemetry__endpoint',
			'https://collector.example.com',
		);
		// compared line by line, because a textarea's newlines are the browser's to normalise
		$rendered = (string) $this->assertSession()->fieldExists('headers')->getValue();

		$this->assertStringContainsString('X-Scope-Org-Id: strata', $rendered);
		$this->assertStringContainsString('X-Extra: two', $rendered);
	}

	#[Test]
	#[TestDox('the telemetry form refuses a header line that is not a name and a value')]
	#[Group('strata/functional')]
	public function telemetryFormRefusesAMalformedHeader(): void
	{
		$this->drupalGet('/admin/config/system/strata/telemetry');
		$this->submitForm(['headers' => 'not-a-header'], self::SAVE);

		$this->assertSession()->pageTextContains('is not a Name: value pair.');
		$this->assertSession()->pageTextNotContains(self::SAVED);
	}

	#endregion

	#region Webhooks

	#[Test]
	#[TestDox('the webhook form saves an endpoint and hands its url back on reload')]
	#[Group('strata/functional')]
	public function webhookFormRoundTripsAnEndpoint(): void
	{
		$this->save('/admin/config/system/strata/webhooks', [
			'endpoint_0[url]' => 'https://hooks.example.com/strata',
			'endpoint_0[timeout]' => 9,
			'endpoint_0[attempts]' => 4,
		]);

		$stored = $this->config(WebhookDispatcher::CONFIG)->get('subscriptions');

		$this->assertCount(1, $stored);
		$this->assertSame('https://hooks.example.com/strata', $stored[0]['url']);
		$this->assertSame(9, $stored[0]['timeout']);
		$this->assertSame(4, $stored[0]['attempts']);

		$this->drupalGet('/admin/config/system/strata/webhooks');
		$this->assertSession()->fieldValueEquals(
			'endpoint_0[url]',
			'https://hooks.example.com/strata',
		);
	}

	#[Test]
	#[TestDox('a stored webhook secret is never rendered back into the page')]
	#[Group('strata/functional')]
	public function storedWebhookSecretIsNeverRendered(): void
	{
		$secret = 'webhook-signing-secret-4f2a';

		$this->save('/admin/config/system/strata/webhooks', [
			'endpoint_0[url]' => 'https://hooks.example.com/strata',
			'endpoint_0[secret]' => $secret,
		]);

		$stored = $this->config(WebhookDispatcher::CONFIG)->get('subscriptions');

		$this->assertSame($secret, $stored[0]['secret'], 'the secret really was stored');

		$this->drupalGet('/admin/config/system/strata/webhooks');

		$this->assertSession()->responseNotContains($secret);
		$this->assertSession()->pageTextContains('A secret is set. Leave empty to keep it.');
	}

	#[Test]
	#[TestDox('an empty secret submission keeps the stored one rather than clearing it')]
	#[Group('strata/functional')]
	public function emptySecretSubmissionKeepsTheStoredSecret(): void
	{
		$secret = 'webhook-signing-secret-9c11';

		$this->save('/admin/config/system/strata/webhooks', [
			'endpoint_0[url]' => 'https://hooks.example.com/strata',
			'endpoint_0[secret]' => $secret,
		]);
		$this->save('/admin/config/system/strata/webhooks', [
			'endpoint_0[url]' => 'https://hooks.example.com/moved',
			'endpoint_0[secret]' => '',
		]);

		$stored = $this->config(WebhookDispatcher::CONFIG)->get('subscriptions');

		$this->assertSame('https://hooks.example.com/moved', $stored[0]['url']);
		$this->assertSame($secret, $stored[0]['secret']);
	}

	#endregion

	#region S3

	#[Test]
	#[TestDox('the s3 form saves the bucket, endpoint and region and hands them back')]
	#[Group('strata/functional')]
	public function s3FormRoundTripsTheBucket(): void
	{
		$this->save('/admin/config/system/strata/s3', [
			'bucket' => 'strata-functional',
			'endpoint' => 'https://minio.example.com',
			'region' => 'auto',
			'path_style' => true,
		]);

		$this->assertSame('strata-functional', $this->settings()->get('s3.bucket'));
		$this->assertSame('https://minio.example.com', $this->settings()->get('s3.endpoint'));
		$this->assertSame('auto', $this->settings()->get('s3.region'));
		$this->assertTrue($this->settings()->get('s3.path_style'));

		$this->drupalGet('/admin/config/system/strata/s3');
		$this->assertSession()->fieldValueEquals('bucket', 'strata-functional');
		$this->assertSession()->fieldValueEquals('region', 'auto');
		$this->assertSession()->checkboxChecked('path_style');
	}

	#[Test]
	#[TestDox('the s3 form refuses a bucket name no endpoint would accept')]
	#[Group('strata/functional')]
	public function s3FormRefusesAnInvalidBucketName(): void
	{
		$this->drupalGet('/admin/config/system/strata/s3');
		$this->submitForm(['bucket' => 'Not A Bucket'], self::SAVE);

		$this->assertSession()->pageTextContains(
			'A bucket name is 3 to 63 lowercase characters, digits, dots or dashes.',
		);
		$this->assertSession()->pageTextNotContains(self::SAVED);
	}

	#[Test]
	#[TestDox('a stored s3 secret is never rendered back into the page')]
	#[Group('strata/functional')]
	public function storedS3SecretIsNeverRendered(): void
	{
		$secret = 'aws-secret-access-key-7b3d';

		$this->save('/admin/config/system/strata/s3', [
			'bucket' => 'strata-functional',
			'access_key_id' => 'AKIAFUNCTIONALTEST',
			'secret_access_key' => $secret,
		]);

		$this->assertSame($secret, $this->settings()->get('s3.secret_access_key'));

		$this->drupalGet('/admin/config/system/strata/s3');

		$this->assertSession()->responseNotContains($secret);
		$this->assertSession()->fieldValueEquals('access_key_id', 'AKIAFUNCTIONALTEST');
		$this->assertSession()->pageTextContains('A secret is stored. Leave empty to keep it.');
	}

	#[Test]
	#[TestDox('clearing a stored s3 secret needs its own control')]
	#[Group('strata/functional')]
	public function clearingTheS3SecretNeedsItsOwnControl(): void
	{
		$secret = 'aws-secret-access-key-1e90';

		$this->save('/admin/config/system/strata/s3', [
			'bucket' => 'strata-functional',
			'secret_access_key' => $secret,
		]);
		$this->save('/admin/config/system/strata/s3', [
			'bucket' => 'strata-functional',
			'clear_secret' => true,
		]);

		$this->assertSame('', $this->settings()->get('s3.secret_access_key'));
	}

	#endregion

	#region Notifications

	#[Test]
	#[TestDox('the notification form saves recipients, a severity floor and the event ticks')]
	#[Group('strata/functional')]
	public function notifyFormRoundTripsRecipientsAndEvents(): void
	{
		$this->save('/admin/config/system/strata/notify', [
			'recipients' => 'ops@example.com, oncall@example.com',
			'severity' => 3,
			'events[commit]' => true,
			'events[prune]' => true,
		]);

		$config = $this->config(NotificationPolicy::CONFIG);

		$this->assertSame('ops@example.com, oncall@example.com', $config->get('recipients'));
		$this->assertSame(3, $config->get('severity'));
		$this->assertTrue($config->get('events.commit'));
		$this->assertTrue($config->get('events.prune'));

		$this->drupalGet('/admin/config/system/strata/notify');
		$this->assertSession()->fieldValueEquals(
			'recipients',
			'ops@example.com, oncall@example.com',
		);
		$this->assertSession()->checkboxChecked('events[commit]');
	}

	#endregion
}
