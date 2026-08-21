<?php

declare(strict_types=1);

namespace Drupal\strata\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\strata\Capture\Classifier\Classification;
use Drupal\strata\Capture\Classifier\ClassificationRegistry;
use Drupal\strata\Codec\CodecCatalog;
use Drupal\strata\Codec\CodecMeasurement;
use Drupal\strata\Codec\CompressionCodecInterface;
use Drupal\strata\Engine;
use Drupal\strata\Estimate\Estimator;
use Drupal\strata\Estimate\Measurement;
use Drupal\strata\Estimate\Projection;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\HealthLedgerInterface;
use Drupal\strata\Health\RepairLadder;
use Drupal\strata\Restore\RestoreAudit;
use Drupal\strata\Tier\Tier;
use Drupal\strata\Tree\RefStore;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Throwable;

/**
 * The commands that measure rather than change.
 *
 * A backup is only a backup if it reads back, so `strata:verify` fetches and decodes what the store
 * holds and names everything that does not come out. `strata:audit` and `strata:heal` read the record
 * that pass leaves behind: which findings are outstanding, and how far up the repair ladder each
 * finding code has climbed. Nothing here takes a rung above the automatic ceiling on its own;
 * quarantine and refuse remove a restore target, and `strata:quarantine` is where a person asks for
 * that.
 *
 * `strata:estimate` and `strata:calibrate` answer the two cost questions. The estimate projects a
 * bill from a site's shape and the policy applied to it. The calibration measures what the codecs on
 * this host actually achieve on this site's own payloads, and marks its figures as measured so they
 * are never confused with the shipped reference numbers.
 *
 * @see RepairLadder
 * @see Estimator
 * @see CodecCatalog
 */
final class StrataDiagnosticCommands extends DrushCommands
{
	use AutowireTrait;
	use StrataOutputTrait;

	/**
	 * Constructs the command set.
	 *
	 * @param Engine $engine
	 *   Builds the verifier, the discovery pass and the codec registry.
	 * @param ConfigFactoryInterface $configFactory
	 *   Supplies the policy dials an estimate defaults to.
	 * @param HealthLedgerInterface $ledger
	 *   Holds the findings and the rung each code sits at.
	 * @param RestoreAudit $audit
	 *   Holds the record of every restore this site has run.
	 * @param ClassificationRegistry $classifications
	 *   Holds what each part of the ephemeral keyspace has been decided to be.
	 */
	public function __construct(
		private readonly Engine $engine,
		private readonly ConfigFactoryInterface $configFactory,
		private readonly HealthLedgerInterface $ledger,
		private readonly RestoreAudit $audit,
		private readonly ClassificationRegistry $classifications,
	) {
		parent::__construct();
	}

	#region Verifying

	/**
	 * Reads the backup back and names what does not come out.
	 *
	 * A deep pass fetches and decodes every frame, which is the only check that proves the bytes are
	 * still there and still decode. A shallow pass asks only whether each object is present and
	 * indexed, at one head request per object instead of a fetch and a decode.
	 *
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return RowsOfFields
	 *   One row per finding, empty when everything the pass touched read back correctly.
	 */
	#[CLI\Command(name: 'strata:verify', aliases: ['strata-verify'])]
	#[CLI\Option(name: 'ref', description: 'Ref to walk back from.')]
	#[
		CLI\Option(
			name: 'limit',
			description: 'Stop after this many commits; 0 to walk to the root.',
		),
	]
	#[
		CLI\Option(
			name: 'shallow',
			description: 'Check only that each object is present and indexed, without decoding it.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:verify',
			description: 'Decode all of history and report what fails.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:verify --limit=50 --shallow',
			description: 'Check the presence of the last fifty commits, for a frequent scheduled run.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'code' => 'Code',
				'severity' => 'Severity',
				'scope' => 'Scope',
				'context' => 'Detail',
			],
		),
	]
	#[CLI\FilterDefaultField(field: 'code')]
	public function verify(
		array $options = ['ref' => RefStore::MAIN, 'limit' => 0, 'shallow' => false],
	): RowsOfFields {
		$limit = max(0, self::number($options, 'limit', 0));
		$report = $this->engine
			->verifier()
			->verify(
				self::ref($options),
				$limit > 0 ? $limit : null,
				!self::flag($options, 'shallow'),
			);

		$this->announce($report->isClean(), $report->summary());

		$rows = [];

		foreach ($report->findings as $position => $finding) {
			$rows[(string) $position] = [
				'code' => $finding->code,
				'severity' => $finding->severityName(),
				'scope' => $finding->scope,
				'context' => $finding->context,
			];
		}

		return new RowsOfFields($rows);
	}

	/**
	 * Every restore this site has run, newest first.
	 *
	 * A restore that is still recorded as pending outlived the process that started it, so those are
	 * reported first: the site may hold a partial write nobody has reconciled.
	 *
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return RowsOfFields
	 *   One row per restore, empty on a site that has never restored anything.
	 */
	#[CLI\Command(name: 'strata:audit', aliases: ['strata-audit'])]
	#[CLI\Option(name: 'limit', description: 'Most rows to list.')]
	#[
		CLI\Option(
			name: 'unfinished',
			description: 'List only the restores that never recorded an outcome.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:audit',
			description: 'List the recent restores and how each ended.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:audit --unfinished',
			description: 'List the restores that started and never finished.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'id' => 'Id',
				'started' => 'Started',
				'actor' => 'Actor',
				'mode' => 'Mode',
				'target' => 'Target',
				'snapshot' => 'Undo Commit',
				'scope' => 'Scope',
				'outcome' => 'Outcome',
				'restored' => 'Restored',
				'skipped' => 'Skipped',
			],
		),
	]
	#[
		CLI\DefaultTableFields(
			fields: ['id', 'started', 'actor', 'mode', 'target', 'outcome', 'restored'],
		),
	]
	#[CLI\FilterDefaultField(field: 'outcome')]
	public function auditLog(array $options = ['limit' => 50, 'unfinished' => false]): RowsOfFields
	{
		$rows = [];
		$records = self::flag($options, 'unfinished')
			? $this->audit->unfinished()
			: $this->audit->recent(max(1, self::number($options, 'limit', 50)));

		foreach ($records as $record) {
			$rows[(string) $record['id']] = [
				'id' => (int) $record['id'],
				'started' => self::moment((int) $record['started'] * 1_000_000),
				'actor' => $record['actor'] === null ? '-' : (string) $record['actor'],
				'mode' => (string) $record['mode'],
				'target' => self::digest((string) $record['target']),
				'snapshot' => self::digest(
					$record['pre_snapshot'] === null ? null : (string) $record['pre_snapshot'],
				),
				'scope' => (string) $record['scope'],
				'outcome' => (string) $record['outcome'],
				'restored' => (int) $record['restored'],
				'skipped' => (int) $record['skipped'],
			];
		}

		return new RowsOfFields($rows);
	}

	/**
	 * Reports where each finding code sits on the repair ladder, and runs the pass its rung names.
	 *
	 * Rungs run cheapest and most reversible first, and a code moves one rung at a time so an
	 * operator can watch it climb. Everything at or below "rebuild" reconstructs derived state from
	 * data that still exists, so the worst case of running it wrongly is wasted work; that is the
	 * ceiling an unattended run may reach. Moving a code to quarantine or refuse takes a restore
	 * target away, so it is reachable only through an explicit `--rung` with a confirmation.
	 *
	 * @param string|null $code
	 *   The finding code to act on, or NULL to report every open code.
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return RowsOfFields
	 *   One row per open finding code, empty when nothing is outstanding.
	 */
	#[CLI\Command(name: 'strata:heal', aliases: ['strata-heal'])]
	#[
		CLI\Argument(
			name: 'code',
			description: 'The finding code to act on, such as frame.hash_mismatch.',
		),
	]
	#[CLI\Option(name: 'list', description: 'Report the ladder and act on nothing.')]
	#[CLI\Option(name: 'apply', description: 'Run the repair pass the code\'s current rung names.')]
	#[
		CLI\Option(
			name: 'rung',
			description: 'Move the code to this rung instead of escalating it one step.',
		),
	]
	#[CLI\Option(name: 'decay', description: 'Move the code one rung back down.')]
	#[
		CLI\Usage(
			name: 'drush strata:heal --list',
			description: 'Report where every open finding code sits.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:heal frame.hash_mismatch --apply -y',
			description: 'Run the repair pass the code has climbed to.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'code' => 'Code',
				'rung' => 'Rung',
				'automatic' => 'Automatic',
				'pass' => 'Repair Pass',
				'findings' => 'Open Findings',
				'worst' => 'Worst Severity',
			],
		),
	]
	public function heal(
		?string $code = null,
		array $options = [
			'list' => false,
			'apply' => false,
			'rung' => self::REQ,
			'decay' => false,
		],
	): RowsOfFields {
		$rung = self::value($options, 'rung') ?? '';

		if ($code === null || $code === '' || self::flag($options, 'list')) {
			return new RowsOfFields($this->ladder());
		}
		if ($rung !== '') {
			$this->moveTo($code, $rung);

			return new RowsOfFields($this->ladder());
		}
		if (self::flag($options, 'decay')) {
			$this->moveTo($code, RepairLadder::decay($this->ledger->rungFor($code)) ?? 'observe');

			return new RowsOfFields($this->ladder());
		}
		if (self::flag($options, 'apply')) {
			$this->runPass($code);

			return new RowsOfFields($this->ladder());
		}

		$next = RepairLadder::escalate($this->ledger->rungFor($code));

		if (!RepairLadder::isAutomatic($next)) {
			$this->prose()->warning(
				sprintf(
					'Escalating %s would reach "%s", which removes a restore target. Use drush ' .
						'strata:quarantine to ask for that.',
					$code,
					$next,
				),
			);

			return new RowsOfFields($this->ladder());
		}

		$this->moveTo($code, $next);

		return new RowsOfFields($this->ladder());
	}

	#endregion

	#region Tiering

	/**
	 * Reports what each storage tier holds, whether it answers, and what a restore would need.
	 *
	 * Two questions an operator cannot answer from anywhere else. The first is where history actually
	 * is, which matters because a bucket that is meant to be cold and is holding nothing means the
	 * migration has never run. The second is which buckets a rollback needs, and the point of asking
	 * it here is to find out before starting rather than halfway through.
	 *
	 * An object with no recorded tier is counted as unplaced rather than assumed to be nearby. While
	 * there is one, every tier is reported as possibly needed, because that is the true answer.
	 *
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return RowsOfFields
	 *   One row per tier, empty when the site writes to a single bucket.
	 */
	#[CLI\Command(name: 'strata:tiers', aliases: ['strata-tiers'])]
	#[
		CLI\Option(
			name: 'restore',
			description: 'Report which tiers a restore to this commit would read.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:tiers',
			description: 'Show every bucket, what it holds and whether it answers.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:tiers --restore=8f2c1a',
			description: 'Say which buckets have to be up before that rollback is started.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'tier' => 'Tier',
				'provider' => 'Provider',
				'location' => 'Location',
				'from-age' => 'From Age',
				'mode' => 'Mode',
				'objects' => 'Objects',
				'bytes' => 'Bytes',
				'needed' => 'Needed',
				'reachable' => 'Reachable',
			],
		),
	]
	#[CLI\FilterDefaultField(field: 'tier')]
	public function tiers(array $options = ['restore' => self::REQ]): RowsOfFields
	{
		$router = $this->engine->tiers();

		if ($router === null) {
			$this->io()->writeln('This site writes to one bucket, so there are no tiers.');

			return new RowsOfFields([]);
		}

		$held = $this->engine->placementIndex()->byTier();
		$status = $router->tierStatus();
		$needed = $this->tierRequirements($options);
		$rows = [];

		foreach ($router->tiers()->all() as $tier) {
			$rows[$tier->name()] = [
				'tier' => $tier->name(),
				'provider' => $tier->target->provider,
				'location' => $tier->target->location === '' ? '-' : $tier->target->location,
				'from-age' => $tier->fromAge,
				'mode' => $this->tierMode($tier),
				'objects' => $held[$tier->index]['objects'] ?? 0,
				'bytes' => $held[$tier->index]['bytes'] ?? 0,
				'needed' => $needed === null ? '-' : self::yesNo(isset($needed[$tier->index])),
				'reachable' =>
					($status[$tier->index] ?? null) === null
						? 'yes'
						: (string) $status[$tier->index],
			];
		}

		return new RowsOfFields($rows);
	}

	/**
	 * Which tiers a named restore would read.
	 *
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return array<int, array<string, mixed>>|null
	 *   Tier rows keyed by index, or NULL when no commit was named.
	 */
	private function tierRequirements(array $options): ?array
	{
		$target = trim((string) self::value($options, 'restore'));
		$pass = $this->engine->tierRequirements();

		if ($target === '' || $pass === null) {
			return null;
		}

		$report = $pass->require($target);
		$this->io()->writeln($report->summary());

		if (!$report->isSatisfiable()) {
			$this->io()->warning('That restore cannot run right now.');
		}

		return $report->tiers;
	}

	/**
	 * Whether a tier keeps the copy below it.
	 *
	 * @param Tier $tier
	 *   The tier.
	 *
	 * @return string
	 *   A short word for the column.
	 */
	private function tierMode(Tier $tier): string
	{
		if ($tier->isNearest()) {
			return 'hot';
		}

		return $tier->retainBelow ? 'replica' : 'destination';
	}

	#endregion

	#region Classifying

	/**
	 * Reports what the ephemeral keyspace holds and which patterns still need a decision.
	 *
	 * Caches, sessions, locks and queues are not one thing. Some of it is the only copy of something
	 * the site needs and some rebuilds itself the moment it is asked for. Anything the heuristics
	 * cannot place is captured verbatim and listed here, busiest first, because a pattern covering
	 * two million keys is the one worth deciding.
	 *
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return RowsOfFields
	 *   One row per classification, plus a row per pattern still undecided.
	 */
	#[CLI\Command(name: 'strata:classify', aliases: ['strata-classify'])]
	#[CLI\Option(name: 'limit', description: 'Most keys the discovery pass examines.')]
	#[
		CLI\Option(
			name: 'skip-discovery',
			description: 'Read the registry without looking at the keyspace again.',
		),
	]
	#[CLI\Option(name: 'pattern', description: 'Record a decision for this key pattern.')]
	#[
		CLI\Option(
			name: 'decide',
			description: 'The decision to record: authoritative, derivable or unclassified.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:classify',
			description: 'Report the keyspace and what is undecided.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:classify --pattern="cache_render:*" --decide=derivable',
			description: 'Record that a pattern rebuilds itself and need not be captured.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'what' => 'What',
				'classification' => 'Classification',
				'source' => 'Source',
				'patterns' => 'Patterns',
				'keys' => 'Keys',
				'bytes' => 'Bytes',
			],
		),
	]
	public function classify(
		array $options = [
			'limit' => 10000,
			'skip-discovery' => false,
			'pattern' => self::REQ,
			'decide' => self::REQ,
		],
	): RowsOfFields {
		$pattern = self::value($options, 'pattern') ?? '';
		$decision = self::value($options, 'decide') ?? '';

		if ($pattern !== '' && $decision !== '') {
			$this->decide($pattern, $decision);
		}
		if (!self::flag($options, 'skip-discovery')) {
			$scanned = max(1, self::number($options, 'limit', 10000));
			$report = $this->engine->keyspaceDiscovery()->discover($scanned);

			$this->announce($report->isClean(), $report->summary());

			foreach ($report->problems as $problem) {
				$this->prose()->warning($problem);
			}
		}

		$rows = [];

		foreach ($this->classifications->statistics() as $name => $totals) {
			$rows[(string) $name] = [
				'what' => sprintf('all %s', (string) $name),
				'classification' => (string) $name,
				'source' => '-',
				'patterns' => $totals['patterns'],
				'keys' => $totals['keys'],
				'bytes' => self::bytes($totals['bytes']),
			];
		}

		foreach ($this->classifications->undecided(50) as $row) {
			$rows[(string) $row['pattern']] = [
				'what' => (string) $row['pattern'],
				'classification' => (string) $row['classification'],
				'source' => (string) $row['source'],
				'patterns' => 1,
				'keys' => (int) $row['keys_seen'],
				'bytes' => self::bytes((int) $row['bytes_seen']),
			];
		}

		return new RowsOfFields($rows);
	}

	#endregion

	#region Costing

	/**
	 * Projects what a site of a given shape costs to keep.
	 *
	 * The shape decides how much churn there is and the policy decides what that churn costs, so a
	 * site twice the size on half the flush interval is not twice the bill. The policy dials default
	 * to what this site is configured for, which makes the projection a statement about this
	 * installation rather than about an imaginary one.
	 *
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return PropertyList
	 *   The projection.
	 */
	#[CLI\Command(name: 'strata:estimate', aliases: ['strata-estimate'])]
	#[CLI\Option(name: 'users', description: 'Registered users.')]
	#[CLI\Option(name: 'nodes', description: 'Content items.')]
	#[CLI\Option(name: 'rows', description: 'Rows in tables the site\'s own modules write.')]
	#[CLI\Option(name: 'files', description: 'Managed and unmanaged files.')]
	#[CLI\Option(name: 'file-bytes', description: 'Total bytes those files occupy.')]
	#[
		CLI\Option(
			name: 'active-share',
			description: 'Share of users active on a given day, from 0 to 1.',
		),
	]
	#[
		CLI\Option(
			name: 'retention',
			description: 'How many days the coarsest retention level keeps history.',
		),
	]
	#[
		CLI\Option(
			name: 'deploys',
			description: 'Deploys a year, which is how often the code realm changes.',
		),
	]
	#[
		CLI\Option(
			name: 'interval',
			description: 'Seconds between flushes; defaults to the configured bound.',
		),
	]
	#[
		CLI\Option(
			name: 'access-churn',
			description: 'How login churn is recorded: event, delta or off.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:estimate --users=50000 --nodes=20000',
			description: 'Project the bill for a site of that shape on this site\'s policy.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:estimate --users=50000 --interval=60 --format=json',
			description: 'Compare a longer flush interval, as data for a spreadsheet.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'total-bytes' => 'Stored Bytes',
				'gigabytes' => 'Stored GB',
				'ops-per-day' => 'Operations a Day',
				'writes-per-month' => 'Write Requests a Month',
				'reads-per-month' => 'Read Requests a Month',
				'ratio' => 'Compression Ratio',
				'delta-reduction' => 'Delta Reduction',
				'collapse' => 'Retention Collapse',
				'dominant' => 'Largest Component',
				'dominant-share' => 'Its Share',
				'r2' => 'R2 a Month',
				's3' => 'S3 a Month',
				's3-ia' => 'S3 Infrequent Access a Month',
			],
		),
	]
	#[CLI\Format(listDelimiter: ':', tableStyle: 'compact')]
	public function estimate(
		array $options = [
			'users' => 0,
			'nodes' => 0,
			'rows' => 0,
			'files' => 0,
			'file-bytes' => 0,
			'active-share' => self::REQ,
			'retention' => 365,
			'deploys' => 150,
			'interval' => self::REQ,
			'access-churn' => self::REQ,
		],
	): PropertyList {
		$settings = $this->configFactory->get('strata.settings');
		$share = self::value($options, 'active-share');
		$churn = (string) ($settings->get('capture.access_churn') ?? Measurement::ACCESS_EVENT);
		$configured = max(1, (int) $settings->get('flush.max_age'));
		$projection = (new Estimator())->project(
			new Measurement(
				max(0, self::number($options, 'users', 0)),
				max(0, self::number($options, 'nodes', 0)),
				max(0, self::number($options, 'rows', 0)),
				max(0, self::number($options, 'files', 0)),
				max(0, self::number($options, 'file-bytes', 0)),
				$share === null ? 0.1 : min(1.0, max(0.0, (float) $share)),
				max(1, self::number($options, 'interval', $configured)),
				max(1, (int) ($settings->get('retention.base_interval') ?? 14_400)),
				max(1, self::number($options, 'retention', 365)),
				max(0, self::number($options, 'deploys', 150)),
				self::value($options, 'access-churn') ?? $churn,
				(bool) $settings->get('capture.file'),
			),
		);

		$this->printComponents($projection);

		$costs = $projection->costs();

		return new PropertyList([
			'total-bytes' => self::bytes($projection->totalBytes()),
			'gigabytes' => sprintf('%.2f', $projection->totalGigabytes()),
			'ops-per-day' => sprintf('%.0f', $projection->opsPerDay),
			'writes-per-month' => $projection->writesPerMonth,
			'reads-per-month' => $projection->readsPerMonth,
			'ratio' => sprintf('%.2fx', $projection->compressionRatio),
			'delta-reduction' => sprintf('%.1f%%', $projection->deltaReduction * 100),
			'collapse' => sprintf('%.2fx', $projection->collapse),
			'dominant' => $projection->dominantComponent(),
			'dominant-share' => sprintf(
				'%.0f%%',
				$projection->share($projection->dominantComponent()) * 100,
			),
			'r2' => sprintf('%.2f', $costs['r2'] ?? 0.0),
			's3' => sprintf('%.2f', $costs['s3'] ?? 0.0),
			's3-ia' => sprintf('%.2f', $costs['s3-ia'] ?? 0.0),
		]);
	}

	/**
	 * Measures what each codec on this host achieves on this site's own payloads.
	 *
	 * A compression ratio depends on content, so the shipped figures exist to let two hosts be
	 * compared and to give a size estimate somewhere to start. This measures the real thing, and
	 * marks every row it produced as measured here so it is never read as a shipped number. A site
	 * with no history yet has nothing to sample, and the reference figures are reported instead.
	 *
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return RowsOfFields
	 *   One row per codec.
	 */
	#[CLI\Command(name: 'strata:calibrate', aliases: ['strata-calibrate'])]
	#[CLI\Option(name: 'ref', description: 'Ref to collect payload samples from.')]
	#[CLI\Option(name: 'samples', description: 'Most payloads to compress per codec.')]
	#[CLI\Option(name: 'level', description: 'Which level to measure: fast, default or dense.')]
	#[CLI\Usage(name: 'drush strata:calibrate', description: 'Measure every codec on this host.')]
	#[
		CLI\Usage(
			name: 'drush strata:calibrate --level=fast',
			description: 'Measure the level the flush path uses rather than the compaction level.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'codec' => 'Codec',
				'available' => 'Available',
				'per-frame' => 'Usable Per Frame',
				'level' => 'Level',
				'ratio' => 'Ratio',
				'compress' => 'Compress MB/s',
				'decompress' => 'Decompress MB/s',
				'source' => 'Source',
				'reason' => 'Reason',
			],
		),
	]
	public function calibrate(
		array $options = ['ref' => RefStore::MAIN, 'samples' => 200, 'level' => 'dense'],
	): RowsOfFields {
		$registry = $this->engine->codecs();
		$wanted = (string) (self::value($options, 'level') ?? 'dense');
		$which = in_array($wanted, ['fast', 'default', 'dense'], true) ? $wanted : 'dense';
		$samples = $this->samples(
			self::ref($options),
			max(1, self::number($options, 'samples', 200)),
		);
		$frameSize = (int) $this->configFactory->get('strata.settings')->get('frame.size');

		if ($samples === []) {
			$this->prose()->warning(
				'There is no stored history to sample, so the shipped reference figures are reported.',
			);
		}

		$rows = [];

		foreach (CodecCatalog::profiles($registry) as $profile) {
			$level = (int) ($profile->levels[$which] ?? 0);
			$measurement =
				$samples === [] || !$profile->available
					? $profile->measurement($frameSize, false, $which)
					: $this->measure($registry->get($profile->id), $level, $frameSize, $samples);

			$rows[$profile->id] = [
				'codec' => $profile->label,
				'available' => self::yesNo($profile->available),
				'per-frame' => self::yesNo($profile->perFrame),
				'level' => $level,
				'ratio' => $measurement === null ? '-' : sprintf('%.2fx', $measurement->ratio),
				'compress' =>
					$measurement === null
						? '-'
						: sprintf('%.1f', $measurement->compressMbPerSecond),
				'decompress' =>
					$measurement === null
						? '-'
						: sprintf('%.1f', $measurement->decompressMbPerSecond),
				'source' => $this->source($measurement),
				'reason' => (string) ($profile->reason ?? '-'),
			];
		}

		return new RowsOfFields($rows);
	}

	#endregion

	#region Ladder

	/**
	 * Every open finding code with where it sits and what its rung would run.
	 *
	 * @return array<string, array<string, string|int>>
	 *   Code keyed to its row.
	 */
	private function ladder(): array
	{
		$counts = [];
		$worst = [];

		foreach ($this->ledger->open() as $finding) {
			$counts[$finding->code] = ($counts[$finding->code] ?? 0) + 1;
			$worst[$finding->code] = max(
				$worst[$finding->code] ?? Finding::INFO,
				$finding->severity,
			);
		}

		$rows = [];

		foreach (array_keys($counts) as $code) {
			$name = (string) $code;
			$rung = $this->ledger->rungFor($name);

			$rows[$name] = [
				'code' => $name,
				'rung' => $rung,
				'automatic' => self::yesNo(RepairLadder::isAutomatic($rung)),
				'pass' => $this->passFor($rung),
				'findings' => $counts[$code],
				'worst' => $this->severityName($worst[$code]),
			];
		}

		return $rows;
	}

	/**
	 * Moves a code to a rung, asking first when the rung removes a restore target.
	 *
	 * @param string $code
	 *   The finding code.
	 * @param string $rung
	 *   The rung to move to.
	 */
	private function moveTo(string $code, string $rung): void
	{
		$from = $this->ledger->rungFor($code);

		$this->prose()->text(sprintf('%s would move from "%s" to "%s"', $code, $from, $rung));

		if (RepairLadder::rank($rung) === RepairLadder::UNRANKED) {
			$this->prose()->error(
				sprintf(
					'"%s" is not a rung; the ladder is %s',
					$rung,
					implode(', ', RepairLadder::RUNGS),
				),
			);

			return;
		}
		if ($from === $rung) {
			$this->prose()->warning(sprintf('%s is already at "%s".', $code, $rung));

			return;
		}
		if (
			!RepairLadder::isAutomatic($rung) &&
			!$this->agreed(sprintf('Move %s to "%s"? This removes a restore target.', $code, $rung))
		) {
			$this->prose()->warning('The rung was left alone.');

			return;
		}

		$this->ledger->setRung($code, $rung);
		$this->announce(true, sprintf('%s is now at "%s"', $code, $rung));
	}

	/**
	 * Runs the repair pass a code's current rung names.
	 *
	 * @param string $code
	 *   The finding code.
	 */
	private function runPass(string $code): void
	{
		$rung = $this->ledger->rungFor($code);

		if (!RepairLadder::isAutomatic($rung)) {
			$this->prose()->warning(
				sprintf('%s sits at "%s", which has no pass a command may run.', $code, $rung),
			);

			return;
		}

		if ($rung === 'reindex') {
			$this->announce(true, $this->engine->reindexer()->reindex()->summary());

			return;
		}
		if ($rung === 'refetch' || $rung === 'rebuild') {
			$this->announce(true, $this->engine->verifier()->verify()->summary());

			return;
		}

		$this->prose()->text(sprintf('%s sits at "%s", which only watches.', $code, $rung));
	}

	/**
	 * The pass a rung names.
	 *
	 * @param string $rung
	 *   The rung.
	 *
	 * @return string
	 *   What running the rung does.
	 */
	private function passFor(string $rung): string
	{
		return match ($rung) {
			'reindex' => 'rebuild the local indexes',
			'refetch', 'rebuild' => 'fetch and decode every frame',
			'quarantine' => 'stop using the objects as restore targets',
			'refuse' => 'block a restore outright',
			default => 'watch only',
		};
	}

	#endregion

	#region Measuring

	/**
	 * Payload samples to compress, newest first.
	 *
	 * @param string $ref
	 *   Ref to collect from.
	 * @param int $limit
	 *   Most payloads to return.
	 *
	 * @return list<string>
	 *   The payloads, empty when there is no readable history.
	 */
	private function samples(string $ref, int $limit): array
	{
		try {
			$collected = $this->engine->dictionaryPass()->collect($ref);
		} catch (Throwable) {
			// an unreadable or absent history is reported as the reference-figure fallback
			return [];
		}

		$samples = [];

		foreach ($collected as $payloads) {
			foreach ($payloads as $payload) {
				if (count($samples) >= $limit) {
					return $samples;
				}

				$samples[] = $payload;
			}
		}

		return $samples;
	}

	/**
	 * Compresses and decompresses a sample set, timing both directions.
	 *
	 * @param CompressionCodecInterface $codec
	 *   The codec to measure.
	 * @param int $level
	 *   The level to measure at.
	 * @param int $frameSize
	 *   The configured frame size, recorded with the figure.
	 * @param list<string> $samples
	 *   The payloads.
	 *
	 * @return CodecMeasurement|null
	 *   The figure, or NULL when the codec could not run.
	 */
	private function measure(
		CompressionCodecInterface $codec,
		int $level,
		int $frameSize,
		array $samples,
	): ?CodecMeasurement {
		$raw = 0;
		$stored = 0;
		$compressed = [];
		$started = microtime(true);

		try {
			foreach ($samples as $payload) {
				$sealed = $codec->compress($payload, $level);
				$raw += strlen($payload);
				$stored += strlen($sealed);
				$compressed[] = $sealed;
			}

			$compressSeconds = microtime(true) - $started;
			$started = microtime(true);

			foreach ($compressed as $sealed) {
				$codec->decompress($sealed);
			}

			$decompressSeconds = microtime(true) - $started;
		} catch (Throwable) {
			// a codec whose extension is absent is already reported as unavailable
			return null;
		}

		return new CodecMeasurement(
			$codec->id(),
			$level,
			false,
			$frameSize,
			$stored < 1 ? 1.0 : $raw / $stored,
			$this->megabytesPerSecond($raw, $compressSeconds),
			$this->megabytesPerSecond($raw, $decompressSeconds),
			true,
		);
	}

	/**
	 * A throughput figure.
	 *
	 * @param int $bytes
	 *   Bytes processed.
	 * @param float $seconds
	 *   How long it took.
	 *
	 * @return float
	 *   Megabytes a second, or zero when the run was too short to time.
	 */
	private function megabytesPerSecond(int $bytes, float $seconds): float
	{
		return $seconds <= 0.0 ? 0.0 : $bytes / 1_000_000 / $seconds;
	}

	/**
	 * Where a figure came from.
	 *
	 * @param CodecMeasurement|null $measurement
	 *   The figure, or NULL when there is none.
	 *
	 * @return string
	 *   Either "measured here", "reference" or "-".
	 */
	private function source(?CodecMeasurement $measurement): string
	{
		if ($measurement === null) {
			return '-';
		}

		return $measurement->onThisHost ? 'measured here' : 'reference';
	}

	/**
	 * Prints what each component of a projection contributes.
	 *
	 * @param Projection $projection
	 *   The projection.
	 */
	private function printComponents(Projection $projection): void
	{
		$rows = [];

		foreach ($projection->components() as $component => $bytes) {
			$rows[] = [
				(string) $component,
				self::bytes($bytes),
				sprintf('%.0f%%', $projection->share((string) $component) * 100),
			];
		}

		$this->prose()->text($projection->summary());
		$this->prose()->table(['Component', 'Bytes', 'Share'], $rows);

		foreach ($projection->notes as $note) {
			$this->prose()->note($note);
		}
	}

	#endregion

	#region Naming

	/**
	 * Records a human decision about a key pattern.
	 *
	 * @param string $pattern
	 *   The key pattern.
	 * @param string $decision
	 *   A classification value.
	 */
	private function decide(string $pattern, string $decision): void
	{
		$classification = Classification::tryFrom($decision);

		if ($classification === null) {
			$this->prose()->error(
				sprintf(
					'"%s" is not a classification; use authoritative, derivable or unclassified',
					$decision,
				),
			);

			return;
		}

		$this->classifications->decide($pattern, $classification);
		$this->announce(true, sprintf('%s is now %s', $pattern, $classification->label()));
	}

	/**
	 * The name of a severity ordinal.
	 *
	 * @param int $severity
	 *   One of the Finding severity ordinals.
	 *
	 * @return string
	 *   The name, or "INFO" for an ordinal below the lowest named one.
	 */
	private function severityName(int $severity): string
	{
		return match (true) {
			$severity >= Finding::CRITICAL => 'CRITICAL',
			$severity >= Finding::ERROR => 'ERROR',
			$severity >= Finding::WARN => 'WARN',
			default => 'INFO',
		};
	}

	#endregion
}
