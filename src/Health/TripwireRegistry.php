<?php

declare(strict_types=1);

namespace Drupal\strata\Health;

use Drupal\strata\Health\Tripwire\BaseUnreachable;
use Drupal\strata\Health\Tripwire\CommitParentMissing;
use Drupal\strata\Health\Tripwire\DeltaChainTooDeep;
use Drupal\strata\Health\Tripwire\DeltaParentMissing;
use Drupal\strata\Health\Tripwire\DictionaryMissing;
use Drupal\strata\Health\Tripwire\FileShiftDetected;
use Drupal\strata\Health\Tripwire\FrameAeadFailure;
use Drupal\strata\Health\Tripwire\FrameHashMismatch;
use Drupal\strata\Health\Tripwire\FrameMissing;
use Drupal\strata\Health\Tripwire\FrameUnindexed;
use Drupal\strata\Health\Tripwire\FrameUnreadable;
use Drupal\strata\Health\Tripwire\KeyRotatedMidFlight;
use Drupal\strata\Health\Tripwire\PackShortRead;
use Drupal\strata\Health\Tripwire\RefMissing;
use Drupal\strata\Health\Tripwire\SegmentTruncated;
use Drupal\strata\Health\Tripwire\TierUnreachable;
use Drupal\strata\Health\Tripwire\AnchorMissing;
use Drupal\strata\Health\Tripwire\UnclassifiedGrowth;
use Drupal\strata\Health\Tripwire\VendorDrift;
use Drupal\strata\Health\Tripwire\WatermarkDrift;
use InvalidArgumentException;
use Throwable;

/**
 * The one place tripwires are enumerated, and the sweep that runs them.
 *
 * Registration is keyed by the tripwire's own code, so the registry cannot end up holding two
 * checks that report under the same identifier - the ledger, the ladder and the circuit breaker
 * all key on that code, and two sources feeding one key makes every count downstream wrong.
 *
 * The sweep is the part that has to be hard to break. A tripwire that throws is caught and turned
 * into a finding of its own, because the alternative is that one broken check hides every check
 * registered after it, and the checks that stop reporting are exactly the ones nobody notices have
 * gone quiet.
 *
 * @see TripwireInterface
 * @see Finding
 */
final class TripwireRegistry
{
	/**
	 * Code reported when a tripwire throws instead of answering.
	 *
	 * The failing tripwire's own code goes in the finding's scope, so one broken check is
	 * attributable without needing a code per tripwire for the same failure mode.
	 */
	public const FAILED = 'tripwire.failed';

	/**
	 * The registered tripwires, keyed by code, in registration order.
	 *
	 * @var array
	 */
	private array $wires = [];

	/**
	 * A registry holding every check over what is in the object store.
	 *
	 * The set a verify pass sweeps with. Ordered cheapest-first only incidentally; every one of them
	 * reads the observation it is handed and nothing else, so the order does not change the result.
	 *
	 * @return self
	 *   A populated registry.
	 */
	public static function withStorageTripwires(): self
	{
		return (new self())
			->register(new FrameUnindexed())
			->register(new FrameMissing())
			->register(new FrameUnreadable())
			->register(new FrameHashMismatch())
			->register(new FrameAeadFailure())
			->register(new PackShortRead())
			->register(new DictionaryMissing())
			->register(new DeltaParentMissing())
			->register(new DeltaChainTooDeep())
			->register(new SegmentTruncated())
			->register(new CommitParentMissing())
			->register(new AnchorMissing())
			->register(new BaseUnreachable())
			->register(new RefMissing())
			->register(new TierUnreachable())
			->register(new KeyRotatedMidFlight());
	}

	/**
	 * A registry holding the checks over whether capture saw everything.
	 *
	 * Separate from the storage set because it answers a different question. The storage checks ask
	 * whether what was stored is intact; this one asks whether anything was missed, which is the only
	 * failure a backup system cannot see from inside its own capture path.
	 *
	 * @return self
	 *   A populated registry.
	 */
	public static function withCaptureTripwires(): self
	{
		return (new self())
			->register(new WatermarkDrift())
			->register(new UnclassifiedGrowth())
			->register(new VendorDrift());
	}

	/**
	 * A registry holding the checks over the file realm.
	 *
	 * Its own set because the file realm's failure is neither corruption nor a gap: the content is
	 * stored and restores exactly, and it simply costs far more than it should because fixed blocks
	 * cannot follow a shift.
	 *
	 * @return self
	 *   A populated registry.
	 */
	public static function withFileTripwires(): self
	{
		return (new self())->register(new FileShiftDetected());
	}

	/**
	 * Registers a tripwire under its own code.
	 *
	 * @param TripwireInterface $wire
	 *   The tripwire.
	 *
	 * @return self
	 *   This registry, so a set can be built in one expression.
	 *
	 * @throws InvalidArgumentException
	 *   When the code is empty, or something is already registered under it. Both are refused
	 *   loudly rather than resolved by last-write-wins, which would silently drop a check.
	 */
	public function register(TripwireInterface $wire): self
	{
		$code = $wire->code();
		if ($code === '') {
			throw new InvalidArgumentException(
				sprintf('%s returned an empty tripwire code', $wire::class),
			);
		}
		if (isset($this->wires[$code])) {
			throw new InvalidArgumentException(
				sprintf('A tripwire is already registered under "%s"', $code),
			);
		}

		$this->wires[$code] = $wire;

		return $this;
	}

	/**
	 * Whether a code is registered.
	 *
	 * @param string $code
	 *   The tripwire code.
	 *
	 * @return bool
	 *   TRUE when a tripwire is registered under this code.
	 */
	public function has(string $code): bool
	{
		return isset($this->wires[$code]);
	}

	/**
	 * One registered tripwire.
	 *
	 * Returns NULL rather than throwing, so a caller checking whether an optional check is
	 * installed does not need a try block to ask.
	 *
	 * @param string $code
	 *   The tripwire code.
	 *
	 * @return TripwireInterface|null
	 *   The tripwire, or NULL when nothing is registered under that code.
	 */
	public function get(string $code): ?TripwireInterface
	{
		return $this->wires[$code] ?? null;
	}

	/**
	 * Every registered code, in registration order.
	 *
	 * @return array
	 *   The codes.
	 */
	public function codes(): array
	{
		return array_keys($this->wires);
	}

	/**
	 * Every registered tripwire, keyed by code, in registration order.
	 *
	 * @return array
	 *   Code to TripwireInterface.
	 */
	public function all(): array
	{
		return $this->wires;
	}

	/**
	 * Runs every tripwire over one observation and collects what fired.
	 *
	 * The sweep never aborts. A tripwire that throws becomes a FAILED finding scoped to that
	 * tripwire's code and the sweep continues, so a bug in one check costs one check.
	 *
	 * @param array $observation
	 *   Scalars the caller already had; passed to every tripwire unchanged.
	 *
	 * @return Finding[]
	 *   Findings in registration order, empty when everything is satisfied.
	 */
	public function evaluate(array $observation): array
	{
		$findings = [];

		foreach ($this->wires as $code => $wire) {
			try {
				$finding = $wire->check($observation);
			} catch (Throwable $error) {
				$findings[] = new Finding(
					self::FAILED,
					Finding::ERROR,
					$code,
					sprintf('%s: %s', $error::class, $error->getMessage()),
				);
				continue;
			}

			if ($finding !== null) {
				$findings[] = $finding;
			}
		}

		return $findings;
	}
}
