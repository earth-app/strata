<?php

declare(strict_types=1);

namespace Drupal\strata\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\strata\Cas\FrameIndexInterface;
use Drupal\strata\Engine;
use Drupal\strata\Health\HealthLedgerInterface;
use Drupal\strata\Journal\JournalInterface;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Site\SiteScopedProvider;
use Drupal\strata\Storage\StorageProviderInterface;
use Drupal\strata\Tree\CommitIndex;
use Drupal\strata\Tree\RefStore;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Throwable;

/**
 * The commands an operator runs on a working site.
 *
 * Reading is separated from writing here. `strata:status`, `strata:list` and `strata:sites` answer
 * questions and are safe on any site in any state, so each of them reports a misconfigured store as
 * a field rather than as an exception: an operator asking why backups are not running needs the
 * answer printed, not a stack trace. `strata:snapshot` and `strata:flush` seal history and fail
 * loudly, because a flush that quietly did nothing is the failure this module exists to prevent.
 *
 * Every table is returned as structured data rather than printed, so `--format=json` and `--field`
 * work on all of them without a second code path.
 *
 * @see Engine
 * @see CommitIndex
 */
final class StrataCommands extends DrushCommands
{
	use AutowireTrait;
	use StrataOutputTrait;

	/**
	 * Constructs the command set.
	 *
	 * @param Engine $engine
	 *   Builds the pipeline this site is configured for.
	 * @param ConfigFactoryInterface $configFactory
	 *   Read for the settings a status report names.
	 * @param CommitIndex $commits
	 *   The local index history is listed from, which costs no requests.
	 * @param JournalInterface $journal
	 *   Asked what has been captured but not yet sealed.
	 * @param FrameIndexInterface $frames
	 *   Asked what the store holds.
	 * @param HealthLedgerInterface $ledger
	 *   Asked how many findings are outstanding.
	 */
	public function __construct(
		private readonly Engine $engine,
		private readonly ConfigFactoryInterface $configFactory,
		private readonly CommitIndex $commits,
		private readonly JournalInterface $journal,
		private readonly FrameIndexInterface $frames,
		private readonly HealthLedgerInterface $ledger,
	) {
		parent::__construct();
	}

	#region Reading

	/**
	 * What Strata is doing on this site, and what it cannot do.
	 *
	 * @return PropertyList
	 *   One row per fact.
	 */
	#[CLI\Command(name: 'strata:status', aliases: ['strata-status'])]
	#[
		CLI\Usage(
			name: 'drush strata:status',
			description: 'Show capture, store and health at a glance.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:status --field=lag',
			description: 'Emit only how far behind the store is, for a monitoring check.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'enabled' => 'Enabled',
				'site' => 'Site',
				'site-source' => 'Site id From',
				'provider' => 'Provider',
				'store' => 'Store',
				'head' => 'Head',
				'head-at' => 'Head At',
				'commits' => 'Commits Indexed',
				'pending' => 'Pending Ops',
				'pending-bytes' => 'Pending Bytes',
				'lag' => 'Lag Seconds',
				'flush-due' => 'Flush Due',
				'frames' => 'Frames',
				'raw-bytes' => 'Captured Bytes',
				'stored-bytes' => 'Stored Bytes',
				'ratio' => 'Ratio',
				'orphans' => 'Collectable Frames',
				'requests-a' => 'Class A Requests, 30d',
				'requests-b' => 'Class B Requests, 30d',
				'request-failures' => 'Failed Requests, 30d',
				'transferred' => 'Transferred, 30d',
				'findings' => 'Open Findings',
			],
		),
	]
	#[CLI\Format(listDelimiter: ':', tableStyle: 'compact')]
	public function status(): PropertyList
	{
		$settings = $this->configFactory->get('strata.settings');
		$head = $this->commits->newest();
		$statistics = $this->frames->statistics();
		$traffic = $this->engine->providerStatStore()->total(time() - 2_592_000);

		return new PropertyList([
			'enabled' => self::yesNo((bool) $settings->get('enabled')),
			'site' => $this->engine->site()->id(),
			'site-source' => $this->engine->site()->isExplicit() ? 'configuration' : 'database',
			'provider' => (string) $settings->get('provider'),
			'store' => $this->storeState(),
			'head' => self::digest($head === null ? null : (string) $head['id']),
			'head-at' => self::moment($head === null ? 0 : (int) $head['microtime']),
			'commits' => $this->commits->count(),
			'pending' => $this->journal->pending(),
			'pending-bytes' => self::bytes($this->journal->pendingBytes()),
			'lag' => sprintf('%.1f', $this->lag()),
			'flush-due' => $this->flushDue(),
			'frames' => $statistics['frames'],
			'raw-bytes' => self::bytes($statistics['rawBytes']),
			'stored-bytes' => self::bytes($statistics['storedBytes']),
			'ratio' => sprintf('%.2fx', $statistics['ratio']),
			'orphans' => $statistics['orphans'],
			'requests-a' => $traffic['classA'],
			'requests-b' => $traffic['classB'],
			'request-failures' => $traffic['failures'],
			'transferred' => self::bytes($traffic['bytes']),
			'findings' => count($this->ledger->open()),
		]);
	}

	/**
	 * The commits this site's history holds, newest first.
	 *
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return RowsOfFields
	 *   One row per commit, empty when nothing has been sealed yet.
	 */
	#[CLI\Command(name: 'strata:list', aliases: ['strata-list', 'strata-log'])]
	#[CLI\Option(name: 'limit', description: 'Most commits to list.')]
	#[CLI\Option(name: 'level', description: 'Only commits at this compaction level.')]
	#[
		CLI\Option(
			name: 'since',
			description: 'Only commits after this time, as anything strtotime reads.',
		),
	]
	#[
		CLI\Option(
			name: 'until',
			description: 'Only commits before this time, as anything strtotime reads.',
		),
	]
	#[CLI\Usage(name: 'drush strata:list', description: 'List the most recent commits.')]
	#[
		CLI\Usage(
			name: 'drush strata:list --since="-2 hours" --level=0',
			description: 'List the uncompacted commits from the last two hours.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'id' => 'Commit',
				'when' => 'When',
				'label' => 'Summary',
				'operations' => 'Ops',
				'raw' => 'Captured',
				'stored' => 'Stored',
				'level' => 'Level',
				'anchor' => 'Anchor',
				'actor' => 'Actor',
			],
		),
	]
	#[CLI\DefaultTableFields(fields: ['id', 'when', 'label', 'operations', 'raw', 'level'])]
	#[CLI\FilterDefaultField(field: 'label')]
	public function commits(
		array $options = [
			'limit' => 50,
			'level' => self::REQ,
			'since' => self::REQ,
			'until' => self::REQ,
		],
	): RowsOfFields {
		$rows = [];
		$level = self::value($options, 'level');

		foreach (
			$this->commits->between(
				$this->micro(self::value($options, 'since'), 0),
				$this->micro(self::value($options, 'until'), PHP_INT_MAX),
				max(1, self::number($options, 'limit', 50)),
				$level === null ? null : (int) $level,
			)
			as $row
		) {
			$rows[(string) $row['id']] = [
				'id' => self::digest((string) $row['id']),
				'when' => self::moment((int) $row['microtime']),
				'label' => (string) $row['label'],
				'operations' => (int) $row['operations'],
				'raw' => self::bytes((int) $row['raw_bytes']),
				'stored' => self::bytes((int) $row['stored_bytes']),
				'level' => (int) $row['level'],
				'anchor' => self::yesNo((bool) $row['is_base']),
				'actor' => $row['actor'] === null ? '-' : (string) $row['actor'],
			];
		}

		return new RowsOfFields($rows);
	}

	/**
	 * Which sites share this bucket.
	 *
	 * Frames are shared across sites and history is not, so the prefixes that are neither content nor
	 * this site are other sites' histories. Listing them is how an operator confirms that a bucket
	 * holds what they think it holds before pointing a restore at it.
	 *
	 * @return RowsOfFields
	 *   One row per site, empty when the store has never been written to.
	 */
	#[CLI\Command(name: 'strata:sites', aliases: ['strata-sites'])]
	#[
		CLI\Usage(
			name: 'drush strata:sites',
			description: 'List every site with history in this bucket.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:sites --format=json',
			description: 'Emit the list for a script that reconciles sites against inventory.',
		),
	]
	#[CLI\FieldLabels(labels: ['site' => 'Site', 'current' => 'Current', 'head' => 'Head'])]
	public function sites(): RowsOfFields
	{
		$provider = $this->engine->provider();
		$bucket = $provider instanceof SiteScopedProvider ? $provider->unscoped() : $provider;
		$current = $this->engine->site()->id();
		$rows = [];

		foreach ($this->siteIds($bucket) as $id) {
			$rows[$id] = [
				'site' => $id,
				'current' => self::yesNo($id === $current),
				'head' => self::digest($this->headOf($bucket, $id)),
			];
		}

		return new RowsOfFields($rows);
	}

	#endregion

	#region Writing

	/**
	 * Captures everything no hook observes, then seals a commit covering all of it.
	 *
	 * Entity, config and state writes arrive through hooks as they happen. Table drift and code
	 * changes do not, so a commit sealed without them describes only part of the site. This runs
	 * those passes first and forces the flush afterwards, which makes the resulting commit a complete
	 * point in time rather than whatever the hooks happened to see.
	 *
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return RowsOfFields
	 *   One row per stage.
	 */
	#[CLI\Command(name: 'strata:snapshot', aliases: ['strata-snapshot'])]
	#[
		CLI\Option(
			name: 'skip-reconcile',
			description: 'Do not compare captured tables against the database.',
		),
	]
	#[CLI\Option(name: 'skip-code', description: 'Do not scan the site code and lockfiles.')]
	#[CLI\Option(name: 'tables', description: 'Most tables the reconcile pass examines.')]
	#[CLI\Option(name: 'ref', description: 'Ref to advance.')]
	#[
		CLI\Usage(
			name: 'drush strata:snapshot',
			description: 'Take a complete point-in-time snapshot.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:snapshot --skip-code',
			description: 'Snapshot data only, on a site whose code is deployed immutably.',
		),
	]
	#[CLI\FieldLabels(labels: ['stage' => 'Stage', 'ran' => 'Ran', 'detail' => 'Detail'])]
	public function snapshot(
		array $options = [
			'skip-reconcile' => false,
			'skip-code' => false,
			'tables' => 100,
			'ref' => RefStore::MAIN,
		],
	): RowsOfFields {
		$rows = [];

		if (!self::flag($options, 'skip-reconcile')) {
			$tables = max(1, self::number($options, 'tables', 100));
			$report = $this->engine->reconciler()->reconcile([], $tables);
			$rows['reconcile'] = [
				'stage' => 'reconcile',
				'ran' => self::yesNo(true),
				'detail' => $report->summary(),
			];
		}
		if (!self::flag($options, 'skip-code')) {
			$code = $this->engine->codeCapture()->capture(true);
			$rows['code'] = [
				'stage' => 'code',
				'ran' => self::yesNo($code->ran),
				'detail' => $code->summary(),
			];
		}

		$flush = $this->engine->flusher()->flush(true, $this->ref($options));
		$rows['flush'] = [
			'stage' => 'flush',
			'ran' => self::yesNo($flush->ran),
			'detail' => $flush->summary(),
		];

		$this->announce($flush->ran, $flush->summary());

		return new RowsOfFields($rows);
	}

	/**
	 * Seals whatever has been captured but not yet stored.
	 *
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return PropertyList
	 *   What the flush did.
	 */
	#[CLI\Command(name: 'strata:flush', aliases: ['strata-flush'])]
	#[CLI\Option(name: 'ref', description: 'Ref to advance.')]
	#[
		CLI\Option(
			name: 'if-due',
			description: 'Seal only when a flush bound has been reached, as cron does.',
		),
	]
	#[CLI\Usage(name: 'drush strata:flush', description: 'Seal the pending window now.')]
	#[
		CLI\Usage(
			name: 'drush strata:flush --if-due',
			description: 'Seal only if the policy says a window is due, for a scheduled run.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'ran' => 'Ran',
				'reason' => 'Reason',
				'commit' => 'Commit',
				'segment' => 'Segment',
				'captured' => 'Operations Read',
				'stored' => 'Operations Stored',
				'raw-bytes' => 'Captured Bytes',
				'trimmed' => 'Journal Rows Trimmed',
				'seconds' => 'Took',
			],
		),
	]
	#[CLI\Format(listDelimiter: ':', tableStyle: 'compact')]
	public function flush(
		array $options = ['ref' => RefStore::MAIN, 'if-due' => false],
	): PropertyList {
		$result = $this->engine
			->flusher()
			->flush(!self::flag($options, 'if-due'), self::ref($options));

		$this->announce($result->ran, $result->summary());

		return new PropertyList([
			'ran' => self::yesNo($result->ran),
			'reason' => (string) ($result->ran ? $result->reason : $result->skipped),
			'commit' => self::digest($result->commit),
			'segment' => self::digest($result->segment),
			'captured' => $result->captured,
			'stored' => $result->stored,
			'raw-bytes' => self::bytes($result->rawBytes),
			'trimmed' => $result->trimmed,
			'seconds' => self::duration($result->seconds),
		]);
	}

	#endregion

	#region Reporting Helpers

	/**
	 * Whether the configured store can be reached, or why it cannot.
	 *
	 * @return string
	 *   Either "reachable" or a sentence naming the obstacle.
	 */
	private function storeState(): string
	{
		try {
			$provider = $this->engine->provider();

			return $provider->isReachable()
				? 'reachable'
				: (string) ($provider->unreachableReason() ?? 'unreachable');
		} catch (Throwable $error) {
			return $error->getMessage();
		}
	}

	/**
	 * How far behind the store is, in seconds.
	 *
	 * Read from the journal rather than from the flusher, so a store that cannot be constructed still
	 * reports its lag.
	 *
	 * @return float
	 *   Seconds since the oldest unsealed operation, or zero when nothing is pending.
	 */
	private function lag(): float
	{
		$oldest = $this->journal->oldest();

		if ($oldest === null) {
			return 0.0;
		}

		$now = (int) round(microtime(true) * JournalOp::MICROSECONDS_PER_SECOND);

		return max(0.0, ($now - $oldest) / JournalOp::MICROSECONDS_PER_SECOND);
	}

	/**
	 * Whether the flush policy says a window is due.
	 *
	 * @return string
	 *   Either "yes", "no", or the reason the question could not be answered.
	 */
	private function flushDue(): string
	{
		try {
			return self::yesNo($this->engine->flusher()->isDue());
		} catch (Throwable $error) {
			return $error->getMessage();
		}
	}

	/**
	 * Every site id present in a bucket.
	 *
	 * @param StorageProviderInterface $bucket
	 *   The unscoped provider.
	 *
	 * @return list<string>
	 *   Site ids, in listing order.
	 */
	private function siteIds(StorageProviderInterface $bucket): array
	{
		$ids = [];
		$cursor = null;

		do {
			$page = $bucket->list('', $cursor, 1000, '/');

			foreach ($page->prefixes as $prefix) {
				if (in_array($prefix, SiteScopedProvider::SHARED_PREFIXES, true)) {
					continue;
				}

				$id = rtrim($prefix, '/');

				if ($id !== '' && !in_array($id, $ids, true)) {
					$ids[] = $id;
				}
			}

			$cursor = $page->cursor;
		} while ($page->hasMore());

		sort($ids, SORT_STRING);

		return $ids;
	}

	/**
	 * The commit one site's main ref points at.
	 *
	 * @param StorageProviderInterface $bucket
	 *   The unscoped provider.
	 * @param string $id
	 *   The site id.
	 *
	 * @return string|null
	 *   The commit id, or NULL when the site has no main ref or it cannot be read.
	 */
	private function headOf(StorageProviderInterface $bucket, string $id): ?string
	{
		$key = sprintf('%s/%s/%s', $id, RefStore::PREFIX, RefStore::MAIN);

		try {
			return $bucket->exists($key) ? trim($bucket->get($key)) : null;
		} catch (Throwable) {
			// a ref another site is mid-write on is not this command's problem
			return null;
		}
	}

	/**
	 * Turns a time expression into unix microseconds.
	 *
	 * @param string|null $expression
	 *   Anything strtotime reads, or NULL.
	 * @param int $fallback
	 *   What to return when there is nothing to parse.
	 *
	 * @return int
	 *   Microseconds since the epoch.
	 */
	private function micro(?string $expression, int $fallback): int
	{
		if ($expression === null || $expression === '') {
			return $fallback;
		}

		$parsed = strtotime($expression);

		return $parsed === false ? $fallback : $parsed * JournalOp::MICROSECONDS_PER_SECOND;
	}

	#endregion
}
