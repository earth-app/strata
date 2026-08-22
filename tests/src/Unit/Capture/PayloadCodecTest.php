<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Capture;

use Drupal\strata\Capture\PayloadCodec;
use Drupal\strata\Journal\Realm;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(PayloadCodec::class)]
class PayloadCodecTest extends TestCase
{
	#region Which Realms Serialize

	/**
	 * @return array<string, array{Realm, bool}>
	 */
	public static function realmProvider(): array
	{
		return [
			'state' => [Realm::STATE, true],
			'key-value' => [Realm::KEY_VALUE, true],
			'entity' => [Realm::ENTITY, false],
			'config' => [Realm::CONFIG, false],
			'table' => [Realm::TABLE, false],
			'schema' => [Realm::SCHEMA, false],
			'file' => [Realm::FILE, false],
			'code' => [Realm::CODE, false],
			'ephemeral' => [Realm::EPHEMERAL, false],
		];
	}

	#[Test]
	#[TestDox('$_dataName encodes the way its shape requires')]
	#[Group('strata/capture')]
	#[DataProvider('realmProvider')]
	public function realmEncoding(Realm $realm, bool $serialized): void
	{
		$this->assertSame($serialized, PayloadCodec::isSerialized($realm));
	}

	#[Test]
	#[TestDox('every realm is decided, so a new one cannot default silently')]
	#[Group('strata/capture')]
	public function everyRealmIsDecided(): void
	{
		$this->assertCount(count(Realm::cases()), self::realmProvider());
	}

	#endregion

	#region Field Maps

	#[Test]
	#[TestDox('a field map round trips through json')]
	#[Group('strata/capture')]
	public function fieldMapRoundTrips(): void
	{
		$fields = ['title' => 'Hello', 'status' => true, 'weight' => 3];
		$payload = PayloadCodec::encode(Realm::CONFIG, $fields);

		$this->assertSame($fields, PayloadCodec::decode(Realm::CONFIG, $payload));
	}

	#[Test]
	#[TestDox('a payload that is not a field map is refused rather than read as empty')]
	#[Group('strata/capture')]
	public function unparseableFieldMapIsRefused(): void
	{
		$this->assertNull(PayloadCodec::decode(Realm::CONFIG, 'not json'));
		$this->assertNull(PayloadCodec::decode(Realm::CONFIG, '"a bare string"'));
		$this->assertNull(PayloadCodec::decode(Realm::CONFIG, '42'));
	}

	#endregion

	#region Arbitrary Values

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function valueProvider(): array
	{
		return [
			'an integer' => [41],
			'a float' => [1.5],
			'a string' => ['a value'],
			'a boolean true' => [true],
			'a boolean false' => [false],
			'null' => [null],
			'an empty array' => [[]],
			'a list' => [[1, 2, 3]],
			'a nested map' => [['a' => ['b' => ['c' => 1]]]],
			'an integer-keyed map' => [[5 => 'five', 9 => 'nine']],
			'a string holding serialized data' => ['b:0;'],
		];
	}

	#[Test]
	#[TestDox('$_dataName survives a state round trip with its type intact')]
	#[Group('strata/capture')]
	#[DataProvider('valueProvider')]
	public function valueRoundTrips(mixed $value): void
	{
		$decoded = PayloadCodec::decode(Realm::STATE, PayloadCodec::encode(Realm::STATE, $value));

		$this->assertNotNull($decoded);
		$this->assertArrayHasKey(PayloadCodec::VALUE, $decoded);
		$this->assertSame($value, $decoded[PayloadCodec::VALUE]);
	}

	#[Test]
	#[TestDox('json would silently retype an object where serializing detects it')]
	#[Group('strata/capture')]
	public function jsonWouldRetypeAnObject(): void
	{
		$object = new stdClass();
		$object->field = 'value';

		// what json does: an object becomes an array, and nothing downstream can tell
		$this->assertSame(['field' => 'value'], json_decode((string) json_encode($object), true));

		// what this codec does: the change of type is visible, so the value is refused
		$this->assertNull(
			PayloadCodec::decode(Realm::STATE, PayloadCodec::encode(Realm::STATE, $object)),
		);
	}

	#endregion

	#region Refusing Objects

	#[Test]
	#[TestDox('a stored object is refused rather than reconstructed')]
	#[Group('strata/capture')]
	public function objectsAreRefused(): void
	{
		$object = new stdClass();
		$object->field = 'value';

		$this->assertNull(
			PayloadCodec::decode(Realm::STATE, PayloadCodec::encode(Realm::STATE, $object)),
		);
	}

	#[Test]
	#[TestDox('an object nested inside an array is refused too')]
	#[Group('strata/capture')]
	public function nestedObjectsAreRefused(): void
	{
		$value = ['outer' => ['inner' => new stdClass()]];

		$this->assertNull(
			PayloadCodec::decode(Realm::STATE, PayloadCodec::encode(Realm::STATE, $value)),
		);
	}

	#[Test]
	#[TestDox('a value nested past the guard depth is refused rather than walked forever')]
	#[Group('strata/capture')]
	public function deepNestingIsRefused(): void
	{
		$value = 'bottom';

		for ($i = 0; $i < 40; $i++) {
			$value = [$value];
		}

		$this->assertNull(
			PayloadCodec::decode(Realm::STATE, PayloadCodec::encode(Realm::STATE, $value)),
		);
	}

	#[Test]
	#[TestDox('a value nested within the guard depth still decodes')]
	#[Group('strata/capture')]
	public function shallowNestingDecodes(): void
	{
		$value = 'bottom';

		for ($i = 0; $i < 8; $i++) {
			$value = [$value];
		}

		$this->assertNotNull(
			PayloadCodec::decode(Realm::STATE, PayloadCodec::encode(Realm::STATE, $value)),
		);
	}

	#endregion

	#region Corruption

	#[Test]
	#[TestDox('an empty payload decodes to nothing')]
	#[Group('strata/capture')]
	public function emptyPayloadIsRefused(): void
	{
		$this->assertNull(PayloadCodec::decode(Realm::STATE, ''));
	}

	#[Test]
	#[TestDox('a corrupt serialized payload is refused rather than read as false')]
	#[Group('strata/capture')]
	public function corruptPayloadIsRefused(): void
	{
		$this->assertNull(PayloadCodec::decode(Realm::STATE, 'a:1:{truncated'));
		$this->assertNull(PayloadCodec::decode(Realm::STATE, 'not serialized at all'));
	}

	#endregion

	#region Bytes JSON Cannot Carry

	#[Test]
	#[TestDox('a json realm refuses a value that is not valid utf-8 rather than encoding nothing')]
	#[Group('strata/capture')]
	public function unencodableValueIsRefused(): void
	{
		// (string) json_encode() is '', which decodes to NULL, so the op would claim a value it lost
		$this->expectException(JsonException::class);

		PayloadCodec::encode(Realm::CONFIG, ['blob' => "\xC3\x28"]);
	}

	#[Test]
	#[TestDox('a serialized realm carries arbitrary binary, which is why state does not use json')]
	#[Group('strata/capture')]
	public function serializedRealmCarriesBinary(): void
	{
		$value = "\x00\xFF\xFE binary \xC3\x28";
		$decoded = PayloadCodec::decode(Realm::STATE, PayloadCodec::encode(Realm::STATE, $value));

		$this->assertNotNull($decoded);
		$this->assertSame($value, $decoded[PayloadCodec::VALUE]);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function textProvider(): array
	{
		return [
			'a null byte' => ["a\x00b"],
			'a newline' => ["a\nb"],
			'an emoji' => ["\u{1F600}"],
			'a right-to-left override' => ["\u{202E}" . 'txt.exe'],
			'a byte order mark' => ["\u{FEFF}" . 'value'],
			'a four-byte character' => ["\u{2A6B2}"],
			'an empty string' => [''],
		];
	}

	#[Test]
	#[TestDox('a field map holding $_dataName round trips through a json realm')]
	#[Group('strata/capture')]
	#[DataProvider('textProvider')]
	public function representableTextRoundTrips(string $text): void
	{
		$fields = ['field' => $text];

		$this->assertSame(
			$fields,
			PayloadCodec::decode(Realm::CONFIG, PayloadCodec::encode(Realm::CONFIG, $fields)),
		);
	}

	#endregion
}
