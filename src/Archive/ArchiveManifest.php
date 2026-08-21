<?php

declare(strict_types=1);

namespace Drupal\strata\Archive;

use JsonSerializable;

/**
 * What an archive holds, and what it is missing.
 *
 * An archive is only useful if it can be restored somewhere else, so the manifest exists to answer
 * one question before anything is unpacked: is this self-contained? The three lists it carries are
 * the three reachability classes - commits, the frames they resolve through, and the dictionaries
 * and delta anchors those frames decode against. An archive missing any one of them holds bytes
 * that cannot be read, which is worse than holding nothing, because it looks like a backup.
 *
 * `problems` is the honest field. An export that could not read an object records it here rather
 * than omitting it silently, and `isComplete()` is false for the rest of the archive's life.
 *
 * @see ArchiveExporter
 * @see ArchiveImporter
 */
final class ArchiveManifest implements JsonSerializable
{
	/**
	 * The manifest format, so an archive from a later release is refused rather than misread.
	 */
	public const VERSION = 1;

	/**
	 * The file the manifest lives in inside the archive.
	 */
	public const FILE = 'strata-manifest.json';

	/**
	 * Constructs a manifest.
	 *
	 * @param string $site
	 *   The site id the history was exported from.
	 * @param int $createdAt
	 *   Unix seconds the archive was written.
	 * @param list<string> $commits
	 *   Commit ids the archive carries, oldest first.
	 * @param list<string> $frames
	 *   Frame content addresses the archive carries.
	 * @param list<string> $dictionaries
	 *   Dictionary ids the archive carries.
	 * @param list<string> $objects
	 *   Every object key in the archive, so an import needs no directory walk.
	 * @param int $bytes
	 *   Bytes of stored objects, not counting the archive's own framing.
	 * @param list<string> $problems
	 *   What could not be read, one line each.
	 * @param string|null $head
	 *   The commit the exported ref pointed at, or NULL when no ref was exported.
	 * @param int $version
	 *   The manifest format version.
	 */
	public function __construct(
		public readonly string $site,
		public readonly int $createdAt,
		public readonly array $commits = [],
		public readonly array $frames = [],
		public readonly array $dictionaries = [],
		public readonly array $objects = [],
		public readonly int $bytes = 0,
		public readonly array $problems = [],
		public readonly ?string $head = null,
		public readonly int $version = self::VERSION,
	) {}

	/**
	 * Whether everything the archive references is inside it.
	 *
	 * @return bool
	 *   TRUE when nothing was recorded as missing.
	 */
	public function isComplete(): bool
	{
		return $this->problems === [];
	}

	/**
	 * How many objects the archive holds.
	 *
	 * @return int
	 *   The count.
	 */
	public function count(): int
	{
		return count($this->objects);
	}

	/**
	 * A one-line summary for a command line or a log.
	 *
	 * @return string
	 *   What the archive holds.
	 */
	public function summary(): string
	{
		$summary = sprintf(
			'%d commits, %d frames, %d dictionaries, %d objects, %s',
			count($this->commits),
			count($this->frames),
			count($this->dictionaries),
			count($this->objects),
			$this->formatBytes($this->bytes),
		);

		if ($this->problems !== []) {
			$summary .= sprintf(' (incomplete: %d problems)', count($this->problems));
		}

		return $summary;
	}

	/**
	 * {@inheritdoc}
	 */
	public function jsonSerialize(): array
	{
		return [
			'version' => $this->version,
			'site' => $this->site,
			'created_at' => $this->createdAt,
			'head' => $this->head,
			'commits' => $this->commits,
			'frames' => $this->frames,
			'dictionaries' => $this->dictionaries,
			'objects' => $this->objects,
			'bytes' => $this->bytes,
			'problems' => $this->problems,
		];
	}

	/**
	 * Rebuilds a manifest from what an archive carries.
	 *
	 * @param array<string, mixed> $data
	 *   The decoded manifest file.
	 *
	 * @return self
	 *   The manifest.
	 */
	public static function fromArray(array $data): self
	{
		return new self(
			(string) ($data['site'] ?? ''),
			(int) ($data['created_at'] ?? 0),
			self::strings($data['commits'] ?? []),
			self::strings($data['frames'] ?? []),
			self::strings($data['dictionaries'] ?? []),
			self::strings($data['objects'] ?? []),
			(int) ($data['bytes'] ?? 0),
			self::strings($data['problems'] ?? []),
			isset($data['head']) ? (string) $data['head'] : null,
			(int) ($data['version'] ?? self::VERSION),
		);
	}

	/**
	 * Reduces a decoded value to a list of strings.
	 *
	 * @param mixed $value
	 *   The decoded value.
	 *
	 * @return list<string>
	 *   The strings it held.
	 */
	private static function strings(mixed $value): array
	{
		return is_array($value) ? array_values(array_map('strval', $value)) : [];
	}

	/**
	 * Renders a byte count in the largest unit that stays readable.
	 *
	 * @param int $bytes
	 *   The count.
	 *
	 * @return string
	 *   The rendered size.
	 */
	private function formatBytes(int $bytes): string
	{
		$units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
		$value = (float) $bytes;
		$unit = 0;

		while ($value >= 1024.0 && $unit < count($units) - 1) {
			$value /= 1024.0;
			$unit++;
		}

		return $unit === 0
			? sprintf('%d %s', (int) $value, $units[$unit])
			: sprintf('%.1f %s', $value, $units[$unit]);
	}
}
