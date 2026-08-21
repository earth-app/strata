<?php

/**
 * @file
 * Hooks, events and service tags Strata offers other modules.
 */

declare(strict_types=1);

/**
 * @defgroup strata_api Strata API
 * @{
 * How another module observes what Strata does and adds to what it can do.
 *
 * Strata has no alter hooks. Everything it captures is content-addressed and everything it writes
 * is immutable, so a hook that let a third module change a payload on its way to the store would
 * produce an object whose hash no longer describes what the site held. Observation happens through
 * events, and new capability arrives as a tagged service.
 *
 * Four extension points, all of them constructor-injected and independently testable:
 *
 * - Events, for reacting to a commit, a restore, a finding, a budget breach, a prune or a drill.
 * - `strata.storage_provider_factory`, for a place to keep objects.
 * - `strata.keyspace_source`, for an ephemeral keyspace the classifier should know about.
 * - `strata.restore_strategy`, for a way to put a table back.
 *
 * A storage factory may implement one more interface rather than carry another tag; see
 * @link strata_api_tiers tiered storage @endlink.
 *
 * Compression codecs and health tripwires are deliberately not collected from other modules. A
 * codec decides how bytes already in the store are read back, and a tripwire is asserted against
 * invariants of the format itself; both are versioned with the format and belong to it.
 * @}
 */

/**
 * @defgroup strata_api_events Strata events
 * @{
 * The six events, and what each one means.
 *
 * Every name is a constant on `StrataEvents`, every payload extends `StrataEvent`, and every event
 * is dispatched through `Notifier`, which swallows a listener's exception and logs it. A subscriber
 * that throws therefore cannot fail the flush that dispatched it - and cannot be relied on to
 * report its own failure to the caller either.
 *
 * | Constant           | Event class     | Dispatched when                                     |
 * | ------------------ | --------------- | --------------------------------------------------- |
 * | `COMMIT_SEALED`    | `CommitEvent`   | A flush sealed a commit and moved the ref            |
 * | `RESTORE_FINISHED` | `RestoreEvent`  | A logical or physical restore finished, either way   |
 * | `HEALTH_FINDING`   | `HealthEvent`   | A tripwire recorded a finding                        |
 * | `BUDGET_BREACHED`  | `BudgetEvent`   | A budget rung was crossed                            |
 * | `PRUNE_APPLIED`    | `PruneEvent`    | A prune removed objects and wrote its receipt        |
 * | `DRILL_FINISHED`   | `DrillEvent`    | A restore drill judged a replay against the site     |
 *
 * `StrataEvents::all()` returns every constant and a unit test asserts the list is complete, so a
 * new event cannot ship without appearing there.
 *
 * An event carries a body rather than a live object graph: `StrataEvent::body()` is a plain array
 * and the event is JSON-serializable, because the same payload is what a webhook receives. Nothing
 * in a body is a database handle or an entity, so a listener cannot reach through an event into
 * state the flush has already moved past.
 *
 * @code
 * namespace Drupal\my_module\EventSubscriber;
 *
 * use Drupal\strata\Event\CommitEvent;
 * use Drupal\strata\Event\StrataEvents;
 * use Symfony\Component\EventDispatcher\EventSubscriberInterface;
 *
 * final class CommitAnnouncer implements EventSubscriberInterface
 * {
 *   public static function getSubscribedEvents(): array
 *   {
 *     return [StrataEvents::COMMIT_SEALED => 'onCommit'];
 *   }
 *
 *   public function onCommit(CommitEvent $event): void
 *   {
 *     // $event->body() holds the commit id, the operation count and the bytes written
 *   }
 * }
 * @endcode
 *
 * Registered the usual way:
 *
 * @code
 * services:
 *   my_module.commit_announcer:
 *     class: Drupal\my_module\EventSubscriber\CommitAnnouncer
 *     tags:
 *       - { name: event_subscriber }
 * @endcode
 *
 * `strata_notify` is a worked example of a subscriber over these events, and
 * `Drupal\strata\Event\Webhook\WebhookSubscriber` is the same events delivered over HTTP with a
 * signed body.
 * @}
 */

/**
 * @defgroup strata_api_providers Storage providers
 * @{
 * Somewhere else to keep objects.
 *
 * A provider is reached through `StorageProviderInterface` and built by a factory, so a site that
 * captures but never flushes never constructs one. Implement
 * `Drupal\strata\Storage\StorageProviderFactoryInterface` - `id()` and `create()` - and tag the
 * service; the id is what `strata.settings:provider` names.
 *
 * @code
 * services:
 *   my_module.storage_factory:
 *     class: Drupal\my_module\Storage\MyStorageFactory
 *     arguments: ['@config.factory']
 *     tags:
 *       - { name: strata.storage_provider_factory }
 * @endcode
 *
 * The contract a provider has to keep is asserted by `Drupal\Tests\strata\Unit\Storage`'s storage
 * contract case, which runs the same assertions against every shipped provider. Point it at a new
 * one before trusting it with history.
 *
 * Capabilities are probed, never assumed. `Drupal\strata\Storage\Capabilities` is what an endpoint
 * says it can do, and code that needs conditional writes, batch delete or a storage class asks
 * first and takes the slower path when the answer is no. An endpoint that reports a capability it
 * does not have produces a failure at flush time rather than a fallback.
 * @}
 */

/**
 * @defgroup strata_api_tiers Tiered storage
 * @{
 * Serving more than one bucket from one endpoint.
 *
 * A site can spread history across several buckets and move old objects into colder ones. Each tier
 * names a provider id and a location, and the provider has to be buildable more than once - the same
 * credentials pointed somewhere else. A factory that can do that implements
 * `Drupal\strata\Storage\TierProviderFactoryInterface` in addition to
 * `StorageProviderFactoryInterface`; the tag on the service does not change.
 *
 * @code
 * final class MyStorageFactory implements TierProviderFactoryInterface
 * {
 *   public function createFor(TierTarget $target): StorageProviderInterface
 *   {
 *     // build the same endpoint against $target->location and $target->storageClass
 *   }
 * }
 * @endcode
 *
 * Implementing it is optional and the absence of it is not a failure: a factory with exactly one
 * possible location can still serve the nearest tier, and the engine refuses a ladder it cannot
 * honour rather than quietly writing two tiers into one bucket. That refusal happens when the ladder
 * is read rather than at the first flush, so a misconfiguration is an error on the settings page and
 * not a surprise at three in the morning.
 *
 * Two rules a tier-aware provider must not break:
 *
 * - **The same object in two tiers is one object.** Placement is recorded per key, and a content
 *   address does not change because a copy was moved, so a replica must be the same key in a second
 *   bucket rather than a new name.
 * - **An unreachable tier is reported, never treated as empty.** `TierStatusInterface::tierStatus()`
 *   returns NULL for a reachable tier and the reason for one that is not, and a verify pass and a
 *   prune both act on that: the prune refuses rather than deleting on a partial picture.
 * @}
 */

/**
 * @defgroup strata_api_keyspaces Ephemeral keyspaces
 * @{
 * Somewhere else the site keeps state worth classifying.
 *
 * The classifier decides which of a site's ephemeral keys are authoritative - a queue is, a
 * rendered-page cache is not - and it can only decide about keys it can see. Implement
 * `Drupal\strata\Capture\Classifier\KeyspaceSourceInterface` and tag the service.
 *
 * @code
 * services:
 *   my_module.keyspace:
 *     class: Drupal\my_module\MyKeyspaceSource
 *     tags:
 *       - { name: strata.keyspace_source }
 * @endcode
 *
 * `keys()` returns a Traversable and takes a limit, because discovery runs inside a cron window and
 * a keyspace can be larger than memory. `isAvailable()` exists so a source whose backend is absent
 * is skipped rather than raising: `strata_redis` reports unavailable when no Redis is reachable and
 * discovery carries on with what is left.
 *
 * An unclassified key is captured verbatim and flagged, never dropped. A source that surfaces keys
 * nobody has classified therefore grows the store and raises
 * `classification.unclassified_growth`; it does not silently lose state.
 * @}
 */

/**
 * @defgroup strata_api_restore Restore strategies
 * @{
 * Another way to put a table back.
 *
 * A physical restore replaces the contents of a table, and how that is done safely depends on the
 * driver. Implement `Drupal\strata\Restore\RestoreStrategyInterface` and tag the service.
 *
 * @code
 * services:
 *   my_module.restore_strategy:
 *     class: Drupal\my_module\Restore\MyStrategy
 *     tags:
 *       - { name: strata.restore_strategy }
 * @endcode
 *
 * `isSupported()` takes the connection and `unsupportedReason()` explains a no in a sentence a
 * confirm form can print. Refusing cleanly is the contract: `ShadowSwapStrategy` needs an atomic
 * rename and says so on SQLite rather than half-restoring, and the shipped
 * `TruncateRestoreStrategy` is the driver-agnostic floor that always answers yes.
 *
 * A strategy is handed rows and columns and returns how many rows it wrote. It never invents a
 * value: a subject `Preflight` classified as degraded is skipped and listed, and filling one with a
 * default is a per-scope choice the operator makes on the confirm form.
 * @}
 */
