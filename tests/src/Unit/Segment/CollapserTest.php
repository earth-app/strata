<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Segment;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;
use Drupal\strata\Segment\Collapser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Collapser::class)]
class CollapserTest extends TestCase
{
	#region The Fold Table

	/**
	 * @return array<string, array{Verb, Verb, list<Verb>}>
	 */
	public static function pairProvider(): array
	{
		return [
			'create then update is a create' => [Verb::CREATE, Verb::UPDATE, [Verb::CREATE]],
			'create then delete is nothing' => [Verb::CREATE, Verb::DELETE, []],
			'create then create is a create' => [Verb::CREATE, Verb::CREATE, [Verb::CREATE]],
			'update then update is an update' => [Verb::UPDATE, Verb::UPDATE, [Verb::UPDATE]],
			'update then delete is a delete' => [Verb::UPDATE, Verb::DELETE, [Verb::DELETE]],
			'update then create is an update' => [Verb::UPDATE, Verb::CREATE, [Verb::UPDATE]],
			'delete then create is an update' => [Verb::DELETE, Verb::CREATE, [Verb::UPDATE]],
			'delete then update is an update' => [Verb::DELETE, Verb::UPDATE, [Verb::UPDATE]],
			'delete then delete is a delete' => [Verb::DELETE, Verb::DELETE, [Verb::DELETE]],
		];
	}

	#[Test]
	#[TestDox('on one subject, $_dataName')]
	#[Group('strata/segment')]
	#[DataProvider('pairProvider')]
	public function foldsPairsToTheirNetEffect(Verb $earlier, Verb $later, array $expected): void
	{
		$folded = Collapser::collapse([self::op(1, $earlier), self::op(2, $later)]);

		$this->assertSame($expected, self::verbs($folded));
	}

	#[Test]
	#[TestDox('a subject created and deleted inside the window leaves nothing behind')]
	#[Group('strata/segment')]
	public function createThenDeleteLeavesNothing(): void
	{
		$folded = Collapser::collapse([
			self::op(1, Verb::CREATE, 'node:1'),
			self::op(2, Verb::CREATE, 'node:2'),
			self::op(3, Verb::DELETE, 'node:1'),
		]);

		$this->assertSame(['node:2'], self::subjects($folded));
	}

	#[Test]
	#[TestDox('a create after a dropped pair opens a new run rather than joining the old one')]
	#[Group('strata/segment')]
	public function createAfterDroppedPairStartsNewRun(): void
	{
		$folded = Collapser::collapse([
			self::op(1, Verb::CREATE),
			self::op(2, Verb::DELETE),
			self::op(3, Verb::CREATE, payloadHash: Hash::of('third')),
		]);

		$this->assertSame([Verb::CREATE], self::verbs($folded));
		$this->assertSame([3], self::sequences($folded));
		$this->assertSame(Hash::of('third'), $folded[0]->payloadHash);
	}

	#endregion

	#region Survivor Fields

	#[Test]
	#[TestDox('a survivor sits at the earliest position and carries the latest value')]
	#[Group('strata/segment')]
	public function survivorKeepsEarliestPositionAndLatestValue(): void
	{
		$folded = Collapser::collapse([
			self::op(
				5,
				Verb::UPDATE,
				payloadHash: Hash::of('v2'),
				payloadLength: 10,
				label: 'first',
			),
			self::op(
				9,
				Verb::UPDATE,
				payloadHash: Hash::of('v3'),
				payloadLength: 30,
				label: 'second',
			),
		]);

		$this->assertCount(1, $folded);
		$this->assertSame(5, $folded[0]->sequence);
		$this->assertSame(6000, $folded[0]->microtime);
		$this->assertSame(Hash::of('v3'), $folded[0]->payloadHash);
		$this->assertSame(30, $folded[0]->payloadLength);
		$this->assertSame('second', $folded[0]->label);
	}

	#[Test]
	#[TestDox('a survivor points at the version before the window, not at an intermediate')]
	#[Group('strata/segment')]
	public function survivorParentIsTheStateBeforeTheWindow(): void
	{
		$folded = Collapser::collapse([
			self::op(1, Verb::UPDATE, payloadHash: Hash::of('v2'), parentHash: Hash::of('v1')),
			self::op(2, Verb::UPDATE, payloadHash: Hash::of('v3'), parentHash: Hash::of('v2')),
			self::op(3, Verb::UPDATE, payloadHash: Hash::of('v4'), parentHash: Hash::of('v3')),
		]);

		$this->assertCount(1, $folded);
		$this->assertSame(Hash::of('v1'), $folded[0]->parentHash);
		$this->assertSame(Hash::of('v4'), $folded[0]->payloadHash);
	}

	#[Test]
	#[TestDox('a survivor is attributed to the request that wrote the value it carries')]
	#[Group('strata/segment')]
	public function survivorIsAttributedToTheLastWriter(): void
	{
		$folded = Collapser::collapse([
			self::op(1, Verb::UPDATE, actor: 3, requestId: 'req-one'),
			self::op(2, Verb::DELETE, actor: 4, requestId: 'req-two'),
		]);

		$this->assertCount(1, $folded);
		$this->assertSame(Verb::DELETE, $folded[0]->verb);
		$this->assertSame(4, $folded[0]->actor);
		$this->assertSame('req-two', $folded[0]->requestId);
	}

	#[Test]
	#[TestDox('a run of updates carries the union of every field it touched')]
	#[Group('strata/segment')]
	public function runOfUpdatesUnionsFields(): void
	{
		$folded = Collapser::collapse([
			self::op(1, Verb::UPDATE, fields: ['title', 'body']),
			self::op(2, Verb::UPDATE, fields: ['body', 'status']),
			self::op(3, Verb::UPDATE, fields: ['sticky']),
		]);

		$this->assertCount(1, $folded);
		$this->assertSame(['title', 'body', 'status', 'sticky'], $folded[0]->fields);
	}

	#[Test]
	#[TestDox('a create that absorbs an update takes the fields of the update')]
	#[Group('strata/segment')]
	public function createTakesTheFieldsOfTheUpdate(): void
	{
		$folded = Collapser::collapse([
			self::op(1, Verb::CREATE, fields: ['title', 'body']),
			self::op(2, Verb::UPDATE, fields: ['status']),
		]);

		$this->assertCount(1, $folded);
		$this->assertSame(Verb::CREATE, $folded[0]->verb);
		$this->assertSame(['status'], $folded[0]->fields);
	}

	#endregion

	#region Ops That Never Fold

	/**
	 * @return array<string, array{Verb}>
	 */
	public static function verbatimVerbProvider(): array
	{
		return [
			'a rename' => [Verb::RENAME],
			'a truncate' => [Verb::TRUNCATE],
			'a ddl' => [Verb::DDL],
		];
	}

	#[Test]
	#[TestDox('$_dataName is kept verbatim and closes the run in front of it')]
	#[Group('strata/segment')]
	#[DataProvider('verbatimVerbProvider')]
	public function verbatimVerbsCloseTheRun(Verb $verb): void
	{
		$folded = Collapser::collapse([
			self::op(1, Verb::UPDATE, realm: Realm::TABLE, subject: 'strata_journal:pk=1'),
			self::op(2, $verb, realm: Realm::TABLE, subject: 'strata_journal:pk=1'),
			self::op(3, Verb::UPDATE, realm: Realm::TABLE, subject: 'strata_journal:pk=1'),
		]);

		$this->assertSame([Verb::UPDATE, $verb, Verb::UPDATE], self::verbs($folded));
		$this->assertSame([1, 2, 3], self::sequences($folded));
	}

	#[Test]
	#[TestDox('two ddl statements on one table are two ops, because their order is the schema')]
	#[Group('strata/segment')]
	public function consecutiveDdlOpsBothSurvive(): void
	{
		$folded = Collapser::collapse([
			self::op(1, Verb::DDL, realm: Realm::SCHEMA, subject: 'node_field_data'),
			self::op(2, Verb::DDL, realm: Realm::SCHEMA, subject: 'node_field_data'),
		]);

		$this->assertSame([Verb::DDL, Verb::DDL], self::verbs($folded));
		$this->assertSame([1, 2], self::sequences($folded));
	}

	/**
	 * @return array<string, array{Realm, string}>
	 */
	public static function verbatimRealmProvider(): array
	{
		return [
			'the schema realm' => [Realm::SCHEMA, 'node_field_data'],
			'the file realm' => [Realm::FILE, 'public://cat.jpg'],
		];
	}

	#[Test]
	#[TestDox('two updates in $_dataName stay two ops')]
	#[Group('strata/segment')]
	#[DataProvider('verbatimRealmProvider')]
	public function verbatimRealmsNeverFold(Realm $realm, string $subject): void
	{
		$folded = Collapser::collapse([
			self::op(1, Verb::UPDATE, $subject, $realm, payloadHash: Hash::of('v1')),
			self::op(2, Verb::UPDATE, $subject, $realm, payloadHash: Hash::of('v2')),
		]);

		$this->assertSame([Verb::UPDATE, Verb::UPDATE], self::verbs($folded));
		$this->assertSame(
			[Hash::of('v1'), Hash::of('v2')],
			[$folded[0]->payloadHash, $folded[1]->payloadHash],
		);
	}

	#[Test]
	#[TestDox('ephemeral state folds like any other realm, since only two realms are exempt')]
	#[Group('strata/segment')]
	public function ephemeralRealmStillFolds(): void
	{
		$folded = Collapser::collapse([
			self::op(1, Verb::UPDATE, 'cache_render:node:42', Realm::EPHEMERAL),
			self::op(2, Verb::UPDATE, 'cache_render:node:42', Realm::EPHEMERAL),
		]);

		$this->assertSame([Verb::UPDATE], self::verbs($folded));
	}

	/**
	 * @return array<string, array{JournalOp, bool}>
	 */
	public static function foldableProvider(): array
	{
		return [
			'an entity create' => [self::op(1, Verb::CREATE), true],
			'an entity update' => [self::op(1, Verb::UPDATE), true],
			'an entity delete' => [self::op(1, Verb::DELETE), true],
			'an ephemeral update' => [self::op(1, Verb::UPDATE, realm: Realm::EPHEMERAL), true],
			'a rename' => [self::op(1, Verb::RENAME), false],
			'a truncate' => [self::op(1, Verb::TRUNCATE), false],
			'a ddl' => [self::op(1, Verb::DDL), false],
			'a schema update' => [self::op(1, Verb::UPDATE, realm: Realm::SCHEMA), false],
			'a file update' => [self::op(1, Verb::UPDATE, realm: Realm::FILE), false],
		];
	}

	#[Test]
	#[TestDox('$_dataName is reported as taking part in a fold or not')]
	#[Group('strata/segment')]
	#[DataProvider('foldableProvider')]
	public function isFoldableReadsVerbAndRealm(JournalOp $op, bool $foldable): void
	{
		$this->assertSame($foldable, Collapser::isFoldable($op));
	}

	#endregion

	#region Windows

	#[Test]
	#[TestDox('an empty window collapses to an empty window')]
	#[Group('strata/segment')]
	public function emptyWindowStaysEmpty(): void
	{
		$this->assertSame([], Collapser::collapse([]));
	}

	#[Test]
	#[TestDox('an op that folds with nothing comes back as the instance that went in')]
	#[Group('strata/segment')]
	public function loneOpIsReturnedUntouched(): void
	{
		$op = self::op(1, Verb::UPDATE);

		$this->assertSame([$op], Collapser::collapse([$op]));
	}

	#[Test]
	#[TestDox('subjects interleaved in one window fold independently of each other')]
	#[Group('strata/segment')]
	public function interleavedSubjectsFoldIndependently(): void
	{
		$folded = Collapser::collapse([
			self::op(1, Verb::CREATE, 'node:1'),
			self::op(2, Verb::CREATE, 'node:2'),
			self::op(3, Verb::UPDATE, 'node:1', payloadHash: Hash::of('v2')),
			self::op(4, Verb::DELETE, 'node:2'),
			self::op(5, Verb::UPDATE, 'node:1', payloadHash: Hash::of('v3')),
		]);

		$this->assertSame([Verb::CREATE], self::verbs($folded));
		$this->assertSame(['node:1'], self::subjects($folded));
		$this->assertSame([1], self::sequences($folded));
		$this->assertSame(Hash::of('v3'), $folded[0]->payloadHash);
	}

	#[Test]
	#[TestDox('the collapsed window is ordered by the sequence each survivor kept')]
	#[Group('strata/segment')]
	public function outputIsOrderedBySurvivingSequence(): void
	{
		$folded = Collapser::collapse([
			self::op(1, Verb::UPDATE, 'node:1'),
			self::op(2, Verb::UPDATE, 'node:2'),
			self::op(3, Verb::UPDATE, 'node:1'),
			self::op(4, Verb::CREATE, 'node:3'),
			self::op(5, Verb::UPDATE, 'node:2'),
		]);

		$this->assertSame([1, 2, 4], self::sequences($folded));
		$this->assertSame(['node:1', 'node:2', 'node:3'], self::subjects($folded));
	}

	#[Test]
	#[TestDox('the sequence decides which op is earlier, not the position in the array')]
	#[Group('strata/segment')]
	public function foldReadsSequenceNotInputOrder(): void
	{
		$folded = Collapser::collapse([
			self::op(2, Verb::UPDATE, payloadHash: Hash::of('v2')),
			self::op(1, Verb::CREATE, payloadHash: Hash::of('v1')),
		]);

		$this->assertSame([Verb::CREATE], self::verbs($folded));
		$this->assertSame([1], self::sequences($folded));
		$this->assertSame(Hash::of('v2'), $folded[0]->payloadHash);
	}

	#[Test]
	#[TestDox('a window handed over out of order folds to the same net effect')]
	#[Group('strata/segment')]
	public function unorderedWindowFoldsToTheSameEffect(): void
	{
		$folded = Collapser::collapse([
			self::op(3, Verb::DELETE),
			self::op(1, Verb::CREATE),
			self::op(2, Verb::UPDATE),
		]);

		$this->assertSame([], $folded);
	}

	#endregion

	#region Reporting

	#[Test]
	#[TestDox('statistics() reports what the fold bought')]
	#[Group('strata/segment')]
	public function statisticsReportsWhatTheFoldBought(): void
	{
		$before = [
			self::op(1, Verb::CREATE, 'node:1'),
			self::op(2, Verb::UPDATE, 'node:1'),
			self::op(3, Verb::UPDATE, 'node:1'),
			self::op(4, Verb::CREATE, 'node:2'),
			self::op(5, Verb::UPDATE, 'node:2'),
			self::op(6, Verb::DELETE, 'node:3'),
		];
		$after = Collapser::collapse($before);

		$this->assertCount(3, $after);
		$this->assertSame(
			['input' => 6, 'output' => 3, 'dropped' => 3, 'ratio' => 2.0],
			Collapser::statistics($before, $after),
		);
	}

	#[Test]
	#[TestDox('statistics() reports the ratio as a float even when it divides evenly')]
	#[Group('strata/segment')]
	public function statisticsRatioIsAlwaysFloat(): void
	{
		$statistics = Collapser::statistics(
			[self::op(1, Verb::UPDATE), self::op(2, Verb::UPDATE)],
			[self::op(1, Verb::UPDATE)],
		);

		$this->assertSame(2.0, $statistics['ratio']);
	}

	#[Test]
	#[TestDox('statistics() reports a ratio of 1.0 when the whole window was dropped')]
	#[Group('strata/segment')]
	public function statisticsHandlesAnEmptyOutput(): void
	{
		$before = [self::op(1, Verb::CREATE), self::op(2, Verb::DELETE)];

		$this->assertSame(
			['input' => 2, 'output' => 0, 'dropped' => 2, 'ratio' => 1.0],
			Collapser::statistics($before, Collapser::collapse($before)),
		);
	}

	#[Test]
	#[TestDox('statistics() reports zeroes and a ratio of 1.0 for an empty window')]
	#[Group('strata/segment')]
	public function statisticsHandlesAnEmptyWindow(): void
	{
		$this->assertSame(
			['input' => 0, 'output' => 0, 'dropped' => 0, 'ratio' => 1.0],
			Collapser::statistics([], []),
		);
	}

	#endregion

	#region Helpers

	private static function op(
		int $sequence,
		Verb $verb,
		string $subject = 'node:42',
		Realm $realm = Realm::ENTITY,
		?string $payloadHash = null,
		?string $parentHash = null,
		int $payloadLength = 0,
		string $label = '',
		array $fields = [],
		?int $actor = null,
		?string $requestId = null,
	): JournalOp {
		return new JournalOp(
			$sequence,
			($sequence + 1) * 1000,
			$realm,
			$subject,
			$verb,
			$actor,
			$requestId,
			$payloadHash,
			$parentHash,
			$payloadLength,
			$label,
			$fields,
		);
	}

	private static function verbs(array $ops): array
	{
		return array_map(static fn(JournalOp $op): Verb => $op->verb, $ops);
	}

	private static function sequences(array $ops): array
	{
		return array_map(static fn(JournalOp $op): int => $op->sequence, $ops);
	}

	private static function subjects(array $ops): array
	{
		return array_map(static fn(JournalOp $op): string => $op->subject, $ops);
	}

	#endregion
}
