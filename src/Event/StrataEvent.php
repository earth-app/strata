<?php

declare(strict_types=1);

namespace Drupal\strata\Event;

use Drupal\Component\EventDispatcher\Event;
use JsonSerializable;

/**
 * Base for every event this module dispatches.
 *
 * Two things every subscriber needs and neither Symfony's Event nor Drupal's supplies: the name the
 * event was dispatched under, so one listener can serve several names, and a JSON body, so a
 * webhook does not have to know the concrete class to render a payload.
 *
 * The payload is built once and cached, because a webhook dispatcher renders it for every matching
 * subscription and a site can have several.
 *
 * @see StrataEvents
 */
abstract class StrataEvent extends Event implements JsonSerializable
{
	/**
	 * The rendered payload, once built.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $rendered = null;

	/**
	 * The name this event is dispatched under.
	 *
	 * @return string
	 *   One of the constants on StrataEvents.
	 */
	abstract public function name(): string;

	/**
	 * What happened, as data.
	 *
	 * @return array<string, mixed>
	 *   The body, without the envelope.
	 */
	abstract protected function body(): array;

	/**
	 * The payload a webhook sends and a log line records.
	 *
	 * @return array<string, mixed>
	 *   The event name, the site, the moment, and the body.
	 */
	public function jsonSerialize(): array
	{
		return $this->rendered ??= ['event' => $this->name()] + $this->body();
	}
}
