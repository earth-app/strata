<?php

declare(strict_types=1);

namespace Drupal\strata\Capture;

use Closure;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;

/**
 * Hands out key-value stores that record what is written to them.
 *
 * The `keyvalue` service is a factory, so the factory is what gets decorated and each store it
 * produces is wrapped on the way out. Stores are cached per collection here as well as in the inner
 * factory, so a collection asked for twice returns the same wrapper and not two.
 *
 * Strata's own collections are handed through unwrapped. Recording the journal's own bookkeeping in
 * the journal is a feedback loop rather than a backup.
 *
 * So is the `state` collection, for a different reason. Core's `state` service is
 * `State(@keyvalue, ...)` and its constructor asks this factory for the collection called `state`,
 * so a wrapped store there means every state write is recorded twice - once as `state` by
 * StateCapture, and again as `keyvalue` under the subject `state:<key>`.
 *
 * The expirable factory is deliberately NOT decorated. An expirable value is a cache with a
 * deadline; capturing one would record something whose correct future state is "gone", and
 * restoring it would put back an entry the site had already finished with.
 *
 * @see KeyValueCapture
 */
final class KeyValueCaptureFactory implements KeyValueFactoryInterface
{
	/**
	 * Collection prefix this module owns, never captured.
	 */
	public const OWN_PREFIX = 'strata';

	/**
	 * Collections another decorator already records, so wrapping them would double-count.
	 *
	 * @var list<string>
	 */
	public const CAPTURED_ELSEWHERE = ['state'];

	/**
	 * Wrapped stores, keyed by collection.
	 *
	 * @var array<string, KeyValueStoreInterface>
	 */
	private array $stores = [];

	/**
	 * Constructs the factory.
	 *
	 * @param KeyValueFactoryInterface $inner
	 *   The factory being wrapped.
	 * @param KeyRecorder|Closure $recorder
	 *   Journals each write the stores make, or a closure returning one.
	 *
	 *   **A closure is what a site gets, and it is not an optimisation.** Core's
	 *   `DevelopmentSettingsPass` calls `$container->get('keyvalue')->get('development_settings')`
	 *   while the container is still being compiled, so everything `keyvalue` depends on has to be
	 *   constructible at that moment. A recorder is not: it needs the journal and a logger channel,
	 *   and both resolve through definitions Symfony refuses to build during compilation. Passing the
	 *   recorder directly makes every container rebuild on a real site fail.
	 */
	public function __construct(
		private readonly KeyValueFactoryInterface $inner,
		private readonly KeyRecorder|Closure $recorder,
	) {}

	/**
	 * {@inheritdoc}
	 */
	public function get($collection)
	{
		if (isset($this->stores[$collection])) {
			return $this->stores[$collection];
		}

		$store = $this->inner->get($collection);
		$name = (string) $collection;

		if (str_starts_with($name, self::OWN_PREFIX) || $this->isCapturedElsewhere($name)) {
			return $this->stores[$collection] = $store;
		}

		return $this->stores[$collection] = new KeyValueCapture($store, $this->recorder, $name);
	}

	/**
	 * Whether a collection is already recorded by a decorator of its own.
	 *
	 * @param string $collection
	 *   The collection name.
	 *
	 * @return bool
	 *   TRUE when wrapping it here would record the same write twice.
	 */
	private function isCapturedElsewhere(string $collection): bool
	{
		return in_array($collection, self::CAPTURED_ELSEWHERE, true);
	}
}
