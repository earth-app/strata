<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Delta;

use Drupal\strata\Delta\ChainDepthPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChainDepthPolicy::class)]
class ChainDepthPolicyTest extends TestCase
{
	/**
	 * @return array<string, array{int}>
	 */
	public static function badDepthProvider(): array
	{
		return [
			'negative' => [-1],
			'far negative' => [-100],
			'above the hard maximum' => [ChainDepthPolicy::HARD_MAX_DEPTH + 1],
		];
	}

	#[Test]
	#[TestDox('a configured depth of $_dataName is refused')]
	#[Group('strata/delta')]
	#[DataProvider('badDepthProvider')]
	public function refusesBadDepth(int $depth): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Chain depth must be between');

		new ChainDepthPolicy($depth);
	}

	#[Test]
	#[TestDox('the boundary depths are accepted')]
	#[Group('strata/delta')]
	public function acceptsBoundaryDepths(): void
	{
		$this->assertSame(0, (new ChainDepthPolicy(0))->maxDepth());
		$this->assertSame(
			ChainDepthPolicy::HARD_MAX_DEPTH,
			(new ChainDepthPolicy(ChainDepthPolicy::HARD_MAX_DEPTH))->maxDepth(),
		);
		$this->assertSame(
			ChainDepthPolicy::DEFAULT_MAX_DEPTH,
			(new ChainDepthPolicy())->maxDepth(),
		);
	}

	#[Test]
	#[TestDox('the shipped default is below git pack.depth, which is 50')]
	#[Group('strata/delta')]
	public function defaultIsBelowGitPackDepth(): void
	{
		$this->assertSame(32, ChainDepthPolicy::DEFAULT_MAX_DEPTH);
		$this->assertLessThan(50, ChainDepthPolicy::DEFAULT_MAX_DEPTH);
	}

	#[Test]
	#[TestDox('a depth of zero anchors every frame')]
	#[Group('strata/delta')]
	public function zeroDepthAnchorsEverything(): void
	{
		$policy = new ChainDepthPolicy(0);

		$this->assertTrue($policy->mustAnchor(0));
		$this->assertSame(0, $policy->nextDepth(0));
		$this->assertSame(0, $policy->remaining(0));
	}

	#[Test]
	#[TestDox('a chain runs to the maximum and then anchors')]
	#[Group('strata/delta')]
	public function chainAnchorsAtTheMaximum(): void
	{
		$policy = new ChainDepthPolicy(3);

		$this->assertFalse($policy->mustAnchor(0));
		$this->assertFalse($policy->mustAnchor(1));
		$this->assertFalse($policy->mustAnchor(2));
		$this->assertTrue($policy->mustAnchor(3));
		$this->assertTrue($policy->mustAnchor(4));
	}

	#[Test]
	#[TestDox('nextDepth() increments inside the chain and resets at the anchor')]
	#[Group('strata/delta')]
	public function nextDepthIncrementsThenResets(): void
	{
		$policy = new ChainDepthPolicy(3);

		$this->assertSame(1, $policy->nextDepth(0));
		$this->assertSame(2, $policy->nextDepth(1));
		$this->assertSame(3, $policy->nextDepth(2));
		$this->assertSame(0, $policy->nextDepth(3));
	}

	#[Test]
	#[TestDox('remaining() counts down to zero and never goes negative')]
	#[Group('strata/delta')]
	public function remainingNeverGoesNegative(): void
	{
		$policy = new ChainDepthPolicy(3);

		$this->assertSame(3, $policy->remaining(0));
		$this->assertSame(1, $policy->remaining(2));
		$this->assertSame(0, $policy->remaining(3));
		$this->assertSame(0, $policy->remaining(99));
	}

	#[Test]
	#[TestDox('a negative parent depth is refused rather than treated as zero')]
	#[Group('strata/delta')]
	public function refusesNegativeParentDepth(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Parent depth cannot be negative');

		(new ChainDepthPolicy())->mustAnchor(-1);
	}

	#[Test]
	#[TestDox('remaining() refuses a negative depth')]
	#[Group('strata/delta')]
	public function remainingRefusesNegativeDepth(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Depth cannot be negative');

		(new ChainDepthPolicy())->remaining(-1);
	}

	#[Test]
	#[TestDox('walking a full chain to the cap produces exactly maxDepth links')]
	#[Group('strata/delta')]
	public function walkingFullChainProducesMaxDepthLinks(): void
	{
		$policy = new ChainDepthPolicy(5);
		$depth = 0;
		$links = 0;

		for ($i = 0; $i < 20; $i++) {
			if ($policy->mustAnchor($depth)) {
				$this->assertSame(5, $links, 'a chain must reach the cap before anchoring');
				$links = 0;
				$depth = 0;

				continue;
			}
			$depth = $policy->nextDepth($depth);
			$links++;
		}

		$this->assertGreaterThan(0, $depth);
	}
}
