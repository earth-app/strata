<?php

declare(strict_types=1);

namespace Drupal\strata\Tree;

use Drupal\strata\Cas\Hash;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use RuntimeException;

/**
 * A complete index of what the site held at one instant, or the change since the last one.
 *
 * The thing a replay starts from. Without an anchor, materializing a subject means walking every
 * segment back to the root of history, which is fine on a site a week old and unusable on one a year
 * old.
 *
 * **A manifest is a flat list of subjects, not a tree, and the reason is measured.** A Merkle tree
 * over the same index rewrites an interior node to record one changed leaf, which at any fanout costs
 * roughly sixteen to twenty times the entry it is recording; the earlier subtree implementation wrote
 * 3,989,289 bytes to record a 201-byte change across 50,000 subjects. A flat list of the subjects
 * that changed since the previous manifest costs the entries themselves and nothing else, and it
 * chains, so an anchor every four hours costs about 240 bytes per changed subject.
 *
 * **Two kinds.** A full manifest lists every subject and ends the chain. A delta manifest lists what
 * changed since its parent, and a NULL entry is a subject that was deleted - which has to be recorded
 * explicitly, because a delta that simply omitted it would leave the parent's entry standing and
 * resurrect it.
 *
 * Content-addressed like everything else, so writing the same manifest twice writes one object.
 *
 * @see BaseWriter
 * @see BaseReader
 * @see BasePolicy
 */
final class BaseManifest implements JsonSerializable
{
	/**
	 * Format marker, so a future change is a new version rather than a silent misread.
	 */
	public const VERSION = 1;

	/**
	 * Key prefix manifests are stored under.
	 */
	public const PREFIX = 'bases';

	/**
	 * Constructs a manifest.
	 *
	 * @param array<string, array{frames: list<string>, size: int}|null> $entries
	 *   Subject path keyed to the frames that reconstruct it and its decoded size, or NULL for a
	 *   subject that was deleted.
	 * @param string|null $parent
	 *   Address of the manifest this one changes, or NULL when it stands alone.
	 * @param bool $full
	 *   Whether the entries are the complete index. A full manifest ends the chain.
	 * @param int $microtime
	 *   Unix microseconds the manifest was written at.
	 *
	 * @throws InvalidArgumentException
	 *   When the parent is not a valid digest, or a full manifest names a parent.
	 */
	public function __construct(
		public readonly array $entries = [],
		public readonly ?string $parent = null,
		public readonly bool $full = false,
		public readonly int $microtime = 0,
	) {
		if ($parent !== null && !Hash::isValid($parent)) {
			throw new InvalidArgumentException('A manifest parent must be a valid digest');
		}
		if ($full && $parent !== null) {
			throw new InvalidArgumentException(
				'A full manifest is the whole index, so it cannot also change another one',
			);
		}
		if ($microtime < 0) {
			throw new InvalidArgumentException('A manifest time cannot be negative');
		}
	}

	/**
	 * This manifest's content address.
	 *
	 * @return string
	 *   A 64-character lowercase hex digest.
	 */
	public function address(): string
	{
		return Hash::of($this->encode());
	}

	/**
	 * How many subjects this manifest names.
	 *
	 * @return int
	 *   The count, including the deletions.
	 */
	public function count(): int
	{
		return count($this->entries);
	}

	/**
	 * Subjects this manifest records as deleted.
	 *
	 * @return list<string>
	 *   Subject paths.
	 */
	public function deletions(): array
	{
		$deleted = [];

		foreach ($this->entries as $subject => $entry) {
			if ($entry === null) {
				$deleted[] = (string) $subject;
			}
		}

		return $deleted;
	}

	/**
	 * Every frame address this manifest references.
	 *
	 * What a reachability walk collects and what a verification pass reads.
	 *
	 * @return list<string>
	 *   Frame addresses, deduplicated.
	 */
	public function frames(): array
	{
		$frames = [];

		foreach ($this->entries as $entry) {
			foreach ($entry['frames'] ?? [] as $frame) {
				$frames[$frame] = true;
			}
		}

		return array_keys($frames);
	}

	/**
	 * Decoded bytes the subjects in this manifest occupy.
	 *
	 * @return int
	 *   Bytes.
	 */
	public function size(): int
	{
		$size = 0;

		foreach ($this->entries as $entry) {
			$size += $entry['size'] ?? 0;
		}

		return $size;
	}

	/**
	 * The stored form.
	 *
	 * @return string
	 *   JSON.
	 *
	 * @throws JsonException
	 *   When a subject is not valid UTF-8. `(string) false` would otherwise write an empty anchor
	 *   addressed as `Hash::of('')`, and every commit between two anchors inherits that address.
	 */
	public function encode(): string
	{
		return json_encode($this->jsonSerialize(), JSON_THROW_ON_ERROR);
	}

	/**
	 * A manifest from its stored form.
	 *
	 * @param string $payload
	 *   The bytes.
	 *
	 * @return BaseManifest
	 *   The manifest.
	 *
	 * @throws RuntimeException
	 *   When the payload is not a manifest this release can read.
	 */
	public static function decode(string $payload): self
	{
		/** @var mixed $decoded */
		$decoded = json_decode($payload, true);

		if (!is_array($decoded) || ($decoded['v'] ?? null) !== self::VERSION) {
			throw new RuntimeException('Not a base manifest this release can read');
		}

		$entries = [];

		/** @var array<string, mixed> $raw */
		$raw = is_array($decoded['entries'] ?? null) ? $decoded['entries'] : [];

		foreach ($raw as $subject => $entry) {
			if ($entry === null) {
				$entries[(string) $subject] = null;

				continue;
			}
			if (!is_array($entry)) {
				throw new RuntimeException(
					sprintf('Base manifest entry for "%s" is not readable', (string) $subject),
				);
			}

			$frames = [];

			foreach (is_array($entry['f'] ?? null) ? $entry['f'] : [] as $frame) {
				$frames[] = (string) $frame;
			}

			$entries[(string) $subject] = ['frames' => $frames, 'size' => (int) ($entry['s'] ?? 0)];
		}

		return new self(
			$entries,
			$decoded['parent'] === null ? null : (string) $decoded['parent'],
			(bool) ($decoded['full'] ?? false),
			(int) ($decoded['microtime'] ?? 0),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The manifest as data. Entries use short keys, because they are repeated once per subject and
	 *   a full manifest for a large site has hundreds of thousands of them.
	 */
	public function jsonSerialize(): array
	{
		$entries = [];

		foreach ($this->entries as $subject => $entry) {
			$entries[(string) $subject] =
				$entry === null ? null : ['f' => $entry['frames'], 's' => $entry['size']];
		}

		return [
			'v' => self::VERSION,
			'parent' => $this->parent,
			'full' => $this->full,
			'microtime' => $this->microtime,
			'entries' => $entries,
		];
	}
}
