<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Codec;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Codec\Dictionary\DictionaryRef;
use Drupal\strata\Codec\Dictionary\DictionaryTrainer;
use Drupal\strata\Codec\GzipCodec;
use Drupal\strata\Codec\NoneCodec;
use Drupal\strata\Codec\ZstdCodec;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(DictionaryRef::class)]
#[CoversClass(DictionaryTrainer::class)]
class DictionaryTest extends TestCase
{
	/**
	 * A corpus whose payloads are nearly identical.
	 *
	 * Where a raw content dictionary wins outright, because the next frame looks like the last one.
	 *
	 * @return list<string>
	 *   The payloads.
	 */
	private function uniformCorpus(int $count = 200): array
	{
		$samples = [];

		for ($i = 0; $i < $count; $i++) {
			$samples[] = (string) json_encode([
				'entity_type' => 'node',
				'bundle' => 'article',
				'nid' => [['value' => $i]],
				'langcode' => [['value' => 'en']],
				'title' => [['value' => 'Article number ' . $i]],
				'status' => [['value' => 1]],
				'created' => [['value' => 1_750_000_000 + $i]],
				'body' => [['value' => str_repeat('paragraph text ', 5 + ($i % 7))]],
			]);
		}

		return $samples;
	}

	/**
	 * A corpus of payloads with nothing in common.
	 *
	 * Where no dictionary can help, so the trainer has to say so rather than store one.
	 *
	 * @return list<string>
	 *   The payloads.
	 */
	private function randomCorpus(int $count = 200): array
	{
		$samples = [];

		for ($i = 0; $i < $count; $i++) {
			$samples[] = random_bytes(512);
		}

		return $samples;
	}

	private function trainer(): DictionaryTrainer
	{
		return new DictionaryTrainer(new ZstdCodec());
	}

	#region References

	#[Test]
	#[TestDox('a reference names the realm and version every frame records')]
	#[Group('strata/codec')]
	public function referenceNamesItself(): void
	{
		$ref = new DictionaryRef(
			'entity',
			3,
			Hash::of('dict'),
			1024,
			DictionaryRef::RAW,
			5.86,
			400,
			1,
		);

		$this->assertSame('entity:3', $ref->id());
		$this->assertSame('dicts/entity/3.zdict', $ref->key());
		$this->assertSame(['realm' => 'entity', 'version' => 3], DictionaryRef::parse('entity:3'));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function badIdProvider(): array
	{
		return [
			'no version' => ['entity'],
			'an empty realm' => [':3'],
			'an empty version' => ['entity:'],
			'a version that is not a number' => ['entity:latest'],
			'a zero version' => ['entity:0'],
		];
	}

	#[Test]
	#[TestDox('$_dataName does not parse as a dictionary id')]
	#[Group('strata/codec')]
	#[DataProvider('badIdProvider')]
	public function badIdsDoNotParse(string $id): void
	{
		$this->assertNull(DictionaryRef::parse($id));
	}

	#[Test]
	#[TestDox('a realm with a colon still round trips, since the version is read from the end')]
	#[Group('strata/codec')]
	public function realmWithAColonRoundTrips(): void
	{
		$ref = new DictionaryRef('table:custom', 2, Hash::of('d'));

		$this->assertSame('table:custom:2', $ref->id());
		$this->assertSame(
			['realm' => 'table:custom', 'version' => 2],
			DictionaryRef::parse($ref->id()),
		);
	}

	/**
	 * @return array<string, array{string, int, string, string}>
	 */
	public static function badReferenceProvider(): array
	{
		return [
			'an empty realm' => ['', 1, 'valid', 'must name the realm'],
			'a zero version' => ['entity', 0, 'valid', 'starts at one'],
			'a bad address' => ['entity', 1, 'not-a-digest', 'must be a valid digest'],
		];
	}

	#[Test]
	#[TestDox('$_dataName is refused')]
	#[Group('strata/codec')]
	#[DataProvider('badReferenceProvider')]
	public function badReferencesAreRefused(
		string $realm,
		int $version,
		string $address,
		string $message,
	): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		new DictionaryRef($realm, $version, $address === 'valid' ? Hash::of('d') : $address);
	}

	#[Test]
	#[TestDox('an unknown source is refused rather than stored as a typo')]
	#[Group('strata/codec')]
	public function unknownSourceIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown dictionary source');

		new DictionaryRef('entity', 1, Hash::of('d'), 10, 'guessed');
	}

	#endregion

	#region Training

	#[Test]
	#[TestDox('a dictionary trained on a realm beats compressing that realm without one')]
	#[Group('strata/codec')]
	public function trainingBeatsNoDictionary(): void
	{
		$trainer = $this->trainer();

		if (!(new ZstdCodec())->isAvailable()) {
			$this->markTestSkipped('zstd is not available on this host');
		}

		$samples = $this->uniformCorpus();
		$candidate = $trainer->train($samples);

		$this->assertNotNull($candidate);
		$this->assertGreaterThan(
			$trainer->baseline(array_slice($samples, 100)),
			$candidate['ratio'],
			'a stored dictionary always measured better than none',
		);
		$this->assertContains($candidate['source'], [DictionaryRef::RAW, DictionaryRef::TRAINED]);
		$this->assertNotSame('', $candidate['bytes']);
		$this->assertSame(count($samples), $candidate['samples']);
	}

	#[Test]
	#[TestDox('a dictionary is scored on samples it does not contain')]
	#[Group('strata/codec')]
	public function scoringUsesHeldOutSamples(): void
	{
		if (!(new ZstdCodec())->isAvailable()) {
			$this->markTestSkipped('zstd is not available on this host');
		}

		$candidate = $this->trainer()->train($this->uniformCorpus());

		$this->assertNotNull($candidate);

		// the raw candidate is built from the first half, so a ratio scored on it would be absurd
		$this->assertLessThan(500.0, $candidate['ratio'], 'the score is not measured on itself');
		$this->assertGreaterThan(1.5, $candidate['ratio']);
	}

	#[Test]
	#[TestDox('a corpus with nothing in common produces no dictionary rather than a useless one')]
	#[Group('strata/codec')]
	public function incompressibleCorpusProducesNothing(): void
	{
		if (!(new ZstdCodec())->isAvailable()) {
			$this->markTestSkipped('zstd is not available on this host');
		}

		$this->assertNull($this->trainer()->train($this->randomCorpus()));
	}

	#[Test]
	#[TestDox('too few samples describe one document rather than a realm, so nothing is trained')]
	#[Group('strata/codec')]
	public function tooFewSamplesTrainNothing(): void
	{
		$this->assertNull(
			$this->trainer()->train($this->uniformCorpus(DictionaryTrainer::MIN_SAMPLES - 1)),
		);
	}

	#[Test]
	#[TestDox('a codec with no dictionary support trains nothing, however good the samples are')]
	#[Group('strata/codec')]
	public function codecWithoutDictionarySupportTrainsNothing(): void
	{
		$this->assertFalse((new GzipCodec())->supportsDictionary());
		$this->assertNull((new DictionaryTrainer(new GzipCodec()))->train($this->uniformCorpus()));
		$this->assertNull((new DictionaryTrainer(new NoneCodec()))->train($this->uniformCorpus()));
	}

	#[Test]
	#[TestDox('a dictionary stays under the ceiling however many samples it is given')]
	#[Group('strata/codec')]
	public function dictionaryStaysUnderTheCeiling(): void
	{
		if (!(new ZstdCodec())->isAvailable()) {
			$this->markTestSkipped('zstd is not available on this host');
		}

		$candidate = $this->trainer()->train($this->uniformCorpus(2000));

		$this->assertNotNull($candidate);
		$this->assertLessThanOrEqual(DictionaryTrainer::MAX_BYTES, strlen($candidate['bytes']));
	}

	#[Test]
	#[TestDox('a frame compressed against a dictionary round trips through it')]
	#[Group('strata/codec')]
	public function dictionaryRoundTrips(): void
	{
		$codec = new ZstdCodec();

		if (!$codec->isAvailable()) {
			$this->markTestSkipped('zstd is not available on this host');
		}

		$samples = $this->uniformCorpus();
		$candidate = $this->trainer()->train($samples);

		$this->assertNotNull($candidate);

		$payload = $samples[7];
		$compressed = $codec->compress($payload, 19, $candidate['bytes']);

		$this->assertSame($payload, $codec->decompress($compressed, $candidate['bytes']));
		$this->assertLessThan(strlen($payload), strlen($compressed));
	}

	#[Test]
	#[
		TestDox(
			'decoding against the wrong dictionary fails rather than returning something plausible',
		),
	]
	#[Group('strata/codec')]
	public function wrongDictionaryFails(): void
	{
		$codec = new ZstdCodec();

		if (!$codec->isAvailable()) {
			$this->markTestSkipped('zstd is not available on this host');
		}

		$payload = (string) json_encode(['title' => 'a node', 'body' => str_repeat('text ', 40)]);
		$right = $this->trainer()->train($this->uniformCorpus());

		$this->assertNotNull($right);

		$compressed = $codec->compress($payload, 19, $right['bytes']);
		$decoded = null;

		try {
			$decoded = $codec->decompress($compressed, 'a different dictionary entirely');
		} catch (\RuntimeException) {
			$decoded = null;
		}

		$this->assertNotSame($payload, $decoded);
	}

	#[Test]
	#[TestDox('whether the host can train with the binary is reported rather than assumed')]
	#[Group('strata/codec')]
	public function trainingCapabilityIsReported(): void
	{
		$this->assertIsBool($this->trainer()->canTrain());
		$this->assertFalse(
			(new DictionaryTrainer(new ZstdCodec(), '/nonexistent/zstd'))->canTrain(),
		);
	}

	#endregion
}
