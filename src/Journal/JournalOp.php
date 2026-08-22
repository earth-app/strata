<?php

declare(strict_types=1);

namespace Drupal\strata\Journal;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Segment\Collapser;
use InvalidArgumentException;
use JsonSerializable;
use ValueError;

/**
 * One captured mutation, and the only shape the journal appends.
 *
 * A value object rather than an array, so a misspelled key is a type error at the call site instead
 * of a silently absent field, and so the fields a restore depends on are checked once, here, rather
 * than again in every writer that reads them.
 *
 * An op describes a mutation and never carries the value. The value is in the object store under
 * $payloadHash, which keeps a journal row the same size whether the subject is a taxonomy term or a
 * 200 MiB file. $parentHash names the version the mutation was applied to, so a chain can be walked
 * backwards without reading a payload at all.
 *
 * $sequence orders every op in a window and is assigned by the journal on append, so a caller
 * builds the op and gets its number back through withSequence().
 *
 * @see Realm
 * @see Verb
 * @see Collapser
 */
final class JournalOp implements JsonSerializable
{
	/**
	 * Microseconds in a second, the unit $microtime is recorded in.
	 */
	public const MICROSECONDS_PER_SECOND = 1000000;

	/**
	 * Constructs an op.
	 *
	 * @param int $sequence
	 *   Position in the window, assigned by the journal on append. Zero or more.
	 * @param int $microtime
	 *   Unix timestamp in microseconds, as `intval(microtime(true) * 1e6)`.
	 * @param Realm $realm
	 *   The subsystem the subject lives in.
	 * @param string $subject
	 *   What was mutated, addressed the way its realm addresses things: `node:42`,
	 *   `user.settings`, `mantle2_api_keys:pk=17`.
	 * @param Verb $verb
	 *   What the mutation did.
	 * @param int|null $actor
	 *   Uid that caused the mutation, or NULL for cron, drush and anything else with no session.
	 * @param string|null $requestId
	 *   Identifier of the request the mutation happened in, so ops that belong to one page
	 *   submission can be grouped after the fact. NULL when the writer did not record one.
	 * @param string|null $payloadHash
	 *   Digest of the value after the mutation, or NULL when there is no value to store, as for a
	 *   delete.
	 * @param string|null $parentHash
	 *   Digest of the value before the mutation, or NULL when the subject had no previous version.
	 * @param int $payloadLength
	 *   Length of the value in bytes, so a window can be totalled without reading the store.
	 * @param string $label
	 *   Human-readable description for the UI, such as "Article: Hello world".
	 * @param list<string> $fields
	 *   Top-level field names the mutation touched. Empty when the writer does not track fields, or
	 *   when the verb replaces the whole subject.
	 *
	 * @throws InvalidArgumentException
	 *   When a number is out of range, the subject is empty, a hash is not a valid digest, $fields
	 *   is not a list of names, or a string the op carries is not valid UTF-8.
	 */
	public function __construct(
		public readonly int $sequence,
		public readonly int $microtime,
		public readonly Realm $realm,
		public readonly string $subject,
		public readonly Verb $verb,
		public readonly ?int $actor = null,
		public readonly ?string $requestId = null,
		public readonly ?string $payloadHash = null,
		public readonly ?string $parentHash = null,
		public readonly int $payloadLength = 0,
		public readonly string $label = '',
		public readonly array $fields = [],
	) {
		if ($sequence < 0) {
			throw new InvalidArgumentException('Journal op sequence cannot be negative');
		}
		if ($microtime <= 0) {
			throw new InvalidArgumentException('Journal op microtime must be a positive timestamp');
		}
		if ($subject === '') {
			throw new InvalidArgumentException('A journal op must name its subject');
		}
		if ($payloadLength < 0) {
			throw new InvalidArgumentException('Journal op payload length cannot be negative');
		}
		if ($payloadHash !== null && !Hash::isValid($payloadHash)) {
			throw new InvalidArgumentException('Journal op payload hash must be a valid digest');
		}
		if ($parentHash !== null && !Hash::isValid($parentHash)) {
			throw new InvalidArgumentException('Journal op parent hash must be a valid digest');
		}

		// a keyed array serializes as a json object instead of an array, so the round trip would
		// not return what went in, and a non-string name breaks the collapser's field union
		if (!array_is_list($fields) || array_filter($fields, 'is_string') !== $fields) {
			throw new InvalidArgumentException('Journal op fields must be a list of field names');
		}

		$this->refuseUnrepresentable();
	}

	/**
	 * Refuses an op carrying a string that JSON cannot represent.
	 *
	 * `json_encode()` returns FALSE when any string in the document is not valid UTF-8, and the
	 * whole document goes with it - not the offending string. SegmentManifest is that document, so
	 * one unrepresentable subject would take every operation in the flush with it. Refusing here
	 * bounds the loss to the one op: every capture source catches, records a finding and returns,
	 * and the reconciler notices the gap on its next pass.
	 *
	 * The strings are checked as one newline-joined document because this runs on every captured
	 * mutation, and which one failed is worked out only on the path that is about to throw. The
	 * separator has to be there and has to be ASCII: joined bare, a subject ending in a truncated
	 * "\xC3" and a label opening with "\xA9" form a valid two-byte sequence across the seam and both
	 * halves pass, while `json_encode()` still refuses each of them on its own. An ASCII byte can
	 * neither continue nor complete a multibyte sequence, so with one between them the joined check
	 * is exactly the per-string check.
	 *
	 * @throws InvalidArgumentException
	 *   When the subject, the label or a field name is not valid UTF-8.
	 */
	private function refuseUnrepresentable(): void
	{
		$joined = implode("\n", [$this->subject, $this->label, ...$this->fields]);

		if (self::isRepresentable($joined)) {
			return;
		}

		$part = match (true) {
			!self::isRepresentable($this->subject) => 'subject',
			!self::isRepresentable($this->label) => 'label',
			default => 'field name',
		};

		throw new InvalidArgumentException(
			sprintf(
				'Journal op %s is not valid UTF-8, so the segment carrying it would not serialize',
				$part,
			),
		);
	}

	/**
	 * Whether a string survives JSON.
	 *
	 * `//u` fails to match when the subject is not valid UTF-8, which is the same condition
	 * `json_encode()` refuses on. PCRE is always compiled in, where mbstring and iconv are core
	 * requirements this module does not declare.
	 *
	 * @param string $value
	 *   The string.
	 *
	 * @return bool
	 *   TRUE when it is valid UTF-8.
	 */
	private static function isRepresentable(string $value): bool
	{
		return preg_match('//u', $value) === 1;
	}

	#region Identity

	/**
	 * The identity a window is grouped by.
	 *
	 * Realm-qualified, because a config name and a table name can collide and mean two unrelated
	 * things. This is the key Collapser folds a run of ops on.
	 *
	 * @return string
	 *   The realm value and the subject, joined by a colon.
	 */
	public function key(): string
	{
		return $this->realm->value . ':' . $this->subject;
	}

	/**
	 * When the mutation happened, as a float unix timestamp.
	 *
	 * Microseconds are stored as an integer so ordering and equality are exact; this is the form
	 * for a formatter or a duration, not for a comparison.
	 *
	 * @return float
	 *   Seconds since the epoch, with microsecond resolution.
	 */
	public function timestamp(): float
	{
		return $this->microtime / self::MICROSECONDS_PER_SECOND;
	}

	/**
	 * Whether this op removed data that only a restore can bring back.
	 *
	 * @return bool
	 *   TRUE when the verb is destructive.
	 */
	public function isDestructive(): bool
	{
		return $this->verb->isDestructive();
	}

	#endregion

	#region Derivation

	/**
	 * The same op at a different position in the window.
	 *
	 * The journal numbers an op on append, and the collapser renumbers nothing: a survivor keeps
	 * the sequence of the run it replaces.
	 *
	 * @param int $sequence
	 *   The new sequence. Zero or more.
	 *
	 * @return self
	 *   A copy with the new sequence and every other field unchanged.
	 *
	 * @throws InvalidArgumentException
	 *   When $sequence is negative.
	 */
	public function withSequence(int $sequence): self
	{
		return new self(
			$sequence,
			$this->microtime,
			$this->realm,
			$this->subject,
			$this->verb,
			$this->actor,
			$this->requestId,
			$this->payloadHash,
			$this->parentHash,
			$this->payloadLength,
			$this->label,
			$this->fields,
		);
	}

	#endregion

	#region Serialization

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   Every field verbatim, with the realm and the verb reduced to their case values so the op
	 *   survives JSON and a schema older than an added case still loads.
	 */
	public function jsonSerialize(): array
	{
		return [
			'sequence' => $this->sequence,
			'microtime' => $this->microtime,
			'realm' => $this->realm->value,
			'subject' => $this->subject,
			'verb' => $this->verb->value,
			'actor' => $this->actor,
			'requestId' => $this->requestId,
			'payloadHash' => $this->payloadHash,
			'parentHash' => $this->parentHash,
			'payloadLength' => $this->payloadLength,
			'label' => $this->label,
			'fields' => $this->fields,
		];
	}

	/**
	 * Rebuilds an op from its serialized form.
	 *
	 * The five fields an op cannot be understood without are required; everything else defaults to
	 * the value the constructor defaults to, so a row written before a field existed still loads.
	 *
	 * @param array<string, mixed> $data
	 *   The array produced by JournalOp::jsonSerialize().
	 *
	 * @return self
	 *   The op.
	 *
	 * @throws InvalidArgumentException
	 *   When a required key is missing, or a value is out of range.
	 * @throws ValueError
	 *   When the realm or the verb is not a case this version knows.
	 */
	public static function fromArray(array $data): self
	{
		foreach (['sequence', 'microtime', 'realm', 'subject', 'verb'] as $required) {
			if (!array_key_exists($required, $data)) {
				throw new InvalidArgumentException(
					sprintf('Journal op is missing "%s"', $required),
				);
			}
		}

		$fields = [];
		foreach ((array) ($data['fields'] ?? []) as $field) {
			$fields[] = (string) $field;
		}

		return new self(
			(int) $data['sequence'],
			(int) $data['microtime'],
			Realm::from((string) $data['realm']),
			(string) $data['subject'],
			Verb::from((string) $data['verb']),
			isset($data['actor']) ? (int) $data['actor'] : null,
			isset($data['requestId']) ? (string) $data['requestId'] : null,
			isset($data['payloadHash']) ? (string) $data['payloadHash'] : null,
			isset($data['parentHash']) ? (string) $data['parentHash'] : null,
			(int) ($data['payloadLength'] ?? 0),
			(string) ($data['label'] ?? ''),
			$fields,
		);
	}

	#endregion
}
