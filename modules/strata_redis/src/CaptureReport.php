<?php

declare(strict_types=1);

namespace Drupal\strata_redis;

use Drupal\strata\Capture\Classifier\Classification;
use JsonSerializable;

/**
 * What one ephemeral capture pass did.
 *
 * A pass is bounded, so "it finished" and "it ran out of budget" are different outcomes and the
 * caller has to be able to tell them apart: the first means the keyspace is described, the second
 * means the next pass has work left. `CaptureReport::stopped` carries which, and there is no outcome
 * that reads as success when the pass did not run.
 *
 * The number to watch is `CaptureReport::flagged`. Every key counted there was stored because nothing
 * recognised it, which is the safe default and also the one that spends storage on state nobody has
 * agreed to keep.
 *
 * @see RedisCapture
 * @see Classification
 */
final class CaptureReport implements JsonSerializable
{
	/**
	 * The pass read everything it was asked to.
	 */
	public const COMPLETE = 'complete';

	/**
	 * The pass stopped at its key limit.
	 */
	public const KEY_LIMIT = 'key_limit';

	/**
	 * The pass stopped at its byte budget.
	 */
	public const BYTE_BUDGET = 'byte_budget';

	/**
	 * The pass did not run because there is no Redis to read.
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * The pass did not run because the ephemeral realm is not captured.
	 */
	public const REALM_OFF = 'realm_off';

	/**
	 * The pass stopped on an error, which is reported rather than thrown.
	 */
	public const FAILED = 'failed';

	/**
	 * Outcomes that mean no key was looked at.
	 *
	 * @var list<string>
	 */
	public const DID_NOT_RUN = [self::UNAVAILABLE, self::REALM_OFF, self::FAILED];

	/**
	 * Constructs a report.
	 *
	 * @param string $stopped
	 *   Which outcome the pass reached, one of this class's own constants.
	 * @param string $reason
	 *   Why it stopped, or an empty string for a pass that simply finished.
	 * @param int $scanned
	 *   Keys the pass looked at.
	 * @param int $captured
	 *   Keys it stored.
	 * @param int $flagged
	 *   Keys it stored that nobody has classified.
	 * @param int $skipped
	 *   Keys it deliberately did not store.
	 * @param int $bytes
	 *   Raw value bytes stored, before the base64 the payload carries them in. The key names and a
	 *   sorted set's scores are not counted, so this is a lower bound on what the pass will cost.
	 * @param array<string, string> $reasons
	 *   Key name keyed to why it was skipped or flagged, up to the sample a report carries.
	 */
	public function __construct(
		public readonly string $stopped = self::COMPLETE,
		public readonly string $reason = '',
		public readonly int $scanned = 0,
		public readonly int $captured = 0,
		public readonly int $flagged = 0,
		public readonly int $skipped = 0,
		public readonly int $bytes = 0,
		public readonly array $reasons = [],
	) {}

	/**
	 * Whether the pass ran at all.
	 *
	 * A pass that could not look is not a pass that found nothing, and reporting the two the same way
	 * is how an uncaptured keyspace looks captured.
	 *
	 * @return bool
	 *   TRUE when the keyspace was walked.
	 */
	public function ran(): bool
	{
		return !in_array($this->stopped, self::DID_NOT_RUN, true);
	}

	/**
	 * Whether the pass saw the whole keyspace it was pointed at.
	 *
	 * @return bool
	 *   TRUE when neither bound was reached.
	 */
	public function isComplete(): bool
	{
		return $this->stopped === self::COMPLETE;
	}

	/**
	 * Whether the pass stored anything a person still has to rule on.
	 *
	 * @return bool
	 *   TRUE when at least one unclassified key was captured.
	 */
	public function needsDecisions(): bool
	{
		return $this->flagged > 0;
	}

	/**
	 * Why one key was skipped or flagged.
	 *
	 * @param string $key
	 *   The key.
	 *
	 * @return string
	 *   The reason, or an empty string when the pass recorded none for it.
	 */
	public function reasonFor(string $key): string
	{
		return $this->reasons[$key] ?? '';
	}

	/**
	 * A one-line summary for a log entry or a command's output.
	 *
	 * @return string
	 *   The summary.
	 */
	public function summary(): string
	{
		if (!$this->ran()) {
			return sprintf(
				'no ephemeral state was captured (%s): %s',
				$this->stopped,
				$this->reason === '' ? 'no reason was given' : $this->reason,
			);
		}

		$summary = sprintf(
			'captured %d of %d ephemeral keys in %d bytes, skipped %d, %d undecided',
			$this->captured,
			$this->scanned,
			$this->bytes,
			$this->skipped,
			$this->flagged,
		);

		return $this->isComplete() ? $summary : $summary . sprintf(' (%s)', $this->reason);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The report as a plain array.
	 */
	public function jsonSerialize(): array
	{
		return [
			'stopped' => $this->stopped,
			'reason' => $this->reason,
			'scanned' => $this->scanned,
			'captured' => $this->captured,
			'flagged' => $this->flagged,
			'skipped' => $this->skipped,
			'bytes' => $this->bytes,
			'reasons' => $this->reasons,
			'ran' => $this->ran(),
			'complete' => $this->isComplete(),
		];
	}
}
