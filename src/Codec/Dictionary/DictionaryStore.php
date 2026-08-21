<?php

declare(strict_types=1);

namespace Drupal\strata\Codec\Dictionary;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Storage\StorageProviderInterface;
use RuntimeException;
use Throwable;

/**
 * Keeps every dictionary version any live frame might need.
 *
 * A dictionary is the one object in the store whose loss is unrecoverable by any other means. A frame
 * compressed against one cannot be opened without it - not partially, not approximately - so a
 * dictionary is never overwritten, never pruned while referenced, and replicated with the frames that
 * name it.
 *
 * Retraining therefore adds a version rather than replacing one. New frames use the new version, old
 * frames keep naming the old one, and both stay until nothing references them.
 *
 * The local table is an index over what the bucket already holds, so an uninstall that drops it loses
 * nothing: `strata:reindex` reads the dictionary objects back.
 *
 * @see DictionaryRef
 * @see DictionaryTrainer
 */
final class DictionaryStore
{
	/**
	 * The table versions are indexed in.
	 */
	public const TABLE = 'strata_dict';

	/**
	 * Dictionary bytes already fetched, keyed by id.
	 *
	 * @var array<string, string>
	 */
	private array $bytes = [];

	/**
	 * References already read, keyed by id.
	 *
	 * @var array<string, DictionaryRef>
	 */
	private array $refs = [];

	/**
	 * Constructs a store.
	 *
	 * @param StorageProviderInterface $provider
	 *   Where dictionary objects live.
	 * @param Connection $database
	 *   The connection holding the index.
	 */
	public function __construct(
		private readonly StorageProviderInterface $provider,
		private readonly Connection $database,
	) {}

	#region Writing

	/**
	 * Stores a newly trained dictionary as the next version of its realm.
	 *
	 * @param string $realm
	 *   Realm value.
	 * @param string $bytes
	 *   The dictionary.
	 * @param string $source
	 *   Either DictionaryRef::RAW or DictionaryRef::TRAINED.
	 * @param float $ratio
	 *   The ratio it measured on the samples it was scored against.
	 * @param int $samples
	 *   How many samples it was built from.
	 *
	 * @return DictionaryRef
	 *   The stored reference.
	 *
	 * @throws RuntimeException
	 *   When the dictionary is empty or cannot be written.
	 */
	public function store(
		string $realm,
		string $bytes,
		string $source = DictionaryRef::RAW,
		float $ratio = 1.0,
		int $samples = 0,
	): DictionaryRef {
		if ($bytes === '') {
			throw new RuntimeException('An empty dictionary would decode nothing');
		}

		$ref = new DictionaryRef(
			$realm,
			$this->nextVersion($realm),
			Hash::of($bytes),
			strlen($bytes),
			$source,
			$ratio,
			$samples,
			time(),
		);

		try {
			$this->provider->put($ref->key(), $bytes);
		} catch (Throwable $error) {
			throw new RuntimeException(
				sprintf('Could not store dictionary %s: %s', $ref->id(), $error->getMessage()),
				0,
				$error,
			);
		}

		$this->record($ref);

		$this->bytes[$ref->id()] = $bytes;
		$this->refs[$ref->id()] = $ref;

		return $ref;
	}

	/**
	 * Writes a reference into the local index.
	 *
	 * Public so a reindex can rebuild the index from the objects in the bucket.
	 *
	 * @param DictionaryRef $ref
	 *   The reference.
	 */
	public function record(DictionaryRef $ref): void
	{
		$this->database
			->merge(self::TABLE)
			->key('id', $ref->id())
			->fields([
				'realm' => $ref->realm,
				'version' => $ref->version,
				'address' => $ref->address,
				'bytes' => $ref->bytes,
				'source' => $ref->source,
				'ratio' => $ref->ratio,
				'samples' => $ref->samples,
				'trained_at' => $ref->trainedAt,
			])
			->execute();

		$this->refs[$ref->id()] = $ref;
	}

	#endregion

	#region Reading

	/**
	 * The bytes of one dictionary version.
	 *
	 * @param string $id
	 *   The id a frame recorded.
	 *
	 * @return string|null
	 *   The dictionary, or NULL when the id names nothing this store holds.
	 *
	 * @throws RuntimeException
	 *   When the object is indexed but absent, or its bytes do not hash to the recorded address. A
	 *   dictionary that decodes to something else would open every frame that names it into garbage,
	 *   so this refuses rather than returning it.
	 */
	public function get(string $id): ?string
	{
		if (isset($this->bytes[$id])) {
			return $this->bytes[$id];
		}

		$ref = $this->ref($id);

		if ($ref === null) {
			return null;
		}

		try {
			$bytes = $this->provider->get($ref->key());
		} catch (Throwable $error) {
			throw new RuntimeException(
				sprintf(
					'Dictionary %s is indexed but could not be read: %s',
					$id,
					$error->getMessage(),
				),
				0,
				$error,
			);
		}

		if (!Hash::equals(Hash::of($bytes), $ref->address)) {
			throw new RuntimeException(
				sprintf('Dictionary %s does not match the address it is filed under', $id),
			);
		}

		return $this->bytes[$id] = $bytes;
	}

	/**
	 * One version's reference.
	 *
	 * @param string $id
	 *   The id.
	 *
	 * @return DictionaryRef|null
	 *   The reference, or NULL when nothing is indexed under it.
	 */
	public function ref(string $id): ?DictionaryRef
	{
		if (isset($this->refs[$id])) {
			return $this->refs[$id];
		}

		$row =
			$this->database
				->select(self::TABLE, 'd')
				->fields('d')
				->condition('id', $id)
				->execute()
				?->fetchAssoc() ?:
			null;

		return $row === null ? null : ($this->refs[$id] = $this->hydrate($row));
	}

	/**
	 * The newest version for a realm.
	 *
	 * What a flush and a compaction pass compress against.
	 *
	 * @param string $realm
	 *   Realm value.
	 *
	 * @return DictionaryRef|null
	 *   The reference, or NULL when the realm has no dictionary yet.
	 */
	public function latest(string $realm): ?DictionaryRef
	{
		$row =
			$this->database
				->select(self::TABLE, 'd')
				->fields('d')
				->condition('realm', $realm)
				->orderBy('version', 'DESC')
				->range(0, 1)
				->execute()
				?->fetchAssoc() ?:
			null;

		return $row === null ? null : $this->hydrate($row);
	}

	/**
	 * Every version this store knows about.
	 *
	 * @return array<string, DictionaryRef>
	 *   Id keyed to reference, newest first within each realm.
	 */
	public function all(): array
	{
		$rows =
			$this->database
				->select(self::TABLE, 'd')
				->fields('d')
				->orderBy('realm')
				->orderBy('version', 'DESC')
				->execute()
				?->fetchAll(FetchAs::Associative) ?? [];

		$refs = [];

		foreach ($rows as $row) {
			$ref = $this->hydrate($row);
			$refs[$ref->id()] = $ref;
		}

		return $refs;
	}

	/**
	 * Ids of every version, for a reachability walk.
	 *
	 * @return list<string>
	 *   The ids.
	 */
	public function ids(): array
	{
		return array_keys($this->all());
	}

	/**
	 * How many versions a realm has.
	 *
	 * @param string $realm
	 *   Realm value.
	 *
	 * @return int
	 *   The count.
	 */
	public function versions(string $realm): int
	{
		return (int) $this->database
			->select(self::TABLE, 'd')
			->condition('realm', $realm)
			->countQuery()
			->execute()
			?->fetchField();
	}

	/**
	 * Removes one version from the index and the store.
	 *
	 * Only ever called with a version a reachability walk has proved nothing references. There is no
	 * safe way to check that here, which is why this takes no decision of its own.
	 *
	 * @param string $id
	 *   The id.
	 *
	 * @return bool
	 *   TRUE when a row was removed.
	 */
	public function forget(string $id): bool
	{
		$ref = $this->ref($id);

		if ($ref === null) {
			return false;
		}

		$this->provider->delete([$ref->key()]);
		$this->database->delete(self::TABLE)->condition('id', $id)->execute();

		unset($this->refs[$id], $this->bytes[$id]);

		return true;
	}

	/**
	 * Forgets what has been fetched, keeping the objects.
	 */
	public function flushCache(): void
	{
		$this->bytes = [];
		$this->refs = [];
	}

	#endregion

	/**
	 * The version number a realm's next dictionary gets.
	 *
	 * @param string $realm
	 *   Realm value.
	 *
	 * @return int
	 *   One more than the newest, or one when the realm has none.
	 */
	private function nextVersion(string $realm): int
	{
		return ($this->latest($realm)->version ?? 0) + 1;
	}

	/**
	 * A reference from an index row.
	 *
	 * @param array<string, mixed> $row
	 *   The row.
	 *
	 * @return DictionaryRef
	 *   The reference.
	 */
	private function hydrate(array $row): DictionaryRef
	{
		return new DictionaryRef(
			(string) $row['realm'],
			(int) $row['version'],
			(string) $row['address'],
			(int) $row['bytes'],
			(string) $row['source'],
			(float) $row['ratio'],
			(int) $row['samples'],
			(int) $row['trained_at'],
		);
	}
}
