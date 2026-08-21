<?php

declare(strict_types=1);

namespace Drupal\strata\Drill;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\strata\Capture\EntityDelta;
use Drupal\strata\Event\Notifier;
use Drupal\strata\Health\Finding;
use Drupal\strata\Health\HealthLedgerInterface;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Restore\ReplayResult;
use Drupal\strata\Restore\Replayer;
use Drupal\strata\Tree\RefStore;
use Drupal\strata\Tree\SubjectIndex;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Proves the store can reproduce what the site holds, on a schedule, without restoring anything.
 *
 * A backup nobody has restored is a hypothesis. This is what turns it into a measurement: for a
 * bounded sample of subjects, replay what the store says the subject was at the commit where it was
 * last captured, read what the site holds now, and compare. Nothing is written; a drill is a read of
 * both sides.
 *
 * **The comparison is only valid where the site has not moved on.** A subject edited after the
 * commit that was replayed will differ for a legitimate reason, and counting that as a failure would
 * make every busy site look broken. So a subject whose last capture is older than its current state
 * is SKIPPED, and the report keeps skipped separate from both pass and fail. A drill that could judge
 * nothing reports `inconclusive` rather than `pass`.
 *
 * **What a drill does not prove.** It reads the sample it took, so a fault confined to a subject
 * outside the sample survives it; it compares values rather than replaying a whole restore, so it
 * says nothing about whether writing those values back would succeed; and it cannot see a subject the
 * capture never recorded, which is what the reconciler exists for. What it does prove is that the
 * bytes in the bucket decode, resolve through their delta chains and dictionaries, and reconstruct
 * the value they claim to hold.
 *
 * @see DrillReport
 * @see DrillIndex
 */
final class DrillRunner
{
	/**
	 * The finding code a drill raises when the store does not reproduce the site.
	 */
	public const CODE = 'drill.drift';

	/**
	 * Subjects one drill examines when no size is given.
	 *
	 * A drill runs on cron beside everything else, so it is bounded by default. Every subject can be
	 * checked with an explicit sample size, at the cost of one replay each.
	 */
	public const DEFAULT_SAMPLE = 50;

	/**
	 * Constructs a runner.
	 *
	 * @param Replayer $replayer
	 *   Reconstructs what the store says a subject was.
	 * @param SubjectIndex $subjects
	 *   Names the subjects and when each was last captured.
	 * @param RefStore $refs
	 *   Supplies the commit to replay against when none is given.
	 * @param EntityTypeManagerInterface $entityTypeManager
	 *   Loads the live entities.
	 * @param ConfigFactoryInterface $configFactory
	 *   Loads the live configuration.
	 * @param StateInterface $state
	 *   Loads the live state values.
	 * @param HealthLedgerInterface $ledger
	 *   Where drift is recorded.
	 * @param TimeInterface $time
	 *   Stamps the report.
	 * @param LoggerInterface $logger
	 *   Records the verdict.
	 * @param DrillIndex|null $index
	 *   Where the run is recorded, or NULL not to record it.
	 * @param Notifier|null $notifier
	 *   Announces the result, or NULL to stay silent.
	 */
	public function __construct(
		private readonly Replayer $replayer,
		private readonly SubjectIndex $subjects,
		private readonly RefStore $refs,
		private readonly EntityTypeManagerInterface $entityTypeManager,
		private readonly ConfigFactoryInterface $configFactory,
		private readonly StateInterface $state,
		private readonly HealthLedgerInterface $ledger,
		private readonly TimeInterface $time,
		private readonly LoggerInterface $logger,
		private readonly ?DrillIndex $index = null,
		private readonly ?Notifier $notifier = null,
	) {}

	/**
	 * Runs a drill.
	 *
	 * @param string|null $target
	 *   Commit to replay against, or NULL for the current head.
	 * @param int $sample
	 *   Subjects to examine, or 0 for the default. A negative value examines every subject.
	 *
	 * @return DrillReport
	 *   What the drill found.
	 */
	public function run(?string $target = null, int $sample = 0): DrillReport
	{
		$started = microtime(true);
		$commit = $target ?? $this->refs->read();

		if ($commit === null) {
			return $this->refuse('there is no history to drill against', $started);
		}

		$population = $this->subjects->all();

		if ($population === []) {
			return $this->refuse('no subject has been captured yet', $started, $commit);
		}

		$chosen = $this->choose(array_keys($population), $sample);
		$buckets = [
			DrillReport::MATCHED => [],
			DrillReport::DIFFERED => [],
			DrillReport::SKIPPED => [],
			DrillReport::UNREADABLE => [],
		];

		foreach ($chosen as $subject) {
			[$status, $detail] = $this->examine($subject, $commit);
			$buckets[$status][$subject] = $detail;
		}

		$report = new DrillReport(
			$commit,
			'replay',
			$buckets[DrillReport::MATCHED],
			$buckets[DrillReport::DIFFERED],
			$buckets[DrillReport::SKIPPED],
			$buckets[DrillReport::UNREADABLE],
			count($chosen),
			count($population),
			microtime(true) - $started,
			$this->time->getRequestTime(),
		);

		$this->record($report);

		return $report;
	}

	/**
	 * Compares one subject against what the store says it was.
	 *
	 * @param string $subject
	 *   Subject path, in the form `<realm>/<name>`.
	 * @param string $commit
	 *   Commit to replay against.
	 *
	 * @return array{string, string}
	 *   The verdict constant and a note.
	 */
	public function examine(string $subject, string $commit): array
	{
		try {
			$result = $this->replayer->materialize($subject, $commit);
		} catch (Throwable $e) {
			return [DrillReport::UNREADABLE, $e->getMessage()];
		}

		if (!$result->isComplete()) {
			return [
				DrillReport::UNREADABLE,
				sprintf('%d frames would not decode', count($result->unreadable)),
			];
		}
		if (!$result->wasFound()) {
			return [DrillReport::SKIPPED, 'the subject does not exist at that commit'];
		}

		$live = $this->live($subject);

		if ($live === null) {
			return [DrillReport::SKIPPED, 'the realm holds no value this drill can read back'];
		}
		if ($live === false) {
			return [DrillReport::DIFFERED, 'the store holds a value and the site does not'];
		}

		return $this->compare($result, $live);
	}

	#region Comparison

	/**
	 * Compares a replayed value against the live one.
	 *
	 * Only the fields the store claims to know are compared. A field the site has and the capture
	 * never recorded is not evidence the store is wrong; it is evidence the capture scope excludes
	 * it, which is a different question and the reconciler's.
	 *
	 * @param ReplayResult $result
	 *   What the store reconstructed.
	 * @param array<string, mixed> $live
	 *   What the site holds.
	 *
	 * @return array{string, string}
	 *   The verdict constant and a note.
	 */
	private function compare(ReplayResult $result, array $live): array
	{
		$differences = [];

		foreach ($result->fields as $field => $stored) {
			if (!array_key_exists($field, $live)) {
				continue;
			}
			if ($this->normalize($stored) !== $this->normalize($live[$field])) {
				$differences[] = (string) $field;
			}
		}

		if ($differences === []) {
			return [
				DrillReport::MATCHED,
				sprintf(
					'%d fields reproduced over %d versions',
					count($result->fields),
					$result->versions,
				),
			];
		}

		return [
			DrillReport::DIFFERED,
			sprintf('%s differ', implode(', ', array_slice($differences, 0, 8))),
		];
	}

	/**
	 * The live value of a subject, as a field map.
	 *
	 * @param string $subject
	 *   Subject path.
	 *
	 * @return array<string, mixed>|false|null
	 *   The field map, FALSE when the realm holds values but this one is gone, or NULL when the realm
	 *   is one a drill cannot read back.
	 */
	private function live(string $subject): array|false|null
	{
		$at = strpos($subject, '/');

		if ($at === false) {
			return null;
		}

		$realm = Realm::tryFrom(substr($subject, 0, $at));
		$name = substr($subject, $at + 1);

		return match ($realm) {
			Realm::ENTITY => $this->liveEntity($name),
			Realm::CONFIG => $this->configFactory->get($name)->isNew()
				? false
				: $this->configFactory->get($name)->get(),
			Realm::STATE => $this->liveState($name),
			default => null,
		};
	}

	/**
	 * The live field map of an entity named by a subject path.
	 *
	 * @param string $name
	 *   The part after the realm, in the form `<entity type>/<id>`.
	 *
	 * @return array<string, mixed>|false|null
	 *   The field map, FALSE when the entity is gone, or NULL when the path is not readable.
	 */
	private function liveEntity(string $name): array|false|null
	{
		$parts = explode('/', $name);

		if (count($parts) < 2) {
			return null;
		}

		try {
			$storage = $this->entityTypeManager->getStorage($parts[0]);
		} catch (Throwable) {
			return null;
		}

		$entity = $storage->load($parts[1]);

		if (!($entity instanceof EntityInterface)) {
			return false;
		}

		return EntityDelta::payload($entity, null);
	}

	/**
	 * The live value of a state key, wrapped as a field map.
	 *
	 * @param string $key
	 *   The state key.
	 *
	 * @return array<string, mixed>|false
	 *   A single-entry map, or FALSE when the key is gone.
	 */
	private function liveState(string $key): array|false
	{
		$value = $this->state->get($key);

		return $value === null ? false : ['value' => $value];
	}

	/**
	 * Reduces a value to something two sides can be compared on.
	 *
	 * A stored value has been through JSON, so an integer may come back as a string and an object as
	 * an array. Comparing the serialized forms rather than the values themselves makes the drill
	 * insensitive to that round trip and sensitive to what actually changed.
	 *
	 * @param mixed $value
	 *   The value.
	 *
	 * @return string
	 *   A comparable form.
	 */
	private function normalize(mixed $value): string
	{
		if (is_scalar($value) || $value === null) {
			return (string) json_encode($value);
		}

		$encoded = json_encode($value);

		return $encoded === false ? serialize($value) : $encoded;
	}

	#endregion

	/**
	 * The subjects one drill examines.
	 *
	 * Chosen by even stride rather than at random, so two drills of the same store cover different
	 * subjects only when the store has changed, and a repeated drill of a failing store reproduces
	 * the failure rather than hiding it behind a new sample.
	 *
	 * @param list<string> $subjects
	 *   Every subject the index holds.
	 * @param int $sample
	 *   How many to examine, 0 for the default, negative for all.
	 *
	 * @return list<string>
	 *   The chosen subjects.
	 */
	private function choose(array $subjects, int $sample): array
	{
		$size = $sample === 0 ? self::DEFAULT_SAMPLE : $sample;

		if ($size < 0 || $size >= count($subjects)) {
			return $subjects;
		}

		sort($subjects);

		$stride = (int) floor(count($subjects) / $size);
		$chosen = [];

		for ($at = 0; $at < count($subjects) && count($chosen) < $size; $at += max(1, $stride)) {
			$chosen[] = $subjects[$at];
		}

		return $chosen;
	}

	/**
	 * Records a report, raising a finding when the store did not reproduce the site.
	 *
	 * @param DrillReport $report
	 *   The report.
	 */
	private function record(DrillReport $report): void
	{
		$this->index?->record($report);
		$this->notifier?->drillFinished($report);

		if ($report->passed()) {
			$this->logger->info('Strata restore drill passed: @summary', [
				'@summary' => $report->summary(),
			]);

			return;
		}

		// a drill that could judge nothing is a gap in coverage, not evidence of a fault
		$finding = new Finding(
			self::CODE,
			$report->judged() === 0 ? Finding::WARN : Finding::ERROR,
			$report->target,
			$report->summary(),
		);

		$this->ledger->record($finding);
		$this->notifier?->findingRecorded($finding, $this->ledger->rungFor($finding->code));

		$this->logger->error('Strata restore drill did not pass: @summary', [
			'@summary' => $report->summary(),
		]);
	}

	/**
	 * A report for a drill that could not start.
	 *
	 * @param string $reason
	 *   Why it did not run.
	 * @param float $started
	 *   When it was attempted.
	 * @param string $target
	 *   The commit it would have used.
	 *
	 * @return DrillReport
	 *   The refusal.
	 */
	private function refuse(string $reason, float $started, string $target = ''): DrillReport
	{
		return new DrillReport(
			$target,
			'replay',
			[],
			[],
			[],
			[],
			0,
			0,
			microtime(true) - $started,
			$this->time->getRequestTime(),
			$reason,
		);
	}
}
