<?php

declare(strict_types=1);

namespace Drupal\strata\Codec\Dictionary;

use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Segment\SegmentReader;
use Drupal\strata\Tree\CommitLog;
use Drupal\strata\Tree\RefStore;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Trains each realm's dictionary from what that realm has actually been writing.
 *
 * Samples come from the operations already in the store rather than from a fixture, because a
 * dictionary is only worth anything if it looks like the next frame. A site whose nodes all carry the
 * same twelve fields gets a dictionary full of those field names; a site with one node type gets a
 * different one, and neither is guessable in advance.
 *
 * Runs on cron and by hand, never on a flush. Building a candidate compresses the samples twice and
 * the winner is scored against a held-out slice, so a pass costs seconds - fine on a schedule,
 * impossible inside a web request.
 *
 * **A realm gets a new version only when one measures better than what it has.** Retraining for the
 * sake of it would add a version, strand nothing, and cost every new frame the same ratio, so the
 * gain is measured against the realm's current dictionary before anything is written.
 *
 * @see DictionaryTrainer
 * @see DictionaryStore
 */
final class DictionaryPass
{
	/**
	 * Segments read to collect samples.
	 *
	 * A bound on the pass rather than a policy: the newest segments describe what the site is writing
	 * now, and reading further back trains against a shape the site has moved on from.
	 */
	public const SEGMENT_LIMIT = 200;

	/**
	 * Most samples collected per realm.
	 */
	public const SAMPLE_LIMIT = 400;

	/**
	 * How much better a candidate has to be before it becomes a new version.
	 *
	 * Five per cent. Below that the new version costs a fetch and a cache entry for nothing.
	 */
	public const MIN_GAIN = 0.05;

	/**
	 * Constructs a pass.
	 *
	 * @param DictionaryTrainer $trainer
	 *   Builds and scores the candidates.
	 * @param DictionaryStore $store
	 *   Where a winning dictionary is stored.
	 * @param ObjectStore $objects
	 *   Fetches the payload frames samples are taken from.
	 * @param SegmentReader $segments
	 *   Reads the segment manifests that name them.
	 * @param CommitLog $commits
	 *   Walks history to find the segments.
	 * @param RefStore $refs
	 *   Resolves the ref to walk from.
	 * @param LoggerInterface $logger
	 *   Records what a pass did.
	 */
	public function __construct(
		private readonly DictionaryTrainer $trainer,
		private readonly DictionaryStore $store,
		private readonly ObjectStore $objects,
		private readonly SegmentReader $segments,
		private readonly CommitLog $commits,
		private readonly RefStore $refs,
		private readonly LoggerInterface $logger,
	) {}

	/**
	 * Trains every realm that has enough recent data.
	 *
	 * @param string $ref
	 *   Ref to collect samples from.
	 *
	 * @return array<string, array{stored: bool, reason: string, ratio: float, source: string}>
	 *   Realm value keyed to what happened, so a command can print a line per realm rather than one
	 *   number for the run.
	 */
	public function run(string $ref = RefStore::MAIN): array
	{
		$samples = $this->collect($ref);
		$results = [];

		foreach ($samples as $realm => $payloads) {
			$results[$realm] = $this->trainRealm((string) $realm, $payloads);
		}

		return $results;
	}

	/**
	 * Trains one realm.
	 *
	 * @param string $realm
	 *   Realm value.
	 * @param list<string> $samples
	 *   Payloads from that realm.
	 *
	 * @return array{stored: bool, reason: string, ratio: float, source: string}
	 *   What happened.
	 */
	public function trainRealm(string $realm, array $samples): array
	{
		if (count($samples) < DictionaryTrainer::MIN_SAMPLES) {
			return [
				'stored' => false,
				'reason' => sprintf(
					'%d samples, and %d is the fewest worth training from',
					count($samples),
					DictionaryTrainer::MIN_SAMPLES,
				),
				'ratio' => 0.0,
				'source' => '',
			];
		}

		try {
			$candidate = $this->trainer->train($samples);
		} catch (Throwable $error) {
			return [
				'stored' => false,
				'reason' => sprintf('training failed: %s', $error->getMessage()),
				'ratio' => 0.0,
				'source' => '',
			];
		}

		if ($candidate === null) {
			return [
				'stored' => false,
				'reason' => 'no candidate beat compressing without a dictionary',
				'ratio' => 0.0,
				'source' => '',
			];
		}

		$current = $this->store->latest($realm);

		if ($current !== null && $candidate['ratio'] < $current->ratio * (1.0 + self::MIN_GAIN)) {
			return [
				'stored' => false,
				'reason' => sprintf(
					'%.2fx against the %.2fx version %d already gets',
					$candidate['ratio'],
					$current->ratio,
					$current->version,
				),
				'ratio' => $candidate['ratio'],
				'source' => $candidate['source'],
			];
		}

		$ref = $this->store->store(
			$realm,
			$candidate['bytes'],
			$candidate['source'],
			$candidate['ratio'],
			$candidate['samples'],
		);

		$this->logger->info('Strata trained dictionary %id at %ratio', [
			'%id' => $ref->id(),
			'%ratio' => sprintf('%.2fx', $ref->ratio),
		]);

		return [
			'stored' => true,
			'reason' => sprintf('stored as %s', $ref->id()),
			'ratio' => $ref->ratio,
			'source' => $ref->source,
		];
	}

	/**
	 * Payload samples per realm, newest first.
	 *
	 * @param string $ref
	 *   Ref to walk.
	 *
	 * @return array<string, list<string>>
	 *   Realm value keyed to payloads.
	 */
	public function collect(string $ref = RefStore::MAIN): array
	{
		$head = $this->refs->read($ref);

		if ($head === null) {
			return [];
		}

		$samples = [];
		$read = 0;

		foreach ($this->commits->walk($head, self::SEGMENT_LIMIT) as $commit) {
			$key = $commit->metadata['segment'] ?? null;

			if (!is_string($key) || $key === '') {
				continue;
			}

			try {
				$manifest = $this->segments->read($key);
			} catch (Throwable) {
				// a segment that will not read is the verifier's problem, not the trainer's
				continue;
			}

			$read++;

			foreach ($manifest->operations as $operation) {
				$realm = $operation->realm->value;

				if (count($samples[$realm] ?? []) >= self::SAMPLE_LIMIT) {
					continue;
				}

				$payload = $this->payloadOf($manifest->payloadFor($operation));

				if ($payload !== null) {
					$samples[$realm][] = $payload;
				}
			}

			if ($this->isSatisfied($samples)) {
				break;
			}
		}

		$this->logger->info('Strata sampled %segments segments for %realms realms', [
			'%segments' => $read,
			'%realms' => count($samples),
		]);

		return $samples;
	}

	/**
	 * One operation's payload, reassembled from its frames.
	 *
	 * @param list<string> $frames
	 *   Frame addresses.
	 *
	 * @return string|null
	 *   The payload, or NULL when any frame could not be read.
	 */
	private function payloadOf(array $frames): ?string
	{
		if ($frames === []) {
			return null;
		}

		$payload = '';

		foreach ($frames as $frame) {
			try {
				$bytes = $this->objects->frame($frame);
			} catch (Throwable) {
				return null;
			}

			if ($bytes === null) {
				return null;
			}

			$payload .= $bytes;
		}

		return $payload === '' ? null : $payload;
	}

	/**
	 * Whether every realm seen so far has all the samples it can use.
	 *
	 * @param array<string, list<string>> $samples
	 *   What has been collected.
	 *
	 * @return bool
	 *   TRUE when nothing more can be added.
	 */
	private function isSatisfied(array $samples): bool
	{
		if ($samples === []) {
			return false;
		}

		foreach ($samples as $payloads) {
			if (count($payloads) < self::SAMPLE_LIMIT) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Every realm that could carry a dictionary.
	 *
	 * @return list<string>
	 *   Realm values.
	 */
	public static function realms(): array
	{
		$realms = [];

		foreach (Realm::cases() as $realm) {
			$realms[] = $realm->value;
		}

		return $realms;
	}
}
