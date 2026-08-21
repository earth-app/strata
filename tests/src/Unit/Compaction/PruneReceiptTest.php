<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Compaction;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Compaction\PruneReceipt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(PruneReceipt::class)]
class PruneReceiptTest extends TestCase
{
	#region Refusal

	#[Test]
	#[TestDox('a refusal carries its reason and removes nothing')]
	#[Group('strata/compaction')]
	public function refusal(): void
	{
		$receipt = PruneReceipt::refuse('the walk could not read 3 objects', ['tree abc: gone']);

		$this->assertTrue($receipt->wasRefused());
		$this->assertFalse($receipt->applied);
		$this->assertSame([], $receipt->frames);
		$this->assertSame([], $receipt->objects);
		$this->assertSame(0, $receipt->bytes);
		$this->assertSame(1, $receipt->keptCount());
		$this->assertStringContainsString('could not read 3 objects', $receipt->summary());
	}

	#endregion

	#region Reporting

	#[Test]
	#[TestDox('a dry run says what it would do rather than what it did')]
	#[Group('strata/compaction')]
	public function dryRunReadsAsProposal(): void
	{
		$receipt = new PruneReceipt(
			false,
			[Hash::of('a'), Hash::of('b')],
			['packs/aa/bb/cc'],
			4_194_304,
			[Hash::of('commit')],
			['frame ddd: a reachable commit needs it'],
		);

		$this->assertFalse($receipt->wasRefused());
		$this->assertFalse($receipt->isEmpty());
		$this->assertStringContainsString('would prune 2 frames', $receipt->summary());
		$this->assertStringContainsString('4.0 MiB freed', $receipt->summary());
		$this->assertStringContainsString('1 restore points removed', $receipt->summary());
		$this->assertStringContainsString('1 held back', $receipt->summary());
	}

	#[Test]
	#[TestDox('an applied prune says what it did')]
	#[Group('strata/compaction')]
	public function appliedReadsAsFact(): void
	{
		$receipt = new PruneReceipt(true, [Hash::of('a')], ['packs/aa/bb/cc'], 1024);

		$this->assertStringContainsString('pruned 1 frames', $receipt->summary());
		$this->assertStringContainsString('1.0 KiB freed', $receipt->summary());
	}

	#[Test]
	#[TestDox('a prune with no candidates says so rather than reporting zeroes')]
	#[Group('strata/compaction')]
	public function emptyPrune(): void
	{
		$receipt = new PruneReceipt(true);

		$this->assertTrue($receipt->isEmpty());
		$this->assertSame('nothing to prune', $receipt->summary());
	}

	#[Test]
	#[TestDox('a receipt with only held-back candidates is still empty')]
	#[Group('strata/compaction')]
	public function heldBackOnlyIsEmpty(): void
	{
		$receipt = new PruneReceipt(true, [], [], 0, [], ['frame abc: still live']);

		$this->assertTrue($receipt->isEmpty());
		$this->assertSame(1, $receipt->keptCount());
	}

	#[Test]
	#[TestDox('the serialized form carries the held-back list, which is the proof of the checks')]
	#[Group('strata/compaction')]
	public function serializedFormCarriesEverything(): void
	{
		$receipt = new PruneReceipt(
			true,
			[Hash::of('a')],
			['packs/aa/bb/cc'],
			512,
			[Hash::of('commit')],
			['frame ddd: a dictionary was trained against it'],
			null,
			1.5,
		);

		$data = $receipt->jsonSerialize();

		$this->assertTrue($data['applied']);
		$this->assertNull($data['refused']);
		$this->assertSame([Hash::of('a')], $data['frames']);
		$this->assertSame(['packs/aa/bb/cc'], $data['objects']);
		$this->assertSame(512, $data['bytes']);
		$this->assertCount(1, $data['kept']);
		$this->assertSame(1.5, $data['seconds']);
	}

	#[Test]
	#[TestDox('byte counts render at a readable scale')]
	#[Group('strata/compaction')]
	public function byteScaling(): void
	{
		$frames = [Hash::of('a')];

		$this->assertStringContainsString(
			'900 B freed',
			(new PruneReceipt(true, $frames, [], 900))->summary(),
		);
		$this->assertStringContainsString(
			'1.0 GiB freed',
			(new PruneReceipt(true, $frames, [], 1_073_741_824))->summary(),
		);
	}

	#endregion
}
