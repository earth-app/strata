<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Health;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\RepairLadder;
use Drupal\strata\Health\Tripwire\BaseUnreachable;
use Drupal\strata\Health\Tripwire\CommitParentMissing;
use Drupal\strata\Health\Tripwire\DeltaChainTooDeep;
use Drupal\strata\Health\Tripwire\DeltaParentMissing;
use Drupal\strata\Health\Tripwire\DictionaryMissing;
use Drupal\strata\Health\Tripwire\FrameAeadFailure;
use Drupal\strata\Health\Tripwire\FrameHashMismatch;
use Drupal\strata\Health\Tripwire\FrameMissing;
use Drupal\strata\Health\Tripwire\FrameUnindexed;
use Drupal\strata\Health\Tripwire\FrameUnreadable;
use Drupal\strata\Health\Tripwire\KeyRotatedMidFlight;
use Drupal\strata\Health\Tripwire\PackShortRead;
use Drupal\strata\Health\Tripwire\RefMissing;
use Drupal\strata\Health\Tripwire\TierUnreachable;
use Drupal\strata\Health\Tripwire\SegmentTruncated;
use Drupal\strata\Health\Tripwire\AnchorMissing;
use Drupal\strata\Health\TripwireInterface;
use Drupal\strata\Health\TripwireRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(BaseUnreachable::class)]
#[CoversClass(CommitParentMissing::class)]
#[CoversClass(DeltaChainTooDeep::class)]
#[CoversClass(DeltaParentMissing::class)]
#[CoversClass(DictionaryMissing::class)]
#[CoversClass(FrameAeadFailure::class)]
#[CoversClass(FrameHashMismatch::class)]
#[CoversClass(FrameMissing::class)]
#[CoversClass(FrameUnindexed::class)]
#[CoversClass(FrameUnreadable::class)]
#[CoversClass(PackShortRead::class)]
#[CoversClass(SegmentTruncated::class)]
#[CoversClass(AnchorMissing::class)]
class StorageTripwireTest extends TestCase
{
	/**
	 * A digest to stand in for a frame, pack, tree or commit address.
	 */
	private static function digest(string $of = 'subject'): string
	{
		return Hash::of($of);
	}

	#region The Set

	#[Test]
	#[TestDox('the storage registry holds every check the verify pass sweeps with')]
	#[Group('strata/health')]
	public function registryHoldsTheWholeSet(): void
	{
		$codes = TripwireRegistry::withStorageTripwires()->codes();

		$this->assertContains('frame.unindexed', $codes);
		$this->assertContains('frame.missing', $codes);
		$this->assertContains('frame.unreadable', $codes);
		$this->assertContains('frame.hash_mismatch', $codes);
		$this->assertContains('frame.aead_fail', $codes);
		$this->assertContains('pack.short_read', $codes);
		$this->assertContains('dict.missing', $codes);
		$this->assertContains('delta.parent_missing', $codes);
		$this->assertContains('delta.chain_too_deep', $codes);
		$this->assertContains('segment.truncated', $codes);
		$this->assertContains('commit.parent_missing', $codes);
		$this->assertContains('anchor.missing', $codes);
		$this->assertContains('base.unreachable', $codes);
		$this->assertContains('ref.missing', $codes);
		$this->assertContains('tier.unreachable', $codes);
		$this->assertContains('key.rotated_mid_flight', $codes);
		$this->assertCount(16, $codes);
	}

	/**
	 * @return array<string, array{TripwireInterface}>
	 */
	public static function everyTripwireProvider(): array
	{
		return [
			'frame.unindexed' => [new FrameUnindexed()],
			'frame.missing' => [new FrameMissing()],
			'frame.unreadable' => [new FrameUnreadable()],
			'frame.hash_mismatch' => [new FrameHashMismatch()],
			'frame.aead_fail' => [new FrameAeadFailure()],
			'pack.short_read' => [new PackShortRead()],
			'dict.missing' => [new DictionaryMissing()],
			'delta.parent_missing' => [new DeltaParentMissing()],
			'delta.chain_too_deep' => [new DeltaChainTooDeep()],
			'segment.truncated' => [new SegmentTruncated()],
			'commit.parent_missing' => [new CommitParentMissing()],
			'anchor.missing' => [new AnchorMissing()],
			'base.unreachable' => [new BaseUnreachable()],
			'ref.missing' => [new RefMissing()],
			'tier.unreachable' => [new TierUnreachable()],
			'key.rotated_mid_flight' => [new KeyRotatedMidFlight()],
		];
	}

	#[Test]
	#[TestDox('$_dataName stays quiet on an observation that says nothing about it')]
	#[Group('strata/health')]
	#[DataProvider('everyTripwireProvider')]
	public function quietOnAnEmptyObservation(TripwireInterface $wire): void
	{
		$this->assertNull($wire->check([]));
		$this->assertNull($wire->check(['unrelated' => 'value']));
	}

	#[Test]
	#[TestDox('$_dataName reports under a code the ladder can rank')]
	#[Group('strata/health')]
	#[DataProvider('everyTripwireProvider')]
	public function codeIsStableAndDotted(TripwireInterface $wire): void
	{
		$this->assertMatchesRegularExpression('/^[a-z]+\.[a-z_]+$/', $wire->code());
	}

	#endregion

	#region Frames

	#[Test]
	#[TestDox('a frame the index does not know is unindexed rather than missing')]
	#[Group('strata/health')]
	public function unindexedFrame(): void
	{
		$finding = (new FrameUnindexed())->check([
			'frame' => self::digest(),
			'frame_indexed' => false,
		]);

		$this->assertNotNull($finding);
		$this->assertSame('frame.unindexed', $finding->code);
		$this->assertSame(Finding::ERROR, $finding->severity);
		$this->assertSame('reindex', RepairLadder::initialRung($finding->severity));
		$this->assertTrue(RepairLadder::isAutomatic(RepairLadder::initialRung($finding->severity)));
	}

	#[Test]
	#[TestDox('a frame whose object is gone is critical and names the key it looked at')]
	#[Group('strata/health')]
	public function missingFrame(): void
	{
		$finding = (new FrameMissing())->check([
			'frame' => self::digest(),
			'frame_present' => false,
			'key' => 'packs/aa/bb/cc',
		]);

		$this->assertNotNull($finding);
		$this->assertSame(Finding::CRITICAL, $finding->severity);
		$this->assertStringContainsString('packs/aa/bb/cc', $finding->context);
		$this->assertSame(self::digest(), $finding->scope);
	}

	#[Test]
	#[TestDox('a present frame is not reported as missing')]
	#[Group('strata/health')]
	public function presentFrameIsQuiet(): void
	{
		$this->assertNull(
			(new FrameMissing())->check(['frame' => self::digest(), 'frame_present' => true]),
		);
	}

	#[Test]
	#[TestDox('content that hashes to something else is reported with both addresses')]
	#[Group('strata/health')]
	public function hashMismatch(): void
	{
		$finding = (new FrameHashMismatch())->check([
			'frame' => self::digest('expected'),
			'decoded_hash' => self::digest('actual'),
		]);

		$this->assertNotNull($finding);
		$this->assertSame(Finding::CRITICAL, $finding->severity);
		$this->assertStringContainsString(
			Hash::abbreviate(self::digest('expected')),
			$finding->context,
		);
		$this->assertStringContainsString(
			Hash::abbreviate(self::digest('actual')),
			$finding->context,
		);
	}

	#[Test]
	#[TestDox('content that hashes to its own address is not reported')]
	#[Group('strata/health')]
	public function matchingHashIsQuiet(): void
	{
		$this->assertNull(
			(new FrameHashMismatch())->check([
				'frame' => self::digest(),
				'decoded_hash' => self::digest(),
			]),
		);
	}

	#[Test]
	#[TestDox('a failed tag names all three causes rather than picking one')]
	#[Group('strata/health')]
	public function aeadFailure(): void
	{
		$finding = (new FrameAeadFailure())->check([
			'frame' => self::digest(),
			'aead_failed' => true,
		]);

		$this->assertNotNull($finding);
		$this->assertSame(Finding::CRITICAL, $finding->severity);
		$this->assertStringContainsString('key changed', $finding->context);
		$this->assertStringContainsString('relocated', $finding->context);
	}

	#[Test]
	#[TestDox('a decode failure carries its reason through verbatim')]
	#[Group('strata/health')]
	public function unreadableFrame(): void
	{
		$finding = (new FrameUnreadable())->check([
			'frame' => self::digest(),
			'decode_error' => 'no codec is registered for "lz4"',
		]);

		$this->assertNotNull($finding);
		$this->assertSame(Finding::ERROR, $finding->severity);
		$this->assertStringContainsString('lz4', $finding->context);
	}

	#endregion

	#region Packs

	#[Test]
	#[TestDox('a range past the end of a pack is reported with both numbers')]
	#[Group('strata/health')]
	public function shortRead(): void
	{
		$finding = (new PackShortRead())->check([
			'frame' => self::digest(),
			'pack' => self::digest('pack'),
			'pack_size' => 1000,
			'frame_offset' => 900,
			'frame_length' => 200,
		]);

		$this->assertNotNull($finding);
		$this->assertSame(Finding::ERROR, $finding->severity);
		$this->assertStringContainsString('1100', $finding->context);
		$this->assertStringContainsString('1000', $finding->context);
	}

	#[Test]
	#[TestDox('a range that ends exactly at the last byte of a pack fits')]
	#[Group('strata/health')]
	public function exactFitIsQuiet(): void
	{
		$this->assertNull(
			(new PackShortRead())->check([
				'frame' => self::digest(),
				'pack' => self::digest('pack'),
				'pack_size' => 1000,
				'frame_offset' => 800,
				'frame_length' => 200,
			]),
		);
	}

	#[Test]
	#[TestDox('a standalone frame reports no pack size and is not checked for one')]
	#[Group('strata/health')]
	public function standaloneFrameIsQuiet(): void
	{
		$this->assertNull(
			(new PackShortRead())->check([
				'frame' => self::digest(),
				'pack' => '',
				'pack_size' => 0,
				'frame_offset' => 0,
				'frame_length' => 200,
			]),
		);
	}

	#endregion

	#region Dependencies

	#[Test]
	#[TestDox('a dictionary a frame needs and cannot get is critical')]
	#[Group('strata/health')]
	public function missingDictionary(): void
	{
		$finding = (new DictionaryMissing())->check([
			'frame' => self::digest(),
			'dictionary' => 'entity/4',
			'dictionary_present' => false,
		]);

		$this->assertNotNull($finding);
		$this->assertSame(Finding::CRITICAL, $finding->severity);
		$this->assertSame('entity/4', $finding->scope);
	}

	#[Test]
	#[TestDox('a frame needing no dictionary is not reported')]
	#[Group('strata/health')]
	public function noDictionaryIsQuiet(): void
	{
		$this->assertNull(
			(new DictionaryMissing())->check([
				'frame' => self::digest(),
				'dictionary' => '',
				'dictionary_present' => true,
			]),
		);
	}

	#[Test]
	#[TestDox('a delta parent that has been collected is critical and names the depth')]
	#[Group('strata/health')]
	public function missingDeltaParent(): void
	{
		$finding = (new DeltaParentMissing())->check([
			'frame' => self::digest(),
			'delta_parent' => self::digest('parent'),
			'delta_parent_present' => false,
			'delta_depth' => 7,
		]);

		$this->assertNotNull($finding);
		$this->assertSame(Finding::CRITICAL, $finding->severity);
		$this->assertSame(self::digest('parent'), $finding->scope);
		$this->assertStringContainsString('depth 7', $finding->context);
	}

	#[Test]
	#[TestDox('a chain past the cap is a warning a rebuild can fix, not a critical loss')]
	#[Group('strata/health')]
	public function chainTooDeep(): void
	{
		$finding = (new DeltaChainTooDeep())->check([
			'frame' => self::digest(),
			'delta_depth' => 40,
			'max_depth' => 32,
		]);

		$this->assertNotNull($finding);
		$this->assertSame(Finding::WARN, $finding->severity);
		$this->assertStringContainsString('40 links', $finding->context);
		$this->assertStringContainsString('32 link cap', $finding->context);
		$this->assertTrue(RepairLadder::isAutomatic(RepairLadder::initialRung($finding->severity)));
	}

	#[Test]
	#[TestDox('a chain exactly at the cap is where the encoder anchors and is not reported')]
	#[Group('strata/health')]
	public function chainAtTheCapIsQuiet(): void
	{
		$this->assertNull(
			(new DeltaChainTooDeep())->check([
				'frame' => self::digest(),
				'delta_depth' => 32,
				'max_depth' => 32,
			]),
		);
	}

	#[Test]
	#[TestDox('no configured cap means no depth to exceed')]
	#[Group('strata/health')]
	public function noCapIsQuiet(): void
	{
		$this->assertNull(
			(new DeltaChainTooDeep())->check([
				'frame' => self::digest(),
				'delta_depth' => 99,
				'max_depth' => 0,
			]),
		);
	}

	#endregion

	#region History

	#[Test]
	#[TestDox('an unreadable segment carries the reason it would not read')]
	#[Group('strata/health')]
	public function truncatedSegment(): void
	{
		$finding = (new SegmentTruncated())->check([
			'segment' => 'segments/0/17/00-abc.seg',
			'segment_readable' => false,
			'segment_error' => 'has no readable header',
		]);

		$this->assertNotNull($finding);
		$this->assertSame(Finding::ERROR, $finding->severity);
		$this->assertStringContainsString('no readable header', $finding->context);
	}

	#[Test]
	#[TestDox('an unreadable segment with no reason recorded says so rather than nothing')]
	#[Group('strata/health')]
	public function truncatedSegmentWithoutReason(): void
	{
		$finding = (new SegmentTruncated())->check([
			'segment' => 'segments/0/17/00-abc.seg',
			'segment_readable' => false,
		]);

		$this->assertNotNull($finding);
		$this->assertStringContainsString('no reason recorded', $finding->context);
	}

	#[Test]
	#[TestDox('a missing parent commit says history stops there')]
	#[Group('strata/health')]
	public function missingCommitParent(): void
	{
		$finding = (new CommitParentMissing())->check([
			'commit' => self::digest('child'),
			'commit_parent' => self::digest('parent'),
			'commit_parent_present' => false,
		]);

		$this->assertNotNull($finding);
		$this->assertSame(Finding::CRITICAL, $finding->severity);
		$this->assertStringContainsString('history stops here', $finding->context);
	}

	#[Test]
	#[TestDox('a root commit names no parent and is not reported')]
	#[Group('strata/health')]
	public function rootCommitIsQuiet(): void
	{
		$this->assertNull(
			(new CommitParentMissing())->check([
				'commit' => self::digest(),
				'commit_parent' => '',
				'commit_parent_present' => true,
			]),
		);
	}

	#[Test]
	#[TestDox('a missing base anchor names the commit that wanted it')]
	#[Group('strata/health')]
	public function orphanTree(): void
	{
		$finding = (new AnchorMissing())->check([
			'commit' => self::digest('commit'),
			'anchor' => self::digest('anchor'),
			'anchor_present' => false,
		]);

		$this->assertNotNull($finding);
		$this->assertSame(Finding::CRITICAL, $finding->severity);
		$this->assertStringContainsString(
			Hash::abbreviate(self::digest('commit')),
			$finding->context,
		);
	}

	#[Test]
	#[TestDox('a chain with no anchor reports how far it walked before giving up')]
	#[Group('strata/health')]
	public function unreachableAnchor(): void
	{
		$finding = (new BaseUnreachable())->check([
			'commit' => self::digest(),
			'anchor_reachable' => false,
			'walked' => 240,
		]);

		$this->assertNotNull($finding);
		$this->assertSame(Finding::CRITICAL, $finding->severity);
		$this->assertStringContainsString('240 commits', $finding->context);
		$this->assertSame('quarantine', RepairLadder::initialRung($finding->severity));
	}

	#endregion

	#region The Sweep

	#[Test]
	#[TestDox('one broken frame produces one finding per distinct symptom, not one per check')]
	#[Group('strata/health')]
	public function sweepReportsOnlyWhatFired(): void
	{
		$findings = TripwireRegistry::withStorageTripwires()->evaluate([
			'frame' => self::digest(),
			'frame_present' => false,
			'key' => 'frames/aa/bb/cc',
		]);

		$this->assertCount(1, $findings);
		$this->assertSame('frame.missing', $findings[0]->code);
	}

	#[Test]
	#[TestDox('a clean observation sweeps to nothing')]
	#[Group('strata/health')]
	public function cleanSweep(): void
	{
		$this->assertSame(
			[],
			TripwireRegistry::withStorageTripwires()->evaluate([
				'frame' => self::digest(),
				'frame_indexed' => true,
				'frame_present' => true,
				'decoded_hash' => self::digest(),
				'dictionary_present' => true,
				'delta_parent_present' => true,
				'max_depth' => 32,
				'delta_depth' => 0,
			]),
		);
	}

	#endregion
}
