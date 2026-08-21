<?php

declare(strict_types=1);

namespace Drupal\strata\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\strata\Compaction\Compactor;
use Drupal\strata\Compaction\PruneReceipt;
use Drupal\strata\Engine;
use Drupal\strata\Tree\RefStore;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * The passes that make stored history cheaper, and the two that remove parts of it.
 *
 * Recompaction, re-anchoring, dictionary training and reindexing change no restore target: each
 * rewrites or rebuilds derived state, so the worst case of running one at the wrong moment is wasted
 * work. `strata:compact` therefore never prunes, whatever else it does.
 *
 * Pruning and collecting do remove restore targets, so both print their receipt in full and ask
 * before acting, and both go through `Compactor::prune()` rather than deleting objects themselves. A
 * pack survives while one frame in it is live, a candidate is dropped only when all three
 * reachability classes agree, and an incomplete walk refuses the whole pass; putting a second
 * deletion path beside that one would mean maintaining those rules twice.
 *
 * @see Compactor
 * @see PruneReceipt
 */
final class StrataMaintenanceCommands extends DrushCommands
{
	use AutowireTrait;
	use StrataOutputTrait;

	/**
	 * Constructs the command set.
	 *
	 * @param Engine $engine
	 *   Builds the compactor, the reachability set and the passes over them.
	 */
	public function __construct(private readonly Engine $engine)
	{
		parent::__construct();
	}

	#region Densifying

	/**
	 * Rewrites stored objects at a setting the flush path cannot afford.
	 *
	 * Folds each retention level into the one above, breaks delta chains that have grown past the
	 * cap, and recompresses packs. No content address changes and nothing is removed, so the pass is
	 * safe to schedule and safe to interrupt. Removing what history no longer needs is
	 * `strata:prune`.
	 *
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return PropertyList
	 *   What the pass did.
	 */
	#[CLI\Command(name: 'strata:compact', aliases: ['strata-compact'])]
	#[
		CLI\Option(
			name: 'budget',
			description: 'Most stored bytes to read while recompressing; 0 for no limit.',
		),
	]
	#[CLI\Usage(name: 'drush strata:compact', description: 'Recompress and fold the whole store.')]
	#[
		CLI\Usage(
			name: 'drush strata:compact --budget=104857600',
			description: 'Recompress at most 100 MiB, for a bounded cron window.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'packs' => 'Packs Rewritten',
				'frames' => 'Frames Rewritten',
				'before' => 'Bytes Before',
				'after' => 'Bytes After',
				'saved' => 'Bytes Saved',
				'gain' => 'Gain',
				'skipped' => 'Frames Skipped',
				'problems' => 'Problems',
				'seconds' => 'Took',
			],
		),
	]
	#[CLI\Format(listDelimiter: ':', tableStyle: 'compact')]
	public function compact(array $options = ['budget' => 0]): PropertyList
	{
		$report = $this->engine->compactor()->compact(max(0, self::number($options, 'budget', 0)));

		$this->announce($report->isClean(), $report->summary());

		foreach ($report->problems as $problem) {
			$this->prose()->warning($problem);
		}

		return new PropertyList([
			'packs' => $report->packs,
			'frames' => $report->frames,
			'before' => self::bytes($report->bytesBefore),
			'after' => self::bytes($report->bytesAfter),
			'saved' => self::bytes($report->saved()),
			'gain' => sprintf('%.1f%%', $report->gain() * 100),
			'skipped' => $report->skipped,
			'problems' => count($report->problems),
			'seconds' => self::duration($report->seconds),
		]);
	}

	/**
	 * Breaks delta chains that have grown past the configured cap.
	 *
	 * A chain past the cap still decodes; every frame in it has to be fetched to do so. Re-anchoring
	 * stores the deepest frames standalone again, trading stored bytes for a bounded restore cost.
	 *
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return PropertyList
	 *   What the pass did, or would do.
	 */
	#[CLI\Command(name: 'strata:reanchor', aliases: ['strata-reanchor'])]
	#[
		CLI\Option(
			name: 'budget',
			description: 'Most frames to rewrite; 0 for the built-in default.',
		),
	]
	#[
		CLI\Option(
			name: 'dry-run',
			description: 'Report the chains past the cap without rewriting any.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:reanchor',
			description: 'Re-anchor the chains that are too deep.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:reanchor --dry-run',
			description: 'Report how deep the chains have grown without rewriting anything.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'applied' => 'Applied',
				'examined' => 'Chains Examined',
				'reanchored' => 'Chains Re-anchored',
				'deepest' => 'Deepest Chain',
				'saved' => 'Bytes Saved',
				'problems' => 'Problems',
			],
		),
	]
	#[CLI\Format(listDelimiter: ':', tableStyle: 'compact')]
	public function reanchor(array $options = ['budget' => 0, 'dry-run' => false]): PropertyList
	{
		$reanchorer = $this->engine->reanchorer();
		$budget = max(0, self::number($options, 'budget', 0));

		if (self::flag($options, 'dry-run')) {
			$candidates = $reanchorer->overDeep($budget > 0 ? $budget : 200);

			$this->prose()->text(
				sprintf(
					'%d chains are past the cap; the deepest is %d frames',
					count($candidates),
					$reanchorer->deepest(),
				),
			);

			return new PropertyList([
				'applied' => self::yesNo(false),
				'examined' => count($candidates),
				'reanchored' => 0,
				'deepest' => $reanchorer->deepest(),
				'saved' => self::bytes(0),
				'problems' => 0,
			]);
		}

		$result = $reanchorer->run($budget);

		$this->announce(
			$result['problems'] === [],
			sprintf(
				're-anchored %d of %d chains past the cap; deepest was %d',
				$result['reanchored'],
				$result['examined'],
				$result['deepest'],
			),
		);

		foreach ($result['problems'] as $problem) {
			$this->prose()->warning($problem);
		}

		return new PropertyList([
			'applied' => self::yesNo(true),
			'examined' => $result['examined'],
			'reanchored' => $result['reanchored'],
			'deepest' => $result['deepest'],
			'saved' => self::bytes($result['saved']),
			'problems' => count($result['problems']),
		]);
	}

	/**
	 * Trains a compression dictionary per realm from what the store already holds.
	 *
	 * A dictionary is what makes small payloads compress at all: a 200-byte field delta has almost
	 * no internal redundancy, and the shared structure it does have lives in the other payloads from
	 * the same realm. Existing frames stay decodable, because each names the dictionary it used.
	 *
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return RowsOfFields
	 *   One row per realm, empty when there is no history to sample.
	 */
	#[CLI\Command(name: 'strata:train-dict', aliases: ['strata-train-dict'])]
	#[CLI\Option(name: 'ref', description: 'Ref to collect samples from.')]
	#[
		CLI\Option(
			name: 'realm',
			description: 'Train only this realm rather than every one with samples.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:train-dict',
			description: 'Train a dictionary for every realm with enough data.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:train-dict --realm=entity',
			description: 'Train only the entity realm, which is the bulk of the operations.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'realm' => 'Realm',
				'stored' => 'Stored',
				'ratio' => 'Ratio',
				'source' => 'Source',
				'reason' => 'Reason',
			],
		),
	]
	public function trainDict(
		array $options = ['ref' => RefStore::MAIN, 'realm' => self::REQ],
	): RowsOfFields {
		$pass = $this->engine->dictionaryPass();
		$ref = self::ref($options);
		$realm = self::value($options, 'realm') ?? '';

		if ($realm === '') {
			$results = $pass->run($ref);
		} else {
			$samples = $pass->collect($ref);
			$results = [$realm => $pass->trainRealm($realm, $samples[$realm] ?? [])];
		}

		$rows = [];

		foreach ($results as $name => $result) {
			$rows[(string) $name] = [
				'realm' => (string) $name,
				'stored' => self::yesNo($result['stored']),
				'ratio' => sprintf('%.2fx', $result['ratio']),
				'source' => $result['source'] === '' ? '-' : $result['source'],
				'reason' => $result['reason'],
			];
		}

		return new RowsOfFields($rows);
	}

	/**
	 * Rebuilds the local indexes from what the bucket holds.
	 *
	 * The commit index and the frame index are caches over the store, so a site restored from a
	 * database dump, or one that imported an archive, has objects the indexes do not know about. This
	 * reads the objects back and rewrites both tables from them. Nothing in the bucket is touched.
	 *
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return PropertyList
	 *   What was rebuilt.
	 */
	#[CLI\Command(name: 'strata:reindex', aliases: ['strata-reindex'])]
	#[
		CLI\Option(
			name: 'keep',
			description: 'Merge into the existing rows instead of clearing them first.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:reindex',
			description: 'Rebuild both local indexes from the bucket.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:reindex --keep',
			description: 'Add what the bucket holds without dropping rows the walk did not reach.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'commits' => 'Commit Rows',
				'frames' => 'Frame Rows',
				'packs' => 'Packs Read',
				'segments' => 'Segments Read',
				'references' => 'References Attributed',
				'skipped' => 'Objects Skipped',
				'seconds' => 'Took',
			],
		),
	]
	#[CLI\Format(listDelimiter: ':', tableStyle: 'compact')]
	public function reindex(array $options = ['keep' => false]): PropertyList
	{
		$report = $this->engine->reindexer()->reindex(!self::flag($options, 'keep'));

		$this->announce($report->isClean(), $report->summary());

		foreach ($report->problems as $problem) {
			$this->prose()->warning($problem);
		}

		return new PropertyList([
			'commits' => $report->commits,
			'frames' => $report->frames,
			'packs' => $report->packs,
			'segments' => $report->segments,
			'references' => $report->references,
			'skipped' => $report->skipped,
			'seconds' => self::duration($report->seconds),
		]);
	}

	#endregion

	#region Removing

	/**
	 * Removes what no reachable history needs, or explains why it would not.
	 *
	 * The receipt is printed before anything is deleted, and the numbers in a dry run are the same
	 * ones an applied run produces. Every candidate held back is named with its reason, so a pass
	 * that frees nothing says which frames kept it from doing so.
	 *
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return PropertyList
	 *   The receipt.
	 */
	#[CLI\Command(name: 'strata:prune', aliases: ['strata-prune'])]
	#[CLI\Option(name: 'dry-run', description: 'Produce the receipt and delete nothing.')]
	#[CLI\Option(name: 'limit', description: 'Most frames to consider in one pass.')]
	#[
		CLI\Usage(
			name: 'drush strata:prune --dry-run',
			description: 'Show what a prune would remove.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:prune -y',
			description: 'Remove what no reachable history needs, without being asked to confirm.',
		),
	]
	#[
		CLI\FieldLabels(
			labels: [
				'applied' => 'Applied',
				'refused' => 'Refused',
				'frames' => 'Frames Removed',
				'objects' => 'Objects Removed',
				'bytes' => 'Bytes Freed',
				'kept' => 'Candidates Held Back',
				'seconds' => 'Took',
			],
		),
	]
	#[CLI\Format(listDelimiter: ':', tableStyle: 'compact')]
	public function prune(array $options = ['dry-run' => false, 'limit' => 1000]): PropertyList
	{
		$compactor = $this->engine->compactor();
		$limit = max(1, self::number($options, 'limit', 1000));
		$receipt = $compactor->prune(false, $limit);

		$this->printReceipt($receipt);

		if ($receipt->wasRefused() || $receipt->isEmpty() || self::flag($options, 'dry-run')) {
			return $this->receiptList($receipt);
		}
		if (
			!$this->agreed(
				sprintf(
					'Delete %d objects and free %s? This removes restore targets permanently.',
					count($receipt->objects),
					self::bytes($receipt->bytes),
				),
			)
		) {
			$this->prose()->warning('Nothing was deleted.');

			return $this->receiptList($receipt);
		}

		$applied = $compactor->prune(true, $limit);

		$this->announce(!$applied->wasRefused(), $applied->summary());

		return $this->receiptList($applied);
	}

	/**
	 * Reports what the reachability walk reached, and removes the frames it did not.
	 *
	 * The walk's own totals are what answer "why is nothing collectable": a frame is held by a
	 * reachable commit, by a live frame that was compressed against its dictionary, or by something
	 * that decodes against it as a delta parent, and the counts say which class is doing the holding.
	 * The removal itself is the same prune pass, so the pack and dictionary rules apply unchanged.
	 *
	 * @param array<string, mixed> $options
	 *   Command options.
	 *
	 * @return RowsOfFields
	 *   One row per reachability class, plus what is collectable.
	 */
	#[CLI\Command(name: 'strata:gc', aliases: ['strata-gc'])]
	#[CLI\Option(name: 'dry-run', description: 'Report the walk and collect nothing.')]
	#[CLI\Option(name: 'limit', description: 'Most collectable frames to consider.')]
	#[
		CLI\Usage(
			name: 'drush strata:gc --dry-run',
			description: 'Report what the reachability walk reached.',
		),
	]
	#[
		CLI\Usage(
			name: 'drush strata:gc -y',
			description: 'Collect the frames the walk did not reach, without being asked to confirm.',
		),
	]
	#[CLI\FieldLabels(labels: ['what' => 'What', 'count' => 'Count', 'bytes' => 'Bytes'])]
	public function gc(array $options = ['dry-run' => false, 'limit' => 1000]): RowsOfFields
	{
		$reachability = $this->engine->reachability();
		$limit = max(1, self::number($options, 'limit', 1000));
		$statistics = $reachability->statistics();
		$collectable = $reachability->collectableFrames($limit);
		$freeable = 0;

		foreach ($collectable as $record) {
			$freeable += $record->storedSize;
		}

		$rows = [];

		foreach ($statistics as $what => $count) {
			$rows[(string) $what] = [
				'what' => sprintf('live %s', (string) $what),
				'count' => $count,
				'bytes' => '-',
			];
		}

		$rows['collectable'] = [
			'what' => 'collectable frames',
			'count' => count($collectable),
			'bytes' => self::bytes($freeable),
		];

		if (!$reachability->isComplete()) {
			$this->prose()->warning(
				sprintf(
					'The walk could not read %d objects, so a prune will refuse rather than guess.',
					count($reachability->unreadable()),
				),
			);

			return new RowsOfFields($rows);
		}
		if ($collectable === [] || self::flag($options, 'dry-run')) {
			return new RowsOfFields($rows);
		}
		if (
			!$this->agreed(
				sprintf(
					'Collect %d frames holding %s? This removes restore targets permanently.',
					count($collectable),
					self::bytes($freeable),
				),
			)
		) {
			$this->prose()->warning('Nothing was collected.');

			return new RowsOfFields($rows);
		}

		$receipt = $this->engine->compactor()->prune(true, $limit);

		$this->printReceipt($receipt);
		$this->announce(!$receipt->wasRefused(), $receipt->summary());

		$rows['collected'] = [
			'what' => 'collected',
			'count' => count($receipt->frames),
			'bytes' => self::bytes($receipt->bytes),
		];

		return new RowsOfFields($rows);
	}

	#endregion

	#region Receipts

	/**
	 * Prints a receipt in full, before anything acts on it.
	 *
	 * @param PruneReceipt $receipt
	 *   The receipt.
	 */
	private function printReceipt(PruneReceipt $receipt): void
	{
		$this->prose()->text($receipt->summary());

		if ($receipt->wasRefused()) {
			$this->prose()->error((string) $receipt->refused);
		}
		if ($receipt->kept !== []) {
			$this->prose()->text('Held back:');
			$this->prose()->listing($receipt->kept);
		}
	}

	/**
	 * Turns a receipt into the command's result.
	 *
	 * @param PruneReceipt $receipt
	 *   The receipt.
	 *
	 * @return PropertyList
	 *   The result.
	 */
	private function receiptList(PruneReceipt $receipt): PropertyList
	{
		return new PropertyList([
			'applied' => self::yesNo($receipt->applied),
			'refused' => (string) ($receipt->refused ?? '-'),
			'frames' => count($receipt->frames),
			'objects' => count($receipt->objects),
			'bytes' => self::bytes($receipt->bytes),
			'kept' => $receipt->keptCount(),
			'seconds' => self::duration($receipt->seconds),
		]);
	}

	#endregion
}
