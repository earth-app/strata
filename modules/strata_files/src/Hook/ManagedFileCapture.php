<?php

declare(strict_types=1);

namespace Drupal\strata_files\Hook;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;
use Drupal\strata\Capture\CaptureScope;
use Drupal\strata\Engine;
use Drupal\strata\File\FileCapture;
use Drupal\strata\Journal\Realm;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Captures a managed file when Drupal records one.
 *
 * A managed file is the case worth hooking, because Drupal tells us about it: an entity is saved when
 * the file is uploaded and again when it becomes permanent, and both carry the URI. Unmanaged files -
 * anything written straight into the files directory by a module - are not events and are found by the
 * cron sweep instead.
 *
 * **Only permanent files are captured.** A temporary file is one Drupal is about to delete, so storing
 * it would fill the file realm with uploads nobody kept. The insert of a temporary file is ignored and
 * the update that makes it permanent is what captures it.
 *
 * **The engine arrives rather than the capture, and that is load bearing.** Building a FileCapture
 * assembles the storage provider, which refuses when nothing is configured yet. Drupal's hook system
 * resolves this class from the container before `hook_file_insert` is invoked and outside any
 * try/catch of its own, so a constructor argument that refused would fail every file save on the
 * site rather than being logged. The capture is therefore resolved on first use, inside the same
 * guard that already keeps a failed capture from breaking the save.
 *
 * @see FileCapture
 */
final class ManagedFileCapture
{
	/**
	 * The capture, once a file has needed one.
	 */
	private ?FileCapture $capture = null;

	/**
	 * Constructs the capture.
	 *
	 * @param Engine $engine
	 *   Builds the block store on first use. Deliberately not the capture itself; see the class
	 *   docblock.
	 * @param CaptureScope $scope
	 *   Decides whether the file realm is captured.
	 * @param AccountProxyInterface $currentUser
	 *   Attributes the capture to whoever caused it.
	 * @param FileSystemInterface $files
	 *   Resolves a stream URI to a path on disk.
	 * @param LoggerInterface $logger
	 *   Records a capture that could not run.
	 */
	public function __construct(
		private readonly Engine $engine,
		private readonly CaptureScope $scope,
		private readonly AccountProxyInterface $currentUser,
		private readonly FileSystemInterface $files,
		private readonly LoggerInterface $logger,
	) {}

	/**
	 * Captures a file that has just become permanent.
	 *
	 * @param FileInterface $file
	 *   The file entity.
	 */
	#[Hook('file_insert')]
	public function onInsert(FileInterface $file): void
	{
		$this->store($file);
	}

	/**
	 * Captures a file whose content or status changed.
	 *
	 * @param FileInterface $file
	 *   The file entity.
	 */
	#[Hook('file_update')]
	public function onUpdate(FileInterface $file): void
	{
		$this->store($file);
	}

	/**
	 * Stores one file, if it is one worth storing.
	 *
	 * @param FileInterface $file
	 *   The file entity.
	 */
	private function store(FileInterface $file): void
	{
		if (!$this->scope->covers(Realm::FILE) || !$file->isPermanent()) {
			return;
		}

		$uri = (string) $file->getFileUri();
		$path = $this->files->realpath($uri);

		if ($path === false || !is_file($path)) {
			return;
		}

		try {
			// a capture never breaks the save that caused it, and assembling the store is part of it
			$this->capture()->capture($path, $uri, $this->actor());
		} catch (Throwable $error) {
			$this->logger->error('Strata could not capture %file: %message', [
				'%file' => $uri,
				'%message' => $error->getMessage(),
			]);
		}
	}

	/**
	 * The capture, resolved once.
	 *
	 * @return FileCapture
	 *   The capture.
	 */
	private function capture(): FileCapture
	{
		return $this->capture ??= $this->engine->fileCapture();
	}

	/**
	 * The user a capture is attributed to.
	 *
	 * @return int|null
	 *   A Drupal user id, or NULL for unattended work.
	 */
	private function actor(): ?int
	{
		$id = (int) $this->currentUser->id();

		return $id > 0 ? $id : null;
	}
}
