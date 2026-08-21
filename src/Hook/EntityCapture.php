<?php

declare(strict_types=1);

namespace Drupal\strata\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Capture\CaptureScope;
use Drupal\strata\Capture\EntityDelta;
use Drupal\strata\Journal\JournalInterface;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records entity creates, updates and deletes as they happen.
 *
 * The three hooks fire after the entity storage has committed, so what is captured is what the site
 * now holds rather than what a save intended. The previous version comes from the entity's own
 * original, which is already in memory, so an update costs a comparison rather than a second read.
 *
 * A capture never breaks a save. Everything here runs inside someone's request, and a backup that
 * takes the site down when its bucket is unreachable is worse than a missing backup, so every hook
 * catches, records a finding, and returns. The reconciler notices the gap on the next pass.
 *
 * @see EntityDelta
 * @see CaptureScope
 */
final class EntityCapture
{
	/**
	 * Constructs the capture.
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
	 * Records a newly created entity.
	 *
	 * @param EntityInterface $entity
	 *   The entity that was created.
	 */
	#[Hook('entity_insert')]
	public function onInsert(EntityInterface $entity): void
	{
		$this->capture($entity, Verb::CREATE, null);
	}

	/**
	 * Records a changed entity.
	 *
	 * @param EntityInterface $entity
	 *   The entity that was saved.
	 */
	#[Hook('entity_update')]
	public function onUpdate(EntityInterface $entity): void
	{
		$this->capture($entity, Verb::UPDATE, $entity->getOriginal());
	}

	/**
	 * Records a removed entity.
	 *
	 * A delete carries no payload: the value is whatever the previous commit already holds, and a
	 * restore reaches it by walking back rather than by reading it here.
	 *
	 * @param EntityInterface $entity
	 *   The entity that was deleted.
	 */
	#[Hook('entity_delete')]
	public function onDelete(EntityInterface $entity): void
	{
		$this->capture($entity, Verb::DELETE, null);
	}

	/**
	 * Appends one entity operation.
	 *
	 * @param EntityInterface $entity
	 *   The entity.
	 * @param Verb $verb
	 *   What happened to it.
	 * @param EntityInterface|null $original
	 *   The previous version, for an update.
	 */
	private function capture(EntityInterface $entity, Verb $verb, ?EntityInterface $original): void
	{
		try {
			if (!$this->scope->coversEntityType($entity->getEntityTypeId())) {
				return;
			}

			$mode = $this->scope->accessChurnMode();

			if ($verb === Verb::UPDATE && EntityDelta::isAccessTouch($entity, $original)) {
				if ($mode === 'drop') {
					return;
				}
				if ($mode === 'event') {
					$this->appendLoginEvent($entity);

					return;
				}
			}

			if ($verb === Verb::UPDATE && !EntityDelta::isMeaningful($entity, $original)) {
				return;
			}

			$payload =
				$verb === Verb::DELETE
					? null
					: (string) json_encode(EntityDelta::payload($entity, $original));

			$this->journal->append(
				new JournalOp(
					0,
					$this->now(),
					Realm::ENTITY,
					$this->subject($entity),
					$verb,
					$this->actor(),
					$this->requestId === '' ? null : $this->requestId,
					$payload === null ? null : Hash::of($payload),
					null,
					$payload === null ? 0 : strlen($payload),
					$this->label($entity, $verb),
					EntityDelta::changedFields($entity, $original),
				),
				$payload,
			);
		} catch (Throwable $e) {
			// a backup must never take a save down with it
			$this->logger->error('Strata could not capture %subject: %message', [
				'%subject' => $this->subject($entity),
				'%message' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Appends a compact login event instead of a full field delta.
	 *
	 * Access-timestamp churn is two thirds of a Drupal site's write volume and restoring an old
	 * value would misreport an active account as dormant. The event keeps the security trail - who
	 * was seen, and when - at a fraction of the size, and carries no payload to restore.
	 *
	 * @param EntityInterface $entity
	 *   The user whose access timestamps moved.
	 */
	private function appendLoginEvent(EntityInterface $entity): void
	{
		$this->journal->append(
			new JournalOp(
				0,
				$this->now(),
				Realm::EPHEMERAL,
				$this->subject($entity),
				Verb::UPDATE,
				$this->actor(),
				$this->requestId === '' ? null : $this->requestId,
				null,
				null,
				0,
				sprintf('%s was seen', $entity->label() ?? $this->subject($entity)),
				EntityDelta::ACCESS_FIELDS,
			),
		);
	}

	/**
	 * The subject key an entity is recorded under.
	 *
	 * @param EntityInterface $entity
	 *   The entity.
	 *
	 * @return string
	 *   Something such as "node:42".
	 */
	private function subject(EntityInterface $entity): string
	{
		return $entity->getEntityTypeId() . ':' . ($entity->id() ?? 'new');
	}

	/**
	 * The human label recorded with an operation.
	 *
	 * @param EntityInterface $entity
	 *   The entity.
	 * @param Verb $verb
	 *   What happened to it.
	 *
	 * @return string
	 *   A label for the timeline.
	 */
	private function label(EntityInterface $entity, Verb $verb): string
	{
		$type = $entity->getEntityType()->getLabel();
		$bundle = $entity->bundle() === $entity->getEntityTypeId() ? '' : ' ' . $entity->bundle();

		return sprintf(
			'%s%s "%s" %s',
			$type,
			$bundle,
			$entity->label() ?? $this->subject($entity),
			$verb->value . 'd',
		);
	}

	/**
	 * The user an operation is attributed to.
	 *
	 * @return int|null
	 *   A Drupal user id, or NULL for anonymous and unattended work.
	 */
	private function actor(): ?int
	{
		$uid = (int) $this->currentUser->id();

		return $uid > 0 ? $uid : null;
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
