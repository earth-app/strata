<?php

declare(strict_types=1);

namespace Drupal\strata\Capture;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\strata\Cas\Hash;
use Drupal\strata\Journal\JournalInterface;
use Drupal\strata\Journal\JournalOp;
use Drupal\strata\Journal\Realm;
use Drupal\strata\Journal\Verb;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records a change to one key in a key-addressed store.
 *
 * State and the key-value collections are the same shape - a key, a serializable value, set and
 * delete - and they have no events, so capture is a decorator around each store. This is the part
 * both decorators share, kept separate so neither has to know about journals, actors or timestamps.
 *
 * PayloadCodec decides how a value is encoded, because the replayer has to decode it the same way.
 *
 * @see StateCapture
 * @see KeyValueCapture
 */
final class KeyRecorder
{
	/**
	 * Constructs a recorder.
	 *
	 * @param JournalInterface $journal
	 *   Where operations are appended.
	 * @param CaptureScope $scope
	 *   Decides whether the realm is captured.
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
	 * Whether a realm is captured at all.
	 *
	 * Checked by a decorator before it reads the old value, since reading it is the only cost the
	 * decorator adds and there is no point paying it for a realm nobody captures.
	 *
	 * @param Realm $realm
	 *   The realm.
	 *
	 * @return bool
	 *   TRUE when operations in this realm are recorded.
	 */
	public function covers(Realm $realm): bool
	{
		return $this->scope->covers($realm);
	}

	/**
	 * Records a value being written.
	 *
	 * @param Realm $realm
	 *   Realm::STATE or Realm::KEY_VALUE.
	 * @param string $subject
	 *   The subject key: a state key, or `collection:key` for a key-value pair.
	 * @param mixed $value
	 *   The value written.
	 * @param bool $existed
	 *   Whether the key held a value before, which decides create against update.
	 */
	public function written(Realm $realm, string $subject, mixed $value, bool $existed): void
	{
		$this->record(
			$realm,
			$subject,
			$existed ? Verb::UPDATE : Verb::CREATE,
			PayloadCodec::encode($realm, $value),
		);
	}

	/**
	 * Records a key being removed.
	 *
	 * @param Realm $realm
	 *   Realm::STATE or Realm::KEY_VALUE.
	 * @param string $subject
	 *   The subject key.
	 */
	public function deleted(Realm $realm, string $subject): void
	{
		$this->record($realm, $subject, Verb::DELETE, null);
	}

	/**
	 * Records a key being renamed.
	 *
	 * Recorded as a write of the new key plus a delete of the old, so a restore walking forward
	 * produces one key rather than two.
	 *
	 * @param Realm $realm
	 *   Realm::KEY_VALUE, the only store that renames.
	 * @param string $from
	 *   The subject key it moved from.
	 * @param string $to
	 *   The subject key it moved to.
	 * @param mixed $value
	 *   The value that moved.
	 */
	public function renamed(Realm $realm, string $from, string $to, mixed $value): void
	{
		$this->record($realm, $to, Verb::RENAME, PayloadCodec::encode($realm, $value), $from);
		$this->record($realm, $from, Verb::DELETE, null);
	}

	/**
	 * Appends one operation.
	 *
	 * @param Realm $realm
	 *   The realm.
	 * @param string $subject
	 *   The subject key.
	 * @param Verb $verb
	 *   What happened.
	 * @param string|null $payload
	 *   The serialized value, or NULL for a delete.
	 * @param string|null $from
	 *   The key it was renamed from, or NULL.
	 */
	private function record(
		Realm $realm,
		string $subject,
		Verb $verb,
		?string $payload,
		?string $from = null,
	): void {
		if (!$this->scope->covers($realm)) {
			return;
		}

		try {
			$this->journal->append(
				new JournalOp(
					0,
					$this->now(),
					$realm,
					$subject,
					$verb,
					$this->actor(),
					$this->requestId === '' ? null : $this->requestId,
					$payload === null ? null : Hash::of($payload),
					null,
					$payload === null ? 0 : strlen($payload),
					$from === null
						? sprintf('%s %s', $verb->label(), $subject)
						: sprintf('%s renamed to %s', $from, $subject),
					[],
				),
				$payload,
			);
		} catch (Throwable $error) {
			// a capture never breaks the write that caused it
			$this->logger->error('Strata could not capture %realm %subject: %message', [
				'%realm' => $realm->value,
				'%subject' => $subject,
				'%message' => $error->getMessage(),
			]);
		}
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
