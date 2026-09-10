<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Controller;

use Drupal\Core\Url;
use Drupal\strata\Explore\StorageExplorer;
use Drupal\strata\Journal\Realm;
use Drupal\strata_ui\Render\Format;

/**
 * What the store holds, and what deleting something would take with it.
 *
 * **The number an operator wants is almost never the size of the thing they clicked.** A commit that
 * wrote four megabytes usually frees far less: its frames are shared with the commits around it, held
 * as anchors by later versions of the same subject, or named as dictionaries by frames elsewhere. So
 * this page prices a removal by asking what would become unreachable, and lists what would be kept
 * with the reason.
 *
 * When the reachability walk could not finish, the collectable figure is shown as unknown rather than
 * as zero, and the prune link is withheld. A partial walk cannot prove a frame is unreferenced - only
 * that it did not happen to see a reference - and those are different claims.
 *
 * @see StorageExplorer
 */
final class ExplorerController extends StrataControllerBase
{
	/**
	 * What the store holds.
	 *
	 * @return array<string, mixed>
	 *   The page.
	 */
	public function page(): array
	{
		return $this->guard(fn(): array => $this->overview());
	}

	/**
	 * The overview, once the engine is known to assemble.
	 *
	 * @return array<string, mixed>
	 *   The render array.
	 */
	private function overview(): array
	{
		$explorer = $this->engine->storageExplorer();
		$report = $explorer->report();

		return [
			'#theme' => 'strata_explorer',
			// keyed by what the page should say rather than by the property name, because the
			// template renders the key and "raw" is not a column heading
			'#totals' => [
				(string) $this->t('Frames') => Format::count($report->frames),
				(string) $this->t('Restore Points') => Format::count($report->commits),
				(string) $this->t('Dictionaries') => Format::count($report->dictionaries),
				(string) $this->t('Captured') => Format::bytes($report->rawBytes),
				(string) $this->t('Stored') => Format::bytes($report->storedBytes),
				(string) $this->t('Compression') => Format::ratio($report->ratio()),
			],
			'#reachable' => $this->reachable($report->reachable),
			'#by_realm' => $this->realms($report->byRealm),
			'#complete' => $report->complete,
			'#collectable' => $report->collectableCount(),
			'#collectable_bytes' => Format::bytes($report->collectableBytes),
			'#waste_share' => sprintf('%.1f%%', $report->wasteShare() * 100),
			'#incomplete_note' => (string) $this->t(
				'The reachability walk did not finish, so a prune would refuse. Run strata:verify.',
			),
			// the sum is over the journal table, which a flush empties, so this measures the window
			// that has not been sealed yet rather than anything the store holds
			'#realm_note' => (string) $this->t(
				'Captured bytes still waiting to be sealed, not what the store holds. A frame carries no realm of its own.',
			),
			'#largest' => $this->largest($explorer),
			'#prune_url' => Url::fromRoute('strata_ui.prune')->toString(),
			'#summary' => $report->summary(),
		];
	}

	/**
	 * What removing one commit would free.
	 *
	 * @param string $commit
	 *   The commit id.
	 *
	 * @return array<string, mixed>
	 *   The page.
	 */
	public function commit(string $commit): array
	{
		return $this->guard(fn(): array => $this->removalCost($commit));
	}

	/**
	 * The removal cost, once the engine is known to assemble.
	 *
	 * @param string $commit
	 *   The commit id.
	 *
	 * @return array<string, mixed>
	 *   The render array.
	 */
	private function removalCost(string $commit): array
	{
		$cost = $this->engine->storageExplorer()->costOfRemoving($commit);
		$retained = [];

		foreach ($cost->retained as $hash => $reason) {
			$retained[] = [Format::address((string) $hash), $reason];
		}

		return [
			'#type' => 'container',
			'summary' => ['#markup' => $cost->summary()],
			'freed' => [
				'#type' => 'item',
				'#title' => $this->t('Would Be Freed'),
				'#markup' => $this->t('@count frames, @bytes', [
					'@count' => Format::count(count($cost->freed)),
					'@bytes' => Format::bytes($cost->bytes),
				]),
			],
			'retained' => [
				'#type' => 'table',
				'#caption' => $this->t('Would Be Kept, and Why'),
				'#header' => [$this->t('Frame'), $this->t('Held By')],
				'#rows' => $retained,
				'#empty' => $this->t('Nothing this commit references is needed elsewhere.'),
			],
			'back' => [
				'#type' => 'link',
				'#title' => $this->t('Back to the Storage Explorer'),
				'#url' => Url::fromRoute('strata_ui.explorer'),
			],
		];
	}

	/**
	 * The largest commits, as table rows.
	 *
	 * @param StorageExplorer $explorer
	 *   The explorer.
	 *
	 * @return list<array<int, mixed>>
	 *   The rows.
	 */
	private function largest(StorageExplorer $explorer): array
	{
		$rows = [];

		foreach ($explorer->largestCommits() as $row) {
			$id = (string) $row['id'];

			$rows[] = [
				Format::moment((int) $row['microtime']),
				Format::address($id),
				(string) $row['label'],
				Format::bytes((int) $row['stored_bytes']),
				[
					'data' => [
						'#type' => 'link',
						'#title' => $this->t('What It Holds'),
						'#url' => Url::fromRoute('strata_ui.explorer.commit', ['commit' => $id]),
					],
				],
			];
		}

		return $rows;
	}

	/**
	 * The per-realm capture totals, rendered.
	 *
	 * @param array<string, int> $byRealm
	 *   Realm value keyed to bytes.
	 *
	 * @return list<array<int, string>>
	 *   The rows, largest first.
	 */
	private function realms(array $byRealm): array
	{
		arsort($byRealm);

		$rows = [];

		foreach ($byRealm as $realm => $bytes) {
			$rows[] = [Realm::tryFrom((string) $realm)?->label() ?? $realm, Format::bytes($bytes)];
		}

		return $rows;
	}

	/**
	 * The reachability counts, keyed by what to call each one on screen.
	 *
	 * `trees` appears nowhere else in the UI and means nothing to a site builder, so the walk's own
	 * keys are translated here rather than rendered raw.
	 *
	 * @param array<string, int> $counts
	 *   What the walk found, keyed by its own names.
	 *
	 * @return array<string, int>
	 *   The same counts, keyed by their labels.
	 */
	private function reachable(array $counts): array
	{
		$labels = [
			'commits' => (string) $this->t('Live Restore Points'),
			'trees' => (string) $this->t('Commit Indexes'),
			'frames' => (string) $this->t('Live Frames'),
			'dictionaries' => (string) $this->t('Dictionaries'),
			'unreadable' => (string) $this->t('Unreadable'),
		];

		$rows = [];

		foreach ($counts as $key => $count) {
			$rows[$labels[$key] ?? (string) $key] = $count;
		}

		return $rows;
	}
}
