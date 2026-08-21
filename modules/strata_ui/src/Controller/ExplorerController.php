<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Controller;

use Drupal\Core\Url;
use Drupal\strata\Explore\StorageExplorer;
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
			'#totals' => [
				'frames' => Format::count($report->frames),
				'commits' => Format::count($report->commits),
				'dictionaries' => Format::count($report->dictionaries),
				'raw' => Format::bytes($report->rawBytes),
				'stored' => Format::bytes($report->storedBytes),
				'ratio' => Format::ratio($report->ratio()),
			],
			'#reachable' => $report->reachable,
			'#by_realm' => $this->realms($report->byRealm),
			'#complete' => $report->complete,
			'#collectable' => $report->collectableCount(),
			'#collectable_bytes' => Format::bytes($report->collectableBytes),
			'#waste_share' => sprintf('%.1f%%', $report->wasteShare() * 100),
			'#incomplete_note' => (string) $this->t(
				'The reachability walk did not finish, so a prune would refuse. Run strata:verify.',
			),
			'#realm_note' => (string) $this->t(
				'These are captured bytes, not bytes on disk. A frame carries no realm of its own.',
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
			$rows[] = [$realm, Format::bytes($bytes)];
		}

		return $rows;
	}
}
