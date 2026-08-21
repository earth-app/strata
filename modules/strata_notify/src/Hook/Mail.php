<?php

declare(strict_types=1);

namespace Drupal\strata_notify\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\strata\Event\StrataEvent;
use Drupal\strata_notify\NotificationSubscriber;

/**
 * Composes the notification bodies.
 *
 * The body is the event's own payload rendered as lines, because that payload is already the
 * canonical description of what happened - the same one a webhook receives. Writing a second,
 * prose-only version would give two accounts of one event that could disagree.
 *
 * @see NotificationSubscriber
 */
final class Mail
{
	use StringTranslationTrait;

	/**
	 * Implements hook_mail().
	 *
	 * @param string $key
	 *   Which notification is being composed.
	 * @param array<string, mixed> $message
	 *   The message, altered by reference.
	 * @param array<string, mixed> $params
	 *   The parameters the subscriber passed.
	 */
	#[Hook('mail')]
	public function compose(string $key, array &$message, array $params): void
	{
		$event = $params['strata_event'] ?? null;

		if (!($event instanceof StrataEvent)) {
			return;
		}

		$message['subject'] = (string) ($params['subject'] ?? $this->t('Strata notification'));
		$message['body'][] = $this->headline($key);

		foreach ($event->jsonSerialize() as $field => $value) {
			$message['body'][] = sprintf('%s: %s', (string) $field, $this->render($value));
		}
	}

	/**
	 * The opening line for one notification.
	 *
	 * @param string $key
	 *   The mail key.
	 *
	 * @return string
	 *   One sentence saying what this is about.
	 */
	private function headline(string $key): string
	{
		return match ($key) {
			'restore' => (string) $this->t(
				'A Strata restore finished. The full manifest is in the restore log.',
			),
			'budget' => (string) $this->t(
				'Strata crossed a configured spend ceiling and has applied the escalation rung below.',
			),
			'health' => (string) $this->t(
				'A Strata tripwire recorded a finding. The health dashboard shows what rung it is on.',
			),
			'drill' => (string) $this->t(
				'A Strata restore drill finished. Any verdict but pass means the store did not match.',
			),
			'prune' => (string) $this->t(
				'A Strata prune ran. The restore points it removed are gone permanently.',
			),
			default => (string) $this->t('A Strata event was recorded.'),
		};
	}

	/**
	 * Renders one payload value as a line.
	 *
	 * @param mixed $value
	 *   The value.
	 *
	 * @return string
	 *   The rendered value.
	 */
	private function render(mixed $value): string
	{
		if (is_bool($value)) {
			return $value ? 'yes' : 'no';
		}
		if ($value === null) {
			return '-';
		}
		if (is_scalar($value)) {
			return (string) $value;
		}

		return (string) json_encode($value);
	}
}
