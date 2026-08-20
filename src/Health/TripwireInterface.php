<?php

declare(strict_types=1);

namespace Drupal\strata\Health;

/**
 * A single health check that asserts a SYMPTOM rather than a cause.
 *
 * Each implementation corresponds to a failure mode that has actually occurred. One that never
 * fires still costs a check on every sweep.
 *
 * Three rules bind every implementation:
 *
 * - It asserts a symptom, not a cause. "This frame's digest does not match its bytes" is a
 *   tripwire; "the storage provider is misconfigured" is a conclusion, and a conclusion drawn from
 *   one observation is how a health system starts reporting confident nonsense.
 * - It must be O(1) or explicitly bounded. No full-table scans on the request path, and no going
 *   looking for data the caller did not already have - that is how a cheap check turns into a
 *   query that runs on every request.
 * - It must never repair anything. Detection and repair are separated so a repair can be gated,
 *   rate-limited and escalated independently of whatever noticed, and so a check stays safe to run
 *   from anywhere.
 *
 * @see Finding
 * @see TripwireRegistry
 * @see RepairLadder
 */
interface TripwireInterface
{
	/**
	 * The stable dotted code every finding from this tripwire reports under.
	 *
	 * The code is the key the registry, the circuit breaker and the ledger all use, so it has to
	 * outlive refactors of the class that produces it. Rename the class freely; never the code.
	 *
	 * @return string
	 *   For example "frame.digest_mismatch".
	 */
	public function code(): string;

	/**
	 * Evaluates the check against one observation.
	 *
	 * @param array $observation
	 *   Scalars the caller already had in hand. A tripwire reads from this and nothing else.
	 *
	 * @return Finding|null
	 *   A finding when the symptom is present, or NULL when the check is satisfied.
	 */
	public function check(array $observation): ?Finding;
}
