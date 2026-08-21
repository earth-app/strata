<?php

declare(strict_types=1);

namespace Drupal\strata\Codec;

use InvalidArgumentException;
use RuntimeException;

/**
 * Chooses which codec writes a frame, and finds the one that can read an existing frame.
 *
 * Two separate jobs, and conflating them is how a store becomes unreadable. Writing wants the best
 * codec this host can run. Reading wants whichever codec understands the id already recorded in a
 * frame header, even when that is not the one this host would choose - a bucket written on a box
 * with `ext-zstd` must stay readable on a box that only has the `zstd` binary, and a bucket written
 * years ago on gzip must stay readable forever.
 *
 * Write preference follows the figures in CodecCatalog. At 16 KiB frames zstd level 1 reaches
 * 4.28x at 422.9 MB/s against gzip level 9's 4.40x at 67.0 MB/s, so zstd wins the flush path on
 * throughput. Dictionary support outranks raw ratio, because DeltaCodec cannot produce a delta
 * without it.
 *
 * ZstdPipeCodec is never the hot-path choice. A process spawn dominates the work at frame sizes -
 * 1,000 invocations measured 5.88 seconds - so it is registered for reading and for batch work,
 * and a host without the extension gets gzip on the flush path instead.
 *
 * @see CompressionCodecInterface
 * @see DeltaCodec
 */
final class CodecRegistry
{
	/**
	 * Write preference, best first.
	 *
	 * Ordered by measured ratio with dictionary support weighted above it, because a codec that
	 * cannot take a dictionary cannot produce a delta.
	 */
	private const WRITE_PREFERENCE = ['zstd', 'brotli', 'gzip', 'none'];

	/**
	 * Codec id pinned by configuration, or NULL to follow the write preference.
	 */
	private ?string $pinned = null;

	/**
	 * Codecs able to write a single frame, keyed by id. First registration for an id wins.
	 *
	 * @var array<string, CompressionCodecInterface>
	 */
	private array $writers = [];

	/**
	 * Codecs able to write in bulk but not per frame, keyed by id.
	 *
	 * @var array<string, CompressionCodecInterface>
	 */
	private array $bulkWriters = [];

	/**
	 * Every registered codec able to read a given id, in registration order.
	 *
	 * @var array<string, list<CompressionCodecInterface>>
	 */
	private array $readers = [];

	/**
	 * Builds a registry holding every codec Strata ships.
	 *
	 * Registration order matters for readers: the extension is tried before the binary, so a host
	 * with both never pays a process spawn to read a frame.
	 *
	 * @return self
	 *   A registry with the shipped codecs registered.
	 */
	public static function withShippedCodecs(): self
	{
		$registry = new self();

		$registry->register(new ZstdCodec());
		// a spawn per frame is 5.9 ms against sub-millisecond compression, so the binary is
		// registered for reading and batch work only and never wins the flush path
		$registry->register(new ZstdPipeCodec(), false);
		$registry->register(new BrotliCodec());
		$registry->register(new GzipCodec());
		$registry->register(new NoneCodec());

		return $registry;
	}

	/**
	 * Adds a codec.
	 *
	 * Registering an unavailable codec is not an error and is in fact required: it is what lets
	 * CodecRegistry::unavailable() explain to an administrator why a better codec is not being used.
	 *
	 * @param CompressionCodecInterface $codec
	 *   The codec to register.
	 * @param bool $perFrame
	 *   Whether this codec may be chosen to compress a single frame on the flush path. Pass FALSE
	 *   for an implementation whose per-call overhead dominates at frame sizes, such as one that
	 *   shells out; it stays available for reading and for batch work.
	 *
	 * @return $this
	 *   The registry, for chaining.
	 */
	public function register(CompressionCodecInterface $codec, bool $perFrame = true): self
	{
		$id = $codec->id();

		$this->readers[$id][] = $codec;

		if (!$codec->isAvailable()) {
			return $this;
		}

		if ($perFrame && !isset($this->writers[$id])) {
			$this->writers[$id] = $codec;
		}
		if (!isset($this->bulkWriters[$id])) {
			$this->bulkWriters[$id] = $codec;
		}

		return $this;
	}

	/**
	 * Pins the codec that writes frames, overriding the measured preference.
	 *
	 * An administrator who has calibrated on their own data outranks the shipped ordering. Pinning
	 * only changes what is WRITTEN; every registered reader stays available, so pinning gzip on a
	 * bucket full of zstd frames leaves that bucket readable.
	 *
	 * @param string $id
	 *   The codec id to write with, or an empty string to follow the preference.
	 *
	 * @return $this
	 *   The registry, for chaining.
	 *
	 * @throws InvalidArgumentException
	 *   When the id is not registered as a per-frame writer on this host. Falling back silently
	 *   would leave an administrator believing a setting took effect when it did not.
	 */
	public function prefer(string $id): self
	{
		if ($id === '') {
			$this->pinned = null;

			return $this;
		}
		if (!isset($this->writers[$id])) {
			throw new InvalidArgumentException(
				sprintf(
					'Codec "%s" cannot write frames on this host; available: %s',
					$id,
					implode(', ', array_keys($this->writers)) ?: 'none',
				),
			);
		}

		$this->pinned = $id;

		return $this;
	}

	/**
	 * Whether any registered codec can read frames written under an id.
	 *
	 * @param string $id
	 *   A codec id as recorded in a frame header.
	 *
	 * @return bool
	 *   TRUE when at least one available codec understands it.
	 */
	public function canRead(string $id): bool
	{
		foreach ($this->readers[$id] ?? [] as $codec) {
			if ($codec->isAvailable()) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a codec can be chosen to compress a single frame on the flush path.
	 *
	 * Distinct from CodecRegistry::canRead(). A host with the `zstd` binary but no `ext-zstd` can
	 * read every zstd frame in the bucket and can use it for compaction, but must not use it per
	 * frame, because a process spawn dominates at frame sizes. Reporting that as plain availability
	 * would tell an administrator the extension is unnecessary while the flush path has fallen back
	 * to gzip.
	 *
	 * @param string $id
	 *   A codec id.
	 *
	 * @return bool
	 *   TRUE when this codec may compress an individual frame here.
	 */
	public function canWritePerFrame(string $id): bool
	{
		return isset($this->writers[$id]);
	}

	/**
	 * The codec that can read frames written under an id.
	 *
	 * @param string $id
	 *   A codec id as recorded in a frame header.
	 *
	 * @return CompressionCodecInterface
	 *   An available codec that understands that id.
	 *
	 * @throws RuntimeException
	 *   When nothing on this host can read it. The message names what is missing, because this is
	 *   the error an administrator sees when a restore hits a frame their PHP cannot decode.
	 */
	public function reader(string $id): CompressionCodecInterface
	{
		foreach ($this->readers[$id] ?? [] as $codec) {
			if ($codec->isAvailable()) {
				return $codec;
			}
		}

		$reasons = [];
		foreach ($this->readers[$id] ?? [] as $codec) {
			$reasons[] = (string) $codec->unavailableReason();
		}

		if ($reasons === []) {
			throw new RuntimeException(
				sprintf(
					'No codec is registered for "%s"; the frame was written by a newer Strata or a ' .
						'contributed codec that is not installed',
					$id,
				),
			);
		}

		throw new RuntimeException(
			sprintf(
				'Cannot read frames written with "%s" on this host: %s',
				$id,
				implode('; ', array_unique($reasons)),
			),
		);
	}

	/**
	 * The codec to write the flush path with.
	 *
	 * Throughput matters more than ratio here, and the level the caller passes decides the rest.
	 *
	 * @return CompressionCodecInterface
	 *   The best available writer, never NULL because NoneCodec is always available.
	 *
	 * @throws RuntimeException
	 *   When no codec at all is registered, which means the registry was built by hand and left
	 *   empty.
	 */
	public function writer(): CompressionCodecInterface
	{
		if ($this->pinned !== null) {
			return $this->writers[$this->pinned];
		}

		foreach (self::WRITE_PREFERENCE as $id) {
			if (isset($this->writers[$id])) {
				return $this->writers[$id];
			}
		}

		// a codec registered by an add-on under an id Strata does not rank still beats nothing
		foreach ($this->writers as $codec) {
			return $codec;
		}

		throw new RuntimeException('No compression codec is registered');
	}

	/**
	 * The codec to write with when a dictionary is required.
	 *
	 * Delta coding on the flush path needs this. Returns NULL rather than falling back, because a
	 * caller that silently accepted a non-dictionary codec would write frames whose headers claim a
	 * dictionary they were not compressed with. A host with only the zstd binary gets NULL here and
	 * a usable codec from CodecRegistry::bulkWriter(): it can produce deltas during compaction but
	 * not on the request path.
	 *
	 * @return CompressionCodecInterface|null
	 *   The best available per-frame dictionary-capable writer, or NULL when this host has none.
	 */
	public function dictionaryWriter(): ?CompressionCodecInterface
	{
		foreach (self::WRITE_PREFERENCE as $id) {
			$codec = $this->writers[$id] ?? null;
			if ($codec !== null && $codec->supportsDictionary()) {
				return $codec;
			}
		}

		return null;
	}

	/**
	 * The codec to run bulk work with, which may spawn a process.
	 *
	 * Compaction, dictionary training and export are batch operations off the request path, so a
	 * binary that costs a spawn per batch is acceptable there and buys the better ratio.
	 *
	 * @return CompressionCodecInterface
	 *   The best available codec for batch work.
	 *
	 * @throws RuntimeException
	 *   When no codec at all is registered.
	 */
	public function bulkWriter(): CompressionCodecInterface
	{
		foreach (self::WRITE_PREFERENCE as $id) {
			$codec = $this->bulkWriters[$id] ?? null;
			if ($codec !== null && $codec->supportsDictionary()) {
				return $codec;
			}
		}
		foreach (self::WRITE_PREFERENCE as $id) {
			if (isset($this->bulkWriters[$id])) {
				return $this->bulkWriters[$id];
			}
		}

		return $this->writer();
	}

	/**
	 * A registered codec by id, whether or not it can run.
	 *
	 * @param string $id
	 *   A codec id.
	 *
	 * @return CompressionCodecInterface
	 *   The first codec registered under that id.
	 *
	 * @throws InvalidArgumentException
	 *   When nothing is registered under that id.
	 */
	public function get(string $id): CompressionCodecInterface
	{
		if (!isset($this->readers[$id][0])) {
			throw new InvalidArgumentException(sprintf('No codec is registered as "%s"', $id));
		}

		return $this->readers[$id][0];
	}

	/**
	 * Ids of every codec that can run on this host.
	 *
	 * @return list<string>
	 *   Codec ids, in write-preference order followed by any unranked additions.
	 */
	public function available(): array
	{
		$ids = [];

		foreach (self::WRITE_PREFERENCE as $id) {
			if (isset($this->writers[$id])) {
				$ids[] = $id;
			}
		}
		foreach (array_keys($this->writers) as $id) {
			if (!in_array($id, $ids, true)) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Why each unavailable codec cannot run, for the settings form and hook_requirements().
	 *
	 * A codec with several implementations reports one reason per implementation, because "ext-zstd
	 * is not loaded" and "the zstd binary was not found on PATH" are different things to fix.
	 *
	 * @return array<string, list<string>>
	 *   Codec id keyed to the distinct reasons its implementations gave.
	 */
	public function unavailable(): array
	{
		$reasons = [];

		foreach ($this->readers as $id => $codecs) {
			if (isset($this->writers[$id])) {
				continue;
			}
			foreach ($codecs as $codec) {
				$reason = $codec->unavailableReason();
				if ($reason !== null && !in_array($reason, $reasons[$id] ?? [], true)) {
					$reasons[$id][] = $reason;
				}
			}
		}

		return $reasons;
	}
}
