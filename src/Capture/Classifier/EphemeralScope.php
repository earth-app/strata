<?php

declare(strict_types=1);

namespace Drupal\strata\Capture\Classifier;

use Drupal\strata\Capture\CaptureScope;
use Drupal\strata\Journal\Realm;

/**
 * Decides whether one ephemeral key is captured, and whether a restore writes it back.
 *
 * Two questions have to agree before an ephemeral key is recorded: is the realm captured at all, and
 * is this particular key worth capturing. The first is a configuration switch and the second is a
 * classification, and they live in different places for a reason - the switch is read on every
 * mutation and must not touch the database, while the classification is stored and must. This is
 * where the two meet.
 *
 * **On a site with no Redis this answers almost nothing, and that is correct.** Drupal's default
 * queue, semaphore, flood and session backends are TABLES, so the authoritative parts of the
 * ephemeral keyspace are already captured through `Realm::TABLE` by the statement tap and the
 * reconciler. `Realm::EPHEMERAL` exists for the case where a backend is not the database, which in
 * practice means Redis. Capturing a database-backed queue twice - once as a table and once as an
 * ephemeral key - would double its cost and give a restore two disagreeing copies.
 *
 * @see ClassificationRegistry
 * @see CaptureScope
 */
final class EphemeralScope
{
	/**
	 * Constructs a scope.
	 *
	 * @param CaptureScope $scope
	 *   Whether the ephemeral realm is captured at all.
	 * @param ClassificationRegistry $classifications
	 *   What each part of the keyspace has been decided to be.
	 */
	public function __construct(
		private readonly CaptureScope $scope,
		private readonly ClassificationRegistry $classifications,
	) {}

	/**
	 * Whether an ephemeral key is captured.
	 *
	 * @param string $key
	 *   The key, such as "queue:aggregator_feeds" or "cache_render:foo".
	 *
	 * @return bool
	 *   TRUE when the realm is on and the key is not known to be derivable.
	 */
	public function captures(string $key): bool
	{
		return $this->scope->covers(Realm::EPHEMERAL) && $this->classifications->captures($key);
	}

	/**
	 * Whether a restore writes an ephemeral key back.
	 *
	 * Stricter than capture on purpose. A key is stored when nothing has ruled it out, and written
	 * back only when something has ruled it in: writing an unrecognised ephemeral value over a live
	 * one during a restore is help nobody asked for, and the data it describes rebuilds it anyway.
	 *
	 * @param string $key
	 *   The key.
	 *
	 * @return bool
	 *   TRUE only when the key is classified authoritative.
	 */
	public function restores(string $key): bool
	{
		return $this->classifications->restores($key);
	}

	/**
	 * What a key is classified as.
	 *
	 * @param string $key
	 *   The key.
	 *
	 * @return Classification
	 *   The classification.
	 */
	public function classify(string $key): Classification
	{
		return $this->classifications->classify($key);
	}

	/**
	 * Why a key is or is not captured, for the admin UI and a drush command.
	 *
	 * @param string $key
	 *   The key.
	 *
	 * @return string
	 *   A sentence naming the decision and where it came from.
	 */
	public function explain(string $key): string
	{
		if (!$this->scope->covers(Realm::EPHEMERAL)) {
			return 'ephemeral capture is switched off, so no key is captured';
		}

		$classification = $this->classify($key);
		$source = $this->classifications->isDecidedByHuman($key)
			? 'decided here'
			: 'matched by a rule';

		return match ($classification) {
			Classification::AUTHORITATIVE => sprintf(
				'%s is authoritative (%s), so it is captured and restored',
				$key,
				$source,
			),
			Classification::DERIVABLE => sprintf(
				'%s is derivable (%s), so it is neither captured nor restored',
				$key,
				$source,
			),
			Classification::UNCLASSIFIED => sprintf(
				'%s is not classified, so it is captured verbatim and never restored until someone ' .
					'decides',
				$key,
			),
		};
	}
}
