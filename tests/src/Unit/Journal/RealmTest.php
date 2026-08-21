<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Journal;

use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use ValueError;

#[CoversClass(Realm::class)]
#[CoversClass(Verb::class)]
class RealmTest extends TestCase
{
	#region Realms

	/**
	 * @return array<string, array{Realm, string, string, bool}>
	 */
	public static function realmProvider(): array
	{
		return [
			'entity' => [Realm::ENTITY, 'entity', 'Entity', true],
			'config' => [Realm::CONFIG, 'config', 'Configuration', true],
			'state' => [Realm::STATE, 'state', 'State', true],
			'key-value' => [Realm::KEY_VALUE, 'keyvalue', 'Key-Value', true],
			'table' => [Realm::TABLE, 'table', 'Table', true],
			'schema' => [Realm::SCHEMA, 'schema', 'Schema', true],
			'file' => [Realm::FILE, 'file', 'File', true],
			'ephemeral' => [Realm::EPHEMERAL, 'ephemeral', 'Ephemeral', false],
			'code' => [Realm::CODE, 'code', 'Code', true],
		];
	}

	#[Test]
	#[TestDox('the $_dataName realm carries its stored value, its label and its restore posture')]
	#[Group('strata/journal')]
	#[DataProvider('realmProvider')]
	public function realmCarriesValueLabelAndPosture(
		Realm $realm,
		string $value,
		string $label,
		bool $restorable,
	): void {
		$this->assertSame($value, $realm->value);
		$this->assertSame($realm, Realm::from($value));
		$this->assertSame($label, $realm->label());
		$this->assertMatchesRegularExpression('/^[A-Z]/', $label);
		$this->assertSame($restorable, $realm->isRestorable());
	}

	#[Test]
	#[TestDox('every realm case is covered by the label table')]
	#[Group('strata/journal')]
	public function everyRealmHasLabel(): void
	{
		$this->assertCount(count(self::realmProvider()), Realm::cases());

		foreach (Realm::cases() as $realm) {
			$this->assertNotSame('', $realm->label());
		}
	}

	#[Test]
	#[TestDox('ephemeral is the only realm a default restore skips')]
	#[Group('strata/journal')]
	public function onlyEphemeralIsUnrestorable(): void
	{
		$skipped = array_values(
			array_filter(Realm::cases(), static fn(Realm $realm): bool => !$realm->isRestorable()),
		);

		$this->assertSame([Realm::EPHEMERAL], $skipped);
	}

	#[Test]
	#[TestDox('a realm value this version does not know is refused rather than guessed')]
	#[Group('strata/journal')]
	public function unknownRealmValueIsRefused(): void
	{
		$this->expectException(ValueError::class);

		Realm::from('nonsense');
	}

	#endregion

	#region Verbs

	/**
	 * @return array<string, array{Verb, string, string, bool}>
	 */
	public static function verbProvider(): array
	{
		return [
			'create' => [Verb::CREATE, 'create', 'Create', false],
			'update' => [Verb::UPDATE, 'update', 'Update', false],
			'delete' => [Verb::DELETE, 'delete', 'Delete', true],
			'rename' => [Verb::RENAME, 'rename', 'Rename', false],
			'truncate' => [Verb::TRUNCATE, 'truncate', 'Truncate', true],
			'ddl' => [Verb::DDL, 'ddl', 'DDL', true],
		];
	}

	#[Test]
	#[TestDox('the $_dataName verb carries its stored value, its label and its blast radius')]
	#[Group('strata/journal')]
	#[DataProvider('verbProvider')]
	public function verbCarriesValueLabelAndRadius(
		Verb $verb,
		string $value,
		string $label,
		bool $destructive,
	): void {
		$this->assertSame($value, $verb->value);
		$this->assertSame($verb, Verb::from($value));
		$this->assertSame($label, $verb->label());
		$this->assertMatchesRegularExpression('/^[A-Z]/', $label);
		$this->assertSame($destructive, $verb->isDestructive());
	}

	#[Test]
	#[TestDox('every verb case is covered by the label table')]
	#[Group('strata/journal')]
	public function everyVerbHasLabel(): void
	{
		$this->assertCount(count(self::verbProvider()), Verb::cases());

		foreach (Verb::cases() as $verb) {
			$this->assertNotSame('', $verb->label());
		}
	}

	#[Test]
	#[TestDox('delete, truncate and ddl are the destructive verbs')]
	#[Group('strata/journal')]
	public function destructiveVerbsAreTheRemovingOnes(): void
	{
		$destructive = array_values(
			array_filter(Verb::cases(), static fn(Verb $verb): bool => $verb->isDestructive()),
		);

		$this->assertSame([Verb::DELETE, Verb::TRUNCATE, Verb::DDL], $destructive);
	}

	#[Test]
	#[TestDox('a verb value this version does not know is refused rather than guessed')]
	#[Group('strata/journal')]
	public function unknownVerbValueIsRefused(): void
	{
		$this->expectException(ValueError::class);

		Verb::from('nonsense');
	}

	#endregion
}
