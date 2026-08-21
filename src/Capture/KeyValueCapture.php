<?php

declare(strict_types=1);

namespace Drupal\strata\Capture;

use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Closure;
use Drupal\strata\Journal\Realm;

/**
 * Records key-value writes by wrapping one collection's store.
 *
 * Key-value has no events either, and unlike state it comes from a factory, so the factory is
 * decorated and each store it hands out is wrapped in one of these.
 *
 * The subject is `collection:key`, so two collections holding the same key are two subjects and a
 * restore of one does not touch the other.
 *
 * @see KeyValueCaptureFactory
 * @see KeyRecorder
 */
final class KeyValueCapture implements KeyValueStoreInterface
{
	/**
	 * The recorder once a closure has been called, so it is resolved once per store.
	 */
	private ?KeyRecorder $resolved = null;

	/**
	 * Constructs the decorator.
	 *
	 * @param KeyValueStoreInterface $inner
	 *   The store being wrapped.
	 * @param KeyRecorder|Closure $recorder
	 *   Journals each write, or a closure returning one.
	 * @param string $collection
	 *   The collection name, which becomes part of the subject path.
	 */
	public function __construct(
		private readonly KeyValueStoreInterface $inner,
		private readonly KeyRecorder|Closure $recorder,
		private readonly string $collection,
	) {}

	/**
	 * The recorder, resolved on first use.
	 *
	 * A closure rather than a recorder is what a site is wired with, because `keyvalue` is asked for
	 * a store while the container is still compiling and a recorder cannot be built then. Reading a
	 * value never resolves it; only a write does, by which time the container is finished.
	 *
	 * @return KeyRecorder
	 *   The recorder.
	 */
	private function recorder(): KeyRecorder
	{
		if ($this->recorder instanceof KeyRecorder) {
			return $this->recorder;
		}

		return $this->resolved ??= ($this->recorder)();
	}

	#region Reads

	/**
	 * {@inheritdoc}
	 */
	public function getCollectionName()
	{
		return $this->inner->getCollectionName();
	}

	/**
	 * {@inheritdoc}
	 */
	public function has($key)
	{
		return $this->inner->has($key);
	}

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
	public function getAll()
	{
		return $this->inner->getAll();
	}

	/**
	 * {@inheritdoc}
	 */
	public function getAllKeys(): iterable
	{
		return $this->inner->getAllKeys();
	}

	#endregion

	#region Writes

	/**
	 * {@inheritdoc}
	 */
	public function set($key, $value)
	{
		if ($this->covers()) {
			$this->recorder()->written(
				Realm::KEY_VALUE,
				$this->subject($key),
				$value,
				$this->inner->has($key),
			);
		}

		$this->inner->set($key, $value);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Recorded only when the write actually happened. The whole point of this method is that it does
	 * nothing when the key is taken, and recording a change that did not occur would put a value in
	 * the history that was never in the site.
	 */
	public function setIfNotExists($key, $value)
	{
		$written = $this->inner->setIfNotExists($key, $value);

		if ($written && $this->covers()) {
			$this->recorder()->written(Realm::KEY_VALUE, $this->subject($key), $value, false);
		}

		return $written;
	}

	/**
	 * {@inheritdoc}
	 */
	public function setMultiple(array $data)
	{
		if ($this->covers() && $data !== []) {
			$existing = $this->inner->getMultiple(array_keys($data));

			foreach ($data as $key => $value) {
				$this->recorder()->written(
					Realm::KEY_VALUE,
					$this->subject((string) $key),
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
	public function rename($key, $new_key)
	{
		if ($this->covers() && $this->inner->has($key)) {
			$this->recorder()->renamed(
				Realm::KEY_VALUE,
				$this->subject($key),
				$this->subject($new_key),
				$this->inner->get($key),
			);
		}

		$this->inner->rename($key, $new_key);
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete($key)
	{
		if ($this->covers() && $this->inner->has($key)) {
			$this->recorder()->deleted(Realm::KEY_VALUE, $this->subject($key));
		}

		$this->inner->delete($key);
	}

	/**
	 * {@inheritdoc}
	 */
	public function deleteMultiple(array $keys)
	{
		if ($this->covers() && $keys !== []) {
			$existing = $this->inner->getMultiple($keys);

			foreach ($keys as $key) {
				if (array_key_exists($key, $existing)) {
					$this->recorder()->deleted(Realm::KEY_VALUE, $this->subject((string) $key));
				}
			}
		}

		$this->inner->deleteMultiple($keys);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Every key is recorded individually rather than as one collection-level operation, because a
	 * restore puts keys back one at a time and an operation naming the collection would give it
	 * nothing to put back.
	 */
	public function deleteAll()
	{
		if ($this->covers()) {
			foreach (array_keys($this->inner->getAll()) as $key) {
				$this->recorder()->deleted(Realm::KEY_VALUE, $this->subject((string) $key));
			}
		}

		$this->inner->deleteAll();
	}

	#endregion

	/**
	 * Whether this collection's writes are recorded.
	 *
	 * @return bool
	 *   TRUE when the key-value realm is captured.
	 */
	private function covers(): bool
	{
		return $this->recorder()->covers(Realm::KEY_VALUE);
	}

	/**
	 * The subject key one entry is recorded under.
	 *
	 * @param string $key
	 *   The entry's key.
	 *
	 * @return string
	 *   Shaped `collection:key`.
	 */
	private function subject(string $key): string
	{
		return $this->collection . ':' . $key;
	}
}
