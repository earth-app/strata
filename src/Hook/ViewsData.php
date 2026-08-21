<?php

declare(strict_types=1);

namespace Drupal\strata\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\strata\Cas\DatabaseFrameIndex;
use Drupal\strata\Capture\Classifier\ClassificationRegistry;
use Drupal\strata\Codec\Dictionary\DictionaryStore;
use Drupal\strata\Health\DatabaseHealthLedger;
use Drupal\strata\Journal\DatabaseJournal;
use Drupal\strata\Restore\RestoreAudit;
use Drupal\strata\Tree\CommitIndex;
use Drupal\strata\Tree\SubjectIndex;

/**
 * Exposes every table Strata writes to Views.
 *
 * The reason to do this rather than build bespoke report pages: an operator's questions about a backup
 * are not knowable in advance. "Which commits did this user cause last Tuesday", "which frames have one
 * reference and are over a megabyte", "which restores were refused and why" - each of those is a view
 * somebody can build in a minute and none of them is worth a controller.
 *
 * **Relationships are declared where they are real.** A commit's parent is a commit, an operation
 * belongs to a commit, a frame belongs to a pack. Declaring those lets a view join them; not declaring
 * them would leave an operator with ten unrelated tables and no way to ask a question that spans two.
 *
 * **The payload columns are exposed but not rendered by default.** A journal payload is the captured
 * value itself, which is exactly the thing `view strata payloads` gates, so a view that shows it needs
 * that permission and the default views do not include it.
 *
 * @see ClassificationRegistry
 * @see RestoreAudit
 */
final class ViewsData
{
	use StringTranslationTrait;

	/**
	 * Declares Strata's tables and how they join.
	 *
	 * @return array<string, mixed>
	 *   Views data, keyed by table name.
	 */
	#[Hook('views_data')]
	public function tables(): array
	{
		return [
			DatabaseJournal::TABLE => $this->journal(),
			CommitIndex::TABLE => $this->commits(),
			SubjectIndex::TABLE => $this->subjects(),
			DatabaseFrameIndex::TABLE => $this->frames(),
			DictionaryStore::TABLE => $this->dictionaries(),
			RestoreAudit::TABLE => $this->restores(),
			DatabaseHealthLedger::TABLE => $this->health(),
			ClassificationRegistry::TABLE => $this->classifications(),
		];
	}

	#region Tables

	/**
	 * The journal: operations captured and not yet sealed.
	 *
	 * @return array<string, mixed>
	 *   Views data for the table.
	 */
	private function journal(): array
	{
		$data = $this->table(
			DatabaseJournal::TABLE,
			$this->t('Strata journal'),
			$this->t('Operations captured on this site and not yet sealed into a segment.'),
			'sequence',
		);

		$data['sequence'] = $this->numeric($this->t('Sequence'), $this->t('Capture order.'));
		$data['microtime'] = $this->microtime($this->t('Captured'));
		$data['realm'] = $this->realm();
		$data['subject'] = $this->text(
			$this->t('Subject'),
			$this->t('What was written, such as "node:42".'),
		);
		$data['verb'] = $this->verb();
		$data['actor'] = $this->actor();
		$data['request_id'] = $this->text(
			$this->t('Request'),
			$this->t('Groups every operation captured in one request.'),
		);
		$data['label'] = $this->text(
			$this->t('Label'),
			$this->t('Human summary of the operation.'),
		);
		$data['payload_length'] = $this->bytes(
			$this->t('Payload size'),
			$this->t('Decoded bytes the operation captured.'),
		);
		$data['payload_hash'] = $this->digest($this->t('Payload address'));
		$data['parent_hash'] = $this->digest($this->t('Previous version'));

		return $data;
	}

	/**
	 * The commit index: one row per point in history.
	 *
	 * @return array<string, mixed>
	 *   Views data for the table.
	 */
	private function commits(): array
	{
		$data = $this->table(
			CommitIndex::TABLE,
			$this->t('Strata commits'),
			$this->t("Points in the site's history a restore can target."),
			'id',
		);

		$data['id'] = $this->commitId($this->t('Commit'));
		$data['parent'] = $this->commitId($this->t('Parent commit'));
		$data['index_ref'] = $this->digest($this->t('Anchor'));
		$data['microtime'] = $this->microtime($this->t('Sealed'));
		$data['label'] = $this->text($this->t('Label'), $this->t('What the commit covers.'));
		$data['actor'] = $this->actor();
		$data['operations'] = $this->numeric(
			$this->t('Operations'),
			$this->t('How many operations the commit covers.'),
		);
		$data['raw_bytes'] = $this->bytes(
			$this->t('Raw size'),
			$this->t('Decoded bytes the operations describe.'),
		);
		$data['stored_bytes'] = $this->bytes(
			$this->t('Stored size'),
			$this->t('Bytes written after deduplication, compression and sealing.'),
		);
		$data['level'] = $this->numeric(
			$this->t('Compaction level'),
			$this->t('Zero for a freshly flushed commit, higher after a rollup.'),
		);
		$data['is_base'] = $this->boolean(
			$this->t('Anchors a replay'),
			$this->t('Whether this commit wrote the anchor it names.'),
		);
		$data['chain'] = $this->numeric(
			$this->t('Anchor chain'),
			$this->t("Anchors between this commit's anchor and the full one behind it."),
		);
		$data['anchored_at'] = $this->microtime($this->t('Anchored'));
		$data['segment_key'] = $this->text(
			$this->t('Segment'),
			$this->t('Object key of the segment this commit sealed.'),
		);

		// a commit's parent is a commit, which is what makes a history view possible
		$data['parent_commit'] = [
			'title' => $this->t('Parent commit'),
			'help' => $this->t('The commit this one builds on.'),
			'relationship' => [
				'base' => CommitIndex::TABLE,
				'base field' => 'id',
				'field' => 'parent',
				'id' => 'standard',
				'label' => $this->t('Parent commit'),
			],
		];

		return $data;
	}

	/**
	 * The subject index: where each subject was last stored.
	 *
	 * @return array<string, mixed>
	 *   Views data for the table.
	 */
	private function subjects(): array
	{
		$data = $this->table(
			SubjectIndex::TABLE,
			$this->t('Strata subjects'),
			$this->t('Every subject the history covers, and where its newest version is stored.'),
			'subject',
		);

		$data['subject'] = $this->text(
			$this->t('Subject'),
			$this->t('Realm-qualified path, such as "entity/node:42".'),
		);
		$data['size'] = $this->bytes(
			$this->t('Size'),
			$this->t('Decoded bytes the subject occupies.'),
		);
		$data['deleted'] = $this->boolean(
			$this->t('Deleted'),
			$this->t('Whether the subject was removed rather than written.'),
		);
		$data['updated'] = $this->microtime($this->t('Last written'));

		return $data;
	}

	/**
	 * The frame index: one row per stored frame.
	 *
	 * @return array<string, mixed>
	 *   Views data for the table.
	 */
	private function frames(): array
	{
		$data = $this->table(
			DatabaseFrameIndex::TABLE,
			$this->t('Strata frames'),
			$this->t('Content-addressed frames, and what each one cost to store.'),
			'hash',
		);

		$data['hash'] = $this->digest($this->t('Address'));
		$data['raw_size'] = $this->bytes(
			$this->t('Raw size'),
			$this->t('Bytes the frame decodes to.'),
		);
		$data['stored_size'] = $this->bytes(
			$this->t('Stored size'),
			$this->t('Bytes on the wire, after compression and sealing.'),
		);
		$data['codec'] = $this->text($this->t('Codec'), $this->t('What compressed the frame.'));
		$data['cipher'] = $this->text($this->t('Cipher'), $this->t('What sealed the frame.'));
		$data['dictionary'] = $this->text(
			$this->t('Dictionary'),
			$this->t('Which dictionary version the frame needs to decode.'),
		);
		$data['pack'] = $this->text(
			$this->t('Pack'),
			$this->t('The pack object holding the frame, empty when it stands alone.'),
		);
		$data['offset'] = $this->numeric(
			$this->t('Pack offset'),
			$this->t('Where in the pack the frame starts.'),
		);
		$data['refs'] = $this->numeric(
			$this->t('References'),
			$this->t('How many stored operations name the frame.'),
		);
		$data['delta_parent'] = $this->digest($this->t('Delta parent'));
		$data['delta_depth'] = $this->numeric(
			$this->t('Chain depth'),
			$this->t('How many frames have to be read to decode this one.'),
		);
		$data['created'] = [
			'title' => $this->t('Stored'),
			'help' => $this->t('When the frame was written.'),
			'field' => ['id' => 'date'],
			'sort' => ['id' => 'date'],
			'filter' => ['id' => 'date'],
			'argument' => ['id' => 'date'],
		];

		return $data;
	}

	/**
	 * The dictionary index.
	 *
	 * @return array<string, mixed>
	 *   Views data for the table.
	 */
	private function dictionaries(): array
	{
		$data = $this->table(
			DictionaryStore::TABLE,
			$this->t('Strata dictionaries'),
			$this->t('Per-realm compression dictionaries, and the ratio each one measured.'),
			'id',
		);

		$data['id'] = $this->text($this->t('Dictionary'), $this->t('Realm and version.'));
		$data['realm'] = $this->realm();
		$data['version'] = $this->numeric($this->t('Version'), $this->t('Counting from one.'));
		$data['address'] = $this->digest($this->t('Address'));
		$data['bytes'] = $this->bytes($this->t('Size'), $this->t('How large the dictionary is.'));
		$data['source'] = $this->text(
			$this->t('Source'),
			$this->t('Whether it was concatenated from samples or built by the zstd trainer.'),
		);
		$data['ratio'] = $this->numeric(
			$this->t('Measured ratio'),
			$this->t('Compression the dictionary achieved on the samples it was scored against.'),
		);
		$data['samples'] = $this->numeric(
			$this->t('Samples'),
			$this->t('How many payloads it was built from.'),
		);
		$data['trained_at'] = [
			'title' => $this->t('Trained'),
			'help' => $this->t('When the dictionary was built.'),
			'field' => ['id' => 'date'],
			'sort' => ['id' => 'date'],
			'filter' => ['id' => 'date'],
		];

		return $data;
	}

	/**
	 * The restore audit.
	 *
	 * @return array<string, mixed>
	 *   Views data for the table.
	 */
	private function restores(): array
	{
		$data = $this->table(
			RestoreAudit::TABLE,
			$this->t('Strata restores'),
			$this->t('Every restore attempted on this site, including the refused ones.'),
			'id',
		);

		$data['id'] = $this->numeric($this->t('Restore'), $this->t('Audit row id.'));
		$data['target'] = $this->commitId($this->t('Target commit'));
		$data['scope'] = $this->text($this->t('Scope'), $this->t('What the restore covered.'));
		$data['mode'] = $this->text($this->t('Mode'), $this->t('Logical or physical.'));
		$data['actor'] = $this->actor();
		$data['started'] = [
			'title' => $this->t('Started'),
			'help' => $this->t('When the restore began.'),
			'field' => ['id' => 'date'],
			'sort' => ['id' => 'date'],
			'filter' => ['id' => 'date'],
		];
		$data['finished'] = [
			'title' => $this->t('Finished'),
			'help' => $this->t('When it ended, empty while it is still running.'),
			'field' => ['id' => 'date'],
			'sort' => ['id' => 'date'],
			'filter' => ['id' => 'date'],
		];
		$data['outcome'] = $this->text($this->t('Outcome'), $this->t('What happened.'));
		$data['restored'] = $this->numeric(
			$this->t('Restored'),
			$this->t('How many subjects were written.'),
		);
		$data['skipped'] = $this->numeric(
			$this->t('Skipped'),
			$this->t('How many were left alone, and there is always a reason.'),
		);
		$data['detail'] = $this->text(
			$this->t('Detail'),
			$this->t('What was skipped, what failed, and why.'),
		);
		$data['pre_snapshot'] = $this->commitId($this->t('Undo commit'));

		// a restore targets a commit, so a view can show what it was going back to
		$data['target_commit'] = [
			'title' => $this->t('Target commit'),
			'help' => $this->t('The commit the restore went back to.'),
			'relationship' => [
				'base' => CommitIndex::TABLE,
				'base field' => 'id',
				'field' => 'target',
				'id' => 'standard',
				'label' => $this->t('Target commit'),
			],
		];

		return $data;
	}

	/**
	 * The health ledger.
	 *
	 * @return array<string, mixed>
	 *   Views data for the table.
	 */
	private function health(): array
	{
		$data = $this->table(
			DatabaseHealthLedger::TABLE,
			$this->t('Strata health'),
			$this->t('What the tripwires found, and how far up the repair ladder each finding is.'),
			'id',
		);

		$data['id'] = $this->numeric($this->t('Finding'), $this->t('Ledger row id.'));
		$data['code'] = $this->text(
			$this->t('Code'),
			$this->t('Stable identifier for the symptom, such as "frame.missing".'),
		);
		$data['severity'] = $this->severity();
		$data['scope'] = $this->text($this->t('Scope'), $this->t('What the finding is about.'));
		$data['context'] = $this->text($this->t('Detail'), $this->t('What was observed.'));
		$data['rung'] = $this->text(
			$this->t('Repair rung'),
			$this->t('How far up the ladder this finding has escalated.'),
		);
		$data['created'] = [
			'title' => $this->t('Recorded'),
			'help' => $this->t('When the symptom was recorded.'),
			'field' => ['id' => 'date'],
			'sort' => ['id' => 'date'],
			'filter' => ['id' => 'date'],
		];
		$data['resolved'] = [
			'title' => $this->t('Resolved'),
			'help' => $this->t('When it stopped being observed, empty while it is open.'),
			'field' => ['id' => 'date'],
			'sort' => ['id' => 'date'],
			'filter' => ['id' => 'date'],
		];

		return $data;
	}

	/**
	 * The classification registry.
	 *
	 * @return array<string, mixed>
	 *   Views data for the table.
	 */
	private function classifications(): array
	{
		$data = $this->table(
			ClassificationRegistry::TABLE,
			$this->t('Strata classifications'),
			$this->t('What each part of the ephemeral keyspace has been decided to be.'),
			'pattern',
		);

		$data['pattern'] = $this->text(
			$this->t('Pattern'),
			$this->t('The key pattern this decision covers.'),
		);
		$data['classification'] = $this->classification();
		$data['source'] = $this->text(
			$this->t('Decided by'),
			$this->t('Whether a rule or a person decided it.'),
		);
		$data['keys_seen'] = $this->numeric(
			$this->t('Keys'),
			$this->t('How many keys the last discovery pass found under the pattern.'),
		);
		$data['bytes_seen'] = $this->bytes(
			$this->t('Bytes'),
			$this->t('How many bytes those keys held.'),
		);
		$data['decided'] = [
			'title' => $this->t('Decided'),
			'help' => $this->t('When the classification was last written.'),
			'field' => ['id' => 'date'],
			'sort' => ['id' => 'date'],
			'filter' => ['id' => 'date'],
		];

		return $data;
	}

	#endregion

	#region Column Shapes

	/**
	 * The table declaration every one of these shares.
	 *
	 * @param string $table
	 *   The table name.
	 * @param string|object $title
	 *   Human title.
	 * @param string|object $help
	 *   What the table holds.
	 * @param string $field
	 *   The column a view uses as its base field.
	 *
	 * @return array<string, mixed>
	 *   The opening of a views data array.
	 */
	private function table(
		string $table,
		string|object $title,
		string|object $help,
		string $field,
	): array {
		return [
			'table' => [
				'group' => $this->t('Strata'),
				'provider' => 'strata',
				'base' => [
					'field' => $field,
					'title' => $title,
					'help' => $help,
				],
			],
		];
	}

	/**
	 * A plain string column.
	 *
	 * @param string|object $title
	 *   Human title.
	 * @param string|object $help
	 *   What the column holds.
	 *
	 * @return array<string, mixed>
	 *   The column declaration.
	 */
	private function text(string|object $title, string|object $help): array
	{
		return [
			'title' => $title,
			'help' => $help,
			'field' => ['id' => 'standard'],
			'sort' => ['id' => 'standard'],
			'filter' => ['id' => 'string'],
			'argument' => ['id' => 'string'],
		];
	}

	/**
	 * A plain number column.
	 *
	 * @param string|object $title
	 *   Human title.
	 * @param string|object $help
	 *   What the column holds.
	 *
	 * @return array<string, mixed>
	 *   The column declaration.
	 */
	private function numeric(string|object $title, string|object $help): array
	{
		return [
			'title' => $title,
			'help' => $help,
			'field' => ['id' => 'numeric'],
			'sort' => ['id' => 'standard'],
			'filter' => ['id' => 'numeric'],
			'argument' => ['id' => 'numeric'],
		];
	}

	/**
	 * A byte count, rendered in the largest unit that stays readable.
	 *
	 * @param string|object $title
	 *   Human title.
	 * @param string|object $help
	 *   What the column holds.
	 *
	 * @return array<string, mixed>
	 *   The column declaration.
	 */
	private function bytes(string|object $title, string|object $help): array
	{
		return [
			'title' => $title,
			'help' => $help,
			'field' => ['id' => 'strata_bytes'],
			'sort' => ['id' => 'standard'],
			'filter' => ['id' => 'numeric'],
		];
	}

	/**
	 * A boolean column.
	 *
	 * @param string|object $title
	 *   Human title.
	 * @param string|object $help
	 *   What the column holds.
	 *
	 * @return array<string, mixed>
	 *   The column declaration.
	 */
	private function boolean(string|object $title, string|object $help): array
	{
		return [
			'title' => $title,
			'help' => $help,
			'field' => ['id' => 'boolean'],
			'sort' => ['id' => 'standard'],
			'filter' => ['id' => 'boolean', 'label' => $title, 'type' => 'yes-no'],
		];
	}

	/**
	 * A content address, shown abbreviated.
	 *
	 * @param string|object $title
	 *   Human title.
	 *
	 * @return array<string, mixed>
	 *   The column declaration.
	 */
	private function digest(string|object $title): array
	{
		return [
			'title' => $title,
			'help' => $this->t('A content address. Shown shortened; the full digest is the value.'),
			'field' => ['id' => 'strata_digest'],
			'sort' => ['id' => 'standard'],
			'filter' => ['id' => 'string'],
			'argument' => ['id' => 'string'],
		];
	}

	/**
	 * A commit id, which links to the commit.
	 *
	 * @param string|object $title
	 *   Human title.
	 *
	 * @return array<string, mixed>
	 *   The column declaration.
	 */
	private function commitId(string|object $title): array
	{
		return [
			'title' => $title,
			'help' => $this->t('A commit address, linked to what it covers.'),
			'field' => ['id' => 'strata_commit'],
			'sort' => ['id' => 'standard'],
			'filter' => ['id' => 'string'],
			'argument' => ['id' => 'string'],
		];
	}

	/**
	 * Unix microseconds, rendered as a date.
	 *
	 * @param string|object $title
	 *   Human title.
	 *
	 * @return array<string, mixed>
	 *   The column declaration.
	 */
	private function microtime(string|object $title): array
	{
		return [
			'title' => $title,
			'help' => $this->t('Unix microseconds, so two writes in the same second still order.'),
			'field' => ['id' => 'strata_microtime'],
			'sort' => ['id' => 'standard'],
			'filter' => ['id' => 'numeric'],
		];
	}

	/**
	 * A user id, joined to the user entity so a view can show the account.
	 *
	 * @return array<string, mixed>
	 *   The column declaration.
	 */
	private function actor(): array
	{
		return [
			'title' => $this->t('Actor'),
			'help' => $this->t('Who caused it, empty for cron and other unattended work.'),
			// the uid itself; the relationship is what a view adds to show the account behind it
			'field' => ['id' => 'numeric'],
			'sort' => ['id' => 'standard'],
			'filter' => ['id' => 'numeric'],
			'argument' => ['id' => 'user_uid'],
			'relationship' => [
				'base' => 'users_field_data',
				'base field' => 'uid',
				'id' => 'standard',
				'label' => $this->t('Actor'),
			],
		];
	}

	/**
	 * The realm column, filtered by the realms that exist.
	 *
	 * @return array<string, mixed>
	 *   The column declaration.
	 */
	private function realm(): array
	{
		return [
			'title' => $this->t('Realm'),
			'help' => $this->t('Which kind of thing was written.'),
			'field' => ['id' => 'strata_realm'],
			'sort' => ['id' => 'standard'],
			'filter' => ['id' => 'strata_realm'],
			'argument' => ['id' => 'string'],
		];
	}

	/**
	 * The verb column, filtered by the verbs that exist.
	 *
	 * @return array<string, mixed>
	 *   The column declaration.
	 */
	private function verb(): array
	{
		return [
			'title' => $this->t('Verb'),
			'help' => $this->t('What kind of write it was.'),
			'field' => ['id' => 'strata_verb'],
			'sort' => ['id' => 'standard'],
			'filter' => ['id' => 'strata_verb'],
			'argument' => ['id' => 'string'],
		];
	}

	/**
	 * The severity column, filtered by the severities that exist.
	 *
	 * @return array<string, mixed>
	 *   The column declaration.
	 */
	private function severity(): array
	{
		return [
			'title' => $this->t('Severity'),
			'help' => $this->t('How serious the finding is.'),
			'field' => ['id' => 'strata_severity'],
			'sort' => ['id' => 'standard'],
			'filter' => ['id' => 'strata_severity'],
			'argument' => ['id' => 'string'],
		];
	}

	/**
	 * The classification column, filtered by the classifications that exist.
	 *
	 * @return array<string, mixed>
	 *   The column declaration.
	 */
	private function classification(): array
	{
		return [
			'title' => $this->t('Classification'),
			'help' => $this->t('Whether the keys are authoritative, derivable, or undecided.'),
			'field' => ['id' => 'strata_classification'],
			'sort' => ['id' => 'standard'],
			'filter' => ['id' => 'strata_classification'],
			'argument' => ['id' => 'string'],
		];
	}

	#endregion
}
