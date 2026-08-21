<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Diff;

use Drupal\strata\Diff\DiffHunk;
use Drupal\strata\Diff\FieldDiff;
use Drupal\strata\Diff\SubjectDiff;
use Drupal\strata\Journal\Realm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Proves what a diff hands a renderer, from a run of lines up to a whole subject.
 *
 * The mode a subject is drawn in comes from its realm and is checked against every case the enum
 * has, so a realm added later without a mode of its own lands on the value rendering rather than on
 * whichever branch happened to be first.
 */
#[CoversClass(DiffHunk::class)]
#[CoversClass(FieldDiff::class)]
#[CoversClass(SubjectDiff::class)]
class DiffTest extends TestCase
{
	#region Hunks

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function hunkTypeProvider(): array
	{
		return [
			'a copied run' => [DiffHunk::COPY, false],
			'an added run' => [DiffHunk::ADD, true],
			'a deleted run' => [DiffHunk::DELETE, true],
			'a changed run' => [DiffHunk::CHANGE, true],
		];
	}

	#[Test]
	#[TestDox('$_dataName is a difference unless it was copied')]
	#[Group('strata/diff')]
	#[DataProvider('hunkTypeProvider')]
	public function isChangeIsFalseOnlyForCopy(string $type, bool $expected): void
	{
		$this->assertSame($expected, (new DiffHunk($type, ['a'], ['a']))->isChange());
	}

	#[Test]
	#[TestDox('height() is the larger of the two runs')]
	#[Group('strata/diff')]
	public function heightIsTheLargerRun(): void
	{
		$this->assertSame(3, (new DiffHunk(DiffHunk::CHANGE, ['a', 'b', 'c'], ['x']))->height());
		$this->assertSame(3, (new DiffHunk(DiffHunk::CHANGE, ['a'], ['x', 'y', 'z']))->height());
		$this->assertSame(2, (new DiffHunk(DiffHunk::COPY, ['a', 'b'], ['a', 'b']))->height());
		$this->assertSame(0, (new DiffHunk(DiffHunk::COPY))->height());
	}

	#[Test]
	#[TestDox('jsonSerialize() carries both runs and where each starts')]
	#[Group('strata/diff')]
	public function hunkJsonSerialize(): void
	{
		$hunk = new DiffHunk(DiffHunk::CHANGE, ['was'], ['is'], 4, 7);

		$this->assertSame(
			[
				'type' => DiffHunk::CHANGE,
				'before' => ['was'],
				'after' => ['is'],
				'before_line' => 4,
				'after_line' => 7,
			],
			$hunk->jsonSerialize(),
		);
	}

	#endregion

	#region Fields

	#[Test]
	#[TestDox('isTextual() is true only when there are hunks to draw')]
	#[Group('strata/diff')]
	public function isTextualFollowsTheHunks(): void
	{
		$scalar = new FieldDiff('title', FieldDiff::CHANGED, 'was', 'is');
		$textual = new FieldDiff('body', FieldDiff::CHANGED, "a\nb", "a\nc", [
			new DiffHunk(DiffHunk::COPY, ['a'], ['a']),
			new DiffHunk(DiffHunk::CHANGE, ['b'], ['c']),
		]);

		$this->assertFalse($scalar->isTextual());
		$this->assertTrue($textual->isTextual());
	}

	#[Test]
	#[TestDox('changedLines() counts only the runs that are not copies')]
	#[Group('strata/diff')]
	public function changedLinesSkipsCopies(): void
	{
		$diff = new FieldDiff('body', FieldDiff::CHANGED, '', '', [
			new DiffHunk(DiffHunk::COPY, ['a', 'b', 'c'], ['a', 'b', 'c']),
			new DiffHunk(DiffHunk::CHANGE, ['d'], ['D']),
			new DiffHunk(DiffHunk::COPY, ['e'], ['e']),
			new DiffHunk(DiffHunk::ADD, [], ['f', 'g']),
		]);

		$this->assertSame(3, $diff->changedLines());
		$this->assertSame(0, (new FieldDiff('title', FieldDiff::CHANGED))->changedLines());
	}

	#[Test]
	#[TestDox('describe() names what was added, removed or changed')]
	#[Group('strata/diff')]
	public function describeNamesTheChange(): void
	{
		$added = new FieldDiff('field_tag', FieldDiff::ADDED, '', 'Cornwall');
		$removed = new FieldDiff('field_tag', FieldDiff::REMOVED, 'Cornwall');
		$changed = new FieldDiff('title', FieldDiff::CHANGED, 'Before', 'After');

		$this->assertSame('added: Cornwall', $added->describe());
		$this->assertSame('removed: Cornwall', $removed->describe());
		$this->assertSame('Before -> After', $changed->describe());
	}

	#[Test]
	#[TestDox('describe() clips a long value with an ellipsis')]
	#[Group('strata/diff')]
	public function describeClipsALongValue(): void
	{
		$diff = new FieldDiff('body', FieldDiff::ADDED, '', str_repeat('x', 200));

		$this->assertSame('added: ' . str_repeat('x', 60) . '...', $diff->describe());
		$this->assertSame('added: ' . str_repeat('x', 10) . '...', $diff->describe(10));
	}

	#[Test]
	#[TestDox('a value exactly at the limit is not clipped')]
	#[Group('strata/diff')]
	public function describeKeepsAValueAtTheLimit(): void
	{
		$diff = new FieldDiff('body', FieldDiff::ADDED, '', str_repeat('x', 60));

		$this->assertSame('added: ' . str_repeat('x', 60), $diff->describe());
		$this->assertStringNotContainsString('...', $diff->describe());
	}

	#[Test]
	#[TestDox('describe() flattens newlines and runs of whitespace into single spaces')]
	#[Group('strata/diff')]
	public function describeFlattensWhitespace(): void
	{
		$diff = new FieldDiff('body', FieldDiff::CHANGED, "  one\n\ntwo \t three  ", "four\nfive");

		$this->assertSame('one two three -> four five', $diff->describe());
		$this->assertStringNotContainsString("\n", $diff->describe());
	}

	#[Test]
	#[TestDox('clipping counts characters rather than bytes, so a multibyte value survives')]
	#[Group('strata/diff')]
	public function describeClipsByCharacter(): void
	{
		$diff = new FieldDiff('body', FieldDiff::ADDED, '', str_repeat('e', 20));

		$this->assertSame('added: ' . str_repeat('e', 5) . '...', $diff->describe(5));
		$this->assertSame(1, preg_match('//u', $diff->describe(5)));
	}

	#[Test]
	#[TestDox('jsonSerialize() reports whether the field is textual and how much changed')]
	#[Group('strata/diff')]
	public function fieldJsonSerialize(): void
	{
		$hunk = new DiffHunk(DiffHunk::CHANGE, ['b'], ['c'], 2, 2);
		$diff = new FieldDiff('body', FieldDiff::CHANGED, "a\nb", "a\nc", [$hunk]);

		$this->assertSame(
			[
				'name' => 'body',
				'status' => FieldDiff::CHANGED,
				'before' => "a\nb",
				'after' => "a\nc",
				'textual' => true,
				'changed_lines' => 1,
				'hunks' => [$hunk],
			],
			$diff->jsonSerialize(),
		);
	}

	#endregion

	#region Subject modes

	/**
	 * @return array<string, array{Realm, string}>
	 */
	public static function realmModeProvider(): array
	{
		$expected = [
			Realm::ENTITY->value => SubjectDiff::MODE_ENTITY,
			Realm::CONFIG->value => SubjectDiff::MODE_CONFIG,
			Realm::TABLE->value => SubjectDiff::MODE_ROW,
			Realm::FILE->value => SubjectDiff::MODE_FILE,
		];
		$cases = [];

		foreach (Realm::cases() as $realm) {
			$cases[$realm->value] = [$realm, $expected[$realm->value] ?? SubjectDiff::MODE_VALUE];
		}

		return $cases;
	}

	#[Test]
	#[TestDox('the $_dataName realm is rendered in the mode that suits it')]
	#[Group('strata/diff')]
	#[DataProvider('realmModeProvider')]
	public function modeComesFromTheRealm(Realm $realm, string $expected): void
	{
		$diff = new SubjectDiff($realm->value . '/thing', $realm, SubjectDiff::CHANGED);

		$this->assertSame($expected, $diff->mode());
	}

	#[Test]
	#[TestDox('a realm this release does not know falls back to the value rendering')]
	#[Group('strata/diff')]
	public function unknownRealmFallsBackToValue(): void
	{
		$diff = new SubjectDiff('invented/thing', null, SubjectDiff::CHANGED);

		$this->assertSame(SubjectDiff::MODE_VALUE, $diff->mode());
		$this->assertNull($diff->jsonSerialize()['realm']);
	}

	#[Test]
	#[TestDox('every realm gets a mode that is one of the declared constants')]
	#[Group('strata/diff')]
	public function everyRealmMapsToADeclaredMode(): void
	{
		$modes = [
			SubjectDiff::MODE_ENTITY,
			SubjectDiff::MODE_CONFIG,
			SubjectDiff::MODE_ROW,
			SubjectDiff::MODE_FILE,
			SubjectDiff::MODE_VALUE,
		];

		foreach (Realm::cases() as $realm) {
			$diff = new SubjectDiff('a/b', $realm, SubjectDiff::CHANGED);

			$this->assertContains($diff->mode(), $modes, $realm->value);
		}
	}

	#endregion

	#region Subject naming

	#[Test]
	#[TestDox('name() strips the realm prefix and keeps the rest of the path')]
	#[Group('strata/diff')]
	public function nameStripsTheRealmPrefix(): void
	{
		$this->assertSame(
			'node/12',
			(new SubjectDiff('entity/node/12', Realm::ENTITY, SubjectDiff::CHANGED))->name(),
		);
		$this->assertSame(
			'system.site',
			(new SubjectDiff('config/system.site', Realm::CONFIG, SubjectDiff::CHANGED))->name(),
		);
		$this->assertSame(
			'nothing',
			(new SubjectDiff('nothing', null, SubjectDiff::CHANGED))->name(),
			'a path with no realm prefix is its own name',
		);
		$this->assertSame(
			'',
			(new SubjectDiff('entity/', Realm::ENTITY, SubjectDiff::CHANGED))->name(),
		);
	}

	#[Test]
	#[TestDox('title() prefers the label and falls back to the name')]
	#[Group('strata/diff')]
	public function titlePrefersTheLabel(): void
	{
		$labelled = new SubjectDiff(
			'entity/node/12',
			Realm::ENTITY,
			SubjectDiff::CHANGED,
			[],
			'About Us',
		);
		$bare = new SubjectDiff('entity/node/12', Realm::ENTITY, SubjectDiff::CHANGED);

		$this->assertSame('About Us', $labelled->title());
		$this->assertSame('node/12', $bare->title());
	}

	#endregion

	#region Subject summaries

	#[Test]
	#[TestDox('a created subject says so rather than listing fields it has no earlier value for')]
	#[Group('strata/diff')]
	public function addedSubjectSummary(): void
	{
		$diff = new SubjectDiff(
			'entity/node/12',
			Realm::ENTITY,
			SubjectDiff::ADDED,
			[new FieldDiff('title', FieldDiff::ADDED, '', 'About Us')],
			'About Us',
		);

		$this->assertSame('About Us was created', $diff->summary());
		$this->assertTrue($diff->isReadable());
	}

	#[Test]
	#[TestDox('a removed subject says so')]
	#[Group('strata/diff')]
	public function removedSubjectSummary(): void
	{
		$diff = new SubjectDiff('entity/node/12', Realm::ENTITY, SubjectDiff::REMOVED);

		$this->assertSame('node/12 was removed', $diff->summary());
	}

	#[Test]
	#[TestDox('a changed subject names the fields, and counts one field in the singular')]
	#[Group('strata/diff')]
	public function changedSubjectSummary(): void
	{
		$one = new SubjectDiff('entity/node/12', Realm::ENTITY, SubjectDiff::CHANGED, [
			new FieldDiff('title', FieldDiff::CHANGED, 'a', 'b'),
		]);
		$several = new SubjectDiff('entity/node/12', Realm::ENTITY, SubjectDiff::CHANGED, [
			new FieldDiff('title', FieldDiff::CHANGED, 'a', 'b'),
			new FieldDiff('body', FieldDiff::CHANGED, 'c', 'd'),
		]);

		$this->assertSame('node/12 changed in 1 field: title', $one->summary());
		$this->assertSame('node/12 changed in 2 fields: title, body', $several->summary());
		$this->assertSame(1, $one->changedFields());
		$this->assertSame(2, $several->changedFields());
	}

	#[Test]
	#[TestDox('a subject that did not decode reports why instead of an empty change list')]
	#[Group('strata/diff')]
	public function unreadableSubjectSummary(): void
	{
		$diff = new SubjectDiff(
			'entity/node/12',
			Realm::ENTITY,
			SubjectDiff::UNREADABLE,
			[],
			'',
			'frame bddd813c is missing',
		);

		$this->assertFalse($diff->isReadable());
		$this->assertSame('node/12 could not be read: frame bddd813c is missing', $diff->summary());
	}

	#[Test]
	#[TestDox('a changed subject with no fields still reads as a sentence')]
	#[Group('strata/diff')]
	public function changedWithNoFieldsStillReads(): void
	{
		$diff = new SubjectDiff('entity/node/12', Realm::ENTITY, SubjectDiff::CHANGED);

		$this->assertSame('node/12 changed in 0 fields: ', $diff->summary());
	}

	#[Test]
	#[TestDox('jsonSerialize() carries the realm value, the mode and the reason it failed')]
	#[Group('strata/diff')]
	public function subjectJsonSerialize(): void
	{
		$field = new FieldDiff('title', FieldDiff::CHANGED, 'a', 'b');
		$diff = new SubjectDiff(
			'config/system.site',
			Realm::CONFIG,
			SubjectDiff::CHANGED,
			[$field],
			'System Site',
		);

		$this->assertSame(
			[
				'subject' => 'config/system.site',
				'realm' => 'config',
				'mode' => SubjectDiff::MODE_CONFIG,
				'status' => SubjectDiff::CHANGED,
				'label' => 'System Site',
				'problem' => null,
				'fields' => [$field],
			],
			$diff->jsonSerialize(),
		);
	}

	#endregion
}
