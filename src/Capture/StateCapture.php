<?php

declare(strict_types=1);

namespace Drupal\strata\Capture;

use Drupal\Core\DestructableInterface;
use Drupal\Core\State\StateInterface;
use Drupal\strata\Journal\Realm;
use stdClass;

/**
 * Records state writes by wrapping the state service.
 *
 * State has no events, and it is not a factory, so decoration is the way in. Every read passes
 * straight through; every write is journaled and then delegated.
 *
 * The old value is read before a write, because the decorator has no other way to tell a create
 * from an update and because a restore of a create has to know the key did not exist. State handles
 * ~580 operations a day on a 50,000-user site - under 1% of write volume - so one extra read per
 * write is not a cost worth optimising away, and `getMultiple()` makes the batch case one query
 * rather than N.
 *
 * The whole value is stored rather than a delta. State values are small and structurally arbitrary,
 * so a field-level diff would cost more to compute and store than the value itself.
 *
 * @see KeyRecorder
 * @see KeyValueCapture
 */
final class StateCapture implements StateInterface, DestructableInterface
{
	/**
	 * Constructs the decorator.
	 *
	 * @param StateInterface $inner
	 *   The state service being wrapped.
	 * @param KeyRecorder $recorder
	 *   Journals each write.
	 */
	public function __construct(
		private readonly StateInterface $inner,
		private readonly KeyRecorder $recorder,
	) {}

	#region Reads

	/**
	 * {@inheritdoc}
	 */
	public function get($key, $default = null)
	{
		return $this->inner->get($key, $default);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getMultiple(array $keys)
	{
		return $this->inner->getMultiple($keys);
	}

	/**
	 * {@inheritdoc}
	 */
	public function resetCache()
	{
		$this->inner->resetCache();
	}

	/**
	 * {@inheritdoc}
	 */
	public function getValuesSetDuringRequest(string $key): ?array
	{
		return $this->inner->getValuesSetDuringRequest($key);
	}

	#endregion

	#region Writes

	/**
	 * {@inheritdoc}
	 */
	public function set($key, $value)
	{
		if ($this->recorder->covers(Realm::STATE)) {
			$this->recorder->written(Realm::STATE, $key, $value, $this->exists($key));
		}

		$this->inner->set($key, $value);
	}

	/**
	 * {@inheritdoc}
	 */
	public function setMultiple(array $data)
	{
		if ($this->recorder->covers(Realm::STATE) && $data !== []) {
			// one read for the whole batch rather than one per key
			$existing = $this->inner->getMultiple(array_keys($data));

			foreach ($data as $key => $value) {
				$this->recorder->written(
					Realm::STATE,
					(string) $key,
					$value,
					array_key_exists($key, $existing),
				);
			}
		}

		$this->inner->setMultiple($data);
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete($key)
	{
		if ($this->recorder->covers(Realm::STATE) && $this->exists($key)) {
			$this->recorder->deleted(Realm::STATE, $key);
		}

		$this->inner->delete($key);
	}

	/**
	 * {@inheritdoc}
	 */
	public function deleteMultiple(array $keys)
	{
		if ($this->recorder->covers(Realm::STATE) && $keys !== []) {
			$existing = $this->inner->getMultiple($keys);

			foreach ($keys as $key) {
				// a delete of a key that was not there changes nothing and is not recorded
				if (array_key_exists($key, $existing)) {
					$this->recorder->deleted(Realm::STATE, (string) $key);
				}
			}
		}

		$this->inner->deleteMultiple($keys);
	}

	#endregion

	/**
	 * {@inheritdoc}
	 *
	 * State is a cache collector, and the `needs_destruction` tag on the service is what makes it
	 * write its collected values out at the end of a request. Decorating the service moves that tag to
	 * this class, so a decorator that did not pass the call through would silently stop state being
	 * persisted at all - a failure that looks like nothing until a value goes missing.
	 */
	public function destruct(): void
	{
		if ($this->inner instanceof DestructableInterface) {
			$this->inner->destruct();
		}
	}

	/**
	 * Whether a key currently holds a value.
	 *
	 * A sentinel is used rather than comparing against NULL, since NULL is a value state can hold and
	 * a restore that turned a stored NULL into a missing key would be wrong.
	 *
	 * @param string $key
	 *   The state key.
	 *
	 * @return bool
	 *   TRUE when the key is set.
	 */
	private function exists(string $key): bool
	{
		$sentinel = new stdClass();

		return $this->inner->get($key, $sentinel) !== $sentinel;
	}
}
