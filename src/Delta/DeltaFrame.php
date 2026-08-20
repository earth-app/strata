<?php

declare(strict_types=1);

namespace Drupal\strata\Delta;

use Drupal\strata\Cas\Hash;
use InvalidArgumentException;
use JsonSerializable;

/**
 * One encoded value, either self-contained or expressed against its previous version.
 *
 * A value object rather than an array so a typo in a key is a type error instead of a silently
 * absent field, and so the two modes cannot be confused: an anchor has no parent and depth zero, a
 * delta has both, and the constructor refuses any other combination. That invariant is what lets
 * DeltaCodec::decode() trust the frame it is handed.
 *
 * @see DeltaCodec
 * @see ChainDepthPolicy
 */
final class DeltaFrame implements JsonSerializable
{
	/**
	 * A frame that decodes with nothing else.
	 */
	public const MODE_ANCHOR = 'anchor';

	/**
	 * A frame that decodes only against its parent.
	 */
	public const MODE_DELTA = 'delta';

	/**
	 * Constructs a frame.
	 *
	 * @param string $mode
	 *   DeltaFrame::MODE_ANCHOR or DeltaFrame::MODE_DELTA.
	 * @param string $codec
	 *   The codec id the bytes were produced by, as recorded for the store.
	 * @param string $bytes
	 *   The encoded bytes.
	 * @param string|null $parent
	 *   Digest of the value this frame was encoded against, or NULL for an anchor.
	 * @param int $depth
	 *   Links between this frame and its anchor; zero for an anchor.
	 * @param int $plainLength
	 *   Length of the decoded value, so a caller can size a buffer and a verifier can catch a
	 *   truncated decode without decoding twice.
	 *
	 * @throws InvalidArgumentException
	 *   When the mode, parent and depth do not describe a coherent frame.
	 */
	public function __construct(
		public readonly string $mode,
		public readonly string $codec,
		public readonly string $bytes,
		public readonly ?string $parent = null,
		public readonly int $depth = 0,
		public readonly int $plainLength = 0,
	) {
		if ($mode !== self::MODE_ANCHOR && $mode !== self::MODE_DELTA) {
			throw new InvalidArgumentException(sprintf('Unknown delta frame mode "%s"', $mode));
		}
		if ($codec === '') {
			throw new InvalidArgumentException('A delta frame must record the codec that wrote it');
		}
		if ($depth < 0) {
			throw new InvalidArgumentException('Delta frame depth cannot be negative');
		}
		if ($plainLength < 0) {
			throw new InvalidArgumentException('Delta frame plain length cannot be negative');
		}

		if ($mode === self::MODE_ANCHOR) {
			if ($parent !== null) {
				throw new InvalidArgumentException('An anchor frame cannot have a parent');
			}
			if ($depth !== 0) {
				throw new InvalidArgumentException('An anchor frame is always at depth zero');
			}

			return;
		}

		if ($parent === null) {
			throw new InvalidArgumentException('A delta frame must name its parent');
		}
		if (!Hash::isValid($parent)) {
			throw new InvalidArgumentException('A delta frame parent must be a valid digest');
		}
		if ($depth < 1) {
			throw new InvalidArgumentException('A delta frame is always at depth one or more');
		}
	}

	/**
	 * Whether this frame decodes without any other.
	 *
	 * @return bool
	 *   TRUE for an anchor.
	 */
	public function isAnchor(): bool
	{
		return $this->mode === self::MODE_ANCHOR;
	}

	/**
	 * Encoded size in bytes.
	 *
	 * @return int
	 *   Length of the encoded bytes.
	 */
	public function length(): int
	{
		return strlen($this->bytes);
	}

	/**
	 * How much smaller the encoding is than the value it encodes.
	 *
	 * @return float
	 *   Plain length divided by encoded length; 1.0 when either is zero.
	 */
	public function ratio(): float
	{
		if ($this->plainLength === 0 || $this->length() === 0) {
			return 1.0;
		}

		return $this->plainLength / $this->length();
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   Keys mode, codec, parent, depth and plainLength verbatim, plus bytes base64-encoded so the
	 *   frame survives JSON.
	 */
	public function jsonSerialize(): array
	{
		return [
			'mode' => $this->mode,
			'codec' => $this->codec,
			'parent' => $this->parent,
			'depth' => $this->depth,
			'plainLength' => $this->plainLength,
			'bytes' => base64_encode($this->bytes),
		];
	}

	/**
	 * Rebuilds a frame from its serialized form.
	 *
	 * @param array<string, mixed> $data
	 *   The array produced by DeltaFrame::jsonSerialize().
	 *
	 * @return self
	 *   The frame.
	 *
	 * @throws InvalidArgumentException
	 *   When a required key is missing, or the bytes are not valid base64.
	 */
	public static function fromArray(array $data): self
	{
		foreach (['mode', 'codec', 'bytes'] as $required) {
			if (!array_key_exists($required, $data)) {
				throw new InvalidArgumentException(
					sprintf('Delta frame is missing "%s"', $required),
				);
			}
		}

		$bytes = base64_decode((string) $data['bytes'], true);
		if ($bytes === false) {
			throw new InvalidArgumentException('Delta frame bytes are not valid base64');
		}

		$parent = $data['parent'] ?? null;

		return new self(
			(string) $data['mode'],
			(string) $data['codec'],
			$bytes,
			$parent === null ? null : (string) $parent,
			(int) ($data['depth'] ?? 0),
			(int) ($data['plainLength'] ?? 0),
		);
	}
}
