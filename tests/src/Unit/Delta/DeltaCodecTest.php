<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Delta;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Codec\CompressionCodecInterface;
use Drupal\strata\Codec\GzipCodec;
use Drupal\strata\Codec\ZstdCodec;
use Drupal\strata\Codec\ZstdPipeCodec;
use Drupal\strata\Delta\ChainDepthPolicy;
use Drupal\strata\Delta\DeltaCodec;
use Drupal\strata\Delta\DeltaFrame;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DeltaCodec::class)]
class DeltaCodecTest extends TestCase
{
	#region Fixtures

	/**
	 * A dictionary-capable codec, or a skip when this host has neither.
	 */
	private function dictionaryCodec(): CompressionCodecInterface
	{
		foreach ([new ZstdCodec(), new ZstdPipeCodec()] as $codec) {
			if ($codec->isAvailable() && $codec->supportsDictionary()) {
				return $codec;
			}
		}

		$this->markTestSkipped('no dictionary-capable zstd is available on this host');
	}

	/**
	 * A notification blob and the same blob with one flag flipped and one entry appended.
	 *
	 * @return array{string, string}
	 *   The previous and current values.
	 */
	private function rewrittenBlob(): array
	{
		mt_srand(1215);
		$entries = [];
		for ($i = 0; $i < 25; $i++) {
			$entries[] = [
				'id' => bin2hex(random_bytes(8)),
				'title' => 'kudos trail nature minute',
				'message' => 'expedition prompt response article activity event circle',
				'read' => false,
				'created' => 1_755_000_000 + $i,
			];
		}
		$previous = (string) json_encode($entries);

		$entries[0]['read'] = true;
		$entries[] = [
			'id' => bin2hex(random_bytes(8)),
			'title' => 'badge quest',
			'message' => 'leaderboard referral notification',
			'read' => false,
			'created' => 1_755_900_000,
		];

		return [$previous, (string) json_encode($entries)];
	}

	#endregion

	#region Encoding

	#[Test]
	#[TestDox('with no previous version every frame is an anchor')]
	#[Group('strata/delta')]
	public function noPreviousVersionAnchors(): void
	{
		$codec = new DeltaCodec($this->dictionaryCodec(), new ChainDepthPolicy(), 19);
		$frame = $codec->encode(str_repeat('content ', 200));

		$this->assertTrue($frame->isAnchor());
		$this->assertSame(DeltaFrame::MODE_ANCHOR, $frame->mode);
		$this->assertNull($frame->parent);
	}

	#[Test]
	#[TestDox('an empty previous version anchors rather than encoding against nothing')]
	#[Group('strata/delta')]
	public function emptyPreviousAnchors(): void
	{
		$codec = new DeltaCodec($this->dictionaryCodec(), new ChainDepthPolicy(), 19);

		$this->assertTrue($codec->encode(str_repeat('c ', 200), '')->isAnchor());
	}

	#[Test]
	#[TestDox('a rewritten blob encodes far smaller against its previous version')]
	#[Group('strata/delta')]
	public function rewrittenBlobShrinksSharplyAsDelta(): void
	{
		$codec = new DeltaCodec($this->dictionaryCodec(), new ChainDepthPolicy(), 19);
		[$previous, $current] = $this->rewrittenBlob();

		$anchor = $codec->encode($current);
		$delta = $codec->encode($current, $previous);

		$this->assertFalse($delta->isAnchor());
		$this->assertSame(1, $delta->depth);
		$this->assertSame(Hash::of($previous), $delta->parent);

		// the measured win on this shape of change is an order of magnitude, not a few percent
		$this->assertLessThan($anchor->length() / 4, $delta->length());
		$this->assertGreaterThan($anchor->ratio(), $delta->ratio());
	}

	#[Test]
	#[TestDox('a delta that would not pay for itself falls back to an anchor')]
	#[Group('strata/delta')]
	public function unprofitableDeltaFallsBackToAnchor(): void
	{
		$codec = new DeltaCodec($this->dictionaryCodec(), new ChainDepthPolicy(), 19);
		[$previous] = $this->rewrittenBlob();

		// nothing in common with the parent, so the dependency would buy nothing
		$unrelated = (string) json_encode(
			array_map(
				static fn(int $i): array => [
					'sku' => $i,
					'price' => $i * 37,
					'label' => "widget $i",
				],
				range(1, 120),
			),
		);

		$this->assertTrue($codec->encode($unrelated, $previous)->isAnchor());
	}

	#[Test]
	#[TestDox('a codec without dictionary support anchors every frame and says so')]
	#[Group('strata/delta')]
	public function codecWithoutDictionarySupportAlwaysAnchors(): void
	{
		$codec = new DeltaCodec(new GzipCodec(), new ChainDepthPolicy());
		[$previous, $current] = $this->rewrittenBlob();

		$this->assertFalse($codec->canDelta());
		$this->assertTrue($codec->encode($current, $previous)->isAnchor());
	}

	#[Test]
	#[TestDox('a chain at the depth cap anchors instead of adding another link')]
	#[Group('strata/delta')]
	public function chainAnchorsAtTheDepthCap(): void
	{
		$codec = new DeltaCodec($this->dictionaryCodec(), new ChainDepthPolicy(3), 19);
		[$previous, $current] = $this->rewrittenBlob();

		$this->assertFalse($codec->encode($current, $previous, null, 2)->isAnchor());
		$this->assertTrue($codec->encode($current, $previous, null, 3)->isAnchor());
	}

	#[Test]
	#[TestDox('a supplied parent digest is used instead of being recomputed')]
	#[Group('strata/delta')]
	public function suppliedParentDigestIsUsed(): void
	{
		$codec = new DeltaCodec($this->dictionaryCodec(), new ChainDepthPolicy(), 19);
		[$previous, $current] = $this->rewrittenBlob();

		$frame = $codec->encode($current, $previous, Hash::of($previous), 0);

		$this->assertSame(Hash::of($previous), $frame->parent);
	}

	#endregion

	#region Decoding

	#[Test]
	#[TestDox('an anchor decodes with no parent')]
	#[Group('strata/delta')]
	public function anchorDecodesAlone(): void
	{
		$codec = new DeltaCodec($this->dictionaryCodec(), new ChainDepthPolicy(), 19);
		$payload = str_repeat('anchor payload ', 300);

		$this->assertSame($payload, $codec->decode($codec->encode($payload)));
	}

	#[Test]
	#[TestDox('a delta decodes back to the exact original given its parent')]
	#[Group('strata/delta')]
	public function deltaDecodesExactly(): void
	{
		$codec = new DeltaCodec($this->dictionaryCodec(), new ChainDepthPolicy(), 19);
		[$previous, $current] = $this->rewrittenBlob();

		$frame = $codec->encode($current, $previous);

		$this->assertSame($current, $codec->decode($frame, $previous));
	}

	#[Test]
	#[TestDox('a delta handed no parent names the parent it needs')]
	#[Group('strata/delta')]
	public function deltaWithoutParentNamesIt(): void
	{
		$codec = new DeltaCodec($this->dictionaryCodec(), new ChainDepthPolicy(), 19);
		[$previous, $current] = $this->rewrittenBlob();
		$frame = $codec->encode($current, $previous);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(Hash::abbreviate((string) $frame->parent));

		$codec->decode($frame);
	}

	#[Test]
	#[TestDox('a delta handed the wrong parent raises instead of decoding garbage')]
	#[Group('strata/delta')]
	public function deltaWithWrongParentRaises(): void
	{
		$codec = new DeltaCodec($this->dictionaryCodec(), new ChainDepthPolicy(), 19);
		[$previous, $current] = $this->rewrittenBlob();
		$frame = $codec->encode($current, $previous);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('was given a different value');

		$codec->decode($frame, 'a completely different previous version');
	}

	#[Test]
	#[TestDox('a frame whose recorded length disagrees with the decode raises')]
	#[Group('strata/delta')]
	public function truncatedDecodeIsCaught(): void
	{
		$underlying = $this->dictionaryCodec();
		$codec = new DeltaCodec($underlying, new ChainDepthPolicy(), 19);
		$payload = str_repeat('length check ', 200);

		$honest = $codec->encode($payload);
		$lying = new DeltaFrame(
			DeltaFrame::MODE_ANCHOR,
			$honest->codec,
			$honest->bytes,
			null,
			0,
			$honest->plainLength + 1,
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('the frame is truncated or corrupt');

		$codec->decode($lying);
	}

	#endregion

	#region Chains

	#[Test]
	#[TestDox('a chain of revisions decodes back to every original in order')]
	#[Group('strata/delta')]
	public function chainDecodesEveryRevision(): void
	{
		$codec = new DeltaCodec($this->dictionaryCodec(), new ChainDepthPolicy(8), 19);

		$entries = [];
		for ($i = 0; $i < 20; $i++) {
			$entries[] = ['id' => $i, 'title' => "entry $i", 'read' => false];
		}

		$revisions = [];
		$frames = [];
		$previous = null;
		$depth = 0;

		for ($r = 0; $r < 8; $r++) {
			$entries[$r]['read'] = true;
			$value = (string) json_encode($entries);
			$frame = $codec->encode($value, $previous, null, $depth);

			$revisions[] = $value;
			$frames[] = $frame;
			$previous = $value;
			$depth = $frame->depth;
		}

		$this->assertSame($revisions[0], $codec->decode($frames[0]));

		foreach ($frames as $index => $frame) {
			$parent = $index === 0 ? null : $revisions[$index - 1];
			$this->assertSame($revisions[$index], $codec->decode($frame, $parent));
		}
	}

	#[Test]
	#[TestDox('decodeChain() walks an anchor-first chain to the final value')]
	#[Group('strata/delta')]
	public function decodeChainWalksToTheEnd(): void
	{
		$codec = new DeltaCodec($this->dictionaryCodec(), new ChainDepthPolicy(8), 19);

		// a large stable structure with a small mutation per revision, which is the shape a delta
		// actually wins on; a tiny value cannot beat its own anchor by MIN_GAIN and would anchor
		$entries = [];
		for ($i = 0; $i < 60; $i++) {
			$entries[] = [
				'id' => sprintf('%08x', $i * 2654435761),
				'title' => 'kudos trail nature minute expedition',
				'message' => 'prompt response article activity event circle badge quest cosmetic',
				'read' => false,
				'created' => 1_755_000_000 + $i,
			];
		}

		$values = [];
		$frames = [];
		$previous = null;
		$depth = 0;

		for ($r = 0; $r < 5; $r++) {
			$entries[$r]['read'] = true;
			$value = (string) json_encode($entries);
			$frame = $codec->encode($value, $previous, null, $depth);

			$values[] = $value;
			$frames[] = $frame;
			$previous = $value;
			$depth = $frame->depth;
		}

		// the fixture must actually exercise a chain, or this asserts nothing
		$this->assertGreaterThan(0, $frames[4]->depth, 'the fixture produced no delta at all');
		$this->assertSame(end($values), $codec->decodeChain($frames));
	}

	#[Test]
	#[TestDox('decodeChain() treats a mid-chain anchor as a restart, not a discontinuity')]
	#[Group('strata/delta')]
	public function decodeChainAcceptsMidChainAnchor(): void
	{
		$underlying = $this->dictionaryCodec();
		$codec = new DeltaCodec($underlying, new ChainDepthPolicy(8), 19);
		[$previous, $current] = $this->rewrittenBlob();

		$anchor = $codec->encode($previous);
		$delta = $codec->encode($current, $previous, null, 0);
		$restart = $codec->encode($previous);

		$this->assertTrue($anchor->isAnchor());
		$this->assertFalse($delta->isAnchor());
		$this->assertTrue($restart->isAnchor());

		// the anchor at position 2 discards everything before it and the walk continues
		$this->assertSame($previous, $codec->decodeChain([$anchor, $delta, $restart]));
	}

	#[Test]
	#[TestDox('a chain of nothing but anchors decodes to its last value')]
	#[Group('strata/delta')]
	public function allAnchorChainDecodes(): void
	{
		$codec = new DeltaCodec($this->dictionaryCodec(), new ChainDepthPolicy(8), 19);

		// encoding with no previous version always anchors, whatever the codec is tuned to
		$values = [];
		$frames = [];

		for ($r = 0; $r < 4; $r++) {
			$value = (string) json_encode([
				'revision' => $r,
				'body' => str_repeat("word $r ", 200),
			]);
			$values[] = $value;
			$frames[] = $codec->encode($value);
		}

		foreach ($frames as $frame) {
			$this->assertTrue($frame->isAnchor());
		}

		$this->assertSame(end($values), $codec->decodeChain($frames));
	}

	#endregion
}
