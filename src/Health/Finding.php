<?php

declare(strict_types=1);

namespace Drupal\strata\Health;

use JsonSerializable;
use TypeError;

/**
 * One thing a tripwire noticed, and the only shape a health signal travels in.
 *
 * A value object rather than a loose array, because every consumer - the ledger, the circuit
 * breaker, a JSON payload on its way to an operator - reads the same four fields, and an array
 * lets a misspelled key become a silently absent field. Here a missing or misspelled key is a
 * TypeError at the call site: the constructor takes four typed arguments, and fromArray() feeds
 * them positionally, so a bad key arrives as NULL against a `string` parameter and stops there.
 *
 * A finding asserts a SYMPTOM. It carries no verdict about a cause and no instruction to repair.
 * What to do about it is RepairLadder's answer, and whether to try again is CircuitBreaker's.
 *
 * Every field is readonly, so a finding that has been handed to the ledger cannot be edited by
 * whoever reads it back. The properties are declared rather than promoted because scope and
 * context are clamped during construction, and a promoted readonly property cannot be rewritten
 * in the constructor body.
 *
 * @see TripwireInterface
 * @see RepairLadder
 * @see HealthLedgerInterface
 */
final class Finding implements JsonSerializable
{
	/**
	 * Something worth recording that needs nobody to act.
	 */
	public const INFO = 0;

	/**
	 * A symptom that is tolerable now and will not be if it repeats.
	 */
	public const WARN = 1;

	/**
	 * Something is already wrong; a repair starts at the reindex rung.
	 */
	public const ERROR = 2;

	/**
	 * A restore target may be unusable; the ladder starts at quarantine and waits for a human.
	 */
	public const CRITICAL = 3;

	/**
	 * Longest scope this will carry.
	 *
	 * A scope is a frame hash, a table name or a provider name, all of which fit; anything longer
	 * is a caller pasting a payload into the wrong field.
	 */
	public const MAX_SCOPE = 120;

	/**
	 * Longest context this will carry into the ledger.
	 *
	 * An unbounded detail column is how a log table becomes the largest thing in the database. The
	 * cap is applied here, at construction, rather than at the storage boundary, so every ledger
	 * implementation inherits the bound instead of each one having to remember it.
	 */
	public const MAX_CONTEXT = 400;

	/**
	 * Stable dotted identifier; the circuit breaker and the ladder both key on this.
	 *
	 * @var string
	 */
	public readonly string $code;

	/**
	 * One of the severity ordinals on this class.
	 *
	 * @var int
	 */
	public readonly int $severity;

	/**
	 * What the finding was about: a frame hash, a table, a storage provider.
	 *
	 * @var string
	 */
	public readonly string $scope;

	/**
	 * Short human-readable detail, clamped to MAX_CONTEXT on construction.
	 *
	 * @var string
	 */
	public readonly string $context;

	/**
	 * Constructs a finding.
	 *
	 * @param string $code
	 *   Stable dotted identifier, such as "frame.digest_mismatch".
	 * @param int $severity
	 *   One of INFO, WARN, ERROR or CRITICAL. An ordinal outside that set is kept rather than
	 *   refused, because the ladder reads severity with >= and so treats an unknown high value as
	 *   critical, which is the safe direction to be wrong in.
	 * @param string $scope
	 *   What it was about; clamped to MAX_SCOPE.
	 * @param string $context
	 *   Human-readable detail; clamped to MAX_CONTEXT.
	 */
	public function __construct(
		string $code,
		int $severity,
		string $scope = '',
		string $context = '',
	) {
		$this->code = $code;
		$this->severity = $severity;
		$this->scope = self::clamp($scope, self::MAX_SCOPE);
		$this->context = self::clamp($context, self::MAX_CONTEXT);
	}

	/**
	 * Names the severity for a log line or an operator-facing table.
	 *
	 * Never parse this back; fromArray() reads the ordinal, which is what the ledger stores.
	 *
	 * @return string
	 *   INFO, WARN, ERROR, CRITICAL, or UNKNOWN for an ordinal outside the set.
	 */
	public function severityName(): string
	{
		return self::severities()[$this->severity] ?? 'UNKNOWN';
	}

	/**
	 * Every severity name, keyed by the ordinal the ledger stores.
	 *
	 * Read by a report that has an ordinal out of the database and no finding object to ask.
	 *
	 * @return array<int, string>
	 *   Ordinal keyed to name, lowest first.
	 */
	public static function severities(): array
	{
		return [
			self::INFO => 'INFO',
			self::WARN => 'WARN',
			self::ERROR => 'ERROR',
			self::CRITICAL => 'CRITICAL',
		];
	}

	/**
	 * Renders the finding as the array it is stored and transported as.
	 *
	 * @return array
	 *   Keys code, severity, scope and context, which is exactly what fromArray() reads.
	 */
	public function jsonSerialize(): array
	{
		return [
			'code' => $this->code,
			'severity' => $this->severity,
			'scope' => $this->scope,
			'context' => $this->context,
		];
	}

	/**
	 * Rebuilds a finding from the array jsonSerialize() produced.
	 *
	 * All four keys are required. A missing or misspelled key resolves to NULL and then fails
	 * against a typed parameter, which is the whole reason this type exists: a health record that
	 * silently lost its scope is worse than one that refused to load.
	 *
	 * @param array $data
	 *   Keys code, severity, scope and context.
	 *
	 * @return self
	 *   The finding, with scope and context re-clamped.
	 *
	 * @throws TypeError
	 *   When a key is absent or holds the wrong type.
	 */
	public static function fromArray(array $data): self
	{
		return new self(
			$data['code'] ?? null,
			$data['severity'] ?? null,
			$data['scope'] ?? null,
			$data['context'] ?? null,
		);
	}

	/**
	 * Cuts a string to a byte budget without leaving it unencodable.
	 *
	 * A cut on a byte boundary can split a multibyte character, and one invalid sequence makes
	 * json_encode() refuse the entire record - so a truncated context would take the whole ledger
	 * row down with it. Only a value that was well-formed before the cut is repaired; binary that
	 * arrived broken is passed through as-is rather than being eaten a byte at a time.
	 *
	 * @param string $value
	 *   The string to clamp.
	 * @param int $limit
	 *   Maximum length in bytes.
	 *
	 * @return string
	 *   At most $limit bytes.
	 */
	private static function clamp(string $value, int $limit): string
	{
		if (strlen($value) <= $limit) {
			return $value;
		}

		$cut = substr($value, 0, $limit);
		if (preg_match('//u', $value) !== 1) {
			return $cut;
		}

		// at most three bytes of a split sequence to walk back over
		while ($cut !== '' && preg_match('//u', $cut) !== 1) {
			$cut = substr($cut, 0, -1);
		}

		return $cut;
	}
}
