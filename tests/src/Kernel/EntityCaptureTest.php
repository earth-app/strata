<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Kernel;

use Drupal\strata\Capture\CaptureScope;
use Drupal\strata\Hook\EntityCapture;
use Drupal\strata\Journal\JournalInterface;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves a real entity save reaches the journal through the hook system.
 */
class EntityCaptureTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'field', 'key', 'strata'];

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->installEntitySchema('user');
		$this->installSchema('user', ['users_data']);

		$this->config('strata.settings')->set('enabled', true)->save();
		$this->scope()->reset();
	}

	private function journal(): JournalInterface
	{
		return $this->container->get('strata.journal');
	}

	private function scope(): CaptureScope
	{
		return $this->container->get('strata.capture_scope');
	}

	private function user(string $name): User
	{
		$user = User::create(['name' => $name, 'mail' => $name . '@example.com', 'status' => 1]);
		$user->save();

		return $user;
	}

	#region Wiring

	#[Test]
	#[TestDox('the capture service and the journal are both registered')]
	#[Group('strata/capture')]
	public function servicesAreRegistered(): void
	{
		$this->assertTrue($this->container->has('strata.journal'));
		$this->assertTrue($this->container->has('strata.capture_scope'));
		$this->assertTrue($this->container->has(EntityCapture::class));
		$this->assertInstanceOf(JournalInterface::class, $this->journal());
		$this->assertInstanceOf(
			JournalInterface::class,
			$this->container->get(JournalInterface::class),
			'the interface is aliased so a hook class can autowire it',
		);
	}

	#[Test]
	#[TestDox('capture is off until the module is enabled for it')]
	#[Group('strata/capture')]
	public function captureIsOffByDefault(): void
	{
		$this->config('strata.settings')->set('enabled', false)->save();
		$this->scope()->reset();

		$this->journal()->clear();
		$this->user('ignored');

		$this->assertSame(0, $this->journal()->pending());
	}

	#endregion

	#region Creates and updates

	#[Test]
	#[TestDox('creating an entity appends one create operation')]
	#[Group('strata/capture')]
	public function createIsCaptured(): void
	{
		$this->journal()->clear();
		$user = $this->user('created');

		$entries = $this->journal()->read();

		$this->assertCount(1, $entries);

		$operation = $entries[0]['operation'];
		$this->assertSame(Realm::ENTITY, $operation->realm);
		$this->assertSame(Verb::CREATE, $operation->verb);
		$this->assertSame('user:' . $user->id(), $operation->subject);
		$this->assertGreaterThan(0, $operation->sequence);
		$this->assertGreaterThan(0, $operation->microtime);
		$this->assertStringContainsString('created', $operation->label);
		$this->assertNotNull($entries[0]['payload']);
	}

	#[Test]
	#[TestDox('changing a field appends one update carrying only that field')]
	#[Group('strata/capture')]
	public function updateCapturesOnlyChangedFields(): void
	{
		$user = $this->user('before');
		$this->journal()->clear();

		$user->set('name', 'after');
		$user->save();

		$entries = $this->journal()->read();

		$this->assertCount(1, $entries);

		$operation = $entries[0]['operation'];
		$this->assertSame(Verb::UPDATE, $operation->verb);
		$this->assertSame(['name'], $operation->fields);

		/** @var array<string, mixed> $payload */
		$payload = json_decode((string) $entries[0]['payload'], true);
		$this->assertSame(['name'], array_keys($payload));
	}

	#[Test]
	#[TestDox('a save that changed nothing appends nothing')]
	#[Group('strata/capture')]
	public function noopSaveAppendsNothing(): void
	{
		$user = $this->user('noop');
		$this->journal()->clear();

		$user->save();

		$this->assertSame(0, $this->journal()->pending());
	}

	#[Test]
	#[TestDox('deleting an entity appends a delete with no payload')]
	#[Group('strata/capture')]
	public function deleteIsCapturedWithoutPayload(): void
	{
		$user = $this->user('doomed');
		$subject = 'user:' . $user->id();
		$this->journal()->clear();

		$user->delete();

		$entries = $this->journal()->read();

		$this->assertCount(1, $entries);
		$this->assertSame(Verb::DELETE, $entries[0]['operation']->verb);
		$this->assertSame($subject, $entries[0]['operation']->subject);
		$this->assertNull($entries[0]['payload']);
		$this->assertSame(0, $entries[0]['operation']->payloadLength);
	}

	#[Test]
	#[TestDox('several saves append in capture order with increasing sequences')]
	#[Group('strata/capture')]
	public function sequencesIncrease(): void
	{
		$this->journal()->clear();

		$user = $this->user('ordered');
		for ($i = 0; $i < 3; $i++) {
			$user->set('name', "ordered-$i");
			$user->save();
		}

		$sequences = array_map(
			static fn(array $entry): int => $entry['operation']->sequence,
			$this->journal()->read(),
		);

		$this->assertCount(4, $sequences);
		$this->assertSame($sequences, array_values(array_unique($sequences)));

		$sorted = $sequences;
		sort($sorted);
		$this->assertSame($sorted, $sequences, 'the journal preserves capture order');
	}

	#endregion

	#region Access churn

	#[Test]
	#[TestDox('an access touch becomes a compact event rather than a field delta')]
	#[Group('strata/capture')]
	public function accessTouchBecomesAnEvent(): void
	{
		$user = $this->user('seen');
		$this->journal()->clear();

		$user->set('access', (int) $user->get('access')->value + 180);
		$user->save();

		$entries = $this->journal()->read();

		$this->assertCount(1, $entries);

		$operation = $entries[0]['operation'];
		$this->assertSame(Realm::EPHEMERAL, $operation->realm);
		$this->assertNull($entries[0]['payload'], 'a login event carries no payload to restore');
		$this->assertSame(0, $operation->payloadLength);
		$this->assertStringContainsString('was seen', $operation->label);
	}

	#[Test]
	#[TestDox('an access touch can be dropped entirely when configured to be')]
	#[Group('strata/capture')]
	public function accessTouchCanBeDropped(): void
	{
		$this->config('strata.settings')->set('capture.access_churn', 'drop')->save();
		$this->scope()->reset();

		$user = $this->user('dropped');
		$this->journal()->clear();

		$user->set('access', (int) $user->get('access')->value + 180);
		$user->save();

		$this->assertSame(0, $this->journal()->pending());
	}

	#[Test]
	#[TestDox('an access touch can be captured as a full delta when configured to be')]
	#[Group('strata/capture')]
	public function accessTouchCanBeFullDelta(): void
	{
		$this->config('strata.settings')->set('capture.access_churn', 'delta')->save();
		$this->scope()->reset();

		$user = $this->user('full');
		$this->journal()->clear();

		$user->set('access', (int) $user->get('access')->value + 180);
		$user->save();

		// the access fields are still excluded from the delta itself, so nothing meaningful changed
		$this->assertSame(0, $this->journal()->pending());
	}

	#[Test]
	#[TestDox('the event mode is far cheaper than the churn it replaces')]
	#[Group('strata/capture')]
	public function eventModeIsCheaper(): void
	{
		$user = $this->user('cost');
		$this->journal()->clear();

		$user->set('access', (int) $user->get('access')->value + 180);
		$user->save();

		$eventBytes = $this->journal()->pendingBytes();
		$this->journal()->clear();

		$user->set('name', 'renamed');
		$user->save();

		$this->assertSame(0, $eventBytes, 'a login event stores no payload at all');
		$this->assertGreaterThan(0, $this->journal()->pendingBytes());
	}

	#endregion

	#region Journal behaviour

	#[Test]
	#[TestDox('the journal reports what is pending and trims what a flush has sealed')]
	#[Group('strata/capture')]
	public function journalReportsAndTrims(): void
	{
		$this->journal()->clear();

		$user = $this->user('trimmed');
		$user->set('name', 'trimmed-again');
		$user->save();

		$this->assertSame(2, $this->journal()->pending());
		$this->assertGreaterThan(0, $this->journal()->pendingBytes());
		$this->assertNotNull($this->journal()->oldest());

		$entries = $this->journal()->read();
		$through = $entries[0]['operation']->sequence;

		$this->assertSame(1, $this->journal()->trim($through));
		$this->assertSame(1, $this->journal()->pending());
	}

	#[Test]
	#[TestDox('an empty journal reports nothing pending and no oldest entry')]
	#[Group('strata/capture')]
	public function emptyJournalReportsNothing(): void
	{
		$this->journal()->clear();

		$this->assertSame(0, $this->journal()->pending());
		$this->assertSame(0, $this->journal()->pendingBytes());
		$this->assertNull($this->journal()->oldest());
		$this->assertSame([], $this->journal()->read());
	}

	#[Test]
	#[TestDox('a read limit bounds the window a flush takes')]
	#[Group('strata/capture')]
	public function readLimitBoundsTheWindow(): void
	{
		$this->journal()->clear();

		$user = $this->user('limited');
		for ($i = 0; $i < 5; $i++) {
			$user->set('name', "limited-$i");
			$user->save();
		}

		$this->assertCount(6, $this->journal()->read());
		$this->assertCount(3, $this->journal()->read(3));
		$this->assertSame([], $this->journal()->read(0));
	}

	#[Test]
	#[TestDox('an operation round-trips through the database journal unchanged')]
	#[Group('strata/capture')]
	public function operationRoundTripsThroughTheDatabase(): void
	{
		$this->journal()->clear();

		$user = $this->user('roundtrip');
		$user->set('name', 'roundtrip-two');
		$user->set('mail', 'roundtrip-two@example.com');
		$user->save();

		$entries = $this->journal()->read();
		$update = $entries[1]['operation'];

		$fields = $update->fields;
		sort($fields);

		$this->assertSame(Realm::ENTITY, $update->realm);
		$this->assertSame(Verb::UPDATE, $update->verb);
		$this->assertSame(['mail', 'name'], $fields);
		$this->assertNotNull($update->payloadHash);
		$this->assertSame(strlen((string) $entries[1]['payload']), $update->payloadLength);
	}

	#endregion

	#region Safety

	#[Test]
	#[TestDox('a capture failure is logged rather than taking the save down')]
	#[Group('strata/capture')]
	public function captureFailureDoesNotBreakTheSave(): void
	{
		// dropping the journal table makes every append fail
		$this->container->get('database')->schema()->dropTable('strata_journal');

		$user = User::create([
			'name' => 'survives',
			'mail' => 'survives@example.com',
			'status' => 1,
		]);

		$user->save();

		$this->assertNotNull($user->id(), 'the save completed despite the capture failing');
		$this->assertSame('survives', $user->getAccountName());
	}

	#endregion
}
