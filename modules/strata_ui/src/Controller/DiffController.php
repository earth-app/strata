<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Controller;

use Drupal\Core\Url;
use Drupal\strata\Diff\DiffBuilder;
use Drupal\strata\Diff\SubjectDiff;
use Drupal\strata\Journal\Realm;
use Drupal\strata_ui\Render\Format;
use Symfony\Component\HttpFoundation\Request;

/**
 * What changed between two commits, field by field.
 *
 * **A diff is bounded and says when it was cut.** Comparing two commits costs two replays per
 * subject, and a replay walks from an anchor forward, so a diff across a year of history over a
 * hundred thousand subjects is not a page load. The subject count is capped and the page reports the
 * cut rather than showing the first few hundred as if they were all of them.
 *
 * Payload values are shown only to an account with the payload permission. A diff names which fields
 * changed for anyone who may see diffs; what they changed FROM and TO is the site's own content, and
 * a user profile field or an unpublished article is exactly the kind of thing a diff viewer would
 * otherwise leak to anyone with report access.
 *
 * @see DiffBuilder
 * @see SubjectDiff
 */
final class DiffController extends StrataControllerBase
{
	/**
	 * The permission that reveals stored values rather than only field names.
	 */
	public const PAYLOADS = 'view strata payloads';

	/**
	 * The diff between two commits.
	 *
	 * @param Request $request
	 *   The request, whose query string can narrow the realm.
	 * @param string|null $from
	 *   The earlier commit, or NULL to use the head's parent.
	 * @param string|null $to
	 *   The later commit, or NULL to use the head.
	 *
	 * @return array<string, mixed>
	 *   The page.
	 */
	public function page(Request $request, ?string $from = null, ?string $to = null): array
	{
		return $this->guard(fn(): array => $this->compare($request, $from, $to));
	}

	/**
	 * The diff, once the engine is known to assemble.
	 *
	 * @param Request $request
	 *   The request, whose query string can narrow the realm.
	 * @param string|null $from
	 *   The earlier commit, or NULL to derive it.
	 * @param string|null $to
	 *   The later commit, or NULL to derive it.
	 *
	 * @return array<string, mixed>
	 *   The render array.
	 */
	private function compare(Request $request, ?string $from, ?string $to): array
	{
		$pair = $this->resolve($from, $to);

		if ($pair === null) {
			return $this->nothing((string) $this->t('There are not yet two commits to compare.'));
		}

		[$earlier, $later] = $pair;
		$realm = Realm::tryFrom((string) $request->query->get('realm', ''));
		$result = $this->engine
			->diffBuilder()
			->between($earlier, $later, DiffBuilder::MAX_SUBJECTS, $realm);

		return [
			'#theme' => 'strata_diff',
			'#from' => Format::address($earlier),
			'#to' => Format::address($later),
			'#subjects' => $this->subjects($result['diffs']),
			'#examined' => Format::count($result['examined']),
			'#truncated' => $result['truncated'],
			'#truncation_note' => (string) $this->t(
				'Only the first @count subjects were examined. Narrow by realm to see the rest.',
				['@count' => DiffBuilder::MAX_SUBJECTS],
			),
			'#payloads' => $this->currentUser()->hasPermission(self::PAYLOADS),
			'#realms' => $this->realmLinks($earlier, $later),
			'#timeline_url' => Url::fromRoute('strata_ui.timeline')->toString(),
		];
	}

	/**
	 * The two commits to compare.
	 *
	 * @param string|null $from
	 *   The earlier commit, or NULL to derive it.
	 * @param string|null $to
	 *   The later commit, or NULL to derive it.
	 *
	 * @return array{string, string}|null
	 *   The pair, or NULL when the history is too short to have one.
	 */
	private function resolve(?string $from, ?string $to): ?array
	{
		if ($from !== null && $to !== null) {
			return [$from, $to];
		}

		$head = $this->engine->commitLog()->head();

		if ($head === null || $head->parent === null) {
			return null;
		}

		// with no pair given, the newest change is the head against the commit before it
		return [$from ?? $head->parent, $to ?? $head->id()];
	}

	/**
	 * Every changed subject, rendered.
	 *
	 * @param list<SubjectDiff> $diffs
	 *   The diffs.
	 *
	 * @return list<array<string, mixed>>
	 *   What a template draws.
	 */
	private function subjects(array $diffs): array
	{
		$subjects = [];

		foreach ($diffs as $diff) {
			$subjects[] = [
				'subject' => $diff->subject,
				'title' => $diff->title(),
				'realm' => $diff->realm?->label() ?? (string) $this->t('Unknown'),
				'mode' => $diff->mode(),
				'status' => $diff->status,
				'readable' => $diff->isReadable(),
				'problem' => $diff->problem,
				'summary' => $diff->summary(),
				'fields' => $this->fields($diff),
			];
		}

		return $subjects;
	}

	/**
	 * The fields one subject changed in.
	 *
	 * @param SubjectDiff $diff
	 *   The subject diff.
	 *
	 * @return list<array<string, mixed>>
	 *   What a template draws.
	 */
	private function fields(SubjectDiff $diff): array
	{
		$fields = [];

		foreach ($diff->fields as $field) {
			$fields[] = [
				'name' => $field->name,
				'status' => $field->status,
				'textual' => $field->isTextual(),
				'changed_lines' => $field->changedLines(),
				'before' => $field->before,
				'after' => $field->after,
				'describe' => $field->describe(),
				'hunks' => $field->hunks,
			];
		}

		return $fields;
	}

	/**
	 * A link narrowing the diff to each realm.
	 *
	 * @param string $from
	 *   The earlier commit.
	 * @param string $to
	 *   The later commit.
	 *
	 * @return list<array<string, mixed>>
	 *   Each realm's label and URL, with an entry for every realm.
	 */
	private function realmLinks(string $from, string $to): array
	{
		$links = [
			[
				'label' => (string) $this->t('Every Realm'),
				'url' => Url::fromRoute('strata_ui.diff.pair', [
					'from' => $from,
					'to' => $to,
				])->toString(),
			],
		];

		foreach (Realm::cases() as $realm) {
			$links[] = [
				'label' => $realm->label(),
				'url' => Url::fromRoute(
					'strata_ui.diff.pair',
					['from' => $from, 'to' => $to],
					['query' => ['realm' => $realm->value]],
				)->toString(),
			];
		}

		return $links;
	}
}
