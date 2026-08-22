<?php

declare(strict_types=1);

namespace Drupal\strata\Capture;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\strata\Journal\Realm;

/**
 * Decides whether a given change is captured at all.
 *
 * Read on every mutation, so every answer is resolved from configuration once per request and held.
 * A scope check that hit the config system per entity save would cost more than the capture it
 * guards.
 *
 * Strata's own tables are never captured. Recording the journal in the journal, or the frame index
 * in a frame, is an unbounded feedback loop rather than a backup.
 */
final class CaptureScope
{
	/**
	 * Table prefix this module owns, never captured.
	 */
	public const OWN_PREFIX = 'strata_';

	/**
	 * Entity types whose saves describe Drupal's own bookkeeping rather than site content.
	 */
	public const EXCLUDED_ENTITY_TYPES = ['strata_commit', 'strata_frame'];

	/**
	 * Resolved settings, or NULL until first read.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $resolved = null;

	/**
	 * Whether the settings are being read right now.
	 */
	private bool $resolving = false;

	/**
	 * Constructs a scope.
	 *
	 * @param ConfigFactoryInterface $configFactory
	 *   Where the capture settings are read from.
	 */
	public function __construct(private readonly ConfigFactoryInterface $configFactory) {}

	/**
	 * Whether capture is switched on at all.
	 *
	 * @return bool
	 *   TRUE when the module is enabled for capture.
	 */
	public function isEnabled(): bool
	{
		return (bool) ($this->settings()['enabled'] ?? false);
	}

	/**
	 * Whether a realm is captured.
	 *
	 * @param Realm $realm
	 *   The realm.
	 *
	 * @return bool
	 *   TRUE when capture is on and this realm is included.
	 */
	public function covers(Realm $realm): bool
	{
		if (!$this->isEnabled()) {
			return false;
		}

		/** @var array<string, bool> $capture */
		$capture = $this->settings()['capture'] ?? [];

		return (bool) ($capture[$realm->value] ?? false);
	}

	/**
	 * Whether an entity type is captured.
	 *
	 * @param string $entityTypeId
	 *   The entity type id.
	 *
	 * @return bool
	 *   TRUE when the entity realm is covered and this type is not excluded.
	 */
	public function coversEntityType(string $entityTypeId): bool
	{
		if (in_array($entityTypeId, self::EXCLUDED_ENTITY_TYPES, true)) {
			return false;
		}

		return $this->covers(Realm::ENTITY);
	}

	/**
	 * Whether a table is captured.
	 *
	 * @param string $table
	 *   The table name.
	 *
	 * @return bool
	 *   TRUE when the table realm is covered and the table is not one of this module's own.
	 */
	public function coversTable(string $table): bool
	{
		if (str_starts_with($table, self::OWN_PREFIX)) {
			return false;
		}

		return $this->covers(Realm::TABLE);
	}

	/**
	 * Whether the raw SQL tap runs at all.
	 *
	 * Separate from whether the table realm is captured, and deliberately so: the tap is the only
	 * driver-agnostic way to see a write nothing else instrumented, and it is also the only capture
	 * that adds cost to every statement a site runs rather than to every mutation. Measured at under
	 * 10 us per mutation, but a site that has calibrated and does not want it gets a switch that stops
	 * the events being enabled at all rather than one that filters afterwards.
	 *
	 * @return bool
	 *   TRUE when statement events should be enabled on the connection.
	 */
	public function tapsStatements(): bool
	{
		if (!$this->isEnabled()) {
			return false;
		}

		/** @var array<string, bool> $capture */
		$capture = $this->settings()['capture'] ?? [];

		return (bool) ($capture['statements'] ?? false);
	}

	/**
	 * Whether a connection target is captured.
	 *
	 * A replica target sees only reads by definition, so a write arriving on one is either a
	 * misconfiguration or a deliberate write to a secondary that the primary will not have. Neither
	 * belongs in the primary's history.
	 *
	 * @param string $target
	 *   The connection target, such as "default" or "replica".
	 *
	 * @return bool
	 *   TRUE when writes on this target are captured.
	 */
	public function coversTarget(string $target): bool
	{
		return $target === 'default';
	}

	/**
	 * How a user access-timestamp touch is recorded.
	 *
	 * @return string
	 *   One of "delta" (a full field delta), "event" (a compact login event) or "drop".
	 */
	public function accessChurnMode(): string
	{
		/** @var array<string, mixed> $capture */
		$capture = $this->settings()['capture'] ?? [];
		$mode = (string) ($capture['access_churn'] ?? 'event');

		return in_array($mode, ['delta', 'event', 'drop'], true) ? $mode : 'event';
	}

	/**
	 * Forgets the resolved settings.
	 *
	 * Called by a test that changes configuration mid-run, and by a long command between batches.
	 */
	public function reset(): void
	{
		$this->resolved = null;
	}

	/**
	 * The settings, resolved once.
	 *
	 * `??=` assigns after its right-hand side has run, so during a cold read the memo is still NULL.
	 * A cold read of `strata.settings` misses `cache_config` and writes it back, that write is a
	 * MERGE, and MERGE is a write keyword the statement tap acts on - which asks this object what it
	 * covers, from inside the read that has not finished. The flag ends that walk rather than
	 * recursing. What makes it unreachable today is that `StatementCaptureSubscriber::enable()`
	 * resolves the scope before it enables the events, and that invariant lives in another class.
	 *
	 * @return array<string, mixed>
	 *   The raw settings array, or an empty one while a resolution is already in flight, which reads
	 *   as "nothing is captured" and is the safe answer for a statement Strata itself caused.
	 */
	private function settings(): array
	{
		if ($this->resolved !== null) {
			return $this->resolved;
		}
		if ($this->resolving) {
			return [];
		}

		$this->resolving = true;

		try {
			return $this->resolved = (array) $this->configFactory
				->get('strata.settings')
				->getRawData();
		} finally {
			$this->resolving = false;
		}
	}
}
