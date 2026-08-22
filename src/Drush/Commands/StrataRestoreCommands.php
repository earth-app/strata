<?php

declare(strict_types=1);

namespace Drupal\strata\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\strata\Archive\ArchiveExporter;
use Drupal\strata\Archive\ArchiveImporter;
use Drupal\strata\Archive\ArchiveManifest;
use Drupal\strata\Branch\Branch;
use Drupal\strata\Branch\MergeEntry;
use Drupal\strata\Branch\MergeOutcome;
use Drupal\strata\Branch\MergePlan;
use Drupal\strata\Branch\MergeResult;
use Drupal\strata\Branch\MergeStrategy;
use Drupal\strata\Engine;
use Drupal\strata\Health\HealthLedgerInterface;
use Drupal\strata\Health\RepairLadder;
use Drupal\strata\Restore\Conflict;
use Drupal\strata\Restore\RestorePlan;
use Drupal\strata\Restore\RestoreResult;
use Drupal\strata\Restore\SubjectStatus;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * The commands that read history back and the commands that write it over the live site.
 *
 * A restore is planned before it is applied, and the plan is what the operator is shown. It carries
 * a per-subject split - restorable, degraded, unrestorable - and the plan a confirmation applies is
 * the same object that was printed, not a second one computed after the answer. A subject somebody
 * changed while the plan was waiting is listed as a conflict and left alone unless the operator says
 * otherwise, because overwriting an edit nobody reviewed is a decision rather than a detail.
 *
 * A merge follows the same shape and the same rule. Its manifest is printed with both values of
 * every key the two sides disagree about, and a strategy has to be named before any of them is
 * decided. It covers the configuration realm alone; the other realms are captured as deltas against
 * a parent, and merging two divergent delta chains would mean inventing values.
 *
 * Archives are the same history in a portable file. An import verifies every content-addressed
 * object against its own digest before writing it and never advances a ref, so importing history
 * from elsewhere cannot change what this site restores to.
 *
 * @see RestorePlan
 * @see MergePlan
 * @see ArchiveManifest
 */
final class StrataRestoreCommands extends DrushCommands
{
	use AutowireTrait;
	use StrataOutputTrait;

	/**
	 * Constructs the command set.
	 *
	 * @param Engine $engine
	 *   Builds the preflight, the replayer and the restore.
	 * @param HealthLedgerInterface $ledger
	 *   Holds the rung each finding code sits at, which is what a quarantine moves.
	 */
	public function __construct(
		private readonly Engine $engine,
		private readonly HealthLedgerInterface $ledger,
	) {
		parent::__construct();
	}

	/**
	 * The archive writer, built when a command asks for one.
	 *
	 * Not a constructor argument. Building one assembles the whole store, which refuses on a site
	 * that has not chosen a key yet, and Drush answers a constructor that throws by dropping every
	 * command on the class with a debug-level line nobody sees. Losing `strata:rollback` because
	 * `strata:export` could not be built is the wrong trade at exactly the moment somebody needs it.
	 *
	 * @return ArchiveExporter
	 *   The exporter.
	 */
	private function exporter(): ArchiveExporter
	{
		return $this->engine->archiveExporter();
	}

	/**
	 * The archive reader, built when a command asks for one.
	 *
	 * @return ArchiveImporter
	 *   The importer.
	 */
	private function importer(): ArchiveImporter
	{
		return $this->engine->archiveImporter();
	}

	#region Reading

	/**
	 * What differs between two points in history.
	 *
	 * Without a subject this compares which subjects each commit covers, which is the question a
	 * rollback turns on: what would appear, what would disappear, and what exists in both. With one
	 * it reconstructs that subject at both commits and compares the fields.
	 *
	 * @param string $commit
	 *   The commit to compare.
	 * @param string|null $other
	 *   The commit to compare it against, or NULL for the current head.
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return RowsOfFields
	 *   One row per difference, empty when the two points agree.
	 */
	#[CLI\Command(name: 'strata:diff', aliases: ['strata-diff'])]
	#[CLI\Argument(name: 'commit', description: 'The commit to compare.')]
	#[
		CLI\Argument(
			name: 'other',
			description: 'The commit to compare it against. Defaults to the head.',
		),
	]
	#[
		CLI\Option(
			name: 'subject',
			description: 'Compare the fields of one subject, such as entity/node:42.',
		),
	]
	#[CLI\Option(name: 'limit', description: 'Most differences to report.')]
	#[
		CLI\Usage(
			name: 'drush strata:diff 4f2a1b',
			description: 'Compare a commit against the current head.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:diff 4f2a1b 9c7e02 --subject=entity/node:42',
			description: 'Compare one node between two commits, field by field.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'change' => 'Change',
				'subject' => 'Subject',
				'left' => 'At Commit',
				'right' => 'At Other',
			],
		),
	]
	public function diff(
		string $commit,
		?string $other = null,
		array $options = ['subject' => self::REQ, 'limit' => 200],
	): RowsOfFields {
		$replayer = $this->engine->replayer();
		$right = $other ?? ($this->engine->commitLog()->head()?->id() ?? $commit);
		$limit = max(1, self::number($options, 'limit', 200));
		$subject = self::value($options, 'subject') ?? '';

		if ($subject !== '') {
			return new RowsOfFields($this->fieldDiff($commit, $right, $subject, $limit));
		}

		$left = array_fill_keys($replayer->subjectsAt($commit), true);
		$rows = [];

		foreach ($replayer->subjectsAt($right) as $path) {
			if (isset($left[$path])) {
				unset($left[$path]);

				continue;
			}
			if (count($rows) >= $limit) {
				break;
			}

			$rows[$path] = [
				'change' => 'only in other',
				'subject' => $path,
				'left' => 'absent',
				'right' => 'present',
			];
		}

		foreach (array_keys($left) as $path) {
			if (count($rows) >= $limit) {
				break;
			}

			$rows[(string) $path] = [
				'change' => 'only in commit',
				'subject' => (string) $path,
				'left' => 'present',
				'right' => 'absent',
			];
		}

		return new RowsOfFields($rows);
	}

	#endregion

	#region Restoring

	/**
	 * Puts the whole site back to a commit.
	 *
	 * The plan is printed before the confirmation, with the restorable, degraded and unrestorable
	 * split and every subject somebody has changed since the plan was built. A degraded subject is
	 * one only partly reconstructable, and it is skipped unless `--fill-degraded` says otherwise:
	 * writing half a node over a whole one loses data that was still there.
	 *
	 * @param string $commit
	 *   Commit id to restore to.
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return PropertyList
	 *   What was written, or what would be.
	 */
	#[CLI\Command(name: 'strata:rollback', aliases: ['strata-rollback'])]
	#[CLI\Argument(name: 'commit', description: 'Commit id to restore to.')]
	#[CLI\Option(name: 'dry-run', description: 'Plan the restore and write nothing.')]
	#[
		CLI\Option(
			name: 'limit',
			description: 'Most subjects to plan; 0 for every subject the commit covers.',
		),
	]
	#[
		CLI\Option(
			name: 'fill-degraded',
			description: 'Write subjects that can only be partly reconstructed.',
		),
	]
	#[
		CLI\Option(
			name: 'accept-conflicts',
			description: 'Write subjects somebody changed since the plan was built.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:rollback 4f2a1b --dry-run',
			description: 'Show what a rollback would write.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:rollback 4f2a1b -y',
			description: 'Roll the site back without being asked to confirm.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'outcome' => 'Outcome',
				'target' => 'Target',
				'snapshot' => 'Undo Commit',
				'restorable' => 'Restorable',
				'degraded' => 'Degraded',
				'unrestorable' => 'Unrestorable',
				'restored' => 'Restored',
				'skipped' => 'Skipped',
				'failed' => 'Failed',
				'refused' => 'Refused',
			],
		),
	]
	#[CLI\Format(listDelimiter: ':', tableStyle: 'compact')]
	public function rollback(
		string $commit,
		array $options = [
			'dry-run' => false,
			'limit' => 0,
			'fill-degraded' => false,
			'accept-conflicts' => false,
		],
	): PropertyList {
		$limit = max(0, self::number($options, 'limit', 0));
		$plan = $this->engine
			->preflight()
			->plan(
				$commit,
				self::flag($options, 'fill-degraded'),
				$limit > 0 ? $limit : null,
				self::flag($options, 'accept-conflicts'),
			);

		return $this->applyPlan($plan, 'all', self::flag($options, 'dry-run'));
	}

	/**
	 * Puts named subjects back to a commit, leaving the rest of the site alone.
	 *
	 * A subject the target commit does not cover is planned anyway and comes back unrestorable, so
	 * "that node did not exist then" is an answer rather than an error.
	 *
	 * @param string $commit
	 *   Commit id to restore to.
	 * @param string $subjects
	 *   Comma-separated subject paths, such as "entity/node:42,config/system.site".
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return PropertyList
	 *   What was written, or what would be.
	 */
	#[CLI\Command(name: 'strata:restore', aliases: ['strata-restore'])]
	#[CLI\Argument(name: 'commit', description: 'Commit id to restore to.')]
	#[
		CLI\Argument(
			name: 'subjects',
			description: 'Comma-separated subject paths, such as entity/node:42.',
		),
	]
	#[CLI\Option(name: 'dry-run', description: 'Plan the restore and write nothing.')]
	#[
		CLI\Option(
			name: 'fill-degraded',
			description: 'Write subjects that can only be partly reconstructed.',
		),
	]
	#[
		CLI\Option(
			name: 'accept-conflicts',
			description: 'Write subjects somebody changed since the plan was built.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:restore 4f2a1b entity/node:42 --dry-run',
			description: 'Show what restoring one node would write.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:restore 4f2a1b config/system.site,config/user.settings -y',
			description: 'Restore two config objects without being asked to confirm.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'outcome' => 'Outcome',
				'target' => 'Target',
				'snapshot' => 'Undo Commit',
				'restorable' => 'Restorable',
				'degraded' => 'Degraded',
				'unrestorable' => 'Unrestorable',
				'restored' => 'Restored',
				'skipped' => 'Skipped',
				'failed' => 'Failed',
				'refused' => 'Refused',
			],
		),
	]
	#[CLI\Format(listDelimiter: ':', tableStyle: 'compact')]
	public function restore(
		string $commit,
		string $subjects,
		array $options = [
			'dry-run' => false,
			'fill-degraded' => false,
			'accept-conflicts' => false,
		],
	): PropertyList {
		$paths = array_values(array_filter(array_map('trim', explode(',', $subjects))));
		$plan = $this->engine
			->preflight()
			->planSubjects(
				$commit,
				$paths,
				self::flag($options, 'fill-degraded'),
				self::flag($options, 'accept-conflicts'),
			);

		return $this->applyPlan($plan, implode(',', $paths), self::flag($options, 'dry-run'));
	}

	/**
	 * Stops a finding code being treated as restorable, or lets it be again.
	 *
	 * Quarantine sits above the ceiling an unattended run may reach, so nothing gets here without a
	 * person asking: the rung takes a restore target away, which is the thing a backup exists to
	 * provide. Called with no code it lists what is currently held.
	 *
	 * @param string|null $code
	 *   The finding code to move, or NULL to list what is quarantined.
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return RowsOfFields
	 *   One row per code, empty when nothing is quarantined.
	 */
	#[CLI\Command(name: 'strata:quarantine', aliases: ['strata-quarantine'])]
	#[
		CLI\Argument(
			name: 'code',
			description: 'The finding code to quarantine, such as frame.hash_mismatch.',
		),
	]
	#[CLI\Option(name: 'dry-run', description: 'Report the move without making it.')]
	#[
		CLI\Option(
			name: 'release',
			description: 'Move the code one rung back down instead of quarantining it.',
		),
	]
	#[
		CLI\Option(
			name: 'refuse',
			description: 'Move the code to refuse, which blocks a restore outright.',
		),
	]
	#[CLI\Usage(name: 'drush strata:quarantine', description: 'List the codes currently held.')]
	#[
		CLI\Usage(
			name: 'drush strata:quarantine frame.hash_mismatch -y',
			description: 'Stop frames with a hash mismatch being used as restore targets.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:quarantine frame.hash_mismatch --release -y',
			description: 'Let the code climb the ladder again once the frames have been repaired.',
		),
	]
	#[CLI\FieldLabels(labels: ['code' => 'Code', 'rung' => 'Rung', 'findings' => 'Open Findings'])]
	public function quarantine(
		?string $code = null,
		array $options = ['dry-run' => false, 'release' => false, 'refuse' => false],
	): RowsOfFields {
		if ($code === null || $code === '') {
			return new RowsOfFields($this->heldCodes());
		}

		$from = $this->ledger->rungFor($code);
		$to = $this->targetRung($from, $options);

		$this->prose()->text(sprintf('%s would move from "%s" to "%s"', $code, $from, $to));

		if ($from === $to) {
			$this->prose()->warning(sprintf('%s is already at "%s".', $code, $to));

			return new RowsOfFields($this->heldCodes());
		}
		if (self::flag($options, 'dry-run')) {
			return new RowsOfFields($this->heldCodes());
		}
		if (!$this->agreed(sprintf('Move %s to "%s"?', $code, $to))) {
			$this->prose()->warning('The rung was left alone.');

			return new RowsOfFields($this->heldCodes());
		}

		$this->ledger->setRung($code, $to);
		$this->announce(true, sprintf('%s is now at "%s"', $code, $to));

		return new RowsOfFields($this->heldCodes());
	}

	#endregion

	#region Branching

	/**
	 * Lists the configuration branches, or creates and removes one.
	 *
	 * Called with no name it lists what the store holds. Called with one it cuts a branch off the
	 * trunk's tip, or off `--from`, and `--delete` removes the name while leaving every commit it
	 * pointed at in place for a prune to decide about.
	 *
	 * A branch carries configuration and nothing else, because configuration is captured whole and
	 * every other realm is captured as deltas against a parent. Flush onto one with
	 * `drush strata:flush --ref=heads/NAME`.
	 *
	 * @param string|null $name
	 *   The branch name, or NULL to list what exists.
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return RowsOfFields
	 *   One row per branch, the trunk included.
	 */
	#[CLI\Command(name: 'strata:branch', aliases: ['strata-branch'])]
	#[CLI\Argument(name: 'name', description: 'The branch name. Omit to list what exists.')]
	#[
		CLI\Option(
			name: 'from',
			description: 'Commit to fork from. Defaults to the trunk\'s current tip.',
		),
	]
	#[CLI\Option(name: 'delete', description: 'Remove the named branch instead of creating it.')]
	#[
		CLI\Usage(
			name: 'drush strata:branch',
			description: 'List every configuration branch and where each one points.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:branch release-12',
			description: 'Cut a branch off the current head to hold configuration changes.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:branch release-12 --delete -y',
			description: 'Remove a branch name, leaving its commits for a prune to decide about.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'name' => 'Branch',
				'ref' => 'Ref',
				'forked-from' => 'Forked From',
				'tip' => 'Tip',
				'moved' => 'Moved Since Fork',
				'created' => 'Created',
				'actor' => 'Actor',
			],
		),
	]
	#[CLI\DefaultTableFields(fields: ['name', 'forked-from', 'tip', 'moved', 'created'])]
	public function branch(
		?string $name = null,
		array $options = ['from' => self::REQ, 'delete' => false],
	): RowsOfFields {
		$merger = $this->engine->merger();

		if ($name === null || $name === '') {
			return new RowsOfFields($this->branchRows($merger->list()));
		}

		if (self::flag($options, 'delete')) {
			if (!$this->agreed(sprintf('Remove the branch "%s"?', $name))) {
				$this->prose()->warning('The branch was left alone.');

				return new RowsOfFields($this->branchRows($merger->list()));
			}

			$this->announce(
				$merger->remove($name),
				sprintf('branch %s is gone; its commits are not', $name),
			);

			return new RowsOfFields($this->branchRows($merger->list()));
		}

		$branch = $merger->branch($name, self::value($options, 'from'));

		$this->announce(
			true,
			sprintf('branch %s forked from %s', $branch->name, self::digest($branch->forkedFrom)),
		);
		$this->prose()->text(
			sprintf(
				'Flush configuration onto it with: drush strata:flush --ref=%s',
				$branch->ref(),
			),
		);

		return new RowsOfFields($this->branchRows($merger->list()));
	}

	/**
	 * Merges a configuration branch back into the trunk.
	 *
	 * The manifest is printed before the confirmation: every object the merge would write, every one
	 * it would keep, and both values of every key the two sides disagree about. A conflict stops the
	 * merge unless `--strategy` names what to do with it, because two people having written different
	 * answers to the same question is not something to decide by default.
	 *
	 * Applying goes through the ordinary logical restore, so the pre-write snapshot that makes it
	 * undoable is taken whatever the options say.
	 *
	 * @param string $name
	 *   The branch to merge.
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return PropertyList
	 *   What was written, or what would be.
	 */
	#[CLI\Command(name: 'strata:merge', aliases: ['strata-merge'])]
	#[CLI\Argument(name: 'name', description: 'The branch to merge into the trunk.')]
	#[
		CLI\Option(
			name: 'strategy',
			description: 'What to do with a key both sides changed: refuse, ours or theirs.',
		),
	]
	#[CLI\Option(name: 'dry-run', description: 'Print the manifest and write nothing.')]
	#[
		CLI\Usage(
			name: 'drush strata:merge release-12 --dry-run',
			description: 'Print what merging a branch would write, and what collides.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:merge release-12 --strategy=theirs -y',
			description: 'Merge a branch, letting it win every key the trunk also changed.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'branch' => 'Branch',
				'target' => 'Target',
				'base' => 'Merge Base',
				'strategy' => 'Strategy',
				'applied' => 'Applied',
				'written' => 'Objects Written',
				'conflicts' => 'Conflicts',
				'unchanged' => 'Unchanged',
				'snapshot' => 'Undo Commit',
				'commit' => 'Merge Commit',
				'failed' => 'Failed',
				'refused' => 'Refused',
			],
		),
	]
	#[CLI\Format(listDelimiter: ':', tableStyle: 'compact')]
	public function merge(
		string $name,
		array $options = ['strategy' => self::REQ, 'dry-run' => false],
	): PropertyList {
		$merger = $this->engine->merger();
		$plan = $merger->plan($name, MergeStrategy::named(self::value($options, 'strategy')));

		$this->printMerge($plan);

		if (self::flag($options, 'dry-run') || !$plan->isApplicable()) {
			return $this->mergeList($merger->apply($plan, false));
		}
		if (
			!$this->agreed(
				sprintf(
					'Write %d configuration objects from %s over the live site?',
					count($plan->writable()),
					$name,
				),
			)
		) {
			$this->prose()->warning('Nothing was merged.');

			return $this->mergeList($merger->apply($plan, false));
		}

		$result = $merger->apply($plan, true);

		$this->announce($result->isComplete(), $result->summary());

		return $this->mergeList($result);
	}

	#endregion

	#region Archiving

	/**
	 * Writes a span of history into one portable file.
	 *
	 * What makes the archive restorable is the closure rather than the commits: every anchor a commit
	 * resolves through, every frame those anchors and segments name, every delta parent up each
	 * chain, and every dictionary any of those frames needs. An archive missing one of them unpacks
	 * cleanly and restores nothing, so anything unreadable is recorded as a problem rather than
	 * skipped.
	 *
	 * @param string $path
	 *   Where to write. A ".gz" or ".tgz" suffix compresses the archive.
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return PropertyList
	 *   What was written.
	 */
	#[CLI\Command(name: 'strata:export', aliases: ['strata-export'])]
	#[
		CLI\Argument(
			name: 'path',
			description: 'Archive file to write. A .gz or .tgz suffix compresses it.',
		),
	]
	#[
		CLI\Option(
			name: 'from',
			description: 'Commit to walk back from. Defaults to the current head.',
		),
	]
	#[CLI\Option(name: 'limit', description: 'Commits to include; 0 for every commit in history.')]
	#[
		CLI\Usage(
			name: 'drush strata:export /tmp/strata.tgz',
			description: 'Export the most recent history to a compressed archive.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:export /tmp/strata.tar --from=4f2a1b --limit=0',
			description: 'Export everything reachable from one commit.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'path' => 'Path',
				'site' => 'Site',
				'head' => 'Head',
				'commits' => 'Commits',
				'frames' => 'Frames',
				'dictionaries' => 'Dictionaries',
				'objects' => 'Objects',
				'bytes' => 'Bytes',
				'complete' => 'Complete',
				'problems' => 'Problems',
			],
		),
	]
	#[CLI\Format(listDelimiter: ':', tableStyle: 'compact')]
	public function export(
		string $path,
		array $options = ['from' => self::REQ, 'limit' => self::REQ],
	): PropertyList {
		$limit = self::value($options, 'limit');
		$manifest = $this->exporter()->export(
			$path,
			self::value($options, 'from'),
			$limit === null ? null : max(0, (int) $limit),
		);

		$this->announce($manifest->isComplete(), $manifest->summary());
		$this->printProblems($manifest);

		return $this->manifestList($path, $manifest);
	}

	/**
	 * Loads an archive's objects into this site's store.
	 *
	 * Every content-addressed object is checked against the digest it is filed under before it is
	 * written, so an archive that was edited or truncated is caught here rather than at the moment
	 * somebody needs the restore. The ref is never advanced and the local indexes are not rebuilt;
	 * `strata:reindex` does that from the objects an import has just put in place.
	 *
	 * @param string $path
	 *   The archive to read.
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return PropertyList
	 *   What was written, or what would be.
	 */
	#[CLI\Command(name: 'strata:import', aliases: ['strata-import'])]
	#[CLI\Argument(name: 'path', description: 'Archive file to read.')]
	#[CLI\Option(name: 'dry-run', description: 'Report what the archive holds and write nothing.')]
	#[
		CLI\Usage(
			name: 'drush strata:import /tmp/strata.tgz --dry-run',
			description: 'Report what an archive holds without writing any of it.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:import /tmp/strata.tgz -y',
			description: 'Write the archive into this store, then run strata:reindex.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'path' => 'Path',
				'site' => 'Site',
				'head' => 'Head',
				'commits' => 'Commits',
				'frames' => 'Frames',
				'dictionaries' => 'Dictionaries',
				'objects' => 'Objects',
				'bytes' => 'Bytes',
				'complete' => 'Complete',
				'problems' => 'Problems',
			],
		),
	]
	#[CLI\Format(listDelimiter: ':', tableStyle: 'compact')]
	public function import(string $path, array $options = ['dry-run' => false]): PropertyList
	{
		$manifest = $this->importer()->inspect($path);

		$this->prose()->text(
			sprintf(
				'%s was exported from site %s: %s',
				$path,
				$manifest->site,
				$manifest->summary(),
			),
		);
		$this->printProblems($manifest);

		if (self::flag($options, 'dry-run')) {
			return $this->manifestList($path, $this->importer()->import($path, false));
		}
		if (
			!$this->agreed(
				sprintf('Write %d objects from %s into this store?', $manifest->count(), $path),
			)
		) {
			$this->prose()->warning('Nothing was imported.');

			return $this->manifestList($path, $manifest);
		}

		$written = $this->importer()->import($path, true);

		$this->announce($written->isComplete(), $written->summary());
		$this->printProblems($written);
		$this->prose()->text('Run drush strata:reindex to index what was written.');

		return $this->manifestList($path, $written);
	}

	#endregion

	#region Plans

	/**
	 * Prints a plan, asks about it, and applies it.
	 *
	 * The plan handed to the restore is the one that was printed, so a subject that changed between
	 * the two is caught as a conflict rather than written silently.
	 *
	 * @param RestorePlan $plan
	 *   The plan.
	 * @param string $scope
	 *   What the restore was scoped to, as the operator expressed it.
	 * @param bool $dryRun
	 *   TRUE to stop after printing.
	 *
	 * @return PropertyList
	 *   What was written, or what would be.
	 */
	private function applyPlan(RestorePlan $plan, string $scope, bool $dryRun): PropertyList
	{
		$restore = $this->engine->logicalRestore();

		$this->printPlan($plan, $this->engine->preflight()->concurrentChanges($plan));

		if ($plan->problems !== [] || $plan->isEmpty() || $dryRun) {
			return $this->resultList($plan, $restore->apply($plan, $scope, false));
		}
		if (
			!$this->agreed(
				sprintf(
					'Write %d subjects over the live site as they were at %s?',
					count($plan->writable()),
					self::digest($plan->target),
				),
			)
		) {
			$this->prose()->warning('Nothing was written.');

			return $this->resultList($plan, $restore->apply($plan, $scope, false));
		}

		$result = $restore->apply($plan, $scope, true);

		$this->announce(!$result->wasRefused(), $result->summary());

		return $this->resultList($plan, $result);
	}

	/**
	 * Prints a plan in full, before anything acts on it.
	 *
	 * @param RestorePlan $plan
	 *   The plan.
	 * @param array<string, Conflict> $conflicts
	 *   Subjects changed since the plan was built.
	 */
	private function printPlan(RestorePlan $plan, array $conflicts): void
	{
		$counts = $plan->counts();

		$this->prose()->text($plan->summary());
		$this->prose()->table(
			['Status', 'Subjects'],
			[
				['restorable', (string) $counts[SubjectStatus::RESTORABLE->value]],
				['degraded', (string) $counts[SubjectStatus::DEGRADED->value]],
				['unrestorable', (string) $counts[SubjectStatus::UNRESTORABLE->value]],
			],
		);

		if ($plan->problems !== []) {
			$this->prose()->error('This plan cannot be applied:');
			$this->prose()->listing($plan->problems);
		}
		if ($conflicts !== []) {
			$this->prose()->warning(
				sprintf('%d subjects changed since the plan was built:', count($conflicts)),
			);
			$this->prose()->listing(
				array_map(
					static fn(Conflict $c): string => $c->describe(),
					array_values($conflicts),
				),
			);
		}
	}

	/**
	 * Turns a plan and its outcome into the command's result.
	 *
	 * @param RestorePlan $plan
	 *   The plan.
	 * @param RestoreResult $result
	 *   What the restore did.
	 *
	 * @return PropertyList
	 *   The result.
	 */
	private function resultList(RestorePlan $plan, RestoreResult $result): PropertyList
	{
		$counts = $plan->counts();

		return new PropertyList([
			'outcome' => $result->outcome(),
			'target' => self::digest($plan->target),
			'snapshot' => self::digest($result->snapshot),
			'restorable' => $counts[SubjectStatus::RESTORABLE->value],
			'degraded' => $counts[SubjectStatus::DEGRADED->value],
			'unrestorable' => $counts[SubjectStatus::UNRESTORABLE->value],
			'restored' => count($result->restored),
			'skipped' => count($result->skipped),
			'failed' => count($result->failed),
			'refused' => (string) ($result->refused ?? '-'),
		]);
	}

	#endregion

	#region Merges

	/**
	 * Turns a branch listing into the command's rows.
	 *
	 * @param array<string, Branch> $branches
	 *   Branch name keyed to the branch.
	 *
	 * @return array<string, array<string, string>>
	 *   Branch name keyed to its row.
	 */
	private function branchRows(array $branches): array
	{
		$rows = [];

		foreach ($branches as $branch) {
			$rows[$branch->name] = [
				'name' => $branch->name,
				'ref' => $branch->ref(),
				'forked-from' => self::digest($branch->forkedFrom),
				'tip' => self::digest($branch->tip),
				'moved' => self::yesNo(!$branch->isUnchanged()),
				'created' => self::moment($branch->createdAt),
				'actor' => $branch->actor === null ? '-' : (string) $branch->actor,
			];
		}

		return $rows;
	}

	/**
	 * Prints a merge manifest in full, before anything acts on it.
	 *
	 * @param MergePlan $plan
	 *   The plan.
	 */
	private function printMerge(MergePlan $plan): void
	{
		$this->prose()->text($plan->summary());
		$this->prose()->text(sprintf('Conflicts: %s.', $plan->strategy->describe()));

		$rows = [];

		foreach ($plan->entries as $entry) {
			$rows[] = [
				$entry->name,
				$entry->outcome->value,
				self::yesNo($entry->isWritten()),
				$entry->describe(),
			];
		}

		if ($rows !== []) {
			$this->prose()->table(['Object', 'Outcome', 'Written', 'Detail'], $rows);
		}

		foreach ($plan->unresolved() as $entry) {
			$this->printConflict($entry);
		}

		if ($plan->problems !== []) {
			$this->prose()->error('This merge cannot be applied:');
			$this->prose()->listing($plan->problems);
		}
	}

	/**
	 * Prints both values of every key one object disagrees about.
	 *
	 * @param MergeEntry $entry
	 *   The conflicting entry.
	 */
	private function printConflict(MergeEntry $entry): void
	{
		$rows = [];

		foreach ($entry->conflicts as $path => $pair) {
			$rows[] = [
				$path === '' ? '(the whole object)' : (string) $path,
				$this->render($pair['ours']),
				$this->render($pair['theirs']),
			];
		}

		$this->prose()->warning(sprintf('%s: both sides changed the same keys', $entry->name));
		$this->prose()->table(['Key', 'On the Trunk', 'On the Branch'], $rows);
	}

	/**
	 * Turns a merge outcome into the command's result.
	 *
	 * @param MergeResult $result
	 *   What the merge did.
	 *
	 * @return PropertyList
	 *   The result.
	 */
	private function mergeList(MergeResult $result): PropertyList
	{
		$plan = $result->plan;
		$counts = $plan->counts();

		return new PropertyList([
			'branch' => $plan->branch,
			'target' => $plan->target,
			'base' => self::digest($plan->base),
			'strategy' => $plan->strategy->value,
			'applied' => self::yesNo($result->applied),
			'written' => count($result->written),
			'conflicts' => $counts[MergeOutcome::CONFLICT->value],
			'unchanged' => $counts[MergeOutcome::UNCHANGED->value],
			'snapshot' => self::digest($result->snapshot),
			'commit' => self::digest($result->commit),
			'failed' => count($result->failed),
			'refused' => (string) ($result->refused ?? '-'),
		]);
	}

	#endregion

	#region Helpers

	/**
	 * The field-level difference for one subject between two commits.
	 *
	 * @param string $left
	 *   The commit to compare.
	 * @param string $right
	 *   The commit to compare it against.
	 * @param string $subject
	 *   Subject path.
	 * @param int $limit
	 *   Most fields to report.
	 *
	 * @return array<string, array<string, string>>
	 *   Field name keyed to its row.
	 */
	private function fieldDiff(string $left, string $right, string $subject, int $limit): array
	{
		$replayer = $this->engine->replayer();
		$before = $replayer->materialize($subject, $left);
		$after = $replayer->materialize($subject, $right);
		$fields = array_unique([...array_keys($before->fields), ...array_keys($after->fields)]);
		$rows = [];

		sort($fields, SORT_STRING);

		foreach ($fields as $field) {
			if (count($rows) >= $limit) {
				break;
			}

			$name = (string) $field;
			$was = $this->render($before->fields[$name] ?? null);
			$now = $this->render($after->fields[$name] ?? null);

			if ($was === $now) {
				continue;
			}

			$rows[$name] = [
				'change' => 'changed',
				'subject' => $name,
				'left' => $was,
				'right' => $now,
			];
		}

		return $rows;
	}

	/**
	 * Renders a field value for a table cell.
	 *
	 * @param mixed $value
	 *   The value, or NULL when the field was absent.
	 *
	 * @return string
	 *   A single-line rendering, truncated to keep the table readable.
	 */
	private function render(mixed $value): string
	{
		if ($value === null) {
			return '-';
		}

		$rendered = is_scalar($value)
			? (string) $value
			: (string) json_encode($value, JSON_UNESCAPED_SLASHES);
		$rendered = (string) preg_replace('/\s+/', ' ', $rendered);

		return strlen($rendered) > 80 ? substr($rendered, 0, 77) . '...' : $rendered;
	}

	/**
	 * Every finding code sitting above the ceiling an unattended run may reach.
	 *
	 * @return array<string, array<string, string|int>>
	 *   Code keyed to its row.
	 */
	private function heldCodes(): array
	{
		$counts = [];

		foreach ($this->ledger->open() as $finding) {
			$counts[$finding->code] = ($counts[$finding->code] ?? 0) + 1;
		}

		$rows = [];

		foreach (array_keys($counts) as $code) {
			$rung = $this->ledger->rungFor((string) $code);

			if (RepairLadder::isAutomatic($rung)) {
				continue;
			}

			$rows[(string) $code] = [
				'code' => (string) $code,
				'rung' => $rung,
				'findings' => $counts[$code],
			];
		}

		return $rows;
	}

	/**
	 * The rung a quarantine command is asking for.
	 *
	 * @param string $from
	 *   The rung the code sits at now.
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return string
	 *   The rung to move to.
	 */
	private function targetRung(string $from, array $options): string
	{
		if (self::flag($options, 'release')) {
			return RepairLadder::decay($from) ?? RepairLadder::RUNGS[0];
		}

		return self::flag($options, 'refuse') ? 'refuse' : 'quarantine';
	}

	/**
	 * Prints whatever an archive could not account for.
	 *
	 * @param ArchiveManifest $manifest
	 *   The manifest.
	 */
	private function printProblems(ArchiveManifest $manifest): void
	{
		if ($manifest->problems === []) {
			return;
		}

		$this->prose()->warning('This archive is not self-contained:');
		$this->prose()->listing($manifest->problems);
	}

	/**
	 * Turns a manifest into the command's result.
	 *
	 * @param string $path
	 *   The archive path.
	 * @param ArchiveManifest $manifest
	 *   The manifest.
	 *
	 * @return PropertyList
	 *   The result.
	 */
	private function manifestList(string $path, ArchiveManifest $manifest): PropertyList
	{
		return new PropertyList([
			'path' => $path,
			'site' => $manifest->site,
			'head' => self::digest($manifest->head),
			'commits' => count($manifest->commits),
			'frames' => count($manifest->frames),
			'dictionaries' => count($manifest->dictionaries),
			'objects' => $manifest->count(),
			'bytes' => self::bytes($manifest->bytes),
			'complete' => self::yesNo($manifest->isComplete()),
			'problems' => count($manifest->problems),
		]);
	}

	#endregion
}
