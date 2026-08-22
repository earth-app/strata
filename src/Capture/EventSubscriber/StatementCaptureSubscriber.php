<?php

declare(strict_types=1);

namespace Drupal\strata\Capture\EventSubscriber;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Event\StatementExecutionEndEvent;
use Drupal\Core\Database\Event\StatementExecutionStartEvent;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\strata\Capture\CaptureScope;
use Drupal\strata\Capture\Reconciler;
use Drupal\strata\Capture\SqlStatement;
use Drupal\strata\Capture\TableWrites;
use Drupal\strata\Journal\JournalFactory;
use Drupal\strata\Journal\JournalInterface;
use Drupal\strata\Journal\JournalOp;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * Sees every write the database performs, including the ones nothing else instruments.
 *
 * Entity, config and state capture cover what goes through an API. This covers what does not: a raw
 * `Connection::insert()` into a custom table, an update hook's DDL, a contributed module writing
 * directly. Without it a site can be modified in ways the backup never hears about, and the backup
 * would not know it had a gap.
 *
 * **What it records is that a table changed, not which rows.** A statement says which rows it
 * touched only in its WHERE clause, and evaluating that would mean running the query again. So one
 * operation per table per window, which keeps the tree bounded by the schema rather than by
 * traffic, and the reconciler turns a dirty table into the per-row detail a restore can actually
 * use.
 *
 * **Cost.** The verb guard runs on every statement and reads one keyword. Enabling the events at
 * all costs more than the guard does: `StatementExecutionEndEvent` only fires when the Start event
 * is also enabled, and Start's constructor calls `Connection::findCallerFromDebugBacktrace()`,
 * measured at 0.78 us at stack depth 20 and 2.99 us at depth 100. Total capture overhead stays
 * under 10 us per mutation, about 0.6 ms on a 200-query request. `strata:calibrate` measures it on
 * the real host and the settings form shows that number next to the switch.
 *
 * **The connection's table prefix comes off before anything else looks at the name.** The SQL a
 * statement event carries is already prefixed, and a prefix is a `settings.php` detail that can
 * differ between the site a backup came from and the site it goes back to. It also hides this
 * module's own tables from CaptureScope::coversTable(), which would make journaling a write a write
 * that gets journaled.
 *
 * **The gap this has, stated plainly.** Statement events are per-connection and off by default, so
 * they are enabled on the first request event this subscriber sees. Queries that run before that -
 * bootstrap, routing, session - are not captured. That is what the reconciler's watermark is for: a
 * table that changed without a captured operation is drift, and drift is reported.
 *
 * **The journal arrives as a factory, and that is load bearing.** Building a journal reads
 * `strata.settings`, and reading configuration queries the database, and a query dispatches the very
 * event this class listens for. Taking `strata.journal` as a constructor argument therefore made the
 * container resolve this subscriber while it was already resolving it, which is a circular reference
 * and takes the site down on any request that logs. So the factory is injected and `journal()`
 * resolves once on first use, by which point the container has finished with this object. The
 * re-entrancy flag guards the resolution itself against being entered twice; it is defence for a
 * future caller, because nothing on the statement path reaches `journal()` today.
 *
 * @see JournalFactory
 * @see SqlStatement
 * @see TableWrites
 * @see Reconciler
 */
final class StatementCaptureSubscriber implements EventSubscriberInterface
{
	/**
	 * Longest statement fragment kept as a sample.
	 *
	 * Enough to recognise the query in a timeline. The full text is not stored: an unbounded column
	 * fed by every write on a busy site is how an audit table becomes the largest thing in the
	 * database, and the statement is not what a restore replays anyway.
	 */
	public const SAMPLE_LENGTH = 200;

	/**
	 * Most distinct tables to accumulate in one request before new ones are dropped.
	 *
	 * A bound rather than a policy. A request touching more tables than this is a migration or an
	 * update hook, and the reconciler covers what falls off the end.
	 */
	public const MAX_TABLES = 200;

	/**
	 * Whether events have been enabled on the connection.
	 */
	private bool $tapping = false;

	/**
	 * What each table saw this request, keyed by table name.
	 *
	 * @var array<string, TableWrites>
	 */
	private array $dirty = [];

	/**
	 * The journal, once something has needed it.
	 */
	private ?JournalInterface $journal = null;

	/**
	 * Whether a journal is being resolved right now.
	 *
	 * Resolving one reads configuration, and reading configuration queries. Nothing on the statement
	 * path asks for the journal today, so this is a guard against a future one rather than a live
	 * mechanism.
	 */
	private bool $resolving = false;

	/**
	 * Constructs the subscriber.
	 *
	 * @param Connection $database
	 *   The connection events are enabled on.
	 * @param JournalFactory $journalFactory
	 *   Builds the journal on first use. Deliberately not the journal itself; see the class docblock.
	 * @param CaptureScope $scope
	 *   Decides whether the tap runs and which tables it covers.
	 * @param AccountProxyInterface $currentUser
	 *   Attributes an operation to whoever caused it.
	 * @param LoggerInterface $logger
	 *   Records a capture that failed.
	 * @param string $requestId
	 *   Groups every operation captured in one request.
	 */
	public function __construct(
		private readonly Connection $database,
		private readonly JournalFactory $journalFactory,
		private readonly CaptureScope $scope,
		private readonly AccountProxyInterface $currentUser,
		private readonly LoggerInterface $logger,
		private readonly string $requestId = '',
	) {}

	/**
	 * The journal, resolved once.
	 *
	 * @return JournalInterface|null
	 *   The journal, or NULL while one is already being resolved, which means the caller is the
	 *   configuration read that resolution itself triggered.
	 */
	private function journal(): ?JournalInterface
	{
		if ($this->journal !== null) {
			return $this->journal;
		}
		if ($this->resolving) {
			return null;
		}

		$this->resolving = true;

		try {
			return $this->journal = $this->journalFactory->create();
		} finally {
			$this->resolving = false;
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   Events this subscriber listens to.
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			// early, so as much of the request as possible is covered
			KernelEvents::REQUEST => ['onRequest', 512],
			KernelEvents::TERMINATE => ['onTerminate', -512],
			StatementExecutionEndEvent::class => ['onStatement'],
		];
	}

	#region Lifecycle

	/**
	 * Turns the tap on for this request.
	 *
	 * @param RequestEvent $event
	 *   The request event, unused beyond triggering this.
	 */
	public function onRequest(RequestEvent $event): void
	{
		$this->enable();
	}

	/**
	 * Journals what the request touched, once the response has been sent.
	 *
	 * @param TerminateEvent $event
	 *   The terminate event, unused beyond triggering this.
	 */
	public function onTerminate(TerminateEvent $event): void
	{
		$this->commit();
	}

	/**
	 * Enables statement events on the connection.
	 *
	 * Public because a request is not the only thing that writes: cron, a drush command and a queue
	 * worker all need to call this, and none of them dispatches a kernel request event.
	 */
	public function enable(): void
	{
		if ($this->tapping || !$this->scope->tapsStatements()) {
			return;
		}

		// the End event only fires when Start is enabled too, since End reads Start's caller frame
		$this->database->enableEvents([
			StatementExecutionStartEvent::class,
			StatementExecutionEndEvent::class,
		]);

		$this->tapping = true;
	}

	/**
	 * Turns the tap off.
	 *
	 * Used by a restore, which writes a great deal and whose writes are already described by the
	 * commit it is restoring from, and by a calibration run measuring the cost of the tap itself.
	 */
	public function disable(): void
	{
		if (!$this->tapping) {
			return;
		}

		$this->database->disableEvents([
			StatementExecutionStartEvent::class,
			StatementExecutionEndEvent::class,
		]);

		$this->tapping = false;
	}

	/**
	 * Whether the tap is currently on.
	 *
	 * @return bool
	 *   TRUE when statement events are enabled.
	 */
	public function isTapping(): bool
	{
		return $this->tapping;
	}

	#endregion

	#region Capture

	/**
	 * Notes a statement that changed something.
	 *
	 * @param StatementExecutionEndEvent $event
	 *   The statement that just ran.
	 */
	public function onStatement(StatementExecutionEndEvent $event): void
	{
		// the guard first: all but a few per cent of statements are reads and stop here
		if (!SqlStatement::isWrite($event->queryString)) {
			return;
		}
		if (!$this->scope->coversTarget($event->target)) {
			return;
		}

		$statement = SqlStatement::parse($event->queryString, $this->database->getPrefix());

		if ($statement === null) {
			return;
		}
		if (!$this->scope->covers($statement->realm)) {
			return;
		}
		if (!$this->scope->coversTable($statement->table)) {
			return;
		}

		$this->note($statement, $event->queryString);
	}

	/**
	 * Journals one operation per table touched, and clears the accumulator.
	 *
	 * Deferred to the end of the request so a request writing four hundred rows to one table produces
	 * one operation rather than four hundred, and so the journal write happens off the response's
	 * critical path. Public so cron and a drush command can flush what they touched without a
	 * terminate event.
	 *
	 * @return int
	 *   How many operations were journaled.
	 */
	public function commit(): int
	{
		if ($this->dirty === []) {
			return 0;
		}

		// resolving the journal reads configuration and can refuse; a capture never breaks the
		// request that caused it, so this is inside the guard rather than beside it
		try {
			$journal = $this->journal();
		} catch (Throwable $error) {
			$this->logger->error('Strata could not open its journal to record writes: %message', [
				'%message' => $error->getMessage(),
			]);

			return 0;
		}

		if ($journal === null) {
			// a nested commit, which means this call is the configuration read resolving the journal
			return 0;
		}

		$dirty = $this->dirty;
		$this->dirty = [];
		$written = 0;

		foreach ($dirty as $table => $seen) {
			try {
				$payload = (string) json_encode($seen);

				$journal->append(
					new JournalOp(
						0,
						$this->now(),
						$seen->realm,
						$table,
						$seen->verb,
						$this->actor(),
						$this->requestId === '' ? null : $this->requestId,
						null,
						null,
						strlen($payload),
						$seen->label($table),
						$seen->fieldNames(),
					),
					$payload,
				);

				$written++;
			} catch (Throwable $error) {
				// a capture never breaks the request that caused it
				$this->logger->error('Strata could not record writes to %table: %message', [
					'%table' => $table,
					'%message' => $error->getMessage(),
				]);
			}
		}

		return $written;
	}

	/**
	 * Tables noted this request but not yet journaled.
	 *
	 * @return list<string>
	 *   Table names.
	 */
	public function pending(): array
	{
		return array_keys($this->dirty);
	}

	/**
	 * What a table has seen so far this request.
	 *
	 * @param string $table
	 *   The table name.
	 *
	 * @return TableWrites|null
	 *   The record, or NULL when the table has not been written to.
	 */
	public function writesTo(string $table): ?TableWrites
	{
		return $this->dirty[$table] ?? null;
	}

	/**
	 * Folds one statement into the accumulator.
	 *
	 * @param SqlStatement $statement
	 *   The classified statement.
	 * @param string $sql
	 *   The statement text, kept as a shape so the timeline can name the query without carrying any
	 *   value that might have been interpolated into it.
	 */
	private function note(SqlStatement $statement, string $sql): void
	{
		$table = $statement->table;

		if (!isset($this->dirty[$table]) && count($this->dirty) >= self::MAX_TABLES) {
			return;
		}

		$sample = SqlStatement::shape($sql, self::SAMPLE_LENGTH);
		$existing = $this->dirty[$table] ?? null;

		$this->dirty[$table] =
			$existing === null
				? TableWrites::from($statement, $sample)
				: $existing->fold($statement, $sample);
	}

	#endregion

	/**
	 * The user an operation is attributed to.
	 *
	 * @return int|null
	 *   A Drupal user id, or NULL for unattended work.
	 */
	private function actor(): ?int
	{
		$id = (int) $this->currentUser->id();

		return $id > 0 ? $id : null;
	}

	/**
	 * The current time in unix microseconds.
	 *
	 * @return int
	 *   Microseconds since the epoch.
	 */
	private function now(): int
	{
		return (int) round(microtime(true) * JournalOp::MICROSECONDS_PER_SECOND);
	}
}
