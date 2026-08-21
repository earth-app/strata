<?php

declare(strict_types=1);

namespace Drupal\strata\Tier;

use Closure;
use Drupal\strata\Storage\ByteRange;
use Drupal\strata\Storage\Capabilities;
use Drupal\strata\Storage\ObjectMeta;
use Drupal\strata\Storage\ObjectPage;
use Drupal\strata\Storage\PutResult;
use Drupal\strata\Storage\StorageProviderInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * One store spread across several buckets.
 *
 * Implements the same provider contract as a single bucket, so nothing above it changes: the object
 * store, the commit log, the segment writer and the ref store all compose the keys they always
 * composed. What changes is only which endpoint a key is served from, and a key's address is not
 * touched by that - a tier is where an object lives, never part of its name, so the same frame in
 * two tiers is one object rather than a copy under a second name.
 *
 * **A write goes to the nearest tier and nowhere else.** Far tiers are written to once per object,
 * by TierMigrator, on a schedule. That is the whole cost argument: a far tier's class-A count is the
 * number of objects that have ever aged into it, not the number of times the site was written to.
 *
 * **A read tries what the placement index knows, then everything else nearest-first.** A known
 * object costs one request. An unknown one costs up to one request per tier and then records what it
 * found, so it is unknown only once - which is also why a dropped placement table costs requests
 * rather than history.
 *
 * **Nothing is ever reconstructed.** An object no tier holds raises, naming every tier that was
 * asked. A head or an exists answers absent only after every tier said absent. The store never
 * reports clean about a question it could not put.
 *
 * **A delete refuses when a tier holding the key cannot be reached.** Deleting from the reachable
 * tiers and forgetting the placement would leave an object in a bucket with no row naming it, which
 * is indistinguishable from a stale index and is exactly the state a prune receipt exists to avoid.
 *
 * @see TierMap
 * @see TierPolicy
 * @see PlacementIndexInterface
 * @see TierMigrator
 */
final class TieredProvider implements StorageProviderInterface, TierStatusInterface
{
	/**
	 * Separator between the tier index and that tier's own cursor in a composite listing cursor.
	 *
	 * A unit separator, because an endpoint's continuation token is opaque and may contain anything
	 * a base64 alphabet does, including every printable character a more obvious delimiter would use.
	 */
	public const CURSOR_SEPARATOR = "\x1f";

	/**
	 * Builds the provider for one tier.
	 *
	 * @var Closure
	 */
	private readonly Closure $resolver;

	/**
	 * Providers already built, keyed by tier index.
	 *
	 * Built on first use, the same way StorageProviderManager defers: a far tier that is
	 * misconfigured must report itself unreachable rather than stopping a flush that never touches
	 * it.
	 *
	 * @var array<int, StorageProviderInterface>
	 */
	private array $built = [];

	/**
	 * Why a tier could not be built, keyed by tier index.
	 *
	 * @var array<int, string>
	 */
	private array $broken = [];

	/**
	 * Constructs a router.
	 *
	 * @param TierPolicy $policy
	 *   Decides where a write goes, which order a read tries, and what a promotion targets.
	 * @param callable(Tier): StorageProviderInterface $resolver
	 *   Builds the provider for one tier. Injected rather than resolved here so the engine keeps
	 *   being the only place that reads settings, and so a test can hand over local directories.
	 * @param PlacementIndexInterface $placement
	 *   Remembers which tier served which key.
	 */
	public function __construct(
		private readonly TierPolicy $policy,
		callable $resolver,
		private readonly PlacementIndexInterface $placement,
	) {
		$this->resolver = Closure::fromCallable($resolver);
	}

	#region Tiers

	/**
	 * The ladder this router applies.
	 *
	 * @return TierMap
	 *   The ladder.
	 */
	public function tiers(): TierMap
	{
		return $this->policy->tiers();
	}

	/**
	 * The placement index this router reads and writes.
	 *
	 * @return PlacementIndexInterface
	 *   The index.
	 */
	public function placement(): PlacementIndexInterface
	{
		return $this->placement;
	}

	/**
	 * The provider for one tier.
	 *
	 * @param int $index
	 *   Tier index.
	 *
	 * @return StorageProviderInterface
	 *   The provider.
	 *
	 * @throws InvalidArgumentException
	 *   When the index is not on the ladder.
	 * @throws RuntimeException
	 *   When the tier cannot be built. The reason is remembered, so a second attempt reports the
	 *   same thing without paying to find out again.
	 */
	public function tier(int $index): StorageProviderInterface
	{
		if (isset($this->built[$index])) {
			return $this->built[$index];
		}
		if (isset($this->broken[$index])) {
			throw new RuntimeException($this->broken[$index]);
		}

		$tier = $this->tiers()->at($index);

		try {
			return $this->built[$index] = ($this->resolver)($tier);
		} catch (Throwable $error) {
			$this->broken[$index] = sprintf(
				'tier "%s" could not be built: %s',
				$tier->name(),
				$error->getMessage(),
			);

			throw new RuntimeException($this->broken[$index], 0, $error);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function tierStatus(): array
	{
		$status = [];

		foreach ($this->tiers()->all() as $tier) {
			try {
				$provider = $this->tier($tier->index);
				$status[$tier->index] = $provider->isReachable()
					? null
					: $provider->unreachableReason() ?? 'the endpoint did not say why';
			} catch (Throwable $error) {
				$status[$tier->index] = $error->getMessage();
			}
		}

		return $status;
	}

	/**
	 * {@inheritdoc}
	 */
	public function tierNames(): array
	{
		return $this->tiers()->names();
	}

	/**
	 * One page of one tier's listing, without merging the others in.
	 *
	 * What TierMigrator and TierPlacementRebuilder work from: both are asking about one bucket, and
	 * the merged listing would hide which bucket an object came from, which is the answer they need.
	 *
	 * @param int $index
	 *   Tier index.
	 * @param string $prefix
	 *   Key prefix.
	 * @param string|null $cursor
	 *   That tier's own continuation token.
	 * @param int $limit
	 *   Most objects to return.
	 *
	 * @return ObjectPage
	 *   The page, with that tier's own cursor.
	 *
	 * @throws InvalidArgumentException
	 *   When the index is not on the ladder.
	 * @throws RuntimeException
	 *   When the tier cannot be built or the listing fails.
	 */
	public function listIn(
		int $index,
		string $prefix = '',
		?string $cursor = null,
		int $limit = 1000,
	): ObjectPage {
		return $this->tier($index)->list($prefix, $cursor, $limit);
	}

	/**
	 * Writes one object into a named tier.
	 *
	 * Only TierMigrator uses this. A write that arrives through the provider contract goes where the
	 * policy says, which is always the nearest tier; this is the one path that puts bytes into a far
	 * one, and it records the placement so the object is findable in one request afterwards.
	 *
	 * @param int $index
	 *   Tier index to write to.
	 * @param string $key
	 *   Object key.
	 * @param string $body
	 *   The bytes.
	 *
	 * @return PutResult
	 *   What was written.
	 *
	 * @throws InvalidArgumentException
	 *   When the index is not on the ladder.
	 * @throws RuntimeException
	 *   When the tier cannot be built or the write fails.
	 */
	public function putIn(int $index, string $key, string $body): PutResult
	{
		$result = $this->tier($index)->put($key, $body);
		$this->placement->place($key, $index, $result->size);

		return $result;
	}

	/**
	 * Reads one object out of a named tier, without falling back to another.
	 *
	 * A verify or a migration asking "is it in THIS bucket" must not be answered by another bucket,
	 * because the answer it wants is precisely which one has it.
	 *
	 * @param int $index
	 *   Tier index.
	 * @param string $key
	 *   Object key.
	 *
	 * @return string
	 *   The bytes.
	 *
	 * @throws InvalidArgumentException
	 *   When the index is not on the ladder.
	 * @throws RuntimeException
	 *   When the tier cannot be built, or the object is not in it.
	 */
	public function getFrom(int $index, string $key): string
	{
		return $this->tier($index)->get($key);
	}

	/**
	 * Removes one object from a named tier.
	 *
	 * @param int $index
	 *   Tier index.
	 * @param string $key
	 *   Object key.
	 *
	 * @return bool
	 *   TRUE when the endpoint accepted the delete.
	 *
	 * @throws InvalidArgumentException
	 *   When the index is not on the ladder.
	 * @throws RuntimeException
	 *   When the tier cannot be built or is unreachable, or the delete fails.
	 */
	public function deleteFrom(int $index, string $key): bool
	{
		$provider = $this->reachableTier($index);
		$accepted = $provider->delete([$key]) > 0;
		$this->placement->displace($key, $index);

		return $accepted;
	}

	#endregion

	#region Identity

	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		return $this->tiers()->nearest()->target->provider;
	}

	/**
	 * {@inheritdoc}
	 */
	public function label(): string
	{
		return sprintf('Tiered storage across %d buckets', $this->tiers()->count());
	}

	/**
	 * {@inheritdoc}
	 *
	 * The nearest tier's capabilities, with one exception. Every write goes to the nearest tier, so
	 * what a write may ask for is what that tier can do; and a range read is honoured here whatever
	 * the serving tier can do, by fetching the object whole and slicing it, so the range flag is
	 * reported as available rather than intersected down to the weakest tier.
	 */
	public function capabilities(): Capabilities
	{
		return $this->tier($this->tiers()->nearest()->index)
			->capabilities()
			->with(['rangeRead' => true]);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Reachable when the nearest tier is, because that is where every write lands. A far tier that
	 * is down does not stop a flush, and reporting the whole store unreachable for it would take a
	 * working site offline over a bucket nothing is currently writing to. TieredProvider::tierStatus()
	 * is what a verify pass reads to find out about the rest.
	 */
	public function isReachable(): bool
	{
		return $this->unreachableReason() === null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function unreachableReason(): ?string
	{
		$nearest = $this->tiers()->nearest();

		try {
			$provider = $this->tier($nearest->index);
		} catch (Throwable $error) {
			return $error->getMessage();
		}

		$reason = $provider->isReachable() ? null : $provider->unreachableReason();

		return $reason === null
			? null
			: sprintf('tier "%s" is unreachable: %s', $nearest->name(), $reason);
	}

	#endregion

	#region Reading

	/**
	 * {@inheritdoc}
	 */
	public function get(string $key, ?ByteRange $range = null): string
	{
		$tried = [];

		foreach ($this->readOrder($key) as $index) {
			try {
				$bytes = $this->rangedGet($index, $key, $range);
			} catch (Throwable $error) {
				$tried[$index] = $error->getMessage();

				continue;
			}

			$this->learn($key, $index);

			return $bytes;
		}

		throw new RuntimeException($this->nowhere($key, $tried));
	}

	/**
	 * {@inheritdoc}
	 */
	public function stream(string $key)
	{
		$tried = [];

		foreach ($this->readOrder($key) as $index) {
			try {
				$stream = $this->tier($index)->stream($key);
			} catch (Throwable $error) {
				$tried[$index] = $error->getMessage();

				continue;
			}

			$this->learn($key, $index);

			return $stream;
		}

		throw new RuntimeException($this->nowhere($key, $tried));
	}

	/**
	 * {@inheritdoc}
	 */
	public function head(string $key): ?ObjectMeta
	{
		foreach ($this->readOrder($key) as $index) {
			try {
				$meta = $this->tier($index)->head($key);
			} catch (Throwable) {
				// an unreachable tier cannot say whether it holds the key, so the next one is asked
				continue;
			}

			if ($meta !== null) {
				$this->learn($key, $index, $meta->size);

				return $meta;
			}
		}

		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function exists(string $key): bool
	{
		foreach ($this->readOrder($key) as $index) {
			try {
				$present = $this->tier($index)->exists($key);
			} catch (Throwable) {
				continue;
			}

			if ($present) {
				$this->learn($key, $index);

				return true;
			}
		}

		return false;
	}

	#endregion

	#region Writing

	/**
	 * {@inheritdoc}
	 */
	public function put(string $key, mixed $body, array $options = []): PutResult
	{
		$index = $this->policy->tierForWrite();
		$result = $this->tier($index)->put($key, $body, $options);

		$this->placement->place($key, $index, $result->size);

		return $result;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Grouped by the tiers that hold each key, so a key in one bucket costs one request there rather
	 * than one in every bucket. A key nothing is recorded for is deleted from every tier, which is
	 * safe because deleting an absent key is not an error, and is what keeps a prune correct while
	 * the placement table is still cold.
	 */
	public function delete(array $keys): int
	{
		$grouped = [];

		foreach ($keys as $key) {
			foreach ($this->deleteTargets($key) as $index) {
				$grouped[$index][] = $key;
			}
		}

		$accepted = 0;

		foreach ($grouped as $index => $batch) {
			$accepted += $this->reachableTier($index)->delete($batch);
		}

		$this->placement->forget(array_values($keys));

		return $accepted;
	}

	#endregion

	#region Listing

	/**
	 * {@inheritdoc}
	 *
	 * Tiers are drained in order and a key is emitted by the nearest tier that holds it, so a
	 * replicated object is named once rather than once per copy. The cursor carries the tier being
	 * drained and that tier's own token, because an endpoint's continuation token is opaque and
	 * cannot be synthesised from a key.
	 *
	 * A page can come back empty with a cursor still set, when the tier being drained had nothing
	 * left under the prefix and a further tier has not been reached yet. Every caller in the engine
	 * pages until the cursor is NULL, which is the contract, so that costs a request rather than a
	 * missed object.
	 */
	public function list(
		string $prefix = '',
		?string $cursor = null,
		int $limit = 1000,
		?string $delimiter = null,
	): ObjectPage {
		[$index, $inner] = $this->parseCursor($cursor);

		if (!$this->tiers()->has($index)) {
			return new ObjectPage();
		}

		$page = $this->tier($index)->list($prefix, $inner, $limit, $delimiter);
		$objects = [];

		foreach ($page->objects as $object) {
			// one lookup answers both questions, so a listing costs one query per object and not two
			$placement = $this->placement->get($object->key);
			$nearest = $placement?->nearest();

			if ($index > 0 && $nearest !== null && $nearest < $index) {
				continue;
			}
			if ($placement === null || !$placement->has($index)) {
				$this->placement->place($object->key, $index, $object->size);
			}

			$objects[] = $object;
		}

		$next =
			$page->cursor === null
				? ($this->tiers()->has($index + 1)
					? $this->composeCursor($index + 1, null)
					: null)
				: $this->composeCursor($index, $page->cursor);

		return new ObjectPage($objects, $next, $page->prefixes);
	}

	#endregion

	#region Routing

	/**
	 * The order tiers are tried in for one key.
	 *
	 * @param string $key
	 *   Object key.
	 *
	 * @return list<int>
	 *   Tier indexes.
	 */
	private function readOrder(string $key): array
	{
		return $this->policy->readOrder($key, $this->placement->get($key));
	}

	/**
	 * The tiers a delete has to reach for one key.
	 *
	 * @param string $key
	 *   Object key.
	 *
	 * @return list<int>
	 *   Tier indexes.
	 */
	private function deleteTargets(string $key): array
	{
		$placement = $this->placement->get($key);

		if ($placement === null || $placement->isEmpty()) {
			return $this->tiers()->indexes();
		}

		return array_values(
			array_filter($placement->tiers(), fn(int $tier): bool => $this->tiers()->has($tier)),
		);
	}

	/**
	 * A ranged read, satisfied here when the serving tier cannot do one itself.
	 *
	 * @param int $index
	 *   Tier index.
	 * @param string $key
	 *   Object key.
	 * @param ByteRange|null $range
	 *   The range, or NULL for the whole object.
	 *
	 * @return string
	 *   The bytes.
	 *
	 * @throws RuntimeException
	 *   When the tier cannot be built, the object is absent, or the object is shorter than the range
	 *   asks for. A short read raises rather than returning what there was, because a truncated
	 *   frame cannot be told apart from a real one downstream.
	 */
	private function rangedGet(int $index, string $key, ?ByteRange $range): string
	{
		$provider = $this->tier($index);

		if ($range === null || $provider->capabilities()->rangeRead) {
			return $provider->get($key, $range);
		}

		$whole = $provider->get($key);
		$slice = substr($whole, $range->offset, $range->length);

		if (strlen($slice) !== $range->length) {
			throw new RuntimeException(
				sprintf(
					'Object %s returned %d bytes for a %d byte range at offset %d',
					$key,
					strlen($slice),
					$range->length,
					$range->offset,
				),
			);
		}

		return $slice;
	}

	/**
	 * The provider for a tier that can be reached, or a refusal.
	 *
	 * @param int $index
	 *   Tier index.
	 *
	 * @return StorageProviderInterface
	 *   The provider.
	 *
	 * @throws RuntimeException
	 *   When the tier cannot be built or is unreachable.
	 */
	private function reachableTier(int $index): StorageProviderInterface
	{
		$provider = $this->tier($index);

		if ($provider->isReachable()) {
			return $provider;
		}

		throw new RuntimeException(
			sprintf(
				'Tier "%s" is unreachable, so what it holds cannot be removed: %s',
				$this->tiers()->at($index)->name(),
				$provider->unreachableReason() ?? 'the endpoint did not say why',
			),
		);
	}

	/**
	 * Records that a tier served a key.
	 *
	 * @param string $key
	 *   Object key.
	 * @param int $index
	 *   Tier index that answered.
	 * @param int $bytes
	 *   The object's size, or zero when the call did not report one.
	 */
	private function learn(string $key, int $index, int $bytes = 0): void
	{
		$placement = $this->placement->get($key);

		if ($placement !== null && $placement->has($index)) {
			return;
		}

		$this->placement->place($key, $index, $bytes);
	}

	/**
	 * The message for an object no tier has.
	 *
	 * Names every tier that was asked and what each said, because the useful question after this is
	 * which bucket was supposed to hold it.
	 *
	 * @param string $key
	 *   Object key.
	 * @param array<int, string> $tried
	 *   Tier index keyed to the reason it did not answer.
	 *
	 * @return string
	 *   The message.
	 */
	private function nowhere(string $key, array $tried): string
	{
		$names = $this->tierNames();
		$lines = [];

		foreach ($tried as $index => $reason) {
			$lines[] = sprintf('%s: %s', $names[$index] ?? $index, $reason);
		}

		return sprintf(
			'Object %s is in none of the %d tiers that were asked (%s)',
			$key,
			count($tried),
			$lines === [] ? 'no tier was asked' : implode('; ', $lines),
		);
	}

	#endregion

	#region Cursors

	/**
	 * Splits a composite cursor.
	 *
	 * @param string|null $cursor
	 *   The cursor, or NULL to start at the nearest tier.
	 *
	 * @return array{0: int, 1: string|null}
	 *   The tier index and that tier's own token.
	 */
	private function parseCursor(?string $cursor): array
	{
		if ($cursor === null || $cursor === '') {
			return [$this->tiers()->nearest()->index, null];
		}

		$at = strpos($cursor, self::CURSOR_SEPARATOR);

		if ($at === false) {
			return [$this->tiers()->nearest()->index, $cursor];
		}

		$inner = substr($cursor, $at + 1);

		return [(int) substr($cursor, 0, $at), $inner === '' ? null : $inner];
	}

	/**
	 * Joins a tier index and its token into one cursor.
	 *
	 * @param int $index
	 *   Tier index.
	 * @param string|null $inner
	 *   That tier's token, or NULL to start it from the beginning.
	 *
	 * @return string
	 *   The composite cursor.
	 */
	private function composeCursor(int $index, ?string $inner): string
	{
		return $index . self::CURSOR_SEPARATOR . ($inner ?? '');
	}

	#endregion
}
