<?php

declare(strict_types=1);

namespace Drupal\strata\Archive;

use Drupal\Core\Archiver\ArchiveTar;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Cas\ObjectStore;
use Drupal\strata\Storage\StorageProviderInterface;
use Drupal\strata\Tree\CommitLog;
use JsonException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Loads an archive into this site's store.
 *
 * **Content-addressed objects are verified before they are written.** A frame, a commit and an
 * anchor are all filed under the digest of their own bytes, so an archive that was edited or
 * truncated can be caught here rather than at the moment someone needs a restore. A key whose bytes
 * do not hash to it is refused and recorded; the rest of the archive still imports.
 *
 * **An import never advances the ref.** The archive's own head is reported and left alone: a site
 * importing history from elsewhere is inspecting or merging it, and silently repointing the live ref
 * at a foreign commit would change what the site restores to without anyone asking for it.
 *
 * The local indexes are not rebuilt here either. `strata:reindex` does that, from the objects an
 * import has just put in place, and it is the same pass an uninstall-and-reinstall runs.
 *
 * @see ArchiveExporter
 * @see ArchiveManifest
 */
final class ArchiveImporter
{
	/**
	 * Constructs an importer.
	 *
	 * @param StorageProviderInterface $provider
	 *   Where objects are written.
	 * @param LoggerInterface $logger
	 *   Records what was refused.
	 */
	public function __construct(
		private readonly StorageProviderInterface $provider,
		private readonly LoggerInterface $logger,
	) {}

	/**
	 * Reads an archive's manifest without writing anything.
	 *
	 * @param string $path
	 *   The archive.
	 *
	 * @return ArchiveManifest
	 *   What the archive says it holds.
	 *
	 * @throws RuntimeException
	 *   When the file is not readable, is not an archive, or carries no manifest.
	 */
	public function inspect(string $path): ArchiveManifest
	{
		return $this->manifest($this->open($path));
	}

	/**
	 * Writes an archive's objects into the store.
	 *
	 * @param string $path
	 *   The archive.
	 * @param bool $apply
	 *   FALSE to report what would be written without writing it.
	 *
	 * @return ArchiveManifest
	 *   What was written, with anything refused listed as a problem.
	 *
	 * @throws RuntimeException
	 *   When the file is not readable, is not an archive, or carries no manifest.
	 */
	public function import(string $path, bool $apply = false): ArchiveManifest
	{
		$archive = $this->open($path);
		$manifest = $this->manifest($archive);
		$problems = $manifest->problems;
		$written = [];
		$bytes = 0;

		foreach ($manifest->objects as $key) {
			$body = $archive->extractInString($key);

			if (!is_string($body)) {
				$problems[] = sprintf(
					'Object %s is named in the manifest but not in the archive',
					$key,
				);

				continue;
			}

			$reason = $this->reject($key, $body);

			if ($reason !== null) {
				$problems[] = $reason;

				continue;
			}

			if ($apply) {
				try {
					$this->provider->put($key, $body);
				} catch (Throwable $e) {
					$problems[] = sprintf(
						'Object %s could not be written: %s',
						$key,
						$e->getMessage(),
					);

					continue;
				}
			}

			$written[] = $key;
			$bytes += strlen($body);
		}

		if ($problems !== []) {
			$this->logger->warning('Strata imported @path with @count problems', [
				'@path' => $path,
				'@count' => count($problems),
			]);
		}

		return new ArchiveManifest(
			$manifest->site,
			$manifest->createdAt,
			$manifest->commits,
			$manifest->frames,
			$manifest->dictionaries,
			$written,
			$bytes,
			$problems,
			$manifest->head,
			$manifest->version,
		);
	}

	/**
	 * Why an object may not be written, if there is a reason.
	 *
	 * Only content-addressed keys can be checked. A ref, a segment and a dictionary are named by
	 * position rather than by digest, so their bytes are taken as given - an archive is not a trust
	 * boundary, it is a transport, and a caller importing one they did not produce has already
	 * decided to trust it.
	 *
	 * @param string $key
	 *   The object key.
	 * @param string $body
	 *   The bytes.
	 *
	 * @return string|null
	 *   The reason to refuse it, or NULL to write it.
	 */
	private function reject(string $key, string $body): ?string
	{
		$expected = $this->addressIn($key);

		if ($expected === null) {
			return null;
		}

		$actual = Hash::of($body);

		if ($actual === $expected) {
			return null;
		}

		return sprintf(
			'Object %s holds bytes that hash to %s, so it was refused',
			$key,
			Hash::abbreviate($actual),
		);
	}

	/**
	 * The digest a key claims its bytes hash to.
	 *
	 * @param string $key
	 *   The object key.
	 *
	 * @return string|null
	 *   The digest, or NULL when the key is not content-addressed.
	 */
	private function addressIn(string $key): ?string
	{
		$verified = [
			ObjectStore::FRAME_PREFIX,
			ObjectStore::PACK_PREFIX,
			CommitLog::PREFIX,
			'bases',
		];

		foreach ($verified as $prefix) {
			if (!str_starts_with($key, $prefix . '/')) {
				continue;
			}

			$name = substr($key, strrpos($key, '/') + 1);

			return Hash::isValid($name) ? $name : null;
		}

		return null;
	}

	/**
	 * Opens an archive.
	 *
	 * @param string $path
	 *   The archive.
	 *
	 * @return ArchiveTar
	 *   The open archive.
	 *
	 * @throws RuntimeException
	 *   When the file is not readable.
	 */
	private function open(string $path): ArchiveTar
	{
		if (!is_file($path) || !is_readable($path)) {
			throw new RuntimeException(sprintf('Archive %s cannot be read', $path));
		}

		return new ArchiveTar($path, preg_match('/\.(gz|tgz)$/i', $path) === 1 ? 'gz' : null);
	}

	/**
	 * Reads the manifest out of an archive.
	 *
	 * @param ArchiveTar $archive
	 *   The open archive.
	 *
	 * @return ArchiveManifest
	 *   The manifest.
	 *
	 * @throws RuntimeException
	 *   When the manifest is absent, unreadable, or from a later format than this release knows.
	 */
	private function manifest(ArchiveTar $archive): ArchiveManifest
	{
		$body = $archive->extractInString(ArchiveManifest::FILE);

		if (!is_string($body) || $body === '') {
			throw new RuntimeException(
				sprintf(
					'The archive carries no %s, so it is not a Strata archive',
					ArchiveManifest::FILE,
				),
			);
		}

		try {
			/** @var array<string, mixed> $data */
			$data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuntimeException(
				sprintf('The archive manifest could not be read: %s', $e->getMessage()),
				0,
				$e,
			);
		}

		$manifest = ArchiveManifest::fromArray($data);

		if ($manifest->version > ArchiveManifest::VERSION) {
			throw new RuntimeException(
				sprintf(
					'The archive is format %d and this release reads %d, so it would be misread ' .
						'rather than imported',
					$manifest->version,
					ArchiveManifest::VERSION,
				),
			);
		}

		return $manifest;
	}
}
