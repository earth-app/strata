<?php

declare(strict_types=1);

namespace Drupal\strata\Cas;

use InvalidArgumentException;
use JsonSerializable;

/**
 * What the index knows about one stored frame.
 *
 * Enough to fetch and decode the frame without reading anything else: which object holds it, where
 * inside that object it starts, which codec and cipher wrote it, and which dictionary it needs.
 * A frame whose dictionary is unknown cannot be decoded at all, so the reference is part of the
 * record rather than something inferred from the store's current settings, which may have changed
 * since the frame was written.
 *
 * @see FrameIndexInterface
 * @see ObjectStore
 */
final class FrameRecord implements JsonSerializable
{
	/**
	 * Constructs a record.
	 *
	 * @param string $hash
	 *   Content address of the decoded frame.
	 * @param int $rawSize
	 *   Decoded size in bytes.
	 * @param int $storedSize
	 *   Encoded size in bytes, after compression and sealing.
	 * @param string $codec
	 *   Codec id the frame was compressed with.
	 * @param string $cipher
	 *   Cipher id the frame was sealed with.
	 * @param string|null $dictionary
	 *   Dictionary id the codec needs, or NULL when none was used.
	 * @param string|null $pack
	 *   Pack object id the frame lives inside, or NULL when it is its own object.
	 * @param int $offset
	 *   Byte offset within the pack; zero for a standalone frame.
	 * @param int $references
	 *   How many segments, trees or file maps point at this frame. A frame at zero is collectable.
	 * @param int $created
	 *   Unix timestamp the frame was first stored.
	 * @param string|null $deltaParent
	 *   Content address of the frame this one is a delta against, or NULL when it stands alone. A
	 *   prune must keep the whole chain: a frame whose parent has been collected decodes to
	 *   nothing, so this is the third thing reachability protects, alongside commits and
	 *   dictionaries.
	 * @param int $deltaDepth
	 *   Position in the delta chain; zero for an anchor.
	 *
	 * @throws InvalidArgumentException
	 *   When the hash is not a valid digest, a size or offset is negative, the reference count is
	 *   negative, an offset is given without a pack, or a delta depth is given without a parent.
	 */
	public function __construct(
		public readonly string $hash,
		public readonly int $rawSize,
		public readonly int $storedSize,
		public readonly string $codec,
		public readonly string $cipher,
		public readonly ?string $dictionary = null,
		public readonly ?string $pack = null,
		public readonly int $offset = 0,
		public readonly int $references = 1,
		public readonly int $created = 0,
		public readonly ?string $deltaParent = null,
		public readonly int $deltaDepth = 0,
	) {
		if (!Hash::isValid($hash)) {
			throw new InvalidArgumentException('A frame record needs a valid content address');
		}
		if ($codec === '' || $cipher === '') {
			throw new InvalidArgumentException(
				'A frame record must name the codec and cipher that wrote it',
			);
		}
		if ($rawSize < 0 || $storedSize < 0) {
			throw new InvalidArgumentException('A frame size cannot be negative');
		}
		if ($offset < 0) {
			throw new InvalidArgumentException('A frame offset cannot be negative');
		}
		if ($references < 0) {
			throw new InvalidArgumentException('A reference count cannot be negative');
		}
		if ($pack === null && $offset !== 0) {
			throw new InvalidArgumentException(
				'A frame with no pack starts at offset zero by definition',
			);
		}
		if ($deltaParent !== null && !Hash::isValid($deltaParent)) {
			throw new InvalidArgumentException('A delta parent must be a valid content address');
		}
		if ($deltaParent === null && $deltaDepth !== 0) {
			throw new InvalidArgumentException(
				'A frame with no delta parent is an anchor and sits at depth zero',
			);
		}
		if ($deltaParent !== null && $deltaDepth < 1) {
			throw new InvalidArgumentException(
				'A frame encoded against a parent sits at least one link into the chain',
			);
		}
	}

	/**
	 * Whether this frame needs another frame to decode.
	 *
	 * @return bool
	 *   TRUE when it is a delta against a parent.
	 */
	public function isDelta(): bool
	{
		return $this->deltaParent !== null;
	}

	/**
	 * Whether this frame shares an object with others.
	 *
	 * @return bool
	 *   TRUE when it lives inside a pack.
	 */
	public function isPacked(): bool
	{
		return $this->pack !== null;
	}

	/**
	 * Whether anything still points at this frame.
	 *
	 * @return bool
	 *   TRUE when the reference count has reached zero.
	 */
	public function isOrphan(): bool
	{
		return $this->references === 0;
	}

	/**
	 * How much smaller the stored form is than the frame.
	 *
	 * @return float
	 *   Raw size divided by stored size; 1.0 when either is zero.
	 */
	public function ratio(): float
	{
		if ($this->rawSize === 0 || $this->storedSize === 0) {
			return 1.0;
		}

		return $this->rawSize / $this->storedSize;
	}

	/**
	 * The same record with a different reference count.
	 *
	 * @param int $references
	 *   The new count.
	 *
	 * @return self
	 *   A new record.
	 *
	 * @throws InvalidArgumentException
	 *   When the count is negative.
	 */
	public function withReferences(int $references): self
	{
		return new self(
			$this->hash,
			$this->rawSize,
			$this->storedSize,
			$this->codec,
			$this->cipher,
			$this->dictionary,
			$this->pack,
			$this->offset,
			$references,
			$this->created,
			$this->deltaParent,
			$this->deltaDepth,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The record as a plain array.
	 */
	public function jsonSerialize(): array
	{
		return [
			'hash' => $this->hash,
			'rawSize' => $this->rawSize,
			'storedSize' => $this->storedSize,
			'codec' => $this->codec,
			'cipher' => $this->cipher,
			'dictionary' => $this->dictionary,
			'pack' => $this->pack,
			'offset' => $this->offset,
			'references' => $this->references,
			'created' => $this->created,
			'deltaParent' => $this->deltaParent,
			'deltaDepth' => $this->deltaDepth,
		];
	}

	/**
	 * Rebuilds a record from its serialized form.
	 *
	 * @param array<string, mixed> $data
	 *   The array produced by FrameRecord::jsonSerialize().
	 *
	 * @return self
	 *   The record.
	 *
	 * @throws InvalidArgumentException
	 *   When a required key is missing or a value is out of range.
	 */
	public static function fromArray(array $data): self
	{
		foreach (['hash', 'codec', 'cipher'] as $required) {
			if (!array_key_exists($required, $data)) {
				throw new InvalidArgumentException(
					sprintf('A frame record is missing "%s"', $required),
				);
			}
		}

		return new self(
			(string) $data['hash'],
			(int) ($data['rawSize'] ?? 0),
			(int) ($data['storedSize'] ?? 0),
			(string) $data['codec'],
			(string) $data['cipher'],
			isset($data['dictionary']) ? (string) $data['dictionary'] : null,
			isset($data['pack']) ? (string) $data['pack'] : null,
			(int) ($data['offset'] ?? 0),
			(int) ($data['references'] ?? 1),
			(int) ($data['created'] ?? 0),
			isset($data['deltaParent']) ? (string) $data['deltaParent'] : null,
			(int) ($data['deltaDepth'] ?? 0),
		);
	}
}
