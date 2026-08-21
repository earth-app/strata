<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Controller;

use Drupal\Core\Url;
use Drupal\strata\Branch\Branch;
use Drupal\strata\Branch\Merger;
use Drupal\strata_ui\Render\Format;

/**
 * The configuration branches this site holds, and where each one points.
 *
 * **Read from the local index rather than the bucket.** Listing branches from the store means one
 * listing plus a GET per branch, and the page an operator opens to see what exists should not cost a
 * request per row. The rows are refreshed from the store as the listing is built, so the page is
 * accurate and `strata:reindex` can rebuild the table from the objects at any time.
 *
 * The trunk is listed with everything else. It is the ref every merge writes into, and leaving it
 * out would make the one branch that always exists the one the page never mentions.
 *
 * @see Branch
 * @see Merger
 */
final class BranchController extends StrataControllerBase
{
	/**
	 * The listing.
	 *
	 * @return array<string, mixed>
	 *   The page.
	 */
	public function page(): array
	{
		return $this->guard(function (): array {
			$merger = $this->engine->merger();
			$branches = $merger->list();
			$rows = [];

			foreach ($branches as $branch) {
				$rows[] = $this->row($branch);
			}

			return [
				'#theme' => 'strata_branches',
				'#branches' => $rows,
				'#empty' => count($branches) < 2,
				'#realm' => Branch::REALM->label(),
				'#realm_note' => (string) $this->t(
					'A branch carries configuration only. Configuration is captured whole, so merging it is well defined; content and table rows are captured as deltas against a parent, and merging two divergent delta chains would mean inventing values.',
				),
				'#flush_note' => (string) $this->t(
					'Flush onto a branch with drush strata:flush --ref=heads/NAME.',
				),
				'#timeline_url' => Url::fromRoute('strata_ui.timeline')->toString(),
			];
		});
	}

	/**
	 * One branch as a template draws it.
	 *
	 * @param Branch $branch
	 *   The branch.
	 *
	 * @return array<string, mixed>
	 *   The row.
	 */
	private function row(Branch $branch): array
	{
		return [
			'name' => $branch->name,
			'ref' => $branch->ref(),
			'trunk' => $branch->isTrunk(),
			'forked_from' => Format::address($branch->forkedFrom),
			'tip' => Format::address($branch->tip),
			'moved' => !$branch->isUnchanged(),
			'created' => $branch->createdAt < 1 ? '' : Format::moment($branch->createdAt),
			'merge_url' =>
				$branch->isTrunk() || $branch->isUnchanged()
					? null
					: Url::fromRoute('strata_ui.merge', ['branch' => $branch->name])->toString(),
		];
	}
}
