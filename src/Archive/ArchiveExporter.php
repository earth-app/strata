<?php

declare(strict_types=1);

namespace Drupal\strata\Archive;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Archiver\ArchiveTar;
use Drupal\strata\Cas\FrameIndexInterface;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Codec\Dictionary\DictionaryRef;
use Drupal\strata\Codec\Dictionary\DictionaryStore;
use Drupal\strata\Segment\SegmentReader;
use Drupal\strata\Site\SiteContext;
use Drupal\strata\Storage\StorageProviderInterface;
use Drupal\strata\Tree\BaseManifest;
use Drupal\strata\Tree\BaseReader;
use Drupal\strata\Tree\Commit;
use Drupal\strata\Tree\CommitLog;
use Drupal\strata\Tree\RefStore;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Writes a span of history into one portable file.
 *
 * The archive is the bucket's own layout, tarred - object keys become paths inside the tar, so an
 * import is a copy rather than a translation, and a human can open one and read it with no tooling.
 *
 * **What makes it self-contained is the closure, not the commits.** A commit resolves through a base
 * anchor, an anchor names frames, a frame may be a delta against a parent frame and may need a
 * dictionary to decompress. Exporting only the commits would produce an archive that unpacks
 * cleanly and restores nothing. So each commit pulls in its whole chain of anchors, every frame
 * those anchors and segments reference, every delta parent up the chain, and every dictionary any
 * of those frames names.
 *
 * A frame that lives inside a pack pulls the whole pack, which carries frames belonging to other
 * commits too. That costs archive size and buys correctness: a pack is one object, and slicing one
 * frame out of it would mean rewriting the pack index.
 *
 * @see ArchiveManifest
 * @see ArchiveImporter
 */
final class ArchiveExporter
{
	/**
	 * Commits one export walks when no limit is given.
	 *
	 * An archive is a file someone downloads, so the default is bounded. `--limit=0` lifts it.
	 */
	public const DEFAULT_LIMIT = 500;

	/**
	 * Constructs an exporter.
	 *
	 * @param StorageProviderInterface $provider
	 *   Where the objects are read from.
	 * @param CommitLog $commits
	 *   Walks the history.
	 * @param RefStore $refs
	 *   Names the head the export starts from.
	 * @param SegmentReader $segments
	 *   Reads which frames a segment references.
	 * @param BaseReader $bases
	 *   Reads which frames an anchor references.
	 * @param FrameIndexInterface $index
	 *   Resolves a frame to the object it lives in, and to its delta parent.
	 * @param DictionaryStore $dictionaries
	 *   Resolves a dictionary id to its object key.
	 * @param SiteContext $site
	 *   Names the site in the manifest.
	 * @param TimeInterface $time
	 *   Stamps the manifest.
	 * @param LoggerInterface $logger
	 *   Records what the export could not read.
	 */
	public function __construct(
		private readonly StorageProviderInterface $provider,
		private readonly CommitLog $commits,
		private readonly RefStore $refs,
		private readonly SegmentReader $segments,
		private readonly BaseReader $bases,
		private readonly FrameIndexInterface $index,
		private readonly DictionaryStore $dictionaries,
		private readonly SiteContext $site,
		private readonly TimeInterface $time,
		private readonly LoggerInterface $logger,
	) {}

	/**
	 * Writes an archive.
	 *
	 * @param string $path
	 *   Where to write. A `.gz` or `.tgz` suffix compresses the archive.
	 * @param string|null $fromCommit
	 *   Commit to walk back from, or NULL for the current head.
	 * @param int|null $limit
	 *   Commits to include, or NULL for the default; 0 for every commit.
	 *
	 * @return ArchiveManifest
	 *   What was written.
	 *
	 * @throws RuntimeException
	 *   When the archive file cannot be created, or the history is empty.
	 */
	public function export(
		string $path,
		?string $fromCommit = null,
		?int $limit = null,
	): ArchiveManifest {
		$head = $fromCommit ?? $this->refs->read();

		if ($head === null) {
			throw new RuntimeException('There is no history to export');
		}

		$closure = $this->closure($head, $limit ?? self::DEFAULT_LIMIT);
		$archive = new ArchiveTar($path, $this->compression($path));
		$bytes = 0;
		$written = [];

		foreach ($closure['objects'] as $key) {
			$body = $this->read($key, $closure['problems']);

			if ($body === null) {
				continue;
			}

			$archive->addString($key, $body);
			$written[] = $key;
			$bytes += strlen($body);
		}

		$manifest = new ArchiveManifest(
			$this->site->id(),
			$this->time->getRequestTime(),
			$closure['commits'],
			$closure['frames'],
			$closure['dictionaries'],
			$written,
			$bytes,
			$closure['problems'],
			$head,
		);

		$archive->addString(
			ArchiveManifest::FILE,
			(string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
		);

		if (!$manifest->isComplete()) {
			$this->logger->warning('Strata exported an incomplete archive to @path: @summary', [
				'@path' => $path,
				'@summary' => $manifest->summary(),
			]);
		}

		return $manifest;
	}

	#region Closure

	/**
	 * Everything an archive needs to be restorable on its own.
	 *
	 * @param string $head
	 *   Commit to walk back from.
	 * @param int $limit
	 *   Commits to include, or 0 for every commit.
	 *
	 * @return array<string, list<string>>
	 *   Keys `commits`, `frames`, `dictionaries`, `objects` and `problems`, each holding a list of
	 *   strings.
	 */
	public function closure(string $head, int $limit = self::DEFAULT_LIMIT): array
	{
		$commits = [];
		$frames = [];
		$objects = [];
		$problems = [];
		$anchors = [];

		// a broken parent link ends the walk with what was collected rather than throwing it away
		try {
			foreach ($this->commits->walk($head, $limit > 0 ? $limit : null) as $id => $commit) {
				$commits[] = (string) $id;
				$objects[Hash::key((string) $id, CommitLog::PREFIX)] = true;

				foreach ($this->segmentFrames($commit, $objects, $problems) as $hash) {
					$frames[$hash] = true;
				}
				foreach (
					$this->anchorChain($commit->index, $anchors, $objects, $problems)
					as $hash
				) {
					$frames[$hash] = true;
				}
			}
		} catch (Throwable $e) {
			$problems[] = sprintf(
				'The commit chain from %s ended early: %s',
				Hash::abbreviate($head),
				$e->getMessage(),
			);
		}

		$resolved = $this->frameObjects(array_keys($frames), $problems);

		foreach ($resolved['objects'] as $key) {
			$objects[$key] = true;
		}

		$dictionaries = [];

		foreach ($resolved['dictionaries'] as $id) {
			$key = $this->dictionaryKey($id, $problems);

			if ($key !== null) {
				$dictionaries[] = $id;
				$objects[$key] = true;
			}
		}

		// the ref makes the archive point at something once it is imported
		$objects[RefStore::PREFIX . '/' . RefStore::MAIN] = true;

		return [
			'commits' => array_values(array_reverse($commits)),
			'frames' => $resolved['frames'],
			'dictionaries' => $dictionaries,
			'objects' => array_keys($objects),
			'problems' => $problems,
		];
	}

	/**
	 * Frames a commit's segment references, adding the segment object itself.
	 *
	 * @param Commit $commit
	 *   The commit.
	 * @param array<string, true> $objects
	 *   Object keys collected so far, added to by reference.
	 * @param list<string> $problems
	 *   Problems collected so far, added to by reference.
	 *
	 * @return list<string>
	 *   Frame addresses.
	 */
	private function segmentFrames(Commit $commit, array &$objects, array &$problems): array
	{
		$key = isset($commit->metadata['segment']) ? (string) $commit->metadata['segment'] : '';

		if ($key === '') {
			return [];
		}

		$objects[$key] = true;

		try {
			return $this->segments->read($key)->frames();
		} catch (Throwable $e) {
			$problems[] = sprintf('Segment %s could not be read: %s', $key, $e->getMessage());

			return [];
		}
	}

	/**
	 * Frames the whole anchor chain behind an address references.
	 *
	 * A commit between two anchors names the anchor in force, so several commits share one chain and
	 * it is walked once.
	 *
	 * @param string $address
	 *   The anchor address the commit names, or an empty string.
	 * @param array<string, true> $anchors
	 *   Anchor addresses already walked, added to by reference.
	 * @param array<string, true> $objects
	 *   Object keys collected so far, added to by reference.
	 * @param list<string> $problems
	 *   Problems collected so far, added to by reference.
	 *
	 * @return list<string>
	 *   Frame addresses.
	 */
	private function anchorChain(
		string $address,
		array &$anchors,
		array &$objects,
		array &$problems,
	): array {
		if ($address === '' || isset($anchors[$address])) {
			return [];
		}

		try {
			$chain = $this->bases->chain($address);
		} catch (Throwable $e) {
			$problems[] = sprintf(
				'Anchor %s could not be walked: %s',
				Hash::abbreviate($address),
				$e->getMessage(),
			);

			return [];
		}

		$frames = [];

		foreach ($chain as $link) {
			$anchors[$link] = true;
			$objects[Hash::key($link, BaseManifest::PREFIX)] = true;

			try {
				foreach ($this->bases->frames($link) as $hash) {
					$frames[] = $hash;
				}
			} catch (Throwable $e) {
				$problems[] = sprintf(
					'Anchor %s could not be read: %s',
					Hash::abbreviate($link),
					$e->getMessage(),
				);
			}
		}

		return $frames;
	}

	/**
	 * Resolves frames to the objects that hold them, following every delta chain to its anchor.
	 *
	 * @param list<string> $hashes
	 *   Frame addresses the commits reference directly.
	 * @param list<string> $problems
	 *   Problems collected so far, added to by reference.
	 *
	 * @return array{frames: list<string>, objects: list<string>, dictionaries: list<string>}
	 *   Every frame including chain parents, the objects holding them, and the dictionaries they
	 *   decode against.
	 */
	private function frameObjects(array $hashes, array &$problems): array
	{
		$seen = [];
		$objects = [];
		$dictionaries = [];
		$queue = $hashes;

		while ($queue !== []) {
			$hash = array_pop($queue);

			if (isset($seen[$hash])) {
				continue;
			}

			$seen[$hash] = true;
			$record = $this->index->get($hash);

			if ($record === null) {
				$problems[] = sprintf(
					'Frame %s is referenced but not indexed, so its object is unknown',
					Hash::abbreviate($hash),
				);

				continue;
			}

			// a packed frame pulls its whole pack, which is one object and cannot be sliced
			$key = $record->isPacked()
				? Hash::key((string) $record->pack, ObjectStore::PACK_PREFIX)
				: Hash::key($hash, ObjectStore::FRAME_PREFIX);

			$objects[$key] = true;

			if ($record->dictionary !== null) {
				$dictionaries[$record->dictionary] = true;
			}
			if ($record->deltaParent !== null) {
				$queue[] = $record->deltaParent;
			}
		}

		return [
			'frames' => array_keys($seen),
			'objects' => array_keys($objects),
			'dictionaries' => array_keys($dictionaries),
		];
	}

	/**
	 * The object key a dictionary id lives at.
	 *
	 * @param string $id
	 *   The dictionary id.
	 * @param list<string> $problems
	 *   Problems collected so far, added to by reference.
	 *
	 * @return string|null
	 *   The key, or NULL when the dictionary is not known here.
	 */
	private function dictionaryKey(string $id, array &$problems): ?string
	{
		$ref = $this->dictionaries->ref($id);

		if ($ref !== null) {
			return $ref->key();
		}

		$parts = DictionaryRef::parse($id);

		if ($parts === null) {
			$problems[] = sprintf('Dictionary id "%s" is not one this release writes', $id);

			return null;
		}

		$problems[] = sprintf(
			'Dictionary %s is referenced by a frame but is not in the index, so frames using it ' .
				'will not decode after an import',
			$id,
		);

		return null;
	}

	#endregion

	/**
	 * Reads one object, recording rather than throwing when it is gone.
	 *
	 * @param string $key
	 *   The object key.
	 * @param list<string> $problems
	 *   Problems collected so far, added to by reference.
	 *
	 * @return string|null
	 *   The bytes, or NULL when the object could not be read.
	 */
	private function read(string $key, array &$problems): ?string
	{
		try {
			return $this->provider->get($key);
		} catch (Throwable $e) {
			$problems[] = sprintf('Object %s could not be read: %s', $key, $e->getMessage());

			return null;
		}
	}

	/**
	 * The compression an archive path asks for.
	 *
	 * @param string $path
	 *   The archive path.
	 *
	 * @return string|null
	 *   "gz" for a gzip suffix, otherwise NULL for an uncompressed tar.
	 */
	private function compression(string $path): ?string
	{
		return preg_match('/\.(gz|tgz)$/i', $path) === 1 ? 'gz' : null;
	}
}
