<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Drill;

use Drupal\strata\Drill\Comparable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Proves what a drill counts as the same value, and what it still counts as different.
 *
 * The first half exists because a drill once called every node with a body drifted: the stored field
 * map and the live one held the same properties in a different order.
 */
#[CoversClass(Comparable::class)]
class ComparableTest extends TestCase
{
	#[Test]
	#[TestDox('the same properties in another order are the same value')]
	#[Group('strata/drill')]
	public function keyOrderIsIgnored(): void
	{
		$stored = [['format' => 'plain_text', 'summary' => null, 'value' => 'text']];
		$live = [['value' => 'text', 'format' => 'plain_text', 'summary' => null]];

		$this->assertSame(Comparable::of($stored), Comparable::of($live));
	}

	#[Test]
	#[TestDox('key order is ignored at every depth')]
	#[Group('strata/drill')]
	public function nestedKeyOrderIsIgnored(): void
	{
		$this->assertSame(
			Comparable::of(['b' => ['y' => 1, 'x' => ['n' => 1, 'm' => 2]], 'a' => 1]),
			Comparable::of(['a' => 1, 'b' => ['x' => ['m' => 2, 'n' => 1], 'y' => 1]]),
		);
	}

	#[Test]
	#[TestDox('a list keeps its order, because another order is another value')]
	#[Group('strata/drill')]
	public function listOrderMatters(): void
	{
		$this->assertNotSame(Comparable::of([1, 2, 3]), Comparable::of([3, 2, 1]));
		$this->assertNotSame(
			Comparable::of([['target_id' => 'a'], ['target_id' => 'b']]),
			Comparable::of([['target_id' => 'b'], ['target_id' => 'a']]),
		);
	}

	#[Test]
	#[TestDox('a changed value is still a difference')]
	#[Group('strata/drill')]
	public function aChangedValueDiffers(): void
	{
		$this->assertNotSame(
			Comparable::of(['value' => 'one', 'format' => 'plain_text']),
			Comparable::of(['format' => 'plain_text', 'value' => 'two']),
		);
		$this->assertNotSame(Comparable::of(['a' => 1]), Comparable::of(['a' => 1, 'b' => null]));
		$this->assertNotSame(Comparable::of(null), Comparable::of(false));
	}

	#[Test]
	#[TestDox('a scalar and a string that spells it are different values')]
	#[Group('strata/drill')]
	public function scalarsKeepTheirType(): void
	{
		$this->assertNotSame(Comparable::of(5), Comparable::of('5'));
		$this->assertSame('5', Comparable::of(5));
	}

	#[Test]
	#[TestDox('a value with no JSON form falls back to its serialized form')]
	#[Group('strata/drill')]
	public function unencodableValuesAreSerialized(): void
	{
		$value = ['bytes' => "\xB1\x31"];

		$this->assertSame(serialize($value), Comparable::of($value));
	}
}
