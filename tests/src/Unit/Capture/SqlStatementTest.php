<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Capture;

use Drupal\strata\Capture\SqlStatement;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqlStatement::class)]
class SqlStatementTest extends TestCase
{
	#region The Guard

	/**
	 * @return array<string, array{string}>
	 */
	public static function readProvider(): array
	{
		return [
			'a select' => [
				'SELECT nid FROM node_field_data WHERE status = :db_condition_placeholder_0',
			],
			'a lowercase select' => ['select 1'],
			'a count' => ['SELECT COUNT(*) AS expression FROM users_field_data'],
			'a savepoint' => ['SAVEPOINT savepoint_1'],
			'a rollback' => ['ROLLBACK TO SAVEPOINT savepoint_1'],
			'a commit' => ['COMMIT'],
			'a begin' => ['BEGIN'],
			'a pragma' => ['PRAGMA foreign_keys = ON'],
			'a set' => ['SET NAMES utf8mb4'],
			'a show' => ['SHOW TABLES'],
			'an explain' => ['EXPLAIN SELECT 1'],
			'a describe' => ['DESCRIBE node'],
			'an empty string' => [''],
			'only whitespace' => ["  \n\t "],
			'only a comment' => ['/* nothing here */'],
			'a word longer than any keyword' => ['SOMETHINGVERYLONGINDEED foo'],
		];
	}

	#[Test]
	#[TestDox('$_dataName is not a write and costs one keyword to decide')]
	#[Group('strata/capture')]
	#[DataProvider('readProvider')]
	public function readsAreNotWrites(string $sql): void
	{
		$this->assertFalse(SqlStatement::isWrite($sql));
		$this->assertNull(SqlStatement::parse($sql));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function writeKeywordProvider(): array
	{
		return [
			'insert' => ['INSERT INTO node (nid) VALUES (:db_insert_placeholder_0)'],
			'update' => ['UPDATE node SET vid = :db_update_placeholder_0'],
			'delete' => ['DELETE FROM node WHERE nid = :db_condition_placeholder_0'],
			'truncate' => ['TRUNCATE node'],
			'create' => ['CREATE TABLE node (nid INT)'],
			'alter' => ['ALTER TABLE node ADD COLUMN extra INT'],
			'drop' => ['DROP TABLE node'],
			'replace' => ['REPLACE INTO node (nid) VALUES (1)'],
		];
	}

	#[Test]
	#[TestDox('an $_dataName passes the guard')]
	#[Group('strata/capture')]
	#[DataProvider('writeKeywordProvider')]
	public function writesPassTheGuard(string $sql): void
	{
		$this->assertTrue(SqlStatement::isWrite($sql));
	}

	#[Test]
	#[TestDox('the guard tolerates leading whitespace and comments')]
	#[Group('strata/capture')]
	public function guardSkipsBlanks(): void
	{
		$this->assertTrue(SqlStatement::isWrite("  \n /* a comment */ UPDATE node SET vid = 1"));
		$this->assertTrue(SqlStatement::isWrite("-- a line comment\nDELETE FROM node"));
		$this->assertFalse(SqlStatement::isWrite("-- a line comment\nSELECT 1"));
	}

	#endregion

	#region Rows

	/**
	 * @return array<string, array{string, Verb, string}>
	 */
	public static function rowProvider(): array
	{
		return [
			'an insert' => [
				'INSERT INTO node_field_data (nid, vid) VALUES (:db_insert_placeholder_0, :db_insert_placeholder_1)',
				Verb::CREATE,
				'node_field_data',
			],
			'an insert from a select' => [
				'INSERT INTO archive_node (nid) SELECT nid FROM node_field_data',
				Verb::CREATE,
				'archive_node',
			],
			'an upsert on mysql' => [
				'INSERT INTO key_value (name, value) VALUES (:a, :b) ON DUPLICATE KEY UPDATE value = :c',
				Verb::CREATE,
				'key_value',
			],
			'an upsert on postgres' => [
				'INSERT INTO key_value (name) VALUES (:a) ON CONFLICT (name) DO UPDATE SET value = :b',
				Verb::CREATE,
				'key_value',
			],
			'an insert or replace on sqlite' => [
				'INSERT OR REPLACE INTO key_value (name) VALUES (:a)',
				Verb::CREATE,
				'key_value',
			],
			'a replace into' => [
				'REPLACE INTO sessions (sid) VALUES (:a)',
				Verb::CREATE,
				'sessions',
			],
			'an update' => [
				'UPDATE users_field_data SET access = :db_update_placeholder_0 WHERE uid = :db_condition_placeholder_0',
				Verb::UPDATE,
				'users_field_data',
			],
			'a low priority update' => [
				'UPDATE LOW_PRIORITY watchdog SET wid = :a',
				Verb::UPDATE,
				'watchdog',
			],
			'a delete' => [
				'DELETE FROM cache_render WHERE cid = :db_condition_placeholder_0',
				Verb::DELETE,
				'cache_render',
			],
			'a truncate' => ['TRUNCATE cache_page', Verb::TRUNCATE, 'cache_page'],
			'a truncate table' => ['TRUNCATE TABLE cache_page', Verb::TRUNCATE, 'cache_page'],
			'a backtick quoted table' => [
				'UPDATE `node_field_data` SET title = :a',
				Verb::UPDATE,
				'node_field_data',
			],
			'a double quoted table' => [
				'UPDATE "node_field_data" SET title = :a',
				Verb::UPDATE,
				'node_field_data',
			],
			'a bracket quoted table' => [
				'UPDATE [node_field_data] SET title = :a',
				Verb::UPDATE,
				'node_field_data',
			],
			'a schema qualified table' => [
				'UPDATE public.node_field_data SET title = :a',
				Verb::UPDATE,
				'node_field_data',
			],
			'a lowercase statement' => [
				'update node_field_data set title = :a',
				Verb::UPDATE,
				'node_field_data',
			],
			'a statement spanning lines' => [
				"INSERT INTO\n  node_field_data\n  (nid)\nVALUES\n  (:a)",
				Verb::CREATE,
				'node_field_data',
			],
			'a prefixed table' => [
				'UPDATE drupal_node_field_data SET title = :a',
				Verb::UPDATE,
				'drupal_node_field_data',
			],
		];
	}

	#[Test]
	#[TestDox('$_dataName is classified by verb and table')]
	#[Group('strata/capture')]
	#[DataProvider('rowProvider')]
	public function rowStatements(string $sql, Verb $verb, string $table): void
	{
		$statement = SqlStatement::parse($sql);

		$this->assertNotNull($statement);
		$this->assertSame($verb, $statement->verb);
		$this->assertSame(Realm::TABLE, $statement->realm);
		$this->assertSame($table, $statement->table);
		$this->assertSame($table, $statement->subject());
		$this->assertFalse($statement->isStructural());
	}

	#endregion

	#region Structure

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function structureProvider(): array
	{
		return [
			'a create table' => ['CREATE TABLE strata_demo (id INT)', 'strata_demo'],
			'a create table if not exists' => ['CREATE TABLE IF NOT EXISTS demo (id INT)', 'demo'],
			'a create temporary table' => ['CREATE TEMPORARY TABLE tmp_demo (id INT)', 'tmp_demo'],
			'a create temp table' => ['CREATE TEMP TABLE tmp_demo (id INT)', 'tmp_demo'],
			'an alter table' => ['ALTER TABLE demo ADD COLUMN extra INT', 'demo'],
			'an alter table rename' => ['ALTER TABLE demo RENAME TO demo_old', 'demo'],
			'a drop table' => ['DROP TABLE demo', 'demo'],
			'a drop table if exists' => ['DROP TABLE IF EXISTS demo', 'demo'],
			'a rename table' => ['RENAME TABLE demo TO demo_old', 'demo'],
			'a create index' => ['CREATE INDEX demo_idx ON demo (id)', 'demo'],
			'a create unique index' => ['CREATE UNIQUE INDEX demo_idx ON demo (id)', 'demo'],
			'a quoted create table' => ['CREATE TABLE "demo" (id INT)', 'demo'],
		];
	}

	#[Test]
	#[TestDox('$_dataName is classified as a schema change on its table')]
	#[Group('strata/capture')]
	#[DataProvider('structureProvider')]
	public function structureStatements(string $sql, string $table): void
	{
		$statement = SqlStatement::parse($sql);

		$this->assertNotNull($statement);
		$this->assertSame(Verb::DDL, $statement->verb);
		$this->assertSame(Realm::SCHEMA, $statement->realm);
		$this->assertSame($table, $statement->table);
		$this->assertTrue($statement->isStructural());
	}

	#endregion

	#region Declining To Guess

	/**
	 * @return array<string, array{string}>
	 */
	public static function unclassifiableProvider(): array
	{
		return [
			'a create view' => ['CREATE VIEW demo_view AS SELECT 1'],
			'a drop view' => ['DROP VIEW demo_view'],
			'a create trigger' => ['CREATE TRIGGER demo_trigger BEFORE INSERT ON demo'],
			'a create sequence' => ['CREATE SEQUENCE demo_seq'],
			'a create database' => ['CREATE DATABASE other'],
			'a drop index with no table' => ['DROP INDEX demo_idx'],
			'an insert naming nothing' => ['INSERT INTO'],
			'an update naming nothing' => ['UPDATE'],
			'a create naming nothing' => ['CREATE'],
			'a create table naming nothing' => ['CREATE TABLE'],
			'a truncate naming nothing' => ['TRUNCATE'],
			'a delete naming nothing' => ['DELETE FROM'],
			'a rename that is not a table' => ['RENAME USER a TO b'],
		];
	}

	#[Test]
	#[TestDox('$_dataName is declined rather than attributed to the wrong subject')]
	#[Group('strata/capture')]
	#[DataProvider('unclassifiableProvider')]
	public function declinesToGuess(string $sql): void
	{
		$this->assertNull(SqlStatement::parse($sql));
	}

	#[Test]
	#[TestDox('a drop index that names its table is attributed to that table')]
	#[Group('strata/capture')]
	public function dropIndexWithTable(): void
	{
		$statement = SqlStatement::parse('DROP INDEX demo_idx ON demo');

		$this->assertNotNull($statement);
		$this->assertSame('demo', $statement->table);
		$this->assertSame(Realm::SCHEMA, $statement->realm);
	}

	#[Test]
	#[TestDox('every write keyword the guard admits is either classified or declined, never fatal')]
	#[Group('strata/capture')]
	public function everyWriteKeywordIsHandled(): void
	{
		foreach (array_keys(SqlStatement::WRITE_KEYWORDS) as $keyword) {
			$this->assertTrue(SqlStatement::isWrite($keyword . ' something'));

			// the point is that parse() answers rather than raising, whichever answer it gives
			SqlStatement::parse($keyword . ' something');
		}

		$this->addToAssertionCount(1);
	}

	#endregion

	#region Table Prefix

	#[Test]
	#[TestDox('the connection prefix comes off the table a statement names')]
	#[Group('strata/capture')]
	public function prefixIsRemoved(): void
	{
		$statement = SqlStatement::parse(
			'INSERT INTO drupal_node_field_data (nid) VALUES (:a)',
			'drupal_',
		);

		$this->assertNotNull($statement);
		$this->assertSame('node_field_data', $statement->table);
		$this->assertSame('node_field_data', $statement->subject());
	}

	#[Test]
	#[TestDox('the prefix comes off a schema change as well as a row change')]
	#[Group('strata/capture')]
	public function prefixIsRemovedFromDdl(): void
	{
		$statement = SqlStatement::parse('ALTER TABLE drupal_demo ADD COLUMN extra INT', 'drupal_');

		$this->assertNotNull($statement);
		$this->assertSame('demo', $statement->table);
		$this->assertSame(Realm::SCHEMA, $statement->realm);
	}

	#[Test]
	#[TestDox("this module's own tables are recognisable again once the prefix is off")]
	#[Group('strata/capture')]
	public function ownTableIsRecognisableAfterStripping(): void
	{
		$statement = SqlStatement::parse(
			'INSERT INTO test123strata_journal (sequence) VALUES (:a)',
			'test123',
		);

		$this->assertNotNull($statement);
		$this->assertStringStartsWith('strata_', $statement->table);
	}

	#[Test]
	#[TestDox('a table that does not carry the prefix is left as it was')]
	#[Group('strata/capture')]
	public function tableWithoutThePrefixIsUntouched(): void
	{
		$statement = SqlStatement::parse('UPDATE other_widget SET a = :b', 'drupal_');

		$this->assertNotNull($statement);
		$this->assertSame('other_widget', $statement->table);
	}

	#[Test]
	#[TestDox('no prefix configured leaves every name exactly as the statement wrote it')]
	#[Group('strata/capture')]
	public function noPrefixLeavesTheNameAlone(): void
	{
		$statement = SqlStatement::parse('UPDATE drupal_node_field_data SET title = :a');

		$this->assertNotNull($statement);
		$this->assertSame('drupal_node_field_data', $statement->table);
	}

	#endregion
}
