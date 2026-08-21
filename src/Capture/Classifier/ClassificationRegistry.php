<?php

declare(strict_types=1);

namespace Drupal\strata\Capture\Classifier;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * Remembers what each part of the ephemeral keyspace has been decided to be.
 *
 * Rows are keyed by PATTERN, not by key. A site with two million cache entries under `cache_render`
 * gets one row, and the volume is carried as counters on it. Storing a row per key would make the
 * classification table larger than the thing it describes.
 *
 * **A human decision is never overwritten by a rule.** `Heuristics` runs on every discovery pass and
 * would otherwise re-derive its own answer over the top of a person's, silently, every cron. So a
 * row whose source is `human` is left alone by everything except another human. That asymmetry is
 * the whole reason this is stored rather than recomputed.
 *
 * @see Heuristics
 * @see Classification
 * @see KeyspaceDiscovery
 */
final class ClassificationRegistry
{
	/**
	 * The table classifications live in.
	 */
	public const TABLE = 'strata_classification';

	/**
	 * Resolved patterns, keyed by pattern.
	 *
	 * A discovery pass asks about the same pattern once per key it meets, so the answers are held
	 * for the pass rather than re-queried per key.
	 *
	 * @var array<string, array{classification: Classification, source: string}>|null
	 */
	private ?array $resolved = null;

	/**
	 * Constructs a registry.
	 *
	 * @param Connection $database
	 *   The database.
	 */
	public function __construct(private readonly Connection $database) {}

	#region Reading

	/**
	 * What a key is classified as.
	 *
	 * A stored pattern wins over a rule, and the most specific stored pattern wins over a broader
	 * one, so `cache_render*` decided by a human beats `cache*` derived by a rule.
	 *
	 * @param string $key
	 *   The key or namespace.
	 *
	 * @return Classification
	 *   The classification.
	 */
	public function classify(string $key): Classification
	{
		$stored = $this->storedFor($key);

		return $stored === null ? Heuristics::classify($key) : $stored['classification'];
	}

	/**
	 * Whether a key is captured.
	 *
	 * @param string $key
	 *   The key or namespace.
	 *
	 * @return bool
	 *   TRUE when operations on this key are recorded.
	 */
	public function captures(string $key): bool
	{
		return $this->classify($key)->isCaptured();
	}

	/**
	 * Whether a key is written back by a restore.
	 *
	 * @param string $key
	 *   The key or namespace.
	 *
	 * @return bool
	 *   TRUE only when the key is known to be authoritative.
	 */
	public function restores(string $key): bool
	{
		return $this->classify($key)->isRestored();
	}

	/**
	 * Whether a person decided this key's classification.
	 *
	 * @param string $key
	 *   The key or namespace.
	 *
	 * @return bool
	 *   TRUE when a stored row for it carries the human source.
	 */
	public function isDecidedByHuman(string $key): bool
	{
		return ($this->storedFor($key)['source'] ?? null) === Heuristics::HUMAN;
	}

	/**
	 * The most specific stored row covering a key.
	 *
	 * @param string $key
	 *   The key or namespace.
	 *
	 * @return array{classification: Classification, source: string}|null
	 *   The row, or NULL when nothing stored covers it.
	 */
	private function storedFor(string $key): ?array
	{
		$best = null;
		$length = -1;

		foreach ($this->all() as $pattern => $row) {
			if (!Heuristics::matches($pattern, $key)) {
				continue;
			}

			// a longer pattern is a narrower one, so it describes the key more closely
			$specificity = strlen(str_replace('*', '', $pattern));

			if ($specificity > $length) {
				$best = $row;
				$length = $specificity;
			}
		}

		return $best;
	}

	/**
	 * Every stored classification.
	 *
	 * @return array<string, array{classification: Classification, source: string}>
	 *   Pattern keyed to its decision.
	 */
	public function all(): array
	{
		if ($this->resolved !== null) {
			return $this->resolved;
		}

		$rows =
			$this->database
				->select(self::TABLE, 'c')
				->fields('c')
				->execute()
				?->fetchAll(FetchAs::Associative) ?? [];

		$this->resolved = [];

		foreach ($rows as $row) {
			$classification = Classification::tryFrom((string) $row['classification']);

			// a value this release does not know is treated as undecided rather than guessed at
			$this->resolved[(string) $row['pattern']] = [
				'classification' => $classification ?? Classification::UNCLASSIFIED,
				'source' => (string) $row['source'],
			];
		}

		return $this->resolved;
	}

	/**
	 * Patterns still waiting for a human decision, busiest first.
	 *
	 * What the admin UI lists. Ordered by observed volume, because a pattern covering two million
	 * keys is the one worth deciding and a pattern covering three is not.
	 *
	 * @param int $limit
	 *   Most rows to return.
	 *
	 * @return list<array<string, mixed>>
	 *   The rows.
	 */
	public function undecided(int $limit = 50): array
	{
		return $this->database
			->select(self::TABLE, 'c')
			->fields('c')
			->condition('classification', Classification::UNCLASSIFIED->value)
			->orderBy('bytes_seen', 'DESC')
			->orderBy('keys_seen', 'DESC')
			->range(0, max(0, $limit))
			->execute()
			?->fetchAll(FetchAs::Associative) ?? [];
	}

	/**
	 * What the keyspace looks like, per classification.
	 *
	 * @return array<string, array{patterns: int, keys: int, bytes: int}>
	 *   Classification value keyed to its totals, with every classification present even at zero.
	 */
	public function statistics(): array
	{
		$totals = [];

		foreach (Classification::cases() as $case) {
			$totals[$case->value] = ['patterns' => 0, 'keys' => 0, 'bytes' => 0];
		}

		$query = $this->database->select(self::TABLE, 'c');
		$query->addField('c', 'classification');
		$query->addExpression('COUNT(*)', 'patterns');
		$query->addExpression('COALESCE(SUM([c].[keys_seen]), 0)', 'keys');
		$query->addExpression('COALESCE(SUM([c].[bytes_seen]), 0)', 'bytes');
		$query->groupBy('c.classification');

		foreach ($query->execute()?->fetchAll(FetchAs::Associative) ?? [] as $row) {
			$value = (string) $row['classification'];

			if (!isset($totals[$value])) {
				continue;
			}

			$totals[$value] = [
				'patterns' => (int) $row['patterns'],
				'keys' => (int) $row['keys'],
				'bytes' => (int) $row['bytes'],
			];
		}

		return $totals;
	}

	#endregion

	#region Writing

	/**
	 * Records what a discovery pass observed under a pattern.
	 *
	 * The classification is only set when the pattern is new. An existing row keeps whatever it
	 * says, because it may hold a decision, and the counters are replaced rather than added to
	 * because a pass measures the whole keyspace rather than the change since the last one.
	 *
	 * @param string $pattern
	 *   The pattern observed.
	 * @param Classification $classification
	 *   What the heuristics made of it, used only for a pattern not seen before.
	 * @param int $keys
	 *   Keys observed under it.
	 * @param int $bytes
	 *   Bytes observed under it.
	 */
	public function observe(
		string $pattern,
		Classification $classification,
		int $keys,
		int $bytes,
	): void {
		$existing = $this->all()[$pattern] ?? null;

		if ($existing === null) {
			$this->write($pattern, $classification, Heuristics::SOURCE, $keys, $bytes);

			return;
		}

		$this->database
			->update(self::TABLE)
			->fields(['keys_seen' => $keys, 'bytes_seen' => $bytes, 'decided' => time()])
			->condition('pattern', $pattern)
			->execute();
	}

	/**
	 * Records a person's decision about a pattern.
	 *
	 * @param string $pattern
	 *   The pattern.
	 * @param Classification $classification
	 *   What they decided.
	 */
	public function decide(string $pattern, Classification $classification): void
	{
		$existing = $this->all()[$pattern] ?? null;

		$this->write($pattern, $classification, Heuristics::HUMAN, 0, 0, $existing !== null);
	}

	/**
	 * Removes a stored classification, so the heuristics decide again.
	 *
	 * @param string $pattern
	 *   The pattern.
	 *
	 * @return bool
	 *   TRUE when a row was removed.
	 */
	public function forget(string $pattern): bool
	{
		$removed =
			(int) $this->database->delete(self::TABLE)->condition('pattern', $pattern)->execute() >
			0;

		$this->reset();

		return $removed;
	}

	/**
	 * Drops every stored classification.
	 *
	 * @return int
	 *   How many rows were removed.
	 */
	public function clear(): int
	{
		$removed = (int) $this->database->delete(self::TABLE)->execute();
		$this->reset();

		return $removed;
	}

	/**
	 * Forgets the resolved rows.
	 *
	 * Called after a write, and by a long-running command between batches.
	 */
	public function reset(): void
	{
		$this->resolved = null;
	}

	/**
	 * Writes one row.
	 *
	 * @param string $pattern
	 *   The pattern.
	 * @param Classification $classification
	 *   The classification.
	 * @param string $source
	 *   Either Heuristics::SOURCE or Heuristics::HUMAN.
	 * @param int $keys
	 *   Keys observed.
	 * @param int $bytes
	 *   Bytes observed.
	 * @param bool $keepCounters
	 *   TRUE to leave the observed counters as they are, which a decision does: someone choosing a
	 *   classification has not measured anything.
	 */
	private function write(
		string $pattern,
		Classification $classification,
		string $source,
		int $keys,
		int $bytes,
		bool $keepCounters = false,
	): void {
		$fields = [
			'classification' => $classification->value,
			'source' => $source,
			'decided' => time(),
		];

		if (!$keepCounters) {
			$fields['keys_seen'] = $keys;
			$fields['bytes_seen'] = $bytes;
		}

		$this->database->merge(self::TABLE)->key('pattern', $pattern)->fields($fields)->execute();
		$this->reset();
	}

	#endregion
}
