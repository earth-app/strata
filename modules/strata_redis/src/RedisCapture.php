<?php

declare(strict_types=1);

namespace Drupal\strata_redis;

use Drupal\strata\Capture\Classifier\Classification;
use Drupal\strata\Capture\Classifier\EphemeralScope;
use Drupal\strata\Capture\KeyRecorder;
use Drupal\strata\Capture\PayloadCodec;
use Drupal\strata\Journal\Realm;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stores the parts of a Redis keyspace that are the only copy of something.
 *
 * Three rules decide every key, and the third is the one that keeps this honest:
 *
 * - **Authoritative is captured.** A queue holding work nobody else recorded, a flood counter
 *   enforcing a lockout, a session someone is logged in with.
 * - **Derivable is skipped.** A cache bin rebuilds itself the moment it is asked for, so storing it
 *   spends storage on something a restore would never write back.
 * - **Unclassified is captured AND flagged.** Nothing recognised the key, which is a gap in the rules
 *   rather than a statement that the key does not matter. It is stored verbatim and counted as needing
 *   a decision, never dropped quietly: a backup that silently skips what it does not recognise has
 *   gaps nobody knows about.
 *
 * **A pass is bounded by keys and by bytes.** The key bound keeps the walk off a busy server. The
 * byte bound is separate because one authoritative hash can be larger than the whole rest of the
 * pass, and a window whose size is decided by the size of a cache is not a window. The budget bounds
 * a pass rather than vetoing a key: a value larger than the entire budget is captured on its own and
 * the pass stops there, so it can never be the key that every pass skips.
 *
 * **Only types that come back byte for byte are stored.** A Redis value is a string, hash, list, set,
 * sorted set or stream. The first five are read whole by one command and stored as raw bytes, base64
 * encoded because the payload is JSON and a Redis value is arbitrary binary. A stream is not: its
 * entry ids and consumer-group offsets are not reproduced by any single read, so what `XRANGE`
 * returns is a rendering rather than the value. A stream is skipped with a recorded reason, because a
 * lossy copy is worse than a named gap.
 *
 * **Redis being absent is not an error.** Every path ends in a report saying what happened, and the
 * cron hook above this never sees an exception.
 *
 * @see CaptureReport
 * @see EphemeralScope
 * @see KeyRecorder
 * @see RedisKeyspaceSource
 */
final class RedisCapture
{
	#region Bounds

	/**
	 * Keys one pass reads when nothing else is asked for.
	 */
	public const DEFAULT_KEYS = 5000;

	/**
	 * Bytes one pass captures when nothing else is asked for.
	 *
	 * 8 MiB. A pass that pulled more than this into one journal window would make the window's size
	 * depend on how large a site's ephemeral state is rather than on how much of it changed.
	 */
	public const DEFAULT_BYTES = 8_388_608;

	/**
	 * Per-key reasons one report carries.
	 *
	 * A report is read by a person and logged, so it names a bounded sample of what it skipped rather
	 * than every key of a five thousand key pass.
	 */
	public const REASON_LIMIT = 50;

	#endregion

	#region Types

	/**
	 * A single value.
	 */
	public const TYPE_STRING = 'string';

	/**
	 * A field map.
	 */
	public const TYPE_HASH = 'hash';

	/**
	 * An ordered sequence.
	 */
	public const TYPE_LIST = 'list';

	/**
	 * An unordered collection of unique members.
	 */
	public const TYPE_SET = 'set';

	/**
	 * Members carrying a score.
	 */
	public const TYPE_ZSET = 'zset';

	/**
	 * An append-only log with server-assigned ids.
	 */
	public const TYPE_STREAM = 'stream';

	/**
	 * The reply codes `TYPE` answers with, keyed to the name each one means.
	 *
	 * A client may answer with the code or with the name, so both forms are accepted and neither is
	 * relied on.
	 *
	 * @var array<int, string>
	 */
	public const TYPES = [
		1 => self::TYPE_STRING,
		2 => self::TYPE_SET,
		3 => self::TYPE_LIST,
		4 => self::TYPE_ZSET,
		5 => self::TYPE_HASH,
		6 => self::TYPE_STREAM,
	];

	/**
	 * Types that are stored, keyed to the command that reads one whole.
	 *
	 * Everything absent from this map is skipped with a reason rather than stored approximately.
	 *
	 * @var array<string, string>
	 */
	public const COMMANDS = [
		self::TYPE_STRING => 'get',
		self::TYPE_HASH => 'hGetAll',
		self::TYPE_LIST => 'lRange',
		self::TYPE_SET => 'sMembers',
		self::TYPE_ZSET => 'zRange',
	];

	/**
	 * The field naming which Redis type a payload holds.
	 */
	public const FIELD_TYPE = 'type';

	/**
	 * The field naming how the bytes in a payload are encoded.
	 */
	public const FIELD_ENCODING = 'encoding';

	/**
	 * How every byte string in a payload is encoded.
	 *
	 * The ephemeral payload is JSON and a Redis value is arbitrary binary, which JSON cannot carry:
	 * `json_encode()` refuses a string that is not valid UTF-8 and would leave an empty payload
	 * behind. Base64 costs a third more bytes and makes the value readable back exactly.
	 */
	public const ENCODING = 'base64';

	#endregion

	/**
	 * Constructs a capture.
	 *
	 * @param RedisKeyspaceSource $keys
	 *   Walks the keyspace with a cursor.
	 * @param RedisClientFactory $clients
	 *   Resolves the client the values are read with.
	 * @param EphemeralScope $ephemeral
	 *   Decides what each key is, and whether the realm is captured at all.
	 * @param KeyRecorder $recorder
	 *   Appends a captured key to the journal.
	 * @param LoggerInterface $logger
	 *   Records what a pass did.
	 * @param int $keyLimit
	 *   Keys one pass reads.
	 * @param int $byteBudget
	 *   Bytes one pass captures.
	 */
	public function __construct(
		private readonly RedisKeyspaceSource $keys,
		private readonly RedisClientFactory $clients,
		private readonly EphemeralScope $ephemeral,
		private readonly KeyRecorder $recorder,
		private readonly LoggerInterface $logger,
		private readonly int $keyLimit = self::DEFAULT_KEYS,
		private readonly int $byteBudget = self::DEFAULT_BYTES,
	) {}

	#region Capture

	/**
	 * Captures one pass over the keyspace.
	 *
	 * @param int|null $limit
	 *   Most keys to read, or NULL for the configured limit.
	 * @param int|null $budget
	 *   Most bytes to capture, or NULL for the configured budget.
	 *
	 * @return CaptureReport
	 *   What the pass did, including the case where it could not run.
	 */
	public function capture(?int $limit = null, ?int $budget = null): CaptureReport
	{
		if (!$this->recorder->covers(Realm::EPHEMERAL)) {
			return new CaptureReport(
				CaptureReport::REALM_OFF,
				'ephemeral capture is switched off, so no key is captured',
			);
		}

		$client = $this->clients->create();

		if ($client === null || !$this->keys->isAvailable()) {
			return new CaptureReport(CaptureReport::UNAVAILABLE, $this->keys->reason());
		}

		try {
			return $this->logged(
				$this->pass(
					$client,
					max(1, $limit ?? $this->keyLimit),
					max(1, $budget ?? $this->byteBudget),
				),
			);
		} catch (Throwable $error) {
			// a capture pass never breaks the cron run that asked for it
			$this->logger->error('Strata could not capture ephemeral state: %message', [
				'%message' => $error->getMessage(),
			]);

			return new CaptureReport(CaptureReport::FAILED, $error->getMessage());
		}
	}

	/**
	 * Walks the keyspace and records what belongs in the journal.
	 *
	 * @param object $client
	 *   The client values are read with.
	 * @param int $limit
	 *   Most keys to read.
	 * @param int $budget
	 *   Most bytes to capture.
	 *
	 * @return CaptureReport
	 *   What the pass did.
	 */
	private function pass(object $client, int $limit, int $budget): CaptureReport
	{
		$scanned = 0;
		$captured = 0;
		$flagged = 0;
		$skipped = 0;
		$bytes = 0;
		$reasons = [];
		$stopped = CaptureReport::COMPLETE;
		$reason = '';

		foreach ($this->keys->keys($limit) as $key => $size) {
			$name = (string) $key;
			$scanned++;
			$classification = $this->ephemeral->classify($name);

			if (!$classification->isCaptured()) {
				$skipped++;
				self::note($reasons, $name, 'derivable, so a restore would never write it back');

				continue;
			}

			$why = '';
			$read = $this->read($client, $name, $why);

			if ($read === null) {
				$skipped++;
				self::note($reasons, $name, $why);

				continue;
			}

			// the budget bounds a pass and never vetoes a key: a value larger than the whole budget
			// is captured on its own, so it cannot be the key every pass skips
			if ($captured > 0 && $bytes + $read['bytes'] > $budget) {
				$stopped = CaptureReport::BYTE_BUDGET;
				$reason = sprintf('the %d byte budget was reached at %s', $budget, $name);

				break;
			}

			$this->store($name, $read['payload']);
			$captured++;
			$bytes += $read['bytes'];

			if ($classification->needsDecision()) {
				$flagged++;
				self::note(
					$reasons,
					$name,
					'captured verbatim and waiting for someone to decide it',
				);
			}
		}

		if ($stopped === CaptureReport::COMPLETE && $scanned >= $limit) {
			$stopped = CaptureReport::KEY_LIMIT;
			$reason = sprintf('the pass stopped at its limit of %d keys', $limit);
		}

		return new CaptureReport(
			$stopped,
			$reason,
			$scanned,
			$captured,
			$flagged,
			$skipped,
			$bytes,
			$reasons,
		);
	}

	/**
	 * Appends one captured key.
	 *
	 * Recorded as an update rather than a create. A periodic pass observes that a key holds a value;
	 * it does not see the moment the value was written, so the first capture of a key is not
	 * distinguishable from the fortieth.
	 *
	 * @param string $key
	 *   The Redis key, used verbatim as the subject.
	 * @param array<string, mixed> $payload
	 *   The field map to store.
	 */
	private function store(string $key, array $payload): void
	{
		$this->recorder->written(Realm::EPHEMERAL, $key, $payload, true);
	}

	#endregion

	#region Reading

	/**
	 * Reads one key, or says why it was not stored.
	 *
	 * @param object $client
	 *   The client.
	 * @param string $key
	 *   The key.
	 * @param string $why
	 *   Set to the reason when nothing is returned.
	 *
	 * @return array{payload: array<string, mixed>, bytes: int}|null
	 *   The field map and the raw bytes it holds, or NULL when the value cannot be stored exactly.
	 */
	private function read(object $client, string $key, string &$why): ?array
	{
		$type = $this->typeOfKey($client, $key);

		if ($type === '') {
			$why = 'the key went away, or holds a type this release does not recognise';

			return null;
		}
		if (!isset(self::COMMANDS[$type])) {
			$why = sprintf(
				'a %s is not read back byte for byte by one command, so it is named rather than stored',
				$type,
			);

			return null;
		}

		$command = self::COMMANDS[$type];

		if (!method_exists($client, $command)) {
			$why = sprintf(
				'the client does not support %s, which reading a %s needs',
				$command,
				$type,
			);

			return null;
		}
		if ($type === self::TYPE_STRING) {
			return $this->readString($client, $key, $why);
		}
		if ($type === self::TYPE_LIST || $type === self::TYPE_SET) {
			return $this->readMembers($client, $key, $type, $why);
		}

		return $this->readPairs($client, $key, $type, $why);
	}

	/**
	 * Reads a string key.
	 *
	 * @param object $client
	 *   The client.
	 * @param string $key
	 *   The key.
	 * @param string $why
	 *   Set to the reason when nothing is returned.
	 *
	 * @return array{payload: array<string, mixed>, bytes: int}|null
	 *   The field map and its raw bytes, or NULL.
	 */
	private function readString(object $client, string $key, string &$why): ?array
	{
		/** @var mixed $value */
		$value = $client->get($key);

		if (!is_string($value)) {
			$why = sprintf(
				'the value came back as %s rather than bytes, so it is not stored',
				get_debug_type($value),
			);

			return null;
		}

		return [
			'payload' => self::payload(self::TYPE_STRING, base64_encode($value)),
			'bytes' => strlen($value),
		];
	}

	/**
	 * Reads a list or a set.
	 *
	 * @param object $client
	 *   The client.
	 * @param string $key
	 *   The key.
	 * @param string $type
	 *   RedisCapture::TYPE_LIST or RedisCapture::TYPE_SET.
	 * @param string $why
	 *   Set to the reason when nothing is returned.
	 *
	 * @return array{payload: array<string, mixed>, bytes: int}|null
	 *   The field map and its raw bytes, or NULL. A list keeps the order Redis returned it in, which
	 *   is the order the list holds; a set has none to keep.
	 */
	private function readMembers(object $client, string $key, string $type, string &$why): ?array
	{
		/** @var mixed $members */
		$members =
			$type === self::TYPE_LIST ? $client->lRange($key, 0, -1) : $client->sMembers($key);

		if (!is_array($members)) {
			$why = sprintf('the %s did not come back as a collection, so it is not stored', $type);

			return null;
		}

		$encoded = [];
		$bytes = 0;

		foreach ($members as $member) {
			if (!is_string($member)) {
				$why = sprintf(
					'a member of the %s came back as %s rather than bytes, so the key is not stored',
					$type,
					get_debug_type($member),
				);

				return null;
			}

			$encoded[] = base64_encode($member);
			$bytes += strlen($member);
		}

		return ['payload' => self::payload($type, $encoded), 'bytes' => $bytes];
	}

	/**
	 * Reads a hash or a sorted set.
	 *
	 * @param object $client
	 *   The client.
	 * @param string $key
	 *   The key.
	 * @param string $type
	 *   RedisCapture::TYPE_HASH or RedisCapture::TYPE_ZSET.
	 * @param string $why
	 *   Set to the reason when nothing is returned.
	 *
	 * @return array{payload: array<string, mixed>, bytes: int}|null
	 *   The field map and its raw bytes, or NULL. A hash stores base64 field names against base64
	 *   values; a sorted set stores base64 members against their scores, in the score order Redis
	 *   returned them in.
	 */
	private function readPairs(object $client, string $key, string $type, string &$why): ?array
	{
		/** @var mixed $pairs */
		$pairs =
			$type === self::TYPE_HASH ? $client->hGetAll($key) : $client->zRange($key, 0, -1, true);

		if (!is_array($pairs)) {
			$why = sprintf('the %s did not come back as a map, so it is not stored', $type);

			return null;
		}

		$encoded = [];
		$bytes = 0;

		foreach ($pairs as $member => $value) {
			// a numeric field name arrives as an integer array key, and casting it back is exact
			$name = (string) $member;
			$bytes += strlen($name);

			if ($type === self::TYPE_ZSET) {
				if (!self::isScore($value)) {
					$why = sprintf(
						'the score for %s came back as %s rather than a number, so the key is not stored',
						$name,
						get_debug_type($value),
					);

					return null;
				}

				$encoded[base64_encode($name)] = (float) $value;

				continue;
			}
			if (!is_string($value)) {
				$why = sprintf(
					'the field %s came back as %s rather than bytes, so the key is not stored',
					$name,
					get_debug_type($value),
				);

				return null;
			}

			$encoded[base64_encode($name)] = base64_encode($value);
			$bytes += strlen($value);
		}

		return ['payload' => self::payload($type, $encoded), 'bytes' => $bytes];
	}

	/**
	 * What type a key holds.
	 *
	 * @param object $client
	 *   The client.
	 * @param string $key
	 *   The key.
	 *
	 * @return string
	 *   A type name, or an empty string when the key is gone or the reply is unrecognised.
	 */
	private function typeOfKey(object $client, string $key): string
	{
		if (!method_exists($client, 'type')) {
			return '';
		}

		try {
			/** @var mixed $reply */
			$reply = $client->type($key);
		} catch (Throwable) {
			return '';
		}

		return self::typeOf($reply);
	}

	#endregion

	#region Shapes

	/**
	 * The type name a `TYPE` reply means.
	 *
	 * @param mixed $reply
	 *   The reply, either a code or a name.
	 *
	 * @return string
	 *   The type name, or an empty string for a missing key and for anything unrecognised.
	 */
	public static function typeOf(mixed $reply): string
	{
		if (is_int($reply)) {
			return self::TYPES[$reply] ?? '';
		}
		if (!is_string($reply)) {
			return '';
		}

		$name = strtolower(trim($reply));

		return in_array($name, self::TYPES, true) ? $name : '';
	}

	/**
	 * One payload, ready for the journal.
	 *
	 * @param string $type
	 *   The Redis type the value came from, so a reader knows what shape the value is in.
	 * @param mixed $value
	 *   The encoded value.
	 *
	 * @return array<string, mixed>
	 *   The field map.
	 */
	private static function payload(string $type, mixed $value): array
	{
		return [
			self::FIELD_TYPE => $type,
			self::FIELD_ENCODING => self::ENCODING,
			PayloadCodec::VALUE => $value,
		];
	}

	/**
	 * Whether a value is a sorted-set score.
	 *
	 * Accepted as a float, as an integer and as a numeric string, because which of the three arrives
	 * depends on the client rather than on what Redis holds.
	 *
	 * @param mixed $value
	 *   The value.
	 *
	 * @return bool
	 *   TRUE when it converts to a float exactly.
	 */
	private static function isScore(mixed $value): bool
	{
		if (is_float($value) || is_int($value)) {
			return true;
		}

		return is_string($value) && is_numeric($value);
	}

	/**
	 * Records a per-key reason, up to the sample a report carries.
	 *
	 * @param array<string, string> $reasons
	 *   The reasons so far.
	 * @param string $key
	 *   The key.
	 * @param string $why
	 *   The reason.
	 */
	private static function note(array &$reasons, string $key, string $why): void
	{
		if (count($reasons) < self::REASON_LIMIT) {
			$reasons[$key] = $why;
		}
	}

	/**
	 * Logs a finished pass and hands the report back.
	 *
	 * A pass that captured something undecided is a warning rather than a note. `Classification`
	 * keeps an unrecognised key instead of dropping it, and the cost of that choice is storage
	 * nobody has agreed to yet.
	 *
	 * @param CaptureReport $report
	 *   The report.
	 *
	 * @return CaptureReport
	 *   The same report.
	 */
	private function logged(CaptureReport $report): CaptureReport
	{
		$this->logger->info('Strata %summary', ['%summary' => $report->summary()]);

		if ($report->needsDecisions()) {
			$this->logger->warning(
				'Strata captured %flagged ephemeral keys nobody has classified, which are stored and never restored until someone decides them',
				['%flagged' => $report->flagged],
			);
		}

		return $report;
	}

	#endregion
}
