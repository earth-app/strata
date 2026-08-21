<?php

declare(strict_types=1);

namespace Drupal\strata;

use Drupal\Component\Datetime\Time;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\strata\Access\RestoreAccess;
use Drupal\strata\Anomaly\AnomalyDetector;
use Drupal\strata\Anomaly\MetricSampler;
use Drupal\strata\Archive\ArchiveExporter;
use Drupal\strata\Archive\ArchiveImporter;
use Drupal\strata\Diff\DiffBuilder;
use Drupal\strata\Drill\DrillIndex;
use Drupal\strata\Drill\DrillRunner;
use Drupal\strata\Estimate\PriceTable;
use Drupal\strata\Event\Notifier;
use Drupal\strata\Explore\StorageExplorer;
use Drupal\strata\Metrics\SeriesBuilder;
use Drupal\strata\Telemetry\OtlpExporter;
use Drupal\strata\Telemetry\OtlpPayload;
use Drupal\strata\Telemetry\TelemetryPass;
use Drupal\strata\Telemetry\Tracer;
use Drupal\strata\Timeline\TimelineQuery;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use Drupal\strata\Capture\CaptureScope;
use Drupal\strata\Capture\Classifier\ClassificationRegistry;
use Drupal\strata\Capture\Classifier\DatabaseKeyspaceSource;
use Drupal\strata\Capture\Classifier\KeyspaceDiscovery;
use Drupal\strata\Capture\Classifier\KeyspaceSourceInterface;
use Drupal\strata\Capture\Reconciler;
use Drupal\strata\Cas\FrameIndexInterface;
use Drupal\strata\Cas\Framer;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Cas\Packer;
use Drupal\strata\Code\CodeCapture;
use Drupal\strata\Code\CodeScanner;
use Drupal\strata\Code\VendorDriftDetector;
use Drupal\strata\Codec\CodecRegistry;
use Drupal\strata\Codec\Dictionary\DictionaryPass;
use Drupal\strata\Codec\Dictionary\DictionaryRef;
use Drupal\strata\Codec\Dictionary\DictionaryStore;
use Drupal\strata\Codec\Dictionary\DictionaryTrainer;
use Drupal\strata\Compaction\Compactor;
use Drupal\strata\Compaction\LevelPolicy;
use Drupal\strata\Compaction\Reachability;
use Drupal\strata\Compaction\Recompressor;
use Drupal\strata\Compaction\Rollup;
use Drupal\strata\Crypto\CipherInterface;
use Drupal\strata\Delta\ChainDepthPolicy;
use Drupal\strata\Delta\Reanchorer;
use Drupal\strata\Crypto\KeyProviderInterface;
use Drupal\strata\Crypto\KeyRing;
use Drupal\strata\Crypto\KeyRotation;
use Drupal\strata\Crypto\NullCipher;
use Drupal\strata\Crypto\RotatingCipher;
use Drupal\strata\Crypto\StaticKeyProvider;
use Drupal\strata\Crypto\XChaCha20Poly1305Cipher;
use Drupal\strata\File\BlockSplitter;
use Drupal\strata\File\FileCapture;
use Drupal\strata\File\MediaStore;
use Drupal\strata\File\ShiftDetector;
use Drupal\strata\File\StorageClassPolicy;
use Drupal\strata\Flush\Flusher;
use Drupal\strata\Flush\Lease;
use Drupal\strata\Health\HealthLedgerInterface;
use Drupal\strata\Health\TripwireRegistry;
use Drupal\strata\Journal\FlushPolicy;
use Drupal\strata\Journal\JournalInterface;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Restore\LogicalRestore;
use Drupal\strata\Restore\PhysicalRestore;
use Drupal\strata\Restore\Plugin\Strata\Restore\ShadowSwapStrategy;
use Drupal\strata\Restore\Plugin\Strata\Restore\TruncateRestoreStrategy;
use Drupal\strata\Restore\Preflight;
use Drupal\strata\Restore\Replayer;
use Drupal\strata\Restore\RestoreAudit;
use Drupal\strata\Restore\RestoreStrategyInterface;
use Drupal\strata\Segment\SegmentReader;
use Drupal\strata\Site\SiteContext;
use Drupal\strata\Site\SiteScopedProvider;
use Drupal\strata\Segment\SegmentWriter;
use Drupal\strata\Storage\Plugin\Strata\Storage\LocalStorage;
use Drupal\strata\Storage\Plugin\Strata\Storage\NullStorage;
use Drupal\strata\Storage\ProviderStats;
use Drupal\strata\Storage\ProviderStatStore;
use Drupal\strata\Storage\RecordingProvider;
use Drupal\strata\Storage\StorageProviderInterface;
use Drupal\strata\Storage\StorageProviderManager;
use Drupal\strata\Storage\TierProviderFactoryInterface;
use Drupal\strata\Tier\DatabasePlacementIndex;
use Drupal\strata\Tier\PlacementIndexInterface;
use Drupal\strata\Tier\Tier;
use Drupal\strata\Tier\TieredProvider;
use Drupal\strata\Tier\TierMap;
use Drupal\strata\Tier\TierMigrator;
use Drupal\strata\Tier\TierPlacementRebuilder;
use Drupal\strata\Tier\TierPolicy;
use Drupal\strata\Tier\TierRequirements;
use Drupal\strata\Tree\CommitIndex;
use Drupal\strata\Tree\CommitLog;
use Drupal\strata\Tree\RefStore;
use Drupal\strata\Tree\BasePolicy;
use Drupal\strata\Tree\BaseReader;
use Drupal\strata\Tree\BaseWriter;
use Drupal\strata\Tree\SubjectIndex;
use Drupal\strata\Verify\Reindexer;
use Drupal\strata\Verify\Verifier;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Assembles the pipeline from configuration.
 *
 * Every part of the engine is independently constructible and independently tested; this is the one
 * place that reads settings and decides which parts a given site gets.
 *
 * Nothing is built until it is asked for, and each piece is built once. A request that captures but
 * never flushes therefore never constructs a storage provider or a cipher.
 */
final class Engine
{
	/**
	 * The storage provider, once resolved.
	 */
	private ?StorageProviderInterface $provider = null;

	/**
	 * The tiered router, once resolved, or NULL when the site writes to one bucket.
	 */
	private ?TieredProvider $tiers = null;

	/**
	 * What the store has been asked to do this process.
	 *
	 * Deliberately not cleared by Engine::reset(): a settings change does not un-issue the requests
	 * that were already made, and the same accumulator is handed to the provider built afterwards.
	 */
	private ?ProviderStats $providerStats = null;

	/**
	 * What each tier has been asked to do this process, keyed by tier name.
	 *
	 * Not cleared by Engine::reset(), for the reason the total is not.
	 *
	 * @var array<string, ProviderStats>
	 */
	private array $tierStats = [];

	/**
	 * The placement index, once built.
	 */
	private ?PlacementIndexInterface $placementIndex = null;

	/**
	 * The per-day record of store traffic, once built.
	 */
	private ?ProviderStatStore $providerStatStore = null;

	/**
	 * The cipher, once resolved.
	 */
	private ?CipherInterface $cipher = null;

	/**
	 * The codec registry, once built.
	 */
	private ?CodecRegistry $codecs = null;

	/**
	 * The object store, once built.
	 */
	private ?ObjectStore $store = null;

	/**
	 * The dictionary store, once built.
	 */
	private ?DictionaryStore $dictionaryStore = null;

	/**
	 * The telemetry exporter, once built.
	 */
	private ?OtlpExporter $telemetry = null;

	/**
	 * The tracer, once built.
	 *
	 * Held rather than rebuilt because spans accumulate in it, and a second tracer would start with
	 * an empty buffer and export half the request.
	 */
	private ?Tracer $tracer = null;

	/**
	 * Keyspace sources contributed by submodules.
	 *
	 * @var list<KeyspaceSourceInterface>
	 */
	private array $keyspaceSources = [];

	/**
	 * Restore strategies contributed by submodules.
	 *
	 * @var list<RestoreStrategyInterface>
	 */
	private array $restoreStrategies = [];

	/**
	 * Constructs an engine.
	 *
	 * @param ConfigFactoryInterface $configFactory
	 *   Where settings are read from.
	 * @param JournalInterface $journal
	 *   The journal a flush drains.
	 * @param FrameIndexInterface $index
	 *   The deduplication index.
	 * @param CommitIndex $commitIndex
	 *   The local index over the commit log.
	 * @param HealthLedgerInterface $ledger
	 *   Where a verify pass records what it found.
	 * @param Lease $lease
	 *   Keeps two flushes from overlapping.
	 * @param LoggerInterface $logger
	 *   Records what a flush did.
	 * @param RestoreAudit $audit
	 *   Where a restore records who did what.
	 * @param EntityTypeManagerInterface $entityTypeManager
	 *   Loads and saves the entities a logical restore writes into.
	 * @param StateInterface $state
	 *   Where a logical restore writes state values back.
	 * @param KeyValueFactoryInterface $keyValue
	 *   Where a logical restore writes key-value entries back.
	 * @param Connection $database
	 *   The database the reconciler inspects.
	 * @param CaptureScope $scope
	 *   Decides which tables the reconciler watches.
	 * @param ClassificationRegistry $classifications
	 *   Holds what each part of the ephemeral keyspace has been decided to be.
	 * @param SiteContext $site
	 *   Which site's history this engine reads and writes, so several can share one bucket.
	 * @param AccountProxyInterface $currentUser
	 *   Attributes a restore to whoever ran it.
	 * @param Notifier $notifier
	 *   Announces what the pipeline did.
	 * @param TimeInterface $time
	 *   The clock an archive and a drill are stamped from.
	 * @param KeyRepositoryInterface|null $keys
	 *   Supplies the encryption key, or NULL when the key module is absent.
	 * @param StorageProviderManager|null $providers
	 *   Providers contributed by submodules, or NULL to use only the built-in ones.
	 * @param ClientInterface|null $http
	 *   The client telemetry is exported with, or NULL to build one.
	 */
	public function __construct(
		private readonly ConfigFactoryInterface $configFactory,
		private readonly JournalInterface $journal,
		private readonly FrameIndexInterface $index,
		private readonly CommitIndex $commitIndex,
		private readonly HealthLedgerInterface $ledger,
		private readonly Lease $lease,
		private readonly LoggerInterface $logger,
		private readonly RestoreAudit $audit,
		private readonly EntityTypeManagerInterface $entityTypeManager,
		private readonly StateInterface $state,
		private readonly KeyValueFactoryInterface $keyValue,
		private readonly Connection $database,
		private readonly CaptureScope $scope,
		private readonly ClassificationRegistry $classifications,
		private readonly SiteContext $site,
		private readonly AccountProxyInterface $currentUser,
		private readonly Notifier $notifier = new Notifier(),
		private readonly TimeInterface $time = new Time(),
		private readonly ?KeyRepositoryInterface $keys = null,
		private readonly ?StorageProviderManager $providers = null,
		private readonly ?ClientInterface $http = null,
	) {}

	/**
	 * The configured storage provider.
	 *
	 * @return StorageProviderInterface
	 *   The provider.
	 *
	 * @throws RuntimeException
	 *   When the configured provider is not registered, or the local provider has no directory.
	 */
	public function provider(): StorageProviderInterface
	{
		if ($this->provider !== null) {
			return $this->provider;
		}

		$map = $this->tierMap();

		if ($map === null) {
			// counting sits under the site prefix, so a cross-site listing is billed like any other
			$recorded = new RecordingProvider(
				$this->buildProvider((string) $this->settings()->get('provider'), null),
				$this->providerStats(),
			);

			// every site in a bucket writes under its own prefix, so one cannot read another's history
			return $this->provider = new SiteScopedProvider($recorded, $this->site);
		}

		$this->tiers = new TieredProvider(
			new TierPolicy($map),
			fn(Tier $tier): StorageProviderInterface => $this->buildTier($tier),
			$this->placementIndex(),
		);

		// the router is inside the site prefix, so a placement row names the key the bucket holds
		return $this->provider = new SiteScopedProvider($this->tiers, $this->site);
	}

	/**
	 * The tiered router, when the site spreads history across several buckets.
	 *
	 * @return TieredProvider|null
	 *   The router, or NULL when the site writes to one bucket. Built by Engine::provider(), so
	 *   asking for it resolves the provider first.
	 */
	public function tiers(): ?TieredProvider
	{
		$this->provider();

		return $this->tiers;
	}

	/**
	 * The storage ladder from configuration.
	 *
	 * @return TierMap|null
	 *   The ladder, or NULL when tiering is off or fewer than two tiers are configured. One tier is
	 *   what an untiered site already does, so it stays on the single-provider path.
	 *
	 * @throws RuntimeException
	 *   When the configured ladder is not one this can honour - a repeated bucket, a threshold that
	 *   does not increase, a tier naming no provider. Refused here rather than at the first flush.
	 */
	public function tierMap(): ?TierMap
	{
		/** @var array<int, array<string, mixed>> $levels */
		$levels = $this->settings()->get('tiers.levels') ?? [];

		try {
			return TierMap::fromSettings((bool) $this->settings()->get('tiers.enabled'), $levels);
		} catch (InvalidArgumentException $error) {
			throw new RuntimeException(
				sprintf('The storage ladder is not usable: %s', $error->getMessage()),
				0,
				$error,
			);
		}
	}

	/**
	 * Where each object lives.
	 *
	 * @return PlacementIndexInterface
	 *   The index.
	 */
	public function placementIndex(): PlacementIndexInterface
	{
		return $this->placementIndex ??= new DatabasePlacementIndex($this->database);
	}

	/**
	 * A migration pass that moves aged objects into the tier they belong in.
	 *
	 * @return TierMigrator|null
	 *   The pass, or NULL when the site writes to one bucket.
	 */
	public function tierMigrator(): ?TierMigrator
	{
		$router = $this->tiers();

		if ($router === null) {
			return null;
		}

		return new TierMigrator(
			$router,
			new TierPolicy($router->tiers()),
			$this->index,
			$this->site,
			$this->time,
			$this->logger,
			$this->settings()->get('tiers.verify_copies') !== false,
		);
	}

	/**
	 * A pass that rebuilds the placement index by listing every tier.
	 *
	 * @return TierPlacementRebuilder|null
	 *   The pass, or NULL when the site writes to one bucket.
	 */
	public function tierPlacementRebuilder(): ?TierPlacementRebuilder
	{
		$router = $this->tiers();

		return $router === null
			? null
			: new TierPlacementRebuilder($router, $this->placementIndex(), $this->logger);
	}

	/**
	 * A pass that answers which tiers a restore would need.
	 *
	 * @return TierRequirements|null
	 *   The pass, or NULL when the site writes to one bucket and every restore needs it.
	 */
	public function tierRequirements(): ?TierRequirements
	{
		$router = $this->tiers();

		if ($router === null) {
			return null;
		}

		return new TierRequirements(
			$router,
			$this->placementIndex(),
			$this->commitLog(),
			$this->baseReader(),
			$this->index,
			$this->site,
		);
	}

	/**
	 * The provider one tier writes to, with its own request counter.
	 *
	 * Counted twice on purpose. The inner accumulator is this tier's own bill, which is what makes a
	 * far tier's promise of few requests a measurement rather than a claim; the outer is the process
	 * total, which is what the budget guard and the telemetry pass read. Counting only the total
	 * would leave the per-tier figures estimated, and counting only per tier would leave the guard
	 * with nothing.
	 *
	 * @param Tier $tier
	 *   The tier.
	 *
	 * @return StorageProviderInterface
	 *   The provider.
	 *
	 * @throws RuntimeException
	 *   When the tier's provider is not registered, or cannot address its own location.
	 */
	private function buildTier(Tier $tier): StorageProviderInterface
	{
		$stats = $this->tierStats[$tier->name()] ??= new ProviderStats();

		return new RecordingProvider(
			new RecordingProvider(
				$this->buildProvider($tier->target->provider, $tier),
				$this->providerStats(),
			),
			$stats,
		);
	}

	/**
	 * One raw storage provider, optionally pointed at a tier's own location.
	 *
	 * @param string $id
	 *   Provider id from configuration.
	 * @param Tier|null $tier
	 *   The tier to build for, or NULL for the site's single configured destination.
	 *
	 * @return StorageProviderInterface
	 *   The provider.
	 *
	 * @throws RuntimeException
	 *   When the id is not registered, the local provider has no directory, or the tier names a
	 *   location the provider's factory cannot address.
	 */
	private function buildProvider(string $id, ?Tier $tier): StorageProviderInterface
	{
		if ($tier !== null && $tier->target->isRelocated()) {
			return $this->relocated($id, $tier);
		}

		if ($this->providers !== null && $this->providers->has($id)) {
			return $this->providers->get($id);
		}

		return match ($id) {
			'local' => new LocalStorage($this->localPath()),
			'null' => new NullStorage(),
			default => throw new RuntimeException(
				sprintf('Storage provider "%s" is configured but not registered', $id),
			),
		};
	}

	/**
	 * A provider pointed at a tier's own bucket or directory.
	 *
	 * A provider whose factory cannot take a second location is refused rather than quietly built at
	 * the first one. Two tiers sharing a bucket would make a move a delete and a replica a claim
	 * about durability that is not true, and the ladder's own validation cannot catch it because the
	 * locations differ on paper.
	 *
	 * @param string $id
	 *   Provider id.
	 * @param Tier $tier
	 *   The tier.
	 *
	 * @return StorageProviderInterface
	 *   The provider.
	 *
	 * @throws RuntimeException
	 *   When the provider cannot be pointed at the tier's location.
	 */
	private function relocated(string $id, Tier $tier): StorageProviderInterface
	{
		if ($id === 'local') {
			return new LocalStorage($tier->target->location);
		}
		if ($id === 'null') {
			return new NullStorage();
		}

		$factory = $this->providers?->factory($id);

		if ($factory instanceof TierProviderFactoryInterface) {
			return $factory->createFor($tier->target);
		}

		throw new RuntimeException(
			sprintf(
				'Tier "%s" wants provider "%s" at %s, and that provider cannot be pointed at a second location',
				$tier->name(),
				$id,
				$tier->target->location,
			),
		);
	}

	/**
	 * What the store has been asked to do so far this process.
	 *
	 * One accumulator per engine, shared by every part that reaches the store, so a flush and the
	 * verify that follows it appear on the same bill. Engine::persistProviderStats() moves it into
	 * ProviderStatStore, which is the only place the figures survive the process.
	 *
	 * @return ProviderStats
	 *   The accumulator.
	 */
	public function providerStats(): ProviderStats
	{
		return $this->providerStats ??= new ProviderStats();
	}

	/**
	 * The per-day record of what every store was asked to do.
	 *
	 * @return ProviderStatStore
	 *   The store.
	 */
	public function providerStatStore(): ProviderStatStore
	{
		return $this->providerStatStore ??= new ProviderStatStore($this->database, $this->time);
	}

	/**
	 * Moves this process's request counts into the per-day record and starts a new window.
	 *
	 * Safe to call when nothing has touched the store: an empty accumulator writes no row, so a
	 * request that captured but never flushed costs no query. Called on terminate, so the figures a
	 * report reads include the flush that just happened rather than the one before it.
	 *
	 * On a tiered store each tier is filed under its own name as well, which is what makes "the far
	 * buckets are barely touched" a figure an operator can read rather than a design claim. The total
	 * is still filed under the provider id, so a report that never heard of tiers keeps working.
	 *
	 * @return int
	 *   How many operation rows were written.
	 */
	public function persistProviderStats(): int
	{
		$store = $this->providerStatStore();
		$written = 0;

		foreach ($this->tierStats as $name => $window) {
			if ($window->operations() === 0) {
				continue;
			}

			$written += $store->record($name, $window);
			$window->reset();
		}

		$stats = $this->providerStats();

		if ($stats->operations() === 0) {
			return $written;
		}

		$written += $store->record((string) $this->settings()->get('provider'), $stats);
		$stats->reset();

		return $written;
	}

	/**
	 * What each tier has been asked to do so far this process.
	 *
	 * @return array<string, ProviderStats>
	 *   Tier name keyed to its accumulator, empty until a tiered provider has been built.
	 */
	public function tierStats(): array
	{
		return $this->tierStats;
	}

	/**
	 * The deduplication index.
	 *
	 * Read by the pages that report what the store holds.
	 *
	 * @return FrameIndexInterface
	 *   The index.
	 */
	public function frameIndex(): FrameIndexInterface
	{
		return $this->index;
	}

	/**
	 * The journal a flush drains.
	 *
	 * Read by the status panels, which report what is captured and not yet sealed.
	 *
	 * @return JournalInterface
	 *   The journal.
	 */
	public function journal(): JournalInterface
	{
		return $this->journal;
	}

	/**
	 * The local index over the commit log.
	 *
	 * Read by the pages that show a commit without needing to fetch it from the bucket.
	 *
	 * @return CommitIndex
	 *   The index.
	 */
	public function commitIndex(): CommitIndex
	{
		return $this->commitIndex;
	}

	/**
	 * The health ledger.
	 *
	 * Read by the dashboard and by a repair action, both of which need the ledger rather than anything
	 * that writes to it.
	 *
	 * @return HealthLedgerInterface
	 *   The ledger.
	 */
	public function ledger(): HealthLedgerInterface
	{
		return $this->ledger;
	}

	/**
	 * The provider ids a submodule has contributed.
	 *
	 * Read by the settings form, which offers the two built-in providers plus whatever is registered.
	 *
	 * @return list<string>
	 *   The ids, empty when no submodule contributes one.
	 */
	public function providerIds(): array
	{
		return $this->providers?->ids() ?? [];
	}

	/**
	 * The site whose history this engine reads and writes.
	 *
	 * @return SiteContext
	 *   The context.
	 */
	public function site(): SiteContext
	{
		return $this->site;
	}

	/**
	 * The configured cipher.
	 *
	 * A site with encryption on and no key configured is refused rather than silently storing
	 * plaintext. The bucket would look like a working backup and would not be one.
	 *
	 * @return CipherInterface
	 *   The cipher.
	 *
	 * @throws RuntimeException
	 *   When encryption is on and no usable key is configured.
	 */
	public function cipher(): CipherInterface
	{
		if ($this->cipher !== null) {
			return $this->cipher;
		}

		if ((string) $this->settings()->get('cipher.id') === 'none') {
			return $this->cipher = new NullCipher();
		}

		$ring = $this->keyRing();

		// a ring with retired keys has to try each one, so the plain cipher is used until it does
		return $this->cipher = $ring->isRotating()
			? new RotatingCipher($ring)
			: new XChaCha20Poly1305Cipher($ring->active()->key());
	}

	/**
	 * The keys frames are sealed and opened with.
	 *
	 * @return KeyRing
	 *   The active key plus any retired keys still configured.
	 *
	 * @throws RuntimeException
	 *   When no active key is configured, or one is the wrong length.
	 */
	public function keyRing(): KeyRing
	{
		/** @var list<string> $retiredIds */
		$retiredIds = $this->settings()->get('retired_keys') ?? [];
		$retired = [];

		foreach ($retiredIds as $id) {
			$provider = $this->keyProviderFor(trim((string) $id));

			if ($provider !== null) {
				$retired[] = $provider;
			}
		}

		return new KeyRing($this->keyProvider(), $retired);
	}

	/**
	 * A rotation pass over the frames still sealed under a retired key.
	 *
	 * @return KeyRotation
	 *   The pass.
	 *
	 * @throws RuntimeException
	 *   When encryption is off, since there is then nothing to rotate.
	 */
	public function keyRotation(): KeyRotation
	{
		$cipher = $this->cipher();

		if (!($cipher instanceof RotatingCipher)) {
			$cipher = new RotatingCipher($this->keyRing());
		}

		return new KeyRotation(
			$cipher,
			$this->objectStore(),
			$this->recompressor(),
			$this->index,
			$this->ledger,
			$this->logger,
		);
	}

	/**
	 * The codec registry.
	 *
	 * @return CodecRegistry
	 *   The registry, holding every codec this release ships.
	 */
	public function codecs(): CodecRegistry
	{
		return $this->codecs ??= CodecRegistry::withShippedCodecs()->prefer(
			(string) $this->settings()->get('codec.id'),
		);
	}

	/**
	 * The object store payloads go through.
	 *
	 * @return ObjectStore
	 *   The store.
	 */
	public function objectStore(): ObjectStore
	{
		$dictionaries = $this->dictionaries();
		$active = $this->activeDictionary();

		return $this->store ??= new ObjectStore(
			$this->provider(),
			$this->index,
			$this->codecs(),
			$this->cipher(),
			new Framer((int) $this->settings()->get('frame.size')),
			new Packer((int) $this->settings()->get('frame.pack_target')),
			$this->level('flush_level'),
			$active === null ? null : $dictionaries->get($active->id()),
			$active?->id(),
			$dictionaries,
			$this->chainDepthPolicy(),
		);
	}

	/**
	 * The delta chain cap from configuration.
	 *
	 * @return ChainDepthPolicy|null
	 *   The policy, or NULL when delta coding is switched off, which stores every frame standalone.
	 */
	public function chainDepthPolicy(): ?ChainDepthPolicy
	{
		if ($this->settings()->get('delta.enabled') !== true) {
			return null;
		}

		return new ChainDepthPolicy(
			(int) ($this->settings()->get('delta.max_depth') ??
				ChainDepthPolicy::DEFAULT_MAX_DEPTH),
		);
	}

	/**
	 * A re-anchoring pass over the chains in the index.
	 *
	 * @return Reanchorer
	 *   The pass.
	 */
	public function reanchorer(): Reanchorer
	{
		return new Reanchorer(
			$this->index,
			$this->objectStore(),
			$this->chainDepthPolicy() ?? new ChainDepthPolicy(),
			$this->ledger,
			$this->logger,
		);
	}

	/**
	 * The dictionary store over the configured provider.
	 *
	 * @return DictionaryStore
	 *   The store.
	 */
	public function dictionaries(): DictionaryStore
	{
		return $this->dictionaryStore ??= new DictionaryStore($this->provider(), $this->database);
	}

	/**
	 * A trainer scoring candidates through the codec this host will actually use.
	 *
	 * @return DictionaryTrainer
	 *   The trainer.
	 */
	public function dictionaryTrainer(): DictionaryTrainer
	{
		return new DictionaryTrainer($this->codecs()->writer());
	}

	/**
	 * A training pass over what the store already holds.
	 *
	 * @return DictionaryPass
	 *   The pass.
	 */
	public function dictionaryPass(): DictionaryPass
	{
		return new DictionaryPass(
			$this->dictionaryTrainer(),
			$this->dictionaries(),
			$this->objectStore(),
			$this->segmentReader(),
			$this->commitLog(),
			$this->refStore(),
			$this->logger,
		);
	}

	/**
	 * The dictionary new frames are compressed against.
	 *
	 * One dictionary per store rather than one per realm, because a frame is addressed by its content
	 * and a store that chose a dictionary per operation would compress the same bytes two ways and
	 * store them twice. The entity realm is the one that matters - it is the overwhelming majority of
	 * operations - so its dictionary is the one that is loaded.
	 *
	 * @return DictionaryRef|null
	 *   The reference, or NULL when dictionaries are off, unsupported, or none has been trained.
	 */
	public function activeDictionary(): ?DictionaryRef
	{
		if ($this->settings()->get('codec.dictionary') !== true) {
			return null;
		}
		if (!$this->codecs()->writer()->supportsDictionary()) {
			return null;
		}

		return $this->dictionaries()->latest(Realm::ENTITY->value);
	}

	/**
	 * A segment writer over the configured store.
	 *
	 * @return SegmentWriter
	 *   The writer.
	 */
	public function segmentWriter(): SegmentWriter
	{
		return new SegmentWriter(
			$this->provider(),
			$this->codecs(),
			$this->cipher(),
			$this->level('flush_level'),
		);
	}

	/**
	 * A segment reader over the configured store.
	 *
	 * @return SegmentReader
	 *   The reader.
	 */
	public function segmentReader(): SegmentReader
	{
		return new SegmentReader($this->provider(), $this->codecs(), $this->cipher());
	}

	/**
	 * A writer for the base anchors a replay starts from.
	 *
	 * @return BaseWriter
	 *   The writer.
	 */
	public function baseWriter(): BaseWriter
	{
		return new BaseWriter($this->provider());
	}

	/**
	 * A reader over the base anchors.
	 *
	 * @return BaseReader
	 *   The reader.
	 */
	public function baseReader(): BaseReader
	{
		return new BaseReader($this->provider());
	}

	/**
	 * The anchor policy from configuration.
	 *
	 * @return BasePolicy
	 *   The policy.
	 */
	public function basePolicy(): BasePolicy
	{
		return BasePolicy::fromSettings(
			$this->settings()->get('retention.base_interval') === null
				? null
				: (int) $this->settings()->get('retention.base_interval'),
			$this->settings()->get('retention.base_full_every') === null
				? null
				: (int) $this->settings()->get('retention.base_full_every'),
		);
	}

	/**
	 * The local index of where every subject was last stored.
	 *
	 * @return SubjectIndex
	 *   The index.
	 */
	public function subjectIndex(): SubjectIndex
	{
		return new SubjectIndex($this->database);
	}

	/**
	 * The commit log over the configured store.
	 *
	 * @return CommitLog
	 *   The log.
	 */
	public function commitLog(): CommitLog
	{
		return new CommitLog($this->provider(), $this->refStore());
	}

	/**
	 * The ref store over the configured provider.
	 *
	 * @return RefStore
	 *   The store.
	 */
	public function refStore(): RefStore
	{
		return new RefStore($this->provider());
	}

	/**
	 * A verifier over the configured store.
	 *
	 * @return Verifier
	 *   A verifier wired to the storage tripwires and the persistent ledger.
	 */
	public function verifier(): Verifier
	{
		return new Verifier(
			$this->provider(),
			$this->index,
			$this->objectStore(),
			$this->commitLog(),
			$this->refStore(),
			$this->baseReader(),
			$this->segmentReader(),
			TripwireRegistry::withStorageTripwires(),
			$this->ledger,
			(int) $this->settings()->get('delta.max_depth'),
			$this->commitIndex,
			$this->tiers(),
		);
	}

	/**
	 * A replayer over the configured store.
	 *
	 * @return Replayer
	 *   The replayer.
	 */
	public function replayer(): Replayer
	{
		return new Replayer(
			$this->objectStore(),
			$this->commitLog(),
			$this->refStore(),
			$this->segmentReader(),
			$this->baseReader(),
		);
	}

	/**
	 * A preflight over the configured store.
	 *
	 * @return Preflight
	 *   The preflight.
	 */
	public function preflight(): Preflight
	{
		return new Preflight(
			$this->replayer(),
			$this->commitLog(),
			$this->ledger,
			$this->subjectIndex(),
		);
	}

	/**
	 * A logical restore over the configured store.
	 *
	 * @return LogicalRestore
	 *   The restore.
	 */
	public function logicalRestore(): LogicalRestore
	{
		return new LogicalRestore(
			$this->preflight(),
			$this->replayer(),
			$this->flusher(),
			$this->audit,
			$this->entityTypeManager,
			$this->configFactory,
			$this->state,
			$this->keyValue,
			$this->currentUser,
			$this->logger,
		);
	}

	/**
	 * The reconciler.
	 *
	 * @return Reconciler
	 *   A reconciler over the site's own database.
	 */
	public function reconciler(): Reconciler
	{
		return new Reconciler(
			$this->database,
			$this->journal,
			$this->scope,
			TripwireRegistry::withCaptureTripwires(),
			$this->ledger,
			$this->logger,
			(int) ($this->settings()->get('capture.sample_rows') ?? Reconciler::DEFAULT_SAMPLE),
		);
	}

	/**
	 * A keyspace discovery pass over whatever sources are registered.
	 *
	 * The database source is always present, since a site's default cache and key-value backends are
	 * tables. Submodules add their own by tagging a service, so a site with `strata_redis` gets both.
	 *
	 * @return KeyspaceDiscovery
	 *   The discovery pass.
	 */
	public function keyspaceDiscovery(): KeyspaceDiscovery
	{
		$discovery = new KeyspaceDiscovery(
			$this->classifications,
			TripwireRegistry::withCaptureTripwires(),
			$this->ledger,
			$this->logger,
		);

		$discovery->addSource(new DatabaseKeyspaceSource($this->database));

		foreach ($this->keyspaceSources as $source) {
			$discovery->addSource($source);
		}

		return $discovery;
	}

	/**
	 * Adds a keyspace source contributed by a submodule.
	 *
	 * Collected from the container by tag, so a submodule contributes one without this class
	 * knowing what backends exist.
	 *
	 * @param KeyspaceSourceInterface $source
	 *   The source.
	 */
	public function addKeyspaceSource(KeyspaceSourceInterface $source): void
	{
		$this->keyspaceSources[] = $source;
	}

	/**
	 * The restore access checker, configured with the site's approval policy.
	 *
	 * @return RestoreAccess
	 *   The checker.
	 */
	public function restoreAccess(): RestoreAccess
	{
		return new RestoreAccess((bool) $this->settings()->get('restore.two_person_approval'));
	}

	/**
	 * The physical restore, with the strategies this host can run.
	 *
	 * Both shipped strategies are registered whether or not the driver supports them, because an
	 * operator asking why the swap is unavailable needs it listed with its reason rather than absent.
	 *
	 * @return PhysicalRestore
	 *   The restore.
	 */
	public function physicalRestore(): PhysicalRestore
	{
		$restore = new PhysicalRestore(
			$this->database,
			$this->replayer(),
			$this->flusher(),
			$this->audit,
			$this->state,
			$this->currentUser,
			$this->logger,
		);

		$restore->addStrategy(new TruncateRestoreStrategy());
		$restore->addStrategy(new ShadowSwapStrategy());

		foreach ($this->restoreStrategies as $strategy) {
			$restore->addStrategy($strategy);
		}

		return $restore;
	}

	/**
	 * Adds a restore strategy contributed by a submodule.
	 *
	 * @param RestoreStrategyInterface $strategy
	 *   The strategy.
	 */
	public function addRestoreStrategy(RestoreStrategyInterface $strategy): void
	{
		$this->restoreStrategies[] = $strategy;
	}

	/**
	 * A code capture pass over the project root.
	 *
	 * @return CodeCapture
	 *   The pass.
	 */
	public function codeCapture(): CodeCapture
	{
		$root = $this->projectRoot();

		return new CodeCapture(
			new CodeScanner(
				$root,
				(int) ($this->settings()->get('code.max_files') ?? CodeScanner::MAX_FILES),
			),
			new VendorDriftDetector(
				$root,
				(int) ($this->settings()->get('code.vendor_sample') ??
					VendorDriftDetector::DEFAULT_SAMPLE),
			),
			$this->journal,
			$this->scope,
			$this->state,
			TripwireRegistry::withCaptureTripwires(),
			$this->ledger,
			$this->logger,
		);
	}

	/**
	 * The directory the site's code and lockfiles live under.
	 *
	 * Configured rather than derived. The docroot and the composer root are the same directory on
	 * some installs and one level apart on the `drupal/recommended-project` layout, and guessing
	 * wrong means walking the wrong tree - or walking `vendor/` as if it were the site's own code.
	 *
	 * @return string
	 *   The configured root, or Drupal's own root when nothing is configured.
	 */
	public function projectRoot(): string
	{
		$configured = trim((string) $this->settings()->get('code.root'));

		if ($configured !== '') {
			return $configured;
		}

		// the composer root is one level above the docroot on the recommended project layout
		$docroot = DRUPAL_ROOT;

		return is_file(dirname($docroot) . '/composer.json') ? dirname($docroot) : $docroot;
	}

	/**
	 * The retention ladder from configuration.
	 *
	 * @return LevelPolicy
	 *   The policy.
	 */
	public function levelPolicy(): LevelPolicy
	{
		/** @var array<int, array<string, mixed>> $levels */
		$levels = $this->settings()->get('retention.levels') ?? [];

		return LevelPolicy::fromSettings($levels);
	}

	/**
	 * A reachability set over the configured store.
	 *
	 * @return Reachability
	 *   The set, unwalked until it is asked a question.
	 */
	public function reachability(): Reachability
	{
		return new Reachability(
			$this->index,
			$this->commitLog(),
			$this->refStore(),
			$this->baseReader(),
		);
	}

	/**
	 * A recompressor at the configured compaction level.
	 *
	 * @return Recompressor
	 *   The recompressor.
	 */
	public function recompressor(): Recompressor
	{
		return new Recompressor(
			$this->provider(),
			$this->index,
			$this->objectStore(),
			$this->codecs(),
			$this->cipher(),
			new Packer((int) $this->settings()->get('frame.pack_target')),
			$this->level('compaction_level') ?? 19,
		);
	}

	/**
	 * The compactor.
	 *
	 * @return Compactor
	 *   A compactor wired to the configured store and retention ladder.
	 */
	public function compactor(): Compactor
	{
		return new Compactor(
			$this->provider(),
			$this->index,
			$this->recompressor(),
			$this->reachability(),
			$this->levelPolicy(),
			$this->lease,
			$this->logger,
			$this->reanchorer(),
			$this->rollup(),
			$this->tierMigrator(),
			$this->tiers() === null ? null : $this->placementIndex(),
		);
	}

	/**
	 * A file capture over the configured store.
	 *
	 * @return FileCapture
	 *   The capture.
	 */
	public function fileCapture(): FileCapture
	{
		$splitter = new BlockSplitter(
			(int) ($this->settings()->get('file.block_size') ?? BlockSplitter::DEFAULT_SIZE),
		);

		return new FileCapture(
			$this->mediaStore($splitter),
			$splitter,
			ShiftDetector::fromSettings(
				$this->settings()->get('file.shift_threshold') === null
					? null
					: (float) $this->settings()->get('file.shift_threshold'),
			),
			$this->journal,
			$this->scope,
			$this->state,
			TripwireRegistry::withFileTripwires(),
			$this->ledger,
			$this->logger,
		);
	}

	/**
	 * The block store file content is written to.
	 *
	 * @param BlockSplitter|null $splitter
	 *   The splitter to use, or NULL to build one from configuration.
	 *
	 * @return MediaStore
	 *   The store.
	 */
	public function mediaStore(?BlockSplitter $splitter = null): MediaStore
	{
		return new MediaStore(
			$this->provider(),
			$splitter ??
				new BlockSplitter(
					(int) ($this->settings()->get('file.block_size') ??
						BlockSplitter::DEFAULT_SIZE),
				),
			StorageClassPolicy::fromSettings(
				$this->settings()->get('file.tier_blocks') === null
					? null
					: (bool) $this->settings()->get('file.tier_blocks'),
				$this->settings()->get('file.storage_class') === null
					? null
					: (string) $this->settings()->get('file.storage_class'),
			),
		);
	}

	/**
	 * A rollup pass over the retention ladder.
	 *
	 * @return Rollup
	 *   The pass.
	 */
	public function rollup(): Rollup
	{
		return new Rollup(
			$this->segmentReader(),
			$this->segmentWriter(),
			$this->levelPolicy(),
			$this->index,
			$this->logger,
		);
	}

	/**
	 * An exporter writing a portable archive of this site's history.
	 *
	 * @return ArchiveExporter
	 *   The exporter.
	 */
	public function archiveExporter(): ArchiveExporter
	{
		return new ArchiveExporter(
			$this->provider(),
			$this->commitLog(),
			$this->refStore(),
			$this->segmentReader(),
			$this->baseReader(),
			$this->index,
			$this->dictionaries(),
			$this->site,
			$this->time,
			$this->logger,
		);
	}

	/**
	 * An importer loading a portable archive into this site's store.
	 *
	 * @return ArchiveImporter
	 *   The importer.
	 */
	public function archiveImporter(): ArchiveImporter
	{
		return new ArchiveImporter($this->provider(), $this->logger);
	}

	/**
	 * A timeline query over the local commit index.
	 *
	 * @return TimelineQuery
	 *   The query.
	 */
	public function timeline(): TimelineQuery
	{
		return new TimelineQuery($this->database, $this->commitIndex);
	}

	/**
	 * A series builder for the graphs.
	 *
	 * @return SeriesBuilder
	 *   The builder, priced against the configured provider.
	 */
	public function series(): SeriesBuilder
	{
		return new SeriesBuilder($this->timeline(), $this->index, $this->priceTable());
	}

	/**
	 * The price table for the configured provider.
	 *
	 * A provider with no published prices falls back to the local table, which charges nothing. An
	 * add-on may register any provider id and a site may have none configured at all, and a cost
	 * graph that raised on either would take down the page an operator opens to find out what the
	 * store is costing them.
	 *
	 * @return PriceTable
	 *   The table, or the local one when no table is shipped for the configured provider.
	 */
	public function priceTable(): PriceTable
	{
		$id = (string) $this->settings()->get('provider');

		return isset(PriceTable::all()[$id]) ? PriceTable::of($id) : PriceTable::local();
	}

	/**
	 * A diff builder over the configured store.
	 *
	 * @return DiffBuilder
	 *   The builder.
	 */
	public function diffBuilder(): DiffBuilder
	{
		return new DiffBuilder($this->replayer(), $this->entityTypeManager);
	}

	/**
	 * A storage explorer over the configured store.
	 *
	 * @return StorageExplorer
	 *   The explorer.
	 */
	public function storageExplorer(): StorageExplorer
	{
		return new StorageExplorer(
			$this->reachability(),
			$this->index,
			$this->commitIndex,
			$this->dictionaries(),
			$this->segmentReader(),
			$this->database,
		);
	}

	/**
	 * The local index of what each restore drill proved.
	 *
	 * @return DrillIndex
	 *   The index.
	 */
	public function drillIndex(): DrillIndex
	{
		return new DrillIndex($this->database);
	}

	/**
	 * A restore drill over the configured store.
	 *
	 * @return DrillRunner
	 *   The runner.
	 */
	public function drillRunner(): DrillRunner
	{
		return new DrillRunner(
			$this->replayer(),
			$this->subjectIndex(),
			$this->refStore(),
			$this->commitLog(),
			$this->entityTypeManager,
			$this->configFactory,
			$this->state,
			$this->ledger,
			$this->time,
			$this->logger,
			$this->drillIndex(),
			$this->notifier,
		);
	}

	/**
	 * The metric window anomaly detection reads from.
	 *
	 * @return MetricSampler
	 *   The sampler.
	 */
	public function metricSampler(): MetricSampler
	{
		return new MetricSampler(
			$this->state,
			$this->index,
			$this->commitIndex,
			$this->database,
			$this->time,
		);
	}

	/**
	 * An anomaly detector over the sampled window.
	 *
	 * @return AnomalyDetector
	 *   The detector.
	 */
	public function anomalyDetector(): AnomalyDetector
	{
		return new AnomalyDetector(
			$this->metricSampler(),
			$this->ledger,
			$this->logger,
			$this->notifier,
			(float) ($this->settings()->get('anomaly.warn_sigma') ?? AnomalyDetector::WARN_SIGMA),
		);
	}

	/**
	 * A tracer, enabled only when a collector is configured.
	 *
	 * @return Tracer
	 *   The tracer.
	 */
	public function tracer(): Tracer
	{
		return $this->tracer ??= new Tracer($this->telemetryExporter()->isEnabled());
	}

	/**
	 * An OTLP exporter over the configured collector.
	 *
	 * @return OtlpExporter
	 *   The exporter, which sends nothing when no endpoint is configured.
	 */
	public function telemetryExporter(): OtlpExporter
	{
		if ($this->telemetry !== null) {
			return $this->telemetry;
		}

		/** @var array<string, string> $headers */
		$headers = $this->settings()->get('telemetry.headers') ?? [];

		return $this->telemetry = new OtlpExporter(
			$this->http ?? new Client(),
			new OtlpPayload('strata', '', ['strata.site' => $this->site->id()]),
			$this->logger,
			(string) ($this->settings()->get('telemetry.endpoint') ?? ''),
			$headers,
		);
	}

	/**
	 * A telemetry pass over the local indexes.
	 *
	 * @return TelemetryPass
	 *   The pass.
	 */
	public function telemetryPass(): TelemetryPass
	{
		return new TelemetryPass(
			$this->telemetryExporter(),
			$this->commitIndex,
			$this->index,
			$this->journal,
			$this->ledger,
			$this->time,
			$this->providerStats(),
		);
	}

	/**
	 * A reindexer over the configured store.
	 *
	 * @return Reindexer
	 *   A reindexer that rebuilds both local indexes from the bucket.
	 */
	public function reindexer(): Reindexer
	{
		return new Reindexer(
			$this->provider(),
			$this->index,
			$this->commitIndex,
			$this->commitLog(),
			$this->refStore(),
			$this->segmentReader(),
			$this->logger,
			$this->tierPlacementRebuilder(),
		);
	}

	/**
	 * The flush policy from configuration.
	 *
	 * @return FlushPolicy
	 *   The policy.
	 */
	public function flushPolicy(): FlushPolicy
	{
		return new FlushPolicy(
			(int) $this->settings()->get('flush.max_age'),
			(int) $this->settings()->get('flush.max_bytes'),
			(int) $this->settings()->get('flush.max_ops'),
		);
	}

	/**
	 * The flusher.
	 *
	 * @return Flusher
	 *   A flusher wired to the configured store.
	 */
	public function flusher(): Flusher
	{
		return new Flusher(
			$this->journal,
			$this->flushPolicy(),
			$this->objectStore(),
			$this->segmentWriter(),
			$this->baseWriter(),
			$this->baseReader(),
			$this->basePolicy(),
			$this->subjectIndex(),
			$this->commitLog(),
			$this->lease,
			$this->logger,
			$this->commitIndex,
		);
	}

	/**
	 * Forgets everything resolved so far.
	 *
	 * Called after a settings change, and by a long-running command between batches.
	 */
	public function reset(): void
	{
		$this->provider = null;
		$this->tiers = null;
		$this->cipher = null;
		$this->dictionaryStore = null;
		$this->codecs = null;
		$this->store = null;
	}

	/**
	 * The key the cipher seals with.
	 *
	 * @return KeyProviderInterface
	 *   A provider holding the configured key.
	 *
	 * @throws RuntimeException
	 *   When no key is configured, the key module is absent, or the key is the wrong length.
	 */
	private function keyProvider(): KeyProviderInterface
	{
		$id = (string) $this->settings()->get('key');

		if ($id === '') {
			throw new RuntimeException(
				'Encryption is on but no key is configured. Choose a key, or set the cipher to ' .
					'"none" to store frames unencrypted.',
			);
		}
		if ($this->keys === null) {
			throw new RuntimeException(
				'Encryption is on but the key module is not installed, so the key cannot be read.',
			);
		}

		$provider = $this->keyProviderFor($id);

		if ($provider === null) {
			throw new RuntimeException(sprintf('Key "%s" is configured but holds no value', $id));
		}

		return $provider;
	}

	/**
	 * One key from the key module, by its machine name.
	 *
	 * A retired key that has already been deleted resolves to NULL rather than throwing, because a
	 * finished rotation is exactly the case where that happens and it must not stop the site reading
	 * its own history.
	 *
	 * @param string $id
	 *   The key entity's machine name.
	 *
	 * @return KeyProviderInterface|null
	 *   The provider, or NULL when the key is absent or holds nothing.
	 */
	private function keyProviderFor(string $id): ?KeyProviderInterface
	{
		if ($id === '' || $this->keys === null) {
			return null;
		}

		$key = $this->keys->getKey($id)?->getKeyValue();

		if ($key === null || $key === '') {
			return null;
		}

		// a key stored as hex is the common shape, and is accepted alongside raw bytes
		if (strlen($key) === KeyProviderInterface::KEY_BYTES * 2 && ctype_xdigit($key)) {
			return StaticKeyProvider::fromHex($key);
		}

		return new StaticKeyProvider($key);
	}

	/**
	 * Where the local provider writes.
	 *
	 * Returned as configured rather than resolved to a real path. `LocalStorage` uses plain file
	 * functions, which route through any registered stream wrapper, so `private://strata` works
	 * directly and so does the `vfs://` root a kernel test runs in. Calling realpath() here would
	 * reject both.
	 *
	 * @return string
	 *   The configured path.
	 *
	 * @throws RuntimeException
	 *   When no directory is configured.
	 */
	private function localPath(): string
	{
		$configured = trim((string) $this->settings()->get('local_path'));

		if ($configured === '') {
			throw new RuntimeException('The local storage provider has no directory configured');
		}

		return $configured;
	}

	/**
	 * A configured compression level.
	 *
	 * @param string $which
	 *   Either "flush_level" or "compaction_level".
	 *
	 * @return int|null
	 *   The level, or NULL for the codec's own default.
	 */
	private function level(string $which): ?int
	{
		$value = $this->settings()->get('codec.' . $which);

		return $value === null ? null : (int) $value;
	}

	/**
	 * The module settings.
	 *
	 * @return ImmutableConfig
	 *   The settings.
	 */
	private function settings(): ImmutableConfig
	{
		return $this->configFactory->get('strata.settings');
	}
}
