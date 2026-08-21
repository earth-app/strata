<?php

declare(strict_types=1);

namespace Drupal\strata\Capture\EventSubscriber;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigRenameEvent;
use Drupal\Core\Config\StorableConfigBase;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Capture\CaptureScope;
use Drupal\strata\Journal\JournalInterface;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;
use Drupal\strata\Restore\LogicalRestore;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

/**
 * Records configuration changes as they are saved.
 *
 * Config is the realm where a single change does the most damage per byte. Twelve operations a day
 * on a 50,000-user site, under 1% of write volume, and among them are the ones that take a site
 * down: a permission grant, a text format's allowed tags, a mail server, a cache backend. So it is
 * captured in full rather than as a delta - a whole config object is small, and having the complete
 * value means a restore does not depend on replaying every change since the object was created.
 *
 * A rename is recorded as one operation against the NEW name carrying the old one, plus a delete of
 * the old. Recording only the new name would leave the old object present in every earlier commit
 * with nothing saying it went away, and a restore walking forward would resurrect it.
 *
 * @see LogicalRestore
 */
final class ConfigCaptureSubscriber implements EventSubscriberInterface
{
	/**
	 * Constructs the subscriber.
	 *
	 * @param JournalInterface $journal
	 *   Where operations are appended.
	 * @param CaptureScope $scope
	 *   Decides what is captured.
	 * @param AccountProxyInterface $currentUser
	 *   Attributes an operation to whoever caused it.
	 * @param LoggerInterface $logger
	 *   Records a capture that failed.
	 * @param string $requestId
	 *   Groups every operation captured in one request.
	 */
	public function __construct(
		private readonly JournalInterface $journal,
		private readonly CaptureScope $scope,
		private readonly AccountProxyInterface $currentUser,
		private readonly LoggerInterface $logger,
		private readonly string $requestId = '',
	) {}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   Events this subscriber listens to.
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			ConfigEvents::SAVE => ['onSave'],
			ConfigEvents::DELETE => ['onDelete'],
			ConfigEvents::RENAME => ['onRename'],
		];
	}

	/**
	 * Records a saved config object.
	 *
	 * A save that changed nothing is still recorded. Drupal saves config objects on module install, on
	 * import and on form submit whether or not the data moved, and the frame store deduplicates an
	 * unchanged value to nothing, so the operation costs its header and buys an accurate record of
	 * when the object was last written.
	 *
	 * @param ConfigCrudEvent $event
	 *   The save event.
	 */
	public function onSave(ConfigCrudEvent $event): void
	{
		$config = $event->getConfig();

		$this->record(
			$config->getName(),
			$this->isCreate($config) ? Verb::CREATE : Verb::UPDATE,
			$config->getRawData(),
		);
	}

	/**
	 * Whether a save created the object rather than changing it.
	 *
	 * `Config::isNew()` cannot answer this from inside a save subscriber. `Config::save()` sets the
	 * flag to FALSE and only then dispatches, so by the time this runs every save looks like an
	 * update. The pre-save data is still intact at that point - it is copied over on the line AFTER
	 * the dispatch - so an empty original is what identifies a create.
	 *
	 * @param StorableConfigBase $config
	 *   The config object being saved.
	 *
	 * @return bool
	 *   TRUE when the object held nothing before this save.
	 */
	private function isCreate(StorableConfigBase $config): bool
	{
		if (!($config instanceof Config)) {
			return false;
		}

		return $config->getOriginal('', false) === [];
	}

	/**
	 * Records a deleted config object.
	 *
	 * @param ConfigCrudEvent $event
	 *   The delete event.
	 */
	public function onDelete(ConfigCrudEvent $event): void
	{
		$this->record($event->getConfig()->getName(), Verb::DELETE, null);
	}

	/**
	 * Records a renamed config object.
	 *
	 * @param ConfigRenameEvent $event
	 *   The rename event.
	 */
	public function onRename(ConfigRenameEvent $event): void
	{
		$config = $event->getConfig();

		$this->record(
			$config->getName(),
			Verb::RENAME,
			$config->getRawData(),
			$event->getOldName(),
		);
		$this->record($event->getOldName(), Verb::DELETE, null);
	}

	/**
	 * Appends one operation.
	 *
	 * @param string $name
	 *   The config object name, which is the subject.
	 * @param Verb $verb
	 *   What happened to it.
	 * @param array<string, mixed>|null $data
	 *   The object's data, or NULL for a delete.
	 * @param string|null $from
	 *   The name it was renamed from, or NULL.
	 */
	private function record(string $name, Verb $verb, ?array $data, ?string $from = null): void
	{
		if (!$this->scope->covers(Realm::CONFIG)) {
			return;
		}
		// this module's own settings describe the backup rather than the site
		if (str_starts_with($name, 'strata.')) {
			return;
		}

		try {
			$payload = $data === null ? null : (string) json_encode($data);

			$this->journal->append(
				new JournalOp(
					0,
					$this->now(),
					Realm::CONFIG,
					$name,
					$verb,
					$this->actor(),
					$this->requestId === '' ? null : $this->requestId,
					$payload === null ? null : Hash::of($payload),
					null,
					$payload === null ? 0 : strlen($payload),
					$this->label($name, $verb, $from),
					$data === null ? [] : array_map('strval', array_keys($data)),
				),
				$payload,
			);
		} catch (Throwable $error) {
			// a capture never breaks the save that caused it
			$this->logger->error('Strata could not capture config %name: %message', [
				'%name' => $name,
				'%message' => $error->getMessage(),
			]);
		}
	}

	/**
	 * The label the timeline shows.
	 *
	 * @param string $name
	 *   The config object name.
	 * @param Verb $verb
	 *   What happened to it.
	 * @param string|null $from
	 *   The name it was renamed from, or NULL.
	 *
	 * @return string
	 *   The label.
	 */
	private function label(string $name, Verb $verb, ?string $from): string
	{
		if ($from !== null) {
			return sprintf('%s renamed to %s', $from, $name);
		}

		return sprintf('%s %s', $verb->label(), $name);
	}

	/**
	 * The user an operation is attributed to.
	 *
	 * @return int|null
	 *   A Drupal user id, or NULL for unattended work.
	 */
	private function actor(): ?int
	{
		$id = (int) $this->currentUser->id();

		return $id > 0 ? $id : null;
	}

	/**
	 * The current time in unix microseconds.
	 *
	 * @return int
	 *   Microseconds since the epoch.
	 */
	private function now(): int
	{
		return (int) round(microtime(true) * JournalOp::MICROSECONDS_PER_SECOND);
	}
}
