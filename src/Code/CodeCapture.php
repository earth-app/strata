<?php

declare(strict_types=1);

namespace Drupal\strata\Code;

use Drupal\strata\Cas\Hash;
use Drupal\strata\Capture\CaptureScope;
use Drupal\strata\Health\HealthLedgerInterface;
use Drupal\strata\Health\TripwireRegistry;
use Drupal\strata\Journal\JournalInterface;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Captures the code a site owns, and the reference that stands in for the code it does not.
 *
 * Runs on cron rather than on a hook, because code changes when someone deploys and a deploy is not
 * an event Drupal dispatches. A pass compares the site's own code against the digest recorded last
 * time and does nothing when nothing moved, so the ordinary case costs one walk and no writes.
 *
 * Three things are captured and they are not the same kind of thing:
 *
 * - **Own modules, themes and profiles**, as bytes. 762 KiB compressed for a real module, and with
 *   delta coding across 150 deploys a year the whole realm is 8.98 MiB annually.
 * - **`settings.php`**, redacted. The configuration a restore needs, without the credentials that
 *   must not leave the server.
 * - **Lockfiles**, as the reference that reproduces `vendor/`. 41 KiB of `composer.lock` against
 *   18 MB of compressed core bytes, and it reproduces the tree exactly rather than approximately.
 *
 * And one thing is watched rather than captured: the vendor tree's fingerprint. When it moves without
 * the lock moving, a dependency was patched by hand, the lock no longer reproduces the tree, and the
 * drifted files are captured as bytes. That check is the only thing standing between the 440x saving
 * and a backup silently missing a patch.
 *
 * @see CodeScanner
 * @see VendorDriftDetector
 * @see LockfileReference
 */
final class CodeCapture
{
	/**
	 * State key holding the digest of the site's own code at the last pass.
	 */
	public const DIGEST_KEY = 'strata.code.digest';

	/**
	 * State key holding the vendor fingerprint recorded with the current lock.
	 */
	public const FINGERPRINT_KEY = 'strata.code.vendor_fingerprint';

	/**
	 * State key holding the per-file descriptions the fingerprint was built from.
	 */
	public const DESCRIPTIONS_KEY = 'strata.code.vendor_files';

	/**
	 * State key holding the digest of `composer.lock` at the last pass.
	 */
	public const LOCK_KEY = 'strata.code.lock_digest';

	/**
	 * Constructs a capture.
	 *
	 * @param CodeScanner $scanner
	 *   Walks the site's own code.
	 * @param VendorDriftDetector $vendor
	 *   Watches the dependency tree.
	 * @param JournalInterface $journal
	 *   Where operations are appended.
	 * @param CaptureScope $scope
	 *   Decides whether the code realm is captured.
	 * @param StateInterface $state
	 *   Remembers what the last pass saw.
	 * @param TripwireRegistry $tripwires
	 *   Runs the drift check.
	 * @param HealthLedgerInterface $ledger
	 *   Where drift is recorded.
	 * @param LoggerInterface $logger
	 *   Records what a pass did.
	 */
	public function __construct(
		private readonly CodeScanner $scanner,
		private readonly VendorDriftDetector $vendor,
		private readonly JournalInterface $journal,
		private readonly CaptureScope $scope,
		private readonly StateInterface $state,
		private readonly TripwireRegistry $tripwires,
		private readonly HealthLedgerInterface $ledger,
		private readonly LoggerInterface $logger,
	) {}

	#region Passes

	/**
	 * Captures the code realm if anything changed.
	 *
	 * @param bool $force
	 *   TRUE to capture whether or not the digest moved, which an export and a first run want.
	 *
	 * @return CodeReport
	 *   What the pass did.
	 */
	public function capture(bool $force = false): CodeReport
	{
		$started = microtime(true);

		if (!$this->scope->covers(Realm::CODE)) {
			return CodeReport::skipped(
				'the code realm is not captured',
				microtime(true) - $started,
			);
		}

		$digest = $this->scanner->digest();
		$previous = (string) $this->state->get(self::DIGEST_KEY, '');
		$drift = $this->checkVendor();

		if (!$force && $previous !== '' && Hash::equals($digest, $previous) && !$drift['drifted']) {
			return CodeReport::skipped(
				'the code and the vendor tree are unchanged',
				microtime(true) - $started,
			);
		}

		$files = $this->captureOwnCode();
		$locks = $this->captureLockfiles();
		$drifted = $drift['drifted'] ? $this->captureDrifted($drift) : 0;

		$this->state->set(self::DIGEST_KEY, $digest);

		$report = new CodeReport(
			true,
			$files['files'],
			$files['bytes'],
			$files['redacted'],
			$locks,
			$drifted,
			$drift['reason'],
			$files['problems'],
			microtime(true) - $started,
		);

		$this->logger->info('Strata %summary', ['%summary' => $report->summary()]);

		return $report;
	}

	/**
	 * What a capture would cost, without writing anything.
	 *
	 * @return array{files: int, bytes: int, redacted: int, lockfiles: int, drifted: bool,
	 *   truncated: bool}
	 *   The measurement.
	 */
	public function measure(): array
	{
		$measured = $this->scanner->measure();

		return [
			'files' => $measured['files'],
			'bytes' => $measured['bytes'],
			'redacted' => $measured['redacted'],
			'lockfiles' => count($this->scanner->lockfiles()),
			'drifted' => $this->checkVendor()['drifted'],
			'truncated' => $measured['truncated'],
		];
	}

	/**
	 * Records the vendor tree as it stands, so later passes have something to compare against.
	 *
	 * Called after a deploy, and by the first pass on a site. Taking the fingerprint and the lock
	 * digest together is what makes "the tree moved but the lock did not" a meaningful statement.
	 */
	public function acceptVendorState(): void
	{
		$lock = LockfileReference::read($this->scanner->root(), 'composer.lock');

		$this->state->set(self::FINGERPRINT_KEY, $this->vendor->fingerprint());
		$this->state->set(self::DESCRIPTIONS_KEY, $this->vendor->descriptions());
		$this->state->set(self::LOCK_KEY, $lock === null ? '' : $lock->digest);
	}

	#endregion

	#region Capturing

	/**
	 * Appends one operation per code file that changed.
	 *
	 * @return array{files: int, bytes: int, redacted: int, problems: list<string>}
	 *   What was appended.
	 */
	private function captureOwnCode(): array
	{
		$files = 0;
		$bytes = 0;
		$redacted = 0;
		$problems = [];

		foreach ($this->scanner->scan() as $path => $file) {
			try {
				$this->append(
					$path,
					$file['contents'],
					$file['redacted'] > 0
						? sprintf('%s (%d secrets redacted)', $path, $file['redacted'])
						: $path,
				);

				$files++;
				$bytes += $file['bytes'];
				$redacted += $file['redacted'];
			} catch (Throwable $error) {
				$problems[] = sprintf('%s: %s', $path, $error->getMessage());
			}
		}

		if ($this->scanner->wasTruncated()) {
			$problems[] = sprintf(
				'the walk stopped at %d files, so the code realm is incomplete',
				$this->scanner->maxFiles(),
			);
		}

		return [
			'files' => $files,
			'bytes' => $bytes,
			'redacted' => $redacted,
			'problems' => $problems,
		];
	}

	/**
	 * Appends one operation per lockfile.
	 *
	 * The lock's own bytes are stored, not just its digest. A digest identifies a lock; only the
	 * contents reproduce a tree, and reproducing the tree is the entire reason `vendor/` is not
	 * stored.
	 *
	 * @return int
	 *   How many lockfiles were captured.
	 */
	private function captureLockfiles(): int
	{
		$captured = 0;

		foreach ($this->scanner->lockfiles() as $path => $reference) {
			$contents = @file_get_contents(rtrim($this->scanner->root(), '/') . '/' . $path);

			if ($contents === false) {
				continue;
			}

			$this->append($path, $contents, sprintf('%s (%d bytes)', $path, $reference->bytes));
			$captured++;
		}

		return $captured;
	}

	/**
	 * Appends the files a patched dependency changed.
	 *
	 * Only the drifted files, which is what makes a patched dependency cost the patch rather than
	 * the tree.
	 *
	 * @param array{drifted: bool, reason: string, lockChanged: bool, fingerprint: string} $drift
	 *   What the comparison found.
	 *
	 * @return int
	 *   How many files were captured.
	 */
	private function captureDrifted(array $drift): int
	{
		/** @var array<string, string> $descriptions */
		$descriptions = (array) $this->state->get(self::DESCRIPTIONS_KEY, []);
		$captured = 0;

		foreach ($this->vendor->drifted($descriptions) as $path) {
			$full = rtrim($this->scanner->root(), '/') . '/' . $path;
			$contents = @file_get_contents($full);

			if ($contents === false) {
				continue;
			}

			$this->append($path, $contents, sprintf('%s was patched in place', $path));
			$captured++;
		}

		// the new state becomes the baseline, so the same patch is not reported again every cron
		$this->state->set(self::FINGERPRINT_KEY, $drift['fingerprint']);
		$this->state->set(self::DESCRIPTIONS_KEY, $this->vendor->descriptions());

		return $captured;
	}

	/**
	 * Appends one code operation.
	 *
	 * @param string $path
	 *   Path relative to the project root, which is the subject.
	 * @param string $contents
	 *   What to store.
	 * @param string $label
	 *   What the timeline shows.
	 */
	private function append(string $path, string $contents, string $label): void
	{
		$this->journal->append(
			new JournalOp(
				0,
				(int) round(microtime(true) * JournalOp::MICROSECONDS_PER_SECOND),
				Realm::CODE,
				$path,
				Verb::UPDATE,
				null,
				null,
				Hash::of($contents),
				null,
				strlen($contents),
				$label,
			),
			$contents,
		);
	}

	#endregion

	/**
	 * Compares the vendor tree against what was recorded, and records drift.
	 *
	 * @return array{drifted: bool, reason: string, lockChanged: bool, fingerprint: string}
	 *   What the comparison found.
	 */
	private function checkVendor(): array
	{
		$result = $this->vendor->compare(
			(string) $this->state->get(self::FINGERPRINT_KEY, ''),
			(string) $this->state->get(self::LOCK_KEY, ''),
		);

		// a first pass has nothing to compare against, so the current state becomes the baseline
		if ((string) $this->state->get(self::FINGERPRINT_KEY, '') === '') {
			$this->acceptVendorState();

			return [
				'drifted' => false,
				'reason' => 'the vendor tree was recorded for the first time',
				'lockChanged' => false,
				'fingerprint' => $result['fingerprint'],
			];
		}

		if ($result['lockChanged']) {
			$this->acceptVendorState();
		}
		if (!$result['drifted']) {
			return $result;
		}

		/** @var array<string, string> $descriptions */
		$descriptions = (array) $this->state->get(self::DESCRIPTIONS_KEY, []);

		foreach (
			$this->tripwires->evaluate([
				'vendor_drifted' => true,
				'drift_reason' => $result['reason'],
				'drifted_files' => count($this->vendor->drifted($descriptions)),
			])
			as $finding
		) {
			$this->ledger->record($finding);
		}

		return $result;
	}
}
