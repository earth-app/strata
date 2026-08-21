<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Journal;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use ValueError;

#[CoversClass(JournalOp::class)]
class JournalOpTest extends TestCase
{
	#region Invariants

	#[Test]
	#[TestDox('an op keeps every field it was handed')]
	#[Group('strata/journal')]
	public function opKeepsEveryField(): void
	{
		$payload = Hash::of('after');
		$parent = Hash::of('before');

		$op = new JournalOp(
			7,
			1700000000123456,
			Realm::ENTITY,
			'node:42',
			Verb::UPDATE,
			3,
			'req-abc',
			$payload,
			$parent,
			512,
			'Article: Hello world',
			['title', 'body'],
		);

		$this->assertSame(7, $op->sequence);
		$this->assertSame(1700000000123456, $op->microtime);
		$this->assertSame(Realm::ENTITY, $op->realm);
		$this->assertSame('node:42', $op->subject);
		$this->assertSame(Verb::UPDATE, $op->verb);
		$this->assertSame(3, $op->actor);
		$this->assertSame('req-abc', $op->requestId);
		$this->assertSame($payload, $op->payloadHash);
		$this->assertSame($parent, $op->parentHash);
		$this->assertSame(512, $op->payloadLength);
		$this->assertSame('Article: Hello world', $op->label);
		$this->assertSame(['title', 'body'], $op->fields);
	}

	#[Test]
	#[TestDox('everything past the verb is optional and defaults to nothing recorded')]
	#[Group('strata/journal')]
	public function optionalFieldsDefaultToNothingRecorded(): void
	{
		$op = new JournalOp(0, 1, Realm::STATE, 'system.cron_last', Verb::CREATE);

		$this->assertNull($op->actor);
		$this->assertNull($op->requestId);
		$this->assertNull($op->payloadHash);
		$this->assertNull($op->parentHash);
		$this->assertSame(0, $op->payloadLength);
		$this->assertSame('', $op->label);
		$this->assertSame([], $op->fields);
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function refusedProvider(): array
	{
		return [
			'a negative sequence' => [['sequence' => -1], 'sequence cannot be negative'],
			'a zero microtime' => [['microtime' => 0], 'must be a positive timestamp'],
			'a negative microtime' => [['microtime' => -1], 'must be a positive timestamp'],
			'an empty subject' => [['subject' => ''], 'must name its subject'],
			'a negative payload length' => [
				['payloadLength' => -1],
				'payload length cannot be negative',
			],
			'a truncated payload hash' => [
				['payloadHash' => 'abc'],
				'payload hash must be a valid digest',
			],
			'an uppercase payload hash' => [
				['payloadHash' => strtoupper(Hash::of('after'))],
				'payload hash must be a valid digest',
			],
			'a parent hash that is not a digest' => [
				['parentHash' => 'not-a-digest'],
				'parent hash must be a valid digest',
			],
			'a keyed fields array' => [
				['fields' => ['title' => 'Title']],
				'must be a list of field names',
			],
			'a field name that is not a string' => [
				['fields' => ['title', 42]],
				'must be a list of field names',
			],
		];
	}

	#[Test]
	#[TestDox('$_dataName is refused at construction rather than stored')]
	#[Group('strata/journal')]
	#[DataProvider('refusedProvider')]
	public function refusesIncoherentValues(array $overrides, string $message): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		self::build($overrides);
	}

	#[Test]
	#[TestDox('sequence zero and a one-microsecond timestamp are both inside the range')]
	#[Group('strata/journal')]
	public function acceptsTheLowestValidNumbers(): void
	{
		$op = self::build(['sequence' => 0, 'microtime' => 1]);

		$this->assertSame(0, $op->sequence);
		$this->assertSame(1, $op->microtime);
	}

	#endregion

	#region Identity

	/**
	 * @return array<string, array{Realm, string, string}>
	 */
	public static function keyProvider(): array
	{
		return [
			'an entity' => [Realm::ENTITY, 'node:42', 'entity:node:42'],
			'a config object' => [Realm::CONFIG, 'user.settings', 'config:user.settings'],
			'a state key' => [Realm::STATE, 'system.cron_last', 'state:system.cron_last'],
			'a table row' => [
				Realm::TABLE,
				'mantle2_api_keys:pk=17',
				'table:mantle2_api_keys:pk=17',
			],
			'a key-value pair' => [Realm::KEY_VALUE, 'strata.window:7', 'keyvalue:strata.window:7'],
		];
	}

	#[Test]
	#[TestDox('the key of $_dataName is its realm and its subject')]
	#[Group('strata/journal')]
	#[DataProvider('keyProvider')]
	public function keyIsRealmQualified(Realm $realm, string $subject, string $expected): void
	{
		$op = self::build(['realm' => $realm, 'subject' => $subject]);

		$this->assertSame($expected, $op->key());
	}

	#[Test]
	#[TestDox('one subject in two realms is two keys')]
	#[Group('strata/journal')]
	public function keySeparatesRealms(): void
	{
		$config = self::build(['realm' => Realm::CONFIG, 'subject' => 'strata.settings']);
		$state = self::build(['realm' => Realm::STATE, 'subject' => 'strata.settings']);

		$this->assertNotSame($config->key(), $state->key());
	}

	#[Test]
	#[TestDox('timestamp() converts stored microseconds to a float unix timestamp')]
	#[Group('strata/journal')]
	public function timestampConvertsMicroseconds(): void
	{
		$this->assertSame(1.5, self::build(['microtime' => 1500000])->timestamp());
		$this->assertEqualsWithDelta(
			1700000000.123456,
			self::build(['microtime' => 1700000000123456])->timestamp(),
			0.000001,
		);
	}

	/**
	 * @return array<string, array{Verb, bool}>
	 */
	public static function destructiveProvider(): array
	{
		return [
			'create' => [Verb::CREATE, false],
			'update' => [Verb::UPDATE, false],
			'delete' => [Verb::DELETE, true],
			'rename' => [Verb::RENAME, false],
			'truncate' => [Verb::TRUNCATE, true],
			'ddl' => [Verb::DDL, true],
		];
	}

	#[Test]
	#[TestDox('an op reports the blast radius of its verb, here $_dataName')]
	#[Group('strata/journal')]
	#[DataProvider('destructiveProvider')]
	public function isDestructiveFollowsTheVerb(Verb $verb, bool $destructive): void
	{
		$this->assertSame($destructive, self::build(['verb' => $verb])->isDestructive());
	}

	#endregion

	#region Derivation

	#[Test]
	#[TestDox('withSequence() renumbers a copy and leaves the original alone')]
	#[Group('strata/journal')]
	public function withSequenceCopiesTheOp(): void
	{
		$op = self::build([
			'sequence' => 3,
			'payloadHash' => Hash::of('after'),
			'fields' => ['title'],
		]);
		$renumbered = $op->withSequence(9);

		$expected = $op->jsonSerialize();
		$expected['sequence'] = 9;

		$this->assertSame(3, $op->sequence);
		$this->assertSame(9, $renumbered->sequence);
		$this->assertSame($expected, $renumbered->jsonSerialize());
	}

	#[Test]
	#[TestDox('withSequence() refuses a negative sequence, like the constructor')]
	#[Group('strata/journal')]
	public function withSequenceRefusesNegative(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('sequence cannot be negative');

		self::build()->withSequence(-1);
	}

	#endregion

	#region Serialization

	#[Test]
	#[TestDox('the serialized form reduces the realm and the verb to their case values')]
	#[Group('strata/journal')]
	public function serializationReducesEnumsToValues(): void
	{
		$data = self::build(['realm' => Realm::KEY_VALUE, 'verb' => Verb::DDL])->jsonSerialize();

		$this->assertSame('keyvalue', $data['realm']);
		$this->assertSame('ddl', $data['verb']);
	}

	#[Test]
	#[TestDox('fromArray(jsonSerialize()) is the identity for a fully populated op')]
	#[Group('strata/journal')]
	public function fullOpRoundTripsThroughJson(): void
	{
		$op = self::build([
			'sequence' => 12,
			'microtime' => 1700000000123456,
			'realm' => Realm::TABLE,
			'subject' => 'mantle2_api_keys:pk=17',
			'verb' => Verb::DELETE,
			'actor' => 41,
			'requestId' => 'req-xyz',
			'payloadHash' => Hash::of('after'),
			'parentHash' => Hash::of('before'),
			'payloadLength' => 4096,
			'label' => 'API key 17',
			'fields' => ['label', 'expires'],
		]);
		$rebuilt = JournalOp::fromArray($op->jsonSerialize());

		$this->assertEquals($op, $rebuilt);
		$this->assertSame(Realm::TABLE, $rebuilt->realm);
		$this->assertSame(Verb::DELETE, $rebuilt->verb);
	}

	#[Test]
	#[TestDox('fromArray(jsonSerialize()) is the identity for an op with nothing optional set')]
	#[Group('strata/journal')]
	public function minimalOpRoundTripsThroughJson(): void
	{
		$op = new JournalOp(0, 1, Realm::EPHEMERAL, 'cache:render', Verb::TRUNCATE);

		$this->assertEquals($op, JournalOp::fromArray($op->jsonSerialize()));
	}

	#[Test]
	#[TestDox('an op survives a real json_encode round trip with its fields still a list')]
	#[Group('strata/journal')]
	public function opSurvivesJsonEncode(): void
	{
		$op = self::build(['fields' => ['title', 'body']]);

		$encoded = json_encode($op);
		$this->assertIsString($encoded);
		$this->assertStringContainsString('"fields":["title","body"]', $encoded);

		$rebuilt = JournalOp::fromArray((array) json_decode($encoded, true));
		$this->assertEquals($op, $rebuilt);
	}

	#[Test]
	#[TestDox('uid zero is an actor and does not decay to nobody on the way back')]
	#[Group('strata/journal')]
	public function anonymousActorSurvivesTheRoundTrip(): void
	{
		$rebuilt = JournalOp::fromArray(self::build(['actor' => 0])->jsonSerialize());

		$this->assertSame(0, $rebuilt->actor);
	}

	#[Test]
	#[TestDox('fromArray() reads the numbers a database row hands back as strings')]
	#[Group('strata/journal')]
	public function fromArrayCastsRowStrings(): void
	{
		$op = JournalOp::fromArray([
			'sequence' => '12',
			'microtime' => '1700000000123456',
			'realm' => 'config',
			'subject' => 'user.settings',
			'verb' => 'update',
			'actor' => '41',
			'payloadLength' => '4096',
		]);

		$this->assertSame(12, $op->sequence);
		$this->assertSame(1700000000123456, $op->microtime);
		$this->assertSame(41, $op->actor);
		$this->assertSame(4096, $op->payloadLength);
	}

	#[Test]
	#[TestDox('fromArray() defaults every field a row written before it existed would not carry')]
	#[Group('strata/journal')]
	public function fromArrayDefaultsWhatIsAbsent(): void
	{
		$op = JournalOp::fromArray([
			'sequence' => 1,
			'microtime' => 1000,
			'realm' => 'code',
			'subject' => 'strata',
			'verb' => 'create',
		]);

		$this->assertNull($op->actor);
		$this->assertNull($op->requestId);
		$this->assertNull($op->payloadHash);
		$this->assertNull($op->parentHash);
		$this->assertSame(0, $op->payloadLength);
		$this->assertSame('', $op->label);
		$this->assertSame([], $op->fields);
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function missingKeyProvider(): array
	{
		$complete = [
			'sequence' => 1,
			'microtime' => 1000,
			'realm' => 'entity',
			'subject' => 'node:42',
			'verb' => 'update',
		];

		$sets = [];
		foreach (array_keys($complete) as $key) {
			$partial = $complete;
			unset($partial[$key]);
			$sets[$key] = [$partial];
		}

		return $sets;
	}

	#[Test]
	#[TestDox('fromArray() refuses a row with no $_dataName instead of inventing one')]
	#[Group('strata/journal')]
	#[DataProvider('missingKeyProvider')]
	public function fromArrayRefusesMissingKeys(array $data): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Journal op is missing');

		JournalOp::fromArray($data);
	}

	#[Test]
	#[TestDox('fromArray() refuses a realm this version does not know')]
	#[Group('strata/journal')]
	public function fromArrayRefusesUnknownRealm(): void
	{
		$this->expectException(ValueError::class);

		JournalOp::fromArray([
			'sequence' => 1,
			'microtime' => 1000,
			'realm' => 'nonsense',
			'subject' => 'node:42',
			'verb' => 'update',
		]);
	}

	#[Test]
	#[TestDox('fromArray() refuses a verb this version does not know')]
	#[Group('strata/journal')]
	public function fromArrayRefusesUnknownVerb(): void
	{
		$this->expectException(ValueError::class);

		JournalOp::fromArray([
			'sequence' => 1,
			'microtime' => 1000,
			'realm' => 'entity',
			'subject' => 'node:42',
			'verb' => 'nonsense',
		]);
	}

	#endregion

	#region Helpers

	private static function build(array $overrides = []): JournalOp
	{
		$values = array_merge(
			[
				'sequence' => 1,
				'microtime' => 1000,
				'realm' => Realm::ENTITY,
				'subject' => 'node:42',
				'verb' => Verb::UPDATE,
				'actor' => 3,
				'requestId' => 'req-abc',
				'payloadHash' => null,
				'parentHash' => null,
				'payloadLength' => 0,
				'label' => '',
				'fields' => [],
			],
			$overrides,
		);

		return new JournalOp(
			$values['sequence'],
			$values['microtime'],
			$values['realm'],
			$values['subject'],
			$values['verb'],
			$values['actor'],
			$values['requestId'],
			$values['payloadHash'],
			$values['parentHash'],
			$values['payloadLength'],
			$values['label'],
			$values['fields'],
		);
	}

	#endregion
}
