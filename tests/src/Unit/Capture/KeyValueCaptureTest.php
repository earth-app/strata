<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Capture;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\strata\Capture\CaptureScope;
use Drupal\strata\Capture\KeyRecorder;
use Drupal\strata\Capture\KeyValueCapture;
use Drupal\strata\Capture\KeyValueCaptureFactory;
use Drupal\strata\Capture\PayloadCodec;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Journal\MemoryJournal;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Covers the key-value decorator directly.
 *
 * The kernel lane cannot reach this. `KernelTestBase` replaces `keyvalue` with a synthetic
 * in-memory factory so state survives a container rebuild, and Symfony refuses to decorate a
 * synthetic service, so the wiring is applied by a service provider that skips it. The decorator's
 * behaviour is therefore proved here, over the same in-memory factory a kernel test would use.
 */
#[CoversClass(KeyValueCapture::class)]
#[CoversClass(KeyValueCaptureFactory::class)]
#[CoversClass(KeyRecorder::class)]
class KeyValueCaptureTest extends TestCase
{
	/**
	 * The journal operations land in.
	 */
	private MemoryJournal $journal;

	/**
	 * The factory under test.
	 */
	private KeyValueCaptureFactory $factory;

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->journal = new MemoryJournal();
		$this->factory = new KeyValueCaptureFactory(
			new KeyValueMemoryFactory(),
			new KeyRecorder(
				$this->journal,
				new CaptureScope($this->settings()),
				$this->account(),
				new NullLogger(),
			),
		);
	}

	/**
	 * A config factory holding settings that capture everything.
	 *
	 * CaptureScope is final and reads from the config factory, so the scope under test is the real
	 * one over stubbed settings rather than a subclass that answers differently.
	 */
	private function settings(): ConfigFactoryInterface
	{
		$config = $this->createMock(ImmutableConfig::class);
		$config->method('getRawData')->willReturn([
			'enabled' => true,
			'capture' => ['keyvalue' => true, 'state' => true],
		]);

		$factory = $this->createMock(ConfigFactoryInterface::class);
		$factory->method('get')->willReturn($config);

		return $factory;
	}

	/**
	 * A current-user stand-in that reports nobody, so operations are unattributed.
	 */
	private function account(): AccountProxyInterface
	{
		$account = $this->createMock(AccountProxyInterface::class);
		$account->method('id')->willReturn(0);

		return $account;
	}

	/**
	 * The journal entry for one subject.
	 *
	 * @return array{operation: JournalOp, payload: string|null}|null
	 *   The entry, or NULL when the subject was not captured.
	 */
	private function entry(string $subject): ?array
	{
		foreach ($this->journal->read(1000) as $entry) {
			if ($entry['operation']->subject === $subject) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * The value a captured entry holds.
	 */
	private function value(string $subject): mixed
	{
		$entry = $this->entry($subject);
		$decoded = PayloadCodec::decode(Realm::KEY_VALUE, (string) $entry['payload']);

		return $decoded[PayloadCodec::VALUE] ?? null;
	}

	#region Writes

	#[Test]
	#[TestDox('a write is captured under collection and key, with its value')]
	#[Group('strata/capture')]
	public function writeIsCaptured(): void
	{
		$this->factory->get('demo')->set('thing', ['a' => 1]);

		$entry = $this->entry('demo:thing');

		$this->assertNotNull($entry);
		$this->assertSame(Realm::KEY_VALUE, $entry['operation']->realm);
		$this->assertSame(Verb::CREATE, $entry['operation']->verb);
		$this->assertSame(['a' => 1], $this->value('demo:thing'));
	}

	#[Test]
	#[TestDox('a second write to the same key is an update')]
	#[Group('strata/capture')]
	public function overwriteIsAnUpdate(): void
	{
		$store = $this->factory->get('demo');
		$store->set('thing', 'first');
		$this->journal->trim(PHP_INT_MAX);

		$store->set('thing', 'second');

		$this->assertSame(Verb::UPDATE, $this->entry('demo:thing')['operation']->verb);
		$this->assertSame('second', $this->value('demo:thing'));
	}

	#[Test]
	#[TestDox('a batch write is captured per key')]
	#[Group('strata/capture')]
	public function batchWriteIsCaptured(): void
	{
		$this->factory->get('demo')->setMultiple(['a' => 1, 'b' => 2]);

		$this->assertSame(1, $this->value('demo:a'));
		$this->assertSame(2, $this->value('demo:b'));
	}

	#[Test]
	#[TestDox('a batch write over an existing key records it as an update')]
	#[Group('strata/capture')]
	public function batchWriteDistinguishesUpdates(): void
	{
		$store = $this->factory->get('demo');
		$store->set('existing', 'here');
		$this->journal->trim(PHP_INT_MAX);

		$store->setMultiple(['existing' => 'changed', 'fresh' => 'new']);

		$this->assertSame(Verb::UPDATE, $this->entry('demo:existing')['operation']->verb);
		$this->assertSame(Verb::CREATE, $this->entry('demo:fresh')['operation']->verb);
	}

	#[Test]
	#[TestDox('setIfNotExists is captured only when it actually wrote')]
	#[Group('strata/capture')]
	public function setIfNotExistsRecordsOnlyRealWrites(): void
	{
		$store = $this->factory->get('demo');

		$this->assertTrue($store->setIfNotExists('once', 'first'));
		$this->assertNotNull($this->entry('demo:once'));

		$this->journal->trim(PHP_INT_MAX);

		$this->assertFalse($store->setIfNotExists('once', 'second'));
		$this->assertNull($this->entry('demo:once'), 'a write that did not happen is not a change');
		$this->assertSame('first', $store->get('once'));
	}

	#endregion

	#region Removals

	#[Test]
	#[TestDox('a delete is captured with no payload')]
	#[Group('strata/capture')]
	public function deleteIsCaptured(): void
	{
		$store = $this->factory->get('demo');
		$store->set('doomed', 'here');
		$this->journal->trim(PHP_INT_MAX);

		$store->delete('doomed');

		$entry = $this->entry('demo:doomed');

		$this->assertNotNull($entry);
		$this->assertSame(Verb::DELETE, $entry['operation']->verb);
		$this->assertNull($entry['payload']);
	}

	#[Test]
	#[TestDox('deleting a key that was never set records nothing')]
	#[Group('strata/capture')]
	public function deleteOfNothingRecordsNothing(): void
	{
		$this->factory->get('demo')->delete('never');

		$this->assertNull($this->entry('demo:never'));
	}

	#[Test]
	#[TestDox('a batch delete records only the keys that were there')]
	#[Group('strata/capture')]
	public function batchDeleteRecordsOnlyPresentKeys(): void
	{
		$store = $this->factory->get('demo');
		$store->set('present', 'here');
		$this->journal->trim(PHP_INT_MAX);

		$store->deleteMultiple(['present', 'absent']);

		$this->assertNotNull($this->entry('demo:present'));
		$this->assertNull($this->entry('demo:absent'));
	}

	#[Test]
	#[TestDox('deleteAll is captured per key, so each one can be put back')]
	#[Group('strata/capture')]
	public function deleteAllIsCapturedPerKey(): void
	{
		$store = $this->factory->get('demo');
		$store->setMultiple(['a' => 1, 'b' => 2]);
		$this->journal->trim(PHP_INT_MAX);

		$store->deleteAll();

		$this->assertSame(Verb::DELETE, $this->entry('demo:a')['operation']->verb);
		$this->assertSame(Verb::DELETE, $this->entry('demo:b')['operation']->verb);
	}

	#[Test]
	#[TestDox('a rename is a write of the new key and a delete of the old')]
	#[Group('strata/capture')]
	public function renameIsCaptured(): void
	{
		$store = $this->factory->get('demo');
		$store->set('before', 'moved');
		$this->journal->trim(PHP_INT_MAX);

		$store->rename('before', 'after');

		$renamed = $this->entry('demo:after');

		$this->assertNotNull($renamed);
		$this->assertSame(Verb::RENAME, $renamed['operation']->verb);
		$this->assertStringContainsString('renamed to', $renamed['operation']->label);
		$this->assertSame('moved', $this->value('demo:after'));
		$this->assertSame(Verb::DELETE, $this->entry('demo:before')['operation']->verb);
	}

	#endregion

	#region Scope And Isolation

	#[Test]
	#[TestDox('two collections holding the same key are two subjects')]
	#[Group('strata/capture')]
	public function collectionsAreSeparateSubjects(): void
	{
		$this->factory->get('one')->set('shared', 'first');
		$this->factory->get('two')->set('shared', 'second');

		$this->assertSame('first', $this->value('one:shared'));
		$this->assertSame('second', $this->value('two:shared'));
	}

	#[Test]
	#[TestDox('this module\'s own collections are handed through unwrapped')]
	#[Group('strata/capture')]
	public function ownCollectionsAreNotWrapped(): void
	{
		$store = $this->factory->get('strata_internal');
		$store->set('thing', 'value');

		$this->assertNotInstanceOf(KeyValueCapture::class, $store);
		$this->assertNull($this->entry('strata_internal:thing'));
	}

	#[Test]
	#[TestDox('the same collection asked for twice is the same wrapper')]
	#[Group('strata/capture')]
	public function collectionsAreCachedPerName(): void
	{
		$this->assertSame($this->factory->get('demo'), $this->factory->get('demo'));
		$this->assertNotSame($this->factory->get('demo'), $this->factory->get('other'));
	}

	#[Test]
	#[TestDox('reads pass through untouched')]
	#[Group('strata/capture')]
	public function readsPassThrough(): void
	{
		$store = $this->factory->get('demo');
		$store->setMultiple(['a' => 1, 'b' => 2]);

		$this->assertSame('demo', $store->getCollectionName());
		$this->assertTrue($store->has('a'));
		$this->assertFalse($store->has('missing'));
		$this->assertSame(1, $store->get('a'));
		$this->assertSame('fallback', $store->get('missing', 'fallback'));
		$this->assertSame(['a' => 1, 'b' => 2], $store->getMultiple(['a', 'b']));
		$this->assertSame(['a' => 1, 'b' => 2], $store->getAll());
		$this->assertSame(['a', 'b'], array_values((array) $store->getAllKeys()));
	}

	#endregion
}
